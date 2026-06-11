<?php
require_once __DIR__ . '/templates/init.php';

// Brze akcije: toggle vidljivosti / istaknuto
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pid = (int) ($_POST['id'] ?? 0);
    if ($_POST['action'] === 'toggle_visible') {
        $db->query('UPDATE products SET is_visible = 1 - is_visible WHERE id = :id', [':id' => $pid]);
    } elseif ($_POST['action'] === 'toggle_featured') {
        $db->query('UPDATE products SET is_featured = 1 - is_featured WHERE id = :id', [':id' => $pid]);
    }
    redirect('admin/proizvodi.php?' . http_build_query(array_diff_key($_GET, ['id' => 1])));
}

$q = trim((string) ($_GET['q'] ?? ''));
$cat = (int) ($_GET['cat'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 30;

$where = 'p.is_orphaned = 0'; $params = [];
if ($q !== '') { $where .= ' AND (p.name LIKE :q OR p.barcode = :qb)'; $params[':q'] = "%$q%"; $params[':qb'] = $q; }
if ($cat > 0) { $where .= ' AND p.category_id = :c'; $params[':c'] = $cat; }

$total = (int) $db->fetchColumn("SELECT COUNT(*) FROM products p WHERE $where", $params);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$products = $db->fetchAll(
    "SELECT p.*, c.name AS cat_name,
        (SELECT filename FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS image,
        (SELECT COUNT(*) FROM product_images pi2 WHERE pi2.product_id = p.id) AS img_cnt
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE $where ORDER BY p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params
);
$cats = $db->fetchAll('SELECT id, name FROM categories ORDER BY name');
$orphans = (int) $db->fetchColumn('SELECT COUNT(*) FROM products WHERE is_orphaned = 1');

$pageTitle = 'Proizvodi';
require __DIR__ . '/templates/header.php';
?>
<?php if ($orphans > 0): ?>
  <div class="alert alert-warning"><?= $orphans ?> artikala više ne postoji u đurđi (skriveni su iz ponude automatski).</div>
<?php endif; ?>

<div class="acard">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
    <input class="ainput" style="max-width:260px" name="q" value="<?= e($q) ?>" placeholder="Naziv ili barkod…">
    <select class="ainput" style="max-width:220px" name="cat" onchange="this.form.submit()">
      <option value="0">— Sve kategorije —</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="abtn sm">Traži</button>
    <span style="margin-left:auto;align-self:center;font-size:13px;color:#8b90a0"><?= $total ?> artikala · cijene i nazivi dolaze iz đurđe</span>
  </form>

  <table class="atable">
    <thead><tr><th></th><th>Artikl</th><th>Kategorija</th><th class="num">Cijena</th><th class="num">Zaliha</th><th>Vidljiv</th><th>Istaknut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
      <tr>
        <td><?php if ($p['image']): ?><img class="thumb" src="<?= e(upload_url('products/' . $p['image'])) ?>" alt=""><?php else: ?><div class="thumb" style="display:flex;align-items:center;justify-content:center;color:#c4c4d0;font-size:10px"><?= (int) $p['img_cnt'] ?> 📷</div><?php endif; ?></td>
        <td><a href="<?= e(adminUrl('proizvod.php?id=' . $p['id'])) ?>"><strong><?= e($p['name']) ?></strong></a><br><small style="color:#9ca3af">/p/<?= e($p['slug']) ?><?= $p['is_service'] ? ' · usluga' : '' ?></small></td>
        <td><?= e($p['cat_name'] ?? '—') ?></td>
        <td class="num"><?= fmt_price($p['price']) ?></td>
        <td class="num"><?= $p['track_stock'] ? (float) $p['stock_qty'] : '∞' ?></td>
        <td>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_visible"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="badge <?= $p['is_visible'] ? 'green' : 'gray' ?>" style="border:0;cursor:pointer"><?= $p['is_visible'] ? 'DA' : 'NE' ?></button></form>
        </td>
        <td>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_featured"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="badge <?= $p['is_featured'] ? 'violet' : 'gray' ?>" style="border:0;cursor:pointer"><?= $p['is_featured'] ? '★' : '☆' ?></button></form>
        </td>
        <td><a class="abtn ghost sm" href="<?= e(adminUrl('proizvod.php?id=' . $p['id'])) ?>">Uredi</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$products): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:30px">Nema artikala. Pokrenite <a href="<?= e(adminUrl('sync.php')) ?>">sinkronizaciju</a> iz đurđe.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
    <div class="pager"><?php for ($i = 1; $i <= $pages; $i++): $qsArr = array_merge($_GET, ['page' => $i]); ?>
      <?php if ($i === $page): ?><span class="cur"><?= $i ?></span><?php else: ?><a href="?<?= e(http_build_query($qsArr)) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
