<?php
require_once __DIR__ . '/templates/init.php';

$id = (int) ($_GET['id'] ?? 0);
$order = $db->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
if (!$order) { flash('error', 'Narudžba nije pronađena.'); redirect('admin/narudzbe.php'); }

// ── Akcije ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'status') {
        Orders::setStatus($id, (string) $_POST['status']);
        flash('success', 'Status ažuriran.');
    } elseif ($action === 'mark_paid') {
        $res = Orders::markPaid($id, 'admin-' . $currentAdmin['id']);
        if (!empty($res['fiscal']['success'])) {
            flash('success', 'Označeno plaćenim i fiskalizirano (račun ' . ($res['fiscal']['receiptNumber'] ?? '') . ').');
        } elseif (isset($res['fiscal'])) {
            flash('warning', 'Označeno plaćenim, fiskalizacija: ' . ($res['fiscal']['error'] ?? 'u tijeku') . '');
        } else {
            flash('success', 'Označeno plaćenim.');
        }
    } elseif ($action === 'fiscalize') {
        $res = Fiscalizer::fiscalizeOrder($db, $id);
        flash($res['success'] ? 'success' : 'error', $res['success']
            ? 'Fiskalizirano — račun ' . ($res['receiptNumber'] ?? '')
            : 'Fiskalizacija: ' . ($res['error'] ?? 'greška'));
    } elseif ($action === 'storno') {
        $res = Fiscalizer::stornoOrder($db, $id, trim((string) ($_POST['reason'] ?? 'Povrat')));
        flash($res['success'] ? 'success' : 'error', $res['success']
            ? 'Storniran — storno račun ' . ($res['stornoReceiptNumber'] ?? '')
            : 'Storno: ' . ($res['error'] ?? 'greška'));
    } elseif ($action === 'cancel') {
        Orders::cancel($id);
        flash('success', 'Narudžba otkazana, zaliha vraćena.');
    } elseif ($action === 'note') {
        $db->update('orders', ['admin_note' => mb_substr((string) $_POST['admin_note'], 0, 2000)], 'id = :id', [':id' => $id]);
        flash('success', 'Bilješka spremljena.');
    }
    redirect('admin/narudzba.php?id=' . $id);
}

$order = $db->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
$items = $db->fetchAll('SELECT * FROM order_items WHERE order_id = :o', [':o' => $id]);
$flog = $db->fetchAll('SELECT * FROM fiscal_log WHERE order_id = :o ORDER BY id DESC LIMIT 12', [':o' => $id]);

$pageTitle = 'Narudžba ' . $order['order_number'];
require __DIR__ . '/templates/header.php';
?>
<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start">
  <div style="display:grid;gap:20px">
    <div class="acard">
      <h3>Stavke</h3>
      <table class="atable">
        <thead><tr><th>Artikl</th><th class="num">Cijena</th><th class="num">Kol.</th><th class="num">PDV</th><th class="num">Ukupno</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['name']) ?></td>
            <td class="num"><?= fmt_price($it['unit_price']) ?></td>
            <td class="num"><?= (int) $it['quantity'] ?></td>
            <td class="num"><?= rtrim(rtrim(number_format((float) $it['vat_rate'], 2, ',', ''), '0'), ',') ?>%</td>
            <td class="num"><strong><?= fmt_price($it['total']) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        <tr><td colspan="4" class="num">Dostava</td><td class="num"><?= fmt_price($order['shipping_cost']) ?></td></tr>
        <?php if ((float) $order['payment_fee'] > 0): ?><tr><td colspan="4" class="num">Naknada plaćanja</td><td class="num"><?= fmt_price($order['payment_fee']) ?></td></tr><?php endif; ?>
        <tr><td colspan="4" class="num" style="font-size:15px"><strong>UKUPNO</strong></td><td class="num" style="font-size:15px"><strong><?= fmt_price($order['total']) ?></strong></td></tr>
        </tbody>
      </table>
    </div>

    <div class="acard">
      <h3>🧾 Fiskalizacija</h3>
      <?php $fs = $order['fiscal_status']; ?>
      <?php if ($fs === 'fiscalized'): ?>
        <div class="alert alert-success">Račun <strong><?= e($order['fiscal_receipt_number']) ?></strong> fiskaliziran <?= e($order['fiscalized_at']) ?> (<?= e($order['fiscal_mode']) ?>)<br>
        JIR: <code><?= e($order['fiscal_jir']) ?></code><br>ZKI: <code><?= e($order['fiscal_zki']) ?></code></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="abtn sm" target="_blank" href="<?= e(adminUrl('racun.php?id=' . $id)) ?>">🖨 Ispiši račun</a>
          <form method="post" onsubmit="return confirm('Stornirati račun? Ova radnja je trajna i šalje storno u Poreznu upravu.')">
            <?= csrf_field() ?><input type="hidden" name="action" value="storno">
            <input type="hidden" name="reason" value="Povrat robe">
            <button class="abtn danger sm">Storniraj račun</button>
          </form>
        </div>
      <?php elseif ($fs === 'stornoed'): ?>
        <div class="alert alert-info">Račun storniran — storno broj <strong><?= e($order['fiscal_storno_receipt_number']) ?></strong>, JIR <code><?= e($order['fiscal_storno_jir']) ?></code></div>
        <a class="abtn ghost sm" target="_blank" href="<?= e(adminUrl('racun.php?id=' . $id)) ?>">🖨 Pregled računa</a>
      <?php elseif ($fs === 'pending_retry'): ?>
        <div class="alert alert-warning">Čeka automatski retry (<?= e($order['fiscal_next_retry_at']) ?>) — pokušaj #<?= (int) $order['fiscal_attempts'] ?>.<br>Zadnja greška: <?= e($order['fiscal_error']) ?></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fiscalize"><button class="abtn sm">Pokušaj odmah</button></form>
      <?php elseif ($fs === 'failed' || $fs === 'failed_expired'): ?>
        <div class="alert alert-error"><?= $fs === 'failed_expired' ? '⚠ 48-satni zakonski rok je istekao! Kontaktirajte knjigovođu.' : 'Greška: ' . e($order['fiscal_error']) ?></div>
        <?php if ($fs === 'failed' && $order['payment_status'] === 'paid'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fiscalize"><button class="abtn sm">Pokušaj ponovno</button></form>
        <?php endif; ?>
      <?php else: ?>
        <p style="color:#8b90a0;font-size:13px;margin:0 0 10px">Račun još nije fiskaliziran. Fiskalizacija je moguća tek kad je narudžba plaćena.</p>
        <?php if ($order['payment_status'] === 'paid'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fiscalize"><button class="abtn sm">Fiskaliziraj sada</button></form>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($flog): ?>
        <details style="margin-top:14px"><summary style="cursor:pointer;font-size:13px;color:#6b7280">Fiskalni log (<?= count($flog) ?>)</summary>
          <table class="atable" style="margin-top:8px;font-size:12.5px">
            <?php foreach ($flog as $l): ?>
              <tr><td style="white-space:nowrap"><?= e($l['created_at']) ?></td><td><span class="badge <?= $l['action'] === 'error' ? 'red' : 'gray' ?>"><?= e($l['action']) ?></span></td>
              <td><?= e($l['receipt_number'] ?? '') ?> <?= e(mb_substr($l['error_message'] ?? '', 0, 80)) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </details>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:grid;gap:20px">
    <div class="acard">
      <h3>Kupac</h3>
      <p style="margin:0;line-height:1.9;font-size:13.5px">
        <strong><?= e($order['customer_name']) ?></strong><br>
        <a href="mailto:<?= e($order['customer_email']) ?>"><?= e($order['customer_email']) ?></a><br>
        <?= e($order['customer_phone'] ?? '—') ?><br>
        <?= e($order['address']) ?><br><?= e($order['postal_code']) ?> <?= e($order['city']) ?>
      </p>
      <?php if ($order['note']): ?><div class="alert alert-info" style="margin-top:10px;font-size:12.5px"><strong>Napomena kupca:</strong> <?= e($order['note']) ?></div><?php endif; ?>
    </div>

    <div class="acard">
      <h3>Status i plaćanje</h3>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
        <span class="badge blue"><?= e(Orders::statusLabel($order['status'])) ?></span>
        <span class="badge <?= $order['payment_status'] === 'paid' ? 'green' : 'amber' ?>"><?= e(Orders::paymentLabel($order['payment_method'])) ?> · <?= $order['payment_status'] === 'paid' ? 'plaćeno' : 'čeka' ?></span>
      </div>
      <form method="post" style="display:flex;gap:8px">
        <?= csrf_field() ?><input type="hidden" name="action" value="status">
        <select class="ainput" name="status">
          <?php foreach (['pending','confirmed','processing','shipped','delivered','refunded'] as $st): ?>
            <option value="<?= $st ?>" <?= $order['status'] === $st ? 'selected' : '' ?>><?= e(Orders::statusLabel($st)) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="abtn sm">Spremi</button>
      </form>
      <?php if ($order['payment_status'] !== 'paid' && !in_array($order['status'], ['cancelled', 'refunded'], true)): ?>
        <form method="post" style="margin-top:10px" onsubmit="return confirm('Označiti narudžbu plaćenom? Time se pokreće fiskalizacija računa.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="mark_paid">
          <button class="abtn ok sm" style="width:100%">✓ Označi plaćenim (+ fiskaliziraj)</button>
        </form>
      <?php endif; ?>
      <?php if (!in_array($order['status'], ['cancelled', 'refunded', 'delivered'], true)): ?>
        <form method="post" style="margin-top:8px" onsubmit="return confirm('Otkazati narudžbu? Zaliha se vraća.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
          <button class="abtn danger sm" style="width:100%">Otkaži narudžbu</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="acard">
      <h3>Interna bilješka</h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="note">
        <textarea class="ainput" name="admin_note" rows="3"><?= e($order['admin_note'] ?? '') ?></textarea>
        <button class="abtn ghost sm" style="margin-top:8px">Spremi bilješku</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
