<?php
/**
 * BaninaPRO – mentésfájl letöltése (csak admin, csak a DBBCKP mappából)
 *   GET mentes_letoltes.php?fajl=20260929_0300.sql
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
    $u = aktualis_felhasznalo();
    if (!$u || $u['szerep'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Csak bejelentkezett ADMIN töltheti le a mentéseket.\n";
        exit;
    }
    $nev = (string)($_GET['fajl'] ?? '');
    $ut = backup_fajl_utvonal($nev);
    $meret = (int)filesize($ut);
    naplo('DB_MENTES_LETOLTES', "$nev (" . round($meret / 1024) . ' kB)');
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="BaninaPRO_' . $nev . '"');
    header('Content-Length: ' . $meret);
    $f = fopen($ut, 'rb');
    if ($f) {
        while (!feof($f)) {
            echo fread($f, 262144);
            flush();
        }
        fclose($f);
    }
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'HIBA: ' . $e->getMessage() . "\n";
}
