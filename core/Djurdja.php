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

    /** Kvota dokumenata: ['used'=>..,'limit'=>..,'periodEnd'=>..] ili null (unlimited/nepoznato). */
    public static function quota(): ?array
    {
        $u = self::account()['usage']['DOCUMENT_CREATE'] ?? null;
        if (!is_array($u) || !isset($u['limit'])) return null;
        return $u;
    }

    public static function planName(): string
    {
        return self::account()['plan']['name'] ?? 'Besplatni';
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
            } catch (DjurdjaApiException $e) {
                // Backend možda još nema /shop/account (stariji deploy) → koristi /me
                if ($e->httpStatus === 404) {
                    $me = $client->me();
                    Settings::setJson('djurdja_company', $me);
                } else {
                    throw $e;
                }
            }
            Settings::set('djurdja_company_synced_at', date('Y-m-d H:i:s'));
            Settings::set('djurdja_last_ok_at', date('Y-m-d H:i:s'));
            Settings::set('djurdja_key_invalid', '0');
            Settings::set('djurdja_last_error', null);
            self::maybeHeartbeat($client);
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

    /** Heartbeat (registracija instalacije) — najviše jednom u 24 h, best-effort. */
    private static function maybeHeartbeat(DjurdjaClient $client): void
    {
        $last = strtotime((string) Settings::get('djurdja_heartbeat_at', ''));
        if ($last && (time() - $last) < 24 * 3600) return;
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
        } catch (DjurdjaApiException $e) {
            if ($e->httpStatus === 404) {
                // backend još nema heartbeat — ne smatraj greškom
                Settings::set('djurdja_heartbeat_at', date('Y-m-d H:i:s'));
            } else {
                error_log('[Djurdja::heartbeat] ' . $e->getMessage());
            }
        } catch (Throwable $e) {
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
