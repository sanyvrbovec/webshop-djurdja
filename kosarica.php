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
          <?php foreach ($items as $it): ?>
            <tr>
              <td>
                <div class="cart-prod">
                  <?php if ($it['image']): ?><img src="<?= e(upload_url('products/' . $it['image'])) ?>" alt=""><?php else: ?><div class="ph"></div><?php endif; ?>
                  <a href="<?= e(url('p/' . $it['slug'])) ?>"><?= e($it['name']) ?></a>
                </div>
              </td>
              <td><?= fmt_price($it['price']) ?></td>
              <td>
                <div class="qty-stepper">
                  <button type="button" data-step="#qty-<?= (int) $it['id'] ?>" data-dec>−</button>
                  <input id="qty-<?= (int) $it['id'] ?>" data-cart-line="<?= (int) $it['id'] ?>" type="number" value="<?= (int) $it['qty'] ?>" min="1" max="999">
                  <button type="button" data-step="#qty-<?= (int) $it['id'] ?>">+</button>
                </div>
              </td>
              <td><strong><?= fmt_price($it['line_total']) ?></strong></td>
              <td><button class="link-danger" data-cart-remove="<?= (int) $it['id'] ?>">Ukloni</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3>Sažetak</h3>
        <div class="summary-row"><span>Međuzbroj</span><span><?= fmt_price($subtotal) ?></span></div>
        <?php $fo = (float) s('shipping_free_over', 0); $flat = (float) s('shipping_flat', 0); ?>
        <div class="summary-row"><span>Dostava</span><span><?= ($fo > 0 && $subtotal >= $fo) ? 'Besplatna 🎉' : (fmt_price($flat) . ' (na blagajni)') ?></span></div>
        <?php if ($fo > 0 && $subtotal < $fo): ?>
          <div class="alert alert-info" style="font-size:13px">Dodajte još <?= fmt_price($fo - $subtotal) ?> za besplatnu dostavu!</div>
        <?php endif; ?>
        <div class="summary-row total"><span>Ukupno (procjena)</span><span class="val"><?= fmt_price($subtotal + (($fo > 0 && $subtotal >= $fo) ? 0 : $flat)) ?></span></div>
        <a href="<?= e(url('narudzba.php')) ?>" class="btn btn-lg" style="width:100%;margin-top:16px">Na blagajnu →</a>
        <a href="<?= e(url('proizvodi.php')) ?>" class="btn btn-ghost btn-sm" style="width:100%;margin-top:10px">Nastavi kupovinu</a>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
