<?php use App\Services\CostTracker; $s = $summary; ?>
<a class="back" href="<?= url('/settings') ?>">‹ Nastavenia</a>
<div class="page-head"><h1>Náklady na spracovanie</h1></div>
<div class="stats stats-grid">
  <div class="card stat"><strong><?= e(CostTracker::format($s['total_30d'])) ?></strong><span>posledných 30 dní</span></div>
  <div class="card stat"><strong><?= e(CostTracker::format($s['total'])) ?></strong><span>celkovo</span></div>
  <div class="card stat"><strong><?= count($s['meetings']) ?></strong><span>spracovaných porád</span></div>
</div>

<div class="card">
  <h2 class="h-small">Po mesiacoch</h2>
  <?php if ($s['months'] === []): ?><p class="muted">Zatiaľ žiadne spracovanie.</p><?php endif; ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Mesiac</th><th>Porady</th><th>Audio</th><th>Prepis</th><th>Analýza</th><th>Tokeny (vst./výst.)</th><th>Spolu</th></tr></thead>
    <tbody>
    <?php foreach ($s['months'] as $m): ?>
      <tr><td><?= e($m['month']) ?></td><td><?= (int) $m['meetings'] ?></td><td><?= e(format_duration((float) $m['audio_seconds'])) ?></td><td><?= e(CostTracker::format((float) $m['stt_cost'])) ?></td><td><?= e(CostTracker::format((float) $m['llm_cost'])) ?></td><td><?= number_format((int) $m['input_tokens'], 0, ',', ' ') ?> / <?= number_format((int) $m['output_tokens'], 0, ',', ' ') ?></td><td><strong><?= e(CostTracker::format((float) $m['cost'])) ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <h2 class="h-small">Podľa poskytovateľa (30 dní)</h2>
  <ul class="kv">
    <?php foreach ($s['providers'] as $p): ?>
      <li><span><?= $p['step'] === 'transcribe' ? 'Prepis' : 'Analýza' ?> · <?= e($p['provider']) ?><?= $p['model'] ? ' · ' . e($p['model']) : '' ?> <span class="muted small">(<?= (int) $p['runs'] ?>×)</span><?= $p['priced'] ? '' : ' <span class="badge badge-error">chýba v cenníku</span>' ?></span><strong><?= e(CostTracker::format((float) $p['cost'])) ?></strong></li>
    <?php endforeach; ?>
    <?php if ($s['providers'] === []): ?><li><span class="muted">Žiadne dáta.</span></li><?php endif; ?>
  </ul>
</div>

<div class="card">
  <h2 class="h-small">Porady</h2>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Porada</th><th>Dátum</th><th>Dĺžka</th><th>Autor</th><th>Behy</th><th>Náklady</th></tr></thead>
    <tbody>
    <?php foreach ($s['meetings'] as $m): ?>
      <tr><td><a href="<?= url('/meetings/' . $m['id']) ?>"><?= e($m['title']) ?></a></td><td><?= e(format_date($m['meeting_date'], 'j. n. Y')) ?></td><td><?= e(format_duration($m['audio_duration'] !== null ? (float) $m['audio_duration'] : null)) ?></td><td><?= e($m['author'] ?? '–') ?></td><td><?= (int) $m['runs'] ?></td><td><strong><?= e(CostTracker::format((float) $m['cost'])) ?></strong><?= $m['priced'] ? '' : ' ⚠️' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <h2 class="h-small">Cenník použitý na odhad</h2>
  <ul class="kv">
    <?php foreach ($pricing['stt'] as $k => $v): ?><li><span>Prepis · <?= e($k) ?></span><strong>$<?= number_format($v, 2) ?> / hod</strong></li><?php endforeach; ?>
    <?php foreach ($pricing['llm'] as $k => $v): ?><li><span>LLM · <?= e($k) ?></span><strong>$<?= number_format($v[0], 2) ?> / $<?= number_format($v[1], 2) ?> za 1M tokenov</strong></li><?php endforeach; ?>
  </ul>
  <p class="hint muted">Upravuje sa v súbore <code>config/pricing.php</code>. Odhad nezahŕňa DPH, hosting ani prípadné minimálne poplatky poskytovateľov.</p>
</div>
