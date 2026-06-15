<?php
/**
 * ════════════════════════════════════════════════════════════════════
 * ĐurđaShop © 2026 Fork — VLASNIČKI softver (source-available).
 * Korištenje isključivo uz aktivan MojaĐurđa račun. Zabranjeno je mijenjati
 * izvorni kod (osim konfiguracije kroz administraciju), uklanjati ili
 * zaobilaziti integraciju s MojaĐurđa, fiskalizaciju, provjeru plana/kvote
 * i atribuciju, te redistribuirati kod. Puni uvjeti: datoteka LICENSE.
 * Kršenje AUTOMATSKI ukida pravo korištenja i omogućuje daljinsko gašenje.
 * ════════════════════════════════════════════════════════════════════
 *
 * bootstrap — uključuje ga svaka javna stranica, API endpoint i admin init.
 */

define('SHOP_VERSION', '1.3.0');
define('SHOP_ROOT', dirname(__DIR__));

// ── 1. Config (ako ne postoji → installer) ──
$__cfg = SHOP_ROOT . '/config/config.php';
if (!file_exists($__cfg)) {
    $self = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = str_replace('\\', '/', dirname($self));
    $base = preg_replace('#/(admin|api)$#', '', rtrim($base, '/'));
    header('Location: ' . ($base === '' ? '' : $base) . '/install/');
    exit;
}
require_once $__cfg;

date_default_timezone_set('Europe/Zagreb');
mb_internal_encoding('UTF-8');

// ── 2. Greške: nikad na ekran u produkciji, uvijek u log ──
error_reporting(E_ALL);
ini_set('display_errors', (defined('DEBUG') && DEBUG) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', SHOP_ROOT . '/logs/php-errors.log');

// ── 3. Autoload core klasa ──
spl_autoload_register(function ($class) {
    $f = SHOP_ROOT . '/core/' . $class . '.php';
    if (is_file($f)) require_once $f;
});

// ── 4. SITE_URL ──
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}
if (!defined('SITE_URL')) {
    $proto = is_https() ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SITE_URL', $proto . '://' . $host . BASE_URL);
}

// ── 5. DB ── (pad baze NIKAD ne smije dati sirovi 500)
try {
    $db = Database::instance();
} catch (Throwable $e) {
    error_log('[bootstrap] Baza nedostupna: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 120');
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Baza podataka trenutno nije dostupna. Pokušajte za koju minutu.']);
    } else {
        $dbgMsg = (defined('DEBUG') && DEBUG) ? '<p style="color:#b91c1c;font-size:13px;word-break:break-all">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>' : '';
        echo '<!doctype html><html lang="hr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>Trenutno nedostupno</title>'
            . '<body style="margin:0;font-family:system-ui,sans-serif;background:#f8fafc;display:grid;place-items:center;min-height:100vh">'
            . '<div style="max-width:480px;padding:40px;text-align:center">'
            . '<div style="font-size:44px">🛠️</div>'
            . '<h1 style="font-size:22px;color:#0f172a">Trgovina se trenutno osvježava</h1>'
            . '<p style="color:#475569;font-size:15px;line-height:1.6">Vratite se za nekoliko minuta — hvala na strpljenju!</p>'
            . $dbgMsg
            . '<p style="color:#94a3b8;font-size:12px;line-height:1.6;border-top:1px solid #e2e8f0;padding-top:14px;margin-top:22px">'
            . 'Napomena za vlasnika trgovine: baza podataka nije dostupna. Provjerite radi li MySQL te jesu li podaci u '
            . '<code>config/config.php</code> ispravni (host, ime baze, korisnik, lozinka). '
            . 'Ako ste trgovinu upravo prenijeli na novi server, obrišite <code>config/config.php</code> i otvorite '
            . '<code>/install/</code> za ponovno postavljanje.</p>'
            . '</div></body></html>';
    }
    exit;
}

// ── 5b. Auto-migracije sheme (tihe, idempotentne) ──
try {
    Migrations::ensure();
} catch (Throwable $e) {
    error_log('[bootstrap] migracije: ' . $e->getMessage());
}

// ── 6. Sesija (sigurni cookie flagovi) ──
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('djshop_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (BASE_URL === '' ? '/' : BASE_URL . '/'),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── 7. Sigurnosni headeri ──
if (PHP_SAPI !== 'cli') {
    Security::applyHeaders();
}

// ════════════════════════════════════════════════════════════
// Helperi
// ════════════════════════════════════════════════════════════

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    // Cache-busting po datumu izmjene datoteke: svaka promjena CSS/JS-a se
    // odmah vidi BEZ čišćenja browser keša (i bez ručnog bumpanja verzije).
    $f = SHOP_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($f) ? (string) filemtime($f) : SHOP_VERSION;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function upload_url(string $path): string
{
    return url('uploads/' . ltrim($path, '/'));
}

function redirect(string $to): void
{
    if (!preg_match('#^https?://#', $to)) $to = url($to);
    header('Location: ' . $to);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Provjeri CSRF token (POST polje _csrf ili header X-CSRF-Token). Prekida izvršavanje na neuspjeh. */
function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['_csrf']) || !is_string($sent) || !hash_equals($_SESSION['_csrf'], $sent)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Sigurnosni token je istekao. Osvježite stranicu i pokušajte ponovno.']);
        exit;
    }
}

/** Honeypot polja za forme (bot zaštita): skriveno polje + vremenska zamka. */
function hp_fields(): string
{
    return '<div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">'
         . '<input type="text" name="website" tabindex="-1" autocomplete="off">'
         . '</div><input type="hidden" name="_ts" value="' . time() . '">';
}

/** Kratica za Settings::get */
function s(string $key, $default = null)
{
    return Settings::get($key, $default);
}

function fmt_price($n): string
{
    return number_format((float) $n, 2, ',', '.') . ' €';
}

/** Slug s hrvatskom transliteracijom. */
function slugify(string $text): string
{
    $map = ['š'=>'s','đ'=>'d','č'=>'c','ć'=>'c','ž'=>'z','Š'=>'s','Đ'=>'d','Č'=>'c','Ć'=>'c','Ž'=>'z'];
    $text = strtr($text, $map);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
    $text = trim($text, '-');
    return $text === '' ? 'n-a' : mb_substr($text, 0, 180);
}

function client_ip(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return substr(trim(explode(',', $ip)[0]), 0, 45);
}

function shop_name(): string
{
    $n = Settings::get('shop_name');
    if ($n) return $n;
    $c = Djurdja::company();
    return $c['companyName'] ?? 'Web trgovina';
}

/** JSON odgovor za API endpointe. */
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
