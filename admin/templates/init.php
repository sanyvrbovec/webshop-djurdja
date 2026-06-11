<?php
/**
 * Admin init — bootstrap + auth guard.
 * Kad je đurđa veza "locked" (ključ povučen / shop suspendiran), dopuštene su
 * samo stranice za popravak veze — ovisnost o đurđi je uvjet rada administracije.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

$script = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (empty($_SESSION['admin_id'])) {
    redirect('admin/login.php');
}
$currentAdmin = $db->fetch('SELECT * FROM admin_users WHERE id = :id', [':id' => (int) $_SESSION['admin_id']]);
if (!$currentAdmin) {
    session_destroy();
    redirect('admin/login.php');
}

// Povremeno tiho osvježi đurđa keš (1:3 učitavanja admina)
if (random_int(1, 3) === 1) {
    Djurdja::maybeRefresh();
}

$djStatus = Djurdja::status();
if ($djStatus === 'locked' && !in_array($script, ['djurdja.php', 'logout.php'], true)) {
    redirect('admin/djurdja.php');
}

function adminUrl(string $path = ''): string
{
    return url('admin/' . ltrim($path, '/'));
}
