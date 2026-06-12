<?php
/**
 * Variants — varijante proizvoda (veličina, boja…), LOKALNI koncept shopa.
 *
 * Đurđa vodi artikl kao jednu stavku (cijena, PDV, ukupna zaliha); varijante
 * su obogaćivanje u shopu: do 2 osi (npr. Veličina × Boja), svaka kombinacija
 * može imati vlastitu cijenu (NULL = nasljeđuje artikl), zalihu (NULL = ne
 * prati se) i SKU. Fiskalno se uvijek koristi đurđin artikl; varijanta ide
 * u opis stavke.
 */

class Variants
{
    public static function forProduct(int $productId, bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM product_variants WHERE product_id = :p'
             . ($onlyActive ? ' AND is_active = 1' : '')
             . ' ORDER BY sort_order, id';
        return Database::instance()->fetchAll($sql, [':p' => $productId]);
    }

    /** Ima li proizvod aktivnih varijanti? */
    public static function productHas(int $productId): bool
    {
        return (bool) Database::instance()->fetchColumn(
            'SELECT 1 FROM product_variants WHERE product_id = :p AND is_active = 1 LIMIT 1',
            [':p' => $productId]
        );
    }

    /** product_id => broj aktivnih varijanti, za listu proizvoda. */
    public static function countByProduct(array $productIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::instance()->fetchAll(
            "SELECT product_id, COUNT(*) AS c FROM product_variants
             WHERE is_active = 1 AND product_id IN ($ph) GROUP BY product_id", $ids
        ) as $r) {
            $out[(int) $r['product_id']] = (int) $r['c'];
        }
        return $out;
    }

    /** Varijanta koja STVARNO pripada proizvodu i aktivna je — inače null. */
    public static function resolve(int $productId, int $variantId): ?array
    {
        if ($variantId < 1) return null;
        $v = Database::instance()->fetch(
            'SELECT * FROM product_variants WHERE id = :id AND product_id = :p AND is_active = 1',
            [':id' => $variantId, ':p' => $productId]
        );
        return $v ?: null;
    }

    /** Efektivna cijena varijante (NULL price = nasljeđuje proizvod). */
    public static function price(array $product, ?array $variant): float
    {
        if ($variant && $variant['price'] !== null) return (float) $variant['price'];
        return (float) $product['price'];
    }

    /**
     * Podaci za storefront JS selektor.
     * @return array{axes: array, variants: array}|null  null = proizvod nema varijanti
     */
    public static function storefrontData(array $product): ?array
    {
        $vars = self::forProduct((int) $product['id']);
        if (!$vars) return null;

        $axis1 = ['name' => $vars[0]['option1_name'] ?: 'Opcija', 'values' => []];
        $axis2 = null;
        $items = [];
        foreach ($vars as $v) {
            if (!in_array($v['option1_value'], $axis1['values'], true)) {
                $axis1['values'][] = $v['option1_value'];
            }
            if ($v['option2_value'] !== null && $v['option2_value'] !== '') {
                if ($axis2 === null) $axis2 = ['name' => $v['option2_name'] ?: 'Opcija 2', 'values' => []];
                if (!in_array($v['option2_value'], $axis2['values'], true)) {
                    $axis2['values'][] = $v['option2_value'];
                }
            }
            $stock = $v['stock_qty'] === null ? null : (float) $v['stock_qty'];
            $items[] = [
                'id'    => (int) $v['id'],
                'o1'    => $v['option1_value'],
                'o2'    => $v['option2_value'] ?? '',
                'price' => round(self::price($product, $v), 2),
                'stock' => $stock,              // null = ne prati se
                'out'   => $stock !== null && $stock <= 0,
                'label' => $v['label'],
            ];
        }
        $axes = [$axis1];
        if ($axis2) $axes[] = $axis2;
        return ['axes' => $axes, 'variants' => $items];
    }

    /**
     * Bulk spremanje iz admin forme: zadržava ID-eve postojećih redaka,
     * dodaje nove, briše izostavljene. $rows: niz [id, option1_value,
     * option2_value, sku, price, stock_qty, is_active].
     */
    public static function saveSet(int $productId, string $axis1Name, string $axis2Name, array $rows): void
    {
        $db = Database::instance();
        $axis1Name = mb_substr(trim($axis1Name), 0, 60) ?: 'Opcija';
        $axis2Name = mb_substr(trim($axis2Name), 0, 60);

        $keep = [];
        $sort = 0;
        foreach ($rows as $r) {
            $o1 = mb_substr(trim((string) ($r['option1_value'] ?? '')), 0, 60);
            if ($o1 === '') continue; // prazan redak
            $o2 = mb_substr(trim((string) ($r['option2_value'] ?? '')), 0, 60);
            $label = $o1 . ($o2 !== '' ? ' / ' . $o2 : '');
            $price = trim((string) ($r['price'] ?? ''));
            $stock = trim((string) ($r['stock_qty'] ?? ''));
            $data = [
                'product_id'    => $productId,
                'option1_name'  => $axis1Name,
                'option1_value' => $o1,
                'option2_name'  => $o2 !== '' ? $axis2Name : null,
                'option2_value' => $o2 !== '' ? $o2 : null,
                'label'         => mb_substr($label, 0, 190),
                'sku'           => mb_substr(trim((string) ($r['sku'] ?? '')), 0, 64) ?: null,
                'price'         => $price === '' ? null : round(max(0, (float) str_replace(',', '.', $price)), 2),
                'stock_qty'     => $stock === '' ? null : round(max(0, (float) str_replace(',', '.', $stock)), 2),
                'is_active'     => !empty($r['is_active']) ? 1 : 0,
                'sort_order'    => $sort++,
            ];
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0 && $db->fetchColumn('SELECT id FROM product_variants WHERE id = :id AND product_id = :p', [':id' => $id, ':p' => $productId])) {
                $db->update('product_variants', $data, 'id = :id', [':id' => $id]);
                $keep[] = $id;
            } else {
                $keep[] = (int) $db->insert('product_variants', $data);
            }
        }

        // Obriši izostavljene (povijest narudžbi čuva variant_label u order_items)
        if ($keep) {
            $ph = implode(',', array_fill(0, count($keep), '?'));
            $db->query("DELETE FROM product_variants WHERE product_id = ? AND id NOT IN ($ph)", array_merge([$productId], $keep));
        } else {
            $db->query('DELETE FROM product_variants WHERE product_id = ?', [$productId]);
        }
    }
}
