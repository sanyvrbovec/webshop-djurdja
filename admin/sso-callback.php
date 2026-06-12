<?php
/**
 * SSO callback — provjera tokena kojeg je potpisala đurđa (HMAC tajnom
 * NAŠEG API ključa). Valjan token = vlasnik đurđa računa je odobrio prijavu
 * → uloguj prvog admina. Challenge je jednokratan i vrijedi 10 minuta.
 */
require_once __DIR__ . '/../core/bootstrap.php';

if (!empty($_SESSION['admin_id'])) redirect('admin/');

$keyId = (string) ($_GET['key_id'] ?? '');
$exp = (int) ($_GET['exp'] ?? 0);
$token = (string) ($_GET['token'] ?? '');
$challenge = (string) ($_SESSION['sso_challenge'] ?? '');
$challengeExp = (int) ($_SESSION['sso_expires'] ?? 0);
unset($_SESSION['sso_challenge'], $_SESSION['sso_expires']); // jednokratno

$failed = static function (string $why): void {
    error_log('[sso-callback] odbijeno: ' . $why);
    flash('error', 'Prijava preko đurđe nije uspjela (' . $why . '). Pokušajte ponovno ili se prijavite lozinkom.');
    redirect('admin/login.php');
};

if ($challenge === '' || time() > $challengeExp) $failed('istekao zahtjev');
if (!preg_match('/^[a-f0-9]{64}$/', $token) || $exp < time()) $failed('nevažeći token');
if (!hash_equals((string) Settings::get('djurdja_key_id', ''), $keyId)) $failed('ključ ne odgovara ovoj trgovini');

$secret = Crypto::decrypt(Settings::get('djurdja_secret_enc'));
if (!$secret) $failed('tajna ključa nije dostupna');

$calc = hash_hmac('sha256', 'shop-sso|' . strtolower($_SERVER['HTTP_HOST'] ?? '') . '|' . $challenge . '|' . $exp, $secret);
if (!hash_equals($calc, $token)) $failed('potpis ne odgovara');

// Rate-limit zaštita od pogađanja (dijeli brojač s loginom)
if (!Security::rateLimit('sso:' . client_ip(), 10, 600)) $failed('previše pokušaja');

$admin = $db->fetch('SELECT * FROM admin_users ORDER BY id ASC LIMIT 1');
if (!$admin) $failed('nema admin korisnika');

session_regenerate_id(true);
$_SESSION['admin_id'] = (int) $admin['id'];
$db->update('admin_users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $admin['id']]);
Djurdja::maybeRefresh();
flash('success', 'Prijavljeni ste preko MojaĐurđa računa ✓');
redirect('admin/');
