<?php use App\Core\Csrf; ?>
<div class="page-head"><h1>Nastavenia</h1></div>
<div class="card">
  <h2 class="h-small">Prihlásený: <?= e($user['name']) ?> <span class="muted">(<?= e($user['email']) ?>)</span></h2>
  <form method="post" action="<?= url('/logout') ?>"><?= Csrf::field() ?><button class="btn" type="submit">Odhlásiť sa</button></form>
</div>
<div class="card">
  <h2 class="h-small">Stav služieb</h2>
  <ul class="kv">
    <li><span>Prepis reči</span><strong><?= e($config['stt_provider']) ?> <?= $config['stt_configured'] ? '✅' : '⚠️ chýba API kľúč' ?></strong></li>
    <li><span>AI zápis</span><strong><?= e($config['ai_model']) ?> <?= $config['ai_configured'] ? '✅' : '⚠️ chýba API kľúč' ?></strong></li>
    <li><span>Spracovanie</span><strong><?= $config['process_mode'] === 'cron' ? 'cron worker' : 'z prehliadača (web)' ?></strong></li>
    <li><span>Čakajúce úlohy</span><strong><?= (int) $pendingJobs ?></strong></li>
    <li><span>Limit nahrávky</span><strong><?= (int) $config['max_upload_mb'] ?> MB (PHP: upload <?= e($config['php_upload_max']) ?>, post <?= e($config['php_post_max']) ?>)</strong></li>
  </ul>
  <p class="hint muted">Kľúče a režim sa nastavujú v súbore <code>.env</code> na serveri.</p>
</div>
<?php if ($failedJobs): ?>
<div class="card">
  <h2 class="h-small">Neúspešné úlohy</h2>
  <ul class="simple-list">
    <?php foreach ($failedJobs as $j): ?><li><a href="<?= url('/meetings/' . $j['meeting_id']) ?>"><?= e($j['title']) ?></a><span class="muted small"><?= e($j['type']) ?>: <?= e(mb_strimwidth((string) $j['last_error'], 0, 120, '…')) ?></span></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<form method="post" action="<?= url('/settings/notify') ?>" class="card form">
  <?= Csrf::field() ?>
  <h2 class="h-small">E-mail so zápisom</h2>
  <p class="hint muted" style="margin:0">Odosielanie: <?= $mailEnabled ? e($mailMode) : 'vypnuté (MAIL_ENABLED=false)' ?><?= $mailAuto ? ' · automatické odoslanie po spracovaní je zapnuté' : ' · automatické odoslanie je vypnuté (MAIL_AUTO_SEND)' ?></p>
  <label class="switch"><input type="checkbox" name="notify_email" value="1" <?= !empty($user['notify_email']) ? 'checked' : '' ?>><span>Po dokončení spracovania mi poslať zápis a prepis na <?= e($user['email']) ?></span></label>
  <button class="btn" type="submit">Uložiť</button>
</form>
<form method="post" action="<?= url('/settings/password') ?>" class="card form">
  <?= Csrf::field() ?>
  <h2 class="h-small">Zmena hesla</h2>
  <label>Súčasné heslo<input type="password" name="current" class="input" required autocomplete="current-password"></label>
  <label>Nové heslo<input type="password" name="new" class="input" required minlength="8" autocomplete="new-password"></label>
  <button class="btn" type="submit">Zmeniť heslo</button>
</form>
<?php if ($users): ?>
<div class="card">
  <h2 class="h-small">Používatelia</h2>
  <ul class="simple-list">
    <?php foreach ($users as $u): ?><li><span><?= e($u['name']) ?> <span class="muted small"><?= e($u['email']) ?> · <?= e($u['role']) ?></span></span><span class="muted small"><?= $u['last_login_at'] ? e(format_date($u['last_login_at'])) : 'neprihlásený' ?></span></li><?php endforeach; ?>
  </ul>
  <form method="post" action="<?= url('/settings/users') ?>" class="form">
    <?= Csrf::field() ?>
    <div class="grid-2">
      <label>Meno<input type="text" name="name" class="input" required></label>
      <label>E-mail<input type="email" name="email" class="input" required></label>
    </div>
    <div class="grid-2">
      <label>Heslo<input type="text" name="password" class="input" required minlength="8"></label>
      <label>Rola<select name="role" class="input"><option value="member">člen</option><option value="admin">admin</option></select></label>
    </div>
    <button class="btn" type="submit">Vytvoriť používateľa</button>
  </form>
</div>
<?php endif; ?>
