<?php
/**
 * ĐurđaShop — instalacijski čarobnjak.
 * Koraci: 1 provjere → 2 baza → 3 đurđa račun → 4 trgovina i admin → 5 instalacija.
 * Nakon uspjeha zapisuje install/.lock i odbija ponovno pokretanje.
 */

session_name('djshop_install');
session_start();

$ROOT = dirname(__DIR__);
$configFile = $ROOT . '/config/config.php';
$lockFile = __DIR__ . '/.lock';

// ── Guard: već instalirano ──
// Bitno: provjeravamo RADI li baza iz configa, jer je najčešća greška da
// korisnik prenese datoteke s localhosta ZAJEDNO s config.php (+ .lock) —
// tada svaka stranica vraća 500, a installer mora objasniti što napraviti.
if (file_exists($configFile)) {
    $dbWorks = false;
    $dbErr = '';
    try {
        require $configFile;
        new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 4]);
        $dbWorks = true;
    } catch (Throwable $e) {
        $dbErr = $e->getMessage();
    }

    http_response_code(403);
    if ($dbWorks) {
        die('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:40px;max-width:640px">'
            . '<h2>Trgovina je već instalirana ✓</h2>'
            . '<p>Sve radi. Iz sigurnosnih razloga obrišite cijeli <code>install/</code> direktorij sa servera.</p>'
            . '<p>Ako baš želite instalaciju ispočetka, ručno obrišite <code>config/config.php</code> i <code>install/.lock</code> pa osvježite ovu stranicu.</p></body>');
    }
    die('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:40px;max-width:680px">'
        . '<h2>⚠ Pronađen je config/config.php — ali baza iz njega NE RADI na ovom serveru.</h2>'
        . '<p style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:14px;line-height:1.7">'
        . 'Najčešći uzrok: datoteke su prenesene s <strong>drugog računala</strong> (npr. localhost) zajedno s njegovim '
        . '<code>config/config.php</code> — on pokazuje na bazu koja ovdje ne postoji, pa cijela trgovina vraća grešku 500.</p>'
        . '<p><strong>Rješenje (2 minute):</strong></p><ol style="line-height:2">'
        . '<li>Preko FTP-a / File Managera <strong>obrišite</strong> <code>config/config.php</code> i <code>install/.lock</code> (ako postoji)</li>'
        . '<li>Osvježite ovu stranicu — pokrenut će se čarobnjak instalacije</li>'
        . '<li>Upišite MySQL podatke <strong>OVOG hostinga</strong> (bazu kreirajte u cPanelu → MySQL Databases)</li></ol>'
        . '<p style="color:#6b7280;font-size:13px">Tehnički detalj: ' . htmlspecialchars(mb_substr($dbErr, 0, 200), ENT_QUOTES) . '</p></body>');
}
if (file_exists($lockFile)) {
    // .lock bez configa = ostatak prijenosa s drugog okruženja — makni ga i nastavi
    @unlink($lockFile);
}

function ie($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['djinst_csrf'])) $_SESSION['djinst_csrf'] = bin2hex(random_bytes(32));
function inst_csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . ie($_SESSION['djinst_csrf']) . '">'; }
function inst_csrf_check(): void {
    if (!hash_equals($_SESSION['djinst_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        die('Sigurnosni token nije valjan. Vratite se i pokušajte ponovno.');
    }
}

$S = &$_SESSION['djinst'];
if (!is_array($S ?? null)) $S = [];

$step = $_GET['step'] ?? '1';
$error = null;

// ════════════════════════════════════════════════════════════
// POST obrade
// ════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    inst_csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'db') {
        $S['db'] = [
            'host' => trim($_POST['db_host'] ?? 'localhost'),
            'name' => trim($_POST['db_name'] ?? ''),
            'user' => trim($_POST['db_user'] ?? ''),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
            'create' => !empty($_POST['db_create']),
        ];
        if ($S['db']['name'] === '' || !preg_match('/^[A-Za-z0-9_]+$/', $S['db']['name'])) {
            $error = 'Naziv baze smije sadržavati samo slova, brojke i donju crtu.';
            $step = '2';
        } else {
            try {
                if ($S['db']['create']) {
                    $pdo = new PDO("mysql:host={$S['db']['host']};charset=utf8mb4", $S['db']['user'], $S['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$S['db']['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_croatian_ci");
                }
                $pdo = new PDO("mysql:host={$S['db']['host']};dbname={$S['db']['name']};charset=utf8mb4", $S['db']['user'], $S['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $step = '3';
            } catch (PDOException $e) {
                $error = 'Spajanje na bazu nije uspjelo: ' . $e->getMessage();
                $step = '2';
            }
        }
    }

    elseif ($action === 'djurdja') {
        $mock = !empty($_POST['mock']);
        if ($mock) {
            $S['djurdja'] = [
                'mock' => true,
                'api_base' => 'https://mojadjurdja.com/api/v1',
                'key_id' => 'pk_test_mock',
                'secret' => 'sk_mock',
            ];
            require_once $ROOT . '/core/DjurdjaClient.php';
            $client = new DjurdjaClient(['mock' => true]);
            $S['company'] = $client->me();
            $step = '4';
        } else {
            $apiBase = rtrim(trim($_POST['api_base'] ?? 'https://mojadjurdja.com/api/v1'), '/');
            $keyId = trim($_POST['key_id'] ?? '');
            $secret = trim($_POST['secret'] ?? '');
            if (!preg_match('#^https://#', $apiBase)) {
                $error = 'API adresa mora počinjati s https://';
                $step = '3';
            } elseif (strpos($keyId, 'pk_') !== 0 || strpos($secret, 'sk_') !== 0) {
                $error = 'Key ID mora počinjati s pk_, a Secret s sk_ (kopirajte ih iz MojaĐurđa → API pristup).';
                $step = '3';
            } else {
                try {
                    require_once $ROOT . '/core/DjurdjaClient.php';
                    $client = new DjurdjaClient(['api_base' => $apiBase, 'key_id' => $keyId, 'secret' => $secret]);
                    $me = $client->me();
                    $S['djurdja'] = ['mock' => false, 'api_base' => $apiBase, 'key_id' => $keyId, 'secret' => $secret];
                    $S['company'] = $me;
                    $step = '4';
                } catch (Throwable $e) {
                    $hint = '';
                    if ($e instanceof DjurdjaApiException && $e->apiErrorCode === 'ip_not_allowed') {
                        $hint = ' Vaš server nije na IP listi ključa — u MojaĐurđa → API pristup dodajte IP servera u whitelist (ili ostavite listu praznom).';
                    }
                    $error = 'Povezivanje s MojaĐurđa nije uspjelo: ' . $e->getMessage() . $hint;
                    $step = '3';
                }
            }
        }
    }

    elseif ($action === 'finish') {
        $shopName = trim($_POST['shop_name'] ?? '');
        $shopEmail = trim($_POST['shop_email'] ?? '');
        $adminUser = trim($_POST['admin_user'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $pass1 = (string) ($_POST['admin_pass'] ?? '');
        $pass2 = (string) ($_POST['admin_pass2'] ?? '');

        if ($shopName === '' || $adminUser === '' || $pass1 === '') {
            $error = 'Sva polja su obavezna.'; $step = '4';
        } elseif (!filter_var($shopEmail, FILTER_VALIDATE_EMAIL) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Unesite ispravne e-mail adrese.'; $step = '4';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $adminUser)) {
            $error = 'Korisničko ime: 3–60 znakova, slova/brojke/točka/crtica.'; $step = '4';
        } elseif (strlen($pass1) < 8) {
            $error = 'Lozinka mora imati najmanje 8 znakova.'; $step = '4';
        } elseif ($pass1 !== $pass2) {
            $error = 'Lozinke se ne podudaraju.'; $step = '4';
        } elseif (empty($S['db']) || empty($S['djurdja'])) {
            $error = 'Sesija je istekla — krenite ispočetka.'; $step = '1';
        } else {
            try {
                // 1. Spoji se i pokreni schemu
                $pdo = new PDO("mysql:host={$S['db']['host']};dbname={$S['db']['name']};charset=utf8mb4", $S['db']['user'], $S['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $schema = file_get_contents(__DIR__ . '/schema.sql');
                foreach (preg_split('/;\s*[\r\n]+/', $schema) as $stmt) {
                    // Makni SQL komentar-linije unutar chunka (chunk može počinjati headerom)
                    $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
                    if ($stmt === '') continue;
                    $pdo->exec($stmt);
                }

                // 2. Generiraj tajne i config.php
                $encKey = bin2hex(random_bytes(32));
                $cronToken = bin2hex(random_bytes(24));
                $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])); // .../install
                $baseUrl = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/');   // parent
                if ($baseUrl === '/' || $baseUrl === '\\' || $baseUrl === '.') $baseUrl = '';
                $mockVal = !empty($S['djurdja']['mock']) ? 'true' : 'false';

                $cfg = "<?php\n"
                    . "// ĐurđaShop konfiguracija — generirano " . date('Y-m-d H:i:s') . ". NE dijeliti, NE stavljati u git.\n"
                    . "define('DB_HOST', '" . addslashes($S['db']['host']) . "');\n"
                    . "define('DB_NAME', '" . addslashes($S['db']['name']) . "');\n"
                    . "define('DB_USER', '" . addslashes($S['db']['user']) . "');\n"
                    . "define('DB_PASS', '" . addslashes($S['db']['pass']) . "');\n"
                    . "define('ENCRYPTION_KEY', '$encKey');\n"
                    . "define('CRON_TOKEN', '$cronToken');\n"
                    . "define('BASE_URL', '" . addslashes($baseUrl) . "');\n"
                    . "define('DJURDJA_MOCK', $mockVal);\n"
                    . "define('DEBUG', false);\n";
                if (file_put_contents($configFile, $cfg) === false) {
                    throw new RuntimeException('Ne mogu zapisati config/config.php — provjerite dozvole.');
                }

                // 3. Enkriptiraj đurđa secret novim ključem
                if (!defined('ENCRYPTION_KEY')) define('ENCRYPTION_KEY', $encKey);
                require_once $ROOT . '/core/Crypto.php';
                $secretEnc = Crypto::encrypt($S['djurdja']['secret']);

                // 4. Postavke
                $ins = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
                $settings = [
                    'shop_name' => $shopName,
                    'shop_email' => $shopEmail,
                    'djurdja_api_base' => $S['djurdja']['api_base'],
                    'djurdja_key_id' => $S['djurdja']['key_id'],
                    'djurdja_secret_enc' => $secretEnc,
                    'djurdja_company' => json_encode($S['company'], JSON_UNESCAPED_UNICODE),
                    'djurdja_company_synced_at' => date('Y-m-d H:i:s'),
                    'djurdja_last_ok_at' => date('Y-m-d H:i:s'),
                    'business_space' => 'WEBSHOP',
                    'cash_register' => '1',
                    'fiscal_enabled' => '1',
                    'force_test_mode' => '0',
                    'shipping_flat' => '5.00',
                    'shipping_free_over' => '50.00',
                    'products_per_page' => '12',
                    'installed_at' => date('Y-m-d H:i:s'),
                    'shop_version' => '1.0.0',
                ];
                foreach ($settings as $k => $v) $ins->execute([$k, $v]);

                // 5. Admin korisnik
                $pdo->prepare('INSERT INTO admin_users (username, email, password_hash) VALUES (?, ?, ?)')
                    ->execute([$adminUser, $adminEmail, password_hash($pass1, PASSWORD_DEFAULT)]);

                // 6. Direktoriji
                @mkdir($ROOT . '/uploads/products', 0775, true);
                @mkdir($ROOT . '/uploads/theme', 0775, true);
                @mkdir($ROOT . '/logs', 0775, true);

                // 7. Lock
                file_put_contents($lockFile, date('c'));
                unset($_SESSION['djinst']);
                $S = ['done_base' => $baseUrl];
                $step = 'done';
            } catch (Throwable $e) {
                @unlink($configFile);
                $error = 'Instalacija nije uspjela: ' . $e->getMessage();
                $step = '4';
            }
        }
    }
}

// ════════════════════════════════════════════════════════════
// Prikaz
// ════════════════════════════════════════════════════════════

function inst_layout(string $stepLabel, string $body, ?string $error): void
{
    $err = $error ? '<div class="err">⚠ ' . ie($error) . '</div>' : '';
    echo '<!doctype html><html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>ĐurđaShop instalacija</title><style>'
        . '*{box-sizing:border-box;margin:0;padding:0}body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:linear-gradient(135deg,#1e1b4b 0%,#4c1d95 50%,#701a75 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;color:#1f2937}'
        . '.card{background:#fff;border-radius:20px;box-shadow:0 25px 60px rgba(0,0,0,.35);max-width:620px;width:100%;overflow:hidden}'
        . '.head{background:linear-gradient(135deg,#4c1d95,#7c3aed);color:#fff;padding:28px 32px}'
        . '.head h1{font-size:22px;font-weight:800;letter-spacing:-.02em}.head p{opacity:.85;font-size:13px;margin-top:4px}'
        . '.body{padding:32px}.step{display:inline-block;background:#ede9fe;color:#6d28d9;font-size:12px;font-weight:700;padding:4px 12px;border-radius:99px;margin-bottom:18px;text-transform:uppercase;letter-spacing:.05em}'
        . 'h2{font-size:19px;margin-bottom:14px;letter-spacing:-.01em}p.lead{color:#6b7280;font-size:14px;margin-bottom:20px;line-height:1.6}'
        . 'label{display:block;font-size:13px;font-weight:600;margin:14px 0 6px}input[type=text],input[type=password],input[type=email],input[type=url]{width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;transition:.15s}input:focus{outline:0;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.12)}'
        . '.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.chk{display:flex;gap:10px;align-items:flex-start;margin:16px 0;font-size:13px;color:#4b5563;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px}'
        . '.btn{display:inline-block;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;border:0;padding:13px 28px;border-radius:11px;font-size:15px;font-weight:700;cursor:pointer;margin-top:22px;transition:.15s;text-decoration:none}.btn:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(124,58,237,.35)}'
        . '.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;line-height:1.5}'
        . '.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px}'
        . 'ul.req{list-style:none;margin:10px 0 6px}ul.req li{padding:9px 0;border-bottom:1px solid #f3f4f6;font-size:14px;display:flex;justify-content:space-between}'
        . '.pass{color:#059669;font-weight:700}.fail{color:#dc2626;font-weight:700}.warn{color:#d97706;font-weight:700}'
        . 'code{background:#f3f4f6;padding:2px 7px;border-radius:6px;font-size:12.5px}.muted{color:#9ca3af;font-size:12px;margin-top:10px}'
        . '</style></head><body><div class="card"><div class="head"><h1>🛍️ ĐurđaShop</h1><p>Besplatna web trgovina za korisnike sustava MojaĐurđa</p></div><div class="body">'
        . '<span class="step">' . ie($stepLabel) . '</span>' . $err . $body
        . '</div></div></body></html>';
}

if ($step === 'done') {
    $base = ie($S['done_base'] ?? '');
    inst_layout('Gotovo 🎉', '<h2>Trgovina je instalirana!</h2>'
        . '<div class="ok">Sve je spremno. Prvi korak: prijavite se u administraciju i pokrenite <strong>sinkronizaciju artikala</strong> iz đurđe.</div>'
        . '<p class="lead"><a class="btn" href="' . $base . '/admin/">Otvori administraciju →</a> &nbsp; <a class="btn" style="background:#374151" href="' . $base . '/">Pogledaj trgovinu</a></p>'
        . '<div class="err" style="margin-top:20px"><strong>VAŽNO za sigurnost:</strong> obrišite cijeli <code>install/</code> direktorij sa servera. Instalacija je zaključana, ali brisanje je najsigurnije.</div>', null);
    exit;
}

if ($step === '2') {
    $d = $S['db'] ?? [];
    inst_layout('Korak 2 / 4 — Baza podataka', '<h2>Spajanje na MySQL bazu</h2>'
        . '<p class="lead">Podatke o bazi dobili ste od svog hosting providera (cPanel → MySQL Databases). Na lokalnom XAMPP-u: korisnik <code>root</code> bez lozinke.</p>'
        . '<form method="post">' . inst_csrf_field() . '<input type="hidden" name="action" value="db">'
        . '<div class="row"><div><label>Host</label><input type="text" name="db_host" value="' . ie($d['host'] ?? 'localhost') . '" required></div>'
        . '<div><label>Naziv baze</label><input type="text" name="db_name" value="' . ie($d['name'] ?? '') . '" required></div></div>'
        . '<div class="row"><div><label>Korisnik</label><input type="text" name="db_user" value="' . ie($d['user'] ?? '') . '" required></div>'
        . '<div><label>Lozinka</label><input type="password" name="db_pass" value=""></div></div>'
        . '<div class="chk"><input type="checkbox" name="db_create" id="dbc" ' . (!empty($d['create']) ? 'checked' : '') . '><label for="dbc" style="margin:0;font-weight:400">Pokušaj kreirati bazu ako ne postoji (zahtijeva CREATE privilegiju — na shared hostingu bazu obično kreirate kroz cPanel)</label></div>'
        . '<button class="btn">Nastavi →</button></form>', $error);
    exit;
}

if ($step === '3') {
    $d = $S['djurdja'] ?? [];
    inst_layout('Korak 3 / 4 — MojaĐurđa račun', '<h2>Povežite trgovinu s đurđom</h2>'
        . '<p class="lead">Trgovina preuzima podatke o vašoj firmi, artikle i fiskalizira račune kroz vaš MojaĐurđa račun. API ključ kreirate u <strong>MojaĐurđa → Postavke → API pristup</strong>. Podaci o firmi se <u>ne mogu</u> unositi ručno — izvor istine je đurđa.</p>'
        . '<form method="post">' . inst_csrf_field() . '<input type="hidden" name="action" value="djurdja">'
        . '<label>API adresa</label><input type="url" name="api_base" value="' . ie($d['api_base'] ?? 'https://mojadjurdja.com/api/v1') . '">'
        . '<label>Key ID <span style="color:#9ca3af;font-weight:400">(pk_test_… ili pk_live_…)</span></label><input type="text" name="key_id" value="' . ie($d['key_id'] ?? '') . '" placeholder="pk_live_xxxxxxxx">'
        . '<label>Secret <span style="color:#9ca3af;font-weight:400">(sk_…, prikazuje se samo jednom pri kreiranju ključa)</span></label><input type="password" name="secret" placeholder="sk_xxxxxxxx">'
        . '<div class="chk"><input type="checkbox" name="mock" id="mck"><label for="mck" style="margin:0;font-weight:400"><strong>Razvojni način (mock)</strong> — bez pravog đurđa računa, s demo podacima. Samo za testiranje na lokalnom računalu!</label></div>'
        . '<button class="btn">Provjeri vezu i nastavi →</button></form>', $error);
    exit;
}

if ($step === '4') {
    $c = $S['company'] ?? [];
    $companyInfo = $c
        ? '<div class="ok">✓ Povezano: <strong>' . ie($c['companyName'] ?? '?') . '</strong> · OIB ' . ie($c['companyOib'] ?? '?') . ' · '
          . (!empty($c['inVatSystem']) ? 'u sustavu PDV-a' : 'nije u sustavu PDV-a')
          . (!empty($S['djurdja']['mock']) ? ' · <strong>MOCK</strong>' : '') . '</div>'
        : '';
    inst_layout('Korak 4 / 4 — Trgovina i administrator', '<h2>Osnovni podaci</h2>' . $companyInfo
        . '<form method="post">' . inst_csrf_field() . '<input type="hidden" name="action" value="finish">'
        . '<label>Naziv trgovine</label><input type="text" name="shop_name" value="' . ie($_POST['shop_name'] ?? ($c['companyName'] ?? '')) . '" required>'
        . '<label>E-mail trgovine (za narudžbe)</label><input type="email" name="shop_email" value="' . ie($_POST['shop_email'] ?? '') . '" required>'
        . '<hr style="border:0;border-top:1px solid #f3f4f6;margin:22px 0">'
        . '<div class="row"><div><label>Admin korisničko ime</label><input type="text" name="admin_user" value="' . ie($_POST['admin_user'] ?? '') . '" required></div>'
        . '<div><label>Admin e-mail</label><input type="email" name="admin_email" value="' . ie($_POST['admin_email'] ?? '') . '" required></div></div>'
        . '<div class="row"><div><label>Lozinka (min 8 znakova)</label><input type="password" name="admin_pass" required minlength="8"></div>'
        . '<div><label>Ponovi lozinku</label><input type="password" name="admin_pass2" required minlength="8"></div></div>'
        . '<button class="btn">Instaliraj trgovinu 🚀</button></form>', $error);
    exit;
}

// ── Korak 1: provjere ──
$checks = [];
$checks[] = ['PHP verzija ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION];
foreach (['pdo_mysql' => 'PDO MySQL', 'curl' => 'cURL', 'openssl' => 'OpenSSL', 'mbstring' => 'Multibyte string', 'json' => 'JSON'] as $ext => $label) {
    $checks[] = [$label, extension_loaded($ext), extension_loaded($ext) ? 'OK' : 'nedostaje'];
}
$gd = extension_loaded('gd');
$writables = [
    'config/' => is_writable($ROOT . '/config'),
    'uploads/' => is_writable($ROOT . '/uploads'),
    'logs/' => is_writable($ROOT . '/logs') || @mkdir($ROOT . '/logs', 0775, true),
    'install/' => is_writable(__DIR__),
];
$allOk = true;
$list = '';
foreach ($checks as [$label, $ok, $detail]) {
    if (!$ok) $allOk = false;
    $list .= '<li><span>' . ie($label) . '</span><span class="' . ($ok ? 'pass' : 'fail') . '">' . ie($detail) . ($ok ? ' ✓' : ' ✗') . '</span></li>';
}
$list .= '<li><span>GD (obrada slika)</span><span class="' . ($gd ? 'pass' : 'warn') . '">' . ($gd ? 'OK ✓' : 'preporučeno') . '</span></li>';
foreach ($writables as $dir => $ok) {
    if (!$ok) $allOk = false;
    $list .= '<li><span>Zapisivanje: <code>' . ie($dir) . '</code></span><span class="' . ($ok ? 'pass' : 'fail') . '">' . ($ok ? 'OK ✓' : 'nema dozvole ✗') . '</span></li>';
}
$btn = $allOk
    ? '<a class="btn" href="?step=2">Kreni s instalacijom →</a>'
    : '<div class="err">Riješite stavke označene ✗ pa osvježite stranicu.</div>';
inst_layout('Korak 1 / 4 — Provjera servera', '<h2>Dobrodošli! Provjerimo server.</h2>'
    . '<p class="lead">ĐurđaShop je besplatna web trgovina koja radi na bilo kojem PHP hostingu. Za rad je potreban aktivan <a href="https://mojadjurdja.com" target="_blank" rel="noopener">MojaĐurđa</a> račun — iz njega se povlače podaci o firmi i artikli te se fiskaliziraju računi.</p>'
    . '<ul class="req">' . $list . '</ul><p class="muted">Napomena: za lijepe URL-ove potreban je Apache <code>mod_rewrite</code> (na 99% hostinga već uključen).</p>' . $btn, $error);
