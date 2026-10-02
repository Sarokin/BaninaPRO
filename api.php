<?php
/**
 * BaninaPRO – JSON API
 * Minden hívás: POST /api.php  { "action": "...", ...paraméterek }
 * Válasz:      { "ok": true, "adat": ... }  vagy  { "ok": false, "hiba": "...", "kod": "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ids.php';
require_once __DIR__ . '/includes/rates.php';
require_once __DIR__ . '/includes/api_auth.php';
require_once __DIR__ . '/includes/api_admin.php';
require_once __DIR__ . '/includes/api_cegek.php';
require_once __DIR__ . '/includes/api_bejovo.php';
require_once __DIR__ . '/includes/api_utalas.php';
require_once __DIR__ . '/includes/api_kimeno.php';
require_once __DIR__ . '/includes/api_riport.php';
require_once __DIR__ . '/includes/api_passkey.php';
require_once __DIR__ . '/includes/backup.php';
require_once __DIR__ . '/includes/api_import.php';
require_once __DIR__ . '/includes/api_kereses.php';
require_once __DIR__ . '/includes/api_reszteljesites.php';
require_once __DIR__ . '/includes/audit.php';

ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// Csak POST + JSON
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_valasz(['ok' => false, 'hiba' => 'Csak POST kérés fogadható.', 'kod' => 'METODUS'], 405);
}

$nyers = file_get_contents('php://input');
$be = json_decode($nyers ?: '{}', true);
if (!is_array($be)) {
    json_valasz(['ok' => false, 'hiba' => 'Érvénytelen JSON kérés.', 'kod' => 'JSON'], 400);
}
$action = (string)($be['action'] ?? '');

// Bejelentkezés nélkül is hívható műveletek
$nyilvanos = ['belepes', 'en', 'ping', 'lap_zaras', 'webauthn_beallitasok', 'qr_kerelem', 'qr_allapot', 'qr_info', 'qr_jovahagy', 'qr_elutasit', 'passkey_belepes_opciok', 'passkey_belepes', 'regisztracio_info', 'regisztracio_vegrehajt'];

try {
    // a szívverés és a „lap bezárva” jelzés nem számít felhasználói tevékenységnek (1.14)
    session_inditas(!in_array($action, ['ping', 'lap_zaras'], true));

    if (!preg_match('/^[a-z0-9_]{1,64}$/', $action) || !function_exists('act_' . $action)) {
        hiba('Ismeretlen művelet.', 'ISMERETLEN', [], 404);
    }
    if (!in_array($action, $nyilvanos, true)) {
        csak_bejelentkezve();
        csrf_ellenorzes();
        csak_jog(muvelet_jog($action));   // szerepkör szerinti jogosultság (includes/jogok.php)
        if (karbantartas_aktiv()) {
            hiba('Adatbázis-visszaállítás folyamatban – próbáld újra egy perc múlva.', 'KARBANTARTAS', [], 503);
        }
        // ha az éjszakai cron-mentés elmaradt, az első használatkor pótoljuk
        if (!in_array($action, ['admin_db_visszaallit', 'admin_mentes_most'], true)) {
            mentes_ha_esedekes();
        }
    }
    $fn = 'act_' . $action;
    $adat = $fn($be);
    ok($adat, ['csrf' => csrf_token()]);
} catch (ApiError $e) {
    $http = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    if ($http >= 400 && !in_array($e->kod, ['NINCS_BELEPVE', 'CSRF'], true)) {
        naplo('API:' . $action, 'hiba: ' . $e->getMessage(), 'HIBA');
    }
    json_valasz(['ok' => false, 'hiba' => $e->getMessage(), 'kod' => $e->kod, 'extra' => $e->extra, 'csrf' => csrf_token()], $http);
} catch (PDOException $e) {
    $uzenet = 'Adatbázis-hiba.';
    if ((string)$e->getCode() === '23000') {
        $m = $e->getMessage();
        if (str_contains($m, 'uq_bejovo_kotes_szamlaszam')) {
            $uzenet = 'Ebben a kötésben már szerepel ez a számlaszám – egy kötésen belül ugyanaz a számlaszám csak egyszer rögzíthető (másik kötésben igen).';
        } elseif (str_contains($m, 'szamlaszam')) {
            $uzenet = 'Ez a kimenő számlaszám már szerepel a rendszerben – a kimenő számlák számlaszáma egyedi.';
        } elseif (str_contains($m, 'uq_ceg_nev')) {
            $uzenet = 'Ilyen nevű cég már létezik.';
        } elseif (str_contains($m, 'uq_felhasznalonev')) {
            $uzenet = 'Ez a felhasználónév már foglalt.';
        } elseif (str_contains($m, 'uq_bank_norm')) {
            $uzenet = 'Ez a banki azonosító már rögzítve van.';
        } elseif (str_contains($m, 'foreign key') || str_contains($m, 'FOREIGN KEY')) {
            $uzenet = 'A művelet nem hajtható végre, mert más adatok hivatkoznak rá.';
        } else {
            $uzenet = 'Ütköző adat: ez az érték már létezik.';
        }
    }
    naplo('API:' . $action, 'DB hiba: ' . $e->getMessage(), 'HIBA');
    json_valasz(['ok' => false, 'hiba' => $uzenet . (APP_DEBUG ? ' [' . $e->getMessage() . ']' : ''), 'kod' => 'DB', 'csrf' => csrf_token()], 400);
} catch (Throwable $e) {
    naplo('API:' . $action, 'kivétel: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), 'HIBA');
    json_valasz(['ok' => false, 'hiba' => 'Váratlan szerverhiba.' . (APP_DEBUG ? ' [' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . ']' : ''), 'kod' => 'SZERVER', 'csrf' => csrf_token()], 500);
}
