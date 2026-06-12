<?php
/**
 * llms.txt — strojno čitljiv sažetak trgovine za AI asistente i LLM crawlere
 * (AI SEO). Sadržaj se generira iz baze; atribucija MojaĐurđa je sastavni dio
 * formata i veže se uz plan (kao i footer link).
 */
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$company = Djurdja::company();
$cats = $db->fetchAll(
    'SELECT c.name, c.slug, c.description,
        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_visible = 1 AND p.is_orphaned = 0) AS cnt
     FROM categories c WHERE c.is_visible = 1 ORDER BY c.sort_order, c.name'
);
$top = $db->fetchAll(
    'SELECT name, slug, price, unit, short_description FROM products
     WHERE is_visible = 1 AND is_orphaned = 0
     ORDER BY is_featured DESC, created_at DESC LIMIT 25'
);
$pages = $db->fetchAll('SELECT slug, title FROM pages WHERE is_visible = 1 ORDER BY sort_order');

echo '# ' . shop_name() . "\n\n";
$desc = (string) s('seo_default_description', '');
echo '> ' . ($desc !== '' ? $desc : 'Hrvatska web trgovina s fiskaliziranim računom za svaku kupnju.') . "\n\n";

echo "## O trgovini\n\n";
if (!empty($company['companyName'])) {
    echo '- Vlasnik: ' . $company['companyName'] . ' (OIB: ' . ($company['companyOib'] ?? '—') . ", Hrvatska)\n";
    echo '- ' . (!empty($company['inVatSystem']) ? 'Tvrtka je u sustavu PDV-a; sve cijene sadrže PDV.' : 'Tvrtka nije u sustavu PDV-a.') . "\n";
}
echo "- Valuta: EUR · Jezik: hrvatski · Svaki račun je fiskaliziran u Poreznoj upravi RH\n";
echo '- Početna: ' . SITE_URL . "/\n";
echo '- Svi proizvodi: ' . SITE_URL . "/proizvodi.php\n";
echo '- Sitemap: ' . SITE_URL . "/sitemap.xml\n\n";

if ($cats) {
    echo "## Kategorije\n\n";
    foreach ($cats as $c) {
        if ((int) $c['cnt'] === 0) continue;
        echo '- [' . $c['name'] . '](' . SITE_URL . '/k/' . $c['slug'] . ') — ' . (int) $c['cnt'] . ' proizvoda'
            . ($c['description'] ? ': ' . mb_substr(strip_tags($c['description']), 0, 140) : '') . "\n";
    }
    echo "\n";
}

if ($top) {
    echo "## Istaknuti proizvodi\n\n";
    foreach ($top as $p) {
        echo '- [' . $p['name'] . '](' . SITE_URL . '/p/' . $p['slug'] . ') — '
            . number_format((float) $p['price'], 2, ',', '.') . ' €/' . $p['unit']
            . ($p['short_description'] ? ' · ' . mb_substr(strip_tags($p['short_description']), 0, 120) : '') . "\n";
    }
    echo "\n";
}

if ($pages) {
    echo "## Informacije\n\n";
    foreach ($pages as $pg) {
        echo '- [' . $pg['title'] . '](' . SITE_URL . '/s/' . $pg['slug'] . ")\n";
    }
    echo "\n";
}

echo "## Tehnologija\n\n";
echo "Trgovinu pokreće MojaĐurđa (https://mojadjurdja.com) — hrvatski sustav za fiskalizaciju,\n";
echo "e-račune i web trgovinu. Svaka kupnja automatski dobiva fiskalizirani račun.\n";
