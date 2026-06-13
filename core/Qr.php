<?php
/**
 * Qr — generiranje QR koda za fiskalni račun iz verifikacijskog linka koji
 * đurđa API vrati nakon fiskalizacije (order.fiscal_qr).
 *
 * Lokalno, bez vanjskog servisa (phpqrcode/GD) — ništa se ne šalje trećoj
 * strani i radi i bez interneta. Zakonska veličina QR-a na računu je min
 * 2×2 cm; CSS-om se skalira na tu veličinu pri ispisu.
 */

class Qr
{
    private static bool $loaded = false;

    private static function load(): bool
    {
        if (self::$loaded) return true;
        if (!function_exists('imagecreate')) return false; // bez GD nema QR-a
        $lib = SHOP_ROOT . '/core/qrcode/qrlib.php';
        if (!is_file($lib)) return false;
        require_once $lib;
        self::$loaded = class_exists('QRcode');
        return self::$loaded;
    }

    /**
     * QR kao PNG datoteka u uploads/qr/ (cache po sadržaju), vraća javni URL
     * ili null. Za e-mail (klijent dohvati sliku s URL-a).
     */
    public static function url(string $text): ?string
    {
        if ($text === '' || !self::load()) return null;
        $dir = SHOP_ROOT . '/uploads/qr';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = 'qr-' . substr(sha1($text), 0, 16) . '.png';
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            try {
                QRcode::png($text, $path, QR_ECLEVEL_M, 5, 2);
            } catch (Throwable $e) {
                error_log('[Qr] ' . $e->getMessage());
                return null;
            }
        }
        return is_file($path) ? upload_url('qr/' . $name) : null;
    }

    /**
     * QR kao data:image/png;base64 — inline, offline-safe (za ispis računa).
     */
    public static function dataUri(string $text): ?string
    {
        if ($text === '' || !self::load()) return null;
        $dir = SHOP_ROOT . '/uploads/qr';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6)) . '.png';
        try {
            QRcode::png($text, $tmp, QR_ECLEVEL_M, 5, 2);
            $png = @file_get_contents($tmp);
            @unlink($tmp);
            return $png ? 'data:image/png;base64,' . base64_encode($png) : null;
        } catch (Throwable $e) {
            @unlink($tmp);
            error_log('[Qr] ' . $e->getMessage());
            return null;
        }
    }
}
