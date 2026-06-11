<?php
/**
 * Kartica proizvoda. Očekuje $p s poljima:
 *   id, name, slug, price, unit, track_stock, stock_qty, is_featured,
 *   image (filename|null), cat_name (opcionalno)
 */
$outOfStock = ((int) $p['track_stock'] === 1 && (float) ($p['stock_qty'] ?? 0) <= 0);
?>
<article class="p-card fade-up">
  <div class="p-media">
    <?php if ($outOfStock): ?>
      <span class="p-badge out">Rasprodano</span>
    <?php elseif (!empty($p['is_featured'])): ?>
      <span class="p-badge featured">★ Izdvojeno</span>
    <?php endif; ?>
    <?php if (!empty($p['image'])): ?>
      <img src="<?= e(upload_url('products/' . $p['image'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy" width="400" height="400">
    <?php else: ?>
      <div class="noimg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
    <?php endif; ?>
  </div>
  <div class="p-body">
    <?php if (!empty($p['cat_name'])): ?><div class="p-cat"><?= e($p['cat_name']) ?></div><?php endif; ?>
    <h3 class="p-name"><a href="<?= e(url('p/' . $p['slug'])) ?>"><?= e($p['name']) ?></a></h3>
    <div class="p-foot">
      <div class="p-price"><?= fmt_price($p['price']) ?> <small>/ <?= e($p['unit']) ?></small></div>
      <button class="p-add" data-add="<?= (int) $p['id'] ?>" <?= $outOfStock ? 'disabled' : '' ?> aria-label="Dodaj u košaricu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      </button>
    </div>
  </div>
</article>
