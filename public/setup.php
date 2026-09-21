<?php
/**
 * Webový inštalátor pre hosting bez SSH.
 * 1) nahrajte .env (s vyplneným SETUP_TOKEN) do koreňa aplikácie
 * 2) otvorte https://vasa-domena/setup.php?token=HODNOTA_SETUP_TOKEN
 * Vytvorí/aktualizuje tabuľky a (ak zadáte) admin používateľa. Po inštalácii SETUP_TOKEN z .env odstráňte.
 */
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database as DB;
use App\Core\Env;

require dirname(__DIR__) . '/src/bootstrap.php';

$token = Env::get('SETUP_TOKEN');
$given = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
if (!$token || $given === '' || !hash_equals($token, $given)) {
    http_response_code(403);
    exit('Inštalátor je vypnutý. Nastavte SETUP_TOKEN v .env a otvorte setup.php?token=...');
}

$messages = [];
$errors = [];
$db = Config::get('db');

try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try {
        $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $db['name']));
    } catch (\Throwable) {
        // na zdieľanom hostingu databázu vytvára admin panel – ignoruj
    }
    DB::pdo()->exec((string) file_get_contents(Config::get('root') . '/database/schema.sql'));
    $messages[] = 'Databázové tabuľky vytvorené / aktualizované.';
} catch (\Throwable $e) {
    $errors[] = 'Databáza: ' . $e->getMessage();
}

$storage = (string) Config::get('storage_path');
if (!is_dir($storage) && !@mkdir($storage, 0775, true)) {
    $errors[] = "Adresár $storage sa nedá vytvoriť – vytvorte ho cez FTP a nastavte práva na zápis.";
} elseif (!is_writable($storage)) {
    $errors[] = "Adresár $storage nie je zapisovateľný – nastavte práva (chmod 775).";
} else {
    $messages[] = 'Úložisko nahrávok je pripravené.';
}

if ($errors === [] && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['email']) && !empty($_POST['password'])) {
    $email = mb_strtolower(trim((string) $_POST['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen((string) $_POST['password']) < 8) {
        $errors[] = 'Zadajte platný e-mail a heslo s aspoň 8 znakmi.';
    } else {
        $hash = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
        $exists = DB::one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($exists) {
            DB::update('users', ['password_hash' => $hash], 'id = ?', [$exists['id']]);
            $messages[] = "Heslo používateľa $email bolo zmenené.";
        } else {
            DB::insert('users', ['email' => $email, 'name' => trim((string) ($_POST['name'] ?? '')) ?: 'Admin', 'password_hash' => $hash, 'role' => 'admin']);
            $messages[] = "Admin používateľ $email bol vytvorený.";
        }
    }
}

$userCount = $errors === [] ? (int) (DB::one('SELECT COUNT(*) AS c FROM users')['c'] ?? 0) : 0;
$checks = [
    'PHP verzia'            => [version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
    'Rozšírenie pdo_mysql'  => [extension_loaded('pdo_mysql'), ''],
    'Rozšírenie curl'       => [extension_loaded('curl'), ''],
    'Rozšírenie mbstring'   => [extension_loaded('mbstring'), ''],
    'Rozšírenie fileinfo'   => [extension_loaded('fileinfo'), ''],
    'vendor/ (Composer)'    => [is_file(Config::get('root') . '/vendor/autoload.php'), ''],
    'ELEVENLABS/ASSEMBLY kľúč' => [(bool) (Config::get('stt.elevenlabs_key') ?: Config::get('stt.assemblyai_key')), (string) Config::get('stt.provider')],
    'ANTHROPIC_API_KEY'     => [(bool) Config::get('anthropic.key'), (string) Config::get('anthropic.model')],
    'upload_max_filesize'   => [true, (string) ini_get('upload_max_filesize')],
    'post_max_size'         => [true, (string) ini_get('post_max_size')],
    'max_execution_time'    => [true, (string) ini_get('max_execution_time') . ' s'],
];
?>
<!DOCTYPE html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Inštalácia · Meet</title>
<link rel="stylesheet" href="assets/css/app.css"></head>
<body class="plain"><main class="page page-center"><div class="card login-card" style="max-width:520px">
<h1>Inštalácia</h1>
<?php foreach ($messages as $m): ?><div class="flash flash-success"><?= e($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $m): ?><div class="flash flash-error"><?= e($m) ?></div><?php endforeach; ?>
<ul class="kv">
<?php foreach ($checks as $label => [$ok, $detail]): ?>
  <li><span><?= e($label) ?></span><strong><?= $ok ? '✅' : '⚠️' ?> <?= e($detail) ?></strong></li>
<?php endforeach; ?>
</ul>
<?php if ($errors === []): ?>
<form method="post" class="form" style="margin-top:1rem">
  <input type="hidden" name="token" value="<?= e($given) ?>">
  <h2 class="h-small"><?= $userCount ? "Používatelia: $userCount · vytvoriť ďalšieho / zmeniť heslo" : 'Vytvoriť admin používateľa' ?></h2>
  <label>Meno<input type="text" name="name" class="input" value="Admin"></label>
  <label>E-mail<input type="email" name="email" class="input" required></label>
  <label>Heslo (min. 8 znakov)<input type="text" name="password" class="input" required minlength="8"></label>
  <button class="btn btn-primary btn-block" type="submit">Uložiť používateľa</button>
</form>
<?php if ($userCount): ?><p class="hint muted">Hotovo. Odstráňte SETUP_TOKEN z .env a <a href="./">prejdite na prihlásenie</a>.</p><?php endif; ?>
<?php endif; ?>
</div></main></body></html>
