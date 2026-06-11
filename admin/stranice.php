<?php
/** CMS stranice — uređivanje, vidljivost, navigacija. */
require_once __DIR__ . '/templates/init.php';

$editId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int) $_POST['id'];
        $title = mb_substr(trim((string) $_POST['title']), 0, 255);
        $data = [
            'title' => $title ?: 'Stranica',
            'content' => (string) $_POST['content'],
            'is_visible' => !empty($_POST['is_visible']) ? 1 : 0,
            'in_nav' => !empty($_POST['in_nav']) ? 1 : 0,
            'in_footer' => !empty($_POST['in_footer']) ? 1 : 0,
            'sort_order' => (int) $_POST['sort_order'],
            'seo_title' => mb_substr(trim((string) $_POST['seo_title']), 0, 190) ?: null,
            'seo_description' => mb_substr(trim((string) $_POST['seo_description']), 0, 300) ?: null,
        ];
        if ($id > 0) {
            $db->update('pages', $data, 'id = :id', [':id' => $id]);
            flash('success', 'Stranica spremljena.');
        } else {
            $slug = slugify($title);
            $i = 2; $base = $slug;
            while ($db->fetchColumn('SELECT id FROM pages WHERE slug = :s', [':s' => $slug])) { $slug = $base . '-' . $i++; }
            $data['slug'] = $slug;
            $id = $db->insert('pages', $data);
            flash('success', 'Stranica kreirana.');
        }
        redirect('admin/stranice.php?id=' . $id);
    } elseif ($action === 'delete') {
        $db->delete('pages', 'id = :id', [':id' => (int) $_POST['id']]);
        flash('success', 'Stranica obrisana.');
        redirect('admin/stranice.php');
    }
}

$pages = $db->fetchAll('SELECT * FROM pages ORDER BY sort_order, id');
$edit = $editId ? $db->fetch('SELECT * FROM pages WHERE id = :id', [':id' => $editId]) : null;

$pageTitle = 'Stranice';
require __DIR__ . '/templates/header.php';
?>
<div style="display:grid;grid-template-columns:330px 1fr;gap:20px;align-items:start">
  <div class="acard">
    <h3>Sve stranice</h3>
    <table class="atable" style="font-size:13px">
      <?php foreach ($pages as $pg): ?>
        <tr>
          <td><a href="?id=<?= (int) $pg['id'] ?>"><strong><?= e($pg['title']) ?></strong></a><br><small style="color:#9ca3af">/s/<?= e($pg['slug']) ?></small></td>
          <td style="text-align:right">
            <?= $pg['is_visible'] ? '<span class="badge green">vidljiva</span>' : '<span class="badge gray">skrivena</span>' ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <a class="abtn sm" style="margin-top:14px" href="?id=0&new=1">+ Nova stranica</a>
  </div>

  <?php if ($edit || isset($_GET['new'])): ?>
  <div class="acard">
    <h3><?= $edit ? 'Uredi: ' . e($edit['title']) : 'Nova stranica' ?></h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="aform-grid">
        <div class="full"><label class="al">Naslov</label><input class="ainput" name="title" required maxlength="255" value="<?= e($edit['title'] ?? '') ?>"></div>
        <div class="full"><label class="al">Sadržaj (HTML)</label><textarea class="ainput" name="content" rows="14" style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($edit['content'] ?? '') ?></textarea></div>
        <div><label class="al">SEO naslov</label><input class="ainput" name="seo_title" maxlength="190" value="<?= e($edit['seo_title'] ?? '') ?>"></div>
        <div><label class="al">Redoslijed</label><input class="ainput" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>"></div>
        <div class="full"><label class="al">SEO opis</label><textarea class="ainput" name="seo_description" rows="2" maxlength="300"><?= e($edit['seo_description'] ?? '') ?></textarea></div>
      </div>
      <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px">
        <label class="acheck"><input type="checkbox" name="is_visible" <?= ($edit['is_visible'] ?? 1) ? 'checked' : '' ?>> Vidljiva</label>
        <label class="acheck"><input type="checkbox" name="in_nav" <?= ($edit['in_nav'] ?? 0) ? 'checked' : '' ?>> U glavnoj navigaciji</label>
        <label class="acheck"><input type="checkbox" name="in_footer" <?= ($edit['in_footer'] ?? 1) ? 'checked' : '' ?>> U podnožju</label>
      </div>
      <div style="display:flex;gap:10px;margin-top:16px">
        <button class="abtn">💾 Spremi</button>
        <?php if ($edit): ?><a class="abtn ghost" target="_blank" href="<?= e(url('s/' . $edit['slug'])) ?>">Pogledaj ↗</a><?php endif; ?>
      </div>
    </form>
    <?php if ($edit): ?>
      <form method="post" style="margin-top:12px" onsubmit="return confirm('Obrisati stranicu?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <button class="abtn danger sm">Obriši stranicu</button>
      </form>
    <?php endif; ?>
  </div>
  <?php else: ?>
    <div class="acard"><p style="color:#8b90a0;margin:0">← Odaberite stranicu za uređivanje ili kreirajte novu.</p></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
