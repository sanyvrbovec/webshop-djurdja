<?php
/**
 * Storefront header. Stranica prije include-a postavlja:
 *   $pageTitle, $pageDesc, $pageCanonical?, $pageOgImage?, $pageType?, $pageJsonLd? (array stringova)
 */
$pageTitle = $pageTitle ?? '';
$pageDesc = $pageDesc ?? '';

// Prisila na update: đurđa centralno postavi minShopVersion → prestare
// instalacije zaključaju IZLOG (admin radi da vlasnik vidi uputu i ažurira)
if (Djurdja::versionBlocked()) {
    http_response_code(503);
    header('Retry-After: 3600');
    echo '<!doctype html><html lang="hr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>Trgovina se ažurira</title>'
        . '<body style="margin:0;font-family:system-ui,sans-serif;background:#f8fafc;display:grid;place-items:center;min-height:100vh">'
        . '<div style="max-width:480px;padding:40px;text-align:center"><div style="font-size:44px">🛠️</div>'
        . '<h1 style="font-size:22px;color:#0f172a">Trgovina se ažurira</h1>'
        . '<p style="color:#475569;font-size:15px;line-height:1.6">Vraćamo se vrlo brzo — hvala na strpljenju!</p>'
        . '<p style="color:#94a3b8;font-size:12px;line-height:1.6;border-top:1px solid #e2e8f0;padding-top:14px;margin-top:22px">'
        . 'Napomena za vlasnika: ova verzija trgovine više nije podržana i privremeno ne prima narudžbe. '
        . 'Preuzmite najnoviju verziju i zamijenite datoteke na serveru — upute u administraciji (Postavke).</p>'
        . '</div></body></html>';
    exit;
}

// Plan-gate: paket bez WEBSHOP prava → izlog zaključan (admin i dalje radi)
if (!Djurdja::shopAllowed()) {
    http_response_code(503);
    header('Retry-After: 3600');
    echo '<!doctype html><html lang="hr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>Trgovina trenutno nije dostupna</title>'
        . '<body style="margin:0;font-family:system-ui,sans-serif;background:#f8fafc;display:grid;place-items:center;min-height:100vh">'
        . '<div style="max-width:460px;padding:40px;text-align:center"><div style="font-size:44px">🔒</div>'
        . '<h1 style="font-size:22px;color:#0f172a">Trgovina trenutno nije dostupna</h1>'
        . '<p style="color:#475569;font-size:15px;line-height:1.6">Web trgovina nije uključena u trenutni paket vlasnika. '
        . 'Vlasniče, prijavite se na <a href="https://mojadjurdja.com/cjenik?utm_source=webshop&utm_medium=lock" style="color:#4f46e5">mojadjurdja.com</a> i nadogradite paket.</p>'
        . '</div></body></html>';
    exit;
}

$company = Djurdja::company();
$logoFile = s('logo');
$navPages = $db->fetchAll('SELECT slug, title FROM pages WHERE is_visible = 1 AND in_nav = 1 ORDER BY sort_order, id');
$blogActive = Djurdja::blogActive()
    && (int) $db->fetchColumn('SELECT COUNT(*) FROM blog_posts WHERE is_published = 1') > 0;
$checkoutOk = Djurdja::acceptsOrders();
$shopNotice = Djurdja::storefrontNotice();
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?><!doctype html>
<html lang="hr" data-base="<?= e(BASE_URL) ?>" data-accepts="<?= $checkoutOk ? '1' : '0' ?>"<?= $shopNotice ? ' data-notice="' . e($shopNotice['type']) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<?= Seo::meta($pageTitle, $pageDesc, $pageCanonical ?? null, $pageOgImage ?? null, $pageType ?? 'website') ?>
<?= Theme::head() ?>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<?php if ($logoFile): ?><link rel="icon" href="<?= e(upload_url('theme/' . $logoFile)) ?>"><?php endif; ?>
<?php foreach (($pageJsonLd ?? []) as $ld) echo $ld; ?>
<?= Seo::organizationJsonLd() ?>
</head>
<body>
<?php if ($shopNotice): ?>
<div class="shop-banner <?= $shopNotice['type'] === 'warn' ? 'warn' : 'danger' ?>" id="shop-notice"><?= e($shopNotice['text']) ?></div>
<?php endif; ?>

<header class="site-header">
  <div class="container">
    <div class="header-inner">
      <button class="icon-btn hamburger" data-toggle-nav aria-label="Izbornik">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <a href="<?= e(url('')) ?>" class="logo">
        <?php if ($logoFile): ?>
          <img src="<?= e(upload_url('theme/' . $logoFile)) ?>" alt="<?= e(shop_name()) ?>">
        <?php else: ?>
          <?= e(shop_name()) ?><span class="logo-dot">.</span>
        <?php endif; ?>
      </a>
      <nav class="main-nav">
        <a href="<?= e(url('')) ?>" <?= $currentScript === 'index.php' ? 'class="active"' : '' ?>>Početna</a>
        <a href="<?= e(url('proizvodi.php')) ?>" <?= in_array($currentScript, ['proizvodi.php', 'proizvod.php']) ? 'class="active"' : '' ?>>Proizvodi</a>
        <?php if ($blogActive): ?><a href="<?= e(url('blog')) ?>" <?= in_array($currentScript, ['blog.php', 'clanak.php']) ? 'class="active"' : '' ?>>Blog</a><?php endif; ?>
        <?php foreach ($navPages as $np): ?>
          <a href="<?= e(url('s/' . $np['slug'])) ?>"><?= e($np['title']) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(url('kontakt.php')) ?>" <?= $currentScript === 'kontakt.php' ? 'class="active"' : '' ?>>Kontakt</a>
      </nav>
      <div class="header-actions">
        <a class="icon-btn" href="<?= e(url(Customer::isLoggedIn() ? 'moj-racun.php' : 'prijava.php')) ?>" aria-label="Moj račun" title="<?= Customer::isLoggedIn() ? 'Moj račun' : 'Prijava' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </a>
        <button class="icon-btn" data-toggle-search aria-label="Pretraga">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
        </button>
        <button class="icon-btn" data-open-cart aria-label="Košarica">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          <span class="cart-badge" data-cart-count style="display:none">0</span>
        </button>
      </div>
    </div>
    <div class="search-bar">
      <form action="<?= e(url('proizvodi.php')) ?>" method="get">
        <input type="search" name="q" placeholder="Pretražite proizvode…" value="<?= e($_GET['q'] ?? '') ?>" maxlength="100">
        <button class="btn btn-sm">Traži</button>
      </form>
    </div>
  </div>
</header>

<main>
<?php foreach (take_flashes() as $f): ?>
  <div class="container"><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div></div>
<?php endforeach; ?>
