<?php
/**
 * Cron endpoint — pozivati svakih 5–15 min (hosting cron ili vanjski servis):
 *   curl "https://tvoja-domena.hr/api/cron.php?token=CRON_TOKEN"
 *
 * Radi: (1) retry fiskalizacija u 48h prozoru, (2) osvježavanje đurđa keša + heartbeat,
 *       (3) auto-sync kataloga jednom dnevno (?sync=1 za prisilni full sync).
 */
require_once __DIR__ . '/../core/bootstrap.php';

/**
 * LAZY mod (?lazy=1, bez tokena) — okida ga izlog beaconom iz browsera kupca,
 * pa shop ostaje "skoro live" i BEZ podešenog crona. Smije raditi samo
 * idempotentne stvari (delta sync ≥15 min, fiskalni retry, refresh keša);
 * GET_LOCK + vremenske granice čine zlouporabu besmislenom.
 */
$lazy = isset($_GET['lazy']) && !isset($_GET['token']);

if (!$lazy && (!defined('CRON_TOKEN') || CRON_TOKEN === '' || !hash_equals(CRON_TOKEN, (string) ($_GET['token'] ?? '')))) {
    json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

if ($lazy) {
    // Paralelni beaconi: samo jedan radi, ostali odmah izlaze
    if (!(int) $db->fetchColumn("SELECT GET_LOCK('djshop_lazy', 0)")) {
        json_out(['ok' => true]);
    }
    set_time_limit(50);
} else {
    set_time_limit(280);
}
$out = ['ok' => true, 'ts' => date('c')];

// 1. Fiskalni retry queue
try {
    $results = Fiscalizer::retryDue($db, 10);
    $out['fiscal_retries'] = count($results);
    foreach ($results as $oid => $r) {
        if (!empty($r['success'])) $out['fiscal_ok'][] = $oid;
    }
} catch (Throwable $e) {
    $out['fiscal_error'] = $e->getMessage();
    error_log('[cron] fiscal: ' . $e->getMessage());
}

// 2. Đurđa keš + heartbeat (interno ograničeno na 6h/24h)
try {
    $out['djurdja_refresh'] = Djurdja::refresh(false);
    $out['djurdja_status'] = Djurdja::status();
} catch (Throwable $e) {
    $out['djurdja_error'] = $e->getMessage();
}

// 3. Katalog: lazy = delta sync čim je stariji od 15 min (skoro-live zalihe
//    i cijene, a đurđu pita najviše 4×/h); cron = jednom u 24 h ili ?sync=1|full
try {
    $force = !$lazy && isset($_GET['sync']);
    $maxAge = $lazy ? 15 * 60 : 24 * 3600;
    $last = strtotime((string) s('catalog_synced_at', ''));
    if ($force || !$last || (time() - $last) > $maxAge) {
        $res = Sync::run($force && ($_GET['sync'] === 'full'));
        $out['sync'] = $res['ok'] ? $res['message'] : ('GREŠKA: ' . $res['message']);
    } else {
        $out['sync'] = 'preskočeno (svježe)';
    }
} catch (Throwable $e) {
    $out['sync_error'] = $e->getMessage();
}

if ($lazy) {
    $db->fetchColumn("SELECT RELEASE_LOCK('djshop_lazy')");
    json_out(['ok' => true]); // beaconu ne otkrivamo interne detalje
}
json_out($out);
