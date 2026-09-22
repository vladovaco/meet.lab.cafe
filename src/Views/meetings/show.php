<?php
use App\Core\Csrf;
$m = $meeting;
$processing = in_array($m['status'], ['queued', 'transcribing', 'transcribed', 'analyzing'], true);
$speakerName = static function (?string $label) use ($speakers, $speakerIndex): array {
    if ($label === null || !isset($speakerIndex[$label])) {
        return ['name' => $label ?? '?', 'color' => '#9ca3af', 'idx' => 0];
    }
    $s = $speakers[$speakerIndex[$label]];
    return [
        'name'  => $s['participant_name'] ?? ($s['suggested_name'] ? $s['suggested_name'] . ' ?' : 'Rečník ' . ($speakerIndex[$label] + 1)),
        'color' => $s['participant_color'] ?? speaker_color($speakerIndex[$label]),
        'idx'   => $speakerIndex[$label],
    ];
};
$analysis = $m['analysis_json'] ? json_decode($m['analysis_json'], true) : null;
?>
<div class="meeting-head" data-meeting-id="<?= $m['id'] ?>" data-status="<?= e($m['status']) ?>" data-process-mode="<?= e($processMode) ?>">
  <a class="back" href="<?= url('/') ?>">‹ Porady</a>
  <h1 class="meeting-h1"><?= e($m['title']) ?></h1>
  <div class="meeting-meta">
    <span>📅 <?= e(format_date($m['meeting_date'], 'l j. n. Y · H:i')) ?></span>
    <?php if ($m['location']): ?><span>📍 <?= e($m['location']) ?></span><?php endif; ?>
    <?php if ($m['audio_duration']): ?><span>⏱ <?= e(format_duration((float) $m['audio_duration'])) ?></span><?php endif; ?>
    <?php if ($m['folder_name']): ?><span class="chip" style="--c:<?= e($m['folder_color']) ?>">📁 <?= e($m['folder_name']) ?></span><?php endif; ?>
    <?php foreach ($tags as $t): ?><a class="chip chip-tag" style="--c:<?= e($t['color']) ?>" href="<?= url('/?tag=' . $t['id']) ?>">#<?= e($t['name']) ?></a><?php endforeach; ?>
  </div>
  <div class="meeting-actions">
    <a class="btn btn-sm" href="<?= url('/meetings/' . $m['id'] . '/edit') ?>">✏️ Upraviť</a>
    <a class="btn btn-sm" href="<?= url('/meetings/' . $m['id'] . '/export.md') ?>">⬇ Zápis (.md)</a>
    <a class="btn btn-sm" href="<?= url('/meetings/' . $m['id'] . '/export.txt') ?>">⬇ Prepis (.txt)</a>
    <button type="button" class="btn btn-sm" id="copy-notes">📋 Kopírovať zápis</button>
    <?php if (!$processing && $m['status'] !== 'error'): ?><button type="button" class="btn btn-sm" id="open-mail">✉️ Poslať e-mailom</button><?php endif; ?>
    <details class="more">
      <summary class="btn btn-sm">⋯</summary>
      <div class="more-menu">
        <form method="post" action="<?= url('/meetings/' . $m['id'] . '/reprocess') ?>"><?= Csrf::field() ?><input type="hidden" name="mode" value="analyze"><button class="btn btn-sm btn-block" type="submit">🔁 Znovu vytvoriť zápis</button></form>
        <form method="post" action="<?= url('/meetings/' . $m['id'] . '/reprocess') ?>"><?= Csrf::field() ?><input type="hidden" name="mode" value="transcribe"><button class="btn btn-sm btn-block" type="submit">🔁 Znovu prepísať audio</button></form>
        <form method="post" action="<?= url('/meetings/' . $m['id'] . '/delete') ?>" onsubmit="return confirm('Naozaj zmazať poradu vrátane nahrávky?')"><?= Csrf::field() ?><button class="btn btn-sm btn-danger btn-block" type="submit">🗑 Zmazať poradu</button></form>
      </div>
    </details>
  </div>
</div>

<?php if ($m['audio_path']): ?>
  <div class="card audio-card">
    <audio id="player" controls preload="metadata" src="<?= url('/meetings/' . $m['id'] . '/audio') ?>"></audio>
  </div>
<?php endif; ?>

<?php if ($processing): ?>
  <div class="card processing" id="processing-box">
    <div class="spinner"></div>
    <div>
      <strong id="processing-label"><?= e(status_label($m['status'])) ?>…</strong>
      <p class="muted" id="processing-hint">Prepis a analýza trvajú zvyčajne 1–3 minúty na každú hodinu nahrávky. Stránka sa obnoví automaticky.</p>
    </div>
  </div>
<?php elseif ($m['status'] === 'error'): ?>
  <div class="card flash flash-error">
    <strong>Spracovanie zlyhalo.</strong>
    <p><?= e($m['error_message'] ?? ($job['last_error'] ?? 'Neznáma chyba')) ?></p>
    <form method="post" action="<?= url('/meetings/' . $m['id'] . '/reprocess') ?>"><?= Csrf::field() ?><input type="hidden" name="mode" value="<?= $m['transcript_text'] ? 'analyze' : 'transcribe' ?>"><button class="btn" type="submit">Skúsiť znova</button></form>
  </div>
<?php endif; ?>

<?php if (!$processing && $m['status'] !== 'error'): ?>
<div class="tabs" role="tablist">
  <button class="tab is-active" data-tab="notes">Zápis</button>
  <button class="tab" data-tab="tasks">Úlohy <span class="count"><?= count(array_filter($actionItems, fn($a) => $a['status'] === 'open')) ?></span></button>
  <button class="tab" data-tab="transcript">Prepis</button>
  <button class="tab" data-tab="people">Rečníci <span class="count"><?= count($speakers) ?></span></button>
</div>

<section class="tab-panel is-active" data-panel="notes" id="notes-panel">
  <div class="card">
    <h2 class="h-small">Súhrn</h2>
    <div class="editable" id="summary" data-url="<?= url('/api/meetings/' . $m['id'] . '/summary') ?>" contenteditable="true"><?= nl2br(e($m['summary'] ?? '')) ?></div>
    <p class="hint muted">Text môžete upraviť priamo – uloží sa automaticky.</p>
  </div>

  <?php if ($topics): ?>
  <div class="card">
    <h2 class="h-small">Priebeh porady</h2>
    <ol class="topics">
      <?php foreach ($topics as $t): ?>
        <li>
          <div class="topic-title"><?php if ($t['start_sec'] !== null): ?><button type="button" class="ts js-seek" data-t="<?= (float) $t['start_sec'] ?>"><?= e(format_duration((float) $t['start_sec'])) ?></button><?php endif; ?> <?= e($t['title']) ?></div>
          <p><?= e($t['summary']) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
  <?php endif; ?>

  <?php foreach ([['key_point', 'Kľúčové body', '•'], ['decision', 'Rozhodnutia', '✅'], ['open_question', 'Otvorené otázky', '❓']] as [$kind, $label, $icon]): ?>
    <?php if (!empty($points[$kind])): ?>
    <div class="card">
      <h2 class="h-small"><?= $label ?></h2>
      <ul class="points">
        <?php foreach ($points[$kind] as $p): ?><li><span class="pt-icon"><?= $icon ?></span><?= e($p['text']) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if ($actionItems): ?>
  <div class="card">
    <h2 class="h-small">Úlohy z porady</h2>
    <ul class="task-list compact">
      <?php foreach ($actionItems as $a): ?>
        <li class="task <?= $a['status'] === 'done' ? 'is-done' : '' ?>">
          <span class="task-desc"><?= e($a['description']) ?></span>
          <span class="task-meta"><?= e($a['participant_name'] ?? $a['assignee_name'] ?? 'nepriradené') ?><?= $a['due_date'] ? ' · do ' . e(date('j. n.', strtotime($a['due_date']))) : '' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</section>

<section class="tab-panel" data-panel="tasks">
  <div class="card">
    <ul class="task-list" id="task-list">
      <?php foreach ($actionItems as $a): ?>
        <li class="task <?= $a['status'] === 'done' ? 'is-done' : '' ?>" data-id="<?= $a['id'] ?>">
          <label class="task-check"><input type="checkbox" class="js-task-toggle" data-id="<?= $a['id'] ?>" <?= $a['status'] === 'done' ? 'checked' : '' ?>><span></span></label>
          <div class="task-body">
            <div class="task-desc" contenteditable="true" data-field="description"><?= e($a['description']) ?></div>
            <div class="task-meta task-edit">
              <select class="input input-sm js-task-field" data-field="participant_id">
                <option value="0"><?= $a['assignee_name'] && !$a['participant_id'] ? e($a['assignee_name']) . ' (nepriradené)' : 'Nepriradené' ?></option>
                <?php foreach ($participants as $p): ?><option value="<?= $p['id'] ?>" <?= $a['participant_id'] == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
              </select>
              <input type="date" class="input input-sm js-task-field" data-field="due_date" value="<?= e($a['due_date'] ?? '') ?>">
              <select class="input input-sm js-task-field" data-field="priority">
                <?php foreach (['low' => 'nízka', 'normal' => 'bežná', 'high' => 'vysoká'] as $k => $v): ?><option value="<?= $k ?>" <?= $a['priority'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-sm btn-ghost js-task-delete" title="Zmazať">✕</button>
            </div>
            <?php if ($a['source_quote'] && $a['source_quote'] !== 'manual'): ?><blockquote class="quote">„<?= e($a['source_quote']) ?>“</blockquote><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <form class="inline-add" id="add-task-form" data-url="<?= url('/api/meetings/' . $m['id'] . '/action-items') ?>">
      <input type="text" name="description" class="input" placeholder="Nová úloha…" required>
      <select name="participant_id" class="input"><option value="0">Nepriradené</option>
        <?php foreach ($participants as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn">Pridať</button>
    </form>
  </div>
</section>

<section class="tab-panel" data-panel="transcript">
  <div class="card">
    <div class="transcript-tools">
      <input type="search" id="transcript-filter" class="input" placeholder="Hľadať v prepise…">
      <label class="switch"><input type="checkbox" id="follow-audio" checked><span>Sledovať prehrávanie</span></label>
    </div>
    <div class="transcript" id="transcript">
      <?php foreach ($segments as $seg): $sp = $speakerName($seg['speaker_label']); ?>
        <div class="seg-row" data-start="<?= (float) $seg['start_sec'] ?>" data-end="<?= (float) $seg['end_sec'] ?>" data-id="<?= $seg['id'] ?>" data-speaker="<?= e($seg['speaker_label']) ?>">
          <div class="seg-head">
            <span class="avatar" style="--c:<?= e($sp['color']) ?>"><?= e(initials($sp['name'])) ?></span>
            <span class="seg-name" data-speaker-name="<?= e($seg['speaker_label']) ?>"><?= e($sp['name']) ?></span>
            <button type="button" class="ts js-seek" data-t="<?= (float) $seg['start_sec'] ?>"><?= e(format_duration((float) $seg['start_sec'])) ?></button>
          </div>
          <div class="seg-text" contenteditable="true" data-url="<?= url('/api/meetings/' . $m['id'] . '/segments/' . $seg['id']) ?>"><?= e($seg['text']) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if ($segments === []): ?><p class="muted">Prepis nie je k dispozícii.</p><?php endif; ?>
    </div>
  </div>
</section>

<section class="tab-panel" data-panel="people">
  <div class="card">
    <h2 class="h-small">Rečníci v nahrávke</h2>
    <p class="hint muted">Priraďte každého rečníka k účastníkovi. Priradenie sa prenesie do prepisu, úloh a štatistík účastníka.</p>
    <?php $total = max(1.0, array_sum(array_map(fn($s) => (float) $s['talk_seconds'], $speakers))); ?>
    <ul class="speakers">
      <?php foreach ($speakers as $i => $s): $sp = $speakerName($s['speaker_label']); ?>
        <li class="speaker" data-label="<?= e($s['speaker_label']) ?>">
          <span class="avatar" style="--c:<?= e($sp['color']) ?>"><?= e(initials($sp['name'])) ?></span>
          <div class="speaker-body">
            <div class="speaker-name">
              <strong><?= e($sp['name']) ?></strong>
              <?php if ($s['suggested_name'] && !$s['participant_id']): ?><span class="muted">AI návrh: <?= e($s['suggested_name']) ?></span><?php endif; ?>
              <?php if ($s['confirmed']): ?><span class="badge badge-done">potvrdené</span><?php endif; ?>
            </div>
            <div class="talk-bar"><span style="width:<?= round((float) $s['talk_seconds'] / $total * 100) ?>%;background:<?= e($sp['color']) ?>"></span></div>
            <div class="muted small"><?= e(format_duration((float) $s['talk_seconds'])) ?> · <?= (int) $s['word_count'] ?> slov · <?= round((float) $s['talk_seconds'] / $total * 100) ?> %</div>
            <?php if (!empty($samples[$s['speaker_label']]) && $m['audio_path']): ?>
            <div class="samples">
              <span class="muted small">Ukážky hlasu:</span>
              <?php foreach ($samples[$s['speaker_label']] as $k => $smp): ?>
                <button type="button" class="btn btn-sm js-sample" data-start="<?= $smp['start'] ?>" data-end="<?= $smp['end'] ?>" title="<?= e($smp['text']) ?>">▶ <?= $k + 1 ?> · <?= e(format_duration($smp['start'])) ?></button>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="speaker-assign">
              <select class="input input-sm js-speaker-select" data-url="<?= url('/api/meetings/' . $m['id'] . '/speakers') ?>">
                <option value="0">– nepriradený –</option>
                <?php foreach ($participants as $p): ?><option value="<?= $p['id'] ?>" <?= $s['participant_id'] == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                <option value="new">+ Nový účastník…</option>
              </select>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($expected): ?>
      <h3 class="h-small">Pozvaní účastníci</h3>
      <div class="chips"><?php foreach ($expected as $p): ?><a class="chip" style="--c:<?= e($p['color']) ?>" href="<?= url('/participants/' . $p['id']) ?>"><?= e($p['name']) ?></a><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($m['transcript_text'] && ($processing || $m['status'] === 'error')): ?>
  <div class="card">
    <h2 class="h-small">Surový prepis</h2>
    <div class="transcript">
      <?php foreach ($segments as $seg): $sp = $speakerName($seg['speaker_label']); ?>
        <div class="seg-row"><div class="seg-head"><span class="avatar" style="--c:<?= e($sp['color']) ?>"><?= e(initials($sp['name'])) ?></span><span class="seg-name"><?= e($sp['name']) ?></span><span class="ts"><?= e(format_duration((float) $seg['start_sec'])) ?></span></div><div class="seg-text"><?= e($seg['text']) ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (!$processing && $m['status'] !== 'error'): ?>
<dialog id="mail-dialog" class="dialog">
  <form method="post" action="<?= url('/meetings/' . $m['id'] . '/email') ?>" class="form">
    <?= Csrf::field() ?>
    <h2 class="h-small">Poslať zápis e-mailom</h2>
    <?php if (!$mailEnabled): ?><div class="flash flash-error">Odosielanie e-mailov je na serveri vypnuté (MAIL_ENABLED).</div><?php endif; ?>
    <fieldset class="fieldset">
      <legend>Rečníci a účastníci porady</legend>
      <?php if ($mailCandidates === []): ?><p class="muted small" style="margin:0">Porada nemá priradených účastníkov. Priraďte rečníkov v záložke „Rečníci“.</p><?php endif; ?>
      <div class="check-list">
        <?php foreach ($mailCandidates as $c): $has = !empty($c['email']); ?>
          <label class="check-row <?= $has ? '' : 'is-disabled' ?>">
            <input type="checkbox" name="participants[]" value="<?= $c['id'] ?>" <?= $has ? 'checked' : 'disabled' ?>>
            <span class="avatar" style="--c:<?= e($c['color']) ?>"><?= e(initials($c['name'])) ?></span>
            <span class="check-body"><strong><?= e($c['name']) ?></strong><?= $c['spoke'] ? ' <span class="badge">rečník</span>' : '' ?><br>
              <span class="muted small"><?= $has ? e($c['email']) : 'bez e-mailu – <a href="' . url('/participants/' . $c['id']) . '#edit">doplniť</a>' ?></span></span>
          </label>
        <?php endforeach; ?>
        <label class="check-row">
          <input type="checkbox" name="me" value="1" checked>
          <span class="avatar" style="--c:#111827"><?= e(initials($currentUser['name'])) ?></span>
          <span class="check-body"><strong><?= e($currentUser['name']) ?> (ja)</strong><br><span class="muted small"><?= e($currentUser['email']) ?></span></span>
        </label>
      </div>
    </fieldset>
    <label>Ďalšie adresy <span class="muted">(oddelené čiarkou)</span><input type="text" name="extra" class="input" placeholder="meno@firma.sk, ..." inputmode="email"></label>
    <label>Poznámka na začiatok e-mailu<textarea name="note" class="input" rows="2" placeholder="napr. Prosím o kontrolu úloh do piatku."></textarea></label>
    <label class="switch"><input type="checkbox" name="transcript" value="1" checked><span>Priložiť celý prepis (v tele e-mailu aj ako .txt)</span></label>
    <div class="dialog-actions">
      <button type="button" class="btn" id="close-mail">Zrušiť</button>
      <button type="submit" class="btn btn-primary" <?= $mailEnabled ? '' : 'disabled' ?>>Odoslať</button>
    </div>
    <?php if ($mailLog): ?>
      <p class="hint muted">Naposledy: <?php foreach (array_slice($mailLog, 0, 2) as $l): ?><?= e(format_date($l['created_at'], 'j. n. H:i')) ?> <?= $l['status'] === 'sent' ? '✅' : '⚠️' ?> <?= e($l['recipients']) ?><?= $l['kind'] === 'auto' ? ' (automaticky)' : '' ?>; <?php endforeach; ?></p>
    <?php endif; ?>
  </form>
</dialog>
<?php endif; ?>

<textarea id="notes-clipboard" hidden><?= e(\App\Core\View::partial('meetings/export_md', ['meeting' => $m, 'speakers' => $speakers, 'segments' => [], 'topics' => $topics, 'points' => array_merge(...array_values($points)), 'actionItems' => $actionItems, 'tags' => $tags])) ?></textarea>
<?php \App\Core\View::addScript('/assets/js/meeting.js'); ?>
