<?php
/**
 * PaymentManager — jedinstveno sučelje prema načinima plaćanja.
 * Config se čita iz payment_methods tablice; tajne (Stripe sk, webhook secret)
 * su u configu enkriptirane (polja s sufiksom _enc).
 */

class PaymentManager
{
    private Database $db;
    private array $cache = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    public function getActiveMethods(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id');
        foreach ($rows as &$r) $r['config'] = json_decode($r['config'] ?? '{}', true) ?: [];
        return $rows;
    }

    public function getAllMethods(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM payment_methods ORDER BY sort_order, id');
        foreach ($rows as &$r) $r['config'] = json_decode($r['config'] ?? '{}', true) ?: [];
        return $rows;
    }

    public function getMethod(string $code): ?array
    {
        if (isset($this->cache[$code])) return $this->cache[$code];
        $m = $this->db->fetch('SELECT * FROM payment_methods WHERE code = :c', [':c' => $code]);
        if (!$m) return null;
        $m['config'] = json_decode($m['config'] ?? '{}', true) ?: [];
        return $this->cache[$code] = $m;
    }

    public function calculateFee(string $code, float $amount): float
    {
        $m = $this->getMethod($code);
        if (!$m) return 0.0;
        switch ($m['fee_type']) {
            case 'fixed':   return (float) $m['fee_value'];
            case 'percent': return round($amount * (float) $m['fee_value'] / 100, 2);
            default:        return 0.0;
        }
    }

    /**
     * Pokreni plaćanje. Vraća redirect URL (kartice) ili null (pouzeće/virman).
     */
    public function initiate(array $order): ?string
    {
        $code = $order['payment_method'];
        $method = $this->getMethod($code);
        if (!$method || !(int) $method['is_active']) {
            throw new RuntimeException("Način plaćanja '$code' nije dostupan.");
        }

        $txId = $this->db->insert('payment_transactions', [
            'order_id' => $order['id'],
            'provider' => $code,
            'amount'   => $order['total'],
            'currency' => 'EUR',
            'status'   => 'initiated',
        ]);

        if ($code === 'stripe') {
            $stripe = $this->stripe();
            $items = $this->db->fetchAll('SELECT * FROM order_items WHERE order_id = :o', [':o' => $order['id']]);
            $successUrl = SITE_URL . '/narudzba-potvrda.php?t=' . urlencode($order['guest_token']) . '&pay=ok';
            $cancelUrl  = SITE_URL . '/narudzba-potvrda.php?t=' . urlencode($order['guest_token']) . '&pay=cancel';
            $session = $stripe->createCheckoutSession($order, $items, $successUrl, $cancelUrl);

            $this->db->update('payment_transactions', [
                'status' => 'pending',
                'transaction_id' => $session['id'] ?? null,
            ], 'id = :id', [':id' => $txId]);
            $this->db->update('orders', [
                'payment_transaction_id' => $session['id'] ?? null,
            ], 'id = :id', [':id' => $order['id']]);

            return $session['url'] ?? null;
        }

        // cod / bank_transfer — bez redirecta
        $this->db->update('payment_transactions', ['status' => 'pending'], 'id = :id', [':id' => $txId]);
        return null;
    }

    /** Instanca Stripe klijenta iz spremljene konfiguracije. */
    public function stripe(): Stripe
    {
        $m = $this->getMethod('stripe');
        $cfg = $m['config'] ?? [];
        $sk = Crypto::decrypt($cfg['secret_key_enc'] ?? null);
        $wh = Crypto::decrypt($cfg['webhook_secret_enc'] ?? null);
        if (!$sk) throw new RuntimeException('Stripe nije konfiguriran (nedostaje secret key).');
        return new Stripe(['secret_key' => $sk, 'webhook_secret' => $wh ?: '']);
    }

    /**
     * Je li payment metoda u sandbox/test modu (određuje fiskalni mod).
     *
     * SIGURNOST: testni Stripe ključ (pk_test_/sk_test_) UVIJEK znači sandbox,
     * bez obzira na kvačicu — tako je nemoguće izdati PRAVI fiskalni račun za
     * testnu (lažnu) uplatu, čak i ako korisnik zabunom isključi "Sandbox".
     */
    public function isSandbox(string $code): bool
    {
        $m = $this->getMethod($code);
        if (!$m) return false;
        $cfg = $m['config'] ?? [];

        if ($code === 'stripe') {
            $pk = (string) ($cfg['publishable_key'] ?? '');
            if (strncmp($pk, 'pk_test_', 8) === 0) return true;
            $sk = (string) (Crypto::decrypt($cfg['secret_key_enc'] ?? null) ?? '');
            if (strncmp($sk, 'sk_test_', 8) === 0) return true;
        }

        return !empty($cfg['sandbox']);
    }
}
