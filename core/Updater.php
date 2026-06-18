<?php
/**
 * Updater — one-click nadogradnja (kao WordPress), ali sigurno za shared hosting:
 *   1. maintenance mode (config/.maintenance) dok traje zamjena datoteka
 *   2. preuzimanje paketa s URL-a koji daje ĐURĐA (+ OBAVEZNA SHA-256 provjera)
 *   3. backup svake datoteke PRIJE prepisivanja
 *   4. ROLLBACK (vraćanje iz backupa) ako bilo što zapne
 *   5. stale-guard u bootstrapu sam ukloni maintenance ako proces padne
 *
 * Paket NE dira: config/config.php (tajne), uploads/ (sadržaj), logs/, backups/,
 * install/ (da se ne re-otvori instalacija). Đurđa kontrolira što i odakle se
 * preuzima (URL + checksum) — supply-chain povjerenje je na đurđa strani.
 */

class Updater
{
    /** Stanje ažuriranja za prikaz u adminu. */
    public static function status(): array
    {
        $cur    = SHOP_VERSION;
        $latest = (string) Settings::get('djurdja_latest_version', '');
        $url    = (string) Settings::get('djurdja_download_url', '');
        $sha    = (string) Settings::get('djurdja_download_sha256', '');
        $newer  = $latest !== '' && version_compare($latest, $cur, '>');
        $cap    = self::capable();
        return [
            'current'  => $cur,
            'latest'   => $latest,
            'newer'    => $newer,
            'hasPkg'   => $url !== '' && $sha !== '',
            'capable'  => $cap,                                  // true ili poruka greške
            'oneClick' => $newer && $url !== '' && $sha !== '' && $cap === true,
        ];
    }

    /** Preduvjeti za one-click. Vrati true ili ljudski čitljivu poruku. */
    public static function capable()
    {
        if (!class_exists('ZipArchive')) return 'Hosting nema PHP ZipArchive — automatska nadogradnja nije moguća (ažurirajte ručno).';
        if (!is_writable(SHOP_ROOT))     return 'Datoteke trgovine nisu zapisive (dozvole) — automatska nadogradnja nije moguća.';
        return true;
    }

    /** Izvrši nadogradnju. Nikad ne baca — vrati ['ok'=>bool, ...]. */
    public static function run(): array
    {
        $st = self::status();
        if (!$st['newer'])          return ['ok' => false, 'error' => 'Već koristite najnoviju verziju.'];
        if ($st['capable'] !== true) return ['ok' => false, 'error' => $st['capable']];
        if (!$st['hasPkg'])         return ['ok' => false, 'error' => 'Đurđa još nije objavila paket za automatsku nadogradnju — ažurirajte ručno.'];

        $root      = SHOP_ROOT;
        $url       = (string) Settings::get('djurdja_download_url', '');
        $expectSha = (string) Settings::get('djurdja_download_sha256', '');
        $maint     = $root . '/config/.maintenance';
        $work      = $root . '/backups/_upd_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $zipPath   = $work . '/pkg.zip';

        if (!@mkdir($work, 0775, true) && !is_dir($work)) {
            return ['ok' => false, 'error' => 'Ne mogu kreirati radni direktorij u backups/ (provjerite dozvole).'];
        }

        @file_put_contents($maint, date('c')); // maintenance ON (vlastiti zahtjev je već prošao gate)
        try {
            $bytes = self::download($url);
            if ($bytes === null || strlen($bytes) < 100) throw new RuntimeException('Preuzimanje paketa nije uspjelo.');
            if (@file_put_contents($zipPath, $bytes) === false) throw new RuntimeException('Ne mogu spremiti paket na disk.');
            unset($bytes);

            $res = self::applyPackage($zipPath, $expectSha, $root, $work); // verify + extract + backup + copy (+ rollback)
            @unlink($maint); // maintenance OFF
            if (!$res['ok']) {
                try { Audit::log('shop_update_failed', ['detail' => mb_substr((string) $res['error'], 0, 200)]); } catch (Throwable $e) {}
                return $res;
            }
            try { Migrations::ensure(); } catch (Throwable $e) { error_log('[Updater] migrate: ' . $e->getMessage()); }
            try { Audit::log('shop_updated', ['detail' => $st['current'] . ' → ' . $res['version']]); } catch (Throwable $e) {}
            self::rrmdir($work); // uspjeh → backup više ne treba
            return ['ok' => true, 'from' => $st['current'], 'version' => $res['version']];
        } catch (Throwable $e) {
            @unlink($maint); // maintenance OFF i na neuspjeh
            error_log('[Updater] ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Provjeri (SHA-256) → raspakiraj → zamijeni datoteke UZ backup; na BILO KOJU
     * grešku vrati sve prepisane datoteke iz backupa (rollback). Izdvojeno radi
     * testiranja (može se pozvati nad sintetičkim paketom i temp korijenom).
     */
    public static function applyPackage(string $zipPath, string $expectSha, string $root, string $work): array
    {
        $backupDir  = $work . '/backup';
        $extractDir = $work . '/new';
        @mkdir($backupDir, 0775, true);
        @mkdir($extractDir, 0775, true);
        $copied = [];
        try {
            // SHA-256 OBAVEZNO — bez ispravne provjere NE diramo nijednu datoteku
            $expectSha = strtolower(trim($expectSha));
            if ($expectSha === '' || !hash_equals($expectSha, strtolower((string) hash_file('sha256', $zipPath)))) {
                throw new RuntimeException('Sigurnosna provjera paketa (SHA-256) nije prošla — paket odbijen, ništa nije promijenjeno.');
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) throw new RuntimeException('Paket se ne može otvoriti (neispravan ZIP).');
            if (!$zip->extractTo($extractDir)) { $zip->close(); throw new RuntimeException('Raspakiravanje paketa nije uspjelo.'); }
            $zip->close();

            $srcRoot = self::resolveSrcRoot($extractDir);
            $files = [];
            self::collectFiles($srcRoot, '', $files);
            if (count($files) < 5) throw new RuntimeException('Paket izgleda nepotpuno — nadogradnja prekinuta.');

            foreach ($files as $rel) {
                if (self::isProtected($rel)) continue;
                $src = $srcRoot . '/' . $rel;
                $dst = $root . '/' . $rel;
                $existed = is_file($dst);
                if ($existed) {
                    $bdst = $backupDir . '/' . $rel;
                    if (!@mkdir(dirname($bdst), 0775, true) && !is_dir(dirname($bdst))) throw new RuntimeException('Backup mapa nije kreirana: ' . $rel);
                    if (!@copy($dst, $bdst)) throw new RuntimeException('Backup datoteke nije uspio: ' . $rel);
                }
                if (!@mkdir(dirname($dst), 0775, true) && !is_dir(dirname($dst))) throw new RuntimeException('Ciljna mapa nije kreirana: ' . $rel);
                if (!@copy($src, $dst)) throw new RuntimeException('Kopiranje nije uspjelo: ' . $rel);
                $copied[] = ['rel' => $rel, 'existed' => $existed];
            }
            return ['ok' => true, 'version' => self::detectVersion($root), 'copied' => count($copied)];
        } catch (Throwable $e) {
            // ROLLBACK (obrnutim redom): prepisane vrati iz backupa, novostvorene obriši → čisto staro stanje
            $restored = 0;
            foreach (array_reverse($copied) as $c) {
                $dst = $root . '/' . $c['rel'];
                if ($c['existed']) { if (@copy($backupDir . '/' . $c['rel'], $dst)) $restored++; }
                else { @unlink($dst); }
            }
            error_log('[Updater] rollback (' . $restored . '/' . count($copied) . ' vraćeno): ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage() . ' Promjene su poništene (rollback).'];
        }
    }

    // ── helpers ──

    private static function download(string $url): ?string
    {
        if (!preg_match('#^https://#i', $url)) return null; // samo HTTPS
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 180,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'DjurdjaShop-Updater/' . SHOP_VERSION,
            ]);
            $data = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($data !== false && $code >= 200 && $code < 300) ? $data : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 180], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? null : $data;
    }

    /** Ako extract sadrži točno jedan poddirektorij (i ništa drugo), uđi u njega. */
    private static function resolveSrcRoot(string $dir): string
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($dir . '/' . $entries[0])) {
            return $dir . '/' . $entries[0];
        }
        return $dir;
    }

    private static function collectFiles(string $base, string $rel, array &$out): void
    {
        $dir = $rel === '' ? $base : $base . '/' . $rel;
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            $childRel = $rel === '' ? $name : $rel . '/' . $name;
            $full = $base . '/' . $childRel;
            if (is_dir($full)) self::collectFiles($base, $childRel, $out);
            else $out[] = $childRel;
        }
    }

    /** Datoteke/mape koje nadogradnja NIKAD ne dira. */
    private static function isProtected(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === 'config/config.php') return true;            // tajne
        foreach (['uploads/', 'logs/', 'backups/', 'install/', '.git/', 'dev-tools/', 'tools/', 'docs/'] as $p) {
            if (strpos($rel, $p) === 0) return true;
        }
        return false;
    }

    /** Pročitaj SHOP_VERSION iz (novog) bootstrap.php. */
    private static function detectVersion(string $root): string
    {
        $src = (string) @file_get_contents($root . '/core/bootstrap.php');
        return preg_match("/define\\('SHOP_VERSION',\\s*'([^']+)'\\)/", $src, $m) ? $m[1] : '?';
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $p = $dir . '/' . $f;
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
