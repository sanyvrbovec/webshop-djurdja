<?php
/** CMS stranica (?slug= ili /s/{slug}). */
require_once __DIR__ . '/core/bootstrap.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$page = $slug !== '' ? $db->fetch('SELECT * FROM pages WHERE slug = :s AND is_visible = 1', [':s' => $slug]) : null;
if (!$page) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$pageTitle = $page['seo_title'] ?: $page['title'];
$pageDesc = $page['seo_description'] ?: mb_substr(strip_tags($page['content'] ?? ''), 0, 300);
$pageCanonical = SITE_URL . '/s/' . $page['slug'];
$pageJsonLd = [Seo::breadcrumbJsonLd([['Početna', SITE_URL . '/'], [$page['title'], null]])];
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-content">
    <nav class="breadcrumbs" style="padding-left:0"><a href="<?= e(url('')) ?>">Početna</a><span class="sep">›</span><?= e($page['title']) ?></nav>
    <h1 style="margin-top:14px"><?= e($page['title']) ?></h1>
    <div class="content"><?= $page['content'] /* HTML uređuje vlasnik u adminu */ ?></div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
