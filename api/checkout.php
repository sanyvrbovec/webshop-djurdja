<?php
/**
 * API: kreiranje narudžbe iz checkout forme.
 * Zaštite: CSRF, honeypot + vremenska zamka, rate limit po IP-u, validacija polja,
 * cijene isključivo iz baze (Cart::detailed), đurđa checkout gating u Orders::create.
 */
require_once __DIR__ . '/../core/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Samo POST.'], 405);
}
csrf_check();

if (!Security::honeypotOk()) {
    json_out(['ok' => false, 'error' => 'Provjera nije uspjela. Osvježite stranicu i pokušajte ponovno.'], 400);
}

$rlKey = 'checkout:' . client_ip();
if (!Security::rateLimit($rlKey, 6, 600)) {
    json_out(['ok' => false, 'error' => 'Previše pokušaja. Pričekajte nekoliko minuta.'], 429);
}
Security::recordAttempt($rlKey);

// ── Validacija ──
$name   = trim((string) ($_POST['name'] ?? ''));
$email  = trim((string) ($_POST['email'] ?? ''));
$phone  = trim((string) ($_POST['phone'] ?? ''));
$address= trim((string) ($_POST['address'] ?? ''));
$city   = trim((string) ($_POST['city'] ?? ''));
$postal = trim((string) ($_POST['postal'] ?? ''));
$note   = trim((string) ($_POST['note'] ?? ''));
$method = (string) ($_POST['payment_method'] ?? '');
$terms  = !empty($_POST['terms']);

$errors = [];
if (mb_strlen($name) < 3) $errors['name'] = 'Unesite ime i prezime.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Unesite ispravan e-mail.';
if (mb_strlen($address) < 3) $errors['address'] = 'Unesite adresu.';
if (mb_strlen($city) < 2) $errors['city'] = 'Unesite grad.';
if (!preg_match('/^\d{4,10}$/', $postal)) $errors['postal'] = 'Unesite poštanski broj.';
if (!in_array($method, ['cod', 'stripe'], true)) $errors['payment_method'] = 'Odaberite način plaćanja.';
if (!$terms) $errors['terms'] = 'Morate prihvatiti uvjete korištenja.';
if ($errors) {
    json_out(['ok' => false, 'error' => reset($errors), 'fields' => $errors], 422);
}

$items = Cart::detailed();
if (!$items) json_out(['ok' => false, 'error' => 'Košarica je prazna.'], 400);

try {
    $order = Orders::create([
        'name' => $name, 'email' => $email, 'phone' => $phone,
        'address' => $address, 'city' => $city, 'postal' => $postal, 'note' => $note,
    ], $method, $items);

    $pm = new PaymentManager();
    $redirect = $pm->initiate($order); // Stripe → URL, ostalo → null

    Cart::clear();
    Security::clearAttempts($rlKey);

    json_out([
        'ok' => true,
        'orderNumber' => $order['order_number'],
        'redirect' => $redirect ?: url('narudzba-potvrda.php') . '?t=' . urlencode($order['guest_token']),
    ]);
} catch (RuntimeException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[checkout] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Došlo je do greške pri obradi narudžbe. Pokušajte ponovno.'], 500);
}
