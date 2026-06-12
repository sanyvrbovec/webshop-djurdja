<?php
/**
 * SSO start — prijava u administraciju preko MojaĐurđa računa (bez lozinke).
 * Generira jednokratni challenge i šalje korisnika na mojadjurdja.com gdje
 * (ulogiran, s ovlasti za firmu) odobrava prijavu; povratak ide na
 * sso-callback.php s HMAC tokenom potpisanim tajnom API ključa.
 */
require_once __DIR__ . '/../core/bootstrap.php';

if (!empty($_SESSION['admin_id'])) redirect('admin/');

if (defined('DJURDJA_MOCK') && DJURDJA_MOCK) {
    flash('error', 'Prijava preko đurđe ne radi u simulaciji (DJURDJA_MOCK).');
    redirect('admin/login.php');
}
if (!Settings::get('djurdja_key_id')) {
    flash('error', 'Trgovina još nije povezana s MojaĐurđa računom.');
    redirect('admin/login.php');
}

$challenge = bin2hex(random_bytes(32));
$_SESSION['sso_challenge'] = $challenge;
$_SESSION['sso_expires'] = time() + 600;

// Frontend baza = api_base bez /api/vN sufiksa
$front = preg_replace('#/api/v\d+/?$#', '', (string) Settings::get('djurdja_api_base', 'https://mojadjurdja.com/api/v1'));
$url = rtrim($front, '/') . '/?shop_sso=1'
    . '&domain=' . rawurlencode(strtolower($_SERVER['HTTP_HOST'] ?? ''))
    . '&challenge=' . $challenge
    . '&return=' . rawurlencode(SITE_URL . '/admin/sso-callback.php');

header('Location: ' . $url);
exit;
