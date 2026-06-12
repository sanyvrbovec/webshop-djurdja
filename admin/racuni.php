<?php
/**
 * Računi — pregled svih fiskaliziranih (i storniranih) računa
 * s filtrima po mjesecu i načinu rada + slanje računa kupcu e-mailom.
 */
require_once __DIR__ . '/templates/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'send') {
        $oid = (int) ($_POST['order_id'] ?? 0);
        $order = $db->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $oid]);
        if ($order && in_array($order['fiscal_status'], ['fiscalized', 'stornoed'], true)) {
            $ok = Mailer::fiscalReceipt($order, $err);
            flash($ok ? 'success' : 'error', $ok
                ? 'Račun ' . $order['fiscal_receipt_number'] . ' poslan na ' . $order['customer_email'] . '.'
                : 'Slanje nije uspjelo: ' . ($err ?: 'nepoznata greška'));
        } else {
            flash('error', 'Račun nije pronađen ili nije fiskaliziran.');
        }
    }
    redirect('admin/racuni.php' . (!empty($_POST['back_qs']) ? '?' . $_POST['back_qs'] : ''));
}

$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$mode = in_array($_GET['mode'] ?? '', ['test', 'live'], true) ? $_GET['mode'] : '';
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 60));

$where = "fiscal_receipt_number IS NOT NULL AND fiscal_status IN ('fiscalized','stornoed')
          AND fiscalized_at >= :od AND fiscalized_at < :do";
$params = [
    ':od' => $month . '-01 00:00:00',
    ':do' => date('Y-m-d', strtotime($month . '-01 +1 month')) . ' 00:00:00',
];
if ($mode !== '') { $where .= ' AND fiscal_mode = :md'; $params[':md'] = $mode; }
if ($q !== '') {
    $where .= ' AND (fiscal_receipt_number LIKE :q OR order_number LIKE :q OR customer_name LIKE :q OR customer_email LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$receipts = $db->fetchAll("SELECT * FROM orders WHERE $where ORDER BY fiscalized_at DESC, id DESC LIMIT 500", $params);
$sumLive = 0.0;
$cntFisk = 0;
foreach ($receipts as $r) {
    if ($r['fiscal_status'] === 'fiscalized') {
        $cntFisk++;
        if ($r['fiscal_mode'] === 'live') $sumLive += (float) $r['total'];
    }
}
$backQs = http_build_query(array_filter(['m' => $month, 'mode' => $mode, 'q' => $q]));

$pageTitle = 'Računi';
require __DIR__ . '/templates/header.php';
?>
<div class="acard">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input class="ainput" type="month" name="m" value="<?= e($month) ?>" style="max-width:170px">
      <select class="ainput" name="mode" style="max-width:140px">
        <option value="">Svi načini</option>
        <option value="live" <?= $mode === 'live' ? 'selected' : '' ?>>LIVE</option>
        <option value="test" <?= $mode === 'test' ? 'selected' : '' ?>>TEST</option>
      </select>
      <input class="ainput" name="q" value="<?= e($q) ?>" placeholder="Broj računa, kupac…" style="max-width:220px">
      <button class="abtn sm">Filtriraj</button>
    </form>
    <div style="font-size:13.5px;color:#6b7280">
      Fiskalizirano: <strong><?= $cntFisk ?></strong> · LIVE promet: <strong><?= fmt_price($sumLive) ?></strong>
    </div>
  </div>

  <?php if (!$receipts): ?>
    <div class="alert alert-info">Nema fiskaliziranih računa za odabrani mjesec.</div>
  <?php else: ?>
  <table class="atable">
    <thead><tr><th>Datum</th><th>Račun br.</th><th>Narudžba</th><th>Kupac</th><th class="num">Iznos</th><th>Mod</th><th>Status</th><th>JIR</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($receipts as $r): ?>
      <tr>
        <td style="white-space:nowrap"><?= e(date('d.m.Y H:i', strtotime($r['fiscalized_at']))) ?></td>
        <td><strong><?= e($r['fiscal_receipt_number']) ?></strong></td>
        <td><a href="<?= e(adminUrl('narudzba.php?id=' . $r['id'])) ?>"><?= e($r['order_number']) ?></a></td>
        <td><?= e($r['customer_name']) ?><br><span style="font-size:12px;color:#9ca3af"><?= e($r['customer_email']) ?></span></td>
        <td class="num"><strong><?= fmt_price($r['total']) ?></strong></td>
        <td><span class="badge <?= $r['fiscal_mode'] === 'live' ? 'green' : 'amber' ?>"><?= strtoupper(e($r['fiscal_mode'])) ?></span></td>
        <td><span class="badge <?= $r['fiscal_status'] === 'fiscalized' ? 'green' : 'red' ?>"><?= $r['fiscal_status'] === 'fiscalized' ? 'Fiskaliziran' : 'Storniran' ?></span></td>
        <td><code style="font-size:11px" title="<?= e($r['fiscal_jir']) ?>"><?= e(mb_substr($r['fiscal_jir'], 0, 8)) ?>…</code></td>
        <td style="white-space:nowrap">
          <a class="abtn ghost sm" target="_blank" href="<?= e(adminUrl('racun.php?id=' . $r['id'])) ?>" title="Ispiši">🖨</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Poslati račun na <?= e($r['customer_email']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="order_id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="back_qs" value="<?= e($backQs) ?>">
            <button class="abtn ghost sm" title="Pošalji kupcu e-mailom">✉</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
