<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class Meeting
{
    public static function find(int $id): ?array
    {
        return DB::one(
            'SELECT m.*, f.name AS folder_name, f.color AS folder_color, u.name AS author_name
             FROM meetings m
             LEFT JOIN folders f ON f.id = m.folder_id
             LEFT JOIN users u ON u.id = m.created_by
             WHERE m.id = ?',
            [$id]
        );
    }

    /** @param array<string,mixed> $filters */
    public static function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['folder_id'])) {
            $where[] = 'm.folder_id = ?';
            $params[] = (int) $filters['folder_id'];
        }
        if (!empty($filters['tag_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM meeting_tags mt WHERE mt.meeting_id = m.id AND mt.tag_id = ?)';
            $params[] = (int) $filters['tag_id'];
        }
        if (!empty($filters['participant_id'])) {
            $where[] = '(EXISTS (SELECT 1 FROM meeting_speakers ms WHERE ms.meeting_id = m.id AND ms.participant_id = ?)
                         OR EXISTS (SELECT 1 FROM meeting_participants mp WHERE mp.meeting_id = m.id AND mp.participant_id = ?))';
            $params[] = (int) $filters['participant_id'];
            $params[] = (int) $filters['participant_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'm.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(m.title LIKE ? OR m.summary LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql = 'SELECT m.id, m.title, m.meeting_date, m.status, m.audio_duration, m.summary, m.source, m.folder_id,
                       f.name AS folder_name, f.color AS folder_color,
                       (SELECT COUNT(*) FROM action_items a WHERE a.meeting_id = m.id AND a.status = "open") AS open_tasks,
                       (SELECT COUNT(*) FROM meeting_speakers s WHERE s.meeting_id = m.id) AS speaker_count
                FROM meetings m LEFT JOIN folders f ON f.id = m.folder_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY m.meeting_date DESC, m.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $rows = DB::all($sql, $params);
        return self::attachTags($rows);
    }

    private static function attachTags(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $tags = DB::all("SELECT mt.meeting_id, t.id, t.name, t.color FROM meeting_tags mt JOIN tags t ON t.id = mt.tag_id WHERE mt.meeting_id IN ($in) ORDER BY t.name", $ids);
        $byMeeting = [];
        foreach ($tags as $t) {
            $byMeeting[$t['meeting_id']][] = $t;
        }
        foreach ($rows as &$r) {
            $r['tags'] = $byMeeting[$r['id']] ?? [];
        }
        return $rows;
    }

    public static function tags(int $meetingId): array
    {
        return DB::all('SELECT t.* FROM meeting_tags mt JOIN tags t ON t.id = mt.tag_id WHERE mt.meeting_id = ? ORDER BY t.name', [$meetingId]);
    }

    /** @param list<int> $tagIds */
    public static function syncTags(int $meetingId, array $tagIds): void
    {
        DB::run('DELETE FROM meeting_tags WHERE meeting_id = ?', [$meetingId]);
        foreach (array_unique(array_map('intval', $tagIds)) as $tid) {
            if ($tid > 0) {
                DB::run('INSERT IGNORE INTO meeting_tags (meeting_id, tag_id) VALUES (?, ?)', [$meetingId, $tid]);
            }
        }
    }

    /** @param list<int> $participantIds */
    public static function syncParticipants(int $meetingId, array $participantIds): void
    {
        DB::run('DELETE FROM meeting_participants WHERE meeting_id = ?', [$meetingId]);
        foreach (array_unique(array_map('intval', $participantIds)) as $pid) {
            if ($pid > 0) {
                DB::run('INSERT IGNORE INTO meeting_participants (meeting_id, participant_id) VALUES (?, ?)', [$meetingId, $pid]);
            }
        }
    }

    public static function expectedParticipants(int $meetingId): array
    {
        return DB::all('SELECT p.* FROM meeting_participants mp JOIN participants p ON p.id = mp.participant_id WHERE mp.meeting_id = ? ORDER BY p.name', [$meetingId]);
    }

    public static function speakers(int $meetingId): array
    {
        return DB::all(
            'SELECT ms.*, p.name AS participant_name, p.color AS participant_color
             FROM meeting_speakers ms LEFT JOIN participants p ON p.id = ms.participant_id
             WHERE ms.meeting_id = ? ORDER BY ms.talk_seconds DESC, ms.speaker_label',
            [$meetingId]
        );
    }

    public static function segments(int $meetingId): array
    {
        return DB::all('SELECT * FROM transcript_segments WHERE meeting_id = ? ORDER BY position', [$meetingId]);
    }

    public static function topics(int $meetingId): array
    {
        return DB::all('SELECT * FROM meeting_topics WHERE meeting_id = ? ORDER BY position', [$meetingId]);
    }

    public static function keyPoints(int $meetingId): array
    {
        return DB::all('SELECT * FROM key_points WHERE meeting_id = ? ORDER BY kind, position', [$meetingId]);
    }

    public static function setStatus(int $id, string $status, ?string $error = null): void
    {
        DB::update('meetings', ['status' => $status, 'error_message' => $error], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        $m = self::find($id);
        if ($m === null) {
            return;
        }
        DB::run('DELETE FROM meetings WHERE id = ?', [$id]);
        if (!empty($m['audio_path'])) {
            $file = \App\Core\Config::get('storage_path') . '/' . $m['audio_path'];
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public static function counts(): array
    {
        $row = DB::one(
            'SELECT COUNT(*) AS total,
                    SUM(status IN ("queued","transcribing","transcribed","analyzing")) AS processing,
                    SUM(status = "error") AS errors FROM meetings'
        ) ?? [];
        $tasks = DB::one('SELECT COUNT(*) AS c FROM action_items WHERE status = "open"')['c'] ?? 0;
        return ['total' => (int) ($row['total'] ?? 0), 'processing' => (int) ($row['processing'] ?? 0), 'errors' => (int) ($row['errors'] ?? 0), 'open_tasks' => (int) $tasks];
    }
}
