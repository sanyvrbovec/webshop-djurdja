<?php
/** Potvrda/status narudžbe — pristup preko guest tokena (?t=...). */
require_once __DIR__ . '/core/bootstrap.php';

$token = (string) ($_GET['t'] ?? '');
$order = preg_match('/^[a-f0-9]{40,64}$/', $token)
    ? $db->fetch('SELECT * FROM orders WHERE guest_token = :t', [':t' => $token])
    : null;

if (!$order) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$items = $db->fetchAll('SELECT * FROM order_items WHERE order_id = :o', [':o' => $order['id']]);
$payCancelled = ($_GET['pay'] ?? '') === 'cancel';

$pageTitle = 'Narudžba ' . $order['order_number'];
$pageDesc = 'Status narudžbe';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="confirm-hero">
    <?php if ($payCancelled && $order['payment_status'] !== 'paid'): ?>
      <h1>Plaćanje nije dovršeno</h1>
      <p style="color:var(--c-muted)">Narudžba <strong><?= e($order['order_number']) ?></strong> je spremljena, ali kartično plaćanje je prekinuto.<br>Možete pokušati ponovno ili nas kontaktirati.</p>
    <?php else: ?>
      <div class="big"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
      <h1>Hvala na narudžbi! 🎉</h1>
      <p style="color:var(--c-muted)">Narudžba <strong><?= e($order['order_number']) ?></strong> je zaprimljena<?= $order['payment_status'] === 'paid' ? ' i plaćena' : '' ?>.<br>Potvrdu smo poslali na <strong><?= e($order['customer_email']) ?></strong>.</p>
    <?php endif; ?>
  </div>

  <div class="checkout-grid" style="max-width:980px;margin:0 auto">
    <div class="card">
      <h3>Stavke</h3>
      <?php foreach ($items as $it): ?>
        <div class="summary-row"><span><?= e($it['name'] . (!empty($it['variant_label']) ? ' — ' . $it['variant_label'] : '')) ?> <small style="color:var(--c-muted)">× <?= (int) $it['quantity'] ?></small></span><span><?= fmt_price($it['total']) ?></span></div>
      <?php endforeach; ?>
      <?php if ((float) $order['shipping_cost'] > 0): ?><div class="summary-row"><span>Dostava</span><span><?= fmt_price($order['shipping_cost']) ?></span></div><?php endif; ?>
      <?php if ((float) $order['payment_fee'] > 0): ?><div class="summary-row"><span>Naknada plaćanja</span><span><?= fmt_price($order['payment_fee']) ?></span></div><?php endif; ?>
      <div class="summary-row total"><span>Ukupno</span><span class="val"><?= fmt_price($order['total']) ?></span></div>

      <?php if ($order['fiscal_status'] === 'fiscalized'): ?>
        <div class="fiscal-box" style="margin-top:16px">
          <?php if ($order['fiscal_mode'] === 'test'): ?><strong style="color:#b45309">⚠ TESTNI RAČUN (probni način rada)</strong><br><?php endif; ?>
          <strong>✓ Račun je fiskaliziran u Poreznoj upravi</strong><br>
          Broj računa: <strong><?= e($order['fiscal_receipt_number']) ?></strong><br>
          JIR: <?= e($order['fiscal_jir']) ?><br>
          ZKI: <?= e($order['fiscal_zki']) ?>
          <?php if ($order['fiscal_qr']): ?><br><a href="<?= e($order['fiscal_qr']) ?>" target="_blank" rel="noopener">Provjeri račun u Poreznoj upravi ↗</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div style="display:grid;gap:20px;align-content:start">
      <div class="card">
        <h3>Status</h3>
        <div class="summary-row"><span>Narudžba</span><strong><?= e(Orders::statusLabel($order['status'])) ?></strong></div>
        <div class="summary-row"><span>Plaćanje</span><strong><?= $order['payment_status'] === 'paid' ? 'Plaćeno ✓' : ($order['payment_status'] === 'failed' ? 'Neuspješno' : 'Čeka uplatu') ?></strong></div>
        <div class="summary-row"><span>Način</span><strong><?= e(Orders::paymentLabel($order['payment_method'])) ?></strong></div>
        <div class="summary-row"><span>Dostava na</span><span style="text-align:right"><?= e($order['customer_name']) ?><br><?= e($order['address']) ?>, <?= e($order['postal_code']) ?> <?= e($order['city']) ?></span></div>
      </div>

      <a href="<?= e(url('proizvodi.php')) ?>" class="btn btn-ghost" style="width:100%">← Natrag u trgovinu</a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
