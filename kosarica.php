<?php
require_once __DIR__ . '/core/bootstrap.php';

$items = Cart::detailed();
$subtotal = Cart::subtotal($items);
$problems = Cart::stockProblems($items);

$pageTitle = 'Košarica';
$pageDesc = 'Pregled košarice — ' . shop_name();
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="section-head" style="margin-top:26px"><h1 class="section-title">Košarica</h1></div>

  <?php if (!$items): ?>
    <div class="alert alert-info" style="max-width:560px">Vaša košarica je prazna. <a href="<?= e(url('proizvodi.php')) ?>">Razgledajte ponudu →</a></div>
  <?php else: ?>
    <?php foreach ($problems as $pr): ?><div class="alert alert-warning"><?= e($pr) ?></div><?php endforeach; ?>

    <div class="checkout-grid">
      <div class="card" style="padding:18px 22px">
        <table class="cart-table">
          <thead><tr><th>Proizvod</th><th>Cijena</th><th>Količina</th><th>Ukupno</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($items as $it):
            $kid = 'qty-' . str_replace(':', '-', $it['cart_key']);
            $maxQty = $it['variant_id']
                ? ($it['variant_stock'] !== null ? max(1, (int) $it['variant_stock']) : 999)
                : (((int) $it['track_stock'] === 1 && $it['stock_qty'] !== null) ? max(1, (int) $it['stock_qty']) : 999);
          ?>
            <tr>
              <td>
                <div class="cart-prod">
                  <?php if ($it['image']): ?><img src="<?= e(upload_url('products/' . $it['image'])) ?>" alt=""><?php else: ?><div class="ph"></div><?php endif; ?>
                  <div>
                    <a href="<?= e(url('p/' . $it['slug'])) ?>"><?= e($it['name']) ?></a>
                    <?php if ($it['variant_label']): ?><div style="font-size:12.5px;color:var(--c-muted)"><?= e($it['variant_label']) ?></div><?php endif; ?>
                  </div>
                </div>
              </td>
              <td><?= fmt_price($it['price']) ?></td>
              <td>
                <div class="qty-stepper">
                  <button type="button" data-step="#<?= $kid ?>" data-dec>−</button>
                  <input id="<?= $kid ?>" data-cart-line="<?= e($it['cart_key']) ?>" type="number" value="<?= (int) $it['qty'] ?>" min="1" max="<?= $maxQty ?>">
                  <button type="button" data-step="#<?= $kid ?>">+</button>
                </div>
              </td>
              <td><strong><?= fmt_price($it['line_total']) ?></strong></td>
              <td><button class="link-danger" data-cart-remove="<?= e($it['cart_key']) ?>">Ukloni</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3>Sažetak</h3>
        <div class="summary-row"><span>Međuzbroj</span><span><?= fmt_price($subtotal) ?></span></div>
        <?php $fo = (float) s('shipping_free_over', 0); $flat = (float) s('shipping_flat', 0); ?>
        <div class="summary-row"><span>Dostava</span><span><?= ($fo > 0 && $subtotal >= $fo) ? 'Besplatna' : (fmt_price($flat) . ' (na blagajni)') ?></span></div>
        <?php if ($fo > 0): $rem = $fo - $subtotal; $pct = max(0, min(100, (int) round($subtotal / $fo * 100))); ?>
          <div class="ship-bar <?= $rem <= 0 ? 'ok' : '' ?>" style="margin:8px 0 4px">
            <div class="ship-bar-txt"><?= $rem > 0 ? 'Još <strong>' . fmt_price($rem) . '</strong> do besplatne dostave' : 'Besplatna dostava ostvarena ✓' ?></div>
            <div class="ship-bar-track"><div class="ship-bar-fill" style="width:<?= $rem <= 0 ? 100 : $pct ?>%"></div></div>
          </div>
        <?php endif; ?>
        <div class="summary-row total"><span>Ukupno (procjena)</span><span class="val"><?= fmt_price($subtotal + (($fo > 0 && $subtotal >= $fo) ? 0 : $flat)) ?></span></div>
        <a href="<?= e(url('narudzba.php')) ?>" class="btn btn-lg" style="width:100%;margin-top:16px">Na blagajnu →</a>
        <a href="<?= e(url('proizvodi.php')) ?>" class="btn btn-ghost btn-sm" style="width:100%;margin-top:10px">Nastavi kupovinu</a>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
