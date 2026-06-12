<?php
/**
 * Cart — košarica u sesiji. Ključ stavke je "productId:variantId" (variantId
 * 0 = bez varijante). Sesija drži SAMO količine — cijene se uvijek ponovno
 * čitaju iz baze pa manipulacija klijenta nije moguća.
 */

class Cart
{
    private const KEY = 'cart';
    private const MAX_QTY = 999;

    /** @return array<string,int> "pid:vid" => qty */
    public static function items(): array
    {
        $c = $_SESSION[self::KEY] ?? [];
        return is_array($c) ? $c : [];
    }

    public static function key(int $productId, int $variantId = 0): string
    {
        return $productId . ':' . max(0, $variantId);
    }

    public static function add(int $productId, int $qty = 1, int $variantId = 0): void
    {
        if ($productId < 1) return;
        $qty = max(1, min(self::MAX_QTY, $qty));
        $k = self::key($productId, $variantId);
        $c = self::items();
        $c[$k] = min(self::MAX_QTY, ($c[$k] ?? 0) + $qty);
        $_SESSION[self::KEY] = $c;
    }

    public static function update(string $key, int $qty): void
    {
        if (!preg_match('/^\d+:\d+$/', $key)) return;
        $c = self::items();
        if ($qty <= 0) {
            unset($c[$key]);
        } else {
            $c[$key] = min(self::MAX_QTY, $qty);
        }
        $_SESSION[self::KEY] = $c;
    }

    public static function remove(string $key): void
    {
        self::update($key, 0);
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /**
     * Stavke s podacima iz baze (samo vidljivi, ne-orphan proizvodi; varijanta
     * mora pripadati proizvodu i biti aktivna). Nevaljane stavke tiho uklanja.
     * Svaki red: product polja + cart_key, qty, variant_id, variant_label,
     * variant_stock, price (efektivna), line_total, display_name, image.
     */
    public static function detailed(): array
    {
        $c = self::items();
        if (!$c) return [];
        $db = Database::instance();

        $pids = [];
        $vids = [];
        foreach ($c as $key => $qty) {
            if (!preg_match('/^(\d+):(\d+)$/', (string) $key, $m)) continue;
            $pids[(int) $m[1]] = true;
            if ((int) $m[2] > 0) $vids[(int) $m[2]] = true;
        }
        if (!$pids) return [];

        $pidList = array_keys($pids);
        $ph = implode(',', array_fill(0, count($pidList), '?'));
        $rows = $db->fetchAll(
            "SELECT p.*, (SELECT filename FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image
             FROM products p WHERE p.id IN ($ph) AND p.is_visible = 1 AND p.is_orphaned = 0",
            $pidList
        );
        $byId = [];
        foreach ($rows as $r) $byId[(int) $r['id']] = $r;

        $variants = [];
        if ($vids) {
            $vidList = array_keys($vids);
            $vph = implode(',', array_fill(0, count($vidList), '?'));
            foreach ($db->fetchAll("SELECT * FROM product_variants WHERE id IN ($vph) AND is_active = 1", $vidList) as $v) {
                $variants[(int) $v['id']] = $v;
            }
        }
        $hasVariants = Variants::countByProduct($pidList);

        $out = [];
        $changed = false;
        foreach ($c as $key => $qty) {
            if (!preg_match('/^(\d+):(\d+)$/', (string) $key, $m)) {
                unset($c[$key]);
                $changed = true;
                continue;
            }
            $pid = (int) $m[1];
            $vid = (int) $m[2];
            $product = $byId[$pid] ?? null;
            $variant = $vid > 0 ? ($variants[$vid] ?? null) : null;

            $valid = $product
                && (!$vid || ($variant && (int) $variant['product_id'] === $pid))   // varijanta pripada proizvodu
                && ($vid || empty($hasVariants[$pid]));                              // proizvod s varijantama traži izbor
            if (!$valid) {
                unset($c[$key]);
                $changed = true;
                continue;
            }

            $row = $product;
            $row['cart_key'] = (string) $key;
            $row['qty'] = (int) $qty;
            $row['variant_id'] = $vid ?: null;
            $row['variant_label'] = $variant['label'] ?? null;
            $row['variant_stock'] = ($variant && $variant['stock_qty'] !== null) ? (float) $variant['stock_qty'] : null;
            $row['price'] = Variants::price($product, $variant);
            $row['line_total'] = round($row['price'] * (int) $qty, 2);
            $row['display_name'] = $row['name'] . ($row['variant_label'] ? ' — ' . $row['variant_label'] : '');
            $out[] = $row;
        }
        if ($changed) $_SESSION[self::KEY] = $c;
        return $out;
    }

    public static function count(): int
    {
        return (int) array_sum(self::items());
    }

    public static function subtotal(?array $detailed = null): float
    {
        $detailed = $detailed ?? self::detailed();
        return round(array_sum(array_column($detailed, 'line_total')), 2);
    }

    /**
     * Provjera zaliha. Varijanta s upisanom zalihom ima prednost; proizvod
     * bez varijante koristi đurđinu zalihu (track_stock).
     * @return string[] poruke o problemima (prazno = sve ok)
     */
    public static function stockProblems(?array $detailed = null): array
    {
        $problems = [];
        foreach ($detailed ?? self::detailed() as $it) {
            $available = null;
            if (!empty($it['variant_id'])) {
                $available = $it['variant_stock']; // null = varijanta se ne prati
            } elseif ((int) $it['track_stock'] === 1 && $it['stock_qty'] !== null) {
                $available = (float) $it['stock_qty'];
            }
            if ($available !== null && $available < $it['qty']) {
                $name = $it['display_name'] ?? $it['name'];
                $problems[] = $available <= 0
                    ? "\"$name\" trenutno nije dostupan."
                    : "\"$name\" — dostupno samo " . (int) $available . " kom.";
            }
        }
        return $problems;
    }
}
