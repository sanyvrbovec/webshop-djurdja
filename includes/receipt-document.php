<?php
/**
 * Zajednički ispis fiskaliziranog računa (A4/POS) — koriste ga admin/racun.php
 * (vlasnik) i racun.php (kupac). Očekuje:
 *   $order, $items, $company, $inVat, $byRate, $itemsTotal
 *   $backUrl (string|null) — link "natrag"; null = bez gumba
 */
if (!isset($order)) { http_response_code(500); exit('receipt-document: nedostaju podaci.'); }
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
  <button onclick="window.print()" style="padding:10px 24px;font-size:14px;cursor:pointer">🖨 Ispiši / spremi PDF</button>
  <?php if (!empty($backUrl)): ?><a href="<?= e($backUrl) ?>" style="margin-left:10px">← natrag</a><?php endif; ?>
</div>

<div class="c">
  <?php if ($logo = Djurdja::receiptLogoUrl()): ?>
    <img src="<?= e($logo) ?>" alt="" style="max-height:64px;max-width:200px;margin:0 auto 8px">
  <?php endif; ?>
  <h2><?= e($company['companyName'] ?? shop_name()) ?></h2>
  <div class="muted">
    <?php if (!empty($company['address'])): ?><?= e($company['address']) ?>, <?= e($company['postalCode'] ?? '') ?> <?= e($company['city'] ?? '') ?><br><?php endif; ?>
    OIB: <?= e($company['companyOib'] ?? '—') ?> · <?= $inVat ? 'U sustavu PDV-a' : 'Nije u sustavu PDV-a' ?>
  </div>
  <?php if ($hdr = Djurdja::invoiceHeader()): ?><div class="muted" style="margin-top:6px;white-space:pre-line"><?= e($hdr) ?></div><?php endif; ?>
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
<?php if ($order['fiscal_mode'] === 'test'): ?>
  <div class="storno" style="color:#b45309;border-color:#b45309">TESTNI RAČUN — probni način rada, nije porezno valjan</div>
<?php endif; ?>

<table>
  <thead><tr><th>Artikl</th><th class="num">Kol.</th><th class="num">Cijena</th><th class="num">Iznos</th></tr></thead>
  <tbody>
  <?php foreach ($items as $it): ?>
    <tr><td><?= e($it['name']) ?><?= !empty($it['variant_label']) ? '<br><span class="muted">' . e($it['variant_label']) . '</span>' : '' ?></td><td class="num"><?= (int) $it['quantity'] ?></td><td class="num"><?= number_format((float) $it['unit_price'], 2, ',', '.') ?></td><td class="num"><?= number_format((float) $it['total'], 2, ',', '.') ?></td></tr>
  <?php endforeach; ?>
  <tr class="tot"><td colspan="3">UKUPNO</td><td class="num"><?= number_format($itemsTotal, 2, ',', '.') ?> €</td></tr>
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
<p class="muted">PDV nije obračunat — prodavatelj nije u sustavu PDV-a (čl. 90. Zakona o porezu na dodanu vrijednost, NN 73/13 i dalje).</p>
<?php endif; ?>

<?php if ((float) $order['shipping_cost'] > 0 || (float) $order['payment_fee'] > 0): ?>
<p class="muted">Napomena: dostava<?= (float) $order['payment_fee'] > 0 ? ' i naknada plaćanja' : '' ?>
(<?= number_format((float) $order['shipping_cost'] + (float) $order['payment_fee'], 2, ',', '.') ?> €)
nije predmet ovog računa i obračunava se zasebno.</p>
<?php endif; ?>

<div class="fbox" style="display:flex;gap:12px;align-items:center">
  <div style="flex:1">
    <strong>FISKALNI PODACI</strong><br>
    JIR: <?= e($order['fiscal_jir']) ?><br>
    ZKI: <?= e($order['fiscal_zki']) ?>
    <?php if ($order['fiscal_qr']): ?><br><span style="font-size:9.5px;color:#777">Skenirajte QR za provjeru računa.</span><?php endif; ?>
  </div>
  <?php if ($order['fiscal_qr'] && ($qr = Qr::dataUri($order['fiscal_qr']))): ?>
    <img src="<?= e($qr) ?>" alt="QR fiskalni" style="width:84px;height:84px;border:1px solid #ccc;border-radius:4px;background:#fff;padding:2px;flex:none">
  <?php endif; ?>
</div>

<?php if ($ftr = Djurdja::invoiceFooter()): ?><p class="muted" style="white-space:pre-line"><?= e($ftr) ?></p><?php endif; ?>
<p class="c muted">Hvala na kupnji! · <?= e(shop_name()) ?> · <?= e(SITE_URL) ?></p>
<?php if (Djurdja::brandingRequired()): ?>
<hr><p class="c muted">Račun izdan putem besplatnog sustava MojaĐurđa · mojadjurdja.com</p>
<?php endif; ?>
</body>
</html>
