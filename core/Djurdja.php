<?php
/**
 * Djurdja — visoka razina veze shopa s mojadjurdja.com računom.
 *
 * Keš u settings tablici:
 *   djurdja_company      JSON /me podaci (naziv, OIB, PDV status) — IZVOR ISTINE o firmi
 *   djurdja_account      JSON /shop/account (plan, features, kvota, shopStatus)
 *   djurdja_last_ok_at   zadnji uspješan kontakt
 *   djurdja_key_invalid  '1' kad server vrati 401/403 (ključ povučen)
 *   djurdja_shop_status  'active' | 'suspended' (kill-switch s heartbeata)
 *
 * Status veze:
 *   connected  zadnji ok < 26 h — sve radi
 *   stale      26–72 h — sve radi, admin upozoren
 *   offline    > 72 h — checkout blokiran (nema fiskalizacije = nema prodaje), izlog radi
 *   locked     ključ nevaljan ili shop suspendiran — checkout blokiran + admin "poveži ponovno"
 */

class Djurdja
{
    public static function client(): ?DjurdjaClient
    {
        return DjurdjaClient::fromSettings();
    }

    /** Podaci o firmi (read-only, iz đurđe). */
    public static function company(): array
    {
        return Settings::getJson('djurdja_company');
    }

    /** Puni account snapshot (plan/features/kvota). */
    public static function account(): array
    {
        return Settings::getJson('djurdja_account');
    }

    public static function status(): string
    {
        if (Settings::get('djurdja_key_invalid') === '1') return 'locked';
        if (Settings::get('djurdja_shop_status', 'active') === 'suspended') return 'locked';
        $lastOk = strtotime((string) Settings::get('djurdja_last_ok_at', ''));
        if (!$lastOk) return 'locked';
        $h = (time() - $lastOk) / 3600;
        if ($h < 26) return 'connected';
        if ($h < 72) return 'stale';
        return 'offline';
    }

    /** Smije li se zaprimati narudžbe? */
    public static function checkoutAllowed(): bool
    {
        return self::shopAllowed() && in_array(self::status(), ['connected', 'stale'], true);
    }

    /**
     * Smije li trgovina uopće raditi na trenutnom đurđa paketu?
     * Backend može u plan_features staviti WEBSHOP=0 za neke planove →
     * svi shopovi na tom planu se zaključaju (izlog pokazuje obavijest).
     * Nepoznato/nedostupno = dopušteno (fail-open; free plan je za sada OK).
     */
    public static function shopAllowed(): bool
    {
        $f = self::account()['features'] ?? null;
        if (is_array($f) && array_key_exists('WEBSHOP', $f) && !$f['WEBSHOP']) {
            return false;
        }
        return true;
    }

    /**
     * Mora li shop prikazivati MojaĐurđa link u footeru?
     * Free plan ima INVOICE_FOOTER_LINK = enabled. Fail-closed: nepoznato → prikazuj.
     */
    public static function brandingRequired(): bool
    {
        $acc = self::account();
        if (!isset($acc['features']) || !is_array($acc['features'])) return true;
        if (!array_key_exists('INVOICE_FOOTER_LINK', $acc['features'])) return true;
        return (bool) $acc['features']['INVOICE_FOOTER_LINK'];
    }

    /**
     * Logo firme za RAČUN — samo plaćeni planovi (feature INVOICE_LOGO_PRINT),
     * URL dolazi iz đurđe (companyLogoUrl). Free plan → null (bez loga).
     */
    public static function receiptLogoUrl(): ?string
    {
        $acc = self::account();
        if (empty($acc['features']['INVOICE_LOGO_PRINT'])) return null;
        $url = self::company()['logoUrl'] ?? null;
        return ($url && preg_match('#^https://#', (string) $url)) ? (string) $url : null;
    }

    /** Smije li korisnik koristiti vlastiti CSS / predloške? (plaćeni plan) */
    public static function customizationAllowed(): bool
    {
        return !self::brandingRequired();
    }

    /** Blog: plaćeni plan + korisnikov prekidač (na free uvijek ugašen). */
    public static function blogActive(): bool
    {
        return self::customizationAllowed() && Settings::get('blog_enabled', '1') === '1';
    }

    /**
     * Promo traka (free plan): sadržaj se centralizirano uređuje u đurđi
     * (Webshop upravljanje) i povlači jednom dnevno. Fallback na zadani
     * tekst dok backend nema /shop/promo.
     */
    public static function promo(): array
    {
        $p = Settings::getJson('djurdja_promo');
        $enabled = !array_key_exists('enabled', $p) || !empty($p['enabled']);
        $text = trim((string) ($p['text'] ?? ''));
        $url = (string) ($p['url'] ?? '');
        if ($text === '') {
            $text = 'Ovu trgovinu pokreće MojaĐurđa — fiskalna blagajna, e-računi i BESPLATNA web trgovina. Otvorite i vi svoju! 🛍️';
        }
        if (!preg_match('#^https://#', $url)) {
            $url = 'https://mojadjurdja.com/?utm_source=webshop&utm_medium=promobar&ref=' . rawurlencode($_SERVER['HTTP_HOST'] ?? '');
        }
        return ['enabled' => $enabled, 'text' => $text, 'url' => $url];
    }

    /** Dnevno povlačenje promo sadržaja s đurđe (best-effort). */
    private static function maybePromo(DjurdjaClient $client): void
    {
        $last = strtotime((string) Settings::get('djurdja_promo_at', ''));
        if ($last && (time() - $last) < 24 * 3600) return;
        try {
            $p = $client->promo();
            Settings::setJson('djurdja_promo', [
                'enabled' => !empty($p['enabled']),
                'text'    => mb_substr((string) ($p['text'] ?? ''), 0, 300),
                'url'     => mb_substr((string) ($p['url'] ?? ''), 0, 300),
            ]);
            Settings::set('djurdja_promo_at', date('Y-m-d H:i:s'));
        } catch (DjurdjaApiException $e) {
            if ($e->httpStatus === 404) Settings::set('djurdja_promo_at', date('Y-m-d H:i:s')); // backend bez rute — ne pokušavaj stalno
        } catch (Throwable $e) {
            error_log('[Djurdja::promo] ' . $e->getMessage());
        }
    }

    /** Kvota dokumenata: ['used'=>..,'limit'=>..,'periodEnd'=>..] ili null (unlimited/nepoznato). */
    public static function quota(): ?array
    {
        $u = self::account()['usage']['DOCUMENT_CREATE'] ?? null;
        if (!is_array($u) || !isset($u['limit'])) return null;
        return $u;
    }

    public static function planName(): string
    {
        return self::account()['plan']['name'] ?? '— (nepoznato)';
    }

    /** Đurđa server još nema deployan shop modul (/shop/* rute vraćaju 404)? */
    public static function shopModuleMissing(): bool
    {
        return Settings::get('djurdja_shop_module_missing') === '1';
    }

    /**
     * Osvježi account/company keš s đurđe. Vraća true na uspjeh.
     * 401/403 → key_invalid. /shop/account 404 (backend bez shop modula) → fallback na /me.
     */
    public static function refresh(bool $force = false): bool
    {
        $lastOk = strtotime((string) Settings::get('djurdja_last_ok_at', ''));
        if (!$force && $lastOk && (time() - $lastOk) < 6 * 3600) {
            return true; // svjež keš
        }
        $client = self::client();
        if (!$client) return false;

        try {
            try {
                $acc = $client->account();
                Settings::setJson('djurdja_account', $acc);
                if (!empty($acc['company'])) {
                    Settings::setJson('djurdja_company', $acc['company']);
                }
                Settings::set('djurdja_shop_status', $acc['shopStatus'] ?? 'active');
                if (!empty($acc['latestShopVersion'])) {
                    Settings::set('djurdja_latest_version', $acc['latestShopVersion']);
                }
                Settings::set('djurdja_shop_module_missing', '0');
            } catch (DjurdjaApiException $e) {
                // Backend još nema /shop/* rute (deploy nije odrađen) → koristi /me,
                // OBAVEZNO očisti stari (možda mock) account keš da admin ne laže
                if ($e->httpStatus === 404) {
                    $me = $client->me();
                    Settings::setJson('djurdja_company', $me);
                    Settings::setJson('djurdja_account', []);
                    Settings::set('djurdja_shop_module_missing', '1');
                } else {
                    throw $e;
                }
            }
            Settings::set('djurdja_company_synced_at', date('Y-m-d H:i:s'));
            Settings::set('djurdja_last_ok_at', date('Y-m-d H:i:s'));
            Settings::set('djurdja_key_invalid', '0');
            Settings::set('djurdja_last_error', null);
            self::maybeQuotaWarning();
            self::maybeHeartbeat($client);
            self::maybePromo($client);
            return true;
        } catch (DjurdjaApiException $e) {
            if (in_array($e->httpStatus, [401, 403], true)) {
                Settings::set('djurdja_key_invalid', '1');
            }
            Settings::set('djurdja_last_error', mb_substr($e->getMessage(), 0, 300));
            error_log('[Djurdja::refresh] ' . $e->getMessage());
            return false;
        } catch (Throwable $e) {
            Settings::set('djurdja_last_error', mb_substr($e->getMessage(), 0, 300));
            error_log('[Djurdja::refresh] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Upozorenje vlasniku kad je kvota pri kraju (≤10% ili ≤3 dokumenta) —
     * šalje se VLASNIKOVIM mailerom (njegov resurs), najviše jednom po periodu.
     */
    private static function maybeQuotaWarning(): void
    {
        try {
            $q = self::quota();
            if (!$q) return;
            $limit = (int) $q['limit'];
            $remaining = $limit - (int) $q['used'];
            if ($remaining > max(3, (int) ceil($limit * 0.10))) return;

            $period = (string) ($q['periodEnd'] ?? date('Y-m'));
            if (Settings::get('quota_warned_for') === $period) return;
            Settings::set('quota_warned_for', $period);

            $to = Settings::get('shop_email', '');
            if (!$to) return;
            Mailer::send(
                $to,
                '⚠ Kvota fiskalizacije je pri kraju — ' . shop_name(),
                '<h2 style="margin:0 0 8px">Još ' . max(0, $remaining) . ' od ' . $limit . ' računa ovaj mjesec</h2>'
                . '<p>Vaš MojaĐurđa paket je gotovo iskorišten. Kad se kvota potroši, '
                . 'web trgovina <strong>ne može fiskalizirati nove narudžbe</strong> do početka novog razdoblja.</p>'
                . '<p><a href="https://mojadjurdja.com/cjenik?utm_source=webshop&utm_medium=email&utm_campaign=quota" '
                . 'style="background:#1f2937;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Nadogradite paket</a></p>'
            );
        } catch (Throwable $e) {
            error_log('[Djurdja::quotaWarning] ' . $e->getMessage());
        }
    }

    /**
     * Heartbeat (registracija u đurđi). 24-satna brana vrijedi SAMO nakon
     * uspješne registracije — neuspjeli pokušaji (404 prije deploya rute,
     * 500 bez api_shops tablice…) se ponavljaju na svakom refreshu dok ne
     * prođe, pa se trgovina sama pojavi u đurđi čim server bude spreman.
     */
    private static function maybeHeartbeat(DjurdjaClient $client): void
    {
        $last = strtotime((string) Settings::get('djurdja_heartbeat_at', ''));
        if (Settings::get('djurdja_heartbeat_ok') === '1' && $last && (time() - $last) < 24 * 3600) return;
        try {
            $resp = $client->heartbeat([
                'domain'   => $_SERVER['HTTP_HOST'] ?? php_uname('n'),
                'baseUrl'  => defined('SITE_URL') ? SITE_URL : '',
                'version'  => SHOP_VERSION,
                'shopName' => Settings::get('shop_name', ''),
            ]);
            if (!empty($resp['status'])) {
                Settings::set('djurdja_shop_status', $resp['status']);
            }
            Settings::set('djurdja_heartbeat_at', date('Y-m-d H:i:s'));
            Settings::set('djurdja_heartbeat_ok', '1');
        } catch (Throwable $e) {
            Settings::set('djurdja_heartbeat_ok', '0');
            error_log('[Djurdja::heartbeat] ' . $e->getMessage());
        }
    }

    /** Lagani lazy refresh — zovi s admin stranica; tih, ne ruši stranicu. */
    public static function maybeRefresh(): void
    {
        try {
            self::refresh(false);
        } catch (Throwable $e) {
            error_log('[Djurdja::maybeRefresh] ' . $e->getMessage());
        }
    }
}
