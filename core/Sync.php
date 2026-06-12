<?php
/**
 * Sync — sinkronizacija kataloga (kategorije + artikli) iz đurđe u lokalnu bazu.
 *
 * Pravila:
 *  - Izvor istine za naziv, cijenu, PDV, jedinicu, barkod, uslugu i zalihu je đurđa.
 *  - Lokalna obogaćivanja (slike, dugi opis, SEO polja, vidljivost, istaknuto) se NE diraju.
 *  - Đurđin "description" ide u short_description (uvijek sync).
 *  - Slug se generira jednom i NE mijenja (SEO stabilnost), osim ako je proizvod nov.
 *  - Full sync označava artikle kojih više nema u đurđi kao orphaned + nevidljive.
 */

class Sync
{
    /** @return array{ok: bool, message: string, stats: array} */
    public static function run(bool $full = false): array
    {
        $db = Database::instance();
        $client = DjurdjaClient::fromSettings();
        if (!$client) {
            return ['ok' => false, 'message' => 'Đurđa API ključ nije konfiguriran.', 'stats' => []];
        }

        $logId = $db->insert('sync_log', ['type' => $full ? 'full' : 'delta', 'status' => 'running']);
        $startedAt = date('Y-m-d H:i:s');
        $since = $full ? null : Settings::get('catalog_synced_at');
        $stats = ['cats_new' => 0, 'cats_upd' => 0, 'prods_new' => 0, 'prods_upd' => 0, 'orphaned' => 0];

        try {
            $offset = 0;
            $catsDone = false;
            $syncedAtResp = null;

            do {
                $batch = $client->catalog($since, $offset);
                $syncedAtResp = $batch['syncedAt'] ?? $syncedAtResp;

                if (!$catsDone) {
                    foreach (($batch['categories'] ?? []) as $cat) {
                        self::upsertCategory($db, $cat, $stats);
                    }
                    $catsDone = true; // kategorije dolaze samo u prvom batchu
                }

                $products = $batch['products'] ?? [];
                foreach ($products as $p) {
                    self::upsertProduct($db, $p, $stats);
                }

                $offset += count($products);
                $hasMore = !empty($batch['hasMore']) && count($products) > 0;
            } while ($hasMore);

            // Orphani: samo kod full synca (delta ne vraća sve pa ne smijemo zaključivati)
            if ($full) {
                $stats['orphaned'] = $db->query(
                    'UPDATE products SET is_orphaned = 1, is_visible = 0 WHERE synced_at < :t OR synced_at IS NULL',
                    [':t' => $startedAt]
                )->rowCount();
            }

            Settings::set('catalog_synced_at', $syncedAtResp ?: date('c'));
            Settings::set('djurdja_last_ok_at', date('Y-m-d H:i:s'));
            Settings::set('djurdja_key_invalid', '0');

            $msg = sprintf(
                'Kategorije: %d novih, %d ažurirano · Artikli: %d novih, %d ažurirano%s',
                $stats['cats_new'], $stats['cats_upd'], $stats['prods_new'], $stats['prods_upd'],
                $full ? (' · uklonjeno iz ponude: ' . $stats['orphaned']) : ''
            );
            $db->update('sync_log', [
                'status' => 'done', 'message' => $msg,
                'stats' => json_encode($stats), 'finished_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', [':id' => $logId]);

            return ['ok' => true, 'message' => $msg, 'stats' => $stats];
        } catch (Throwable $e) {
            if ($e instanceof DjurdjaApiException && in_array($e->httpStatus, [401, 403], true)) {
                Settings::set('djurdja_key_invalid', '1');
            }
            $db->update('sync_log', [
                'status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 490),
                'finished_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', [':id' => $logId]);
            error_log('[Sync] ' . $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage(), 'stats' => $stats];
        }
    }

    private static function upsertCategory(Database $db, array $cat, array &$stats): void
    {
        $djId = $cat['id'] ?? null;
        $name = trim((string) ($cat['name'] ?? ''));
        if (!$djId || $name === '') return;

        $existing = $db->fetch('SELECT id, name FROM categories WHERE djurdja_id = :d', [':d' => $djId]);
        if ($existing) {
            if ($existing['name'] !== $name) {
                $db->update('categories', ['name' => $name, 'synced_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $existing['id']]);
                $stats['cats_upd']++;
            } else {
                $db->update('categories', ['synced_at' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $existing['id']]);
            }
            return;
        }
        $db->insert('categories', [
            'djurdja_id' => $djId,
            'name'       => $name,
            'slug'       => self::uniqueSlug($db, 'categories', slugify($name)),
            'synced_at'  => date('Y-m-d H:i:s'),
        ]);
        $stats['cats_new']++;
    }

    private static function upsertProduct(Database $db, array $p, array &$stats): void
    {
        $djId = $p['id'] ?? null;
        $name = trim((string) ($p['name'] ?? ''));
        if (!$djId || $name === '') return;

        $catId = null;
        if (!empty($p['categoryId'])) {
            $catId = $db->fetchColumn('SELECT id FROM categories WHERE djurdja_id = :d', [':d' => $p['categoryId']]);
            $catId = $catId ? (int) $catId : null;
        }

        $stock = $p['stock'] ?? null;
        // trackStock eksplicitno iz đurđe ako postoji; inače: zaliha poslana = prati se
        $track = array_key_exists('trackStock', $p) ? (!empty($p['trackStock']) ? 1 : 0) : ($stock === null ? 0 : 1);
        $core = [
            'category_id' => $catId,
            'name'        => mb_substr($name, 0, 255),
            'price'       => round((float) ($p['priceMpc'] ?? 0), 2),
            'vat_rate'    => round((float) ($p['vatRate'] ?? 25), 2),
            'unit'        => mb_substr((string) ($p['unit'] ?? 'kom'), 0, 50),
            'barcode'     => $p['barcode'] ?? null,
            'is_service'  => !empty($p['isService']) ? 1 : 0,
            'stock_qty'   => $stock === null ? null : round((float) $stock, 2),
            'track_stock' => $track,
            'short_description' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 500) ?: null,
            'is_orphaned' => 0,
            'synced_at'   => date('Y-m-d H:i:s'),
        ];

        $existing = $db->fetch('SELECT id FROM products WHERE djurdja_id = :d', [':d' => $djId]);
        if ($existing) {
            $db->update('products', $core, 'id = :id', [':id' => $existing['id']]);
            $stats['prods_upd']++;
            return;
        }
        $core['djurdja_id'] = $djId;
        $core['slug'] = self::uniqueSlug($db, 'products', slugify($name));
        $core['is_visible'] = 1;
        $db->insert('products', $core);
        $stats['prods_new']++;
    }

    private static function uniqueSlug(Database $db, string $table, string $base): string
    {
        $slug = $base;
        $i = 2;
        while ($db->fetchColumn("SELECT id FROM `$table` WHERE slug = :s", [':s' => $slug])) {
            $slug = $base . '-' . $i;
            $i++;
            if ($i > 200) { // sigurnosni ventil
                $slug = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }
        return $slug;
    }
}
