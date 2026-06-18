<?php
/**
 * Updater — one-click nadogradnja (kao WordPress), ali sigurno za shared hosting:
 *   1. maintenance mode (config/.maintenance) dok traje zamjena datoteka
 *   2. provjera verzije i preuzimanje paketa IZRAVNO s GitHuba (HTTPS, tvrdo kodiran repo);
 *      paket mora imati ispravan bootstrap koji NIJE stariji (downgrade/garbage zaštita)
 *   3. backup svake datoteke PRIJE prepisivanja
 *   4. ROLLBACK (vraćanje iz backupa) ako bilo što zapne
 *   5. stale-guard u bootstrapu sam ukloni maintenance ako proces padne
 *
 * Paket NE dira: config/config.php (tajne), uploads/ (sadržaj), logs/, backups/,
 * install/. GitHub (javni repo) već ima obfusciran app.js, pa nadogradnja povlači
 * točno ono što treba — bez ovisnosti o đurđa verzioniranju.
 */

class Updater
{
    /** Javni GitHub repo = izvor istine za verziju i paket (source-available). */
    private const GH_REPO   = 'sanyvrbovec/webshop-djurdja';
    private const GH_BRANCH = 'main';

    /** Stanje ažuriranja za prikaz u adminu (najnovija verzija se čita s GitHuba). */
    public static function status(): array
    {
        $cur    = SHOP_VERSION;
        $latest = self::latestFromGitHub();
        $newer  = $latest !== '' && version_compare($latest, $cur, '>');
        $cap    = self::capable();
        return [
            'current'     => $cur,
            'latest'      => $latest !== '' ? $latest : $cur,
            'newer'       => $newer,
            'capable'     => $cap,                               // true ili poruka greške
            'oneClick'    => $newer && $cap === true,
            'checkFailed' => $latest === '',
        ];
    }

    /** Najnovija verzija s GitHuba (SHOP_VERSION iz core/bootstrap.php na main grani). Keš 1 h. */
    private static function latestFromGitHub(): string
    {
        $cached = (string) Settings::get('gh_latest_version', '');
        $at = (int) strtotime((string) Settings::get('gh_latest_at', ''));
        if ($cached !== '' && $at && (time() - $at) < 3600) return $cached;
        $src = self::download('https://raw.githubusercontent.com/' . self::GH_REPO . '/' . self::GH_BRANCH . '/core/bootstrap.php');
        if ($src !== null && preg_match("/define\\('SHOP_VERSION',\\s*'([^']+)'\\)/", $src, $m)) {
            Settings::set('gh_latest_version', $m[1]);
            Settings::set('gh_latest_at', date('Y-m-d H:i:s'));
            return $m[1];
        }
        return $cached; // mreža pala → zadnje poznato (ili prazno)
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
        if (!$st['newer'])           return ['ok' => false, 'error' => 'Već koristite najnoviju verziju.'];
        if ($st['capable'] !== true) return ['ok' => false, 'error' => $st['capable']];

        $root    = SHOP_ROOT;
        $url     = 'https://codeload.github.com/' . self::GH_REPO . '/zip/refs/heads/' . self::GH_BRANCH;
        $maint   = $root . '/config/.maintenance';
        $work    = $root . '/backups/_upd_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $zipPath = $work . '/pkg.zip';

        if (!@mkdir($work, 0775, true) && !is_dir($work)) {
            return ['ok' => false, 'error' => 'Ne mogu kreirati radni direktorij u backups/ (provjerite dozvole).'];
        }

        @file_put_contents($maint, date('c')); // maintenance ON (vlastiti zahtjev je već prošao gate)
        try {
            $bytes = self::download($url);
            if ($bytes === null || strlen($bytes) < 1000) throw new RuntimeException('Preuzimanje paketa s GitHuba nije uspjelo.');
            if (@file_put_contents($zipPath, $bytes) === false) throw new RuntimeException('Ne mogu spremiti paket na disk.');
            unset($bytes);

            $res = self::applyPackage($zipPath, $root, $work); // extract + provjera verzije + backup + copy (+ rollback)
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
    public static function applyPackage(string $zipPath, string $root, string $work): array
    {
        $backupDir  = $work . '/backup';
        $extractDir = $work . '/new';
        @mkdir($backupDir, 0775, true);
        @mkdir($extractDir, 0775, true);
        $copied = [];
        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) throw new RuntimeException('Paket se ne može otvoriti (neispravan ZIP).');
            if (!$zip->extractTo($extractDir)) { $zip->close(); throw new RuntimeException('Raspakiravanje paketa nije uspjelo.'); }
            $zip->close();

            $srcRoot = self::resolveSrcRoot($extractDir);
            $files = [];
            self::collectFiles($srcRoot, '', $files);
            if (count($files) < 10) throw new RuntimeException('Paket izgleda nepotpun — nadogradnja prekinuta.');

            // Sigurnosna provjera (umjesto checksuma): paket MORA imati ispravan core/bootstrap.php
            // s verzijom koja NIJE starija od trenutne — sprječava garbage/downgrade/podvalu.
            // Povjerenje: HTTPS na github.com + tvrdo kodiran repo (nema korisničkog inputa).
            $pkgVer = self::detectVersion($srcRoot);
            if ($pkgVer === '?') throw new RuntimeException('Paket nema ispravan core/bootstrap.php — odbijen.');
            if (version_compare($pkgVer, SHOP_VERSION, '<')) throw new RuntimeException('Paket (' . $pkgVer . ') je stariji od trenutne (' . SHOP_VERSION . ') — odbijen.');

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
