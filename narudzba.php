<?php
/** Blagajna (checkout) — jedna stranica, šalje na api/checkout.php. */
require_once __DIR__ . '/core/bootstrap.php';

$items = Cart::detailed();
if (!$items) redirect('kosarica.php');

$checkoutOk = Djurdja::checkoutAllowed();
$subtotal = Cart::subtotal($items);
$allServices = !array_filter($items, fn($i) => (int) $i['is_service'] === 0);
$fo = (float) s('shipping_free_over', 0);
$shipping = $allServices ? 0.0 : (($fo > 0 && $subtotal >= $fo) ? 0.0 : (float) s('shipping_flat', 0));
$pm = new PaymentManager();
$methods = $pm->getActiveMethods();

$pageTitle = 'Blagajna';
$pageDesc = 'Dovršite narudžbu — ' . shop_name();
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="section-head" style="margin-top:26px"><h1 class="section-title">Blagajna</h1></div>

  <?php if (!$checkoutOk): ?>
    <div class="alert alert-warning">Trgovina trenutno ne može zaprimiti narudžbu (veza sa sustavom za izdavanje računa nije dostupna). Molimo pokušajte kasnije.</div>
  <?php endif; ?>

  <form id="checkout-form" <?= $checkoutOk ? '' : 'style="opacity:.5;pointer-events:none"' ?>>
    <?= csrf_field() ?><?= hp_fields() ?>
    <div class="checkout-grid">
      <div style="display:grid;gap:22px">
        <div class="card">
          <h3>1 · Podaci za dostavu</h3>
          <div class="form-grid">
            <div class="full"><label class="f-label">Ime i prezime *</label><input class="f-input" name="name" required maxlength="200" autocomplete="name"></div>
            <div><label class="f-label">E-mail *</label><input class="f-input" type="email" name="email" required maxlength="190" autocomplete="email"></div>
            <div><label class="f-label">Telefon</label><input class="f-input" name="phone" maxlength="40" autocomplete="tel"></div>
            <div class="full"><label class="f-label">Adresa *</label><input class="f-input" name="address" required maxlength="255" autocomplete="street-address"></div>
            <div><label class="f-label">Grad *</label><input class="f-input" name="city" required maxlength="100" autocomplete="address-level2"></div>
            <div><label class="f-label">Poštanski broj *</label><input class="f-input" name="postal" required pattern="\d{4,10}" maxlength="10" autocomplete="postal-code"></div>
            <div class="full"><label class="f-label">Napomena (opcionalno)</label><textarea class="f-input" name="note" rows="2" maxlength="2000"></textarea></div>
          </div>
        </div>

        <div class="card">
          <h3>2 · Način plaćanja</h3>
          <?php foreach ($methods as $i => $m):
              $fee = $pm->calculateFee($m['code'], $subtotal + $shipping); ?>
            <label class="pay-option <?= $i === 0 ? 'selected' : '' ?>">
              <input type="radio" name="payment_method" value="<?= e($m['code']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
              <div>
                <div class="t"><?= e($m['name']) ?><?= $fee > 0 ? ' <span class="fee">(+' . fmt_price($fee) . ')</span>' : '' ?></div>
                <div class="d"><?= e($m['description']) ?></div>
              </div>
            </label>
          <?php endforeach; ?>
          <div class="chk-line">
            <input type="checkbox" name="terms" id="terms" value="1" required>
            <label for="terms">Pročitao/la sam i prihvaćam <a href="<?= e(url('s/uvjeti-koristenja')) ?>" target="_blank">uvjete korištenja</a> i <a href="<?= e(url('s/zastita-privatnosti')) ?>" target="_blank">politiku privatnosti</a>. *</label>
          </div>
        </div>
      </div>

      <div class="card" style="position:sticky;top:90px">
        <h3>Vaša narudžba</h3>
        <?php foreach ($items as $it): ?>
          <div class="summary-row"><span><?= e($it['display_name'] ?? $it['name']) ?> <small style="color:var(--c-muted)">× <?= (int) $it['qty'] ?></small></span><span><?= fmt_price($it['line_total']) ?></span></div>
        <?php endforeach; ?>
        <div class="summary-row" style="border-top:1px solid var(--c-border);margin-top:6px;padding-top:12px"><span>Dostava</span><span><?= $shipping > 0 ? fmt_price($shipping) : 'Besplatna' ?></span></div>
        <div class="summary-row total"><span>Ukupno</span><span class="val" id="grand-total"><?= fmt_price($subtotal + $shipping) ?></span></div>
        <p style="font-size:12px;color:var(--c-muted);margin:8px 0 14px">+ eventualna naknada odabranog načina plaćanja. Sve cijene su u EUR<?= !empty(Djurdja::company()['inVatSystem']) ? ' s uključenim PDV-om' : '' ?>.</p>
        <button id="checkout-submit" data-label="Potvrdi narudžbu" class="btn btn-lg" style="width:100%" <?= $checkoutOk ? '' : 'disabled' ?>>Potvrdi narudžbu</button>
        <p style="font-size:12px;color:var(--c-muted);margin:12px 0 0;text-align:center">🔒 Sigurna kupovina · Fiskalizirani račun</p>
      </div>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
