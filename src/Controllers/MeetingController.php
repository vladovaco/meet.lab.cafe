<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database as DB;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\ActionItem;
use App\Models\Job;
use App\Models\Meeting;
use App\Models\Participant;
use App\Models\Tag;
use App\Services\Storage;

final class MeetingController
{
    public function create(Request $r): string
    {
        return View::render('meetings/new', [
            'title'        => 'Nová porada',
            'folders'      => Tag::folders(),
            'tags'         => Tag::all(),
            'participants' => Participant::all(),
            'maxUploadMb'  => Config::get('max_upload_mb'),
            'defaultLang'  => Config::get('language'),
            'active'       => 'new',
        ]);
    }

    /** Klasický (non-AJAX) fallback formulára – samotný upload rieši ApiController::upload. */
    public function store(Request $r): void
    {
        Response::redirect('/meetings/new');
    }

    public function show(Request $r): string
    {
        $meeting = $this->findOrFail($r);
        $id = (int) $meeting['id'];
        $speakers = Meeting::speakers($id);
        $speakerIndex = [];
        foreach ($speakers as $i => $s) {
            $speakerIndex[$s['speaker_label']] = $i;
        }
        $segments = Meeting::segments($id);
        $keyPoints = Meeting::keyPoints($id);
        $grouped = ['key_point' => [], 'decision' => [], 'open_question' => []];
        foreach ($keyPoints as $kp) {
            $grouped[$kp['kind']][] = $kp;
        }
        return View::render('meetings/show', [
            'title'        => $meeting['title'],
            'meeting'      => $meeting,
            'tags'         => Meeting::tags($id),
            'speakers'     => $speakers,
            'speakerIndex' => $speakerIndex,
            'segments'     => $segments,
            'samples'      => self::speakerSamples($segments),
            'topics'       => Meeting::topics($id),
            'points'       => $grouped,
            'actionItems'  => ActionItem::forMeeting($id),
            'participants' => Participant::all(),
            'expected'     => Meeting::expectedParticipants($id),
            'job'          => Job::latestForMeeting($id),
            'processMode'  => Config::get('process_mode'),
            'active'       => 'meetings',
        ]);
    }

    public function edit(Request $r): string
    {
        $meeting = $this->findOrFail($r);
        return View::render('meetings/edit', [
            'title'        => 'Upraviť poradu',
            'meeting'      => $meeting,
            'folders'      => Tag::folders(),
            'tags'         => Tag::all(),
            'meetingTags'  => array_column(Meeting::tags((int) $meeting['id']), 'id'),
            'participants' => Participant::all(),
            'expected'     => array_column(Meeting::expectedParticipants((int) $meeting['id']), 'id'),
            'active'       => 'meetings',
        ]);
    }

    public function update(Request $r): void
    {
        $meeting = $this->findOrFail($r);
        $id = (int) $meeting['id'];
        $date = $r->str('meeting_date');
        try {
            $dt = new \DateTimeImmutable($date ?: 'now', new \DateTimeZone((string) Config::get('timezone')));
        } catch (\Throwable) {
            $dt = new \DateTimeImmutable();
        }
        DB::update('meetings', [
            'title'        => mb_substr($r->str('title') ?: $meeting['title'], 0, 200),
            'meeting_date' => $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'location'     => mb_substr($r->str('location'), 0, 160) ?: null,
            'folder_id'    => $r->int('folder_id') ?: null,
            'summary'      => $r->str('summary') ?: null,
        ], 'id = ?', [$id]);
        $tagIds = array_map('intval', (array) $r->input('tags', []));
        foreach (array_filter(array_map('trim', explode(',', $r->str('new_tags')))) as $name) {
            $tagIds[] = Tag::ensure($name);
        }
        Meeting::syncTags($id, $tagIds);
        Meeting::syncParticipants($id, array_map('intval', (array) $r->input('participants', [])));
        Flash::set('success', 'Porada bola uložená.');
        Response::redirect('/meetings/' . $id);
    }

    public function destroy(Request $r): void
    {
        $meeting = $this->findOrFail($r);
        Meeting::delete((int) $meeting['id']);
        Flash::set('success', 'Porada bola zmazaná.');
        Response::redirect('/');
    }

    /** Znovu spustí prepis (mode=transcribe) alebo iba analýzu (mode=analyze). */
    public function reprocess(Request $r): void
    {
        $meeting = $this->findOrFail($r);
        $id = (int) $meeting['id'];
        $mode = $r->str('mode') === 'transcribe' ? 'transcribe' : 'analyze';
        if ($mode === 'analyze' && empty($meeting['transcript_text'])) {
            $mode = 'transcribe';
        }
        DB::run('UPDATE jobs SET status = "failed" WHERE meeting_id = ? AND status = "pending"', [$id]);
        Meeting::setStatus($id, 'queued');
        Job::enqueue($id, $mode);
        Flash::set('success', $mode === 'transcribe' ? 'Prepis a analýza sa spustia znova.' : 'Analýza zápisu sa spustí znova.');
        Response::redirect('/meetings/' . $id);
    }

    /** Streamuje audio s podporou Range (kvôli skoku na časovú značku). */
    public function audio(Request $r): void
    {
        $meeting = $this->findOrFail($r);
        if (empty($meeting['audio_path'])) {
            Response::notFound('Audio nie je k dispozícii');
        }
        $file = Storage::absolute($meeting['audio_path']);
        if (!is_file($file)) {
            Response::notFound('Audio súbor sa nenašiel');
        }
        session_write_close();
        $size = filesize($file);
        $mime = $meeting['audio_mime'] ?: 'application/octet-stream';
        $start = 0;
        $end = $size - 1;
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=86400');
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] !== '') {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min((int) $m[2], $size - 1);
                }
            } elseif ($m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        $fp = fopen($file, 'rb');
        fseek($fp, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, min(65536, $remaining));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($fp);
        exit;
    }

    public function exportMarkdown(Request $r): void
    {
        $meeting = $this->findOrFail($r);
        $id = (int) $meeting['id'];
        $md = View::partial('meetings/export_md', [
            'meeting'     => $meeting,
            'speakers'    => Meeting::speakers($id),
            'segments'    => Meeting::segments($id),
            'topics'      => Meeting::topics($id),
            'points'      => Meeting::keyPoints($id),
            'actionItems' => ActionItem::forMeeting($id),
            'tags'        => Meeting::tags($id),
        ]);
        Response::download(self::slug($meeting['title']) . '.md', $md, 'text/markdown; charset=utf-8');
    }

    public function exportTranscript(Request $r): void
    {
        $meeting = $this->findOrFail($r);
        $id = (int) $meeting['id'];
        $names = [];
        foreach (Meeting::speakers($id) as $s) {
            $names[$s['speaker_label']] = $s['participant_name'] ?? $s['suggested_name'] ?? $s['speaker_label'];
        }
        $lines = [];
        foreach (Meeting::segments($id) as $seg) {
            $lines[] = sprintf('[%s] %s: %s', format_duration((float) $seg['start_sec']), $names[$seg['speaker_label']] ?? $seg['speaker_label'], $seg['text']);
        }
        Response::download(self::slug($meeting['title']) . '-prepis.txt', implode("\n", $lines));
    }

    /**
     * Pre každého rečníka vyberie až 3 najdlhšie úseky (min. 2 s), zoradené podľa času –
     * slúžia ako hlasové ukážky pri priraďovaní rečníka k účastníkovi.
     * @return array<string, list<array{start: float, end: float, text: string}>>
     */
    public static function speakerSamples(array $segments, int $max = 3, float $minLen = 2.0, float $maxLen = 12.0): array
    {
        $bySpeaker = [];
        foreach ($segments as $seg) {
            $len = (float) $seg['end_sec'] - (float) $seg['start_sec'];
            if ($len < $minLen) {
                continue;
            }
            $bySpeaker[$seg['speaker_label'] ?? 'speaker_0'][] = [
                'start' => (float) $seg['start_sec'],
                'end'   => min((float) $seg['end_sec'], (float) $seg['start_sec'] + $maxLen),
                'len'   => $len,
                'text'  => mb_strimwidth((string) $seg['text'], 0, 70, '…'),
            ];
        }
        $out = [];
        foreach ($bySpeaker as $label => $list) {
            usort($list, static fn($a, $b) => $b['len'] <=> $a['len']);
            $top = array_slice($list, 0, $max);
            usort($top, static fn($a, $b) => $a['start'] <=> $b['start']);
            $out[$label] = $top;
        }
        return $out;
    }

    private function findOrFail(Request $r): array
    {
        $meeting = Meeting::find((int) $r->param('id'));
        if ($meeting === null) {
            Response::notFound('Porada sa nenašla');
        }
        return $meeting;
    }

    public static function slug(string $s): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $t = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $t) ?? '', '-'));
        return $t !== '' ? substr($t, 0, 60) : 'porada';
    }
}
