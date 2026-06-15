<?php
/** Moderacija recenzija proizvoda — odobri / odbij / obriši. */
require_once __DIR__ . '/templates/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id && in_array($action, ['approve', 'reject', 'delete'], true)) {
        if ($action === 'delete') {
            $db->query('DELETE FROM product_reviews WHERE id = :id', [':id' => $id]);
            flash('success', 'Recenzija obrisana.');
        } else {
            $db->update('product_reviews', ['status' => $action === 'approve' ? 'approved' : 'rejected'], 'id = :id', [':id' => $id]);
            flash('success', $action === 'approve' ? 'Recenzija odobrena — sad je javno vidljiva.' : 'Recenzija odbijena.');
        }
    }
    redirect('admin/recenzije.php' . (!empty($_POST['f']) ? '?f=' . urlencode((string) $_POST['f']) : ''));
}

$f = (string) ($_GET['f'] ?? 'pending');
$where = in_array($f, ['pending', 'approved', 'rejected'], true) ? 'WHERE r.status = :s' : '';
$params = $where ? [':s' => $f] : [];
$reviews = $db->fetchAll(
    "SELECT r.*, p.name AS product_name, p.slug AS product_slug
       FROM product_reviews r JOIN products p ON p.id = r.product_id
       $where ORDER BY r.id DESC LIMIT 200",
    $params
);
$cmap = [];
foreach ($db->fetchAll('SELECT status, COUNT(*) c FROM product_reviews GROUP BY status') as $c) {
    $cmap[$c['status']] = (int) $c['c'];
}

$pageTitle = 'Recenzije';
require __DIR__ . '/templates/header.php';
?>
<div class="acard">
  <h3>⭐ Recenzije proizvoda</h3>
  <p class="sub" style="margin:0 0 12px">Nove recenzije čekaju odobrenje — javno se prikazuju tek kad ih odobrite.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <?php foreach (['pending' => 'Na čekanju', 'approved' => 'Odobrene', 'rejected' => 'Odbijene', 'all' => 'Sve'] as $k => $lbl): ?>
      <a class="abtn ghost sm" href="<?= e(adminUrl('recenzije.php?f=' . $k)) ?>"<?= $f === $k ? ' style="background:#7c3aed;color:#fff;border-color:#7c3aed"' : '' ?>><?= e($lbl) ?><?= ($k !== 'all' && isset($cmap[$k])) ? ' (' . $cmap[$k] . ')' : '' ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$reviews): ?>
    <p style="color:#8b90a0">Nema recenzija u ovom filteru.</p>
  <?php else: ?>
    <table class="atable">
      <thead><tr><th>Proizvod</th><th>Ocjena</th><th>Autor</th><th>Komentar</th><th>Status</th><th>Datum</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($reviews as $r): ?>
        <tr>
          <td><a href="<?= e(url('p/' . $r['product_slug'])) ?>" target="_blank" rel="noopener"><?= e($r['product_name']) ?></a></td>
          <td style="white-space:nowrap;color:#f59e0b;font-size:13px"><?= str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating']) ?></td>
          <td><?= e($r['author_name']) ?><?= $r['verified'] ? ' <span class="badge green">kupljeno</span>' : '' ?></td>
          <td style="max-width:280px;font-size:12.5px;color:#4b5563"><?= nl2br(e(mb_substr((string) $r['comment'], 0, 240))) ?></td>
          <td><span class="badge <?= $r['status'] === 'approved' ? 'green' : ($r['status'] === 'rejected' ? 'red' : 'amber') ?>"><?= e($r['status']) ?></span></td>
          <td style="white-space:nowrap;font-size:12px"><?= date('d.m.Y', strtotime($r['created_at'])) ?></td>
          <td style="white-space:nowrap">
            <?php if ($r['status'] !== 'approved'): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><button class="abtn ok sm" name="action" value="approve" title="Odobri">✓</button></form><?php endif; ?>
            <?php if ($r['status'] !== 'rejected'): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><button class="abtn ghost sm" name="action" value="reject">Odbij</button></form><?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Trajno obrisati recenziju?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><button class="abtn danger sm" name="action" value="delete" title="Obriši">🗑</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
