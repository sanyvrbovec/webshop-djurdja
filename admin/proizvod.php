<?php
/**
 * Uređivanje proizvoda — LOKALNA obogaćivanja (slike, opis, SEO, vidljivost).
 * Naziv, cijena, PDV, jedinica i zaliha dolaze iz đurđe i tu su read-only.
 */
require_once __DIR__ . '/templates/init.php';

$id = (int) ($_GET['id'] ?? 0);
$p = $db->fetch('SELECT * FROM products WHERE id = :id', [':id' => $id]);
if (!$p) { flash('error', 'Proizvod nije pronađen.'); redirect('admin/proizvodi.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $db->update('products', [
            'description'     => trim((string) $_POST['description']) ?: null,
            'seo_title'       => mb_substr(trim((string) $_POST['seo_title']), 0, 190) ?: null,
            'seo_description' => mb_substr(trim((string) $_POST['seo_description']), 0, 300) ?: null,
            'is_visible'      => !empty($_POST['is_visible']) ? 1 : 0,
            'is_featured'     => !empty($_POST['is_featured']) ? 1 : 0,
        ], 'id = :id', [':id' => $id]);
        flash('success', 'Spremljeno.');

        // Upload novih slika (multiple)
        if (!empty($_FILES['images']['name'][0])) {
            $count = 0;
            foreach ($_FILES['images']['name'] as $i => $nm) {
                $file = [
                    'name' => $nm,
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error' => $_FILES['images']['error'][$i],
                    'size' => $_FILES['images']['size'][$i],
                ];
                if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;
                $v = Security::validateImageUpload($file);
                if (!$v['ok']) { flash('error', $nm . ': ' . $v['error']); continue; }
                $fn = Security::randomFileName($v['ext']);
                if (move_uploaded_file($file['tmp_name'], SHOP_ROOT . '/uploads/products/' . $fn)) {
                    $hasPrimary = (int) $db->fetchColumn('SELECT COUNT(*) FROM product_images WHERE product_id = :p AND is_primary = 1', [':p' => $id]);
                    $db->insert('product_images', [
                        'product_id' => $id, 'filename' => $fn,
                        'alt' => mb_substr($p['name'], 0, 255),
                        'is_primary' => $hasPrimary ? 0 : 1,
                        'sort_order' => $count + 10,
                    ]);
                    $count++;
                }
            }
            if ($count) flash('success', "Učitano slika: $count");
        }
    } elseif ($action === 'variants') {
        $rows = is_array($_POST['vr'] ?? null) ? $_POST['vr'] : [];
        Variants::saveSet($id, (string) ($_POST['axis1_name'] ?? ''), (string) ($_POST['axis2_name'] ?? ''), $rows);
        flash('success', 'Varijante spremljene.');
    } elseif ($action === 'img_delete') {
        $img = $db->fetch('SELECT * FROM product_images WHERE id = :i AND product_id = :p', [':i' => (int) $_POST['img_id'], ':p' => $id]);
        if ($img) {
            @unlink(SHOP_ROOT . '/uploads/products/' . $img['filename']);
            $db->delete('product_images', 'id = :i', [':i' => $img['id']]);
            flash('success', 'Slika obrisana.');
        }
    } elseif ($action === 'img_primary') {
        $db->query('UPDATE product_images SET is_primary = 0 WHERE product_id = :p', [':p' => $id]);
        $db->query('UPDATE product_images SET is_primary = 1 WHERE id = :i AND product_id = :p', [':i' => (int) $_POST['img_id'], ':p' => $id]);
        flash('success', 'Glavna slika postavljena.');
    }
    redirect('admin/proizvod.php?id=' . $id);
}

$p = $db->fetch('SELECT p.*, c.name AS cat_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = :id', [':id' => $id]);
$images = $db->fetchAll('SELECT * FROM product_images WHERE product_id = :p ORDER BY is_primary DESC, sort_order, id', [':p' => $id]);
$variants = Variants::forProduct($id, false);
$axis1Name = $variants[0]['option1_name'] ?? '';
$axis2Name = '';
foreach ($variants as $v) { if (!empty($v['option2_name'])) { $axis2Name = $v['option2_name']; break; } }

$pageTitle = 'Proizvod: ' . $p['name'];
require __DIR__ . '/templates/header.php';
?>
<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?><input type="hidden" name="action" value="save">
<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">
  <div style="display:grid;gap:20px">
    <div class="acard">
      <h3>Podaci iz đurđe <span class="badge violet">read-only</span></h3>
      <p class="sub">Naziv, cijenu, PDV i zalihu mijenjate u sustavu MojaĐurđa — ovdje se sinkroniziraju automatski.</p>
      <div class="aform-grid">
        <div class="full"><label class="al">Naziv</label><input class="ainput" readonly value="<?= e($p['name']) ?>"></div>
        <div><label class="al">Cijena (MPC)</label><input class="ainput" readonly value="<?= fmt_price($p['price']) ?>"></div>
        <div><label class="al">PDV</label><input class="ainput" readonly value="<?= e($p['vat_rate']) ?>%"></div>
        <div><label class="al">Jedinica</label><input class="ainput" readonly value="<?= e($p['unit']) ?>"></div>
        <div><label class="al">Zaliha</label><input class="ainput" readonly value="<?= $p['track_stock'] ? (float) $p['stock_qty'] : 'ne prati se' ?>"></div>
        <div><label class="al">Kategorija</label><input class="ainput" readonly value="<?= e($p['cat_name'] ?? '—') ?>"></div>
        <div><label class="al">Kratki opis (iz đurđe)</label><input class="ainput" readonly value="<?= e($p['short_description'] ?? '') ?>"></div>
      </div>
    </div>

    <div class="acard">
      <h3>Web opis (vaš sadržaj)</h3>
      <p class="sub">Dozvoljeni HTML tagovi za formatiranje. Ovaj opis je ključan za SEO — opišite proizvod detaljno.</p>
      <textarea class="ainput" name="description" rows="10" placeholder="<p>Detaljan opis proizvoda…</p>"><?= e($p['description'] ?? '') ?></textarea>
    </div>

    <div class="acard">
      <h3>SEO</h3>
      <div class="aform-grid">
        <div class="full"><label class="al">SEO naslov (prazno = naziv artikla)</label><input class="ainput" name="seo_title" maxlength="190" value="<?= e($p['seo_title'] ?? '') ?>"></div>
        <div class="full"><label class="al">SEO opis (max 300)</label><textarea class="ainput" name="seo_description" rows="2" maxlength="300"><?= e($p['seo_description'] ?? '') ?></textarea></div>
      </div>
      <p class="sub" style="margin-top:8px">URL: <code><?= e(SITE_URL . '/p/' . $p['slug']) ?></code></p>
    </div>
  </div>

  <div style="display:grid;gap:20px">
    <div class="acard">
      <h3>Vidljivost</h3>
      <label class="acheck"><input type="checkbox" name="is_visible" <?= $p['is_visible'] ? 'checked' : '' ?>> Prikaži u trgovini</label>
      <label class="acheck"><input type="checkbox" name="is_featured" <?= $p['is_featured'] ? 'checked' : '' ?>> Istaknut na naslovnici ★</label>
      <button class="abtn" style="width:100%;margin-top:10px;justify-content:center">💾 Spremi sve</button>
      <a class="abtn ghost sm" style="width:100%;margin-top:8px;justify-content:center" target="_blank" href="<?= e(url('p/' . $p['slug'])) ?>">Pogledaj na webu ↗</a>
    </div>

    <div class="acard">
      <h3>Slike (<?= count($images) ?>)</h3>
      <p class="sub">JPG/PNG/WEBP do 5 MB. Slike se čuvaju na vašem serveru.</p>
      <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="ainput">
      <p class="sub" style="margin-top:6px">Odaberite datoteke pa kliknite "Spremi sve".</p>
    </div>
  </div>
</div>
</form>

<div class="acard" id="variants" style="margin-top:20px">
  <h3>Varijante — veličine, boje… <span class="badge violet">lokalno u trgovini</span></h3>
  <p class="sub">Đurđa vodi ovaj artikl kao jednu stavku (jedna cijena i ukupna zaliha). Varijante su dodatak trgovine:
    kupac bira opciju, a vi po želji odredite drugačiju cijenu (prazno = osnovna <?= fmt_price($p['price']) ?>),
    vlastitu zalihu po opciji (prazno = ne prati se) i SKU. Na računu se varijanta dodaje u opis stavke.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="variants">
    <div class="aform-grid" style="margin-bottom:10px">
      <div><label class="al">Naziv 1. opcije (npr. Veličina)</label><input class="ainput" name="axis1_name" maxlength="60" value="<?= e($axis1Name) ?>" placeholder="Veličina"></div>
      <div><label class="al">Naziv 2. opcije (npr. Boja — opcionalno)</label><input class="ainput" name="axis2_name" maxlength="60" value="<?= e($axis2Name) ?>" placeholder="Boja"></div>
    </div>
    <table class="atable" id="var-table" style="font-size:13px">
      <thead><tr><th>1. opcija *</th><th>2. opcija</th><th>SKU</th><th>Cijena €</th><th>Zaliha</th><th>Aktivna</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($variants as $i => $v): ?>
        <tr>
          <td><input type="hidden" name="vr[<?= $i ?>][id]" value="<?= (int) $v['id'] ?>"><input class="ainput" name="vr[<?= $i ?>][option1_value]" maxlength="60" value="<?= e($v['option1_value']) ?>" required></td>
          <td><input class="ainput" name="vr[<?= $i ?>][option2_value]" maxlength="60" value="<?= e($v['option2_value'] ?? '') ?>"></td>
          <td><input class="ainput" name="vr[<?= $i ?>][sku]" maxlength="64" value="<?= e($v['sku'] ?? '') ?>"></td>
          <td><input class="ainput" name="vr[<?= $i ?>][price]" inputmode="decimal" value="<?= $v['price'] !== null ? e(number_format((float) $v['price'], 2, '.', '')) : '' ?>" placeholder="<?= e(number_format((float) $p['price'], 2, '.', '')) ?>" style="max-width:110px"></td>
          <td><input class="ainput" name="vr[<?= $i ?>][stock_qty]" inputmode="numeric" value="<?= $v['stock_qty'] !== null ? e(rtrim(rtrim(number_format((float) $v['stock_qty'], 2, '.', ''), '0'), '.')) : '' ?>" placeholder="∞" style="max-width:90px"></td>
          <td style="text-align:center"><input type="checkbox" name="vr[<?= $i ?>][is_active]" value="1" <?= $v['is_active'] ? 'checked' : '' ?>></td>
          <td><button type="button" class="abtn danger sm" onclick="this.closest('tr').remove()">✕</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="display:flex;gap:8px;margin-top:12px">
      <button type="button" class="abtn ghost sm" id="var-add">+ Dodaj varijantu</button>
      <button class="abtn sm">💾 Spremi varijante</button>
    </div>
  </form>
  <script>
  (function () {
    var idx = <?= count($variants) ?>;
    document.getElementById('var-add').addEventListener('click', function () {
      var tb = document.querySelector('#var-table tbody');
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td><input type="hidden" name="vr[' + idx + '][id]" value="0"><input class="ainput" name="vr[' + idx + '][option1_value]" maxlength="60" required></td>' +
        '<td><input class="ainput" name="vr[' + idx + '][option2_value]" maxlength="60"></td>' +
        '<td><input class="ainput" name="vr[' + idx + '][sku]" maxlength="64"></td>' +
        '<td><input class="ainput" name="vr[' + idx + '][price]" inputmode="decimal" placeholder="<?= e(number_format((float) $p['price'], 2, '.', '')) ?>" style="max-width:110px"></td>' +
        '<td><input class="ainput" name="vr[' + idx + '][stock_qty]" inputmode="numeric" placeholder="∞" style="max-width:90px"></td>' +
        '<td style="text-align:center"><input type="checkbox" name="vr[' + idx + '][is_active]" value="1" checked></td>' +
        '<td><button type="button" class="abtn danger sm" onclick="this.closest(\'tr\').remove()">✕</button></td>';
      tb.appendChild(tr);
      idx++;
    });
  })();
  </script>
</div>

<?php if ($images): ?>
<div class="acard">
  <h3>Galerija</h3>
  <div class="img-grid">
    <?php foreach ($images as $img): ?>
      <div class="img-tile <?= $img['is_primary'] ? 'primary' : '' ?>">
        <img src="<?= e(upload_url('products/' . $img['filename'])) ?>" alt="">
        <div class="acts">
          <?php if (!$img['is_primary']): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="img_primary"><input type="hidden" name="img_id" value="<?= (int) $img['id'] ?>"><button class="abtn ghost sm" title="Postavi kao glavnu">★</button></form>
          <?php else: ?><span class="badge violet">glavna</span><?php endif; ?>
          <form method="post" onsubmit="return confirm('Obrisati sliku?')"><?= csrf_field() ?><input type="hidden" name="action" value="img_delete"><input type="hidden" name="img_id" value="<?= (int) $img['id'] ?>"><button class="abtn danger sm">✕</button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
