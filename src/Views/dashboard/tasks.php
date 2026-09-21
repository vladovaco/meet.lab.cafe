<div class="page-head"><h1>Otvorené úlohy</h1><span class="muted"><?= (int) $total ?> celkom</span></div>
<?php if ($byPerson === []): ?>
  <div class="empty"><p>Žiadne otvorené úlohy. 🎉</p></div>
<?php endif; ?>
<?php foreach ($byPerson as $person => $items): ?>
  <section class="card">
    <h2 class="h-small"><?= e($person) ?> <span class="muted">(<?= count($items) ?>)</span></h2>
    <ul class="task-list">
      <?php foreach ($items as $a): ?>
        <li class="task" data-id="<?= $a['id'] ?>">
          <label class="task-check"><input type="checkbox" class="js-task-toggle" data-id="<?= $a['id'] ?>"><span></span></label>
          <div class="task-body">
            <div class="task-desc"><?= e($a['description']) ?></div>
            <div class="task-meta">
              <a href="<?= url('/meetings/' . $a['meeting_id']) ?>"><?= e($a['meeting_title']) ?></a>
              <?php if ($a['due_date']): ?><span class="due <?= $a['due_date'] < date('Y-m-d') ? 'overdue' : '' ?>">📅 <?= e(date('j. n. Y', strtotime($a['due_date']))) ?></span><?php endif; ?>
              <?php if ($a['priority'] === 'high'): ?><span class="badge badge-high">vysoká</span><?php endif; ?>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
