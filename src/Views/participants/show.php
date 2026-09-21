<?php use App\Core\Csrf; $p = $participant; ?>
<a class="back" href="<?= url('/participants') ?>">‹ Účastníci</a>
<div class="person-head">
  <span class="avatar avatar-xl" style="--c:<?= e($p['color']) ?>"><?= e(initials($p['name'])) ?></span>
  <div>
    <h1><?= e($p['name']) ?></h1>
    <div class="muted"><?= e(implode(' · ', array_filter([$p['position'], $p['organisation'], $p['email']]))) ?></div>
  </div>
</div>
<div class="stats stats-grid">
  <div class="card stat"><strong><?= (int) $stats['meetings'] ?></strong><span>porád</span></div>
  <div class="card stat"><strong><?= e(format_duration((float) $stats['talk_seconds'])) ?></strong><span>hovoril/a</span></div>
  <div class="card stat"><strong><?= count(array_filter($tasks, fn($t) => $t['status'] === 'open')) ?></strong><span>otvorených úloh</span></div>
</div>

<div class="tabs" role="tablist">
  <button class="tab is-active" data-tab="tasks">Úlohy</button>
  <button class="tab" data-tab="meetings">Porady</button>
  <button class="tab" data-tab="edit">Profil</button>
</div>

<section class="tab-panel is-active" data-panel="tasks">
  <div class="card">
    <?php if ($tasks === []): ?><p class="muted">Žiadne úlohy.</p><?php endif; ?>
    <ul class="task-list">
      <?php foreach ($tasks as $a): ?>
        <li class="task <?= $a['status'] === 'done' ? 'is-done' : '' ?>" data-id="<?= $a['id'] ?>">
          <label class="task-check"><input type="checkbox" class="js-task-toggle" data-id="<?= $a['id'] ?>" <?= $a['status'] === 'done' ? 'checked' : '' ?>><span></span></label>
          <div class="task-body">
            <div class="task-desc"><?= e($a['description']) ?></div>
            <div class="task-meta"><a href="<?= url('/meetings/' . $a['meeting_id']) ?>"><?= e($a['meeting_title']) ?></a> · <?= e(format_date($a['meeting_date'], 'j. n. Y')) ?><?php if ($a['due_date']): ?> · <span class="due <?= $a['due_date'] < date('Y-m-d') && $a['status'] === 'open' ? 'overdue' : '' ?>">do <?= e(date('j. n. Y', strtotime($a['due_date']))) ?></span><?php endif; ?></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="tab-panel" data-panel="meetings">
  <div class="list">
    <?php foreach ($meetings as $m): ?><?= \App\Core\View::partial('partials/meeting_card', ['m' => $m]) ?><?php endforeach; ?>
    <?php if ($meetings === []): ?><p class="muted">Zatiaľ žiadne porady.</p><?php endif; ?>
  </div>
</section>

<section class="tab-panel" data-panel="edit">
  <form method="post" action="<?= url('/participants/' . $p['id']) ?>" class="card form">
    <?= Csrf::field() ?>
    <label>Meno<input type="text" name="name" class="input" value="<?= e($p['name']) ?>" required></label>
    <div class="grid-2">
      <label>Pozícia<input type="text" name="position" class="input" value="<?= e($p['position']) ?>"></label>
      <label>Firma / oddelenie<input type="text" name="organisation" class="input" value="<?= e($p['organisation']) ?>"></label>
    </div>
    <div class="grid-2">
      <label>E-mail<input type="email" name="email" class="input" value="<?= e($p['email']) ?>"></label>
      <label>Farba<input type="color" name="color" class="input" value="<?= e($p['color']) ?>"></label>
    </div>
    <label>Prezývky / iné podoby mena <span class="muted">(čiarkou – pomáha AI rozpoznať rečníka)</span><input type="text" name="aliases" class="input" value="<?= e($p['aliases']) ?>"></label>
    <label>Poznámky<textarea name="notes" class="input" rows="3"><?= e($p['notes']) ?></textarea></label>
    <button class="btn btn-primary" type="submit">Uložiť</button>
  </form>
  <?php if ($others): ?>
  <form method="post" action="<?= url('/participants/' . $p['id'] . '/merge') ?>" class="card form" onsubmit="return confirm('Zlúčiť tohto účastníka do vybraného? Tento profil sa zmaže.')">
    <?= Csrf::field() ?>
    <h2 class="h-small">Zlúčiť duplicitu</h2>
    <div class="inline-add">
      <select name="into" class="input"><?php foreach ($others as $o): ?><option value="<?= $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select>
      <button class="btn" type="submit">Zlúčiť do</button>
    </div>
  </form>
  <?php endif; ?>
  <form method="post" action="<?= url('/participants/' . $p['id'] . '/delete') ?>" onsubmit="return confirm('Naozaj zmazať účastníka? Porady a úlohy ostanú, iba stratia priradenie.')">
    <?= Csrf::field() ?><button class="btn btn-danger" type="submit">Zmazať účastníka</button>
  </form>
</section>
