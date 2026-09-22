<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database as DB;
use App\Core\View;
use App\Models\ActionItem;
use App\Models\Meeting;

/** Zostaví a pošle e-mail so zápisom (a prepisom) z porady. */
final class MeetingMailer
{
    /**
     * @param list<array{email:string,name?:string}> $recipients
     * @return bool true = odoslané
     */
    public static function send(int $meetingId, array $recipients, bool $includeTranscript = true, string $kind = 'manual', ?int $sentBy = null, string $note = ''): bool
    {
        $recipients = self::dedupe($recipients);
        if ($recipients === []) {
            throw new \RuntimeException('Žiadny platný príjemca.');
        }
        $meeting = Meeting::find($meetingId);
        if ($meeting === null) {
            throw new \RuntimeException('Porada neexistuje.');
        }
        $data = self::data($meeting, $includeTranscript);
        $data['note'] = $note;
        $subject = 'Zápis z porady: ' . $meeting['title'] . ' (' . format_date($meeting['meeting_date'], 'j. n. Y') . ')';
        $html = View::partial('emails/meeting', $data);
        $md = View::partial('meetings/export_md', $data);
        $attachments = [['name' => \App\Controllers\MeetingController::slug($meeting['title']) . '-zapis.md', 'content' => $md, 'mime' => 'text/markdown']];
        if ($includeTranscript && $data['segments'] !== []) {
            $attachments[] = ['name' => \App\Controllers\MeetingController::slug($meeting['title']) . '-prepis.txt', 'content' => self::transcriptText($data), 'mime' => 'text/plain'];
        }
        $list = implode(', ', array_map(fn($r) => $r['email'], $recipients));
        try {
            Mailer::send($recipients, $subject, $html, $md, $attachments);
            DB::insert('meeting_emails', ['meeting_id' => $meetingId, 'sent_by' => $sentBy, 'recipients' => $list, 'kind' => $kind, 'status' => 'sent']);
            return true;
        } catch (\Throwable $e) {
            DB::insert('meeting_emails', ['meeting_id' => $meetingId, 'sent_by' => $sentBy, 'recipients' => $list, 'kind' => $kind, 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw new \RuntimeException('E-mail sa nepodarilo odoslať: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Automatické odoslanie autorovi porady po dokončení analýzy (chyby sa iba zalogujú). */
    public static function sendAuto(int $meetingId): void
    {
        if (!Config::get('mail.auto_send') || !Mailer::enabled()) {
            return;
        }
        $meeting = Meeting::find($meetingId);
        if ($meeting === null || empty($meeting['created_by'])) {
            return;
        }
        $user = DB::one('SELECT email, name, notify_email FROM users WHERE id = ?', [$meeting['created_by']]);
        if ($user === null || !$user['notify_email'] || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            self::send($meetingId, [['email' => $user['email'], 'name' => $user['name']]], true, 'auto', (int) $meeting['created_by']);
        } catch (\Throwable $e) {
            error_log('[mail] ' . $e->getMessage());
        }
    }

    /** Dáta pre šablóny (rovnaké ako export). */
    public static function data(array $meeting, bool $includeTranscript): array
    {
        $id = (int) $meeting['id'];
        return [
            'meeting'     => $meeting,
            'speakers'    => Meeting::speakers($id),
            'segments'    => $includeTranscript ? Meeting::segments($id) : [],
            'topics'      => Meeting::topics($id),
            'points'      => Meeting::keyPoints($id),
            'actionItems' => ActionItem::forMeeting($id),
            'tags'        => Meeting::tags($id),
            'url'         => rtrim((string) Config::get('app_url'), '/') . '/meetings/' . $id,
        ];
    }

    public static function transcriptText(array $data): string
    {
        $names = [];
        foreach ($data['speakers'] as $i => $s) {
            $names[$s['speaker_label']] = $s['participant_name'] ?? $s['suggested_name'] ?? ('Rečník ' . ($i + 1));
        }
        $lines = [$data['meeting']['title'], format_date($data['meeting']['meeting_date']), ''];
        foreach ($data['segments'] as $seg) {
            $lines[] = sprintf('[%s] %s: %s', format_duration((float) $seg['start_sec']), $names[$seg['speaker_label']] ?? $seg['speaker_label'], $seg['text']);
        }
        return implode("\n", $lines);
    }

    /** Možní príjemcovia porady: rečníci a pozvaní účastníci s e-mailom + autor. */
    public static function candidates(int $meetingId): array
    {
        $rows = DB::all(
            'SELECT p.id, p.name, p.email, p.color, MAX(ms.speaker_label IS NOT NULL) AS spoke
             FROM participants p
             LEFT JOIN meeting_speakers ms ON ms.participant_id = p.id AND ms.meeting_id = :m1
             LEFT JOIN meeting_participants mp ON mp.participant_id = p.id AND mp.meeting_id = :m2
             WHERE ms.id IS NOT NULL OR mp.meeting_id IS NOT NULL
             GROUP BY p.id, p.name, p.email, p.color ORDER BY spoke DESC, p.name',
            ['m1' => $meetingId, 'm2' => $meetingId]
        );
        return $rows;
    }

    /** @param list<array{email:string,name?:string}> $list */
    private static function dedupe(array $list): array
    {
        $seen = [];
        $out = [];
        foreach ($list as $r) {
            $email = mb_strtolower(trim((string) ($r['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $out[] = ['email' => $email, 'name' => (string) ($r['name'] ?? '')];
        }
        return $out;
    }
}
