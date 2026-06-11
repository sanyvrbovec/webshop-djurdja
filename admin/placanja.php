<?php
/** Načini plaćanja + dostava. Stripe tajne se spremaju enkriptirane. */
require_once __DIR__ . '/templates/init.php';

$pm = new PaymentManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'shipping') {
        Settings::set('shipping_flat', number_format(max(0, (float) $_POST['shipping_flat']), 2, '.', ''));
        Settings::set('shipping_free_over', number_format(max(0, (float) $_POST['shipping_free_over']), 2, '.', ''));
        flash('success', 'Dostava spremljena.');
    } elseif ($action === 'method') {
        $code = (string) $_POST['code'];
        $m = $pm->getMethod($code);
        if ($m) {
            $cfg = $m['config'];
            if ($code === 'bank_transfer') {
                $cfg['iban'] = strtoupper(preg_replace('/\s+/', '', (string) $_POST['iban']));
                $cfg['recipient'] = mb_substr(trim((string) $_POST['recipient']), 0, 200);
                $cfg['model'] = mb_substr(trim((string) $_POST['model']) ?: 'HR00', 0, 10);
                $cfg['instructions'] = mb_substr(trim((string) $_POST['instructions']), 0, 500);
            } elseif ($code === 'stripe') {
                $cfg['publishable_key'] = trim((string) $_POST['publishable_key']);
                if (trim((string) $_POST['secret_key']) !== '') {
                    $cfg['secret_key_enc'] = Crypto::encrypt(trim((string) $_POST['secret_key']));
                }
                if (trim((string) $_POST['webhook_secret']) !== '') {
                    $cfg['webhook_secret_enc'] = Crypto::encrypt(trim((string) $_POST['webhook_secret']));
                }
                $cfg['sandbox'] = !empty($_POST['sandbox']);
            } elseif ($code === 'cod') {
                $cfg['instructions'] = mb_substr(trim((string) $_POST['instructions']), 0, 500);
            }
            $db->update('payment_methods', [
                'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
                'description' => mb_substr(trim((string) $_POST['description']), 0, 500),
                'fee_type'    => in_array($_POST['fee_type'] ?? '', ['none', 'fixed', 'percent'], true) ? $_POST['fee_type'] : 'none',
                'fee_value'   => number_format(max(0, (float) $_POST['fee_value']), 2, '.', ''),
                'fiscal_auto' => !empty($_POST['fiscal_auto']) ? 1 : 0,
                'config'      => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            ], 'code = :c', [':c' => $code]);
            flash('success', strtoupper($code) . ' spremljeno.');
        }
    } elseif ($action === 'stripe_test') {
        try {
            $r = $pm->stripe()->testConnection();
            flash($r['success'] ? 'success' : 'error', $r['message']);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }
    redirect('admin/placanja.php');
}

$methods = $pm->getAllMethods();
$pageTitle = 'Plaćanja i dostava';
require __DIR__ . '/templates/header.php';
?>
<div class="acard">
  <h3>🚚 Dostava</h3>
  <form method="post" style="display:flex;gap:16px;align-items:end;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="shipping">
    <div><label class="al">Cijena dostave (€)</label><input class="ainput" type="number" step="0.01" name="shipping_flat" value="<?= e(s('shipping_flat', '5.00')) ?>"></div>
    <div><label class="al">Besplatna dostava iznad (€, 0 = nikad)</label><input class="ainput" type="number" step="0.01" name="shipping_free_over" value="<?= e(s('shipping_free_over', '50.00')) ?>"></div>
    <button class="abtn">💾 Spremi</button>
  </form>
  <p class="sub" style="margin-top:10px">Narudžbe koje sadrže samo usluge nemaju trošak dostave.</p>
</div>

<?php foreach ($methods as $m): $cfg = $m['config']; ?>
<div class="acard">
  <h3><?= e($m['name']) ?>
    <span class="badge <?= $m['is_active'] ? 'green' : 'gray' ?>"><?= $m['is_active'] ? 'aktivno' : 'isključeno' ?></span>
    <?php if ($m['code'] === 'stripe' && !empty($cfg['sandbox'])): ?><span class="badge amber">sandbox</span><?php endif; ?>
  </h3>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="method"><input type="hidden" name="code" value="<?= e($m['code']) ?>">
    <div class="aform-grid">
      <div class="full"><label class="al">Opis (vidljiv kupcu na blagajni)</label><input class="ainput" name="description" value="<?= e($m['description']) ?>"></div>
      <div><label class="al">Naknada</label>
        <select class="ainput" name="fee_type">
          <option value="none" <?= $m['fee_type'] === 'none' ? 'selected' : '' ?>>Bez naknade</option>
          <option value="fixed" <?= $m['fee_type'] === 'fixed' ? 'selected' : '' ?>>Fiksno (€)</option>
          <option value="percent" <?= $m['fee_type'] === 'percent' ? 'selected' : '' ?>>Postotak (%)</option>
        </select></div>
      <div><label class="al">Iznos naknade</label><input class="ainput" type="number" step="0.01" name="fee_value" value="<?= e($m['fee_value']) ?>"></div>

      <?php if ($m['code'] === 'bank_transfer'): ?>
        <div><label class="al">IBAN</label><input class="ainput" name="iban" value="<?= e($cfg['iban'] ?? '') ?>" placeholder="HR12…"></div>
        <div><label class="al">Primatelj (prazno = naziv firme iz đurđe)</label><input class="ainput" name="recipient" value="<?= e($cfg['recipient'] ?? '') ?>"></div>
        <div><label class="al">Model</label><input class="ainput" name="model" value="<?= e($cfg['model'] ?? 'HR00') ?>"></div>
        <div class="full"><label class="al">Upute kupcu</label><input class="ainput" name="instructions" value="<?= e($cfg['instructions'] ?? '') ?>"></div>
      <?php elseif ($m['code'] === 'stripe'): ?>
        <div class="full"><label class="al">Publishable key</label><input class="ainput" name="publishable_key" value="<?= e($cfg['publishable_key'] ?? '') ?>" placeholder="pk_live_… / pk_test_…"></div>
        <div><label class="al">Secret key <?= !empty($cfg['secret_key_enc']) ? '(spremljen: ' . e(Crypto::hint($cfg['secret_key_enc'])) . ')' : '' ?></label><input class="ainput" type="password" name="secret_key" placeholder="<?= !empty($cfg['secret_key_enc']) ? 'ostavi prazno = ne mijenjaj' : 'sk_live_… / sk_test_…' ?>"></div>
        <div><label class="al">Webhook secret <?= !empty($cfg['webhook_secret_enc']) ? '(spremljen)' : '' ?></label><input class="ainput" type="password" name="webhook_secret" placeholder="whsec_…"></div>
        <div class="full"><label class="acheck"><input type="checkbox" name="sandbox" <?= !empty($cfg['sandbox']) ? 'checked' : '' ?>> Sandbox/test način (računi se fiskaliziraju u TEST modu)</label>
        <p class="sub">Webhook URL za Stripe dashboard: <code><?= e(SITE_URL . '/api/stripe-webhook.php') ?></code> (event: checkout.session.completed)</p></div>
      <?php elseif ($m['code'] === 'cod'): ?>
        <div class="full"><label class="al">Upute kupcu</label><input class="ainput" name="instructions" value="<?= e($cfg['instructions'] ?? '') ?>"></div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:18px;align-items:center;margin-top:12px;flex-wrap:wrap">
      <label class="acheck" style="margin:0"><input type="checkbox" name="is_active" <?= $m['is_active'] ? 'checked' : '' ?>> Aktivno na blagajni</label>
      <label class="acheck" style="margin:0"><input type="checkbox" name="fiscal_auto" <?= $m['fiscal_auto'] ? 'checked' : '' ?>> Automatska fiskalizacija kad je plaćeno</label>
      <button class="abtn sm" style="margin-left:auto">💾 Spremi</button>
    </div>
  </form>
  <?php if ($m['code'] === 'stripe' && !empty($cfg['secret_key_enc'])): ?>
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="stripe_test"><button class="abtn ghost sm">Test Stripe veze</button></form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
