<?php
/** HTML e-mail so zápisom z porady. Premenné: meeting, speakers, segments, topics, points, actionItems, tags, url, note */
$names = [];
foreach ($speakers as $i => $s) {
    $names[$s['speaker_label']] = $s['participant_name'] ?? $s['suggested_name'] ?? ('Rečník ' . ($i + 1));
}
$byKind = ['key_point' => [], 'decision' => [], 'open_question' => []];
foreach ($points as $p) {
    $byKind[$p['kind']][] = $p['text'];
}
$h2 = 'style="font-size:16px;margin:22px 0 8px;color:#111827"';
$li = 'style="margin:0 0 6px"';
?>
<!DOCTYPE html>
<html lang="sk"><head><meta charset="utf-8"><title><?= e($meeting['title']) ?></title></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#111827;font-size:15px;line-height:1.5">
<div style="max-width:640px;margin:0 auto;padding:24px 16px">
  <div style="background:#111827;color:#fff;padding:14px 18px;border-radius:12px 12px 0 0;font-weight:700">
    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f97316;margin-right:8px"></span><?= e(\App\Core\Config::get('app_name')) ?>
  </div>
  <div style="background:#fff;padding:20px 18px;border-radius:0 0 12px 12px;border:1px solid #e5e7eb;border-top:0">
    <h1 style="font-size:20px;margin:0 0 6px"><?= e($meeting['title']) ?></h1>
    <p style="margin:0 0 4px;color:#6b7280;font-size:13px">
      📅 <?= e(format_date($meeting['meeting_date'], 'l j. n. Y · H:i')) ?>
      <?php if ($meeting['location']): ?> · 📍 <?= e($meeting['location']) ?><?php endif; ?>
      <?php if ($meeting['audio_duration']): ?> · ⏱ <?= e(format_duration((float) $meeting['audio_duration'])) ?><?php endif; ?>
    </p>
    <?php if ($speakers): ?><p style="margin:0 0 4px;color:#6b7280;font-size:13px">🗣 <?= e(implode(', ', array_values($names))) ?></p><?php endif; ?>
    <?php if ($tags): ?><p style="margin:0;color:#6b7280;font-size:13px"><?= e(implode(' ', array_map(fn($t) => '#' . $t['name'], $tags))) ?></p><?php endif; ?>
    <?php if (!empty($note)): ?><div style="margin:14px 0;padding:10px 12px;background:#fff7ed;border-left:4px solid #f97316;border-radius:6px"><?= nl2br(e($note)) ?></div><?php endif; ?>

    <h2 <?= $h2 ?>>Súhrn</h2>
    <p style="margin:0"><?= nl2br(e(trim((string) $meeting['summary']))) ?></p>

    <?php if ($topics): ?>
      <h2 <?= $h2 ?>>Priebeh porady</h2>
      <ol style="padding-left:20px;margin:0">
        <?php foreach ($topics as $t): ?>
          <li <?= $li ?>><strong><?= $t['start_sec'] !== null ? '[' . e(format_duration((float) $t['start_sec'])) . '] ' : '' ?><?= e($t['title']) ?></strong><br><span style="color:#4b5563"><?= e($t['summary']) ?></span></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <?php foreach ([['key_point', 'Kľúčové body', '•'], ['decision', 'Rozhodnutia', '✅'], ['open_question', 'Otvorené otázky', '❓']] as [$kind, $label, $icon]): ?>
      <?php if ($byKind[$kind]): ?>
        <h2 <?= $h2 ?>><?= $label ?></h2>
        <ul style="list-style:none;padding:0;margin:0">
          <?php foreach ($byKind[$kind] as $text): ?><li <?= $li ?>><?= $icon ?> <?= e($text) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($actionItems): ?>
      <h2 <?= $h2 ?>>Úlohy</h2>
      <table style="border-collapse:collapse;width:100%;font-size:14px">
        <?php foreach ($actionItems as $a): ?>
          <tr>
            <td style="padding:6px 6px 6px 0;border-top:1px solid #e5e7eb;vertical-align:top;width:22px"><?= $a['status'] === 'done' ? '☑' : '☐' ?></td>
            <td style="padding:6px 0;border-top:1px solid #e5e7eb;vertical-align:top">
              <?= e($a['description']) ?><br>
              <span style="color:#6b7280;font-size:12px"><?= e($a['participant_name'] ?? $a['assignee_name'] ?? 'nepriradené') ?><?= $a['due_date'] ? ' · do ' . e(date('j. n. Y', strtotime($a['due_date']))) : '' ?><?= $a['priority'] === 'high' ? ' · ⚠️ vysoká priorita' : '' ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($segments): ?>
      <h2 <?= $h2 ?>>Prepis</h2>
      <div style="font-size:13px;color:#374151">
        <?php foreach ($segments as $seg): ?>
          <p style="margin:0 0 8px"><span style="color:#9ca3af;font-family:monospace">[<?= e(format_duration((float) $seg['start_sec'])) ?>]</span> <strong><?= e($names[$seg['speaker_label']] ?? $seg['speaker_label']) ?>:</strong> <?= e($seg['text']) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p style="margin:24px 0 0;font-size:13px;color:#6b7280">
      <a href="<?= e($url) ?>" style="display:inline-block;padding:8px 14px;background:#f97316;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Otvoriť poradu v aplikácii</a><br><br>
      Zápis vytvorila aplikácia automaticky z nahrávky porady. Prepis môže obsahovať nepresnosti. V prílohe je zápis (.md)<?= $segments ? ' a prepis (.txt)' : '' ?>.
    </p>
  </div>
</div>
</body></html>
