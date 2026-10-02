<?php
/**
 * BaninaPRO – napi teljes adatbázis-mentés (cron)
 *
 * Parancssorból (cPanel → Cron Jobs, minden nap 03:00):
 *     php -q /home/FELHASZNALO/public_html/cron_mentes.php
 * vagy URL-lel (ha a tárhely csak URL-hívást enged) – a kulcsot az Admin → Adatbázis-mentések oldal mutatja:
 *     wget -q -O /dev/null "https://a-domained.hu/cron_mentes.php?kulcs=KULCS"
 *
 * A mentés a DBBCKP mappába kerül: ÉÉÉÉHHNN_ÓÓPP.sql (pl. 20260929_0300.sql).
 *
 * Csak parancssorból (vészhelyzeti visszaállítás, ha a webes felület nem elérhető):
 *     php -q cron_mentes.php lista                       – a mentések listája
 *     php -q cron_mentes.php vissza 20260929_0300.sql    – visszaállítás (előtte automatikus mentés készül)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/backup.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);
$cli = PHP_SAPI === 'cli';

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store');
    $kulcs = (string)($_GET['kulcs'] ?? $_POST['kulcs'] ?? '');
    $tarolt = '';
    try {
        $tarolt = (string)(db_val('SELECT ertek FROM beallitasok WHERE kulcs = ?', ['mentes_cron_kulcs']) ?? '');
    } catch (Throwable $e) {
        http_response_code(500);
        echo "HIBA: az adatbázis nem elérhető.\n";
        exit;
    }
    if ($kulcs === '' || strlen($tarolt) < 32 || !hash_equals($tarolt, $kulcs)) {
        naplo('DB_MENTES', 'cron URL-hívás érvénytelen kulccsal', 'HIBA', 'cron');
        usleep(300000);
        http_response_code(403);
        echo "HIBA: érvénytelen kulcs.\n";
        exit;
    }
}

if ($cli && !empty($argv[1])) {
    try {
        if ($argv[1] === 'lista') {
            foreach (db_mentesek_lista() as $m) {
                printf("%s  %8d kB  %s  %s\n", date('Y-m-d H:i', $m['ido']), (int)round($m['meret'] / 1024), $m['mod_nev'] . str_repeat(' ', max(0, 24 - mb_strlen($m['mod_nev']))), $m['fajl']);
            }
            exit(0);
        }
        if ($argv[1] === 'vissza' && !empty($argv[2])) {
            echo "Visszaállítás: {$argv[2]} – előtte teljes mentés készül a mostani állapotról.\n";
            $r = db_visszaallit((string)$argv[2], 'cli');
            printf("KÉSZ: %d utasítás, %d tábla, %s mp. A visszaállítás előtti állapot mentése: %s\n", $r['utasitasok'], $r['tablak'], $r['masodperc'], $r['elotte']);
            exit(0);
        }
        echo "Használat: php -q cron_mentes.php [lista | vissza FÁJLNÉV.sql]\n";
        exit(1);
    } catch (Throwable $e) {
        echo 'HIBA: ' . $e->getMessage() . "\n";
        exit(1);
    }
}

try {
    $r = db_mentes('auto', 'cron');
    $uz = sprintf("OK: %s (%d kB, %d tábla, %d sor, %s mp)\n", $r['fajl'], (int)round($r['meret'] / 1024), $r['tablak'], $r['sorok'], $r['masodperc']);
    echo $uz;
    exit(0);
} catch (Throwable $e) {
    if (!$cli) {
        http_response_code(500);
    }
    echo 'HIBA: ' . $e->getMessage() . "\n";
    exit(1);
}
