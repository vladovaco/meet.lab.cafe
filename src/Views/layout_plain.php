<?php use App\Core\Config; use App\Core\Flash; ?>
<!DOCTYPE html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#111827">
<title><?= e($title ?? '') ?> · <?= e(Config::get('app_name')) ?></title>
<link rel="icon" href="<?= url('/assets/icon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
</head>
<body class="plain">
<main class="page page-center">
  <?php foreach (Flash::pull() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?? '' ?>
</main>
</body>
</html>
