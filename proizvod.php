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
        (SELECT filename FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS image,
        (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id AND pv.is_active = 1) AS has_variants
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.is_visible = 1 AND p.is_orphaned = 0 AND p.id != :id' . ($product['category_id'] ? ' AND p.category_id = :cid' : '') . '
     ORDER BY RAND() LIMIT 4',
    array_merge([':id' => $product['id']], $product['category_id'] ? [':cid' => $product['category_id']] : [])
);

$variantData = Variants::storefrontData($product);
$outOfStock = $variantData
    ? !array_filter($variantData['variants'], fn($v) => !$v['out'])   // sve varijante rasprodane
    : ((int) $product['track_stock'] === 1 && (float) ($product['stock_qty'] ?? 0) <= 0);
$lowStock = !$variantData && !$outOfStock && (int) $product['track_stock'] === 1
    && $product['stock_qty'] !== null && (float) $product['stock_qty'] <= 5;

$displayPrice = (float) $product['price'];
$priceFrom = false;
if ($variantData) {
    $prices = array_column($variantData['variants'], 'price');
    $displayPrice = min($prices);
    $priceFrom = count(array_unique($prices)) > 1;
}
$prodUrl = SITE_URL . '/p/' . $product['slug'];

$pageTitle = $product['seo_title'] ?: $product['name'];
$pageDesc = $product['seo_description'] ?: mb_substr(strip_tags($product['short_description'] ?: ($product['description'] ?? '')), 0, 300);
$pageCanonical = $prodUrl;
$pageType = 'product';
$pageOgImage = $images ? SITE_URL . '/uploads/products/' . $images[0]['filename'] : null;
$crumbs = [['Početna', SITE_URL . '/'], ['Proizvodi', SITE_URL . '/proizvodi.php']];
if ($product['cat_name']) $crumbs[] = [$product['cat_name'], SITE_URL . '/k/' . $product['cat_slug']];
$crumbs[] = [$product['name'], null];
$pageJsonLd = [Seo::productJsonLd($product, $images, $prodUrl, $outOfStock), Seo::breadcrumbJsonLd($crumbs)];

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

      <?php if ($variantData): ?>
        <span id="pd-stock-pill" class="stock-pill" style="display:none"></span>
      <?php elseif ((int) $product['track_stock'] === 1): ?>
        <span class="stock-pill <?= $outOfStock ? 'out' : 'in' ?>"><span class="dot"></span><?= $outOfStock ? 'Trenutno nedostupno' : ($lowStock ? 'Još samo ' . (int) $product['stock_qty'] . ' kom' : 'Na zalihi') ?></span>
      <?php endif; ?>

      <?php if ($product['short_description']): ?>
        <p class="pd-short"><?= e($product['short_description']) ?></p>
      <?php endif; ?>

      <div class="pd-price"><small id="pd-price-from"<?= $priceFrom ? '' : ' style="display:none"' ?>>od </small><span id="pd-price-val"><?= fmt_price($displayPrice) ?></span> <small>/ <?= e($product['unit']) ?></small></div>
      <div class="pd-vat"><?= Djurdja::company()['inVatSystem'] ?? true ? 'PDV (' . rtrim(rtrim(number_format((float) $product['vat_rate'], 2, ',', ''), '0'), ',') . '%) uključen u cijenu.' : 'PDV nije obračunat (prodavatelj nije u sustavu PDV-a).' ?></div>

      <?php if ($variantData): ?>
        <div id="variant-area" class="variant-area">
          <?php foreach ($variantData['axes'] as $ai => $axis): ?>
            <div class="variant-axis" data-axis="<?= (int) $ai ?>">
              <span class="variant-label"><?= e($axis['name']) ?></span>
              <div class="variant-opts">
                <?php foreach ($axis['values'] as $val): ?>
                  <button type="button" data-val="<?= e($val) ?>"><?= e($val) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <script id="variant-data" type="application/json"><?= json_encode($variantData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <?php endif; ?>

      <div class="qty-row">
        <div class="qty-stepper">
          <button type="button" data-step="#qty-input" data-dec aria-label="Manje">−</button>
          <input id="qty-input" type="number" value="1" min="1" max="<?= (!$variantData && (int) $product['track_stock'] === 1 && $product['stock_qty'] !== null) ? max(1, (int) $product['stock_qty']) : 999 ?>">
          <button type="button" data-step="#qty-input" aria-label="Više">+</button>
        </div>
        <button class="btn btn-lg" data-add="<?= (int) $product['id'] ?>" data-use-qty<?= $variantData ? ' data-variant-required' : '' ?> <?= $outOfStock ? 'disabled' : '' ?> style="flex:1">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          <span id="add-label"><?= $outOfStock ? 'Rasprodano' : 'Dodaj u košaricu' ?></span>
        </button>
      </div>

      <div class="pd-meta">
        <?php if ($product['barcode']): ?><span>EAN: <?= e($product['barcode']) ?></span><?php endif; ?>
        <span>Šifra: #<?= (int) $product['id'] ?></span>
        <span>✓ Račun fiskaliziran u Poreznoj upravi</span>
      </div>

      <div class="share-row">
        <span class="lbl">Podijeli:</span>
        <button class="share-btn" type="button" data-share="native" data-url="<?= e($prodUrl) ?>" data-title="<?= e($product['name']) ?>" title="Podijeli" aria-label="Podijeli">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
        </button>
        <a class="share-btn" href="https://wa.me/?text=<?= rawurlencode($product['name'] . ' ' . $prodUrl) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.5 14.4c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.5 0 1.47 1.07 2.89 1.22 3.09.15.2 2.11 3.22 5.1 4.51.71.31 1.27.49 1.7.63.72.23 1.37.2 1.88.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.8h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 1 1 8.38 4.63zM12.05 0C5.5 0 .16 5.34.16 11.9c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45c6.56 0 11.9-5.34 11.9-11.9C23.94 5.34 18.6 0 12.05 0z"/></svg>
        </a>
        <a class="share-btn" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($prodUrl) ?>" target="_blank" rel="noopener" title="Facebook" aria-label="Facebook">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.09 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.5 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.49h-2.8V24C19.62 23.09 24 18.1 24 12.07z"/></svg>
        </a>
        <a class="share-btn" href="viber://forward?text=<?= rawurlencode($product['name'] . ' ' . $prodUrl) ?>" title="Viber" aria-label="Viber">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.4 0C9.47.02 5.33.34 3.02 2.46 1.3 4.17.7 6.69.63 9.82c-.06 3.11-.13 8.95 5.5 10.54v2.42s-.03.97.61 1.17c.79.25 1.24-.5 1.99-1.31l1.4-1.58c3.85.32 6.8-.42 7.14-.53.78-.25 5.18-.81 5.9-6.65.74-6.03-.36-9.83-2.34-11.55-.6-.55-3-2.29-8.37-2.31 0 0-.4-.03-1.06-.02zm.07 1.69c.56-.01.9.02.9.02 4.54.01 6.71 1.38 7.22 1.84 1.67 1.43 2.53 4.86 1.9 9.89-.6 4.88-4.18 5.19-4.84 5.4-.28.09-2.89.74-6.16.53 0 0-2.44 2.94-3.2 3.7-.12.13-.26.17-.35.15-.13-.03-.17-.19-.16-.42l.02-4.05c-4.77-1.32-4.49-6.29-4.44-8.89.06-2.6.55-4.73 2-6.17 1.95-1.78 5.47-1.99 7.11-2zm.36 2.49a.42.42 0 0 0-.01.84c1.58.01 2.89.52 3.95 1.56 1.06 1.04 1.59 2.46 1.61 4.27a.42.42 0 0 0 .84-.01c-.02-2-.62-3.65-1.86-4.86-1.23-1.21-2.78-1.79-4.53-1.8zm-3.4.85c-.2-.03-.41.01-.61.13l-.01.01c-.46.27-.88.61-1.24 1.02-.3.35-.46.7-.5 1.04a1.3 1.3 0 0 0 .06.55l.02.01c.32.95.74 1.86 1.25 2.72a16.27 16.27 0 0 0 2.45 3.33l.03.04.05.04.03.04.04.03a16.32 16.32 0 0 0 3.34 2.46c1.32.72 2.12.96 2.72 1.25h.01c.18.06.37.08.55.06.35-.04.7-.2 1.04-.5.4-.36.74-.78 1.01-1.24v-.01c.25-.43.17-.84-.16-1.12a14.56 14.56 0 0 0-2.05-1.46c-.46-.26-.93-.1-1.12.15l-.4.51c-.21.25-.58.22-.58.22h-.01s-2.79-.6-5.27-3.04l-.01-.01c-2.44-2.48-3.04-5.27-3.04-5.27v-.01s-.03-.37.22-.58l.5-.41c.26-.19.41-.66.16-1.12-.39-.7-.88-1.39-1.46-2.04a.84.84 0 0 0-.52-.27zm4.05.97a.42.42 0 0 0 .04.84c1.16.06 2.06.44 2.72 1.13.66.7.99 1.56.96 2.65a.42.42 0 0 0 .84.03c.03-1.27-.37-2.36-1.19-3.23-.83-.88-1.97-1.34-3.29-1.41h-.08zm.65 1.92a.42.42 0 0 0-.05.84c.54.07.91.25 1.16.52.25.27.4.65.43 1.21a.42.42 0 0 0 .84-.05c-.04-.69-.24-1.27-.65-1.72-.42-.45-.99-.71-1.66-.8h-.07z"/></svg>
        </a>
        <button class="share-btn" type="button" data-share="copy" data-url="<?= e($prodUrl) ?>" title="Kopiraj link" aria-label="Kopiraj link">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        </button>
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
