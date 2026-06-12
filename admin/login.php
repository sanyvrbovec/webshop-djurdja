<?php
/** Admin prijava — rate limit + lockout na povučen đurđa ključ rješava init nakon prijave. */
require_once __DIR__ . '/../core/bootstrap.php';

if (!empty($_SESSION['admin_id'])) redirect('admin/');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $rlKey = 'login:' . client_ip();

    if (!Security::rateLimit($rlKey, 5, 900)) {
        $error = 'Previše neuspjelih pokušaja. Pokušajte za 15 minuta.';
    } else {
        $admin = $db->fetch('SELECT * FROM admin_users WHERE username = :u', [':u' => $username]);
        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $db->update('admin_users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $admin['id']]);
            Security::clearAttempts($rlKey);
            Djurdja::maybeRefresh();
            redirect('admin/');
        }
        Security::recordAttempt($rlKey);
        $error = 'Pogrešno korisničko ime ili lozinka.';
    }
}
?><!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Prijava · <?= e(shop_name()) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="adm-brand" style="color:#1f2330;padding-bottom:8px"><span class="b">Đ</span> <?= e(shop_name()) ?></div>
    <p style="color:#8b90a0;font-size:13px;margin:0 0 18px">Prijava u administraciju trgovine</p>
    <?php foreach (take_flashes() as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <?php if (Settings::get('djurdja_key_id') && !(defined('DJURDJA_MOCK') && DJURDJA_MOCK)): ?>
      <a href="<?= e(url('admin/sso.php')) ?>" class="abtn" style="width:100%;justify-content:center;padding:12px;background:#7c3aed;margin-bottom:6px">
        Đ&nbsp; Prijava preko MojaĐurđa (bez lozinke)
      </a>
      <div style="display:flex;align-items:center;gap:10px;margin:12px 0;color:#c3c8d4;font-size:11px">
        <span style="flex:1;height:1px;background:#e5e7eb"></span> ili lozinkom <span style="flex:1;height:1px;background:#e5e7eb"></span>
      </div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label class="al">Korisničko ime</label>
      <input class="ainput" name="username" required autofocus autocomplete="username">
      <label class="al">Lozinka</label>
      <input class="ainput" type="password" name="password" required autocomplete="current-password">
      <button class="abtn" style="width:100%;margin-top:18px;justify-content:center;padding:12px">Prijavi se</button>
    </form>
    <p style="font-size:11.5px;color:#9ca3af;margin:16px 0 0;text-align:center">Pokreće <a href="https://mojadjurdja.com" target="_blank" rel="noopener">MojaĐurđa</a></p>
  </div>
</div>
</body>
</html>
