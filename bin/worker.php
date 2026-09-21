#!/usr/bin/env php
<?php
/**
 * Spracovanie fronty úloh (prepis + analýza).
 *   php bin/worker.php          – spracuje čakajúce úlohy a skončí (vhodné pre cron každú minútu)
 *   php bin/worker.php --loop   – beží stále (Docker / systemd)
 */
declare(strict_types=1);

use App\Services\JobProcessor;

require dirname(__DIR__) . '/src/bootstrap.php';
set_time_limit(0);

$loop = in_array('--loop', $argv, true);
$log = static fn(string $m) => fwrite(STDOUT, '[' . date('H:i:s') . "] $m\n");

do {
    try {
        $n = JobProcessor::runPending(50, $log);
    } catch (\Throwable $e) {
        $log('Fatálna chyba: ' . $e->getMessage());
        $n = 0;
    }
    if ($loop) {
        sleep($n > 0 ? 1 : 5);
    }
} while ($loop);
