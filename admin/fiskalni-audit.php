<?php
/**
 * Fiskalni audit — READ-ONLY pregled svake fiskalizacije/storna: točan zahtjev
 * poslan đurđi i točan odgovor (JIR/ZKI). Nema izmjene/brisanja (append-only log).
 * Vlasnik ovdje neovisno provjerava je li račun stvarno fiskaliziran u Poreznoj.
 */
require_once __DIR__ . '/templates/init.php';

$orderId = (int) ($_GET['order_id'] ?? 0);
$where = $orderId ? 'WHERE fl.order_id = :o' : '';
$params = $orderId ? [':o' => $orderId] : [];
$logs = $db->fetchAll(
    "SELECT fl.*, o.order_number, o.fiscal_qr
       FROM fiscal_log fl LEFT JOIN orders o ON o.id = fl.order_id
       $where ORDER BY fl.id DESC LIMIT 200",
    $params
);

$pretty = static function (?string $json): string {
    if ($json === null || $json === '') return '—';
    $d = json_decode($json, true);
    return $d === null ? $json : (string) json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

$pageTitle = 'Fiskalni audit';
require __DIR__ . '/templates/header.php';
?>
<div class="acard">
  <h3>🔒 Fiskalni audit <span class="badge gray">read-only</span></h3>
  <p class="sub" style="margin:0 0 4px">Točan zapis svake fiskalizacije i storna: <strong>što je shop poslao đurđi</strong> i <strong>što je đurđa/Porezna vratila</strong> (JIR/ZKI). Zapis se ne može mijenjati ni brisati. Stvarnu fiskalizaciju provjerite na Poreznoj putem JIR/QR linka.</p>
  <?php if ($orderId): ?><p class="sub">Filtrirano za narudžbu #<?= (int) $orderId ?> · <a href="<?= e(adminUrl('fiskalni-audit.php')) ?>">prikaži sve</a></p><?php endif; ?>
</div>

<?php if (!$logs): ?>
  <div class="alert alert-info">Još nema fiskalnih zapisa.</div>
<?php else: ?>
  <?php foreach ($logs as $l): ?>
    <div class="acard" style="margin-bottom:12px">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <span class="badge <?= $l['action'] === 'error' ? 'red' : ($l['action'] === 'storno' ? 'amber' : 'green') ?>"><?= e($l['action']) ?></span>
        <strong><?= e($l['order_number'] ?? ('#' . $l['order_id'])) ?></strong>
        <?php if ($l['receipt_number']): ?><span class="sub">račun <?= e($l['receipt_number']) ?></span><?php endif; ?>
        <?php if ($l['mode']): ?><span class="badge <?= $l['mode'] === 'test' ? 'amber' : 'gray' ?>"><?= e($l['mode']) ?></span><?php endif; ?>
        <span class="sub" style="margin-left:auto"><?= e($l['created_at']) ?></span>
      </div>
      <div class="sub" style="margin-top:6px;line-height:1.7">
        <?php if ($l['jir']): ?>JIR: <code><?= e($l['jir']) ?></code><br><?php endif; ?>
        <?php if ($l['zki']): ?>ZKI: <code><?= e($l['zki']) ?></code><br><?php endif; ?>
        <?php if ($l['response_status']): ?>HTTP: <?= (int) $l['response_status'] ?> · <?php endif; ?>
        <?php if ($l['duration_ms'] !== null): ?><?= (int) $l['duration_ms'] ?> ms<?php endif; ?>
        <?php if ($l['error_message']): ?><br><span style="color:#dc2626">Greška: <?= e($l['error_message']) ?></span><?php endif; ?>
        <?php if (!empty($l['fiscal_qr'])): ?><br><a href="<?= e($l['fiscal_qr']) ?>" target="_blank" rel="noopener">Provjeri račun na Poreznoj ↗</a><?php endif; ?>
      </div>
      <details style="margin-top:8px">
        <summary style="cursor:pointer;font-weight:600;color:#4f46e5">Prikaži poslano i primljeno (točan zapis)</summary>
        <div style="margin-top:8px">
          <div class="sub" style="font-weight:600">→ Poslano đurđi (zahtjev):</div>
          <pre style="background:#0b1020;color:#d1d5db;padding:10px;border-radius:8px;overflow:auto;font-size:12px;max-height:320px"><?= e($pretty($l['raw_request'])) ?></pre>
          <div class="sub" style="font-weight:600;margin-top:8px">← Odgovor đurđe/Porezne:</div>
          <pre style="background:#0b1020;color:#d1d5db;padding:10px;border-radius:8px;overflow:auto;font-size:12px;max-height:320px"><?= e($pretty($l['raw_response'])) ?></pre>
        </div>
      </details>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
