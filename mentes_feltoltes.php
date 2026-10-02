<?php
/**
 * BaninaPRO – mentésfájl feltöltése a DBBCKP mappába (csak admin, .sql, multipart POST + CSRF fejléc)
 * Pl. egy korábban letöltött mentés visszatöltéséhez (új tárhelyre költözés, tárhely-hiba után).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/backup.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    session_inditas();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_valasz(['ok' => false, 'hiba' => 'Csak POST.', 'kod' => 'METODUS'], 405);
    }
    csak_admin();
    csrf_ellenorzes();
    if (empty($_FILES['fajl']) || !is_array($_FILES['fajl'])) {
        $max = ini_get('upload_max_filesize');
        hiba("Nem érkezett fájl (a tárhely feltöltési korlátja: $max).");
    }
    $f = $_FILES['fajl'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $max = ini_get('upload_max_filesize');
        hiba(in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? "A fájl túl nagy (a tárhely korlátja: $max)." : 'A feltöltés sikertelen (hiba: ' . (int)$f['error'] . ').');
    }
    $eredeti = basename((string)$f['name']);
    if (!preg_match('/\.sql$/i', $eredeti)) {
        hiba('Csak .sql fájl tölthető fel.');
    }
    $eleje = (string)file_get_contents($f['tmp_name'], false, null, 0, 262144);
    if (!preg_match('/^\s*CREATE TABLE/mi', $eleje) && !preg_match('/^\s*INSERT INTO/mi', $eleje) && !str_starts_with($eleje, BK_FEJLEC)) {
        hiba('A fájl nem tűnik SQL-mentésnek (nincs benne CREATE TABLE / INSERT INTO).');
    }
    // név: a BaninaPRO_ előtag nélkül, ha a saját mintánk; különben ÉÉÉÉHHNN_ÓÓPP_feltoltott.sql
    $nev = preg_replace('/^BaninaPRO_/', '', $eredeti);
    if (!preg_match('/^\d{8}_\d{4}(_[a-z_]+)?(_\d+)?\.sql$/', $nev) || is_file(backup_dir() . '/' . $nev)) {
        $nev = date('Ymd_Hi') . '_feltoltott.sql';
        for ($i = 2; is_file(backup_dir() . '/' . $nev); $i++) {
            $nev = date('Ymd_Hi') . "_feltoltott_$i.sql";
        }
    }
    $ut = backup_fajl_utvonal($nev, false);
    if (!move_uploaded_file($f['tmp_name'], $ut)) {
        hiba('A fájl nem menthető a DBBCKP mappába (írási jog?).');
    }
    @chmod($ut, 0640);
    $info = db_mentes_info($ut);
    naplo('DB_MENTES_FELTOLTES', "$eredeti → $nev (" . round($info['meret'] / 1024) . ' kB' . ($info['sajat'] ? ', BaninaPRO-mentés' . ($info['teljes'] ? ', teljes' : ', CSONKA!') : ', külső SQL') . ')');
    ok(['fajl' => $nev, 'info' => $info], ['csrf' => csrf_token()]);
} catch (ApiError $e) {
    json_valasz(['ok' => false, 'hiba' => $e->getMessage(), 'kod' => $e->kod, 'csrf' => session_status() === PHP_SESSION_ACTIVE ? csrf_token() : ''], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
} catch (Throwable $e) {
    naplo('DB_MENTES_FELTOLTES', 'hiba: ' . $e->getMessage(), 'HIBA');
    json_valasz(['ok' => false, 'hiba' => 'Váratlan hiba a feltöltésnél.', 'kod' => 'HIBA'], 500);
}
