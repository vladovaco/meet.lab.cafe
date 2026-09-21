<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';

Env::load($root . '/.env');
Config::init($root);

date_default_timezone_set((string) Config::get('timezone'));
mb_internal_encoding('UTF-8');

if (Config::get('env') === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $root . '/storage/logs/php-error.log');
}

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

return $root;
