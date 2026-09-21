<?php
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
$active = $active ?? '';
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#111827">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<title><?= e($title ?? '') ?> · <?= e(Config::get('app_name')) ?></title>
<link rel="manifest" href="<?= url('/manifest.webmanifest') ?>">
<link rel="apple-touch-icon" href="<?= url('/assets/icon.svg') ?>">
<link rel="icon" href="<?= url('/assets/icon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body data-base="<?= e(url('/')) ?>">
<header class="topbar">
  <a class="brand" href="<?= url('/') ?>"><span class="brand-dot"></span><?= e(Config::get('app_name')) ?></a>
  <nav class="topnav">
    <a href="<?= url('/') ?>" class="<?= $active === 'meetings' ? 'is-active' : '' ?>">Porady</a>
    <a href="<?= url('/tasks') ?>" class="<?= $active === 'tasks' ? 'is-active' : '' ?>">Úlohy</a>
    <a href="<?= url('/participants') ?>" class="<?= $active === 'participants' ? 'is-active' : '' ?>">Účastníci</a>
    <a href="<?= url('/organise') ?>" class="<?= $active === 'organise' ? 'is-active' : '' ?>">Triedenie</a>
    <a href="<?= url('/search') ?>" class="<?= $active === 'search' ? 'is-active' : '' ?>">Hľadať</a>
    <a href="<?= url('/settings') ?>" class="<?= $active === 'settings' ? 'is-active' : '' ?>"><?= e($user['name'] ?? 'Nastavenia') ?></a>
  </nav>
  <a class="btn btn-primary topbar-cta" href="<?= url('/meetings/new') ?>">+ Nahrať</a>
</header>

<main class="page">
  <?php foreach (Flash::pull() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?? '' ?>
</main>

<nav class="tabbar" aria-label="Hlavná navigácia">
  <a href="<?= url('/') ?>" class="<?= $active === 'meetings' ? 'is-active' : '' ?>"><svg viewBox="0 0 24 24"><path d="M4 5h16v2H4zm0 6h16v2H4zm0 6h10v2H4z"/></svg><span>Porady</span></a>
  <a href="<?= url('/tasks') ?>" class="<?= $active === 'tasks' ? 'is-active' : '' ?>"><svg viewBox="0 0 24 24"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg><span>Úlohy</span></a>
  <a href="<?= url('/meetings/new') ?>" class="tab-record <?= $active === 'new' ? 'is-active' : '' ?>"><span class="tab-record-btn"><svg viewBox="0 0 24 24"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11z"/></svg></span><span>Nahrať</span></a>
  <a href="<?= url('/participants') ?>" class="<?= $active === 'participants' ? 'is-active' : '' ?>"><svg viewBox="0 0 24 24"><path d="M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm-8 1a3 3 0 1 0-3-3 3 3 0 0 0 3 3zm8 2c-2.7 0-8 1.3-8 4v2h16v-2c0-2.7-5.3-4-8-4zm-8 1c-.3 0-.6 0-.9.1C5.6 16 4 17.1 4 18.5V20H0v-1.5C0 16.2 4 15 8 15z"/></svg><span>Ľudia</span></a>
  <a href="<?= url('/search') ?>" class="<?= in_array($active, ['search','organise','settings'], true) ? 'is-active' : '' ?>"><svg viewBox="0 0 24 24"><path d="M15.5 14h-.8l-.3-.3a6.5 6.5 0 1 0-.7.7l.3.3v.8l5 5 1.5-1.5-5-5zm-6 0a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9z"/></svg><span>Viac</span></a>
</nav>
<script src="<?= asset('/assets/js/app.js') ?>" defer></script>
<?php foreach (\App\Core\View::$scripts as $s): ?><script src="<?= asset($s) ?>" defer></script><?php endforeach; ?>
</body>
</html>
