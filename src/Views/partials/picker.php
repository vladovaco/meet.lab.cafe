<?php
/**
 * Výber viacerých položiek s našepkávaním (účastníci, štítky). Bez JS sa zobrazia zaškrtávacie čipy.
 * @var string $name      názov poľa, napr. participants[]
 * @var array  $options   zoznam [id, name, color, (aliases)]
 * @var array  $selected  vybrané id
 * @var string $create    'participant' | 'tag' – čo vytvoriť, ak položka neexistuje
 * @var string $placeholder
 * @var string $prefix    predpona zobrazenia (napr. '#')
 */
$selected = array_map('intval', $selected ?? []);
$prefix ??= '';
$data = array_map(static fn($o) => [
    'id'      => (int) $o['id'],
    'name'    => (string) $o['name'],
    'color'   => (string) ($o['color'] ?? '#6366f1'),
    'aliases' => (string) ($o['aliases'] ?? ''),
], $options);
?>
<div class="picker" data-name="<?= e($name) ?>" data-create="<?= e($create) ?>" data-prefix="<?= e($prefix) ?>">
  <script type="application/json" class="picker-data"><?= json_encode(['options' => $data, 'selected' => $selected], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <div class="picker-box">
    <div class="picker-selected"></div>
    <input type="text" class="picker-input" placeholder="<?= e($placeholder) ?>" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list">
  </div>
  <ul class="picker-list" role="listbox" hidden></ul>
  <noscript>
    <div class="chips">
      <?php foreach ($options as $o): ?>
        <label class="chip chip-select" style="--c:<?= e($o['color'] ?? '#6366f1') ?>"><input type="checkbox" name="<?= e($name) ?>" value="<?= (int) $o['id'] ?>" <?= in_array((int) $o['id'], $selected, true) ? 'checked' : '' ?>><span><?= e($prefix . $o['name']) ?></span></label>
      <?php endforeach; ?>
    </div>
  </noscript>
</div>
