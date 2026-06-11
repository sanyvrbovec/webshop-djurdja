<?php
/**
 * Đurđa veza — srce ovisnosti shopa: API ključ, podaci firme (read-only),
 * plan/kvota, fiskalne postavke. Bez valjane veze checkout ne radi.
 */
require_once __DIR__ . '/templates/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'refresh') {
        $ok = Djurdja::refresh(true);
        flash($ok ? 'success' : 'error', $ok ? 'Podaci osvježeni iz đurđe.' : ('Osvježavanje nije uspjelo: ' . s('djurdja_last_error', 'nepoznata greška')));
    } elseif ($action === 'test') {
        try {
            $client = DjurdjaClient::fromSettings();
            $h = $client ? $client->health() : null;
            flash($h ? 'success' : 'error', $h ? ('Veza OK — đurđa API v' . ($h['version'] ?? '?')) : 'Klijent nije konfiguriran.');
        } catch (Throwable $e) {
            flash('error', 'Test veze: ' . $e->getMessage());
        }
    } elseif ($action === 'key') {
        $keyId = trim((string) $_POST['key_id']);
        $secret = trim((string) $_POST['secret']);
        if (strpos($keyId, 'pk_') !== 0 || strpos($secret, 'sk_') !== 0) {
            flash('error', 'Key ID mora počinjati s pk_, a Secret s sk_.');
        } else {
            try {
                $client = new DjurdjaClient(['api_base' => s('djurdja_api_base', 'https://mojadjurdja.com/api/v1'), 'key_id' => $keyId, 'secret' => $secret]);
                $me = $client->me();
                Settings::set('djurdja_key_id', $keyId);
                Settings::set('djurdja_secret_enc', Crypto::encrypt($secret));
                Settings::setJson('djurdja_company', $me);
                Settings::set('djurdja_key_invalid', '0');
                Settings::set('djurdja_last_ok_at', date('Y-m-d H:i:s'));
                Djurdja::refresh(true);
                flash('success', 'Novi ključ spremljen i provjeren: ' . ($me['companyName'] ?? '?'));
            } catch (Throwable $e) {
                flash('error', 'Ključ nije prihvaćen: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'fiscal') {
        Settings::set('fiscal_enabled', !empty($_POST['fiscal_enabled']) ? '1' : '0');
        Settings::set('force_test_mode', !empty($_POST['force_test_mode']) ? '1' : '0');
        Settings::set('business_space', mb_substr(trim((string) $_POST['business_space']) ?: 'WEBSHOP', 0, 20));
        Settings::set('cash_register', mb_substr(trim((string) $_POST['cash_register']) ?: '1', 0, 20));
        Settings::set('shipping_vat_rate', (string) max(0, min(25, (float) $_POST['shipping_vat_rate'])));
        $map = [];
        foreach (['cod', 'stripe', 'bank_transfer'] as $code) {
            $v = (string) ($_POST["map_$code"] ?? '');
            if (in_array($v, ['G', 'K', 'T', 'C', 'O'], true)) $map[$code] = $v;
        }
        Settings::setJson('fiscal_payment_mapping', $map);
        flash('success', 'Fiskalne postavke spremljene.');
    }
    redirect('admin/djurdja.php');
}

$company = Djurdja::company();
$account = Djurdja::account();
$quota = Djurdja::quota();
$status = Djurdja::status();
$client = DjurdjaClient::fromSettings();
$mapping = Settings::getJson('fiscal_payment_mapping');
$mockMode = defined('DJURDJA_MOCK') && DJURDJA_MOCK;

$statusInfo = [
    'connected' => ['green', 'Povezano', 'Sve radi normalno.'],
    'stale'     => ['amber', 'Zastarjelo', 'Zadnji kontakt prije više od 26 h — provjerite vezu.'],
    'offline'   => ['red', 'Offline', 'Bez kontakta više od 72 h — checkout je BLOKIRAN dok se veza ne obnovi.'],
    'locked'    => ['red', 'Blokirano', 'API ključ je povučen ili je trgovina suspendirana. Unesite valjani ključ.'],
][$status];

$pageTitle = 'Đurđa veza';
require __DIR__ . '/templates/header.php';
?>
<?php if ($mockMode): ?><div class="alert alert-warning">⚠ <strong>MOCK NAČIN</strong> — đurđa API se simulira lokalno (DJURDJA_MOCK=true u config.php). Za produkciju isključite mock i unesite pravi ključ.</div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
  <div style="display:grid;gap:20px">
    <div class="acard">
      <h3>Status veze <span class="badge <?= $statusInfo[0] ?>">● <?= e($statusInfo[1]) ?></span></h3>
      <p class="sub" style="margin:0 0 12px"><?= e($statusInfo[2]) ?></p>
      <table class="atable" style="font-size:13px">
        <tr><td>Zadnji uspješan kontakt</td><td><strong><?= e(s('djurdja_last_ok_at', '—')) ?></strong></td></tr>
        <tr><td>API adresa</td><td><code><?= e(s('djurdja_api_base', 'https://mojadjurdja.com/api/v1')) ?></code></td></tr>
        <tr><td>Ključ</td><td><code><?= e(s('djurdja_key_id', '—')) ?></code> (<?= $client && $client->mode() === 'live' ? 'LIVE' : 'TEST' ?>)</td></tr>
        <?php if (s('djurdja_last_error')): ?><tr><td>Zadnja greška</td><td style="color:#b91c1c"><?= e(s('djurdja_last_error')) ?></td></tr><?php endif; ?>
      </table>
      <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><button class="abtn sm">⟳ Osvježi podatke</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="abtn ghost sm">Test veze</button></form>
      </div>
    </div>

    <div class="acard">
      <h3>Firma <span class="badge violet">izvor: đurđa</span></h3>
      <p class="sub">Podaci o firmi se NE mogu mijenjati u trgovini — uređuju se u sustavu MojaĐurđa i automatski sinkroniziraju.</p>
      <table class="atable" style="font-size:13px">
        <tr><td>Naziv</td><td><strong><?= e($company['companyName'] ?? '—') ?></strong></td></tr>
        <tr><td>OIB</td><td><?= e($company['companyOib'] ?? '—') ?></td></tr>
        <tr><td>PDV</td><td><?= !empty($company['inVatSystem']) ? '✓ U sustavu PDV-a' : '✗ Nije u sustavu PDV-a' ?></td></tr>
        <tr><td>FINA certifikat</td><td><?= !empty($company['hasCertificate']) ? '✓ Postavljen' : '✗ Nedostaje (fiskalizacija neće raditi!)' ?></td></tr>
      </table>
    </div>

    <div class="acard">
      <h3>Paket: <?= e(Djurdja::planName()) ?></h3>
      <?php if ($quota): $pct = min(100, (int) round((int) $quota['used'] / max(1, (int) $quota['limit']) * 100)); ?>
        <div class="quota-bar <?= $pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : '') ?>"><div style="width:<?= $pct ?>%"></div></div>
        <p style="font-size:13px;margin:4px 0 10px"><strong><?= (int) $quota['used'] ?> / <?= (int) $quota['limit'] ?></strong> dokumenata ovaj mjesec (do <?= e(date('d.m.Y', strtotime($quota['periodEnd'] ?? 'now'))) ?>)</p>
      <?php else: ?>
        <p class="sub">Kvota nije dostupna / neograničeno.</p>
      <?php endif; ?>
      <?php if (Djurdja::brandingRequired()): ?><p style="font-size:12.5px;color:#6b7280;margin:0 0 10px">Besplatni plan uključuje "Pokreće MojaĐurđa" link u podnožju trgovine.</p><?php endif; ?>
      <a class="abtn sm" target="_blank" href="https://mojadjurdja.com/cjenik?utm_source=webshop&utm_medium=admin&utm_campaign=connection">Nadogradi paket ↗</a>
    </div>
  </div>

  <div style="display:grid;gap:20px">
    <div class="acard">
      <h3>API ključ</h3>
      <p class="sub">Novi ključ kreirate u <strong>MojaĐurđa → Postavke → API pristup</strong>. Tajna se sprema enkriptirano (AES-256-GCM).</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="key">
        <label class="al">Key ID</label>
        <input class="ainput" name="key_id" placeholder="pk_live_…" required>
        <label class="al">Secret</label>
        <input class="ainput" type="password" name="secret" placeholder="sk_…" required>
        <button class="abtn" style="margin-top:14px">Provjeri i spremi ključ</button>
      </form>
    </div>

    <div class="acard">
      <h3>Fiskalizacija</h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="fiscal">
        <label class="acheck"><input type="checkbox" name="fiscal_enabled" <?= s('fiscal_enabled', '1') === '1' ? 'checked' : '' ?>> Fiskalizacija uključena</label>
        <label class="acheck"><input type="checkbox" name="force_test_mode" <?= s('force_test_mode') === '1' ? 'checked' : '' ?>> Prisili TEST mod (razvoj — računi ne idu u pravu Poreznu)</label>
        <div class="aform-grid">
          <div><label class="al">Poslovni prostor</label><input class="ainput" name="business_space" value="<?= e(s('business_space', 'WEBSHOP')) ?>"></div>
          <div><label class="al">Naplatni uređaj</label><input class="ainput" name="cash_register" value="<?= e(s('cash_register', '1')) ?>"></div>
          <div><label class="al">PDV na dostavu (%)</label><input class="ainput" type="number" step="0.01" name="shipping_vat_rate" value="<?= e(s('shipping_vat_rate', '25')) ?>"></div>
        </div>
        <p class="sub" style="margin-top:12px">Oznaka načina plaćanja prema Poreznoj (G=gotovina, K=kartica, T=transakcijski, O=ostalo):</p>
        <div class="aform-grid">
          <?php
          $defaults = ['cod' => 'G', 'stripe' => 'K', 'bank_transfer' => 'T'];
          foreach (['cod' => 'Pouzeće', 'stripe' => 'Kartice', 'bank_transfer' => 'Virman'] as $code => $label): ?>
            <div><label class="al"><?= e($label) ?></label>
              <select class="ainput" name="map_<?= $code ?>">
                <?php foreach (['G', 'K', 'T', 'C', 'O'] as $f): ?>
                  <option value="<?= $f ?>" <?= ($mapping[$code] ?? $defaults[$code]) === $f ? 'selected' : '' ?>><?= $f ?></option>
                <?php endforeach; ?>
              </select></div>
          <?php endforeach; ?>
        </div>
        <button class="abtn" style="margin-top:14px">💾 Spremi fiskalne postavke</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
