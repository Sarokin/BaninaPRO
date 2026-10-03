<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ids.php';
require_once __DIR__ . '/includes/api_passkey.php';

ini_set('display_errors', APP_DEBUG ? '1' : '0');

// --- Titkos oldal: semmilyen kereső / AI robot nem indexelheti ---------------
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");

$felhasznalo = null;
$csrf = '';
$dbHiba = null;
$beallitasok = null;
$lejart = null;
$qrBelepes = true;
try {
    session_inditas();
    $lejart = munkamenet_lejarat_info();
    $felhasznalo = felhasznalo_publikus(aktualis_felhasznalo());
    $csrf = csrf_token();
    $beallitasok = $felhasznalo ? beallitasok_publikus() : null;
    $qrBelepes = qr_belepes_aktiv();   // 1.15: kikapcsolva a belépő képernyő rögtön a jelszavas űrlapot mutatja
} catch (Throwable $e) {
    $dbHiba = 'Az adatbázis nem elérhető. Ellenőrizd az includes/config.php beállításait és hogy lefuttattad-e az sql/schema.sql fájlt.' . (APP_DEBUG ? ' [' . $e->getMessage() . ']' : '');
}

$v = static function (string $f): string {
    $p = __DIR__ . '/' . $f;
    return $f . '?v=' . (is_file($p) ? (string)filemtime($p) : APP_VERSION);
};
$init = [
    'felhasznalo' => $felhasznalo,
    'beallitasok' => $beallitasok,
    'csrf'        => $csrf,
    'app'         => APP_NAME,
    'verzio'      => APP_VERSION,
    'ma'          => date('Y-m-d'),
    'hiba'        => $dbHiba,
    'lejart'      => $lejart,
    'qr_belepes'  => $qrBelepes,
    'webauthn'    => ['rp_id' => wa_rp_id(), 'origin' => wa_origin(), 'app_url' => wa_app_url(), 'biztonsagos' => wa_https() || in_array(wa_rp_id(), ['localhost', '127.0.0.1'], true), 'qr_lejarat_mp' => QR_LEJARAT_MP],
];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, none">
<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
<meta name="bingbot" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<meta name="format-detection" content="telephone=no">
<meta name="theme-color" content="#FE8302">
<meta name="color-scheme" content="light">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="BaninaPRO">
<meta name="application-name" content="BaninaPRO">
<title>BaninaPRO</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
<link rel="manifest" href="manifest.webmanifest">
<link rel="stylesheet" href="<?= htmlspecialchars($v('assets/app.css'), ENT_QUOTES) ?>">
</head>
<body>
<div id="app" aria-live="polite"></div>
<div id="modal-root"></div>
<div id="toast-root"></div>
<script type="application/json" id="banina-init"><?= json_encode($init, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars($v('assets/qr.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($v('assets/app.js'), ENT_QUOTES) ?>"></script>
</body>
</html>
