<?php
/** Detalj proizvoda (?slug= ili /p/{slug} preko .htaccess). */
require_once __DIR__ . '/core/bootstrap.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$product = $slug !== '' ? $db->fetch(
    'SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.slug = :s AND p.is_visible = 1 AND p.is_orphaned = 0',
    [':s' => $slug]
) : null;

if (!$product) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$images = $db->fetchAll(
    'SELECT * FROM product_images WHERE product_id = :p ORDER BY is_primary DESC, sort_order, id',
    [':p' => $product['id']]
);
$related = $db->fetchAll(
    'SELECT p.*, c.name AS cat_name,
        (SELECT filename FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS image
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.is_visible = 1 AND p.is_orphaned = 0 AND p.id != :id' . ($product['category_id'] ? ' AND p.category_id = :cid' : '') . '
     ORDER BY RAND() LIMIT 4',
    array_merge([':id' => $product['id']], $product['category_id'] ? [':cid' => $product['category_id']] : [])
);

$outOfStock = ((int) $product['track_stock'] === 1 && (float) ($product['stock_qty'] ?? 0) <= 0);
$prodUrl = SITE_URL . '/p/' . $product['slug'];

$pageTitle = $product['seo_title'] ?: $product['name'];
$pageDesc = $product['seo_description'] ?: mb_substr(strip_tags($product['short_description'] ?: ($product['description'] ?? '')), 0, 300);
$pageCanonical = $prodUrl;
$pageType = 'product';
$pageOgImage = $images ? SITE_URL . '/uploads/products/' . $images[0]['filename'] : null;
$crumbs = [['Početna', SITE_URL . '/'], ['Proizvodi', SITE_URL . '/proizvodi.php']];
if ($product['cat_name']) $crumbs[] = [$product['cat_name'], SITE_URL . '/k/' . $product['cat_slug']];
$crumbs[] = [$product['name'], null];
$pageJsonLd = [Seo::productJsonLd($product, $images, $prodUrl), Seo::breadcrumbJsonLd($crumbs)];

require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <nav class="breadcrumbs">
    <a href="<?= e(url('')) ?>">Početna</a><span class="sep">›</span>
    <a href="<?= e(url('proizvodi.php')) ?>">Proizvodi</a>
    <?php if ($product['cat_name']): ?><span class="sep">›</span><a href="<?= e(url('k/' . $product['cat_slug'])) ?>"><?= e($product['cat_name']) ?></a><?php endif; ?>
    <span class="sep">›</span><?= e($product['name']) ?>
  </nav>

  <div class="product-detail">
    <div class="fade-up">
      <div class="gallery-main">
        <?php if ($images): ?>
          <img id="gallery-main-img" src="<?= e(upload_url('products/' . $images[0]['filename'])) ?>" alt="<?= e($images[0]['alt'] ?: $product['name']) ?>" width="600" height="600">
        <?php else: ?>
          <div class="noimg" style="color:var(--c-muted)"><svg viewBox="0 0 24 24" width="80" height="80" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
        <?php endif; ?>
      </div>
      <?php if (count($images) > 1): ?>
        <div class="gallery-thumbs">
          <?php foreach ($images as $i => $img): ?>
            <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-thumb="<?= e(upload_url('products/' . $img['filename'])) ?>">
              <img src="<?= e(upload_url('products/' . $img['filename'])) ?>" alt="" loading="lazy">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="pd-info fade-up">
      <?php if ($product['cat_name']): ?><div class="p-cat"><?= e($product['cat_name']) ?></div><?php endif; ?>
      <h1><?= e($product['name']) ?></h1>

      <?php if ((int) $product['track_stock'] === 1): ?>
        <span class="stock-pill <?= $outOfStock ? 'out' : 'in' ?>"><span class="dot"></span><?= $outOfStock ? 'Trenutno nedostupno' : 'Na zalihi' ?></span>
      <?php endif; ?>

      <?php if ($product['short_description']): ?>
        <p class="pd-short"><?= e($product['short_description']) ?></p>
      <?php endif; ?>

      <div class="pd-price"><?= fmt_price($product['price']) ?> <small>/ <?= e($product['unit']) ?></small></div>
      <div class="pd-vat"><?= Djurdja::company()['inVatSystem'] ?? true ? 'PDV (' . rtrim(rtrim(number_format((float) $product['vat_rate'], 2, ',', ''), '0'), ',') . '%) uključen u cijenu.' : 'PDV nije obračunat (prodavatelj nije u sustavu PDV-a).' ?></div>

      <div class="qty-row">
        <div class="qty-stepper">
          <button type="button" data-step="#qty-input" data-dec aria-label="Manje">−</button>
          <input id="qty-input" type="number" value="1" min="1" max="999">
          <button type="button" data-step="#qty-input" aria-label="Više">+</button>
        </div>
        <button class="btn btn-lg" data-add="<?= (int) $product['id'] ?>" data-use-qty <?= $outOfStock ? 'disabled' : '' ?> style="flex:1">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          <?= $outOfStock ? 'Rasprodano' : 'Dodaj u košaricu' ?>
        </button>
      </div>

      <div class="pd-meta">
        <?php if ($product['barcode']): ?><span>EAN: <?= e($product['barcode']) ?></span><?php endif; ?>
        <span>Šifra: #<?= (int) $product['id'] ?></span>
        <span>✓ Račun fiskaliziran u Poreznoj upravi</span>
      </div>
    </div>
  </div>

  <?php if ($product['description']): ?>
    <div class="pd-desc">
      <h2>Opis proizvoda</h2>
      <div class="content"><?= $product['description'] /* HTML iz admina (vlasnik je trusted) */ ?></div>
    </div>
  <?php endif; ?>

  <?php if ($related): ?>
    <section class="section">
      <div class="section-head"><h2 class="section-title">Moglo bi vas zanimati</h2></div>
      <div class="product-grid">
        <?php foreach ($related as $p) include __DIR__ . '/includes/product-card.php'; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
