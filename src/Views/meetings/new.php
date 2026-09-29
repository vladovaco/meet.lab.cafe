<?php use App\Core\Csrf;
$appendTo ??= null;
$parts ??= [];
?>
<?php if ($appendTo): ?>
<div class="page-head">
  <a class="back" href="<?= url('/meetings/' . $appendTo['id']) ?>">‹ <?= e($appendTo['title']) ?></a>
  <h1>Pokračovať v nahrávaní</h1>
  <p class="muted">Nová nahrávka sa pridá do porady ako časť <?= count($parts) + 1 ?><?= $appendTo['audio_duration'] ? ' (doteraz nahraté ' . e(format_duration((float) $appendTo['audio_duration'])) . ')' : '' ?>. Prepíše sa len nová časť a zápis sa potom vytvorí z celej porady.</p>
</div>
<?php else: ?>
<div class="page-head"><h1>Nová porada</h1></div>
<?php endif; ?>

<div class="card recovery" id="recovery" hidden>
  <h2 class="h-small">⚠️ Neuložená nahrávka</h2>
  <p class="muted small">Prehliadač našiel nahrávku, ktorá nebola uložená na server (napr. po vybití telefónu alebo páde prehliadača).</p>
  <div id="recovery-list"></div>
</div>

<div class="card" id="recorder">
  <div class="seg" role="tablist">
    <button type="button" class="seg-btn is-active" data-mode="record">🎙 Nahrať</button>
    <button type="button" class="seg-btn" data-mode="upload">📁 Nahrať súbor</button>
  </div>

  <div class="mode mode-record">
    <div class="rec-visual">
      <canvas id="rec-wave" width="600" height="80"></canvas>
      <div class="rec-time" id="rec-time">00:00</div>
      <div class="rec-status muted" id="rec-status">Pripravené. Klepnutím na tlačidlo začnete nahrávať.</div>
    </div>
    <div class="rec-controls">
      <button type="button" class="rec-btn" id="rec-start" aria-label="Začať nahrávať"><span class="rec-dot"></span></button>
      <button type="button" class="btn" id="rec-pause" hidden>⏸ Pauza</button>
      <button type="button" class="btn btn-danger" id="rec-stop" hidden>■ Zastaviť</button>
    </div>
    <div class="rec-preview" id="rec-preview" hidden>
      <div class="rec-parts" id="rec-parts"></div>
      <p class="hint muted">Chcete pokračovať? Stlačte znova tlačidlo nahrávania – pridá sa ďalšia časť tej istej porady.</p>
      <button type="button" class="btn btn-ghost" id="rec-discard">Zahodiť všetko a nahrať znova</button>
    </div>
    <p class="hint muted">Telefón nechajte odomknutý a stránku otvorenú, inak prehliadač nahrávanie preruší. Pri dlhých poradách odporúčame pripojiť nabíjačku. Nahrávka sa každých 5 sekúnd zálohuje do pamäte prehliadača, takže po vybití alebo páde sa dá obnoviť.</p>
  </div>

  <div class="mode mode-upload" hidden>
    <label class="dropzone" id="dropzone">
      <input type="file" id="file-input" accept="audio/*,video/webm,video/mp4,.m4a,.mp3,.wav,.ogg,.webm,.flac,.aac,.amr,.3gp">
      <span class="dropzone-icon">⬆️</span>
      <span class="dropzone-text">Vyberte alebo sem presuňte audio súbor</span>
      <span class="muted">mp3, m4a, wav, webm, ogg, flac · max <?= (int) $maxUploadMb ?> MB</span>
    </label>
    <div class="file-info muted" id="file-info" hidden></div>
  </div>

  <form id="meeting-form" class="form" autocomplete="off"<?= $appendTo ? ' data-action="/api/meetings/' . (int) $appendTo['id'] . '/audio"' : '' ?>>
    <?= Csrf::field() ?>
    <?php if (!$appendTo): ?>
    <label>Typ nahrávky
      <select name="recording_type" class="input">
        <?php foreach (\App\Services\Analysis\RecordingType::options() as $k => $label): ?><option value="<?= e($k) ?>" <?= $k === \App\Services\Analysis\RecordingType::DEFAULT ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Názov<input type="text" name="title" class="input" placeholder="napr. Týždenná porada tímu (AI navrhne názov, ak necháte prázdne)"></label>
    <div class="grid-2">
      <label>Dátum a čas<input type="datetime-local" name="meeting_date" class="input" value="<?= date('Y-m-d\TH:i') ?>"></label>
      <label>Miesto<input type="text" name="location" class="input" placeholder="kancelária, online…"></label>
    </div>
    <div class="grid-2">
      <label>Priečinok
        <select name="folder_id" class="input js-folder-select"><option value="">– žiadny –</option>
          <?php foreach ($folders as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?>
          <option value="__new">+ Nový priečinok…</option>
        </select>
      </label>
      <label>Jazyk nahrávky
        <select name="language" class="input">
          <option value="sk" <?= $defaultLang === 'sk' ? 'selected' : '' ?>>slovenčina</option>
          <option value="cs" <?= $defaultLang === 'cs' ? 'selected' : '' ?>>čeština</option>
          <option value="en" <?= $defaultLang === 'en' ? 'selected' : '' ?>>angličtina</option>
          <option value="de">nemčina</option>
          <option value="hu">maďarčina</option>
          <option value="pl">poľština</option>
          <option value="auto">automaticky rozpoznať</option>
        </select>
      </label>
    </div>

    <div class="field">
      <span class="field-label">Účastníci <span class="muted">(pomáha rozpoznať rečníkov)</span></span>
      <?= \App\Core\View::partial('partials/picker', ['name' => 'participants[]', 'options' => $participants, 'selected' => [], 'create' => 'participant', 'placeholder' => 'Začnite písať meno…']) ?>
    </div>

    <div class="field">
      <span class="field-label">Štítky</span>
      <?= \App\Core\View::partial('partials/picker', ['name' => 'tags[]', 'options' => $tags, 'selected' => [], 'create' => 'tag', 'prefix' => '#', 'placeholder' => 'Začnite písať štítok…']) ?>
    </div>
    <?php endif; ?>

    <div class="progress" id="upload-progress" hidden><div class="progress-bar"></div><span class="progress-label">Nahráva sa…</span></div>
    <div class="form-error" id="form-error" hidden></div>
    <button type="submit" class="btn btn-primary btn-block btn-lg" id="submit-btn" disabled><?= $appendTo ? 'Pridať do porady a spustiť prepis' : 'Uložiť a spustiť prepis' ?></button>
  </form>
</div>
<?php \App\Core\View::addScript('/assets/js/picker.js'); \App\Core\View::addScript('/assets/js/recstore.js'); \App\Core\View::addScript('/assets/js/recorder.js'); ?>
