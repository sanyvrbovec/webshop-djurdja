<?php
/** Listing proizvoda: kategorija (?cat=slug), pretraga (?q=), sortiranje, paginacija. */
require_once __DIR__ . '/core/bootstrap.php';

$catSlug = trim((string) ($_GET['cat'] ?? ''));
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100));
$sort = (string) ($_GET['sort'] ?? 'novo');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(4, (int) s('products_per_page', 12));

$category = null;
if ($catSlug !== '') {
    $category = $db->fetch('SELECT * FROM categories WHERE slug = :s AND is_visible = 1', [':s' => $catSlug]);
    if (!$category) { http_response_code(404); require __DIR__ . '/404.php'; exit; }
}

$where = 'p.is_visible = 1 AND p.is_orphaned = 0';
$params = [];
if ($category) { $where .= ' AND p.category_id = :cid'; $params[':cid'] = $category['id']; }
if ($q !== '') {
    $where .= ' AND (p.name LIKE :q OR p.short_description LIKE :q OR p.barcode = :qe)';
    $params[':q'] = '%' . $q . '%';
    $params[':qe'] = $q;
}

$orderBy = [
    'novo'       => 'p.created_at DESC',
    'cijena-uz'  => 'p.price ASC',
    'cijena-niz' => 'p.price DESC',
    'naziv'      => 'p.name ASC',
][$sort] ?? 'p.created_at DESC';

$total = (int) $db->fetchColumn("SELECT COUNT(*) FROM products p WHERE $where", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$products = $db->fetchAll(
    "SELECT p.*, c.name AS cat_name,
        (SELECT filename FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS image
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset",
    $params
);

$sidebar = $db->fetchAll(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_visible = 1 AND p.is_orphaned = 0) AS cnt
     FROM categories c WHERE c.is_visible = 1 ORDER BY c.sort_order, c.name'
);

$pageTitle = $category ? $category['name'] : ($q !== '' ? 'Pretraga: ' . $q : 'Svi proizvodi');
$pageDesc = $category && $category['description'] ? $category['description'] : ($pageTitle . ' — ' . shop_name());
$crumbs = [['Početna', SITE_URL . '/'], ['Proizvodi', SITE_URL . '/proizvodi.php']];
if ($category) $crumbs[] = [$category['name'], null];
$pageJsonLd = [Seo::breadcrumbJsonLd($crumbs)];

// URL builder koji čuva parametre
function listing_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    unset($params['cat']);
    $base = isset($GLOBALS['category']) && $GLOBALS['category'] ? url('k/' . $GLOBALS['category']['slug']) : url('proizvodi.php');
    $qs = http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
    return $base . ($qs ? '?' . $qs : '');
}

require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <nav class="breadcrumbs">
    <a href="<?= e(url('')) ?>">Početna</a><span class="sep">›</span>
    <?php if ($category): ?>
      <a href="<?= e(url('proizvodi.php')) ?>">Proizvodi</a><span class="sep">›</span><?= e($category['name']) ?>
    <?php else: ?>Proizvodi<?php endif; ?>
  </nav>

  <div class="section-head" style="margin-top:18px">
    <h1 class="section-title"><?= e($pageTitle) ?></h1>
  </div>

  <div class="listing">
    <aside class="filter-card">
      <h4>Kategorije</h4>
      <ul>
        <li><a href="<?= e(url('proizvodi.php')) ?>" <?= !$category ? 'class="active"' : '' ?>>Sve kategorije</a></li>
        <?php foreach ($sidebar as $c): if ((int) $c['cnt'] === 0) continue; ?>
          <li><a href="<?= e(url('k/' . $c['slug'])) ?>" <?= $category && $category['id'] === $c['id'] ? 'class="active"' : '' ?>>
            <?= e($c['name']) ?> <span><?= (int) $c['cnt'] ?></span></a></li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <div>
      <div class="listing-toolbar">
        <span class="cnt"><?= $total ?> <?= $total === 1 ? 'proizvod' : ($total < 5 && $total > 1 ? 'proizvoda' : 'proizvoda') ?><?= $q !== '' ? ' za "' . e($q) . '"' : '' ?></span>
        <form method="get" action="<?= e($category ? url('k/' . $category['slug']) : url('proizvodi.php')) ?>">
          <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
          <select name="sort" onchange="this.form.submit()">
            <option value="novo" <?= $sort === 'novo' ? 'selected' : '' ?>>Najnovije</option>
            <option value="cijena-uz" <?= $sort === 'cijena-uz' ? 'selected' : '' ?>>Cijena: niža → viša</option>
            <option value="cijena-niz" <?= $sort === 'cijena-niz' ? 'selected' : '' ?>>Cijena: viša → niža</option>
            <option value="naziv" <?= $sort === 'naziv' ? 'selected' : '' ?>>Naziv A–Ž</option>
          </select>
        </form>
      </div>

      <?php if (!$products): ?>
        <div class="alert alert-info">Nema proizvoda za zadane kriterije. <a href="<?= e(url('proizvodi.php')) ?>">Pogledajte cijelu ponudu →</a></div>
      <?php else: ?>
        <div class="product-grid">
          <?php foreach ($products as $p) include __DIR__ . '/includes/product-card.php'; ?>
        </div>
      <?php endif; ?>

      <?php if ($pages > 1): ?>
        <nav class="pagination">
          <?php if ($page > 1): ?><a href="<?= e(listing_url(['page' => $page - 1])) ?>">‹</a><?php endif; ?>
          <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
            <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="<?= e(listing_url(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $pages): ?><a href="<?= e(listing_url(['page' => $page + 1])) ?>">›</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
