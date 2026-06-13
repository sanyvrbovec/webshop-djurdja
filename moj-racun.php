<?php
/** Moj račun — profil kupca: narudžbe, fiskalizirani računi, podaci, odjava. */
require_once __DIR__ . '/core/bootstrap.php';

$customer = Customer::current();
if (!$customer) redirect('prijava.php?next=moj-racun.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'logout') {
        Customer::logout();
        flash('success', 'Odjavljeni ste. Vidimo se! 👋');
        redirect('');
    } elseif ($action === 'profile') {
        $db->update('customers', [
            'name'        => mb_substr(trim((string) $_POST['name']), 0, 200) ?: $customer['name'],
            'phone'       => mb_substr(trim((string) $_POST['phone']), 0, 40) ?: null,
            'address'     => mb_substr(trim((string) $_POST['address']), 0, 255) ?: null,
            'city'        => mb_substr(trim((string) $_POST['city']), 0, 100) ?: null,
            'postal_code' => mb_substr(trim((string) $_POST['postal_code']), 0, 20) ?: null,
        ], 'id = :id', [':id' => $customer['id']]);
        flash('success', 'Podaci spremljeni — popunit će se sami pri sljedećoj kupnji.');
        redirect('moj-racun.php');
    } elseif ($action === 'password') {
        if (!password_verify((string) $_POST['current'], $customer['password_hash'])) {
            flash('error', 'Trenutna lozinka nije ispravna.');
        } elseif (strlen((string) $_POST['new']) < 8) {
            flash('error', 'Nova lozinka mora imati najmanje 8 znakova.');
        } else {
            $db->update('customers', ['password_hash' => password_hash((string) $_POST['new'], PASSWORD_DEFAULT)], 'id = :id', [':id' => $customer['id']]);
            flash('success', 'Lozinka promijenjena.');
        }
        redirect('moj-racun.php');
    }
}

$orders = $db->fetchAll(
    'SELECT * FROM orders WHERE customer_id = :c ORDER BY id DESC LIMIT 50',
    [':c' => $customer['id']]
);

$pageTitle = 'Moj račun';
$pageDesc = 'Pregled narudžbi i računa — ' . shop_name();
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="section-head" style="margin-top:26px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h1 class="section-title">Pozdrav, <?= e(explode(' ', trim($customer['name']))[0]) ?>! 👋</h1>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="btn btn-ghost btn-sm">Odjava</button></form>
  </div>

  <div class="checkout-grid">
    <div class="card" style="padding:18px 22px">
      <h3>Moje narudžbe i računi</h3>
      <?php if (!$orders): ?>
        <div class="alert alert-info">Još nemate narudžbi. <a href="<?= e(url('proizvodi.php')) ?>">Razgledajte ponudu →</a></div>
      <?php else: ?>
        <table class="cart-table">
          <thead><tr><th>Narudžba</th><th>Datum</th><th>Status</th><th>Iznos</th><th>Račun</th></tr></thead>
          <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td><a href="<?= e(url('narudzba-potvrda.php?t=' . urlencode($o['guest_token']))) ?>"><strong><?= e($o['order_number']) ?></strong></a></td>
              <td style="white-space:nowrap"><?= date('d.m.Y', strtotime($o['created_at'])) ?></td>
              <td><?= e(Orders::statusLabel($o['status'])) ?><?= $o['withdrawal_requested_at'] ? '<br><small style="color:#b45309">zatražen raskid</small>' : '' ?></td>
              <td><strong><?= fmt_price($o['total']) ?></strong></td>
              <td>
                <?php if ($o['fiscal_status'] === 'fiscalized' || $o['fiscal_status'] === 'stornoed'): ?>
                  <a href="<?= e(url('racun.php?id=' . (int) $o['id'])) ?>" target="_blank" style="font-size:12.5px">🧾 <?= e($o['fiscal_receipt_number']) ?><?= $o['fiscal_status'] === 'stornoed' ? ' (stornirano)' : '' ?></a>
                <?php else: ?><span style="color:var(--c-muted);font-size:12.5px">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p style="font-size:12.5px;color:var(--c-muted);margin:12px 0 0">Klik na broj narudžbe otvara detalje, status i fiskalne podatke računa (JIR/ZKI).</p>
      <?php endif; ?>
    </div>

    <div style="display:grid;gap:20px;align-content:start">
      <div class="card">
        <h3>Moji podaci</h3>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="profile">
          <div class="form-grid">
            <div class="full"><label class="f-label">Ime i prezime</label><input class="f-input" name="name" value="<?= e($customer['name']) ?>" maxlength="200"></div>
            <div class="full"><label class="f-label">Telefon</label><input class="f-input" name="phone" value="<?= e($customer['phone'] ?? '') ?>" maxlength="40"></div>
            <div class="full"><label class="f-label">Adresa</label><input class="f-input" name="address" value="<?= e($customer['address'] ?? '') ?>" maxlength="255"></div>
            <div><label class="f-label">Grad</label><input class="f-input" name="city" value="<?= e($customer['city'] ?? '') ?>" maxlength="100"></div>
            <div><label class="f-label">Poštanski broj</label><input class="f-input" name="postal_code" value="<?= e($customer['postal_code'] ?? '') ?>" maxlength="20"></div>
          </div>
          <button class="btn btn-sm" style="margin-top:14px;width:100%">💾 Spremi podatke</button>
        </form>
      </div>

      <div class="card">
        <h3>Promjena lozinke</h3>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="form-grid">
            <div class="full"><label class="f-label">Trenutna lozinka</label><input class="f-input" type="password" name="current" required autocomplete="current-password"></div>
            <div class="full"><label class="f-label">Nova lozinka (min 8)</label><input class="f-input" type="password" name="new" required minlength="8" autocomplete="new-password"></div>
          </div>
          <button class="btn btn-ghost btn-sm" style="margin-top:14px;width:100%">Promijeni lozinku</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
