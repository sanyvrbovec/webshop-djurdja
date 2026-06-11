<?php
/** Kontakt stranica s formom (mail vlasniku). */
require_once __DIR__ . '/core/bootstrap.php';

$sent = false; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!Security::honeypotOk()) {
        $error = 'Provjera nije uspjela. Pokušajte ponovno.';
    } else {
        $rl = 'kontakt:' . client_ip();
        if (!Security::rateLimit($rl, 3, 900)) {
            $error = 'Previše poruka. Pokušajte za 15 minuta.';
        } else {
            Security::recordAttempt($rl);
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $msg = trim((string) ($_POST['message'] ?? ''));
            if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($msg) < 10) {
                $error = 'Ispunite sva polja (poruka min. 10 znakova).';
            } else {
                $html = '<h3>Upit s web trgovine</h3><p><strong>' . e($name) . '</strong> · ' . e($email) . '</p><p>' . nl2br(e(mb_substr($msg, 0, 5000))) . '</p>';
                Mailer::send(s('shop_email', ''), 'Upit s trgovine — ' . $name, $html);
                $sent = true;
            }
        }
    }
}

$company = Djurdja::company();
$pageTitle = 'Kontakt';
$pageDesc = 'Kontaktirajte ' . shop_name();
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="section-head" style="margin-top:26px"><h1 class="section-title">Kontakt</h1></div>
  <div class="checkout-grid" style="max-width:980px">
    <div class="card">
      <?php if ($sent): ?>
        <div class="alert alert-success">Hvala! Vaša poruka je poslana — javit ćemo se u najkraćem roku. 💬</div>
      <?php else: ?>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?><?= hp_fields() ?>
          <div class="form-grid">
            <div><label class="f-label">Ime *</label><input class="f-input" name="name" required maxlength="100"></div>
            <div><label class="f-label">E-mail *</label><input class="f-input" type="email" name="email" required maxlength="190"></div>
            <div class="full"><label class="f-label">Poruka *</label><textarea class="f-input" name="message" rows="6" required maxlength="5000"></textarea></div>
          </div>
          <button class="btn" style="margin-top:16px">Pošalji poruku</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="card">
      <h3>Podaci o trgovcu</h3>
      <?php if (!empty($company['companyName'])): ?>
        <p style="line-height:1.9;font-size:14.5px">
          <strong><?= e($company['companyName']) ?></strong><br>
          <?php if (!empty($company['address'])): ?><?= e($company['address']) ?>, <?= e($company['postalCode'] ?? '') ?> <?= e($company['city'] ?? '') ?><br><?php endif; ?>
          OIB: <?= e($company['companyOib'] ?? '—') ?><br>
          E-mail: <a href="mailto:<?= e(s('shop_email', '')) ?>"><?= e(s('shop_email', '')) ?></a>
        </p>
      <?php endif; ?>
      <p style="font-size:12.5px;color:var(--c-muted)">Podaci o tvrtki preuzimaju se automatski iz sustava MojaĐurđa i uvijek su ažurni.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
