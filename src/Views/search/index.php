<div class="page-head"><h1>Hľadať</h1></div>
<form method="get" action="<?= url('/search') ?>" class="search-form">
  <input type="search" name="q" class="input input-lg" value="<?= e($q) ?>" placeholder="Hľadať v názvoch, zápisoch, prepisoch a úlohách…" autofocus>
  <button class="btn btn-primary" type="submit">Hľadať</button>
</form>
<?php if ($q !== '' && $results === []): ?><div class="empty"><p>Nič sa nenašlo pre „<?= e($q) ?>“.</p></div><?php endif; ?>
<div class="list">
  <?php foreach ($results as $r): ?>
    <a class="card meeting-card" href="<?= url('/meetings/' . $r['id']) ?>">
      <div class="meeting-card-head"><span class="meeting-date"><?= e(format_date($r['meeting_date'], 'j. n. Y')) ?></span><span class="badge badge-<?= e($r['status']) ?>"><?= e(status_label($r['status'])) ?></span></div>
      <h3 class="meeting-title"><?= e($r['title']) ?></h3>
      <?php $hit = $r['hit_segment'] ?? $r['hit_task'] ?? $r['summary'] ?? ''; ?>
      <?php if ($hit): ?><p class="meeting-summary"><?= preg_replace('/(' . preg_quote(e($q), '/') . ')/iu', '<mark>$1</mark>', e(mb_strimwidth($hit, max(0, mb_stripos($hit, $q) - 60), 220, '…'))) ?></p><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<div class="card quick-links">
  <a href="<?= url('/organise') ?>" class="btn">📁 Priečinky a štítky</a>
  <a href="<?= url('/settings') ?>" class="btn">⚙️ Nastavenia</a>
</div>
