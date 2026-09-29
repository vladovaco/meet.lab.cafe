<?php use App\Core\Csrf; $m = $meeting; ?>
<a class="back" href="<?= url('/meetings/' . $m['id']) ?>">‹ Späť na poradu</a>
<div class="page-head"><h1>Upraviť poradu</h1></div>
<form method="post" action="<?= url('/meetings/' . $m['id']) ?>" class="card form">
  <?= Csrf::field() ?>
  <label>Typ nahrávky
    <select name="recording_type" class="input">
      <?php foreach (\App\Services\Analysis\RecordingType::options() as $k => $label): ?><option value="<?= e($k) ?>" <?= $k === \App\Services\Analysis\RecordingType::normalize($m['recording_type'] ?? null) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <?php if (!empty($m['transcript_text'])): ?><span class="muted small">Po zmene typu sa zápis vytvorí znova podľa nového typu.</span><?php endif; ?>
  </label>
  <label>Názov<input type="text" name="title" class="input" value="<?= e($m['title']) ?>" required></label>
  <div class="grid-2">
    <label>Dátum a čas<input type="datetime-local" name="meeting_date" class="input" value="<?= e(format_date($m['meeting_date'], 'Y-m-d\TH:i')) ?>"></label>
    <label>Miesto<input type="text" name="location" class="input" value="<?= e($m['location']) ?>"></label>
  </div>
  <label>Priečinok
    <select name="folder_id" class="input js-folder-select"><option value="">– žiadny –</option>
      <?php foreach ($folders as $f): ?><option value="<?= $f['id'] ?>" <?= $m['folder_id'] == $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach; ?>
      <option value="__new">+ Nový priečinok…</option>
    </select>
  </label>
  <div class="field">
    <span class="field-label">Štítky</span>
    <?= \App\Core\View::partial('partials/picker', ['name' => 'tags[]', 'options' => $tags, 'selected' => $meetingTags, 'create' => 'tag', 'prefix' => '#', 'placeholder' => 'Začnite písať štítok…']) ?>
  </div>
  <div class="field">
    <span class="field-label">Účastníci porady</span>
    <?= \App\Core\View::partial('partials/picker', ['name' => 'participants[]', 'options' => $participants, 'selected' => $expected, 'create' => 'participant', 'placeholder' => 'Začnite písať meno…']) ?>
  </div>
  <label>Súhrn<textarea name="summary" class="input" rows="6"><?= e($m['summary']) ?></textarea></label>
  <button class="btn btn-primary btn-block" type="submit">Uložiť</button>
</form>
<?php \App\Core\View::addScript('/assets/js/picker.js'); ?>
