<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/jogok.php';

/**
 * Munkamenet-időkorlátok másodpercben (config.php: ULES_* – perc / óra; régi config esetén alapértékek).
 *   jelenlet  – a nyitott lap ennyi mp-enként jelez (szívverés)
 *   lapzaras  – a lap / böngésző bezárása után ennyi ideig még visszanyitható belépve
 *   csend     – ha semmilyen jelzés nem jön, ennyi idő után lejár
 *   tetlenseg – ennyi tevékenység nélküli idő után akkor is lejár, ha a lap nyitva maradt
 */
function ules_korlat(string $mi): int
{
    switch ($mi) {
        case 'lapzaras':
            return max(5, (int)round((defined('ULES_LAPZARAS_PERC') ? ULES_LAPZARAS_PERC : 3) * 60));
        case 'csend':
            return max(5, (int)round((defined('ULES_CSEND_PERC') ? ULES_CSEND_PERC : 10) * 60));
        case 'tetlenseg':
            return max(5, (int)round((defined('ULES_TETLENSEG_ORA') ? ULES_TETLENSEG_ORA : 8) * 3600));
        default:
            return max(5, (int)(defined('ULES_JELENLET_MP') ? ULES_JELENLET_MP : 30));
    }
}

/** Percben / órában kiírt időtartam az üzenetekhez */
function ules_ido_szoveg(int $mp): string
{
    if ($mp >= 3600 && $mp % 3600 === 0) {
        return ($mp / 3600) . ' óra';
    }
    return max(1, (int)round($mp / 60)) . ' perc';
}

/**
 * Lejárt-e a belépett munkamenet? null = él; különben ['naplo' => …, 'uzenet' => …]
 *  1. a lap / böngésző bezárása után (lap_zaras jelzés) nem érkezett újabb kérés a türelmi időn belül
 *  2. semmilyen jelzés (szívverés sem) nem jött a csend-korláton belül (böngésző kilőve, gép alszik, telefon háttérben)
 *  3. tevékenység (kattintás, gépelés) nélkül telt el a tétlenségi korlát
 */
function munkamenet_lejart_e(int $most): ?array
{
    if (empty($_SESSION['utolso_jel'])) {   // 1.14 előtti munkamenet – egyszer újra be kell lépni
        return ['naplo' => 'a program frissült (1.14), újra be kell lépni', 'uzenet' => 'A program frissült, ezért egyszer újra be kell lépned.'];
    }
    $jel = (int)$_SESSION['utolso_jel'];
    $zaras = (int)($_SESSION['lap_zaras'] ?? 0);
    $tev = (int)($_SESSION['utolso_tevekenyseg'] ?? $jel);
    if ($zaras > 0 && $most - $zaras > ules_korlat('lapzaras')) {
        return ['naplo' => 'a lap / böngésző bezárása után nem nyitották vissza ' . ules_ido_szoveg(ules_korlat('lapzaras')) . ' alatt',
                'uzenet' => 'A böngésző vagy a lap be lett zárva, ezért biztonsági okból kiléptettünk.'];
    }
    if ($most - $jel > ules_korlat('csend')) {
        return ['naplo' => 'a böngésző ' . ules_ido_szoveg($most - $jel) . ' óta nem jelentkezett',
                'uzenet' => 'Az alkalmazás több mint ' . ules_ido_szoveg(ules_korlat('csend')) . 'ig nem volt nyitva (bezárt böngésző, alvó gép vagy háttérbe tett telefon), ezért biztonsági okból kiléptettünk.'];
    }
    if ($most - $tev > ules_korlat('tetlenseg')) {
        return ['naplo' => ules_ido_szoveg($most - $tev) . ' tétlenség',
                'uzenet' => 'Több mint ' . ules_ido_szoveg(ules_korlat('tetlenseg')) . ' óta nem volt tevékenység, ezért biztonsági okból kiléptettünk.'];
    }
    return null;
}

/**
 * Munkamenet indítása biztonságos sütibeállításokkal.
 * 1.14: a süti a böngésző bezárásáig él (nincs lejárati dátuma), és a szerver a szívverés / lapzárás-jelzés /
 * tétlenség alapján lépteti ki a felhasználót (lásd munkamenet_lejart_e).
 *
 * $tevekenyseg – a kérés felhasználói tevékenységnek számít-e (a szívverés és a lapzárás-jelzés nem)
 */
function session_inditas(bool $tevekenyseg = true): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    // a szerveren tárolt munkamenet legfeljebb a tétlenségi korlát + 1 óra után takarítható el
    ini_set('session.gc_maxlifetime', (string)(ules_korlat('tetlenseg') + 3600));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,          // munkamenet-süti: a böngésző bezárásakor törlődik
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $most = time();
    if (!empty($_SESSION['uid'])) {
        $oka = munkamenet_lejart_e($most);
        if ($oka !== null) {
            $nev = (string)($_SESSION['felhasznalonev'] ?? '-');
            naplo('KILEPTETES', 'lejárt munkamenet: ' . $oka['naplo'], 'OK', $nev);
            session_unset();
            session_destroy();
            session_start();                                   // új, üres munkamenet új azonosítóval
            $_SESSION['lejart'] = ['oka' => $oka['uzenet'], 'nev' => $nev];   // a belépő képernyő egyszer megmutatja
        }
    }
    $_SESSION['utolso_jel'] = $most;        // bármilyen kérés = a böngésző még nyitva van
    unset($_SESSION['lap_zaras']);          // egy újabb kérés érvényteleníti a korábbi „lap bezárva” jelzést
    if ($tevekenyseg) {
        $_SESSION['utolso_tevekenyseg'] = $most;
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

/** Szívverés: a kliens által jelentett tétlenség (mp) alapján a tevékenység ideje (csak előre léphet) */
function munkamenet_tevekenyseg(int $tetlenMp): void
{
    $tetlenMp = max(0, min($tetlenMp, 10 * 365 * 86400));
    $_SESSION['utolso_tevekenyseg'] = max((int)($_SESSION['utolso_tevekenyseg'] ?? 0), time() - $tetlenMp);
}

/** „Lap bezárva” jelzés (pagehide): innentől csak a türelmi időn belül nyitható vissza belépve */
function munkamenet_lap_zaras(): bool
{
    if (empty($_SESSION['uid'])) {
        return false;
    }
    $_SESSION['lap_zaras'] = time();
    return true;
}

/** Az előző munkamenet lejáratának oka (egyszer olvasható, utána törlődik) */
function munkamenet_lejarat_info(): ?array
{
    $x = $_SESSION['lejart'] ?? null;
    unset($_SESSION['lejart']);
    return is_array($x) ? $x : null;
}

function aktualis_felhasznalo(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $cache = null;
    if ($cache !== null && $cache['id'] === (int)$_SESSION['uid']) {
        return $cache;
    }
    $u = db_row('SELECT id, felhasznalonev, nev, szerep, aktiv FROM felhasznalok WHERE id = ?', [(int)$_SESSION['uid']]);
    if (!$u || !$u['aktiv']) {
        session_unset();
        return null;
    }
    $cache = $u;
    return $u;
}

function aktualis_felhasznalo_nev(): string
{
    return (string)($_SESSION['felhasznalonev'] ?? '-');
}

function bejelentkezve(): bool
{
    return aktualis_felhasznalo() !== null;
}

function admin_e(): bool
{
    $u = aktualis_felhasznalo();
    return $u !== null && $u['szerep'] === 'admin';
}

function csak_bejelentkezve(): array
{
    $u = aktualis_felhasznalo();
    if ($u === null) {
        // 1.14: ha épp most járt le a munkamenet, az okot is elküldjük (a kliens a belépő képernyőn mutatja)
        hiba('Nincs bejelentkezve.', 'NINCS_BELEPVE', ['lejart' => munkamenet_lejarat_info()], 401);
    }
    return $u;
}

function csak_admin(): array
{
    $u = csak_bejelentkezve();
    if ($u['szerep'] !== 'admin') {
        hiba('Ehhez a művelethez ADMIN jogosultság kell.', 'NEM_ADMIN', [], 403);
    }
    return $u;
}

function csrf_token(): string
{
    return (string)($_SESSION['csrf'] ?? '');
}

function csrf_ellenorzes(): void
{
    $kuldott = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($kuldott === '' || !hash_equals(csrf_token(), (string)$kuldott)) {
        hiba('Érvénytelen biztonsági token – frissítsd az oldalt és próbáld újra.', 'CSRF', [], 403);
    }
}

/** Belépési kísérletek korlátozása */
function belepes_blokkolva(string $felhasznalonev, string $ip): bool
{
    $n = (int)db_val(
        'SELECT COUNT(*) FROM belepesi_kiserletek
          WHERE sikeres = 0 AND idopont > (NOW() - INTERVAL ? MINUTE)
            AND (felhasznalonev = ? OR ip = ?)',
        [LOGIN_BLOCK_MINUTES, $felhasznalonev, $ip]
    );
    return $n >= LOGIN_MAX_ATTEMPTS;
}

function bejelentkezes(string $felhasznalonev, string $jelszo): array
{
    $felhasznalonev = mb_strtolower(trim($felhasznalonev));
    $ip = kliens_ip();
    if ($felhasznalonev === '' || $jelszo === '') {
        hiba('Add meg a felhasználónevet és a jelszót!');
    }
    if (belepes_blokkolva($felhasznalonev, $ip)) {
        naplo('BELEPES', "felhasználó=$felhasznalonev – túl sok hibás próbálkozás, blokkolva", 'BLOKKOLVA', $felhasznalonev);
        hiba('Túl sok hibás próbálkozás. Próbáld újra ' . LOGIN_BLOCK_MINUTES . ' perc múlva.', 'BLOKKOLVA', [], 429);
    }
    $u = db_row('SELECT * FROM felhasznalok WHERE felhasznalonev = ?', [$felhasznalonev]);
    $jo = $u && (int)$u['aktiv'] === 1 && !empty($u['jelszo_hash']) && password_verify($jelszo, $u['jelszo_hash']);
    db_exec('INSERT INTO belepesi_kiserletek (felhasznalonev, ip, sikeres) VALUES (?, ?, ?)', [$felhasznalonev, $ip, $jo ? 1 : 0]);
    // régi kísérletek takarítása
    db_exec('DELETE FROM belepesi_kiserletek WHERE idopont < (NOW() - INTERVAL 2 DAY)');

    if (!$jo) {
        naplo('BELEPES', "felhasználó=$felhasznalonev – hibás felhasználónév/jelszó", 'HIBA', $felhasznalonev);
        hiba('Hibás felhasználónév vagy jelszó.', 'HIBAS_BELEPES', [], 401);
    }
    // Jelszavas belépés CSAK tartalék: amint a fiókhoz passkey (telefonos biometria) van regisztrálva, tiltott –
    // kivéve, ha az admin kikapcsolta a QR-kódos belépést (1.15): akkor mindenki jelszóval lép be.
    if (qr_belepes_aktiv() && (int)db_val('SELECT COUNT(*) FROM passkeyek WHERE felhasznalo_id = ? AND aktiv = 1', [(int)$u['id']]) > 0) {
        naplo('BELEPES', "felhasználó=$felhasznalonev – jelszavas belépés tiltva (van regisztrált eszköz)", 'TILTVA', $felhasznalonev);
        hiba('Ehhez a fiókhoz már van regisztrált telefon/eszköz, ezért a jelszavas belépés le van tiltva. Használd a QR-kódos, biometrikus belépést.', 'JELSZO_TILTVA', [], 403);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['felhasznalonev'] = $u['felhasznalonev'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['utolso_jel'] = time();
    $_SESSION['utolso_tevekenyseg'] = time();
    unset($_SESSION['lap_zaras'], $_SESSION['lejart']);
    db_exec('UPDATE felhasznalok SET utolso_belepes = NOW() WHERE id = ?', [(int)$u['id']]);
    if (password_needs_rehash($u['jelszo_hash'], PASSWORD_BCRYPT)) {
        db_exec('UPDATE felhasznalok SET jelszo_hash = ? WHERE id = ?', [password_hash($jelszo, PASSWORD_BCRYPT), (int)$u['id']]);
    }
    naplo('BELEPES', 'sikeres belépés', 'OK', $u['felhasznalonev']);
    return aktualis_felhasznalo();
}

/** Munkamenet beléptetése már ellenőrzött felhasználóval (passkey / QR jóváhagyás után) */
function munkamenet_beleptet(int $felhasznaloId, string $mod): array
{
    $u = db_row('SELECT * FROM felhasznalok WHERE id = ?', [$felhasznaloId]);
    if (!$u || (int)$u['aktiv'] !== 1) {
        hiba('A felhasználó nem aktív.', 'INAKTIV', [], 403);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['felhasznalonev'] = $u['felhasznalonev'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['utolso_jel'] = time();
    $_SESSION['utolso_tevekenyseg'] = time();
    unset($_SESSION['lap_zaras'], $_SESSION['lejart']);
    db_exec('UPDATE felhasznalok SET utolso_belepes = NOW() WHERE id = ?', [(int)$u['id']]);
    db_exec('INSERT INTO belepesi_kiserletek (felhasznalonev, ip, sikeres) VALUES (?, ?, 1)', [$u['felhasznalonev'], kliens_ip()]);
    naplo('BELEPES', "sikeres belépés ($mod)", 'OK', $u['felhasznalonev']);
    return aktualis_felhasznalo();
}

function kijelentkezes(): void
{
    $nev = aktualis_felhasznalo_nev();
    naplo('KILEPES', 'kijelentkezés', 'OK', $nev);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Jelszó-erősség: min. 8 karakter */
function jelszo_ellenorzes(string $jelszo): void
{
    if (mb_strlen($jelszo) < 8) {
        hiba('A jelszó legalább 8 karakter legyen.');
    }
}

/** Kliens felé küldhető felhasználó-adat (jelszó nélkül) */
function felhasznalo_publikus(?array $u): ?array
{
    if ($u === null) {
        return null;
    }
    return [
        'id'             => (int)$u['id'],
        'felhasznalonev' => $u['felhasznalonev'],
        'nev'            => $u['nev'],
        'szerep'         => $u['szerep'],
        'szerep_nev'     => szerep_nev($u['szerep']),
        'admin'          => $u['szerep'] === 'admin',
        'jogok'          => jogok_publikus($u),
    ];
}
