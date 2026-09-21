<?php use App\Core\Csrf; $m = $meeting; ?>
<a class="back" href="<?= url('/meetings/' . $m['id']) ?>">‹ Späť na poradu</a>
<div class="page-head"><h1>Upraviť poradu</h1></div>
<form method="post" action="<?= url('/meetings/' . $m['id']) ?>" class="card form">
  <?= Csrf::field() ?>
  <label>Názov<input type="text" name="title" class="input" value="<?= e($m['title']) ?>" required></label>
  <div class="grid-2">
    <label>Dátum a čas<input type="datetime-local" name="meeting_date" class="input" value="<?= e(format_date($m['meeting_date'], 'Y-m-d\TH:i')) ?>"></label>
    <label>Miesto<input type="text" name="location" class="input" value="<?= e($m['location']) ?>"></label>
  </div>
  <label>Priečinok
    <select name="folder_id" class="input"><option value="">– žiadny –</option>
      <?php foreach ($folders as $f): ?><option value="<?= $f['id'] ?>" <?= $m['folder_id'] == $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach; ?>
    </select>
  </label>
  <fieldset class="fieldset"><legend>Štítky</legend>
    <div class="chips">
      <?php foreach ($tags as $t): ?><label class="chip chip-select" style="--c:<?= e($t['color']) ?>"><input type="checkbox" name="tags[]" value="<?= $t['id'] ?>" <?= in_array($t['id'], $meetingTags) ? 'checked' : '' ?>><span>#<?= e($t['name']) ?></span></label><?php endforeach; ?>
    </div>
    <input type="text" name="new_tags" class="input" placeholder="Nové štítky oddelené čiarkou">
  </fieldset>
  <fieldset class="fieldset"><legend>Účastníci porady</legend>
    <div class="chips">
      <?php foreach ($participants as $p): ?><label class="chip chip-select" style="--c:<?= e($p['color']) ?>"><input type="checkbox" name="participants[]" value="<?= $p['id'] ?>" <?= in_array($p['id'], $expected) ? 'checked' : '' ?>><span><?= e($p['name']) ?></span></label><?php endforeach; ?>
    </div>
  </fieldset>
  <label>Súhrn<textarea name="summary" class="input" rows="6"><?= e($m['summary']) ?></textarea></label>
  <button class="btn btn-primary btn-block" type="submit">Uložiť</button>
</form>
