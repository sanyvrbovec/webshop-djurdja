<?php
require_once __DIR__ . '/templates/init.php';

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$kpi = [
    'orders_today' => (int) $db->fetchColumn("SELECT COUNT(*) FROM orders WHERE created_at >= :d AND status != 'cancelled'", [':d' => $today]),
    'orders_month' => (int) $db->fetchColumn("SELECT COUNT(*) FROM orders WHERE created_at >= :d AND status != 'cancelled'", [':d' => $monthStart]),
    'revenue_month' => (float) ($db->fetchColumn("SELECT SUM(total) FROM orders WHERE created_at >= :d AND payment_status = 'paid'", [':d' => $monthStart]) ?? 0),
    'pending' => (int) $db->fetchColumn("SELECT COUNT(*) FROM orders WHERE status IN ('pending','confirmed')"),
    'fiscal_issues' => (int) $db->fetchColumn("SELECT COUNT(*) FROM orders WHERE fiscal_status IN ('failed','failed_expired','pending_retry')"),
];
$latest = $db->fetchAll('SELECT * FROM orders ORDER BY id DESC LIMIT 8');
$quota = Djurdja::quota();
$planName = Djurdja::planName();
$lastSync = $db->fetch("SELECT * FROM sync_log ORDER BY id DESC LIMIT 1");
$productCount = (int) $db->fetchColumn('SELECT COUNT(*) FROM products WHERE is_orphaned = 0');
$upgradeUrl = 'https://mojadjurdja.com/cjenik?utm_source=webshop&utm_medium=admin&utm_campaign=quota';

$pageTitle = 'Nadzorna ploča';
require __DIR__ . '/templates/header.php';
?>

<div class="kpis">
  <div class="kpi"><div class="l">Narudžbe danas</div><div class="v"><?= $kpi['orders_today'] ?></div></div>
  <div class="kpi"><div class="l">Narudžbe (mjesec)</div><div class="v"><?= $kpi['orders_month'] ?></div></div>
  <div class="kpi"><div class="l">Promet (mjesec, plaćeno)</div><div class="v"><?= fmt_price($kpi['revenue_month']) ?></div></div>
  <div class="kpi <?= $kpi['pending'] > 0 ? 'warn' : '' ?>"><div class="l">Za obradu</div><div class="v"><?= $kpi['pending'] ?></div></div>
  <div class="kpi <?= $kpi['fiscal_issues'] > 0 ? 'bad' : '' ?>"><div class="l">Fiskalni problemi</div><div class="v"><?= $kpi['fiscal_issues'] ?></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">
  <div class="acard">
    <h3>Najnovije narudžbe</h3>
    <?php if (!$latest): ?>
      <p style="color:#8b90a0">Još nema narudžbi. <?= $productCount === 0 ? 'Prvo pokrenite <a href="' . e(adminUrl('sync.php')) . '">sinkronizaciju artikala</a> iz đurđe.' : 'Podijelite svoju trgovinu sa svijetom! 🚀' ?></p>
    <?php else: ?>
      <table class="atable">
        <thead><tr><th>Broj</th><th>Kupac</th><th>Status</th><th>Plaćanje</th><th>Račun</th><th class="num">Iznos</th></tr></thead>
        <tbody>
        <?php foreach ($latest as $o): ?>
          <tr>
            <td><a href="<?= e(adminUrl('narudzba.php?id=' . $o['id'])) ?>"><strong><?= e($o['order_number']) ?></strong></a><br><small style="color:#9ca3af"><?= date('d.m.Y H:i', strtotime($o['created_at'])) ?></small></td>
            <td><?= e($o['customer_name']) ?></td>
            <td><span class="badge <?= in_array($o['status'], ['delivered']) ? 'green' : (in_array($o['status'], ['cancelled', 'refunded']) ? 'red' : 'blue') ?>"><?= e(Orders::statusLabel($o['status'])) ?></span></td>
            <td><span class="badge <?= $o['payment_status'] === 'paid' ? 'green' : ($o['payment_status'] === 'failed' ? 'red' : 'amber') ?>"><?= $o['payment_status'] === 'paid' ? 'Plaćeno' : ($o['payment_status'] === 'failed' ? 'Neuspjelo' : 'Čeka') ?></span></td>
            <td><?php
                $fs = $o['fiscal_status'];
                $map = ['fiscalized' => ['green', 'Fiskaliziran'], 'pending_retry' => ['amber', 'Retry'], 'failed' => ['red', 'Greška'], 'failed_expired' => ['red', 'Isteklo!'], 'stornoed' => ['gray', 'Storno'], 'none' => ['gray', '—']];
                [$bc, $bt] = $map[$fs] ?? ['gray', $fs];
            ?><span class="badge <?= $bc ?>"><?= e($bt) ?></span></td>
            <td class="num"><strong><?= fmt_price($o['total']) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin:14px 0 0"><a class="abtn ghost sm" href="<?= e(adminUrl('narudzbe.php')) ?>">Sve narudžbe →</a></p>
    <?php endif; ?>
  </div>

  <div style="display:grid;gap:20px">
    <div class="acard">
      <h3>📋 Đurđa paket: <span style="color:#6d28d9"><?= e($planName) ?></span></h3>
      <?php if ($quota): ?>
        <?php
          $used = (int) $quota['used']; $limit = max(1, (int) $quota['limit']);
          $pct = min(100, (int) round($used / $limit * 100));
          $cls = $pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : '');
        ?>
        <p style="margin:0;font-size:13px;color:#6b7280">Dokumenti ovaj mjesec (računi iz trgovine + đurđa blagajna):</p>
        <div class="quota-bar <?= $cls ?>"><div style="width:<?= $pct ?>%"></div></div>
        <div style="display:flex;justify-content:space-between;font-size:13px"><strong><?= $used ?> / <?= (int) $quota['limit'] ?></strong><span style="color:#8b90a0"><?= $pct ?>%</span></div>
        <?php if ($pct >= 80): ?>
          <div class="alert alert-<?= $pct >= 100 ? 'error' : 'warning' ?>" style="margin-top:12px">
            <?= $pct >= 100 ? 'Kvota je potrošena — nove narudžbe se ne mogu fiskalizirati!' : 'Bliži se kraj mjesečne kvote.' ?>
            <a href="<?= e($upgradeUrl) ?>" target="_blank"><strong>Nadogradite paket →</strong></a>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <p style="color:#8b90a0;font-size:13px">Bez ograničenja ili podaci o kvoti nisu dostupni.</p>
      <?php endif; ?>
      <a class="abtn ghost sm" style="margin-top:10px" href="<?= e($upgradeUrl) ?>" target="_blank">Pogledaj pakete</a>
    </div>

    <div class="acard">
      <h3>🔄 Katalog</h3>
      <p style="font-size:13px;color:#6b7280;margin:0 0 10px">
        <strong><?= $productCount ?></strong> artikala u trgovini.<br>
        Zadnja sinkronizacija: <?= $lastSync ? date('d.m.Y H:i', strtotime($lastSync['started_at'])) . ' (' . e($lastSync['status']) . ')' : '—' ?>
      </p>
      <a class="abtn sm" href="<?= e(adminUrl('sync.php')) ?>">Sinkroniziraj</a>
    </div>

    <?php if (Djurdja::brandingRequired()): ?>
    <div class="acard" style="background:linear-gradient(135deg,#f5f3ff,#fdf4ff);border-color:#ddd6fe">
      <h3>💜 Besplatni plan</h3>
      <p style="font-size:13px;color:#6b7280;margin:0">Trgovina prikazuje "Pokreće MojaĐurđa" u podnožju. Uklonite ga i otključajte više dokumenata nadogradnjom paketa.</p>
      <a class="abtn sm" style="margin-top:10px" href="<?= e($upgradeUrl) ?>" target="_blank">Nadogradi →</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>
