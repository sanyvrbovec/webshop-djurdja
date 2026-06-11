<?php
require_once __DIR__ . '/templates/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    set_time_limit(280);
    $full = !empty($_POST['full']);
    $res = Sync::run($full);
    flash($res['ok'] ? 'success' : 'error', $res['ok'] ? '✓ ' . $res['message'] : 'Sinkronizacija nije uspjela: ' . $res['message']);
    redirect('admin/sync.php');
}

$logs = $db->fetchAll('SELECT * FROM sync_log ORDER BY id DESC LIMIT 12');
$counts = [
    'products' => (int) $db->fetchColumn('SELECT COUNT(*) FROM products WHERE is_orphaned = 0'),
    'visible'  => (int) $db->fetchColumn('SELECT COUNT(*) FROM products WHERE is_visible = 1 AND is_orphaned = 0'),
    'noimg'    => (int) $db->fetchColumn('SELECT COUNT(*) FROM products p WHERE p.is_orphaned = 0 AND NOT EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id)'),
    'cats'     => (int) $db->fetchColumn('SELECT COUNT(*) FROM categories'),
];

$pageTitle = 'Sinkronizacija kataloga';
require __DIR__ . '/templates/header.php';
?>
<div class="kpis">
  <div class="kpi"><div class="l">Artikala</div><div class="v"><?= $counts['products'] ?></div></div>
  <div class="kpi"><div class="l">Vidljivih u trgovini</div><div class="v"><?= $counts['visible'] ?></div></div>
  <div class="kpi <?= $counts['noimg'] > 0 ? 'warn' : '' ?>"><div class="l">Bez slike</div><div class="v"><?= $counts['noimg'] ?></div></div>
  <div class="kpi"><div class="l">Kategorija</div><div class="v"><?= $counts['cats'] ?></div></div>
</div>

<div class="acard">
  <h3>Pokreni sinkronizaciju</h3>
  <p class="sub">Povlači artikle i kategorije iz vašeg MojaĐurđa računa. Lokalna obogaćivanja (slike, opisi, SEO) se NE diraju. Zadnji sync: <strong><?= e(s('catalog_synced_at', 'nikad')) ?></strong></p>
  <form method="post" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
    <?= csrf_field() ?>
    <button class="abtn">🔄 Sinkroniziraj sada</button>
    <label class="acheck" style="margin:0"><input type="checkbox" name="full" value="1"> Puni sync (sve + označi obrisane artikle)</label>
  </form>
  <div class="alert alert-info" style="margin-top:14px;font-size:12.5px">
    💡 Automatski sync jednom dnevno: postavite hosting cron na<br>
    <code><?= e(SITE_URL . '/api/cron.php?token=' . CRON_TOKEN) ?></code> (svakih 5–15 min — radi i fiskalne retry-e).
  </div>
</div>

<div class="acard">
  <h3>Povijest</h3>
  <table class="atable">
    <thead><tr><th>Početak</th><th>Tip</th><th>Status</th><th>Rezultat</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td style="white-space:nowrap"><?= e($l['started_at']) ?></td>
        <td><?= e($l['type']) ?></td>
        <td><span class="badge <?= $l['status'] === 'done' ? 'green' : ($l['status'] === 'error' ? 'red' : 'amber') ?>"><?= e($l['status']) ?></span></td>
        <td style="font-size:12.5px"><?= e($l['message'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="4" style="text-align:center;color:#9ca3af;padding:24px">Još nije bilo sinkronizacija.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
