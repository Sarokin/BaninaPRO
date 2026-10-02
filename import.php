<?php
/**
 * BaninaPRO – Excel (.xlsx) számlanapló feltöltése és elemzése (régi adatok importja)
 *   POST multipart: fajl=<xlsx>, fejléc: X-CSRF-Token   (bejelentkezve)
 *   Válasz: {ok, adat: {token, elemzes}} – az elemzés még semmit nem ír az adatbázisba.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ids.php';
require_once __DIR__ . '/includes/import.php';

ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    session_inditas();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_valasz(['ok' => false, 'hiba' => 'Csak POST.', 'kod' => 'METODUS'], 405);
    }
    $u = csak_bejelentkezve();
    csrf_ellenorzes();
    csak_jog('ir');
    set_time_limit(300);
    $f = $_FILES['fajl'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $max = ini_get('upload_max_filesize');
        $kod = is_array($f) ? (int)($f['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
        hiba(in_array($kod, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? "A fájl túl nagy (a tárhely feltöltési korlátja: $max)." : 'Nem érkezett fájl, vagy a feltöltés megszakadt.');
    }
    $nev = basename((string)$f['name']);
    if (!preg_match('/\.(xlsx|xlsm)$/i', $nev)) {
        hiba('Csak Excel-munkafüzet (.xlsx) tölthető fel. Régi .xls fájlt Excelben ments el .xlsx-ként.');
    }
    $t0 = microtime(true);
    $el = import_elemez($f['tmp_name'], mb_substr($nev, 0, 120, 'UTF-8'));
    $token = import_ment($el);
    $teljes = import_sorok_allapot($el);
    $o = $teljes['osszesites'];
    naplo('EXCEL_ELEMZES', "$nev ({$el['formatum']}): " . count($teljes['sorok']) . " adatsor (új {$o['uj']}, meglévő {$o['letezik']}, ütköző {$o['utkozik']}, dupla {$o['dupla']}, hibás {$o['hiba']}; cégcsoportok: " . count($el['csoportok']) . ', új ' . $teljes['ceg_osszesites']['uj'] . ', bizonytalan ' . $teljes['ceg_osszesites']['bizonytalan'] . '), ' . round((microtime(true) - $t0) * 1000) . ' ms');
    ok(['token' => $token, 'elemzes' => import_kliens_nezet($teljes), 'mentes_kell' => import_mentes_kell()], ['csrf' => csrf_token()]);
} catch (ApiError $e) {
    json_valasz(['ok' => false, 'hiba' => $e->getMessage(), 'kod' => $e->kod, 'extra' => $e->extra, 'csrf' => session_status() === PHP_SESSION_ACTIVE ? csrf_token() : ''], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
} catch (XlsxHiba $e) {
    json_valasz(['ok' => false, 'hiba' => $e->getMessage(), 'kod' => 'XLSX', 'csrf' => session_status() === PHP_SESSION_ACTIVE ? csrf_token() : ''], 400);
} catch (Throwable $e) {
    naplo('EXCEL_ELEMZES', 'hiba: ' . $e->getMessage(), 'HIBA');
    json_valasz(['ok' => false, 'hiba' => APP_DEBUG ? $e->getMessage() : 'Váratlan hiba az Excel feldolgozásakor. A részletek a naplóban vannak.', 'kod' => 'HIBA'], 500);
}
