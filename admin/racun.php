<?php
/** Printabilni prikaz fiskaliziranog računa za vlasnika (admin). */
require_once __DIR__ . '/templates/init.php';

$id = (int) ($_GET['id'] ?? 0);
$order = $db->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
if (!$order || !in_array($order['fiscal_status'], ['fiscalized', 'stornoed'], true)) {
    flash('error', 'Račun nije fiskaliziran.');
    redirect('admin/narudzba.php?id=' . $id);
}
$items = $db->fetchAll('SELECT * FROM order_items WHERE order_id = :o', [':o' => $id]);
$company = Djurdja::company();
$inVat = !empty($company['inVatSystem']);

// PDV rekapitulacija po stopama — SAMO stavke (dostava/naknada nisu predmet računa)
$byRate = [];
$itemsTotal = 0.0;
foreach ($items as $it) {
    $r = (string) round((float) $it['vat_rate'], 2);
    $byRate[$r] = ($byRate[$r] ?? 0) + (float) $it['total'];
    $itemsTotal += (float) $it['total'];
}
$itemsTotal = round($itemsTotal, 2);

$backUrl = adminUrl('narudzba.php?id=' . $id);
require __DIR__ . '/../includes/receipt-document.php';
