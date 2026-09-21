<?php use App\Core\Config; use App\Core\Csrf; ?>
<div class="card login-card">
  <div class="login-brand"><span class="brand-dot"></span><?= e(Config::get('app_name')) ?></div>
  <p class="muted">Nahrávanie, prepis a zápisy z porád.</p>
  <form method="post" action="<?= url('/login') ?>" class="form">
    <?= Csrf::field() ?>
    <label>E-mail<input type="email" name="email" required autocomplete="username" inputmode="email"></label>
    <label>Heslo<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary btn-block" type="submit">Prihlásiť sa</button>
  </form>
</div>
