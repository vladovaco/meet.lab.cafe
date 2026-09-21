<?php use App\Core\Csrf; ?>
<div class="page-head"><h1>Účastníci</h1><span class="muted"><?= count($participants) ?> ľudí</span></div>

<details class="card details-form">
  <summary class="btn btn-primary">+ Pridať účastníka</summary>
  <form method="post" action="<?= url('/participants') ?>" class="form">
    <?= Csrf::field() ?>
    <label>Meno a priezvisko<input type="text" name="name" class="input" required></label>
    <div class="grid-2">
      <label>Pozícia<input type="text" name="position" class="input"></label>
      <label>Firma / oddelenie<input type="text" name="organisation" class="input"></label>
    </div>
    <div class="grid-2">
      <label>E-mail<input type="email" name="email" class="input"></label>
      <label>Prezývky <span class="muted">(čiarkou)</span><input type="text" name="aliases" class="input" placeholder="Peťo, Peter K."></label>
    </div>
    <button class="btn btn-primary" type="submit">Uložiť</button>
  </form>
</details>

<?php if ($participants === []): ?>
  <div class="empty"><p>Zatiaľ žiadni účastníci. Pridajú sa automaticky pri priraďovaní rečníkov, alebo ich pridajte ručne.</p></div>
<?php endif; ?>
<div class="list">
  <?php foreach ($participants as $p): ?>
    <a class="card person-card" href="<?= url('/participants/' . $p['id']) ?>">
      <span class="avatar avatar-lg" style="--c:<?= e($p['color']) ?>"><?= e(initials($p['name'])) ?></span>
      <div class="person-body">
        <strong><?= e($p['name']) ?></strong>
        <div class="muted small"><?= e(implode(' · ', array_filter([$p['position'], $p['organisation']]))) ?></div>
        <div class="person-stats">
          <span><?= (int) $p['meetings_count'] ?> porád</span>
          <?php if ($p['open_tasks']): ?><span class="badge badge-open"><?= (int) $p['open_tasks'] ?> otvorených úloh</span><?php endif; ?>
          <?php if ($p['last_meeting']): ?><span class="muted">naposledy <?= e(format_date($p['last_meeting'], 'j. n. Y')) ?></span><?php endif; ?>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>
