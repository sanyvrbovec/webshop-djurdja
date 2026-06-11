<?php
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
echo "Disallow: " . BASE_URL . "/admin/\n";
echo "Disallow: " . BASE_URL . "/api/\n";
echo "Disallow: " . BASE_URL . "/install/\n";
echo "Disallow: " . BASE_URL . "/kosarica.php\n";
echo "Disallow: " . BASE_URL . "/narudzba.php\n";
echo "Disallow: " . BASE_URL . "/narudzba-potvrda.php\n";
echo "\nSitemap: " . SITE_URL . "/sitemap.xml\n";
