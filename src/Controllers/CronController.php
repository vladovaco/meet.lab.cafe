<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Services\JobProcessor;

/**
 * Spracovanie fronty cez HTTP – pre hosting bez SSH, kde cron vie iba volať URL.
 * Nastavte CRON_TOKEN v .env a v paneli hostingu cron na: https://domena/cron/run?token=CRON_TOKEN
 */
final class CronController
{
    public function run(Request $r): void
    {
        $token = Env::get('CRON_TOKEN');
        $given = (string) $r->input('token', '');
        if (!$token || $given === '' || !hash_equals($token, $given)) {
            Response::json(['error' => 'Neplatný token.'], 403);
        }
        session_write_close();
        ignore_user_abort(true);
        set_time_limit(0);
        $log = [];
        $n = JobProcessor::runPending(5, static function (string $m) use (&$log): void { $log[] = $m; });
        Response::json(['ok' => true, 'processed' => $n, 'log' => $log]);
    }
}
