<?php
/**
 * Fiscalizer — fiskalizacija narudžbi kroz đurđa API.
 *
 * Logika preuzeta iz provjerene produkcijske integracije:
 *  - AUTO-ASSIGN numeracija: broj računa dodjeljuje đurđa atomarno TEK na uspjeh
 *    (nema rupa u CIS sekvenci), shop ne drži lokalni counter.
 *  - Idempotency kroz clientRequestId = shop-{OIB}-order-{id} (stabilan kroz retry-e).
 *  - 48h zakonski prozor (NN 89/2025): transient greške → pending_retry s exponential
 *    backoffom; istek prozora → failed_expired + alert vlasniku.
 *  - PDV svjesnost: firma u PDV sustavu → vatBreakdown grupiran po stopama stavki;
 *    inače nonTaxableAmount.
 *
 * NIKAD ne baca exception — uvijek vraća { success: bool, ... } (webhook mora vratiti 200).
 */

class Fiscalizer
{
    public static function fiscalizeOrder($db, int $orderId): array
    {
        $startTs = microtime(true);

        if (Settings::get('fiscal_enabled', '1') !== '1') {
            return ['success' => false, 'skipped' => true, 'error' => 'Fiskalizacija je isključena.'];
        }

        $order = $db->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
        if (!$order) return ['success' => false, 'error' => 'Narudžba nije pronađena.'];

        // Idempotency
        if ($order['fiscal_status'] === 'fiscalized' && !empty($order['fiscal_jir'])) {
            return [
                'success' => true, 'idempotent' => true,
                'jir' => $order['fiscal_jir'], 'zki' => $order['fiscal_zki'],
                'receiptNumber' => $order['fiscal_receipt_number'], 'mode' => $order['fiscal_mode'],
            ];
        }
        if ($order['payment_status'] !== 'paid') {
            return ['success' => false, 'error' => "Narudžba nije plaćena (payment_status={$order['payment_status']})."];
        }

        $client = DjurdjaClient::fromSettings();
        if (!$client) {
            $err = 'Đurđa API ključ nije konfiguriran (admin → Đurđa veza).';
            self::markFailed($db, $orderId, $err);
            return ['success' => false, 'error' => $err];
        }

        // Paket se osvježava PRIJE SVAKOG izdavanja računa: ako je kvota u
        // međuvremenu potrošena (đurđa blagajna dijeli istu kvotu), narudžbu
        // samo REZERVIRAMO — bez errora, bez trošenja broja — i javimo vlasniku.
        if (!$client->isMock()) {
            try {
                $acc = $client->account();
                Settings::setJson('djurdja_account', $acc);
                if (!empty($acc['company'])) Settings::setJson('djurdja_company', $acc['company']);
                Settings::set('djurdja_last_ok_at', date('Y-m-d H:i:s'));
                $u = $acc['usage']['DOCUMENT_CREATE'] ?? null;
                if (is_array($u) && isset($u['limit']) && (int) $u['used'] >= (int) $u['limit']) {
                    return self::reserveForQuota($db, $orderId, $order);
                }
            } catch (Throwable $e) {
                // best-effort provjera — i bez nje fiscalize uredno vraća 402 (vidi dolje)
            }
        }

        $mode = self::determineMode($client, $order['payment_method']);
        // Lanac okolina (PO-FIRMA, iz đurđe): demo certifikat (DEMO) ↔ TESTNO plaćanje;
        // produkcijski certifikat (PROD) ↔ STVARNO plaćanje. Miješanje je nedopustivo
        // (pravi račun za lažnu uplatu ili obrnuto). Okolinu daje đurđa (company_settings
        // te firme); ako je još ne šalje, fallback na mod API ključa.
        $env = Djurdja::fiscalizationEnv();
        if ($env === null) $env = ($client->mode() === 'live') ? 'PROD' : 'DEMO';
        if ($order['payment_method'] === 'stripe') {
            try { $payIsTest = (new PaymentManager())->isSandbox('stripe'); }
            catch (Throwable $e) { $payIsTest = false; }
            if ($env === 'PROD' && $payIsTest) {
                $err = 'Produkcijski certifikat firme + TESTNO kartično plaćanje — fiskalizacija blokirana (ne izdaje se pravi račun za testnu uplatu).';
                self::markFailed($db, $orderId, $err, 'env_payment_mismatch');
                return ['success' => false, 'error' => $err];
            }
            if ($env === 'DEMO' && !$payIsTest) {
                $err = 'Demo certifikat firme + STVARNO (live) kartično plaćanje — fiskalizacija blokirana. Za demo koristite testne Stripe ključeve.';
                self::markFailed($db, $orderId, $err, 'env_payment_mismatch');
                return ['success' => false, 'error' => $err];
            }
        }
        $company = Djurdja::company();
        $oib = $company['companyOib'] ?? null;

        $clientRequestId = 'shop-' . preg_replace('/[^A-Za-z0-9]/', '', $oib ?? 'unknown') . '-order-' . $orderId;
        $payload = self::buildPayload($db, $order, $company);
        $payload['clientRequestId'] = $clientRequestId;

        try {
            $result = $client->fiscalize($payload);
        } catch (DjurdjaApiException $e) {
            self::logEvent($db, $orderId, 'error', [
                'mode' => $mode, 'response_status' => $e->httpStatus,
                'error_code' => $e->apiErrorCode, 'error_message' => $e->getMessage(),
                'request_id' => $e->requestId,
                'raw_request' => json_encode($payload),
                'raw_response' => $e->responseBody ? json_encode($e->responseBody) : null,
                'duration_ms' => (int) ((microtime(true) - $startTs) * 1000),
            ]);

            $adminMsg = self::friendlyError($e);

            // Kvota potrošena (402) — đurđa NIJE dodijelila broj (nema rupa u
            // numeraciji): narudžba se rezervira za kasniju ručnu fiskalizaciju
            if ($e->apiErrorCode === 'plan_limit_reached') {
                return self::reserveForQuota($db, $orderId, $order);
            }

            if (self::classifyError($e) === 'transient') {
                self::markPendingRetry($db, $orderId, $adminMsg, $e->apiErrorCode, $mode);
                return [
                    'success' => false, 'pending_retry' => true, 'error' => $adminMsg,
                    'apiErrorCode' => $e->apiErrorCode,
                    'message' => 'Đurđa privremeno nedostupna — račun će biti automatski naknadno fiskaliziran (zakonski rok 48 h).',
                ];
            }
            self::markFailed($db, $orderId, $adminMsg, $e->apiErrorCode);
            return ['success' => false, 'error' => $adminMsg, 'apiErrorCode' => $e->apiErrorCode];
        } catch (Throwable $e) {
            $msg = 'Neočekivana greška: ' . $e->getMessage();
            self::markPendingRetry($db, $orderId, $msg, 'internal', $mode);
            error_log('[Fiscalizer] ' . $e->getMessage());
            return ['success' => false, 'pending_retry' => true, 'error' => $msg];
        }

        // fiscalized_at = vrijeme kad je CIS prihvatio (pravna važnost), format "dd.MM.yyyyTHH:mm:ss"
        $cisTime = $result['fiscalizedAt'] ?? null;
        $mysqlDate = null;
        if ($cisTime && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})T(\d{2}):(\d{2}):(\d{2})$/', $cisTime, $m)) {
            $mysqlDate = "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:{$m[6]}";
        }
        if (!$mysqlDate) $mysqlDate = date('Y-m-d H:i:s');

        $receiptNumber = (string) ($result['receiptNumber'] ?? '');
        if ($receiptNumber === '') {
            self::markFailed($db, $orderId, 'Đurđa odgovor ne sadrži receiptNumber.', 'invalid_response');
            return ['success' => false, 'error' => 'Đurđa odgovor ne sadrži receiptNumber.'];
        }
        // Zakonski broj računa = broj/oznaka_poslovnog_prostora/oznaka_naplatnog_uređaja
        // (Zakon o fiskalizaciji). đurđa vraća sve tri komponente (i koristi ih za ZKI) —
        // spajamo ih u propisani trodijelni oblik. Guard: ako broj već sadrži '/', ne diraj.
        if (strpos($receiptNumber, '/') === false) {
            $space  = (string) ($result['businessSpace'] ?? Settings::get('business_space', ''));
            $device = (string) ($result['cashRegister']  ?? Settings::get('cash_register', ''));
            if ($space !== '' && $device !== '') {
                $receiptNumber = $receiptNumber . '/' . $space . '/' . $device;
            }
        }

        $db->update('orders', [
            'fiscal_status' => 'fiscalized',
            // Mod je ono što je đurđa STVARNO napravila (prema svom certifikatu/
            // fiscalization_env), ne nagađanje shopa. 'test' = demo FINA cert.
            'fiscal_mode'   => (string) ($result['mode'] ?? $mode),
            'fiscal_receipt_number' => $receiptNumber,
            'fiscal_jir'    => $result['jir'] ?? null,
            'fiscal_zki'    => $result['zki'] ?? null,
            'fiscal_qr'     => $result['qrCode'] ?? null,
            'fiscalized_at' => $mysqlDate,
            'fiscal_error'  => null,
            'fiscal_next_retry_at'   => null,
            'fiscal_last_error_code' => null,
        ], 'id = :id', [':id' => $orderId]);
        $db->query('UPDATE orders SET fiscal_attempts = fiscal_attempts + 1,
                    fiscal_first_attempt_at = COALESCE(fiscal_first_attempt_at, NOW()) WHERE id = :id', [':id' => $orderId]);

        self::logEvent($db, $orderId, 'fiscalize', [
            'mode' => $mode, 'receipt_number' => $receiptNumber,
            'jir' => $result['jir'] ?? null, 'zki' => $result['zki'] ?? null,
            'request_id' => $result['requestId'] ?? null, 'response_status' => 200,
            'raw_request' => json_encode($payload),
            'raw_response' => json_encode($result),
            'duration_ms' => (int) ((microtime(true) - $startTs) * 1000),
        ]);

        // Fiskalizirani račun automatski kupcu na mail + obavijest vlasniku
        // (best-effort, ne ruši fiskalizaciju ako mail padne)
        try {
            $fresh = $db->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
            if ($fresh) {
                Mailer::fiscalReceipt($fresh);
                @Mailer::send(
                    Settings::get('shop_email', ''),
                    'Fiskaliziran račun ' . $receiptNumber . ' — ' . ($fresh['order_number'] ?? ''),
                    '<p>Račun <strong>' . e($receiptNumber) . '</strong> za narudžbu <strong>' . e($fresh['order_number'] ?? '') . '</strong> '
                    . 'je fiskaliziran i poslan kupcu (' . e($fresh['customer_email']) . ').<br>JIR: ' . e($result['jir'] ?? '') . '</p>'
                );
            }
        } catch (Throwable $e) {
            error_log('[Fiscalizer] slanje racuna: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'jir' => $result['jir'] ?? null, 'zki' => $result['zki'] ?? null,
            'receiptNumber' => $receiptNumber, 'mode' => $mode,
            'idempotent' => !empty($result['idempotent']),
        ];
    }

    public static function stornoOrder($db, int $orderId, string $reason = 'Povrat'): array
    {
        $startTs = microtime(true);
        $order = $db->fetch('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
        if (!$order) return ['success' => false, 'error' => 'Narudžba nije pronađena.'];
        if ($order['fiscal_status'] !== 'fiscalized') {
            return ['success' => false, 'error' => 'Narudžba nije fiskalizirana.'];
        }
        $client = DjurdjaClient::fromSettings();
        if (!$client) return ['success' => false, 'error' => 'Đurđa API ključ nije konfiguriran.'];

        $company = Djurdja::company();
        $stornoClientRequestId = 'storno-shop-'
            . preg_replace('/[^A-Za-z0-9]/', '', $company['companyOib'] ?? 'unknown') . '-order-' . $orderId;

        $payload = [
            'businessSpace'   => Settings::get('business_space', 'WEBSHOP'),
            'cashRegister'    => Settings::get('cash_register', '1'),
            'receiptNumber'   => $order['fiscal_receipt_number'], // identificira ORIGINALNI račun
            'clientRequestId' => $stornoClientRequestId,
            'reason'          => $reason,
        ];

        try {
            $result = $client->storno($payload);
        } catch (DjurdjaApiException $e) {
            if ($e->apiErrorCode === 'already_storno') {
                // Reconcile lokalno stanje — đurđa kaže da je već stornirano
                $det = $e->responseBody['error']['details'] ?? [];
                $db->update('orders', [
                    'fiscal_status' => 'stornoed',
                    'fiscal_storno_jir' => $det['stornoJir'] ?? $order['fiscal_storno_jir'],
                    'fiscal_storno_receipt_number' => $det['stornoReceiptNumber'] ?? $order['fiscal_storno_receipt_number'],
                ], 'id = :id', [':id' => $orderId]);
                return ['success' => true, 'idempotent' => true];
            }
            self::logEvent($db, $orderId, 'error', [
                'mode' => $order['fiscal_mode'], 'response_status' => $e->httpStatus,
                'error_code' => $e->apiErrorCode, 'error_message' => $e->getMessage(),
                'raw_request' => json_encode($payload),
                'duration_ms' => (int) ((microtime(true) - $startTs) * 1000),
            ]);
            return ['success' => false, 'error' => $e->getMessage(), 'apiErrorCode' => $e->apiErrorCode];
        }

        $stornoNumber = (string) ($result['stornoReceiptNumber'] ?? '');
        if ($stornoNumber === '') {
            return ['success' => false, 'error' => 'Đurđa odgovor ne sadrži stornoReceiptNumber.'];
        }
        // Propisani trodijelni oblik (broj/poslovni_prostor/naplatni_uređaj)
        if (strpos($stornoNumber, '/') === false) {
            $space  = (string) ($result['businessSpace'] ?? Settings::get('business_space', ''));
            $device = (string) ($result['cashRegister']  ?? Settings::get('cash_register', ''));
            if ($space !== '' && $device !== '') {
                $stornoNumber = $stornoNumber . '/' . $space . '/' . $device;
            }
        }
        $db->update('orders', [
            'fiscal_status' => 'stornoed',
            'fiscal_storno_jir' => $result['stornoJir'] ?? null,
            'fiscal_storno_receipt_number' => $stornoNumber,
        ], 'id = :id', [':id' => $orderId]);

        self::logEvent($db, $orderId, 'storno', [
            'mode' => $order['fiscal_mode'], 'receipt_number' => $stornoNumber,
            'jir' => $result['stornoJir'] ?? null, 'response_status' => 200,
            'raw_request' => json_encode($payload),
            'raw_response' => json_encode($result),
            'duration_ms' => (int) ((microtime(true) - $startTs) * 1000),
        ]);
        return ['success' => true, 'stornoJir' => $result['stornoJir'] ?? null, 'stornoReceiptNumber' => $stornoNumber];
    }

    /** Cron: pokušaj ponovno sve narudžbe u pending_retry kojima je vrijeme. */
    public static function retryDue($db, int $limit = 10): array
    {
        $due = $db->fetchAll(
            "SELECT id FROM orders WHERE fiscal_status = 'pending_retry'
             AND fiscal_next_retry_at IS NOT NULL AND fiscal_next_retry_at <= NOW()
             ORDER BY fiscal_next_retry_at ASC LIMIT " . (int) $limit
        );
        $results = [];
        foreach ($due as $row) {
            $results[(int) $row['id']] = self::fiscalizeOrder($db, (int) $row['id']);
        }
        return $results;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Mod fiskalizacije ISKLJUČIVO prema đurđa ključu: `pk_live_` = produkcija (live),
     * inače test. Test je moguć SAMO s TESTNIM đurđa ključem — tako se lažni/testni
     * računi NIKAD ne numeriraju u pravom (produkcijskom) nizu Porezne uprave.
     * Postavke (force_test_mode) ni sandbox plaćanja NE smiju forsirati test na
     * produkcijskom ključu (to bi pokvarilo neprekinuti slijed brojeva računa).
     */
    private static function determineMode(DjurdjaClient $client, string $paymentMethod): string
    {
        return $client->mode(); // 'live' (pk_live_) ili 'test'
    }

    /**
     * Payload — fiskaliziraju se stavke narudžbe + DOSTAVA (i naknada plaćanja),
     * jer sve to kupac plaća pa mora biti na računu. PDV razrada po stopama
     * (dostava prati stopu artikala / 25 %), ili nonTaxableAmount ako firma
     * nije u sustavu PDV-a. Iznosi se računaju u Orders::receiptParts (jedan izvor).
     */
    private static function buildPayload($db, array $order, array $company): array
    {
        $items = $db->fetchAll('SELECT vat_rate, total FROM order_items WHERE order_id = :o', [':o' => $order['id']]);
        $parts = Orders::receiptParts($order, $items);

        $payload = [
            'businessSpace' => Settings::get('business_space', 'WEBSHOP'),
            'cashRegister'  => Settings::get('cash_register', '1'),
            'totalAmount'   => $parts['grandTotal'],
            'currency'      => 'EUR',
            'paymentMethod' => self::finaCode($order['payment_method']),
            'note'          => 'Narudžba ' . $order['order_number'] . ' (web shop)',
        ];

        if (!empty($company['inVatSystem'])) {
            $breakdown = [];
            foreach ($parts['byRate'] as $rateStr => $gross) {
                $rate = (float) $rateStr;
                $base = $rate > 0 ? round($gross / (1 + $rate / 100), 2) : round($gross, 2);
                $amount = round($gross - $base, 2);
                $breakdown[] = ['rate' => $rate, 'base' => $base, 'amount' => $amount];
            }
            $payload['vatBreakdown'] = $breakdown;
        } else {
            $payload['vatBreakdown'] = [];
            $payload['nonTaxableAmount'] = $parts['grandTotal'];
        }
        return $payload;
    }

    /**
     * FINA kod načina plaćanja — fiksno (bez korisničkih postavki):
     * kartice (Stripe) = K, pouzeće = G (gotovina pri preuzimanju), ostalo = O.
     */
    private static function finaCode(string $method): string
    {
        switch ($method) {
            case 'stripe':        return 'K';
            case 'cod':           return 'G';
            case 'bank_transfer': return 'T'; // povijesne narudžbe prije v1.1
            default:              return 'O';
        }
    }

    private static function friendlyError(DjurdjaApiException $e): string
    {
        switch ($e->apiErrorCode) {
            case 'ip_not_allowed':
                $ip = $e->responseBody['error']['clientIp'] ?? '?';
                return "Đurđa je odbila zahtjev s IP-a $ip (nije na IP listi ključa). U MojaĐurđa → API pristup dodajte $ip u whitelist.";
            case 'vat_mismatch':
                return 'PDV status u đurđi ne odgovara podacima shopa. Otvorite admin → Đurđa veza → Osvježi podatke.';
            case 'plan_limit_reached':
                return 'Mjesečna kvota dokumenata u đurđi je potrošena. Nadogradite paket na mojadjurdja.com da nastavite prodavati.';
            case 'certificate_missing':
                return 'U đurđi nije uploadan FINA certifikat — bez njega nema fiskalizacije.';
            default:
                return $e->getMessage();
        }
    }

    private static function classifyError(DjurdjaApiException $e): string
    {
        if ($e->httpStatus === 0) return 'transient';
        if ($e->httpStatus >= 500) return 'transient';
        if ($e->httpStatus === 429) return 'transient';
        if (in_array($e->apiErrorCode, ['cis_unreachable', 'cis_timeout', 'fiscalization_failed', 'rate_limited'], true)) {
            return 'transient';
        }
        return 'permanent';
    }

    /**
     * Kvota paketa potrošena → narudžba ostaje REZERVIRANA (fiscal_status='none',
     * bez retry petlje), vlasnik dobiva mail (jednom po narudžbi) i kasnije je
     * ručno fiskalizira gumbom "Fiskaliziraj sada" (nakon nadogradnje/novog
     * razdoblja) ili otkaže.
     */
    private static function reserveForQuota($db, int $orderId, array $order): array
    {
        $msg = 'Đurđa kvota dokumenata je potrošena — narudžba je REZERVIRANA. '
             . 'Nadogradite paket (ili pričekajte novo razdoblje) pa kliknite "Fiskaliziraj sada".';
        $alreadyWarned = str_contains((string) ($order['fiscal_error'] ?? ''), 'REZERVIRANA');

        $db->update('orders', [
            'fiscal_status' => 'none',
            'fiscal_error'  => mb_substr($msg, 0, 255),
            'fiscal_last_error_code' => 'plan_limit_reached',
            'fiscal_next_retry_at'   => null,
        ], 'id = :id', [':id' => $orderId]);
        self::logEvent($db, $orderId, 'error', [
            'error_code' => 'plan_limit_reached',
            'error_message' => 'Kvota potrošena — narudžba rezervirana (broj NIJE dodijeljen, kvota NIJE potrošena).',
        ]);

        if (!$alreadyWarned) {
            try {
                Mailer::send(
                    s('shop_email', ''),
                    '⏸ Narudžba ' . ($order['order_number'] ?? "#$orderId") . ' čeka fiskalizaciju — kvota potrošena',
                    '<h2 style="margin:0 0 8px">Kvota đurđa paketa je potrošena</h2>'
                    . '<p>Narudžba <strong>' . e($order['order_number'] ?? "#$orderId") . '</strong> ('
                    . fmt_price($order['total'] ?? 0) . ') je plaćena i <strong>rezervirana</strong> — račun NIJE izdan i ništa nije izgubljeno.</p>'
                    . '<p>Što napraviti: nadogradite paket (ili pričekajte novo obračunsko razdoblje), zatim u administraciji '
                    . 'otvorite narudžbu i kliknite <strong>"Fiskaliziraj sada"</strong>. Pazite na zakonski rok fiskalizacije od 48 h od naplate!</p>'
                    . '<p><a href="https://mojadjurdja.com/cjenik?utm_source=webshop&utm_medium=email&utm_campaign=quota_full" '
                    . 'style="background:#1f2937;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Nadogradi paket</a></p>'
                );
            } catch (Throwable $e) {
                error_log('[Fiscalizer] quota mail: ' . $e->getMessage());
            }
        }
        return [
            'success' => false,
            'reserved' => true,
            'error' => $msg,
            'apiErrorCode' => 'plan_limit_reached',
        ];
    }

    private static function markFailed($db, int $orderId, string $error, ?string $errorCode = null): void
    {
        $prev = $db->fetch('SELECT fiscal_status, order_number, total FROM orders WHERE id = :id', [':id' => $orderId]);
        $db->update('orders', [
            'fiscal_status' => 'failed',
            'fiscal_error'  => mb_substr($error, 0, 255),
            'fiscal_last_error_code' => $errorCode ? substr($errorCode, 0, 64) : null,
            'fiscal_next_retry_at'   => null,
        ], 'id = :id', [':id' => $orderId]);
        $db->query('UPDATE orders SET fiscal_attempts = fiscal_attempts + 1,
                    fiscal_first_attempt_at = COALESCE(fiscal_first_attempt_at, NOW()) WHERE id = :id', [':id' => $orderId]);

        // Proaktivno obavijesti vlasnika JEDNOM (na prijelaz u 'failed') — račun NIJE
        // izdan, a teče zakonski rok od 48 h. Best-effort: ne smije srušiti fiskalizaciju.
        if ($prev && !in_array($prev['fiscal_status'], ['failed', 'failed_expired'], true)) {
            try {
                Mailer::send(
                    s('shop_email', ''),
                    '⚠ Fiskalizacija nije uspjela — ' . ($prev['order_number'] ?? "#$orderId"),
                    '<h2 style="margin:0 0 8px">Račun nije fiskaliziran</h2>'
                    . '<p>Narudžba <strong>' . e($prev['order_number'] ?? "#$orderId") . '</strong> ('
                    . fmt_price($prev['total'] ?? 0) . ') je plaćena, ali fiskalizacija nije uspjela:</p>'
                    . '<p style="background:#fef2f2;border:1px solid #fecaca;padding:10px 14px;border-radius:8px">' . e($error) . '</p>'
                    . '<p>Otvorite narudžbu u administraciji, otklonite uzrok i kliknite <strong>„Pokušaj ponovno"</strong>. '
                    . 'Pazite na zakonski rok fiskalizacije od <strong>48 h</strong> od naplate.</p>'
                );
            } catch (Throwable $e) {
                error_log('[Fiscalizer] failed-alert mail: ' . $e->getMessage());
            }
        }
    }

    private static function markPendingRetry($db, int $orderId, string $error, ?string $errorCode, string $mode): void
    {
        $db->query('UPDATE orders SET fiscal_attempts = fiscal_attempts + 1,
                    fiscal_first_attempt_at = COALESCE(fiscal_first_attempt_at, NOW()) WHERE id = :id', [':id' => $orderId]);

        $order = $db->fetch('SELECT fiscal_attempts, fiscal_first_attempt_at FROM orders WHERE id = :id', [':id' => $orderId]);
        $attempts = (int) ($order['fiscal_attempts'] ?? 1);
        $firstAttempt = $order['fiscal_first_attempt_at'] ?? date('Y-m-d H:i:s');
        $expiresAt = strtotime($firstAttempt) + 48 * 3600; // zakonski prozor od PRVOG pokušaja

        if (time() >= $expiresAt) {
            self::escalateExpired($db, $orderId, $error, $errorCode);
            return;
        }

        // Backoff: 1, 2, 5, 15, 30, 60 min, zatim svaka 2 h — cap na rub 48h prozora
        $backoffMinutes = [1 => 1, 2 => 2, 3 => 5, 4 => 15, 5 => 30, 6 => 60];
        $minutes = $backoffMinutes[$attempts] ?? 120;
        $nextTs = time() + $minutes * 60;
        if ($nextTs >= $expiresAt) $nextTs = max(time() + 30, $expiresAt - 60);

        $db->update('orders', [
            'fiscal_status' => 'pending_retry',
            'fiscal_error'  => mb_substr($error, 0, 255),
            'fiscal_last_error_code' => $errorCode ? substr($errorCode, 0, 64) : null,
            'fiscal_next_retry_at'   => date('Y-m-d H:i:s', $nextTs),
            'fiscal_mode'            => $mode,
        ], 'id = :id', [':id' => $orderId]);
    }

    private static function escalateExpired($db, int $orderId, string $error, ?string $errorCode): void
    {
        $db->update('orders', [
            'fiscal_status' => 'failed_expired',
            'fiscal_error'  => mb_substr('48h prozor istekao: ' . $error, 0, 255),
            'fiscal_last_error_code' => $errorCode ? substr($errorCode, 0, 64) : null,
            'fiscal_next_retry_at'   => null,
        ], 'id = :id', [':id' => $orderId]);
        self::logEvent($db, $orderId, 'expired', ['error_code' => $errorCode, 'error_message' => mb_substr($error, 0, 1000)]);

        try {
            $order = $db->fetch('SELECT order_number FROM orders WHERE id = :id', [':id' => $orderId]);
            Mailer::send(
                s('shop_email', ''),
                'HITNO: fiskalizacija istekla — ' . ($order['order_number'] ?? "#$orderId"),
                '<p>Narudžba <strong>' . e($order['order_number'] ?? "#$orderId") . '</strong> nije fiskalizirana unutar zakonskog roka od 48 sati.</p>'
                . '<p>Greška: ' . e($error) . '</p>'
                . '<p>Potrebna je ručna intervencija (kontaktirajte knjigovođu ili Poreznu upravu za naknadnu prijavu).</p>'
            );
        } catch (Throwable $e) {
            error_log('[Fiscalizer] alert mail: ' . $e->getMessage());
        }
    }

    private static function logEvent($db, int $orderId, string $action, array $data): void
    {
        try {
            $db->insert('fiscal_log', [
                'order_id'       => $orderId,
                'action'         => $action,
                'request_id'     => $data['request_id'] ?? null,
                'mode'           => $data['mode'] ?? null,
                'receipt_number' => $data['receipt_number'] ?? null,
                'jir'            => $data['jir'] ?? null,
                'zki'            => $data['zki'] ?? null,
                'response_status'=> $data['response_status'] ?? null,
                'error_code'     => $data['error_code'] ?? null,
                'error_message'  => $data['error_message'] ?? null,
                'raw_request'    => $data['raw_request'] ?? null,
                'raw_response'   => $data['raw_response'] ?? null,
                'duration_ms'    => $data['duration_ms'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('[Fiscalizer::logEvent] ' . $e->getMessage());
        }
    }
}
