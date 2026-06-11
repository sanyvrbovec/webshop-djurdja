<?php
/** XML sitemap — proizvodi, kategorije, stranice. */
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$u = function (string $loc, ?string $lastmod = null, string $prio = '0.6') {
    echo '<url><loc>' . e($loc) . '</loc>'
        . ($lastmod ? '<lastmod>' . date('Y-m-d', strtotime($lastmod)) . '</lastmod>' : '')
        . '<priority>' . $prio . '</priority></url>' . "\n";
};

$u(SITE_URL . '/', null, '1.0');
$u(SITE_URL . '/proizvodi.php', null, '0.9');

foreach ($db->fetchAll('SELECT slug, updated_at FROM categories WHERE is_visible = 1') as $c) {
    $u(SITE_URL . '/k/' . $c['slug'], $c['updated_at'], '0.8');
}
foreach ($db->fetchAll('SELECT slug, updated_at FROM products WHERE is_visible = 1 AND is_orphaned = 0') as $p) {
    $u(SITE_URL . '/p/' . $p['slug'], $p['updated_at'], '0.7');
}
foreach ($db->fetchAll('SELECT slug, updated_at FROM pages WHERE is_visible = 1') as $pg) {
    $u(SITE_URL . '/s/' . $pg['slug'], $pg['updated_at'], '0.4');
}
echo '</urlset>';
