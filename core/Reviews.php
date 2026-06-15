<?php
/**
 * Reviews — ocjene i recenzije proizvoda. Javno se prikazuju samo 'approved'.
 * Vlasnik moderira u adminu. Jedna recenzija po (proizvod, kupac).
 */
class Reviews
{
    /** ['avg'=>float, 'count'=>int] za odobrene recenzije proizvoda. */
    public static function summary(int $productId): array
    {
        $r = Database::instance()->fetch(
            "SELECT ROUND(AVG(rating),1) a, COUNT(*) c FROM product_reviews WHERE product_id = :p AND status = 'approved'",
            [':p' => $productId]
        );
        return ['avg' => (float) ($r['a'] ?? 0), 'count' => (int) ($r['c'] ?? 0)];
    }

    /** Odobrene recenzije proizvoda (najnovije prve). */
    public static function approved(int $productId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        return Database::instance()->fetchAll(
            "SELECT * FROM product_reviews WHERE product_id = :p AND status = 'approved' ORDER BY id DESC LIMIT $limit",
            [':p' => $productId]
        );
    }

    /** Recenzija koju je kupac već ostavio za proizvod (ili null). */
    public static function byCustomer(int $productId, int $customerId): ?array
    {
        return Database::instance()->fetch(
            'SELECT * FROM product_reviews WHERE product_id = :p AND customer_id = :c',
            [':p' => $productId, ':c' => $customerId]
        );
    }

    /** Je li kupac platio narudžbu s tim artiklom (verificirana kupnja). */
    public static function purchased(int $productId, int $customerId): bool
    {
        return (bool) Database::instance()->fetchColumn(
            "SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = :p AND o.customer_id = :c AND o.payment_status = 'paid' LIMIT 1",
            [':p' => $productId, ':c' => $customerId]
        );
    }

    /** HTML 5 zvjezdica za ocjenu 0–5 (pune/prazne). */
    public static function stars(float $avg, int $px = 15): string
    {
        $full = (int) round($avg);
        $h = '<span class="stars" style="font-size:' . (int) $px . 'px" role="img" aria-label="' . number_format($avg, 1) . ' od 5">';
        for ($i = 1; $i <= 5; $i++) {
            $h .= '<span class="star' . ($i <= $full ? ' on' : '') . '">★</span>';
        }
        return $h . '</span>';
    }
}
