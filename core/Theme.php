<?php
/**
 * Theme — sustav tema: preseti + prilagodbe vlasnika → CSS custom properties.
 * Sve se renderira server-side inline u <head> (nula dodatnih requesta).
 */

class Theme
{
    public const PRESETS = [
        'djurdja' => [
            'label' => 'Đurđa (ljubičasta)', 'dark' => false,
            'primary' => '#7c3aed', 'primary2' => '#a855f7', 'accent' => '#f59e0b',
            'bg' => '#faf9fc', 'surface' => '#ffffff', 'text' => '#1f2130', 'muted' => '#6b7280', 'border' => '#e9e7f0',
        ],
        'smaragd' => [
            'label' => 'Smaragd (zelena)', 'dark' => false,
            'primary' => '#059669', 'primary2' => '#10b981', 'accent' => '#f59e0b',
            'bg' => '#f7faf9', 'surface' => '#ffffff', 'text' => '#13251e', 'muted' => '#5c6b66', 'border' => '#e2ece8',
        ],
        'more' => [
            'label' => 'More (plava)', 'dark' => false,
            'primary' => '#0369a1', 'primary2' => '#0ea5e9', 'accent' => '#f97316',
            'bg' => '#f6fafc', 'surface' => '#ffffff', 'text' => '#0f2533', 'muted' => '#5b7282', 'border' => '#dfeaf1',
        ],
        'terakota' => [
            'label' => 'Terakota (topla)', 'dark' => false,
            'primary' => '#c2410c', 'primary2' => '#ea580c', 'accent' => '#0d9488',
            'bg' => '#fcf9f7', 'surface' => '#ffffff', 'text' => '#2b1d16', 'muted' => '#7a6a61', 'border' => '#f0e6df',
        ],
        'ponoc' => [
            'label' => 'Ponoć (tamna)', 'dark' => true,
            'primary' => '#38bdf8', 'primary2' => '#818cf8', 'accent' => '#fbbf24',
            'bg' => '#0f172a', 'surface' => '#1e293b', 'text' => '#e2e8f0', 'muted' => '#94a3b8', 'border' => '#2e3d54',
        ],
        'cistoca' => [
            'label' => 'Čistoća (minimal)', 'dark' => false,
            'primary' => '#111827', 'primary2' => '#374151', 'accent' => '#2563eb',
            'bg' => '#ffffff', 'surface' => '#ffffff', 'text' => '#111827', 'muted' => '#6b7280', 'border' => '#e5e7eb',
        ],
    ];

    public const FONT_PAIRS = [
        'system'   => ['label' => 'Sistemski (najbrže)', 'head' => null, 'body' => null],
        'inter'    => ['label' => 'Inter (moderno)', 'head' => 'Inter', 'body' => 'Inter',
                       'url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap'],
        'playfair' => ['label' => 'Playfair + Inter (elegantno)', 'head' => 'Playfair Display', 'body' => 'Inter',
                       'url' => 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;800&family=Inter:wght@400;600&display=swap'],
        'space'    => ['label' => 'Space Grotesk (tehno)', 'head' => 'Space Grotesk', 'body' => 'Space Grotesk',
                       'url' => 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&display=swap'],
    ];

    /**
     * Efektivna tema: preset + vlasnikove prilagodbe.
     * $enforcePlan=true (storefront): na BESPLATNOM planu sve prilagodbe se
     * ignoriraju i vraća se zadana tema — postavke se NE BRIŠU, pa se kod
     * nadogradnje plana sve odmah vraća kako je korisnik podesio.
     * dizajn.php zove get(false) da u admin formi prikaže spremljene vrijednosti.
     */
    public static function get(bool $enforcePlan = true): array
    {
        if ($enforcePlan && !Djurdja::customizationAllowed()) {
            $t = self::PRESETS['djurdja'];
            $t['preset'] = 'djurdja';
            $t['font_pair'] = 'system';
            $t['radius'] = 'soft';
            $t['custom_css'] = '';
            return $t;
        }
        $cfg = Settings::getJson('theme');
        $presetKey = $cfg['preset'] ?? 'djurdja';
        $preset = self::PRESETS[$presetKey] ?? self::PRESETS['djurdja'];
        $t = array_merge($preset, array_filter([
            'primary'  => self::hex($cfg['primary'] ?? null),
            'primary2' => self::hex($cfg['primary2'] ?? null),
            'accent'   => self::hex($cfg['accent'] ?? null),
        ]));
        $t['preset'] = $presetKey;
        $t['font_pair'] = isset(self::FONT_PAIRS[$cfg['font_pair'] ?? '']) ? $cfg['font_pair'] : 'system';
        $t['radius'] = in_array($cfg['radius'] ?? '', ['none', 'soft', 'round'], true) ? $cfg['radius'] : 'soft';
        $t['custom_css'] = (string) ($cfg['custom_css'] ?? '');
        return $t;
    }

    private static function hex(?string $v): ?string
    {
        return ($v && preg_match('/^#[0-9a-f]{6}$/i', $v)) ? $v : null;
    }

    /** Inline <style> s CSS varijablama + Google Fonts linkovi (samo ako nisu sistemski). */
    public static function head(): string
    {
        $t = self::get();
        $fp = self::FONT_PAIRS[$t['font_pair']];
        $radius = ['none' => '2px', 'soft' => '14px', 'round' => '24px'][$t['radius']];

        $sys = "system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',sans-serif";
        $fontHead = $fp['head'] ? "'{$fp['head']}',$sys" : $sys;
        $fontBody = $fp['body'] ? "'{$fp['body']}',$sys" : $sys;

        $out = '';
        if (!empty($fp['url'])) {
            $out .= '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                  . '<link rel="stylesheet" href="' . e($fp['url']) . '">';
        }
        $out .= '<style>:root{'
            . '--c-primary:' . $t['primary'] . ';--c-primary-2:' . $t['primary2'] . ';--c-accent:' . $t['accent'] . ';'
            . '--c-bg:' . $t['bg'] . ';--c-surface:' . $t['surface'] . ';--c-text:' . $t['text'] . ';'
            . '--c-muted:' . $t['muted'] . ';--c-border:' . $t['border'] . ';'
            . '--radius:' . $radius . ';--font-head:' . $fontHead . ';--font-body:' . $fontBody . ';'
            . '--is-dark:' . ($t['dark'] ? '1' : '0') . ';}'
            . ($t['dark'] ? 'html{color-scheme:dark}' : '')
            . '</style>';
        // Vlastiti CSS je pogodnost PLAĆENOG plana — server-side enforcement
        // (i ako je spremljen ranije, na besplatnom planu se ne isporučuje).
        if ($t['custom_css'] !== '' && Djurdja::customizationAllowed()) {
            $out .= '<style>/* custom */' . str_replace('</', '<\/', $t['custom_css']) . '</style>';
        }
        return $out;
    }

    /** Hero konfiguracija s defaultima. */
    public static function hero(): array
    {
        $h = Settings::getJson('hero');
        return [
            'style'    => in_array($h['style'] ?? '', ['gradient', 'image', 'minimal'], true) ? $h['style'] : 'gradient',
            'eyebrow'  => array_key_exists('eyebrow', $h) ? (string) $h['eyebrow'] : '✓ Fiskalizirani račun uz svaku kupnju',
            'title'    => $h['title'] ?? '',
            'subtitle' => $h['subtitle'] ?? 'Provjerena kvaliteta, brza dostava i račun za svaku kupnju.',
            'cta_text' => $h['cta_text'] ?? 'Razgledaj ponudu',
            'cta_link' => $h['cta_link'] ?? url('proizvodi.php'),
            'image'    => $h['image'] ?? null,
            'align'    => in_array($h['align'] ?? '', ['center', 'left'], true) ? $h['align'] : 'left',
            'overlay'  => max(0, min(85, (int) ($h['overlay'] ?? 45))), // zatamnjenje slike u %
            'height'   => in_array($h['height'] ?? '', ['compact', 'normal', 'full'], true) ? $h['height'] : 'normal',
            'parallax' => !empty($h['parallax']) ? 1 : 0,
        ];
    }

    /** Uključene sekcije naslovnice. */
    public static function sections(): array
    {
        $s = Settings::getJson('sections');
        return [
            'categories' => $s['categories'] ?? true,
            'featured'   => $s['featured'] ?? true,
            'usp'        => $s['usp'] ?? true,
            'newsletter' => $s['newsletter'] ?? true,
        ];
    }
}
