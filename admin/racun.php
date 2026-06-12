<?php
/** Printabilni prikaz fiskaliziranog računa (A4/POS friendly). */
require_once __DIR__ . '/templates/init.php';

$id = (int) ($_GET['id'] ?? 0);
$order = $db->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
if (!$order || !in_array($order['fiscal_status'], ['fiscalized', 'stornoed'], true)) {
    flash('error', 'Račun nije fiskaliziran.');
    redirect('admin/narudzba.php?id=' . $id);
}
$items = $db->fetchAll('SELECT * FROM order_items WHERE order_id = :o', [':o' => $id]);
$company = Djurdja::company();
$inVat = !empty($company['inVatSystem']);

// PDV rekapitulacija po stopama (iz bruto iznosa)
$byRate = [];
foreach ($items as $it) {
    $r = (string) round((float) $it['vat_rate'], 2);
    $byRate[$r] = ($byRate[$r] ?? 0) + (float) $it['total'];
}
$extra = (float) $order['shipping_cost'] + (float) $order['payment_fee'];
if ($extra > 0) {
    $r = (string) round((float) s('shipping_vat_rate', '25'), 2);
    $byRate[$r] = ($byRate[$r] ?? 0) + $extra;
}
?><!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title>Račun <?= e($order['fiscal_receipt_number']) ?></title>
<style>
body{font-family:'Segoe UI',Arial,sans-serif;max-width:420px;margin:24px auto;color:#111;font-size:13px;line-height:1.5}
h2{margin:0;font-size:16px}.c{text-align:center}.muted{color:#555;font-size:11.5px}
table{width:100%;border-collapse:collapse;margin:12px 0}
td,th{padding:4px 2px;text-align:left;font-size:12.5px}th{border-bottom:1px solid #000;font-size:11px;text-transform:uppercase}
.num{text-align:right;white-space:nowrap}
.tot{border-top:2px solid #000;font-size:15px;font-weight:bold}
.fbox{border:1px dashed #888;padding:10px;margin:12px 0;font-size:10.5px;word-break:break-all}
hr{border:0;border-top:1px dashed #999}
.storno{color:#b00;border:2px solid #b00;text-align:center;font-weight:bold;padding:6px;margin:10px 0}
@media print{body{margin:4mm auto}.noprint{display:none}}
</style>
</head>
<body>
<div class="noprint c" style="margin-bottom:14px">
  <button onclick="window.print()" style="padding:10px 24px;font-size:14px;cursor:pointer">🖨 Ispiši</button>
  <a href="<?= e(adminUrl('narudzba.php?id=' . $id)) ?>" style="margin-left:10px">← natrag</a>
</div>

<div class="c">
  <h2><?= e($company['companyName'] ?? shop_name()) ?></h2>
  <div class="muted">
    <?php if (!empty($company['address'])): ?><?= e($company['address']) ?>, <?= e($company['postalCode'] ?? '') ?> <?= e($company['city'] ?? '') ?><br><?php endif; ?>
    OIB: <?= e($company['companyOib'] ?? '—') ?> · <?= $inVat ? 'U sustavu PDV-a' : 'Nije u sustavu PDV-a' ?>
  </div>
</div>
<hr>
<div>
  <strong>RAČUN br. <?= e($order['fiscal_receipt_number']) ?></strong><br>
  <span class="muted">Narudžba: <?= e($order['order_number']) ?> · <?= e($order['fiscalized_at']) ?><br>
  Način plaćanja: <?= e(Orders::paymentLabel($order['payment_method'])) ?> · Valuta: EUR</span>
</div>
<?php if ($order['fiscal_status'] === 'stornoed'): ?>
  <div class="storno">STORNIRANO — storno račun br. <?= e($order['fiscal_storno_receipt_number']) ?></div>
<?php endif; ?>

<table>
  <thead><tr><th>Artikl</th><th class="num">Kol.</th><th class="num">Cijena</th><th class="num">Iznos</th></tr></thead>
  <tbody>
  <?php foreach ($items as $it): ?>
    <tr><td><?= e($it['name']) ?><?= !empty($it['variant_label']) ? '<br><span class="muted">' . e($it['variant_label']) . '</span>' : '' ?></td><td class="num"><?= (int) $it['quantity'] ?></td><td class="num"><?= number_format((float) $it['unit_price'], 2, ',', '.') ?></td><td class="num"><?= number_format((float) $it['total'], 2, ',', '.') ?></td></tr>
  <?php endforeach; ?>
  <?php if ((float) $order['shipping_cost'] > 0): ?><tr><td>Dostava</td><td class="num">1</td><td class="num"><?= number_format((float) $order['shipping_cost'], 2, ',', '.') ?></td><td class="num"><?= number_format((float) $order['shipping_cost'], 2, ',', '.') ?></td></tr><?php endif; ?>
  <?php if ((float) $order['payment_fee'] > 0): ?><tr><td>Naknada plaćanja</td><td class="num">1</td><td class="num"><?= number_format((float) $order['payment_fee'], 2, ',', '.') ?></td><td class="num"><?= number_format((float) $order['payment_fee'], 2, ',', '.') ?></td></tr><?php endif; ?>
  <tr class="tot"><td colspan="3">UKUPNO</td><td class="num"><?= number_format((float) $order['total'], 2, ',', '.') ?> €</td></tr>
  </tbody>
</table>

<?php if ($inVat): ?>
<table>
  <thead><tr><th>PDV stopa</th><th class="num">Osnovica</th><th class="num">PDV</th><th class="num">Ukupno</th></tr></thead>
  <tbody>
  <?php foreach ($byRate as $rate => $gross):
      $r = (float) $rate;
      $base = $r > 0 ? round($gross / (1 + $r / 100), 2) : round($gross, 2);
      $vat = round($gross - $base, 2); ?>
    <tr><td><?= rtrim(rtrim(number_format($r, 2, ',', ''), '0'), ',') ?>%</td><td class="num"><?= number_format($base, 2, ',', '.') ?></td><td class="num"><?= number_format($vat, 2, ',', '.') ?></td><td class="num"><?= number_format($gross, 2, ',', '.') ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<p class="muted">PDV nije obračunat — prodavatelj nije u sustavu PDV-a (čl. 90. Zakona o PDV-u).</p>
<?php endif; ?>

<div class="fbox">
  <strong>FISKALNI PODACI</strong><br>
  JIR: <?= e($order['fiscal_jir']) ?><br>
  ZKI: <?= e($order['fiscal_zki']) ?><br>
  <?php if ($order['fiscal_qr']): ?>Provjera računa: <?= e($order['fiscal_qr']) ?><?php endif; ?>
</div>
<p class="c muted">Hvala na kupnji! · <?= e(shop_name()) ?> · <?= e(SITE_URL) ?></p>
</body>
</html>
