<?php
/**
 * Fiskalizirani račun za KUPCA (print/PDF). Pristup:
 *   - preko guest tokena iz e-maila/potvrde: ?t=<guest_token>
 *   - ili prijavljeni kupac koji je vlasnik narudžbe (?id=<id>)
 */
require_once __DIR__ . '/core/bootstrap.php';

$order = null;
$token = (string) ($_GET['t'] ?? '');
if (preg_match('/^[a-f0-9]{40,64}$/', $token)) {
    $order = $db->fetch('SELECT * FROM orders WHERE guest_token = :t', [':t' => $token]);
}
if (!$order && ($cust = Customer::current()) && ($oid = (int) ($_GET['id'] ?? 0))) {
    $order = $db->fetch('SELECT * FROM orders WHERE id = :id AND customer_id = :c', [':id' => $oid, ':c' => $cust['id']]);
}

if (!$order || !in_array($order['fiscal_status'], ['fiscalized', 'stornoed'], true)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$items = $db->fetchAll('SELECT * FROM order_items WHERE order_id = :o', [':o' => $order['id']]);
$company = Djurdja::company();
$inVat = !empty($company['inVatSystem']);

$byRate = [];
$itemsTotal = 0.0;
foreach ($items as $it) {
    $r = (string) round((float) $it['vat_rate'], 2);
    $byRate[$r] = ($byRate[$r] ?? 0) + (float) $it['total'];
    $itemsTotal += (float) $it['total'];
}
$itemsTotal = round($itemsTotal, 2);

$backUrl = Customer::isLoggedIn() ? url('moj-racun.php') : url('narudzba-potvrda.php?t=' . urlencode($order['guest_token']));
require __DIR__ . '/includes/receipt-document.php';
