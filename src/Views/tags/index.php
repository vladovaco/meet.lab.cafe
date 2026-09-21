<?php use App\Core\Csrf; ?>
<div class="page-head"><h1>Triedenie</h1></div>
<div class="card">
  <h2 class="h-small">Priečinky</h2>
  <p class="hint muted">Každá porada patrí do jedného priečinka (napr. tím, projekt, klient).</p>
  <ul class="simple-list">
    <?php foreach ($folders as $f): ?>
      <li><a href="<?= url('/?folder=' . $f['id']) ?>" class="chip" style="--c:<?= e($f['color']) ?>">📁 <?= e($f['name']) ?></a><span class="muted"><?= (int) $f['meetings_count'] ?> porád</span>
        <form method="post" action="<?= url('/folders/' . $f['id'] . '/delete') ?>" onsubmit="return confirm('Zmazať priečinok?')"><?= Csrf::field() ?><button class="btn btn-sm btn-ghost">✕</button></form></li>
    <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= url('/folders') ?>" class="inline-add"><?= Csrf::field() ?>
    <input type="text" name="name" class="input" placeholder="Nový priečinok" required><input type="color" name="color" value="#6366f1" class="input input-color"><button class="btn" type="submit">Pridať</button>
  </form>
</div>
<div class="card">
  <h2 class="h-small">Štítky</h2>
  <p class="hint muted">Porada môže mať viac štítkov. AI navrhuje štítky automaticky podľa obsahu.</p>
  <ul class="simple-list">
    <?php foreach ($tags as $t): ?>
      <li><a href="<?= url('/?tag=' . $t['id']) ?>" class="chip chip-tag" style="--c:<?= e($t['color']) ?>">#<?= e($t['name']) ?></a><span class="muted"><?= (int) $t['meetings_count'] ?> porád</span>
        <form method="post" action="<?= url('/tags/' . $t['id'] . '/delete') ?>" onsubmit="return confirm('Zmazať štítok?')"><?= Csrf::field() ?><button class="btn btn-sm btn-ghost">✕</button></form></li>
    <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= url('/tags') ?>" class="inline-add"><?= Csrf::field() ?>
    <input type="text" name="name" class="input" placeholder="Nový štítok" required><input type="color" name="color" value="#0ea5e9" class="input input-color"><button class="btn" type="submit">Pridať</button>
  </form>
</div>
