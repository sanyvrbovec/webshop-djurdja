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

// Sigurnosna upozorenja (vidljiva samo vlasniku u adminu)
$sysWarn = [];
if (is_dir(SHOP_ROOT . '/install')) {
    $sysWarn[] = 'Instalacijski direktorij <code>install/</code> još postoji na serveru — iz sigurnosnih razloga <strong>obrišite ga</strong> (FTP / File Manager).';
}
if (defined('DEBUG') && DEBUG) {
    $sysWarn[] = 'Uključen je <code>DEBUG</code> način rada — u produkciji ga isključite (<code>config/config.php</code> → <code>DEBUG = false</code>) da se greške ne prikazuju posjetiteljima.';
}
if ($kpi['fiscal_issues'] > 0) {
    $n = (int) $kpi['fiscal_issues'];
    $sysWarn[] = '🧾 <strong>' . $n . '</strong> ' . ($n === 1 ? 'plaćena narudžba nije fiskalizirana' : 'plaćenih narudžbi nije fiskalizirano')
        . ' (neuspjeh, čeka ponovni pokušaj ili istekao rok). <a href="' . e(adminUrl('narudzbe.php')) . '">Otvorite narudžbe</a> i fiskalizirajte ručno — zakonski rok je <strong>48 h od naplate</strong>.';
}
$updateInfo = Updater::status();
?>
<?php foreach ($sysWarn as $w): ?>
  <div class="alert alert-error" style="margin-bottom:14px">⚠ <?= $w ?></div>
<?php endforeach; ?>
<?php if (!empty($_SESSION['admin_prev_login'])): ?>
  <p class="sub" style="margin:0 0 14px">🔐 Zadnja prijava: <strong><?= e(date('d.m.Y H:i', strtotime((string) $_SESSION['admin_prev_login']))) ?></strong> — ako to niste bili vi, odmah promijenite lozinku.</p>
<?php endif; ?>
<?php if ($updateInfo['newer']): ?>
  <div class="alert alert-info" style="margin-bottom:14px;display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap">
    <span>⬆ Dostupna je nova verzija <strong><?= e($updateInfo['latest']) ?></strong> (trenutno <?= e($updateInfo['current']) ?>).</span>
    <a class="abtn sm" href="<?= e(adminUrl('azuriranje.php')) ?>"><?= $updateInfo['oneClick'] ? 'Nadogradi sada' : 'Pogledaj' ?></a>
  </div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="l">Narudžbe danas</div><div class="v"><?= $kpi['orders_today'] ?></div></div>
  <div class="kpi"><div class="l">Narudžbe (mjesec)</div><div class="v"><?= $kpi['orders_month'] ?></div></div>
  <div class="kpi"><div class="l">Promet (mjesec, plaćeno)</div><div class="v"><?= fmt_price($kpi['revenue_month']) ?></div></div>
  <div class="kpi <?= $kpi['pending'] > 0 ? 'warn' : '' ?>"><div class="l">Za obradu</div><div class="v"><?= $kpi['pending'] ?></div></div>
  <div class="kpi <?= $kpi['fiscal_issues'] > 0 ? 'bad' : '' ?>"><div class="l">Fiskalni problemi</div><div class="v"><?= $kpi['fiscal_issues'] ?></div></div>
</div>

<?php $announcements = Djurdja::announcements(); ?>
<div class="acard" style="margin-bottom:20px">
  <h3>📣 Obavijesti</h3>
  <?php if ($announcements): ?>
    <?php foreach ($announcements as $an):
        $clr = ['info' => '#3b82f6', 'success' => '#10b981', 'warning' => '#f59e0b', 'danger' => '#ef4444'][$an['type'] ?? 'info'] ?? '#3b82f6'; ?>
      <div style="padding:12px 14px;border-radius:10px;margin-bottom:10px;background:#f9fafb;border:1px solid #eef0f6;border-left:4px solid <?= $clr ?>">
        <?php if (!empty($an['date'])): ?><span class="sub" style="float:right"><?= e($an['date']) ?></span><?php endif; ?>
        <?php if (!empty($an['title'])): ?><strong style="font-size:14px"><?= e($an['title']) ?></strong><?php endif; ?>
        <div style="font-size:13.5px;color:#374151;line-height:1.6;margin-top:4px"><?= HtmlSanitizer::clean((string) ($an['body'] ?? '')) ?></div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p style="color:#8b90a0;font-size:13.5px;margin:0">Trenutno nema novih obavijesti. Ovdje će se prikazivati važne poruke i novosti iz sustava MojaĐurđa.</p>
  <?php endif; ?>
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
    <div class="acard" style="color:#fff;background:linear-gradient(135deg,#6d28d9,#a855f7 60%,#ec4899);border:0;box-shadow:0 8px 24px rgba(124,58,237,.35)">
      <h3 style="color:#fff">⚡ Otključajte puni potencijal trgovine</h3>
      <ul style="font-size:12.5px;margin:8px 0 12px;padding-left:18px;line-height:2;opacity:.95">
        <li><strong>Vaš brend, bez tuđeg potpisa</strong> — nestaje "Pokreće MojaĐurđa" iz podnožja i s računa</li>
        <li><strong>Logo na računima</strong> — profesionalan dojam u svakom inboxu</li>
        <li><strong>Premium teme, boje i vlastiti CSS</strong> — izgled kakav konkurencija nema</li>
        <li><strong>Blog</strong> — SEO članci koji dovode kupce s Googlea</li>
        <li><strong>Do 2800 dokumenata mjesečno</strong> — rastite bez brige o kvoti</li>
      </ul>
      <a class="abtn sm" style="background:#fff;color:#6d28d9;font-weight:800" href="<?= e($upgradeUrl) ?>" target="_blank">Pogledaj pakete ↗</a>
    </div>
    <?php endif; ?>

    <div class="acard" style="border:1px solid #c7d2fe;background:#f5f7ff">
      <h3>🛠 Ne želite sami postavljati?</h3>
      <p style="font-size:13px;color:#4b5563;margin:0 0 8px">Postavit ćemo vam cijeli ĐurđaShop — povezivanje s MojaĐurđa, fiskalizaciju, plaćanja i dizajn — za jednokratnih <strong>100 €</strong>.</p>
      <p style="font-size:12.5px;color:#6b7280;margin:0 0 6px"><strong>Vi osiguravate:</strong> hosting (i pošaljete nam privremene pristupne podatke), te sami vadite osjetljive podatke — FINA certifikat i registraciju kartičnog plaćanja (Stripe).</p>
      <p style="font-size:12.5px;color:#6b7280;margin:0 0 10px"><strong>Kako:</strong> javite se na <a href="mailto:info.djurdja@gmail.com">info.djurdja@gmail.com</a>; nakon uplate dostavite podatke i sve podesimo umjesto vas.</p>
      <a class="abtn sm" href="mailto:info.djurdja@gmail.com?subject=Postavljanje%20%C4%90ur%C4%91aShop-a">Zatraži postavljanje →</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>
