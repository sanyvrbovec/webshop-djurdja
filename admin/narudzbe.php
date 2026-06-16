<?php
require_once __DIR__ . '/templates/init.php';

$status = (string) ($_GET['status'] ?? '');
$pay = (string) ($_GET['pay'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;

$where = '1=1'; $params = [];
if ($status !== '' && in_array($status, ['pending','confirmed','processing','shipped','delivered','cancelled','refunded'], true)) {
    $where .= ' AND status = :st'; $params[':st'] = $status;
}
if ($pay !== '' && in_array($pay, ['pending','paid','failed','refunded'], true)) {
    $where .= ' AND payment_status = :ps'; $params[':ps'] = $pay;
}
if ($q !== '') {
    $where .= ' AND (order_number LIKE :q1 OR customer_name LIKE :q2 OR customer_email LIKE :q3)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
$total = (int) $db->fetchColumn("SELECT COUNT(*) FROM orders WHERE $where", $params);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$orders = $db->fetchAll("SELECT * FROM orders WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);

$pageTitle = 'Narudžbe';
require __DIR__ . '/templates/header.php';
?>
<div class="acard">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
    <input class="ainput" style="max-width:240px" name="q" value="<?= e($q) ?>" placeholder="Broj, ime ili e-mail…">
    <select class="ainput" style="max-width:180px" name="status" onchange="this.form.submit()">
      <option value="">— Svi statusi —</option>
      <?php foreach (['pending','confirmed','processing','shipped','delivered','cancelled','refunded'] as $st): ?>
        <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= e(Orders::statusLabel($st)) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="ainput" style="max-width:170px" name="pay" onchange="this.form.submit()">
      <option value="">— Plaćanje —</option>
      <option value="pending" <?= $pay === 'pending' ? 'selected' : '' ?>>Čeka uplatu</option>
      <option value="paid" <?= $pay === 'paid' ? 'selected' : '' ?>>Plaćeno</option>
      <option value="failed" <?= $pay === 'failed' ? 'selected' : '' ?>>Neuspjelo</option>
    </select>
    <button class="abtn sm">Traži</button>
    <span style="margin-left:auto;color:#8b90a0;font-size:13px;align-self:center"><?= $total ?> narudžbi</span>
  </form>

  <table class="atable">
    <thead><tr><th>Broj</th><th>Datum</th><th>Kupac</th><th>Način</th><th>Status</th><th>Plaćanje</th><th>Račun</th><th class="num">Iznos</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td><a href="<?= e(adminUrl('narudzba.php?id=' . $o['id'])) ?>"><strong><?= e($o['order_number']) ?></strong></a></td>
        <td style="white-space:nowrap"><?= date('d.m.Y H:i', strtotime($o['created_at'])) ?></td>
        <td><?= e($o['customer_name']) ?><br><small style="color:#9ca3af"><?= e($o['customer_email']) ?></small></td>
        <td><?= e(Orders::paymentLabel($o['payment_method'])) ?></td>
        <td><span class="badge <?= $o['status'] === 'delivered' ? 'green' : (in_array($o['status'], ['cancelled','refunded']) ? 'red' : 'blue') ?>"><?= e(Orders::statusLabel($o['status'])) ?></span>
          <?php if ($o['withdrawal_requested_at']): ?><br><span class="badge red" title="Kupac zatražio jednostrani raskid ugovora">⚠ RASKID</span><?php endif; ?></td>
        <td><span class="badge <?= $o['payment_status'] === 'paid' ? 'green' : ($o['payment_status'] === 'failed' ? 'red' : 'amber') ?>"><?= $o['payment_status'] === 'paid' ? 'Plaćeno' : ($o['payment_status'] === 'failed' ? 'Neuspjelo' : 'Čeka') ?></span></td>
        <td><?php $map = ['fiscalized'=>['green','Fiskaliziran'],'pending_retry'=>['amber','Retry'],'failed'=>['red','Greška'],'failed_expired'=>['red','Isteklo!'],'stornoed'=>['gray','Storno'],'none'=>['gray','—'],'pending'=>['amber','U tijeku']]; [$bc,$bt] = $map[$o['fiscal_status']] ?? ['gray',$o['fiscal_status']]; ?>
          <span class="badge <?= $bc ?>"><?= e($bt) ?></span></td>
        <td class="num"><strong><?= fmt_price($o['total']) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:30px">Nema narudžbi za zadane filtere.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <?php $qsArr = array_merge($_GET, ['page' => $i]); ?>
        <?php if ($i === $page): ?><span class="cur"><?= $i ?></span>
        <?php else: ?><a href="?<?= e(http_build_query($qsArr)) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
