<?php
/**
 * Migrations — automatska nadogradnja sheme baze nakon update-a koda.
 *
 * Verzija sheme živi u settings.schema_version. Svaka nadogradnja je SQL
 * datoteka core/migrations/{N}.sql (N = ciljna verzija). Pokreće se tiho
 * iz bootstrapa; "već postoji" greške (duplikat kolone/tablice/ključa) se
 * preskaču pa je ponovljeno izvođenje bezopasno.
 *
 * NAPOMENA: migracije su u core/ (zaštićen .htaccess "Require all denied")
 * baš zato da PREŽIVE brisanje install/ direktorija nakon instalacije —
 * inače buduće nadogradnje sheme ne bi imale odakle čitati.
 */

class Migrations
{
    /** Verzija sheme koju OVA verzija koda očekuje. */
    public const TARGET = 9;

    /** MySQL error kodovi koji znače "već primijenjeno" — sigurno preskočiti. */
    private const IGNORABLE = [1050, 1060, 1061, 1062, 1091, 1146];

    public static function ensure(): void
    {
        $db = Database::instance();

        // Instalacija još nije gotova (nema settings tablice) → ništa ne radi
        try {
            $db->fetchColumn('SELECT 1 FROM settings LIMIT 1');
        } catch (Throwable $e) {
            return;
        }

        $current = (int) Settings::get('schema_version', '1');
        if ($current >= self::TARGET) return;

        // Lock protiv paralelnog izvođenja (dva requesta istovremeno)
        if (!(int) $db->fetchColumn("SELECT GET_LOCK('djshop_migrate', 5)")) return;

        try {
            // Ponovno pročitaj unutar locka (možda je netko drugi već odradio)
            $current = (int) $db->fetchColumn("SELECT v FROM settings WHERE k = 'schema_version'") ?: $current;

            for ($v = $current + 1; $v <= self::TARGET; $v++) {
                $file = SHOP_ROOT . '/core/migrations/' . $v . '.sql';
                if (!is_file($file)) break;
                self::applyFile($db, $file, $v);
                Settings::set('schema_version', (string) $v);
                error_log("[Migrations] shema nadograđena na v$v");
            }
        } finally {
            $db->fetchColumn("SELECT RELEASE_LOCK('djshop_migrate')");
        }
    }

    private static function applyFile(Database $db, string $file, int $version): void
    {
        $sql = (string) file_get_contents($file);
        // Skini -- komentare pa razdvoji po ; na kraju naredbe
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            try {
                $db->pdo()->exec($stmt);
            } catch (PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($code, self::IGNORABLE, true)) continue;
                error_log("[Migrations] v$version GREŠKA u: " . mb_substr($stmt, 0, 120) . ' — ' . $e->getMessage());
                throw $e;
            }
        }
    }
}
