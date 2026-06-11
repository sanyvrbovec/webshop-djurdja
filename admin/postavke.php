<?php
/** Opće postavke trgovine. */
require_once __DIR__ . '/templates/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'general') {
        Settings::setMany([
            'shop_name' => mb_substr(trim((string) $_POST['shop_name']), 0, 120) ?: shop_name(),
            'shop_email' => filter_var(trim((string) $_POST['shop_email']), FILTER_VALIDATE_EMAIL) ?: s('shop_email'),
            'products_per_page' => (string) max(4, min(60, (int) $_POST['products_per_page'])),
            'seo_default_description' => mb_substr(trim((string) $_POST['seo_default_description']), 0, 300),
            'show_djurdja_credit' => !empty($_POST['show_djurdja_credit']) ? '1' : '0',
        ]);
        flash('success', 'Postavke spremljene.');
    } elseif ($action === 'password') {
        $cur = (string) $_POST['current'];
        $new = (string) $_POST['new'];
        if (!password_verify($cur, $currentAdmin['password_hash'])) {
            flash('error', 'Trenutna lozinka nije ispravna.');
        } elseif (strlen($new) < 8) {
            flash('error', 'Nova lozinka mora imati min. 8 znakova.');
        } else {
            $db->update('admin_users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = :id', [':id' => $currentAdmin['id']]);
            flash('success', 'Lozinka promijenjena.');
        }
    }
    redirect('admin/postavke.php');
}

$pageTitle = 'Opće postavke';
require __DIR__ . '/templates/header.php';
?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
  <div class="acard">
    <h3>Trgovina</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="general">
      <label class="al">Naziv trgovine</label>
      <input class="ainput" name="shop_name" value="<?= e(s('shop_name', '')) ?>">
      <label class="al">E-mail trgovine (narudžbe, upiti)</label>
      <input class="ainput" type="email" name="shop_email" value="<?= e(s('shop_email', '')) ?>">
      <label class="al">Proizvoda po stranici</label>
      <input class="ainput" type="number" name="products_per_page" min="4" max="60" value="<?= e(s('products_per_page', '12')) ?>">
      <label class="al">Zadani SEO opis (meta description)</label>
      <textarea class="ainput" name="seo_default_description" rows="2" maxlength="300"><?= e(s('seo_default_description', '')) ?></textarea>
      <?php if (!Djurdja::brandingRequired()): ?>
        <label class="acheck" style="margin-top:12px"><input type="checkbox" name="show_djurdja_credit" <?= s('show_djurdja_credit', '1') === '1' ? 'checked' : '' ?>> Prikaži "Pokreće MojaĐurđa" u podnožju (hvala na podršci! 💜)</label>
      <?php else: ?>
        <input type="hidden" name="show_djurdja_credit" value="1">
        <p class="sub" style="margin-top:12px">Na besplatnom paketu link "Pokreće MojaĐurđa" je uvijek uključen.</p>
      <?php endif; ?>
      <button class="abtn" style="margin-top:14px">💾 Spremi</button>
    </form>
  </div>

  <div style="display:grid;gap:20px">
    <div class="acard">
      <h3>Promjena lozinke</h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <label class="al">Trenutna lozinka</label>
        <input class="ainput" type="password" name="current" required autocomplete="current-password">
        <label class="al">Nova lozinka (min 8)</label>
        <input class="ainput" type="password" name="new" required minlength="8" autocomplete="new-password">
        <button class="abtn" style="margin-top:14px">Promijeni</button>
      </form>
    </div>

    <div class="acard">
      <h3>⚙️ Tehnički podaci</h3>
      <table class="atable" style="font-size:13px">
        <tr><td>Verzija trgovine</td><td><strong><?= e(SHOP_VERSION) ?></strong><?php $lv = s('djurdja_latest_version'); if ($lv && version_compare($lv, SHOP_VERSION, '>')): ?> <span class="badge amber">dostupna <?= e($lv) ?></span><?php endif; ?></td></tr>
        <tr><td>PHP</td><td><?= e(PHP_VERSION) ?></td></tr>
        <tr><td>Cron URL</td><td><code style="font-size:11px;word-break:break-all"><?= e(SITE_URL . '/api/cron.php?token=' . CRON_TOKEN) ?></code></td></tr>
        <tr><td>Instalirano</td><td><?= e(s('installed_at', '—')) ?></td></tr>
      </table>
      <p class="sub" style="margin-top:10px">Cron pozivajte svakih 5–15 minuta (hosting "Cron Jobs" ili vanjski servis poput cron-job.org) — pokreće fiskalne retry-e, dnevni sync i osvježavanje veze.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
