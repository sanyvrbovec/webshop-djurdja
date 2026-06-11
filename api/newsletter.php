<?php
/**
 * API: prijava na newsletter (double opt-in token spremljen za buduću potvrdu).
 */
require_once __DIR__ . '/../core/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Samo POST.'], 405);
csrf_check();

$rl = 'newsletter:' . client_ip();
if (!Security::rateLimit($rl, 5, 600)) json_out(['ok' => false, 'error' => 'Previše pokušaja.'], 429);
Security::recordAttempt($rl);

$email = trim((string) ($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'Unesite ispravnu e-mail adresu.'], 422);
}

try {
    $db->query(
        'INSERT INTO newsletter_subscribers (email, token) VALUES (:e, :t)
         ON DUPLICATE KEY UPDATE email = email',
        [':e' => mb_substr($email, 0, 190), ':t' => bin2hex(random_bytes(24))]
    );
} catch (Throwable $e) {
    error_log('[newsletter] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Greška, pokušajte kasnije.'], 500);
}
json_out(['ok' => true, 'message' => 'Hvala na prijavi! 💌']);
