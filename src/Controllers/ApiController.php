<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database as DB;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActionItem;
use App\Models\Job;
use App\Models\Meeting;
use App\Models\Participant;
use App\Models\Tag;
use App\Services\JobProcessor;
use App\Services\Storage;

final class ApiController
{
    /** Nahratie audia (z mikrofónu alebo súboru) + vytvorenie porady + zaradenie do fronty. */
    public function upload(Request $r): void
    {
        if (empty($r->files['audio'])) {
            Response::json(['error' => 'Chýba audio súbor.'], 422);
        }
        try {
            $stored = Storage::storeUpload($r->files['audio']);
        } catch (\RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 422);
        }
        $tz = new \DateTimeZone((string) Config::get('timezone'));
        try {
            $dt = new \DateTimeImmutable($r->str('meeting_date') ?: 'now', $tz);
        } catch (\Throwable) {
            $dt = new \DateTimeImmutable('now', $tz);
        }
        $title = $r->str('title');
        if ($title === '') {
            $title = 'Porada ' . $dt->format('j. n. Y H:i');
        }
        $duration = $r->input('duration');
        $lang = $r->str('language');

        $id = DB::insert('meetings', [
            'created_by'     => Auth::id() ?: null,
            'folder_id'      => $r->int('folder_id') ?: null,
            'title'          => mb_substr($title, 0, 200),
            'meeting_date'   => $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'location'       => mb_substr($r->str('location'), 0, 160) ?: null,
            'language'       => $lang !== '' && $lang !== 'auto' ? mb_substr($lang, 0, 8) : null,
            'source'         => $r->str('source') === 'record' ? 'record' : 'upload',
            'status'         => 'queued',
            'audio_path'     => $stored['path'],
            'audio_mime'     => $stored['mime'],
            'audio_size'     => $stored['size'],
            'audio_duration' => is_numeric($duration) && $duration > 0 ? round((float) $duration, 2) : null,
        ]);
        $tagIds = array_map('intval', (array) $r->input('tags', []));
        foreach (array_filter(array_map('trim', explode(',', $r->str('new_tags')))) as $name) {
            $tagIds[] = Tag::ensure($name);
        }
        Meeting::syncTags($id, $tagIds);
        Meeting::syncParticipants($id, array_map('intval', (array) $r->input('participants', [])));
        Job::enqueue($id, 'transcribe');

        Response::json(['ok' => true, 'id' => $id, 'url' => url('/meetings/' . $id)]);
    }

    public function status(Request $r): void
    {
        $m = Meeting::find((int) $r->param('id'));
        if ($m === null) {
            Response::json(['error' => 'not found'], 404);
        }
        $job = Job::latestForMeeting((int) $m['id']);
        Response::json([
            'status'       => $m['status'],
            'status_label' => status_label($m['status']),
            'error'        => $m['error_message'],
            'job'          => $job ? ['type' => $job['type'], 'status' => $job['status'], 'attempts' => (int) $job['attempts'], 'error' => $job['last_error']] : null,
            'pending_jobs' => Job::pendingCount(),
            'updated_at'   => $m['updated_at'],
        ]);
    }

    /**
     * Web mód: spustí spracovanie jednej úlohy z prehliadača (dlhá požiadavka na pozadí).
     * Session sa hneď zatvorí, aby neblokovala ostatné požiadavky.
     */
    public function runJobs(Request $r): void
    {
        if (Config::get('process_mode') !== 'web') {
            Response::json(['ok' => false, 'reason' => 'cron-mode']);
        }
        session_write_close();
        ignore_user_abort(true);
        set_time_limit(0);
        $n = JobProcessor::runPending(1);
        Response::json(['ok' => true, 'processed' => $n]);
    }

    public function toggleActionItem(Request $r): void
    {
        $a = ActionItem::toggle((int) $r->param('id'));
        $a ? Response::json(['ok' => true, 'status' => $a['status']]) : Response::json(['error' => 'not found'], 404);
    }

    public function updateActionItem(Request $r): void
    {
        $id = (int) $r->param('id');
        if (ActionItem::find($id) === null) {
            Response::json(['error' => 'not found'], 404);
        }
        $data = [];
        if ($r->input('description') !== null) {
            $data['description'] = $r->str('description');
        }
        if ($r->input('participant_id') !== null) {
            $pid = $r->int('participant_id');
            $data['participant_id'] = $pid ?: null;
            if ($pid) {
                $p = Participant::find($pid);
                $data['assignee_name'] = $p['name'] ?? null;
            }
        }
        if ($r->input('due_date') !== null) {
            $d = $r->str('due_date');
            $data['due_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        }
        if ($r->input('priority') !== null && in_array($r->str('priority'), ['low', 'normal', 'high'], true)) {
            $data['priority'] = $r->str('priority');
        }
        if ($data !== []) {
            DB::update('action_items', $data, 'id = ?', [$id]);
        }
        Response::json(['ok' => true]);
    }

    public function deleteActionItem(Request $r): void
    {
        DB::run('DELETE FROM action_items WHERE id = ?', [(int) $r->param('id')]);
        Response::json(['ok' => true]);
    }

    public function addActionItem(Request $r): void
    {
        $meetingId = (int) $r->param('id');
        if (Meeting::find($meetingId) === null) {
            Response::json(['error' => 'not found'], 404);
        }
        $desc = $r->str('description');
        if ($desc === '') {
            Response::json(['error' => 'Zadajte popis úlohy.'], 422);
        }
        $pid = $r->int('participant_id') ?: null;
        $p = $pid ? Participant::find($pid) : null;
        $id = DB::insert('action_items', [
            'meeting_id'     => $meetingId,
            'participant_id' => $p ? $pid : null,
            'assignee_name'  => $p['name'] ?? ($r->str('assignee_name') ?: null),
            'description'    => $desc,
            'due_date'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $r->str('due_date')) ? $r->str('due_date') : null,
            'priority'       => in_array($r->str('priority'), ['low', 'normal', 'high'], true) ? $r->str('priority') : 'normal',
            'source_quote'   => 'manual',
        ]);
        Response::json(['ok' => true, 'id' => $id, 'participant' => $p ? ['id' => $p['id'], 'name' => $p['name'], 'color' => $p['color']] : null]);
    }

    /** Priradenie rečníka k účastníkovi (existujúcemu alebo novému). */
    public function assignSpeaker(Request $r): void
    {
        $meetingId = (int) $r->param('id');
        $label = $r->str('speaker_label');
        $speaker = DB::one('SELECT * FROM meeting_speakers WHERE meeting_id = ? AND speaker_label = ?', [$meetingId, $label]);
        if ($speaker === null) {
            Response::json(['error' => 'Rečník sa nenašiel.'], 404);
        }
        $pid = $r->int('participant_id');
        $newName = $r->str('new_name');
        if ($pid === 0 && $newName !== '') {
            $pid = Participant::create(['name' => mb_substr($newName, 0, 120)]);
        }
        $participant = $pid ? Participant::find($pid) : null;
        DB::update('meeting_speakers', ['participant_id' => $participant ? $pid : null, 'confirmed' => 1], 'id = ?', [$speaker['id']]);
        // prenes priradenie aj na úlohy, ktoré boli priradené tomuto rečníkovi/menu
        if ($participant) {
            DB::run(
                'UPDATE action_items SET participant_id = ?, assignee_name = ? WHERE meeting_id = ? AND participant_id IS NULL AND (assignee_name = ? OR assignee_name = ?)',
                [$pid, $participant['name'], $meetingId, $label, $speaker['suggested_name'] ?? '']
            );
            DB::run('INSERT IGNORE INTO meeting_participants (meeting_id, participant_id) VALUES (?, ?)', [$meetingId, $pid]);
        }
        Response::json(['ok' => true, 'participant' => $participant ? ['id' => $participant['id'], 'name' => $participant['name'], 'color' => $participant['color']] : null]);
    }

    public function updateSegment(Request $r): void
    {
        $meetingId = (int) $r->param('id');
        $segId = (int) $r->param('segId');
        $text = $r->str('text');
        if ($text === '') {
            Response::json(['error' => 'Text nemôže byť prázdny.'], 422);
        }
        DB::update('transcript_segments', ['text' => $text], 'id = ? AND meeting_id = ?', [$segId, $meetingId]);
        $full = implode("\n", array_column(Meeting::segments($meetingId), 'text'));
        DB::update('meetings', ['transcript_text' => $full], 'id = ?', [$meetingId]);
        Response::json(['ok' => true]);
    }

    public function updateSummary(Request $r): void
    {
        $meetingId = (int) $r->param('id');
        DB::update('meetings', ['summary' => $r->str('summary')], 'id = ?', [$meetingId]);
        Response::json(['ok' => true]);
    }

    public function quickParticipant(Request $r): void
    {
        $name = $r->str('name');
        if ($name === '') {
            Response::json(['error' => 'Zadajte meno.'], 422);
        }
        $existing = Participant::matchByName($name);
        if ($existing && Participant::normalize($existing['name']) === Participant::normalize($name)) {
            Response::json(['ok' => true, 'id' => $existing['id'], 'name' => $existing['name'], 'existing' => true]);
        }
        $id = Participant::create(['name' => mb_substr($name, 0, 120), 'position' => mb_substr($r->str('position'), 0, 120) ?: null]);
        Response::json(['ok' => true, 'id' => $id, 'name' => $name, 'existing' => false]);
    }
}
