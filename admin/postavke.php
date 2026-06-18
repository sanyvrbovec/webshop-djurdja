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
        Audit::log('settings_updated');
        flash('success', 'Postavke spremljene.');
    } elseif ($action === 'rotate_cron') {
        // Rotacija cron tokena — prepiše CRON_TOKEN u config/config.php
        $cfgFile = SHOP_ROOT . '/config/config.php';
        $src = (string) @file_get_contents($cfgFile);
        $newTok = bin2hex(random_bytes(24));
        $new = preg_replace("/define\\('CRON_TOKEN',\\s*'[^']*'\\)/", "define('CRON_TOKEN', '" . $newTok . "')", $src, 1, $cnt);
        if ($cnt === 1 && @file_put_contents($cfgFile, $new) !== false) {
            Audit::log('cron_token_rotated');
            flash('success', 'Cron token je promijenjen ✓ Ažurirajte cron job na hostingu novim URL-om (prikazan u tehničkim podacima).');
        } else {
            flash('error', 'Ne mogu zapisati config/config.php (dozvole?). Token nije promijenjen.');
        }
    } elseif ($action === 'reset') {
        if (!password_verify((string) ($_POST['admin_pass'] ?? ''), $currentAdmin['password_hash'])) {
            flash('error', 'Pogrešna administratorska lozinka — brisanje je odbijeno.');
        } elseif (trim((string) ($_POST['confirm_text'] ?? '')) !== 'OBRIŠI') {
            flash('error', 'Za potvrdu upišite točno: OBRIŠI (velikim slovima).');
        } else {
            $liveCnt = (int) $db->fetchColumn(
                "SELECT COUNT(*) FROM orders WHERE fiscal_mode = 'live' AND fiscal_status IN ('fiscalized','stornoed')"
            );
            if ($liveCnt > 0 && empty($_POST['confirm_live'])) {
                flash('error', "STOP: postoje $liveCnt LIVE fiskalizirana računa — to je zakonska dokumentacija (čuvanje 11 godina). Prvo ih ispišite/arhivirajte, pa označite dodatnu potvrdu.");
            } else {
                $pdo = $db->pdo();
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach (['fiscal_log', 'payment_transactions', 'order_items', 'orders', 'sync_log', 'login_attempts'] as $t) {
                    $pdo->exec("TRUNCATE TABLE `$t`");
                }
                if (!empty($_POST['wipe_catalog'])) {
                    foreach ($db->fetchAll('SELECT filename FROM product_images') as $im) {
                        @unlink(SHOP_ROOT . '/uploads/products/' . $im['filename']);
                    }
                    foreach (['product_images', 'product_variants', 'products', 'categories'] as $t) {
                        $pdo->exec("TRUNCATE TABLE `$t`");
                    }
                    Settings::set('catalog_synced_at', null);
                }
                if (!empty($_POST['wipe_newsletter'])) {
                    $pdo->exec('TRUNCATE TABLE newsletter_subscribers');
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                Settings::set('quota_warned_for', null);
                Audit::log('shop_reset', ['detail' => 'katalog:' . (!empty($_POST['wipe_catalog']) ? 'da' : 'ne') . ', newsletter:' . (!empty($_POST['wipe_newsletter']) ? 'da' : 'ne')]);
                flash('success', 'Trgovina vraćena na nulu ✓ Narudžbe i računi obrisani, brojač kreće od 1.'
                    . (!empty($_POST['wipe_catalog']) ? ' Pokrenite Sinkronizaciju za ponovno povlačenje artikala iz đurđe.' : ''));
            }
        }
    } elseif ($action === 'password') {
        $cur = (string) $_POST['current'];
        $new = (string) $_POST['new'];
        if (!password_verify($cur, $currentAdmin['password_hash'])) {
            flash('error', 'Trenutna lozinka nije ispravna.');
        } elseif (strlen($new) < 8) {
            flash('error', 'Nova lozinka mora imati min. 8 znakova.');
        } else {
            $db->update('admin_users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = :id', [':id' => $currentAdmin['id']]);
            Audit::log('admin_password_changed');
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
        <tr><td>Verzija trgovine</td><td><strong><?= e(SHOP_VERSION) ?></strong><?php $us = Updater::status(); if ($us['newer']): ?> <a class="badge amber" href="<?= e(adminUrl('azuriranje.php')) ?>">dostupna <?= e($us['latest']) ?> →</a><?php endif; ?></td></tr>
        <tr><td>PHP</td><td><?= e(PHP_VERSION) ?></td></tr>
        <tr><td>Cron URL</td><td>
          <code id="cronUrl" style="font-size:11px;word-break:break-all" data-full="<?= e(SITE_URL . '/api/cron.php?token=' . CRON_TOKEN) ?>"><?= e(SITE_URL . '/api/cron.php?token=') ?>••••••••••</code>
          <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
            <button type="button" class="abtn ghost sm" onclick="var c=document.getElementById('cronUrl');c.textContent=c.dataset.full;this.remove()">Prikaži</button>
            <button type="button" class="abtn ghost sm" onclick="if(navigator.clipboard)navigator.clipboard.writeText(document.getElementById('cronUrl').dataset.full);this.textContent='Kopirano ✓'">Kopiraj URL</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Promijeniti cron token? Stari URL prestaje raditi — morat ćete ažurirati cron na hostingu.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="rotate_cron">
              <button class="abtn ghost sm">🔄 Rotiraj token</button>
            </form>
          </div>
        </td></tr>
        <tr><td>Instalirano</td><td><?= e(s('installed_at', '—')) ?></td></tr>
      </table>
      <p class="sub" style="margin-top:10px"><strong>Cron nije obavezan.</strong> Trgovina se sama održava dok ima posjeta izlogu ili dok radite u adminu (fiskalni retry, dnevni sync, osvježavanje veze). Za <em>zajamčeno</em> izvođenje i na trgovini bez prometa, postavite hosting "Cron Jobs" (ili besplatni cron-job.org) na gornji URL svakih 5–15 min.</p>
    </div>
  </div>
</div>

<div class="acard" style="margin-top:20px;border-color:#fecaca">
  <h3 style="color:#b91c1c">🧨 Opasna zona — vrati trgovinu na nulu</h3>
  <p class="sub">Briše <strong>sve narudžbe, račune, transakcije i logove</strong> (brojevi narudžbi kreću od početka).
    Namijenjeno za kraj testne faze, prije pravog pokretanja. Đurđa veza, dizajn i postavke ostaju netaknuti.<br>
    <strong style="color:#b91c1c">Upozorenje:</strong> LIVE fiskalizirani računi su zakonska dokumentacija — njih brišite samo ako znate što radite.</p>
  <form method="post" onsubmit="return confirm('Zadnja provjera: obrisati sve narudžbe i račune? Ovo je nepovratno.')">
    <?= csrf_field() ?><input type="hidden" name="action" value="reset">
    <label class="acheck"><input type="checkbox" name="wipe_catalog" value="1"> Obriši i katalog (artikli, varijante, kategorije, slike) — vraća se sinkronizacijom iz đurđe</label>
    <label class="acheck"><input type="checkbox" name="wipe_newsletter" value="1"> Obriši i newsletter pretplatnike</label>
    <label class="acheck"><input type="checkbox" name="confirm_live" value="1"> Svjestan/na sam da se brišu i eventualni LIVE fiskalizirani računi</label>
    <div style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap">
      <input class="ainput" type="password" name="admin_pass" placeholder="Vaša admin lozinka" style="max-width:200px" autocomplete="current-password">
      <input class="ainput" name="confirm_text" placeholder="Za potvrdu upišite: OBRIŠI" style="max-width:220px" autocomplete="off">
      <button class="abtn danger">🧨 Vrati na nulu</button>
    </div>
    <p class="sub" style="margin-top:8px">Potrebna je vaša admin lozinka <strong>i</strong> potvrdni tekst — dvostruka brana protiv slučajnog/zlonamjernog brisanja.</p>
  </form>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
