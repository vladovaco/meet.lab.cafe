<?php /** @var array $meetings, $filters, $folders, $tags, $participants, $counts */
$hasFilter = array_filter($filters);
?>
<div class="page-head">
  <h1>Porady</h1>
  <div class="stats">
    <span><strong><?= $counts['total'] ?></strong> porád</span>
    <?php if ($counts['processing']): ?><span class="stat-processing"><strong><?= $counts['processing'] ?></strong> spracúva sa</span><?php endif; ?>
    <span><strong><?= $counts['open_tasks'] ?></strong> otvorených úloh</span>
  </div>
</div>

<form class="filters" method="get" action="<?= url('/') ?>">
  <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Filtrovať podľa názvu…" class="input">
  <div class="filters-row">
    <select name="folder" class="input"><option value="">Priečinok</option>
      <?php foreach ($folders as $f): ?><option value="<?= $f['id'] ?>" <?= $filters['folder_id'] == $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="tag" class="input"><option value="">Štítok</option>
      <?php foreach ($tags as $t): ?><option value="<?= $t['id'] ?>" <?= $filters['tag_id'] == $t['id'] ? 'selected' : '' ?>>#<?= e($t['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="participant" class="input"><option value="">Účastník</option>
      <?php foreach ($participants as $p): ?><option value="<?= $p['id'] ?>" <?= $filters['participant_id'] == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="status" class="input"><option value="">Stav</option>
      <?php foreach (['done','queued','transcribing','analyzing','error'] as $s): ?><option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filtrovať</button>
    <?php if ($hasFilter): ?><a class="btn btn-ghost" href="<?= url('/') ?>">Zrušiť</a><?php endif; ?>
  </div>
</form>

<?php if ($meetings === []): ?>
  <div class="empty">
    <p><?= $hasFilter ? 'Žiadne porady nezodpovedajú filtru.' : 'Zatiaľ tu nie je žiadna porada.' ?></p>
    <a class="btn btn-primary" href="<?= url('/meetings/new') ?>">Nahrať prvú poradu</a>
  </div>
<?php else: ?>
  <div class="list">
    <?php foreach ($meetings as $m): ?>
      <?= \App\Core\View::partial('partials/meeting_card', ['m' => $m]) ?>
    <?php endforeach; ?>
  </div>
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">‹ Novšie</a><?php endif; ?>
    <?php if ($hasMore): ?><a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Staršie ›</a><?php endif; ?>
  </div>
<?php endif; ?>
