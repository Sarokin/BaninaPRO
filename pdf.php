<?php
/**
 * BaninaPRO – nyomtatási lista PDF-ben (A4)
 *
 * Hívás: POST /pdf.php  (űrlap)
 *   tetelek = JSON tömb: [{"t":"bejovo","id":12}, {"t":"kotes","id":3}, ...]
 *   csrf    = a munkamenet CSRF-tokenje
 *
 * Csak bejelentkezett felhasználónak. A PDF mindig az adatbázis friss
 * adataiból készül – a böngésző csak azt jegyzi meg, MELY sorokat kérted.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ids.php';
require_once __DIR__ . '/includes/api_reszteljesites.php';
require_once __DIR__ . '/includes/api_bejovo.php';
require_once __DIR__ . '/includes/api_utalas.php';
require_once __DIR__ . '/includes/api_kimeno.php';
require_once __DIR__ . '/includes/pdf.php';
require_once __DIR__ . '/includes/nyomtatas.php';

ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

/** Egyszerű, olvasható hibaoldal (a PDF új lapon nyílik, ide nem jut el a SPA) */
function pdf_hiba(string $uzenet, int $http = 400): void
{
    http_response_code($http);
    header('Content-Type: text/html; charset=utf-8');
    $u = htmlspecialchars($uzenet, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang=\"hu\"><head><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex,nofollow\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>BaninaPRO – nyomtatás</title>"
        . "<style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#FAFAF8;color:#171717;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}"
        . ".k{background:#fff;border:1px solid #E8E8E5;border-radius:18px;padding:28px 30px;max-width:460px;box-shadow:0 12px 40px rgba(0,0,0,.06)}h1{font-size:18px;margin:0 0 10px}p{margin:0 0 18px;color:#525252;line-height:1.5}a{display:inline-block;background:#017F01;color:#fff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:999px}</style></head>"
        . "<body><div class=\"k\"><h1>A PDF nem készíthető el</h1><p>$u</p><a href=\"./\">Vissza a BaninaPRO-hoz</a></div></body></html>";
    exit;
}

try {
    session_inditas();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        pdf_hiba('A nyomtatást az alkalmazásból, a nyomtató gombbal indítsd.', 405);
    }
    $u = aktualis_felhasznalo();
    if (!$u) {
        pdf_hiba('Lejárt a munkameneted – jelentkezz be újra, és próbáld meg ismét.', 401);
    }
    $csrf = (string)($_POST['csrf'] ?? '');
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
        pdf_hiba('Érvénytelen biztonsági token – frissítsd az oldalt és próbáld újra.', 403);
    }

    $nyers = (string)($_POST['tetelek'] ?? '');
    $lista = json_decode($nyers ?: '[]', true);
    if (!is_array($lista)) {
        pdf_hiba('Érvénytelen nyomtatási lista.');
    }
    if (count($lista) > NY_MAX_TETEL) {
        pdf_hiba('Egyszerre legfeljebb ' . NY_MAX_TETEL . ' sor nyomtatható – szűkítsd a nyomtatási kosarat.');
    }

    // tisztítás: csak ismert típus + pozitív egész azonosító, duplikátumok nélkül, sorrend megtartva
    $tetelek = [];
    $latott = [];
    foreach ($lista as $t) {
        if (!is_array($t)) {
            continue;
        }
        $tip = (string)($t['t'] ?? '');
        $id = filter_var($t['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!in_array($tip, NY_TIPUSOK, true) || $id === false) {
            continue;
        }
        $kulcs = "$tip:$id";
        if (isset($latott[$kulcs])) {
            continue;
        }
        $latott[$kulcs] = true;
        $tetel = ['t' => $tip, 'id' => (int)$id];
        // „egyenleg készítés” jelölés (kimenő MIND fül → Mind a kosárba): az időszak címkéje
        if ($tip === 'kimeno' && array_key_exists('e', $t) && (is_string($t['e']) || is_numeric($t['e']))) {
            $tetel['e'] = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$t['e']) ?? '', 0, 120, 'UTF-8');
        }
        $tetelek[] = $tetel;
    }
    if (!$tetelek) {
        pdf_hiba('A nyomtatási kosár üres – előbb jelöld ki a nyomtatni kívánt sorokat a listákban.');
    }

    $pdfAdat = pdf_lista($tetelek, $u);

    $szamlalo = [];
    foreach ($tetelek as $t) {
        $szamlalo[$t['t']] = ($szamlalo[$t['t']] ?? 0) + 1;
    }
    $reszlet = [];
    foreach ($szamlalo as $tip => $n) {
        $reszlet[] = "$tip: $n";
    }
    naplo('PDF_NYOMTATAS', count($tetelek) . ' tétel (' . implode(', ', $reszlet) . '), ' . strlen($pdfAdat) . ' bájt');

    $fajlnev = 'BaninaPRO_lista_' . date('Y-m-d_Hi') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fajlnev . '"');
    header('Content-Length: ' . strlen($pdfAdat));
    echo $pdfAdat;
} catch (ApiError $e) {
    pdf_hiba($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
} catch (Throwable $e) {
    naplo('PDF_NYOMTATAS', 'hiba: ' . $e->getMessage(), 'HIBA');
    pdf_hiba(APP_DEBUG ? $e->getMessage() : 'Váratlan hiba történt a PDF készítése közben. A részleteket a napló tartalmazza.', 500);
}
