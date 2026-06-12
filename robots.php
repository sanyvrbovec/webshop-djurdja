<?php
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

$deny = static function (): string {
    return "Disallow: " . BASE_URL . "/admin/\n"
         . "Disallow: " . BASE_URL . "/api/\n"
         . "Disallow: " . BASE_URL . "/install/\n"
         . "Disallow: " . BASE_URL . "/kosarica.php\n"
         . "Disallow: " . BASE_URL . "/narudzba.php\n"
         . "Disallow: " . BASE_URL . "/narudzba-potvrda.php\n";
};

echo "# " . shop_name() . " — robots.txt\n";
echo "# AI sažetak trgovine (LLM-friendly): " . SITE_URL . "/llms.txt\n\n";

echo "User-agent: *\n" . $deny() . "\n";

// AI crawleri izričito dobrodošli (klasični + AI SEO)
foreach (['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-Web', 'anthropic-ai', 'PerplexityBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider'] as $bot) {
    echo "User-agent: $bot\n" . $deny() . "Allow: " . (BASE_URL === '' ? '/' : BASE_URL . '/') . "\n\n";
}

echo "Sitemap: " . SITE_URL . "/sitemap.xml\n";
