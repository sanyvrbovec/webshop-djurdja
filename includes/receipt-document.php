<?php
/**
 * Zajednički A4 ispis fiskaliziranog računa — koriste ga admin/racun.php (vlasnik)
 * i racun.php (kupac). Očekuje: $order, $items, $company, $inVat, $backUrl.
 *
 * Porezna razrada (PDV + PnP + neoporezivo) čita se iz $order['fiscal_taxes'] —
 * onako kako ju je đurđa (izvor istine) izračunala i poslala CIS-u. Za stare
 * narudžbe bez spremljene razrade postoji fallback (samo PDV iz stavki).
 */
if (!isset($order)) { http_response_code(500); exit('receipt-document: nedostaju podaci.'); }
$parts = Orders::receiptParts($order, $items);
$money = fn($v) => number_format((float) $v, 2, ',', '.');
$pct   = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');

// Autoritativna razrada iz đurđe (JSON: totalAmount, vatBreakdown[], pnp[], nonTaxableAmount)
$ft = null;
if (!empty($order['fiscal_taxes'])) {
    $decoded = json_decode((string) $order['fiscal_taxes'], true);
    if (is_array($decoded)) $ft = $decoded;
}
$grandTotal = $ft['totalAmount'] ?? $parts['grandTotal'];
?><!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title>Račun <?= e($order['fiscal_receipt_number']) ?></title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;color:#111;font-size:12.5px;line-height:1.55;background:#f3f4f6;margin:0}
.sheet{background:#fff;max-width:190mm;min-height:auto;margin:18px auto;padding:18mm 16mm;box-shadow:0 1px 8px rgba(0,0,0,.12)}
h1{font-size:20px;margin:0 0 2px;letter-spacing:.5px}
.muted{color:#555}.sm{font-size:11px}
.top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}
.top .meta{text-align:right;white-space:nowrap}
.tag{display:inline-block;border:1px solid #111;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:bold}
hr{border:0;border-top:1px solid #d1d5db;margin:14px 0}
.parties{display:flex;justify-content:space-between;gap:24px;margin:6px 0 4px}
.parties h4{margin:0 0 3px;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:#6b7280}
table{width:100%;border-collapse:collapse;margin:10px 0}
th,td{padding:6px 6px;text-align:left;font-size:12px}
thead th{border-bottom:2px solid #111;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:#374151}
tbody td{border-bottom:1px solid #eceef1}
.num{text-align:right;white-space:nowrap}
.tot td{border-top:2px solid #111;border-bottom:none;font-size:15px;font-weight:bold;padding-top:9px}
.recap{width:62%;margin-left:auto}
.recap th,.recap td{padding:4px 6px;font-size:11.5px}
.fbox{display:flex;gap:14px;align-items:center;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;margin:16px 0}
.fbox .code{font-family:'Consolas',monospace;font-size:11px;word-break:break-all}
.banner{text-align:center;font-weight:bold;padding:7px;border-radius:6px;margin:10px 0}
.storno{color:#b00020;border:2px solid #b00020}
.testb{color:#b45309;border:2px solid #b45309}
.foot{margin-top:18px;text-align:center}
.noprint{text-align:center;margin:14px 0}
.noprint button{padding:10px 26px;font-size:14px;cursor:pointer;border:0;border-radius:6px;background:#111;color:#fff}
.noprint a{margin-left:12px}
@media print{
  body{background:#fff}
  .sheet{box-shadow:none;margin:0;max-width:none;padding:0}
  .noprint{display:none}
  @page{size:A4;margin:14mm}
}
</style>
</head>
<body>
<div class="noprint">
  <button onclick="window.print()">🖨 Ispiši / spremi PDF (A4)</button>
  <?php if (!empty($backUrl)): ?><a href="<?= e($backUrl) ?>">← natrag</a><?php endif; ?>
</div>

<div class="sheet">
  <div class="top">
    <div>
      <?php if ($logo = Djurdja::receiptLogoUrl()): ?>
        <img src="<?= e($logo) ?>" alt="" style="max-height:60px;max-width:220px;margin-bottom:8px"><br>
      <?php endif; ?>
      <h1><?= e($company['companyName'] ?? shop_name()) ?></h1>
      <div class="muted sm">
        <?php if (!empty($company['address'])): ?><?= e($company['address']) ?>, <?= e($company['postalCode'] ?? '') ?> <?= e($company['city'] ?? '') ?><br><?php endif; ?>
        OIB: <?= e($company['companyOib'] ?? '—') ?> · <?= $inVat ? 'U sustavu PDV-a' : 'Nije u sustavu PDV-a' ?>
      </div>
      <?php if ($hdr = Djurdja::invoiceHeader()): ?><div class="muted sm" style="margin-top:6px;white-space:pre-line"><?= e($hdr) ?></div><?php endif; ?>
    </div>
    <div class="meta">
      <div class="tag">RAČUN</div>
      <div style="font-size:17px;font-weight:bold;margin-top:6px"><?= e($order['fiscal_receipt_number']) ?></div>
      <div class="muted sm" style="margin-top:6px">
        Datum: <?= e($order['fiscalized_at']) ?><br>
        Narudžba: <?= e($order['order_number']) ?><br>
        Plaćanje: <?= e(Orders::paymentLabel($order['payment_method'])) ?> · EUR
      </div>
    </div>
  </div>

  <?php if ($order['fiscal_status'] === 'stornoed'): ?>
    <div class="banner storno">STORNIRANO — storno račun br. <?= e($order['fiscal_storno_receipt_number']) ?></div>
  <?php endif; ?>
  <?php if ($order['fiscal_mode'] === 'test'): ?>
    <div class="banner testb">TESTNI RAČUN — probni način rada, nije porezno valjan</div>
  <?php endif; ?>

  <hr>
  <div class="parties">
    <div>
      <h4>Kupac</h4>
      <div><?= e($order['customer_name'] ?: '—') ?></div>
      <?php if (!empty($order['address'])): ?><div class="muted sm"><?= e($order['address']) ?>, <?= e($order['postal_code'] ?? '') ?> <?= e($order['city'] ?? '') ?></div><?php endif; ?>
    </div>
  </div>

  <table>
    <thead><tr><th>Artikl</th><th class="num">Kol.</th><th class="num">Cijena</th><th class="num">Iznos</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['name']) ?><?= !empty($it['variant_label']) ? '<br><span class="muted sm">' . e($it['variant_label']) . '</span>' : '' ?></td>
        <td class="num"><?= (int) $it['quantity'] ?></td>
        <td class="num"><?= $money($it['unit_price']) ?></td>
        <td class="num"><?= $money($it['total']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($parts['shipping'] > 0): ?><tr><td>Dostava</td><td class="num"></td><td class="num"></td><td class="num"><?= $money($parts['shipping']) ?></td></tr><?php endif; ?>
    <?php if ($parts['fee'] > 0): ?><tr><td>Naknada plaćanja</td><td class="num"></td><td class="num"></td><td class="num"><?= $money($parts['fee']) ?></td></tr><?php endif; ?>
      <tr class="tot"><td colspan="3">UKUPNO ZA NAPLATU</td><td class="num"><?= $money($grandTotal) ?> €</td></tr>
    </tbody>
  </table>

  <?php
  // Porezna rekapitulacija — primarno iz autoritativne razrade ($ft), inače fallback.
  $hasVat = $ft && !empty($ft['vatBreakdown']);
  $hasPnp = $ft && !empty($ft['pnp']);
  $hasNonTax = $ft && !empty($ft['nonTaxableAmount']);
  ?>
  <?php if ($ft): ?>
    <table class="recap">
      <thead><tr><th>Porezna stavka</th><th class="num">Osnovica</th><th class="num">Stopa</th><th class="num">Iznos</th></tr></thead>
      <tbody>
        <?php foreach (($ft['vatBreakdown'] ?? []) as $r): ?>
          <tr><td>PDV</td><td class="num"><?= $money($r['base']) ?></td><td class="num"><?= $pct($r['rate']) ?>%</td><td class="num"><?= $money($r['amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php foreach (($ft['pnp'] ?? []) as $r): ?>
          <tr><td>Porez na potrošnju</td><td class="num"><?= $money($r['base']) ?></td><td class="num"><?= $pct($r['rate']) ?>%</td><td class="num"><?= $money($r['amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($hasNonTax): ?>
          <tr><td>Ne podliježe oporezivanju</td><td class="num"><?= $money($ft['nonTaxableAmount']) ?></td><td class="num">—</td><td class="num"><?= $money($ft['nonTaxableAmount']) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php if (!$inVat && !$hasPnp): ?>
      <p class="muted sm">PDV nije obračunat — prodavatelj nije u sustavu PDV-a (čl. 90. Zakona o PDV-u).</p>
    <?php endif; ?>
  <?php elseif ($inVat): ?>
    <!-- Fallback za stare narudžbe bez spremljene razrade: PDV iz stavki -->
    <table class="recap">
      <thead><tr><th>PDV stopa</th><th class="num">Osnovica</th><th class="num">PDV</th><th class="num">Ukupno</th></tr></thead>
      <tbody>
      <?php foreach ($parts['byRate'] as $rate => $gross):
          $r = (float) $rate;
          $base = $r > 0 ? round($gross / (1 + $r / 100), 2) : round($gross, 2);
          $vat = round($gross - $base, 2); ?>
        <tr><td><?= $pct($r) ?>%</td><td class="num"><?= $money($base) ?></td><td class="num"><?= $money($vat) ?></td><td class="num"><?= $money($gross) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted sm">PDV nije obračunat — prodavatelj nije u sustavu PDV-a (čl. 90. Zakona o PDV-u).</p>
  <?php endif; ?>

  <div class="fbox">
    <div style="flex:1">
      <strong>FISKALNI PODACI</strong>
      <div class="code">JIR: <?= e($order['fiscal_jir']) ?></div>
      <div class="code">ZKI: <?= e($order['fiscal_zki']) ?></div>
      <?php if ($order['fiscal_qr']): ?><div class="muted sm" style="margin-top:3px">Skenirajte QR kod za provjeru računa na Poreznoj upravi.</div><?php endif; ?>
    </div>
    <?php if ($order['fiscal_qr'] && ($qr = Qr::dataUri($order['fiscal_qr']))): ?>
      <img src="<?= e($qr) ?>" alt="QR" style="width:90px;height:90px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;padding:2px;flex:none">
    <?php endif; ?>
  </div>

  <?php if ($ftr = Djurdja::invoiceFooter()): ?><p class="muted sm" style="white-space:pre-line"><?= e($ftr) ?></p><?php endif; ?>
  <div class="foot muted sm">
    Hvala na kupnji! · <?= e(shop_name()) ?> · <?= e(SITE_URL) ?>
    <?php if (Djurdja::brandingRequired()): ?><br>Račun izdan putem besplatnog sustava MojaĐurđa · mojadjurdja.com<?php endif; ?>
  </div>
</div>
</body>
</html>
