<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\AudioPart;
use App\Models\Job;
use App\Models\Meeting;
use App\Models\Participant;
use App\Models\Tag;
use App\Services\Analysis\AnalyzerFactory;
use App\Services\Transcription\TranscriberFactory;

/**
 * Spracúva frontu úloh: prepis audia a AI analýza.
 * Volané z bin/worker.php (cron) alebo z /api/jobs/run (web mód).
 */
final class JobProcessor
{
    private const MAX_ATTEMPTS = 3;

    /** Voliteľné továrne (na testovanie / výmenu poskytovateľa). */
    public static ?\Closure $transcriberFactory = null;
    public static ?\Closure $analyzerFactory = null;

    /** Spracuje najviac $max úloh. Vráti počet spracovaných. */
    public static function runPending(int $max = 1, ?callable $log = null): int
    {
        $log ??= static fn(string $m) => null;
        $lock = DB::one('SELECT GET_LOCK("meetlab_jobs", 0) AS l');
        if ((int) ($lock['l'] ?? 0) !== 1) {
            $log('Iný proces práve spracúva úlohy.');
            return 0;
        }
        $done = 0;
        try {
            // uvoľni zaseknuté úlohy (proces spadol pred viac ako 45 min)
            DB::run('UPDATE jobs SET status = "pending" WHERE status = "running" AND started_at < (UTC_TIMESTAMP() - INTERVAL 45 MINUTE)');
            while ($done < $max) {
                $job = DB::one('SELECT * FROM jobs WHERE status = "pending" ORDER BY id LIMIT 1');
                if ($job === null) {
                    break;
                }
                DB::update('jobs', ['status' => 'running', 'started_at' => gmdate('Y-m-d H:i:s'), 'attempts' => $job['attempts'] + 1], 'id = ?', [$job['id']]);
                $log(sprintf('Úloha #%d (%s) pre poradu #%d …', $job['id'], $job['type'], $job['meeting_id']));
                try {
                    match ($job['type']) {
                        'transcribe' => self::transcribe((int) $job['meeting_id']),
                        'analyze'    => self::analyze((int) $job['meeting_id']),
                        default      => throw new \RuntimeException('Neznámy typ úlohy ' . $job['type']),
                    };
                    DB::update('jobs', ['status' => 'done', 'finished_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null], 'id = ?', [$job['id']]);
                    $log('  hotovo.');
                } catch (\Throwable $e) {
                    $attempts = (int) $job['attempts'] + 1;
                    $failed = $attempts >= self::MAX_ATTEMPTS || $e instanceof ConfigurationException;
                    DB::update('jobs', [
                        'status'      => $failed ? 'failed' : 'pending',
                        'last_error'  => mb_substr($e->getMessage(), 0, 2000),
                        'finished_at' => $failed ? gmdate('Y-m-d H:i:s') : null,
                    ], 'id = ?', [$job['id']]);
                    if ($failed) {
                        Meeting::setStatus((int) $job['meeting_id'], 'error', mb_substr($e->getMessage(), 0, 2000));
                    }
                    error_log('[jobs] ' . $e);
                    $log('  CHYBA: ' . $e->getMessage());
                    if (!$failed) {
                        break; // ďalší pokus v ďalšom behu
                    }
                }
                $done++;
            }
        } finally {
            DB::run('SELECT RELEASE_LOCK("meetlab_jobs")');
        }
        return $done;
    }

    /**
     * Prepis všetkých ešte neprepísaných častí nahrávky. Už prepísané časti (a ich ručne upravené
     * segmenty a priradenia rečníkov) ostávajú; nová časť sa pripojí s časovým posunom = súčet dĺžok
     * predchádzajúcich častí, takže prepis tvorí jednu súvislú časovú os.
     */
    public static function transcribe(int $meetingId): void
    {
        $meeting = Meeting::find($meetingId);
        if ($meeting === null) {
            throw new \RuntimeException('Porada neexistuje.');
        }
        $parts = AudioPart::forMeeting($meetingId);
        if ($parts === []) {
            throw new \RuntimeException('Porada nemá žiadnu nahrávku.');
        }
        foreach ($parts as $p) {
            if ($p['transcribed_at'] === null && !is_file(Storage::absolute($p['path']))) {
                throw new \RuntimeException('Audio súbor sa nenašiel: ' . $p['path']);
            }
        }
        $pending = array_filter($parts, static fn($p) => $p['transcribed_at'] === null);
        if ($pending === []) {
            Job::enqueue($meetingId, 'analyze');
            return;
        }
        Meeting::setStatus($meetingId, 'transcribing');
        if (count($pending) === count($parts)) {
            // úplný (nový) prepis – začíname od nuly
            DB::run('DELETE FROM transcript_segments WHERE meeting_id = ?', [$meetingId]);
            DB::run('DELETE FROM meeting_speakers WHERE meeting_id = ?', [$meetingId]);
        }

        $expected = Meeting::expectedParticipants($meetingId);
        $transcriber = self::$transcriberFactory ? (self::$transcriberFactory)() : TranscriberFactory::make();
        $language = $meeting['language'] ?: null;
        $offset = 0.0;
        foreach ($parts as $part) {
            $pos = (int) $part['position'];
            if ($part['transcribed_at'] !== null) {
                $offset = (float) $part['offset_sec'] + self::partDuration($meetingId, $part);
                continue;
            }
            $result = $transcriber->transcribe(Storage::absolute($part['path']), $language, $expected !== [] ? count($expected) : null);
            $language = $language ?? ($result->language ?: null);
            $lastEnd = 0.0;
            foreach ($result->segments as $sg) {
                $lastEnd = max($lastEnd, (float) $sg['end']);
            }
            $duration = $result->duration ?? ($part['duration'] !== null ? (float) $part['duration'] : $lastEnd);

            DB::pdo()->beginTransaction();
            try {
                $next = (int) (DB::one('SELECT COALESCE(MAX(position), -1) AS p FROM transcript_segments WHERE meeting_id = ?', [$meetingId])['p'] ?? -1) + 1;
                foreach ($result->segments as $sg) {
                    DB::insert('transcript_segments', [
                        'meeting_id'    => $meetingId,
                        'position'      => $next++,
                        'speaker_label' => AudioPart::labelFor($pos, $sg['speaker'] ?? 'speaker_0'),
                        'start_sec'     => round($offset + $sg['start'], 2),
                        'end_sec'       => round($offset + $sg['end'], 2),
                        'text'          => $sg['text'],
                    ]);
                }
                foreach ($result->speakerStats() as $label => $st) {
                    DB::run(
                        'INSERT INTO meeting_speakers (meeting_id, speaker_label, talk_seconds, word_count) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE talk_seconds = VALUES(talk_seconds), word_count = VALUES(word_count)',
                        [$meetingId, AudioPart::labelFor($pos, $label), round($st['talk_seconds'], 2), $st['word_count']]
                    );
                }
                DB::update('meeting_audio', [
                    'duration'       => round($duration, 2),
                    'offset_sec'     => round($offset, 2),
                    'transcribed_at' => gmdate('Y-m-d H:i:s'),
                ], 'id = ?', [$part['id']]);
                DB::pdo()->commit();
            } catch (\Throwable $e) {
                DB::pdo()->rollBack();
                throw $e;
            }
            CostTracker::recordTranscription($meetingId, $transcriber->name(), $duration);
            $offset += $duration;
        }

        AudioPart::syncMeeting($meetingId);
        $texts = array_map(static fn($s) => (string) $s['text'], Meeting::segments($meetingId));
        DB::update('meetings', [
            'transcript_text' => implode("\n", $texts),
            'language'        => $language ?: $meeting['language'],
            'stt_provider'    => $transcriber->name(),
            'status'          => 'transcribed',
            'error_message'   => null,
        ], 'id = ?', [$meetingId]);
        Job::enqueue($meetingId, 'analyze');
    }

    /** Dĺžka už prepísanej časti; ak chýba, odhad podľa posledného segmentu v jej úseku. */
    private static function partDuration(int $meetingId, array $part): float
    {
        if ($part['duration'] !== null) {
            return (float) $part['duration'];
        }
        $row = (int) $part['position'] <= 1
            ? DB::one('SELECT MAX(end_sec) AS e FROM transcript_segments WHERE meeting_id = ? AND speaker_label NOT REGEXP "^p[0-9]+_"', [$meetingId])
            : DB::one('SELECT MAX(end_sec) AS e FROM transcript_segments WHERE meeting_id = ? AND speaker_label LIKE ?', [$meetingId, 'p' . (int) $part['position'] . '\\_%']);
        return max(0.0, (float) ($row['e'] ?? 0) - (float) $part['offset_sec']);
    }

    public static function analyze(int $meetingId): void
    {
        $meeting = Meeting::find($meetingId);
        if ($meeting === null) {
            throw new \RuntimeException('Porada neexistuje.');
        }
        $segments = array_map(static fn($s) => [
            'speaker' => $s['speaker_label'],
            'start'   => (float) $s['start_sec'],
            'end'     => (float) $s['end_sec'],
            'text'    => $s['text'],
        ], Meeting::segments($meetingId));
        if ($segments === []) {
            throw new \RuntimeException('Prepis je prázdny – v nahrávke sa nenašla reč.');
        }
        Meeting::setStatus($meetingId, 'analyzing');

        $expected = Meeting::expectedParticipants($meetingId);
        $known = DB::all('SELECT * FROM participants ORDER BY name');
        $analyzer = self::$analyzerFactory ? (self::$analyzerFactory)() : AnalyzerFactory::make();
        $analysis = $analyzer->analyze($meeting, $segments, $expected, $known);

        DB::pdo()->beginTransaction();
        try {
            DB::run('DELETE FROM meeting_topics WHERE meeting_id = ?', [$meetingId]);
            DB::run('DELETE FROM key_points WHERE meeting_id = ?', [$meetingId]);
            // zachovaj ručne odškrtnuté úlohy: úlohy z predošlej analýzy nahradíme, ručne pridané (bez source_quote a s 'manual') ostanú
            DB::run('DELETE FROM action_items WHERE meeting_id = ? AND (source_quote IS NULL OR source_quote <> "manual")', [$meetingId]);

            foreach ($analysis['topics'] ?? [] as $i => $t) {
                DB::insert('meeting_topics', [
                    'meeting_id' => $meetingId, 'position' => $i,
                    'title' => mb_substr((string) $t['title'], 0, 200), 'summary' => (string) $t['summary'],
                    'start_sec' => isset($t['start_sec']) && is_numeric($t['start_sec']) ? round((float) $t['start_sec'], 2) : null,
                ]);
            }
            foreach (['key_points' => 'key_point', 'decisions' => 'decision', 'open_questions' => 'open_question'] as $key => $kind) {
                foreach ($analysis[$key] ?? [] as $i => $text) {
                    if (trim((string) $text) !== '') {
                        DB::insert('key_points', ['meeting_id' => $meetingId, 'position' => $i, 'kind' => $kind, 'text' => (string) $text]);
                    }
                }
            }

            // Rečníci -> účastníci
            $speakerToParticipant = [];
            $speakerToName = [];
            $existingSpeakers = [];
            foreach (Meeting::speakers($meetingId) as $s) {
                $existingSpeakers[$s['speaker_label']] = $s;
            }
            foreach ($analysis['speakers'] ?? [] as $sp) {
                $label = (string) $sp['label'];
                if (!isset($existingSpeakers[$label])) {
                    continue;
                }
                $name = isset($sp['name']) && trim((string) $sp['name']) !== '' ? trim((string) $sp['name']) : null;
                $conf = (float) ($sp['confidence'] ?? 0);
                $update = ['suggested_name' => $name ? mb_substr($name, 0, 120) : null];
                // ručne potvrdené priradenie neprepisujeme
                if (!$existingSpeakers[$label]['confirmed'] && $name !== null) {
                    $match = Participant::matchByName($name, $expected !== [] ? $expected : $known) ?? Participant::matchByName($name, $known);
                    if ($match !== null && $conf >= 0.5) {
                        $update['participant_id'] = (int) $match['id'];
                        $speakerToParticipant[$label] = (int) $match['id'];
                    }
                }
                if ($existingSpeakers[$label]['participant_id']) {
                    $speakerToParticipant[$label] = (int) $existingSpeakers[$label]['participant_id'];
                }
                if ($name) {
                    $speakerToName[$label] = $name;
                }
                DB::update('meeting_speakers', $update, 'id = ?', [$existingSpeakers[$label]['id']]);
            }

            // Úlohy
            foreach ($analysis['action_items'] ?? [] as $a) {
                $desc = trim((string) ($a['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $assignee = isset($a['assignee']) ? trim((string) $a['assignee']) : '';
                $participantId = null;
                if ($assignee !== '') {
                    if (isset($speakerToParticipant[$assignee])) {
                        $participantId = $speakerToParticipant[$assignee];
                        $assignee = $speakerToName[$assignee] ?? $assignee;
                    } else {
                        $m = Participant::matchByName($assignee, $known);
                        $participantId = $m ? (int) $m['id'] : null;
                    }
                    if ($participantId !== null) {
                        foreach ($known as $kp) {
                            if ((int) $kp['id'] === $participantId) {
                                $assignee = (string) $kp['name'];
                            }
                        }
                    }
                }
                $due = null;
                if (!empty($a['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a['due_date'])) {
                    $due = (string) $a['due_date'];
                }
                DB::insert('action_items', [
                    'meeting_id'     => $meetingId,
                    'participant_id' => $participantId,
                    'assignee_name'  => $assignee !== '' ? mb_substr($assignee, 0, 120) : null,
                    'description'    => $desc,
                    'due_date'       => $due,
                    'priority'       => in_array($a['priority'] ?? 'normal', ['low', 'normal', 'high'], true) ? $a['priority'] : 'normal',
                    'source_quote'   => isset($a['source_quote']) ? mb_substr((string) $a['source_quote'], 0, 1000) : null,
                ]);
            }

            // Štítky navrhnuté AI (pridajú sa k existujúcim)
            foreach (array_slice($analysis['tags'] ?? [], 0, 5) as $tagName) {
                $tagName = trim(mb_strtolower((string) $tagName));
                if ($tagName !== '') {
                    DB::run('INSERT IGNORE INTO meeting_tags (meeting_id, tag_id) VALUES (?, ?)', [$meetingId, Tag::ensure($tagName)]);
                }
            }

            $update = [
                'summary'       => (string) ($analysis['summary'] ?? ''),
                'analysis_json' => json_encode($analysis, JSON_UNESCAPED_UNICODE),
                'status'        => 'done',
                'error_message' => null,
            ];
            // ak používateľ nechal predvolený názov, použi návrh AI
            if (!empty($analysis['title_suggestion']) && preg_match(\App\Services\Analysis\RecordingType::defaultTitlePattern(), (string) $meeting['title'])) {
                $update['title'] = mb_substr((string) $analysis['title_suggestion'], 0, 200);
            }
            DB::update('meetings', $update, 'id = ?', [$meetingId]);
            DB::pdo()->commit();
        } catch (\Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        if (!empty($analysis['_usage'])) {
            CostTracker::recordAnalysis($meetingId, (string) \App\Core\Config::get('ai.provider', 'claude'), (array) $analysis['_usage']);
        }

        // e-mail so zápisom autorovi porady (chyba e-mailu neovplyvní stav porady)
        MeetingMailer::sendAuto($meetingId);
    }
}
