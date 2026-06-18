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
    <tr><td>Najnovija verzija</td><td>
      <?= $st['latest'] !== '' ? e($st['latest']) : '<span class="sub">još nepoznato — osvježite vezu s đurđom</span>' ?>
      <?php if ($st['newer']): ?> <span class="badge amber">nova dostupna</span><?php elseif ($st['latest'] !== ''): ?> <span class="badge green">ažurno</span><?php endif; ?>
    </td></tr>
  </table>

  <?php if ($st['newer']): ?>
    <?php if ($st['oneClick']): ?>
      <div class="alert alert-info" style="margin-top:14px">Dostupna je nova verzija <strong><?= e($st['latest']) ?></strong>.</div>
      <p class="sub">Trgovina nakratko prelazi u način održavanja, preuzima i <strong>provjerava paket (SHA-256)</strong>, zamjenjuje datoteke uz sigurnosnu kopiju i <strong>automatski vraća prethodno stanje (rollback)</strong> ako nešto zapne. Traje nekoliko sekundi. Postavke, tajne, narudžbe i uploadi ostaju netaknuti.</p>
      <form method="post" onsubmit="return confirm('Pokrenuti nadogradnju na <?= e($st['latest']) ?>? Trgovina će nakratko biti u načinu održavanja.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="update">
        <button class="abtn ok">⬆ Nadogradi na <?= e($st['latest']) ?></button>
      </form>
    <?php else: ?>
      <div class="alert alert-warning" style="margin-top:14px">
        Dostupna je nova verzija <strong><?= e($st['latest']) ?></strong>, ali <strong>automatska nadogradnja trenutno nije moguća</strong><?php
          if (is_string($st['capable'])) echo ' — ' . e($st['capable']);
          elseif (!$st['hasPkg']) echo ' — đurđa još nije objavila paket za automatsku nadogradnju';
        ?>.
      </div>
      <p class="sub">Ažurirajte ručno: preuzmite najnoviju verziju, zamijenite datoteke na serveru (FTP / File Manager) — <code>config/</code>, <code>uploads/</code> i baza ostaju netaknuti, a shema se nadograđuje sama pri prvom učitavanju.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="sub" style="margin-top:14px">Koristite najnoviju verziju. 👍</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
