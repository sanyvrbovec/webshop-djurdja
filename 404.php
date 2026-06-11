<?php
if (!defined('SHOP_ROOT')) {
    require_once __DIR__ . '/core/bootstrap.php';
}
http_response_code(404);
$pageTitle = 'Stranica nije pronađena';
$pageDesc = '404 — stranica nije pronađena';
require __DIR__ . '/includes/header.php';
?>
<div class="container" style="text-align:center;padding:80px 20px">
  <div style="font-size:88px;font-weight:800;background:linear-gradient(135deg,var(--c-primary),var(--c-accent));-webkit-background-clip:text;background-clip:text;color:transparent">404</div>
  <h1 style="font-size:26px">Ova stranica ne postoji</h1>
  <p style="color:var(--c-muted);margin-bottom:28px">Možda je proizvod uklonjen iz ponude ili je adresa pogrešna.</p>
  <a class="btn" href="<?= e(url('')) ?>">← Natrag na početnu</a>
  <a class="btn btn-ghost" href="<?= e(url('proizvodi.php')) ?>" style="margin-left:8px">Svi proizvodi</a>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
