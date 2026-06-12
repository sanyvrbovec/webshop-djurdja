<?php
require_once __DIR__ . '/templates/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'save_all') {
        foreach (($_POST['sort'] ?? []) as $cid => $sort) {
            $db->update('categories', [
                'sort_order' => (int) $sort,
                'is_visible' => isset($_POST['visible'][$cid]) ? 1 : 0,
                'description' => mb_substr(trim((string) ($_POST['descr'][$cid] ?? '')), 0, 500) ?: null,
                'seo_title' => mb_substr(trim((string) ($_POST['seot'][$cid] ?? '')), 0, 190) ?: null,
                'seo_description' => mb_substr(trim((string) ($_POST['seod'][$cid] ?? '')), 0, 300) ?: null,
            ], 'id = :id', [':id' => (int) $cid]);
        }
        flash('success', 'Kategorije spremljene.');
    }
    redirect('admin/kategorije.php');
}

$cats = $db->fetchAll(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_orphaned = 0) AS cnt
     FROM categories c ORDER BY c.sort_order, c.name'
);

$pageTitle = 'Kategorije';
require __DIR__ . '/templates/header.php';
?>
<div class="acard">
  <p class="sub" style="margin:0 0 14px">Kategorije dolaze iz đurđe (naziv je read-only). Ovdje upravljate redoslijedom, vidljivošću i SEO opisom kategorije.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_all">
    <table class="atable">
      <thead><tr><th style="width:70px">Redosl.</th><th>Naziv (đurđa)</th><th>URL</th><th class="num">Artikala</th><th>Vidljiva</th><th>Opis (SEO)</th></tr></thead>
      <tbody>
      <?php foreach ($cats as $c): ?>
        <tr>
          <td><input class="ainput" style="width:64px" type="number" name="sort[<?= (int) $c['id'] ?>]" value="<?= (int) $c['sort_order'] ?>"></td>
          <td><strong><?= e($c['name']) ?></strong></td>
          <td><code style="font-size:12px">/k/<?= e($c['slug']) ?></code></td>
          <td class="num"><?= (int) $c['cnt'] ?></td>
          <td><input type="checkbox" name="visible[<?= (int) $c['id'] ?>]" <?= $c['is_visible'] ? 'checked' : '' ?> style="accent-color:#7c3aed;width:18px;height:18px"></td>
          <td style="display:grid;gap:6px;min-width:260px">
            <input class="ainput" name="descr[<?= (int) $c['id'] ?>]" value="<?= e($c['description'] ?? '') ?>" placeholder="Opis kategorije (prikazuje se i u SEO)…">
            <input class="ainput" name="seot[<?= (int) $c['id'] ?>]" value="<?= e($c['seo_title'] ?? '') ?>" placeholder="SEO naslov (prazno = naziv)" maxlength="190" style="font-size:12.5px">
            <input class="ainput" name="seod[<?= (int) $c['id'] ?>]" value="<?= e($c['seo_description'] ?? '') ?>" placeholder="SEO opis (prazno = opis gore)" maxlength="300" style="font-size:12.5px">
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$cats): ?><tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:30px">Nema kategorija — pokrenite <a href="<?= e(adminUrl('sync.php')) ?>">sinkronizaciju</a>.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php if ($cats): ?><button class="abtn" style="margin-top:16px">💾 Spremi sve</button><?php endif; ?>
  </form>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
