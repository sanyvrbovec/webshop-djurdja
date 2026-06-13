<?php
/**
 * ĐurđaShop — klijent za mojadjurdja.com API.
 *
 * HMAC potpisivanje identično specifikaciji đurđa api_gatewaya:
 *   X-API-Key, X-Timestamp, X-Nonce, X-Signature
 *   Signature = HMAC-SHA256(secret, ts + "\n" + nonce + "\n" + METHOD + "\n" + /api/v1/path + "\n" + sha256(body))
 *
 * MOCK mode (DJURDJA_MOCK=true u config.php): svi pozivi se simuliraju lokalno
 * s demo podacima — za razvoj i testiranje BEZ diranja produkcijskog servera.
 *
 * Klasa baca DjurdjaApiException za svaki failure; aplikacijski sloj odlučuje.
 */

class DjurdjaApiException extends RuntimeException
{
    public ?string $apiErrorCode = null;
    public ?string $requestId = null;
    public int $httpStatus = 0;
    public ?array $responseBody = null;
}

class DjurdjaClient
{
    private string $apiBase;
    private string $keyId;
    private string $secret;
    private int $timeout;
    private int $retries;
    private bool $mock;

    /**
     * @param array $cfg ključevi: api_base, key_id, secret, http_timeout?, http_retries?, mock?
     */
    public function __construct(array $cfg)
    {
        $this->mock = !empty($cfg['mock']);
        if (!$this->mock && (empty($cfg['api_base']) || empty($cfg['key_id']) || empty($cfg['secret']))) {
            throw new DjurdjaApiException('DjurdjaClient: api_base, key_id i secret su obavezni.');
        }
        $this->apiBase = rtrim($cfg['api_base'] ?? 'https://mojadjurdja.com/api/v1', '/');
        $this->keyId   = $cfg['key_id'] ?? 'pk_test_mock';
        $this->secret  = $cfg['secret'] ?? 'sk_mock';
        $this->timeout = (int) ($cfg['http_timeout'] ?? 30);
        $this->retries = (int) ($cfg['http_retries'] ?? 2);
    }

    /** Instanca iz spremljenih postavki shopa. NULL ako ključ nije konfiguriran. */
    public static function fromSettings(): ?self
    {
        $mock = defined('DJURDJA_MOCK') && DJURDJA_MOCK;
        if ($mock) {
            return new self(['mock' => true]);
        }
        $keyId = Settings::get('djurdja_key_id');
        $secret = Crypto::decrypt(Settings::get('djurdja_secret_enc'));
        if (!$keyId || !$secret) return null;
        return new self([
            'api_base' => Settings::get('djurdja_api_base', 'https://mojadjurdja.com/api/v1'),
            'key_id'   => $keyId,
            'secret'   => $secret,
        ]);
    }

    public function isMock(): bool
    {
        return $this->mock;
    }

    /** 'test' ili 'live' — prema prefiksu ključa. */
    public function mode(): string
    {
        return strpos($this->keyId, 'pk_live_') === 0 ? 'live' : 'test';
    }

    // ── Endpointi ──

    public function me(): array
    {
        return $this->call('GET', '/me');
    }

    public function account(): array
    {
        return $this->call('GET', '/shop/account');
    }

    /** @param string|null $since ISO datum za delta sync; $offset za paginaciju */
    public function catalog(?string $since = null, int $offset = 0): array
    {
        $params = [];
        if ($since) $params['since'] = $since;
        if ($offset > 0) $params['offset'] = (string) $offset;
        $q = $params ? ('?' . http_build_query($params)) : '';
        return $this->call('GET', '/shop/catalog' . $q);
    }

    public function heartbeat(array $payload): array
    {
        return $this->call('POST', '/shop/heartbeat', $payload);
    }

    /** Javi đurđi prodaju po varijantama (skida zalihu varijante na masteru). */
    public function variantSale(array $sales): array
    {
        return $this->call('POST', '/shop/variant-sale', ['sales' => $sales]);
    }

    /** Promo traka — centralizirani sadržaj reklame (free plan). */
    public function promo(): array
    {
        return $this->call('GET', '/shop/promo');
    }

    public function fiscalize(array $payload): array
    {
        return $this->call('POST', '/fiscalize', $payload);
    }

    public function storno(array $payload): array
    {
        return $this->call('POST', '/fiscalize/storno', $payload);
    }

    public function health(): array
    {
        if ($this->mock) return ['status' => 'ok', 'version' => 'mock'];
        $ch = curl_init($this->apiBase . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) {
            $e = new DjurdjaApiException("Health check neuspješan: HTTP $status");
            $e->httpStatus = $status;
            throw $e;
        }
        return json_decode((string) $body, true) ?: [];
    }

    // ── Generic potpisani poziv ──

    public function call(string $method, string $relativePath, ?array $bodyArr = null): array
    {
        if ($this->mock) {
            return $this->mockResponse($method, $relativePath, $bodyArr);
        }

        $method = strtoupper($method);
        $url = $this->apiBase . $relativePath;
        $signPath = parse_url($url, PHP_URL_PATH); // server potpisuje originalUrl bez query stringa
        $bodyJson = $bodyArr === null ? '' : json_encode($bodyArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $attempts = max(1, $this->retries + 1);
        $lastException = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $this->doCallOnce($method, $url, $signPath, $bodyJson);
            } catch (DjurdjaApiException $e) {
                $lastException = $e;
                if ($e->httpStatus !== 0) throw $e; // 4xx/5xx ne retry-amo; samo transport greške
                usleep(200000 * (1 << $i));
            }
        }
        throw $lastException ?? new DjurdjaApiException('Nepoznata greška');
    }

    private function doCallOnce(string $method, string $url, string $signPath, string $bodyJson): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', $bodyJson);
        $signedString = $timestamp . "\n" . $nonce . "\n" . $method . "\n" . $signPath . "\n" . $bodyHash;
        $signature = hash_hmac('sha256', $signedString, $this->secret);

        $headers = [
            'X-API-Key: ' . $this->keyId,
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Signature: ' . $signature,
            'Content-Type: application/json',
            'User-Agent: djurdjashop/' . (defined('SHOP_VERSION') ? SHOP_VERSION : 'dev'),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $bodyJson,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            $e = new DjurdjaApiException("Greška mreže: $err");
            $e->httpStatus = 0;
            throw $e;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($resp, $headerSize);
        curl_close($ch);

        $json = json_decode($body, true);
        $isJson = is_array($json);

        if ($status >= 200 && $status < 300) {
            return $isJson ? $json : [];
        }

        $e = new DjurdjaApiException(
            $isJson && isset($json['error']['message']) ? $json['error']['message'] : "đurđa API HTTP $status"
        );
        $e->httpStatus   = $status;
        $e->apiErrorCode = $isJson ? ($json['error']['code'] ?? null) : null;
        $e->requestId    = $isJson ? ($json['requestId'] ?? null) : null;
        $e->responseBody = $isJson ? $json : null;
        throw $e;
    }

    // ══════════════════════════════════════════════════════════
    // MOCK — lokalna simulacija đurđa API-ja (razvoj/test)
    // ══════════════════════════════════════════════════════════

    private function mockResponse(string $method, string $path, ?array $body): array
    {
        $cleanPath = explode('?', $path)[0];

        switch ($method . ' ' . $cleanPath) {
            case 'GET /me':
                return [
                    'companyId'      => 'mock-company-0001',
                    'companyOib'     => '12345678901',
                    'companyName'    => 'Demo obrt za testiranje',
                    'inVatSystem'    => true,
                    'hasCertificate' => true,
                    'mode'           => 'test',
                    'keyId'          => 'pk_test_mock',
                    'keyLabel'       => 'mock',
                ];

            case 'GET /shop/account':
                $used = (int) $this->mockSetting('mock_quota_used', '0');
                return [
                    'company' => [
                        'companyId' => 'mock-company-0001', 'companyOib' => '12345678901',
                        'companyName' => 'Demo obrt za testiranje', 'inVatSystem' => true, 'hasCertificate' => true,
                        'address' => 'Ilica 1', 'city' => 'Zagreb', 'postalCode' => '10000', 'email' => 'demo@example.com',
                        'invoiceHeader' => '', 'invoiceFooter' => 'Roba ostaje vlasništvo prodavatelja do potpune isplate.',
                    ],
                    'plan' => ['id' => 'plan_free_01', 'name' => 'Besplatni', 'isPremium' => false],
                    'features' => ['INVOICE_FOOTER_LINK' => true, 'INVOICE_LOGO_PRINT' => false],
                    'usage' => ['DOCUMENT_CREATE' => ['used' => $used, 'limit' => 30, 'periodEnd' => date('Y-m-t 23:59:59')]],
                    'shopStatus' => 'active',
                    'latestShopVersion' => defined('SHOP_VERSION') ? SHOP_VERSION : '1.0.0',
                    'minShopVersion' => '',
                ];

            case 'GET /shop/catalog':
                return $this->mockCatalog();

            case 'POST /shop/heartbeat':
                return ['ok' => true, 'status' => 'active'];

            case 'POST /shop/variant-sale':
                return ['ok' => true, 'updated' => count($body['sales'] ?? [])];

            case 'GET /shop/promo':
                return ['enabled' => true, 'text' => 'MOCK promo: MojaĐurđa — blagajna, e-računi i besplatna web trgovina ✨', 'url' => 'https://mojadjurdja.com/?utm_source=webshop&utm_medium=promobar'];

            case 'POST /fiscalize':
                $n = (int) $this->mockSetting('mock_fiscal_counter', '0') + 1;
                $this->mockSetting('mock_fiscal_counter', (string) $n, true);
                $used = (int) $this->mockSetting('mock_quota_used', '0') + 1;
                $this->mockSetting('mock_quota_used', (string) $used, true);
                $jir = strtoupper(bin2hex(random_bytes(16)));
                return [
                    'internalReceiptId' => 'mock-' . bin2hex(random_bytes(8)),
                    'receiptNumber'     => $n . '/' . ($body['businessSpace'] ?? 'WEBSHOP') . '/' . ($body['cashRegister'] ?? '1'),
                    'jir'               => $jir,
                    'zki'               => bin2hex(random_bytes(16)),
                    'qrCode'            => 'https://porezna.gov.hr/rn?jir=' . $jir . '&datv=' . date('Ymd_Hi') . '&izn=' . (int) round(($body['totalAmount'] ?? 0) * 100),
                    'fiscalizedAt'      => date('d.m.Y\TH:i:s'),
                    'mode'              => 'test',
                ];

            case 'POST /fiscalize/storno':
                $n = (int) $this->mockSetting('mock_fiscal_counter', '0') + 1;
                $this->mockSetting('mock_fiscal_counter', (string) $n, true);
                return [
                    'stornoReceiptNumber' => $n . '/WEBSHOP/1',
                    'stornoJir'           => strtoupper(bin2hex(random_bytes(16))),
                    'mode'                => 'test',
                ];
        }

        $e = new DjurdjaApiException("Mock: nepoznat endpoint $method $cleanPath");
        $e->httpStatus = 404;
        throw $e;
    }

    /** Settings pristup koji tiho ne uspijeva prije instalacije (mock /me u installeru). */
    private function mockSetting(string $key, string $default, bool $write = false)
    {
        try {
            if ($write) { Settings::set($key, $default); return $default; }
            return Settings::get($key, $default);
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function mockCatalog(): array
    {
        $cats = [
            ['id' => 'mock-cat-1', 'name' => 'Kava i napitci'],
            ['id' => 'mock-cat-2', 'name' => 'Slastice'],
            ['id' => 'mock-cat-3', 'name' => 'Pokloni i paketi'],
            ['id' => 'mock-cat-4', 'name' => 'Usluge'],
        ];
        $prods = [
            ['id' => 'mock-prod-1', 'categoryId' => 'mock-cat-1', 'name' => 'Espresso zrno 1 kg — tamno prženje', 'description' => 'Arabica/robusta blend, svježe prženo u Hrvatskoj.', 'priceMpc' => 18.90, 'vatRate' => 25.00, 'unit' => 'kom', 'barcode' => '3850001000019', 'isService' => false, 'stock' => 42],
            ['id' => 'mock-prod-2', 'categoryId' => 'mock-cat-1', 'name' => 'Filter kava 500 g — etiopska', 'description' => 'Single origin, citrusne note.', 'priceMpc' => 12.50, 'vatRate' => 25.00, 'unit' => 'kom', 'barcode' => '3850001000026', 'isService' => false, 'stock' => 18],
            ['id' => 'mock-prod-3', 'categoryId' => 'mock-cat-2', 'name' => 'Domaći medenjaci 300 g', 'description' => 'Ručno rađeni medenjaci s pravim medom.', 'priceMpc' => 7.90, 'vatRate' => 5.00, 'unit' => 'kom', 'barcode' => null, 'isService' => false, 'stock' => 25],
            ['id' => 'mock-prod-4', 'categoryId' => 'mock-cat-2', 'name' => 'Čokoladna torta (cijela)', 'description' => 'Za 12 osoba, narudžba 2 dana unaprijed.', 'priceMpc' => 35.00, 'vatRate' => 13.00, 'unit' => 'kom', 'barcode' => null, 'isService' => false, 'stock' => null],
            ['id' => 'mock-prod-5', 'categoryId' => 'mock-cat-3', 'name' => 'Poklon paket "Jutro"', 'description' => 'Kava 250 g + šalica + medenjaci.', 'priceMpc' => 24.90, 'vatRate' => 25.00, 'unit' => 'kom', 'barcode' => null, 'isService' => false, 'stock' => 10,
             'showInWebshop' => true,
             'variants' => [
                 ['id' => 'mock-var-1', 'option1Name' => 'Veličina', 'option1Value' => 'Mali', 'option2Name' => null, 'option2Value' => null, 'sku' => 'PJ-S', 'price' => null, 'stock' => 4],
                 ['id' => 'mock-var-2', 'option1Name' => 'Veličina', 'option1Value' => 'Veliki', 'option2Name' => null, 'option2Value' => null, 'sku' => 'PJ-L', 'price' => 34.90, 'stock' => 6],
             ]],
            ['id' => 'mock-prod-6', 'categoryId' => 'mock-cat-3', 'name' => 'Poklon bon 50 €', 'description' => 'Vrijedi 12 mjeseci od kupnje.', 'priceMpc' => 50.00, 'vatRate' => 0.00, 'unit' => 'kom', 'barcode' => null, 'isService' => true, 'stock' => null, 'showInWebshop' => false],
            ['id' => 'mock-prod-7', 'categoryId' => 'mock-cat-4', 'name' => 'Radionica pripreme kave (2 h)', 'description' => 'Mala grupa, max 6 polaznika.', 'priceMpc' => 45.00, 'vatRate' => 25.00, 'unit' => 'kom', 'barcode' => null, 'isService' => true, 'stock' => null],
            ['id' => 'mock-prod-8', 'categoryId' => 'mock-cat-1', 'name' => 'Cold brew boca 0,75 l', 'description' => 'Hladno ekstrahirana, 24 h.', 'priceMpc' => 9.90, 'vatRate' => 25.00, 'unit' => 'kom', 'barcode' => '3850001000033', 'isService' => false, 'stock' => 0],
        ];
        return [
            'categories' => $cats,
            'products'   => $prods,
            'total'      => count($prods),
            'hasMore'    => false,
            'syncedAt'   => date('c'),
        ];
    }
}
