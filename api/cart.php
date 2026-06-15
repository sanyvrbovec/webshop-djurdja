<?php
/**
 * API: košarica (AJAX). Akcije: add, update, remove, summary.
 * Stavke se adresiraju ključem "productId:variantId" (vidi Cart::key).
 */
require_once __DIR__ . '/../core/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Samo POST.'], 405);
}
csrf_check();

$action    = $_POST['action'] ?? 'summary';
$productId = (int) ($_POST['product_id'] ?? 0);
$variantId = (int) ($_POST['variant_id'] ?? 0);
$qty       = (int) ($_POST['qty'] ?? 1);
$key       = (string) ($_POST['key'] ?? '');
if ($key === '' && $productId > 0) $key = Cart::key($productId, $variantId);

switch ($action) {
    case 'add':
        $product = Database::instance()->fetch(
            'SELECT * FROM products WHERE id = :id AND is_visible = 1 AND is_orphaned = 0',
            [':id' => $productId]
        );
        if (!$product) json_out(['ok' => false, 'error' => 'Proizvod nije dostupan.'], 404);

        $qty = max(1, $qty);
        $inCart = (int) (Cart::items()[Cart::key($productId, $variantId)] ?? 0);
        $allowNeg = Djurdja::allowNegativeStock(); // firma dopušta minus → bez ograničenja zalihe

        if (Variants::productHas($productId)) {
            $variant = Variants::resolve($productId, $variantId);
            if (!$variant) {
                json_out(['ok' => false, 'error' => 'Odaberite opciju proizvoda (npr. veličinu ili boju).'], 422);
            }
            if (!$allowNeg && $variant['stock_qty'] !== null && (float) $variant['stock_qty'] < $inCart + $qty) {
                $avail = max(0, (int) $variant['stock_qty'] - $inCart);
                json_out(['ok' => false, 'error' => $avail > 0
                    ? "Na zalihi je još samo $avail kom ove opcije."
                    : 'Ova opcija je trenutno rasprodana.'], 422);
            }
        } else {
            $variantId = 0;
            if (!$allowNeg && (int) $product['track_stock'] === 1 && $product['stock_qty'] !== null
                && (float) $product['stock_qty'] < $inCart + $qty) {
                $avail = max(0, (int) $product['stock_qty'] - $inCart);
                json_out(['ok' => false, 'error' => $avail > 0
                    ? "Na zalihi je još samo $avail kom."
                    : 'Proizvod je trenutno rasprodan.'], 422);
            }
        }
        Cart::add($productId, $qty, $variantId);
        break;

    case 'update':
        Cart::update($key, $qty);
        break;

    case 'remove':
        Cart::remove($key);
        break;

    case 'summary':
        break;

    default:
        json_out(['ok' => false, 'error' => 'Nepoznata akcija.'], 400);
}

$items = Cart::detailed();
$out = [];
foreach ($items as $it) {
    $out[] = [
        'key'          => $it['cart_key'],
        'id'           => (int) $it['id'],
        'name'         => $it['name'],
        'variantLabel' => $it['variant_label'],
        'displayName'  => $it['display_name'],
        'slug'         => $it['slug'],
        'qty'          => (int) $it['qty'],
        'price'        => (float) $it['price'],
        'lineTotal'    => (float) $it['line_total'],
        'image'        => $it['image'] ? upload_url('products/' . $it['image']) : null,
        'url'          => url('p/' . $it['slug']),
    ];
}
$allServices = $items && !array_filter($items, fn($i) => (int) ($i['is_service'] ?? 0) === 0);
json_out([
    'ok'       => true,
    'count'    => Cart::count(),
    'subtotal' => Cart::subtotal($items),
    'subtotalFmt' => fmt_price(Cart::subtotal($items)),
    'items'    => $out,
    'problems' => Cart::stockProblems($items),
    // Za progres-bar besplatne dostave u košarici
    'freeOver'    => (float) s('shipping_free_over', 0),
    'allServices' => $allServices,
]);
