<?php /** @var array $m */ ?>
<a class="card meeting-card status-<?= e($m['status']) ?>" href="<?= url('/meetings/' . $m['id']) ?>">
  <div class="meeting-card-head">
    <span class="meeting-date"><?= e(format_date($m['meeting_date'], 'D j. n. Y · H:i')) ?></span>
    <span class="badge badge-status badge-<?= e($m['status']) ?>"><?= e(status_label($m['status'])) ?></span>
  </div>
  <h3 class="meeting-title"><?= e($m['title']) ?></h3>
  <?php if (!empty($m['summary'])): ?>
    <p class="meeting-summary"><?= e(mb_strimwidth($m['summary'], 0, 180, '…')) ?></p>
  <?php endif; ?>
  <div class="meeting-meta">
    <?php if ($m['audio_duration']): ?><span>⏱ <?= e(format_duration((float) $m['audio_duration'])) ?></span><?php endif; ?>
    <?php if ($m['speaker_count']): ?><span>🗣 <?= (int) $m['speaker_count'] ?></span><?php endif; ?>
    <?php if ($m['open_tasks']): ?><span>☐ <?= (int) $m['open_tasks'] ?> úloh</span><?php endif; ?>
    <?php if ($m['folder_name']): ?><span class="chip" style="--c:<?= e($m['folder_color']) ?>">📁 <?= e($m['folder_name']) ?></span><?php endif; ?>
    <?php foreach (($m['tags'] ?? []) as $t): ?><span class="chip chip-tag" style="--c:<?= e($t['color']) ?>">#<?= e($t['name']) ?></span><?php endforeach; ?>
  </div>
</a>
