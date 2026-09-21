<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class Participant
{
    public static function all(): array
    {
        return DB::all(
            'SELECT p.*,
                    (SELECT COUNT(DISTINCT ms.meeting_id) FROM meeting_speakers ms WHERE ms.participant_id = p.id) AS meetings_count,
                    (SELECT COUNT(*) FROM action_items a WHERE a.participant_id = p.id AND a.status = "open") AS open_tasks,
                    (SELECT MAX(m.meeting_date) FROM meeting_speakers ms JOIN meetings m ON m.id = ms.meeting_id WHERE ms.participant_id = p.id) AS last_meeting
             FROM participants p ORDER BY p.name'
        );
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM participants WHERE id = ?', [$id]);
    }

    public static function create(array $data): int
    {
        $data['color'] = $data['color'] ?? speaker_color(random_int(0, 9));
        return DB::insert('participants', $data);
    }

    public static function stats(int $id): array
    {
        $row = DB::one(
            'SELECT COUNT(*) AS meetings, COALESCE(SUM(talk_seconds),0) AS talk_seconds, COALESCE(SUM(word_count),0) AS words
             FROM meeting_speakers WHERE participant_id = ?',
            [$id]
        );
        return $row ?? ['meetings' => 0, 'talk_seconds' => 0, 'words' => 0];
    }

    /**
     * Nájde účastníka podľa mena (aj podľa aliasov, bez diakritiky a veľkosti písmen).
     * Vracia null, ak nie je dostatočne jednoznačná zhoda.
     */
    public static function matchByName(string $name, ?array $pool = null): ?array
    {
        $needle = self::normalize($name);
        if ($needle === '') {
            return null;
        }
        $pool ??= DB::all('SELECT * FROM participants');
        $best = null;
        $bestScore = 0.0;
        foreach ($pool as $p) {
            $candidates = [$p['name']];
            foreach (explode(',', (string) ($p['aliases'] ?? '')) as $a) {
                if (trim($a) !== '') {
                    $candidates[] = trim($a);
                }
            }
            // aj samotné krstné meno
            $first = explode(' ', trim((string) $p['name']))[0] ?? '';
            if ($first !== '') {
                $candidates[] = $first;
            }
            foreach ($candidates as $c) {
                $cn = self::normalize($c);
                if ($cn === '') {
                    continue;
                }
                $score = 0.0;
                if ($cn === $needle) {
                    $score = 1.0;
                } elseif (str_contains($cn, $needle) || str_contains($needle, $cn)) {
                    $score = 0.85;
                } else {
                    similar_text($cn, $needle, $pct);
                    $score = $pct / 100 * 0.8;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $p;
                }
            }
        }
        return $bestScore >= 0.72 ? $best : null;
    }

    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') {
            $s = $t;
        }
        return preg_replace('/[^a-z0-9 ]+/', '', $s) ?? $s;
    }
}
