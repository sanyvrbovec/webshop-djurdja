<?php
/**
 * Storefront header. Stranica prije include-a postavlja:
 *   $pageTitle, $pageDesc, $pageCanonical?, $pageOgImage?, $pageType?, $pageJsonLd? (array stringova)
 */
$pageTitle = $pageTitle ?? '';
$pageDesc = $pageDesc ?? '';
$company = Djurdja::company();
$logoFile = s('logo');
$navPages = $db->fetchAll('SELECT slug, title FROM pages WHERE is_visible = 1 AND in_nav = 1 ORDER BY sort_order, id');
$checkoutOk = Djurdja::checkoutAllowed();
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?><!doctype html>
<html lang="hr" data-base="<?= e(BASE_URL) ?>">
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
<?php if (!$checkoutOk): ?>
<div class="shop-banner">⏸ Trgovina trenutno ne zaprima nove narudžbe. Razgledavanje je i dalje moguće — hvala na strpljenju!</div>
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
        <?php foreach ($navPages as $np): ?>
          <a href="<?= e(url('s/' . $np['slug'])) ?>"><?= e($np['title']) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(url('kontakt.php')) ?>" <?= $currentScript === 'kontakt.php' ? 'class="active"' : '' ?>>Kontakt</a>
      </nav>
      <div class="header-actions">
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
