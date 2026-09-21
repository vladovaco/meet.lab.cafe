<?php $title = $title ?? 'Chyba'; ?>
<?php if (!isset($content)): ?>
<div class="card">
  <h1>Ups</h1>
  <p><?= nl2br(e($message ?? 'Stránka sa nenašla.')) ?></p>
  <a class="btn" href="<?= url('/') ?>">Späť na porady</a>
</div>
<?php endif; ?>
