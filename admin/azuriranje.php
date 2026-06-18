<?php
/** Nadogradnja trgovine — provjera verzije + one-click update (maintenance + SHA-256 + rollback). */
require_once __DIR__ . '/templates/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'update') {
        @set_time_limit(300);
        $res = Updater::run();
        flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? ('Trgovina je nadograđena s ' . e($res['from'] ?? '?') . ' na ' . e($res['version'] ?? '?') . ' ✓')
            : ('Nadogradnja nije uspjela: ' . ($res['error'] ?? 'nepoznata greška')));
    }
    redirect('admin/azuriranje.php');
}

$st = Updater::status();
$pageTitle = 'Nadogradnja';
require __DIR__ . '/templates/header.php';
?>
<div class="acard" style="max-width:680px">
  <h3>Verzija trgovine</h3>
  <table class="atable" style="font-size:13.5px">
    <tr><td>Trenutna verzija</td><td><strong><?= e($st['current']) ?></strong></td></tr>
    <tr><td>Najnovija verzija (GitHub)</td><td>
      <?php if ($st['checkFailed']): ?><span class="sub">GitHub trenutno nedostupan — pokušajte kasnije</span>
      <?php else: ?><?= e($st['latest']) ?><?php if ($st['newer']): ?> <span class="badge amber">nova dostupna</span><?php else: ?> <span class="badge green">ažurno</span><?php endif; ?><?php endif; ?>
    </td></tr>
    <tr><td>Minimalna verzija odobrena za rad (đurđa)</td><td>
      <?php $minV = Djurdja::minVersion(); if ($minV === ''): ?><span class="sub">nema prisile</span>
      <?php elseif (version_compare($st['current'], $minV, '<')): ?><?= e($minV) ?> <span class="badge red">ispod minimuma — izlog blokiran, ažurirajte hitno</span>
      <?php else: ?><?= e($minV) ?> <span class="badge green">zadovoljeno</span><?php endif; ?>
    </td></tr>
  </table>

  <?php if ($st['newer']): ?>
    <?php if ($st['oneClick']): ?>
      <div class="alert alert-info" style="margin-top:14px">Dostupna je nova verzija <strong><?= e($st['latest']) ?></strong>.</div>
      <p class="sub">Trgovina nakratko prelazi u način održavanja, preuzima paket <strong>izravno s GitHuba (HTTPS)</strong>, zamjenjuje datoteke uz sigurnosnu kopiju i <strong>automatski vraća prethodno stanje (rollback)</strong> ako nešto zapne (uz zaštitu od starije/neispravne verzije). Traje nekoliko sekundi. Postavke, tajne, narudžbe i uploadi ostaju netaknuti.</p>
      <form method="post" onsubmit="return confirm('Pokrenuti nadogradnju na <?= e($st['latest']) ?>? Trgovina će nakratko biti u načinu održavanja.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="update">
        <button class="abtn ok">⬆ Nadogradi na <?= e($st['latest']) ?></button>
      </form>
    <?php else: ?>
      <div class="alert alert-warning" style="margin-top:14px">
        Dostupna je nova verzija <strong><?= e($st['latest']) ?></strong>, ali <strong>automatska nadogradnja nije moguća na ovom hostingu</strong><?php if (is_string($st['capable'])) echo ' — ' . e($st['capable']); ?>.
      </div>
      <p class="sub">Ažurirajte ručno: preuzmite ZIP s <code>github.com/sanyvrbovec/webshop-djurdja</code>, zamijenite datoteke na serveru (FTP / File Manager) — <code>config/</code>, <code>uploads/</code> i baza ostaju netaknuti, a shema se nadograđuje sama pri prvom učitavanju.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="sub" style="margin-top:14px">Koristite najnoviju verziju. 👍</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
