<?php
/**
 * Rozbalenie nasadenia z GitHub Actions (hosting bez SSH).
 * Workflow nahrá cez FTP: release.zip a .deploy-token (sha256 tokenu) do koreňa aplikácie
 * a potom zavolá https://domena/deploy.php?token=... – tento skript archív rozbalí a zmaže.
 * .env a storage/ sa nikdy neprepíšu (v archíve nie sú).
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
$root = dirname(__DIR__);
$tokenFile = $root . '/.deploy-token';
$zipFile = $root . '/release.zip';
$given = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if (!is_file($tokenFile) || $given === '') {
    http_response_code(403);
    exit("Deploy je vypnutý (chýba .deploy-token alebo token).\n");
}
if (!hash_equals(trim((string) file_get_contents($tokenFile)), hash('sha256', $given))) {
    http_response_code(403);
    exit("Neplatný token.\n");
}
if (!is_file($zipFile)) {
    http_response_code(404);
    exit("release.zip sa nenašiel v $root.\n");
}
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit("PHP rozšírenie zip nie je dostupné – zapnite ho v paneli hostingu.\n");
}

set_time_limit(600);
$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    http_response_code(500);
    exit("release.zip sa nedá otvoriť.\n");
}
$count = $zip->numFiles;
$skipped = 0;
for ($i = 0; $i < $count; $i++) {
    $name = (string) $zip->getNameIndex($i);
    // bezpečnostná poistka: žiadne absolútne cesty ani ../, nikdy .env a storage/
    if ($name === '' || str_starts_with($name, '/') || str_contains($name, '..') || $name === '.env' || str_starts_with($name, 'storage/')) {
        $skipped++;
        continue;
    }
    $target = $root . '/' . $name;
    if (str_ends_with($name, '/')) {
        if (!is_dir($target)) {
            mkdir($target, 0775, true);
        }
        continue;
    }
    $dir = dirname($target);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $data = $zip->getFromIndex($i);
    if ($data === false || file_put_contents($target, $data) === false) {
        http_response_code(500);
        exit("Nepodarilo sa zapísať $name\n");
    }
}
$zip->close();
unlink($zipFile);
foreach (['storage', 'storage/audio', 'storage/logs'] as $d) {
    if (!is_dir("$root/$d")) {
        @mkdir("$root/$d", 0775, true);
    }
}
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
$version = is_file("$root/VERSION") ? trim((string) file_get_contents("$root/VERSION")) : 'n/a';
echo "OK – rozbalených " . ($count - $skipped) . " položiek, verzia $version\n";
