<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class ActionItem
{
    public static function forMeeting(int $meetingId): array
    {
        return DB::all(
            'SELECT a.*, p.name AS participant_name, p.color AS participant_color
             FROM action_items a LEFT JOIN participants p ON p.id = a.participant_id
             WHERE a.meeting_id = ? ORDER BY a.status, FIELD(a.priority,"high","normal","low"), a.due_date IS NULL, a.due_date, a.id',
            [$meetingId]
        );
    }

    public static function forParticipant(int $participantId, ?string $status = null): array
    {
        $sql = 'SELECT a.*, m.title AS meeting_title, m.meeting_date FROM action_items a JOIN meetings m ON m.id = a.meeting_id WHERE a.participant_id = ?';
        $params = [$participantId];
        if ($status) {
            $sql .= ' AND a.status = ?';
            $params[] = $status;
        }
        return DB::all($sql . ' ORDER BY a.status, a.due_date IS NULL, a.due_date, a.id DESC', $params);
    }

    public static function open(int $limit = 200): array
    {
        return DB::all(
            'SELECT a.*, p.name AS participant_name, p.color AS participant_color, m.title AS meeting_title, m.meeting_date
             FROM action_items a LEFT JOIN participants p ON p.id = a.participant_id JOIN meetings m ON m.id = a.meeting_id
             WHERE a.status = "open" ORDER BY a.due_date IS NULL, a.due_date, FIELD(a.priority,"high","normal","low"), a.id DESC LIMIT ' . (int) $limit
        );
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM action_items WHERE id = ?', [$id]);
    }

    public static function toggle(int $id): ?array
    {
        $a = self::find($id);
        if ($a === null) {
            return null;
        }
        $new = $a['status'] === 'open' ? 'done' : 'open';
        DB::update('action_items', ['status' => $new, 'done_at' => $new === 'done' ? gmdate('Y-m-d H:i:s') : null], 'id = ?', [$id]);
        $a['status'] = $new;
        return $a;
    }
}
