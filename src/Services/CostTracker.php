<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database as DB;

/** Odhad a evidencia nákladov na prepis a AI analýzu podľa config/pricing.php. */
final class CostTracker
{
    private static ?array $pricing = null;

    public static function pricing(): array
    {
        return self::$pricing ??= require Config::get('root') . '/config/pricing.php';
    }

    /** Prepis: cena podľa dĺžky audia. $provider napr. "elevenlabs:scribe_v2" alebo "assemblyai". */
    public static function recordTranscription(int $meetingId, string $provider, ?float $audioSeconds): void
    {
        [$name, $model] = array_pad(explode(':', $provider, 2), 2, null);
        $rate = self::pricing()['stt'][$name] ?? null;
        $seconds = max(0.0, (float) ($audioSeconds ?? 0));
        $cost = $rate !== null ? $seconds / 3600 * $rate : 0.0;
        DB::insert('meeting_costs', [
            'meeting_id'    => $meetingId,
            'step'          => 'transcribe',
            'provider'      => $name,
            'model'         => $model,
            'audio_seconds' => round($seconds, 2),
            'cost_usd'      => round($cost, 5),
            'priced'        => $rate !== null ? 1 : 0,
        ]);
    }

    /** Analýza: cena podľa tokenov. $usage = ['input_tokens'=>, 'output_tokens'=>, 'model'=>]. */
    public static function recordAnalysis(int $meetingId, string $provider, array $usage): void
    {
        $model = (string) ($usage['model'] ?? '');
        $in = (int) ($usage['input_tokens'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? 0);
        $rates = self::llmRates($model);
        $cost = $rates ? ($in * $rates[0] + $out * $rates[1]) / 1_000_000 : 0.0;
        DB::insert('meeting_costs', [
            'meeting_id'    => $meetingId,
            'step'          => 'analyze',
            'provider'      => $provider,
            'model'         => mb_substr($model, 0, 80),
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'cost_usd'      => round($cost, 5),
            'priced'        => $rates ? 1 : 0,
        ]);
    }

    /** @return array{0:float,1:float}|null */
    public static function llmRates(string $model): ?array
    {
        $best = null;
        $bestLen = 0;
        foreach (self::pricing()['llm'] as $prefix => $rates) {
            if (str_starts_with($model, $prefix) && strlen($prefix) > $bestLen) {
                $best = $rates;
                $bestLen = strlen($prefix);
            }
        }
        return $best;
    }

    public static function forMeeting(int $meetingId): array
    {
        return DB::all('SELECT * FROM meeting_costs WHERE meeting_id = ? ORDER BY id', [$meetingId]);
    }

    public static function totalForMeeting(int $meetingId): float
    {
        return (float) (DB::one('SELECT COALESCE(SUM(cost_usd),0) AS c FROM meeting_costs WHERE meeting_id = ?', [$meetingId])['c'] ?? 0);
    }

    /** Súhrny pre admin prehľad. */
    public static function summary(): array
    {
        return [
            'months' => DB::all(
                'SELECT DATE_FORMAT(created_at, "%Y-%m") AS month, COUNT(DISTINCT meeting_id) AS meetings,
                        SUM(cost_usd) AS cost, SUM(CASE WHEN step = "transcribe" THEN cost_usd ELSE 0 END) AS stt_cost,
                        SUM(CASE WHEN step = "analyze" THEN cost_usd ELSE 0 END) AS llm_cost,
                        SUM(CASE WHEN step = "transcribe" THEN audio_seconds ELSE 0 END) AS audio_seconds,
                        SUM(COALESCE(input_tokens,0)) AS input_tokens, SUM(COALESCE(output_tokens,0)) AS output_tokens
                 FROM meeting_costs GROUP BY month ORDER BY month DESC LIMIT 12'
            ),
            'providers' => DB::all(
                'SELECT step, provider, COALESCE(model, "") AS model, COUNT(*) AS runs, SUM(cost_usd) AS cost, MIN(priced) AS priced
                 FROM meeting_costs WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                 GROUP BY step, provider, model ORDER BY cost DESC'
            ),
            'meetings' => DB::all(
                'SELECT m.id, m.title, m.meeting_date, m.audio_duration, m.status, u.name AS author,
                        SUM(c.cost_usd) AS cost, COUNT(c.id) AS runs, MIN(c.priced) AS priced, MAX(c.created_at) AS last_run
                 FROM meeting_costs c JOIN meetings m ON m.id = c.meeting_id LEFT JOIN users u ON u.id = m.created_by
                 GROUP BY m.id, m.title, m.meeting_date, m.audio_duration, m.status, u.name ORDER BY last_run DESC LIMIT 100'
            ),
            'total' => (float) (DB::one('SELECT COALESCE(SUM(cost_usd),0) AS c FROM meeting_costs')['c'] ?? 0),
            'total_30d' => (float) (DB::one('SELECT COALESCE(SUM(cost_usd),0) AS c FROM meeting_costs WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)')['c'] ?? 0),
        ];
    }

    public static function format(float $usd): string
    {
        $rate = (float) (self::pricing()['eur_rate'] ?? 0);
        $s = '$' . number_format($usd, $usd < 0.1 ? 4 : 2, '.', ' ');
        return $rate > 0 ? $s . ' (≈ ' . number_format($usd * $rate, $usd * $rate < 0.1 ? 4 : 2, ',', ' ') . ' €)' : $s;
    }
}
