<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;
use App\Services\Storage;

/**
 * Časti nahrávky porady (tabuľka meeting_audio). Porada má aspoň jednu časť; ďalšie vznikajú,
 * keď sa po zastavení pokračuje v nahrávaní. Prvá časť je zrkadlená aj v meetings.audio_path.
 */
final class AudioPart
{
    /** Predpona labelov rečníkov z ďalších častí – diarizácia každej časti je nezávislá. */
    public static function labelFor(int $position, string $label): string
    {
        return $position <= 1 ? $label : 'p' . $position . '_' . $label;
    }

    /** Číslo časti podľa labelu rečníka (speaker_0 → 1, p2_speaker_0 → 2). */
    public static function positionOfLabel(?string $label): int
    {
        return $label !== null && preg_match('/^p(\d+)_/', $label, $m) ? (int) $m[1] : 1;
    }

    public static function forMeeting(int $meetingId): array
    {
        return DB::all('SELECT * FROM meeting_audio WHERE meeting_id = ? ORDER BY position', [$meetingId]);
    }

    public static function find(int $meetingId, int $position): ?array
    {
        return DB::one('SELECT * FROM meeting_audio WHERE meeting_id = ? AND position = ?', [$meetingId, $position]);
    }

    /**
     * Pridá časť na koniec porady.
     * @param array{path:string,mime:string,size:int} $stored
     */
    public static function add(int $meetingId, array $stored, string $source, ?float $duration): int
    {
        $pos = (int) (DB::one('SELECT COALESCE(MAX(position), 0) AS p FROM meeting_audio WHERE meeting_id = ?', [$meetingId])['p'] ?? 0) + 1;
        DB::insert('meeting_audio', [
            'meeting_id' => $meetingId,
            'position'   => $pos,
            'source'     => $source === 'record' ? 'record' : 'upload',
            'path'       => $stored['path'],
            'mime'       => $stored['mime'],
            'size'       => $stored['size'],
            'duration'   => $duration !== null && $duration > 0 ? round($duration, 2) : null,
        ]);
        self::syncMeeting($meetingId);
        return $pos;
    }

    /** Prepočíta súhrnné údaje porady (prvý súbor, celková veľkosť a dĺžka). */
    public static function syncMeeting(int $meetingId): void
    {
        $parts = self::forMeeting($meetingId);
        if ($parts === []) {
            return;
        }
        $duration = 0.0;
        $known = true;
        foreach ($parts as $p) {
            if ($p['duration'] === null) {
                $known = false;
            }
            $duration += (float) $p['duration'];
        }
        DB::update('meetings', [
            'audio_path'     => $parts[0]['path'],
            'audio_mime'     => $parts[0]['mime'],
            'audio_size'     => array_sum(array_map(static fn($p) => (int) $p['size'], $parts)),
            'audio_duration' => $known || $duration > 0 ? round($duration, 2) : null,
        ], 'id = ?', [$meetingId]);
    }

    /** Pri úplnom novom prepise sa všetky časti prepíšu odznova. */
    public static function resetTranscription(int $meetingId): void
    {
        DB::run('UPDATE meeting_audio SET transcribed_at = NULL WHERE meeting_id = ?', [$meetingId]);
    }

    /** Zoznam absolútnych ciest k súborom (na zmazanie spolu s poradou). */
    public static function files(int $meetingId): array
    {
        return array_map(static fn($p) => Storage::absolute($p['path']), self::forMeeting($meetingId));
    }
}
