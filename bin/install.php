#!/usr/bin/env php
<?php
/**
 * Inštalácia: vytvorí tabuľky a prvého admin používateľa.
 *   php bin/install.php --email=admin@example.com --password=tajne --name="Admin"
 */
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database as DB;

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('', ['email::', 'password::', 'name::', 'skip-schema']);
$out = static fn(string $m) => fwrite(STDOUT, "$m\n");

$db = Config::get('db');
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $db['name']));
} catch (\Throwable $e) {
    $out('Nepodarilo sa pripojiť k MySQL: ' . $e->getMessage());
    exit(1);
}

if (!isset($opts['skip-schema'])) {
    $sql = file_get_contents(Config::get('root') . '/database/schema.sql');
    DB::pdo()->exec($sql);
    $out('Schéma databázy vytvorená/aktualizovaná.');
}

$email = $opts['email'] ?? null;
$password = $opts['password'] ?? null;
if ($email && $password) {
    $exists = DB::one('SELECT id FROM users WHERE email = ?', [mb_strtolower($email)]);
    if ($exists) {
        DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$exists['id']]);
        $out("Heslo používateľa $email aktualizované.");
    } else {
        DB::insert('users', [
            'email' => mb_strtolower($email),
            'name' => $opts['name'] ?? 'Admin',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'admin',
        ]);
        $out("Admin používateľ $email vytvorený.");
    }
} else {
    $out('Tip: vytvorte admina cez --email=... --password=...');
}
$storage = Config::get('storage_path');
if (!is_dir($storage)) {
    mkdir($storage, 0775, true);
}
$out('Hotovo.');
