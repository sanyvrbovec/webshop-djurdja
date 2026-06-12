<?php
/**
 * E-mail postavke — driver (PHP mail / SMTP), pošiljatelj, test slanja
 * i vodiči za najčešće hostinge (cPanel, Gmail, Outlook). SMTP lozinka
 * se sprema enkriptirana (AES-256-GCM).
 */
require_once __DIR__ . '/templates/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $driver = ($_POST['mail_driver'] ?? 'mail') === 'smtp' ? 'smtp' : 'mail';
        Settings::setMany([
            'mail_driver'    => $driver,
            'mail_from'      => filter_var(trim((string) $_POST['mail_from']), FILTER_VALIDATE_EMAIL) ?: '',
            'mail_from_name' => mb_substr(trim((string) $_POST['mail_from_name']), 0, 100),
            'smtp_host'      => mb_substr(trim((string) $_POST['smtp_host']), 0, 190),
            'smtp_port'      => (string) max(1, min(65535, (int) ($_POST['smtp_port'] ?: 587))),
            'smtp_secure'    => in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'tls',
            'smtp_user'      => mb_substr(trim((string) $_POST['smtp_user']), 0, 190),
        ]);
        if (trim((string) $_POST['smtp_pass']) !== '') {
            Settings::set('smtp_pass_enc', Crypto::encrypt(trim((string) $_POST['smtp_pass'])));
        }
        flash('success', 'E-mail postavke spremljene.');
    } elseif ($action === 'test') {
        $to = filter_var(trim((string) $_POST['test_to']), FILTER_VALIDATE_EMAIL);
        if (!$to) {
            flash('error', 'Upišite ispravnu e-mail adresu za test.');
        } else {
            $r = Mailer::test($to);
            flash($r['ok'] ? 'success' : 'error', $r['ok']
                ? "Testna poruka poslana na $to — provjerite inbox (i spam/junk mapu!)."
                : 'Test nije uspio: ' . ($r['error'] ?: 'nepoznata greška'));
        }
    }
    redirect('admin/email.php');
}

$driver = s('mail_driver', 'mail');
$pageTitle = 'E-mail postavke';
require __DIR__ . '/templates/header.php';
?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
  <div style="display:grid;gap:20px">
    <div class="acard">
      <h3>Način slanja</h3>
      <p class="sub">Trgovina šalje potvrde narudžbi, fiskalizirane račune i obavijesti. Ako mailovi ne stižu (ili padaju u spam), prebacite se na SMTP — vodiči su desno.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="save">

        <label class="acheck"><input type="radio" name="mail_driver" value="mail" <?= $driver !== 'smtp' ? 'checked' : '' ?> onchange="document.getElementById('smtp-box').style.opacity=.45">
          <strong>PHP mail()</strong> — bez podešavanja; radi na većini hostinga (Plus, Avalon, MyDataKnox…)</label>
        <label class="acheck"><input type="radio" name="mail_driver" value="smtp" <?= $driver === 'smtp' ? 'checked' : '' ?> onchange="document.getElementById('smtp-box').style.opacity=1">
          <strong>SMTP</strong> — pouzdanije; potreban mail račun (hosting, Gmail, Outlook…)</label>

        <div class="aform-grid" style="margin-top:14px">
          <div><label class="al">Ime pošiljatelja</label><input class="ainput" name="mail_from_name" maxlength="100" value="<?= e(s('mail_from_name', '')) ?>" placeholder="<?= e(shop_name()) ?>"></div>
          <div><label class="al">E-mail pošiljatelja (From)</label><input class="ainput" type="email" name="mail_from" value="<?= e(s('mail_from', s('shop_email', ''))) ?>" placeholder="trgovina@vasa-domena.hr"></div>
        </div>

        <div id="smtp-box" style="margin-top:14px;<?= $driver === 'smtp' ? '' : 'opacity:.45' ?>">
          <h4 style="margin:0 0 8px;font-size:14px">SMTP podaci</h4>
          <div class="aform-grid">
            <div><label class="al">Server (host)</label><input class="ainput" name="smtp_host" value="<?= e(s('smtp_host', '')) ?>" placeholder="mail.vasa-domena.hr"></div>
            <div><label class="al">Port</label><input class="ainput" type="number" name="smtp_port" value="<?= e(s('smtp_port', '587')) ?>"></div>
            <div><label class="al">Enkripcija</label>
              <select class="ainput" name="smtp_secure">
                <option value="tls" <?= s('smtp_secure', 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                <option value="ssl" <?= s('smtp_secure') === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                <option value="none" <?= s('smtp_secure') === 'none' ? 'selected' : '' ?>>Bez enkripcije (port 25)</option>
              </select></div>
            <div><label class="al">Korisničko ime</label><input class="ainput" name="smtp_user" value="<?= e(s('smtp_user', '')) ?>" placeholder="trgovina@vasa-domena.hr"></div>
            <div class="full"><label class="al">Lozinka <?= s('smtp_pass_enc') ? '(spremljena — ostavite prazno da je ne mijenjate)' : '' ?></label>
              <input class="ainput" type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= s('smtp_pass_enc') ? '••••••••' : 'lozinka mail računa' ?>"></div>
          </div>
        </div>
        <button class="abtn" style="margin-top:14px">💾 Spremi postavke</button>
      </form>
    </div>

    <div class="acard">
      <h3>Test slanja</h3>
      <p class="sub">Nakon spremanja postavki obavezno pošaljite test. Ako poruka ne stigne u 2–3 minute, provjerite spam mapu.</p>
      <form method="post" style="display:flex;gap:8px">
        <?= csrf_field() ?><input type="hidden" name="action" value="test">
        <input class="ainput" type="email" name="test_to" required placeholder="vasa@adresa.hr" value="<?= e($currentAdmin['email'] ?? '') ?>">
        <button class="abtn sm">📨 Pošalji test</button>
      </form>
    </div>
  </div>

  <div class="acard">
    <h3>📖 Vodiči — odaberite svoju situaciju</h3>

    <details open>
      <summary style="cursor:pointer;font-weight:600">1) Najjednostavnije: PHP mail()</summary>
      <div style="font-size:13.5px;line-height:1.7;color:#374151;padding:8px 2px">
        <p>Odaberite "PHP mail()", u polje <em>E-mail pošiljatelja</em> upišite adresu <strong>na vašoj domeni</strong> (npr. <code>info@vasa-trgovina.hr</code> — adresu prvo kreirajte u hosting panelu) i spremite. Pošaljite test.</p>
        <p>⚠ Ako test ne stigne ili pada u spam, vaš hosting blokira mail() — prijeđite na vodič 2.</p>
      </div>
    </details>

    <details>
      <summary style="cursor:pointer;font-weight:600">2) Hosting SMTP (cPanel/Plesk — preporučeno)</summary>
      <div style="font-size:13.5px;line-height:1.7;color:#374151;padding:8px 2px">
        <p><strong>Korak 1:</strong> u hosting panel (cPanel) → <em>Email Accounts</em> → <em>Create</em>. Napravite adresu npr. <code>trgovina@vasa-domena.hr</code> i zapišite lozinku.</p>
        <p><strong>Korak 2:</strong> u cPanelu kliknite <em>Connect Devices</em> (ili "Configure Mail Client") — tamo piše <em>Outgoing Server (SMTP)</em> i port. Tipično:</p>
        <ul style="margin:4px 0 8px 18px">
          <li>Server: <code>mail.vasa-domena.hr</code></li>
          <li>Port: <code>465</code> → ovdje odaberite <strong>SSL</strong> (ili port <code>587</code> → <strong>STARTTLS</strong>)</li>
          <li>Korisničko ime: <strong>cijela</strong> adresa (<code>trgovina@vasa-domena.hr</code>)</li>
        </ul>
        <p><strong>Korak 3:</strong> ovdje odaberite SMTP, upišite te podatke, kao pošiljatelja stavite tu istu adresu, spremite i pošaljite test.</p>
      </div>
    </details>

    <details>
      <summary style="cursor:pointer;font-weight:600">3) Gmail (s "lozinkom za aplikaciju")</summary>
      <div style="font-size:13.5px;line-height:1.7;color:#374151;padding:8px 2px">
        <p>Gmail ne prima običnu lozinku iz aplikacija — treba <em>App password</em>:</p>
        <p><strong>Korak 1:</strong> na <a href="https://myaccount.google.com/security" target="_blank">myaccount.google.com/security</a> uključite <em>2-Step Verification</em> (potvrda preko mobitela). Bez toga app lozinka nije dostupna.</p>
        <p><strong>Korak 2:</strong> otvorite <a href="https://myaccount.google.com/apppasswords" target="_blank">myaccount.google.com/apppasswords</a>, upišite naziv (npr. "Trgovina") i kliknite <em>Create</em>. Google prikaže 16 znakova (npr. <code>abcd efgh ijkl mnop</code>) — kopirajte ih BEZ razmaka.</p>
        <p><strong>Korak 3:</strong> ovdje upišite: server <code>smtp.gmail.com</code>, port <code>587</code>, STARTTLS, korisničko ime = vaša Gmail adresa, lozinka = tih 16 znakova. Pošiljatelj (From) = ista Gmail adresa.</p>
        <p>⚠ Gmail dopušta ~500 poruka dnevno — za trgovinu je to obično dovoljno, ali adresa na vlastitoj domeni (vodič 2) djeluje profesionalnije.</p>
      </div>
    </details>

    <details>
      <summary style="cursor:pointer;font-weight:600">4) Outlook / Office 365</summary>
      <div style="font-size:13.5px;line-height:1.7;color:#374151;padding:8px 2px">
        <p>Server <code>smtp.office365.com</code>, port <code>587</code>, STARTTLS, korisničko ime = puna adresa. Ako prijava ne prolazi, u Microsoft računu uključite 2FA pa kreirajte app lozinku (slično Gmailu), ili u admin centru omogućite "Authenticated SMTP" za taj poštanski sandučić.</p>
      </div>
    </details>

    <details>
      <summary style="cursor:pointer;font-weight:600">💡 Mailovi padaju u spam?</summary>
      <div style="font-size:13.5px;line-height:1.7;color:#374151;padding:8px 2px">
        <p>1) Pošiljatelj (From) neka bude adresa <strong>na vašoj domeni</strong>, ne @gmail.com.<br>
        2) U hosting panelu provjerite da domena ima <strong>SPF</strong> i <strong>DKIM</strong> DNS zapise (cPanel → Email Deliverability → "Repair").<br>
        3) Šaljite preko SMTP-a vašeg hostinga, a ne preko PHP mail().</p>
      </div>
    </details>
  </div>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
