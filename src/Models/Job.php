<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class Job
{
    public static function enqueue(int $meetingId, string $type): int
    {
        // nevytváraj duplicitnú čakajúcu úlohu rovnakého typu
        $existing = DB::one('SELECT id FROM jobs WHERE meeting_id = ? AND type = ? AND status IN ("pending","running")', [$meetingId, $type]);
        if ($existing) {
            return (int) $existing['id'];
        }
        return DB::insert('jobs', ['meeting_id' => $meetingId, 'type' => $type]);
    }

    public static function pendingCount(): int
    {
        return (int) (DB::one('SELECT COUNT(*) AS c FROM jobs WHERE status = "pending"')['c'] ?? 0);
    }

    public static function latestForMeeting(int $meetingId): ?array
    {
        return DB::one('SELECT * FROM jobs WHERE meeting_id = ? ORDER BY id DESC LIMIT 1', [$meetingId]);
    }
}
