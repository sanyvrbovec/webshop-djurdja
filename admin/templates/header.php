<?php
/** Admin shell — očekuje $pageTitle. */
$pageTitle = $pageTitle ?? 'Administracija';
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$djStatus = $djStatus ?? Djurdja::status();
$nav = [
    ['index.php', '📊', 'Nadzorna ploča'],
    ['narudzbe.php', '📦', 'Narudžbe', ['narudzba.php']],
    ['racuni.php', '🧾', 'Računi', ['racun.php']],
    ['fiskalni-audit.php', '🔒', 'Fiskalni audit'],
    ['proizvodi.php', '🏷️', 'Proizvodi', ['proizvod.php']],
    ['recenzije.php', '⭐', 'Recenzije'],
    ['kategorije.php', '🗂️', 'Kategorije'],
    ['sync.php', '🔄', 'Sinkronizacija'],
    ['__sep1', '', 'Izgled i sadržaj'],
    ['dizajn.php', '🎨', 'Dizajn'],
    ['blog.php', '📝', 'Blog'],
    ['stranice.php', '📄', 'Stranice'],
    ['__sep2', '', 'Postavke'],
    ['zakon.php', '⚖️', 'Zakonska usklađenost'],
    ['placanja.php', '💳', 'Plaćanja i dostava'],
    ['email.php', '✉️', 'E-mail postavke'],
    ['djurdja.php', '🔌', 'Đurđa veza'],
    ['postavke.php', '⚙️', 'Općenito'],
    ['dnevnik.php', '🛡️', 'Dnevnik (audit)'],
];
$statusBadge = [
    'connected' => ['green', '● Povezano s đurđom'],
    'stale'     => ['amber', '● Veza zastarjela'],
    'offline'   => ['red', '● Offline — checkout blokiran'],
    'locked'    => ['red', '● Veza blokirana'],
][$djStatus] ?? ['gray', '—'];
?><!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?> · <?= e(shop_name()) ?> admin</title>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body>
<div class="adm-layout">
  <aside class="adm-side">
    <div class="adm-brand"><span class="b">Đ</span> ĐurđaShop</div>
    <?php foreach ($nav as $item): ?>
      <?php if (strpos($item[0], '__sep') === 0): ?>
        <div class="sep"><?= e($item[2]) ?></div>
      <?php else:
        $active = $script === $item[0] || in_array($script, $item[3] ?? [], true); ?>
        <a class="nav <?= $active ? 'active' : '' ?>" href="<?= e(adminUrl($item[0])) ?>"><span class="ic"><?= $item[1] ?></span> <?= e($item[2]) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <div style="margin-top:auto;padding-top:18px">
      <a class="nav" href="<?= e(url('')) ?>" target="_blank"><span class="ic">🌐</span> Pogledaj trgovinu</a>
      <a class="nav" href="<?= e(adminUrl('logout.php')) ?>"><span class="ic">🚪</span> Odjava</a>
    </div>
  </aside>
  <div class="adm-main">
    <div class="adm-top">
      <h1><?= e($pageTitle) ?></h1>
      <div class="right">
        <span class="badge <?= e($statusBadge[0]) ?>"><?= e($statusBadge[1]) ?></span>
        <span>👤 <?= e($currentAdmin['username']) ?></span>
      </div>
    </div>
    <div class="adm-content">
    <?php if (Djurdja::versionBlocked()): ?>
      <div class="alert alert-error">
        🛠️ <strong>Vaša trgovina koristi zastarjelu verziju (<?= e(SHOP_VERSION) ?>) i privremeno NE prima narudžbe.</strong>
        Potrebna je najmanje verzija <strong><?= e(Djurdja::minVersion()) ?></strong>.
        Preuzmite najnoviju verziju, zamijenite datoteke na serveru (FTP/File Manager) i osvježite — izlog se odmah otključava.
      </div>
    <?php endif; ?>
    <?php foreach (take_flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
