<?php
/**
 * Seo — meta tagovi, Open Graph, canonical i schema.org JSON-LD.
 * Podaci o organizaciji dolaze iz đurđe (izvor istine o firmi).
 */

class Seo
{
    /** Glavni meta blok za <head>. */
    public static function meta(string $title, string $description = '', ?string $canonical = null, ?string $ogImage = null, string $type = 'website'): string
    {
        $siteName = shop_name();
        $fullTitle = $title === '' ? $siteName : ($title . ' | ' . $siteName);
        $description = mb_substr(trim($description ?: (string) s('seo_default_description', 'Web trgovina s računom za svaku kupnju.')), 0, 300);
        $canonical = $canonical ?: (SITE_URL . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
        $ogImage = $ogImage ?: (s('logo') ? SITE_URL . '/uploads/theme/' . s('logo') : null);

        $out = '<title>' . e($fullTitle) . '</title>'
            . '<meta name="description" content="' . e($description) . '">'
            . '<link rel="canonical" href="' . e($canonical) . '">'
            . '<meta property="og:site_name" content="' . e($siteName) . '">'
            . '<meta property="og:title" content="' . e($fullTitle) . '">'
            . '<meta property="og:description" content="' . e($description) . '">'
            . '<meta property="og:url" content="' . e($canonical) . '">'
            . '<meta property="og:type" content="' . e($type) . '">'
            . '<meta property="og:locale" content="hr_HR">'
            . '<meta name="twitter:card" content="' . ($ogImage ? 'summary_large_image' : 'summary') . '">';
        if ($ogImage) {
            $out .= '<meta property="og:image" content="' . e($ogImage) . '">';
        }
        return $out;
    }

    private static function jsonLd(array $data): string
    {
        return '<script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>';
    }

    /** Organization — firma iz đurđe + link na mojadjurdja (sameAs mreža). */
    public static function organizationJsonLd(): string
    {
        $c = Djurdja::company();
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $c['companyName'] ?? shop_name(),
            'url'      => SITE_URL . '/',
        ];
        if (!empty($c['companyOib'])) {
            $data['taxID'] = 'HR' . $c['companyOib'];
            $data['identifier'] = $c['companyOib'];
        }
        if (s('logo')) {
            $data['logo'] = SITE_URL . '/uploads/theme/' . s('logo');
        }
        if (!empty($c['address'])) {
            $data['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $c['address'],
                'addressLocality' => $c['city'] ?? '',
                'postalCode' => $c['postalCode'] ?? '',
                'addressCountry' => 'HR',
            ];
        }
        return self::jsonLd($data);
    }

    public static function websiteJsonLd(): string
    {
        return self::jsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => shop_name(),
            'url'      => SITE_URL . '/',
            'potentialAction' => [
                '@type'  => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => SITE_URL . '/proizvodi.php?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ]);
    }

    public static function productJsonLd(array $p, array $images, string $url, ?bool $outOfStock = null): string
    {
        if ($outOfStock === null) {
            $outOfStock = ((int) $p['track_stock'] === 1 && (float) ($p['stock_qty'] ?? 0) <= 0);
        }
        $available = $outOfStock ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock';
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $p['name'],
            'description' => mb_substr(strip_tags($p['description'] ?: ($p['short_description'] ?? '')), 0, 500),
            'url'      => $url,
            'offers'   => [
                '@type' => 'Offer',
                'price' => number_format((float) $p['price'], 2, '.', ''),
                'priceCurrency' => 'EUR',
                'availability'  => $available,
                'url' => $url,
                'seller' => ['@type' => 'Organization', 'name' => Djurdja::company()['companyName'] ?? shop_name()],
            ],
        ];
        if (!empty($p['barcode'])) $data['gtin13'] = $p['barcode'];
        $data['sku'] = (string) ($p['id'] ?? '');
        if ($images) {
            $data['image'] = array_map(fn($img) => SITE_URL . '/uploads/products/' . $img['filename'], array_slice($images, 0, 5));
        }
        return self::jsonLd($data);
    }

    /** @param array<int, array{0: string, 1: ?string}> $items [naziv, url|null] */
    public static function breadcrumbJsonLd(array $items): string
    {
        $list = [];
        foreach ($items as $i => [$name, $u]) {
            $el = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name];
            if ($u) $el['item'] = $u;
            $list[] = $el;
        }
        return self::jsonLd(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list]);
    }
}
