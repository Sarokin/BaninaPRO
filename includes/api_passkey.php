<?php
declare(strict_types=1);

require_once __DIR__ . '/webauthn.php';

// ---------------------------------------------------------------------------
//  Beállítások: RP ID, origin, alap-URL (automatikus felismerés, ha üres)
// ---------------------------------------------------------------------------
function wa_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

function wa_host(): string
{
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return preg_replace('/[^a-z0-9\.\-:\[\]]/', '', $h) ?? 'localhost';
}

function wa_rp_id(): string
{
    if (WEBAUTHN_RP_ID !== '') {
        return WEBAUTHN_RP_ID;
    }
    $h = wa_host();
    return preg_replace('/:\d+$/', '', $h) ?? $h;
}

function wa_origin(): string
{
    if (APP_ORIGIN !== '') {
        return rtrim(APP_ORIGIN, '/');
    }
    return (wa_https() ? 'https' : 'http') . '://' . wa_host();
}

function wa_app_url(): string
{
    if (APP_URL !== '') {
        return rtrim(APP_URL, '/') . '/';
    }
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return wa_origin() . $dir . '/';
}

/** Nyilvános, a kliensnek szükséges beállítások */
function act_webauthn_beallitasok(array $be): array
{
    $bizt = wa_https() || in_array(wa_rp_id(), ['localhost', '127.0.0.1'], true);
    return ['rp_id' => wa_rp_id(), 'origin' => wa_origin(), 'app_url' => wa_app_url(), 'biztonsagos' => $bizt, 'qr_lejarat_mp' => QR_LEJARAT_MP];
}

// ---------------------------------------------------------------------------
//  Segédek
// ---------------------------------------------------------------------------
function kerelem_token(): string
{
    return b64url_enc(random_bytes(18)); // 144 bit, 24 karakter
}

function kerelem_challenge(): string
{
    return b64url_enc(random_bytes(32));
}

function kerelem_kod(): string
{
    return str_pad((string)random_int(0, 99), 2, '0', STR_PAD_LEFT);
}

function kerelem_ua(): string
{
    return mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

/** Emberi olvasású böngésző/eszköz név a user agentből */
function ua_rovid(?string $ua): string
{
    $ua = (string)$ua;
    $os = 'ismeretlen eszköz';
    if (preg_match('/iPhone/i', $ua)) $os = 'iPhone';
    elseif (preg_match('/iPad/i', $ua)) $os = 'iPad';
    elseif (preg_match('/Android/i', $ua)) $os = 'Android';
    elseif (preg_match('/Windows/i', $ua)) $os = 'Windows PC';
    elseif (preg_match('/Macintosh|Mac OS/i', $ua)) $os = 'Mac';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
    $b = '';
    if (preg_match('/Edg\//i', $ua)) $b = 'Edge';
    elseif (preg_match('/OPR\//i', $ua)) $b = 'Opera';
    elseif (preg_match('/Chrome\//i', $ua)) $b = 'Chrome';
    elseif (preg_match('/Firefox\//i', $ua)) $b = 'Firefox';
    elseif (preg_match('/Safari\//i', $ua)) $b = 'Safari';
    return trim($os . ($b ? " · $b" : ''));
}

function session_hash_akt(): string
{
    return hash('sha256', session_id());
}

/** Lejárt kérelmek státuszának rendezése + régiek törlése */
function kerelmek_takarit(): void
{
    db_exec('UPDATE belepesi_kerelmek SET statusz = "EXPIRED" WHERE statusz IN ("PENDING","APPROVED") AND lejar < NOW()');
    db_exec('DELETE FROM belepesi_kerelmek WHERE letrehozva < (NOW() - INTERVAL 2 DAY)');
}

function kerelem_betolt(string $token, bool $zarol = false): array
{
    if (!preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $token)) {
        hiba('Érvénytelen kód.', 'TOKEN');
    }
    $k = db_row('SELECT * FROM belepesi_kerelmek WHERE token = ?' . ($zarol ? ' FOR UPDATE' : ''), [$token]);
    if (!$k) {
        hiba('A kód nem található vagy már felhasználták.', 'TOKEN', [], 404);
    }
    if (in_array($k['statusz'], ['PENDING', 'APPROVED'], true) && strtotime($k['lejar']) < time()) {
        db_exec('UPDATE belepesi_kerelmek SET statusz = "EXPIRED" WHERE id = ?', [(int)$k['id']]);
        $k['statusz'] = 'EXPIRED';
    }
    return $k;
}

function ip_limit(string $muvelet, int $max): void
{
    $n = (int)db_val('SELECT COUNT(*) FROM belepesi_kerelmek WHERE keres_ip = ? AND letrehozva > (NOW() - INTERVAL 15 MINUTE)', [kliens_ip()]);
    if ($n >= $max) {
        naplo($muvelet, 'túl sok kérés erről az IP-ről', 'BLOKKOLVA', '-');
        hiba('Túl sok kérés. Próbáld újra néhány perc múlva.', 'LIMIT', [], 429);
    }
}

function passkeyek_felhasznalohoz(int $felhasznaloId): array
{
    return db_all('SELECT id, credential_id, public_key, alg, sign_count, transports, eszkoz_nev, letrehozva, utolso_hasznalat FROM passkeyek WHERE felhasznalo_id = ? AND aktiv = 1 ORDER BY id', [$felhasznaloId]);
}

function allow_credentials(array $passkeyek): array
{
    return array_map(fn($p) => [
        'type' => 'public-key',
        'id' => $p['credential_id'],
        'transports' => $p['transports'] ? explode(',', $p['transports']) : ['internal', 'hybrid'],
    ], $passkeyek);
}

function passkey_publikus(array $p): array
{
    return [
        'id' => (int)$p['id'], 'eszkoz_nev' => $p['eszkoz_nev'], 'letrehozva' => $p['letrehozva'],
        'utolso_hasznalat' => $p['utolso_hasznalat'], 'transports' => $p['transports'],
    ];
}

/** Regisztrációs (create) opciók összeállítása egy felhasználóhoz */
function regisztracio_opciok(array $u, string $challenge): array
{
    $meglevo = passkeyek_felhasznalohoz((int)$u['id']);
    return [
        'challenge' => $challenge,
        'rp' => ['id' => wa_rp_id(), 'name' => APP_NAME],
        'user' => ['id' => b64url_enc('banina-user-' . $u['id']), 'name' => $u['felhasznalonev'], 'displayName' => $u['nev'] ?: $u['felhasznalonev']],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'preferred'],
        'attestation' => 'none',
        'timeout' => 90000,
        'excludeCredentials' => array_map(fn($p) => ['type' => 'public-key', 'id' => $p['credential_id']], $meglevo),
    ];
}

/** Regisztráció eredményének ellenőrzése és mentése */
function passkey_ment(array $u, array $att, string $challenge, ?string $eszkozNev): int
{
    $r = webauthn_regisztracio_ellenoriz($att, $challenge, wa_rp_id(), wa_origin());
    if (db_row('SELECT id FROM passkeyek WHERE credential_id = ?', [$r['credId']])) {
        hiba('Ez az eszköz már regisztrálva van.', 'WEBAUTHN');
    }
    $nev = $eszkozNev ? mb_substr(trim($eszkozNev), 0, 100) : ua_rovid(kerelem_ua());
    if ($nev === '') {
        $nev = ua_rovid(kerelem_ua());
    }
    db_exec(
        'INSERT INTO passkeyek (felhasznalo_id, credential_id, public_key, alg, sign_count, aaguid, transports, eszkoz_nev, letrehozo_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [(int)$u['id'], $r['credId'], $r['pem'], $r['alg'], $r['signCount'], $r['aaguid'], $r['transports'] ?: null, $nev, kliens_ip()]
    );
    $id = (int)db()->lastInsertId();
    naplo('PASSKEY_REGISZTRACIO', "új eszköz regisztrálva: „{$nev}” (#$id, alg {$r['alg']}) – felhasználó: {$u['felhasznalonev']}", 'OK', $u['felhasznalonev']);
    return $id;
}

/** Assertion ellenőrzése a felhasználó valamelyik passkey-jével → a passkey sor */
function passkey_ellenoriz_belepes(array $u, array $asr, string $challenge): array
{
    $credId = (string)($asr['id'] ?? '');
    $pk = db_row('SELECT * FROM passkeyek WHERE credential_id = ? AND aktiv = 1', [$credId]);
    if (!$pk || (int)$pk['felhasznalo_id'] !== (int)$u['id']) {
        naplo('PASSKEY_BELEPES', "ismeretlen vagy más felhasználóhoz tartozó eszköz – felhasználó: {$u['felhasznalonev']}", 'HIBA', $u['felhasznalonev']);
        hiba('Ez az eszköz nincs ehhez a felhasználóhoz regisztrálva.', 'WEBAUTHN', [], 403);
    }
    $ujSzamlalo = webauthn_assertion_ellenoriz($asr, $pk, $challenge, wa_rp_id(), wa_origin());
    db_exec('UPDATE passkeyek SET sign_count = ?, utolso_hasznalat = NOW() WHERE id = ?', [$ujSzamlalo, (int)$pk['id']]);
    return $pk;
}

// ---------------------------------------------------------------------------
//  1) ASZTALI GÉP: QR-kódos belépési kérelem
// ---------------------------------------------------------------------------

/** Belépési kérelem létrehozása (felhasználónév megadása után) → QR-kód tartalma */
function act_qr_kerelem(array $be): array
{
    qr_belepes_kell();                      // 1.15: az admin kikapcsolhatja a QR-kódos belépést
    kerelmek_takarit();
    ip_limit('QR_KERELEM', QR_KERELEM_LIMIT);
    $nev = mb_strtolower(trim((string)($be['felhasznalonev'] ?? '')));
    if ($nev === '' || !preg_match('/^[a-z0-9._-]{3,64}$/', $nev)) {
        hiba('Add meg a felhasználónevet!');
    }
    $u = db_row('SELECT id, felhasznalonev, aktiv FROM felhasznalok WHERE felhasznalonev = ?', [$nev]);
    // ismeretlen felhasználónál is létrejön a kérelem (ne lehessen felhasználóneveket kitalálni)
    $token = kerelem_token();
    $challenge = kerelem_challenge();
    $kod = kerelem_kod();
    db_exec(
        'INSERT INTO belepesi_kerelmek (token, tipus, felhasznalonev, felhasznalo_id, session_hash, challenge, kod, keres_ip, keres_ua, statusz, lejar)
         VALUES (?, "BELEPES", ?, ?, ?, ?, ?, ?, ?, "PENDING", NOW() + INTERVAL ? SECOND)',
        [$token, $nev, $u && (int)$u['aktiv'] === 1 ? (int)$u['id'] : null, session_hash_akt(), $challenge, $kod, kliens_ip(), kerelem_ua(), QR_LEJARAT_MP]
    );
    naplo('QR_KERELEM', "belépési QR kérve – felhasználó: $nev, eszköz: " . ua_rovid(kerelem_ua()), 'OK', $nev);
    return [
        'token' => $token,
        'url' => wa_app_url() . '#/qr/' . $token,
        'kod' => $kod,
        'lejarat_mp' => QR_LEJARAT_MP,
        'van_passkey' => null, // szándékosan nem áruljuk el
    ];
}

/** Asztali gép lekérdezi a kérelem állapotát; jóváhagyás után beléptet */
function act_qr_allapot(array $be): array
{
    $token = (string)($be['token'] ?? '');
    $k = kerelem_betolt($token, true);
    if ($k['tipus'] !== 'BELEPES' || !hash_equals((string)$k['session_hash'], session_hash_akt())) {
        hiba('Ez a kérelem nem ehhez a böngészőhöz tartozik.', 'TOKEN', [], 403);
    }
    if ($k['statusz'] === 'APPROVED') {
        qr_belepes_kell();                  // a kikapcsolás előtt jóváhagyott kérés sem léptet be utána
        db_exec('UPDATE belepesi_kerelmek SET statusz = "USED" WHERE id = ?', [(int)$k['id']]);
        $pk = $k['jovahagyo_passkey_id'] ? db_row('SELECT eszkoz_nev FROM passkeyek WHERE id = ?', [(int)$k['jovahagyo_passkey_id']]) : null;
        $u = munkamenet_beleptet((int)$k['felhasznalo_id'], 'QR + biometrikus jóváhagyás' . ($pk ? ", eszköz: „{$pk['eszkoz_nev']}”" : ''));
        return ['statusz' => 'BELEPVE', 'felhasznalo' => felhasznalo_publikus($u), 'eszkoz' => $pk['eszkoz_nev'] ?? null];
    }
    return ['statusz' => $k['statusz'], 'lejar_mp' => max(0, strtotime($k['lejar']) - time())];
}

// ---------------------------------------------------------------------------
//  2) TELEFON: a QR beolvasása után – kérelem adatai, jóváhagyás, elutasítás
// ---------------------------------------------------------------------------

/** A telefon lekéri, mit hagy jóvá (+ WebAuthn opciók) */
function act_qr_info(array $be): array
{
    $k = kerelem_betolt((string)($be['token'] ?? ''));
    if ($k['tipus'] !== 'BELEPES') {
        hiba('Érvénytelen kód.', 'TOKEN');
    }
    $ki = [
        'statusz' => $k['statusz'], 'felhasznalonev' => $k['felhasznalonev'], 'kod' => $k['kod'],
        'keres_eszkoz' => ua_rovid($k['keres_ua']), 'keres_ip' => $k['keres_ip'], 'letrehozva' => $k['letrehozva'],
        'lejar_mp' => max(0, strtotime($k['lejar']) - time()),
        'opciok' => null, 'van_passkey' => false,
    ];
    if ($k['statusz'] === 'PENDING' && $k['felhasznalo_id']) {
        $pkk = passkeyek_felhasznalohoz((int)$k['felhasznalo_id']);
        $ki['van_passkey'] = count($pkk) > 0;
        if ($pkk) {
            $ki['opciok'] = ['challenge' => $k['challenge'], 'rpId' => wa_rp_id(), 'allowCredentials' => allow_credentials($pkk), 'userVerification' => 'required', 'timeout' => 90000];
        }
    } elseif ($k['statusz'] === 'PENDING') {
        // ismeretlen felhasználó: ugyanaz az üzenet, mint amikor nincs eszköz (ne lehessen felhasználót kitalálni)
        $ki['van_passkey'] = false;
    }
    return $ki;
}

/** Jóváhagyás a telefonon (biometrikus assertion) */
function act_qr_jovahagy(array $be): array
{
    qr_belepes_kell();                      // 1.15: az admin kikapcsolhatja a QR-kódos belépést
    $token = (string)($be['token'] ?? '');
    $asr = $be['assertion'] ?? null;
    if (!is_array($asr)) {
        hiba('Hiányzó hitelesítési adat.', 'WEBAUTHN');
    }
    $e = db_tx(function () use ($token, $asr) {
        $k = kerelem_betolt($token, true);
        if ($k['tipus'] !== 'BELEPES' || $k['statusz'] !== 'PENDING') {
            hiba('Ez a belépési kérés már nem érvényes (lejárt vagy felhasználták). Kérj új QR-kódot a gépen.', 'TOKEN', [], 410);
        }
        if (!$k['felhasznalo_id']) {
            hiba('Ehhez a felhasználónévhez nincs regisztrált eszköz.', 'WEBAUTHN', [], 403);
        }
        $u = db_row('SELECT * FROM felhasznalok WHERE id = ?', [(int)$k['felhasznalo_id']]);
        if (!$u || (int)$u['aktiv'] !== 1) {
            hiba('A felhasználó nem aktív.', 'INAKTIV', [], 403);
        }
        $pk = passkey_ellenoriz_belepes($u, $asr, $k['challenge']);
        db_exec('UPDATE belepesi_kerelmek SET statusz = "APPROVED", jovahagyva = NOW(), jovahagyo_passkey_id = ?, jovahagyo_ip = ? WHERE id = ?', [(int)$pk['id'], kliens_ip(), (int)$k['id']]);
        return ['u' => $u, 'pk' => $pk, 'k' => $k];
    });
    naplo('QR_JOVAHAGYAS', "belépés jóváhagyva telefonról – eszköz „{$e['pk']['eszkoz_nev']}”, kérő gép: " . ua_rovid($e['k']['keres_ua']) . " ({$e['k']['keres_ip']})", 'OK', $e['u']['felhasznalonev']);
    return ['jovahagyva' => true, 'felhasznalonev' => $e['u']['felhasznalonev'], 'keres_eszkoz' => ua_rovid($e['k']['keres_ua'])];
}

/** Elutasítás a telefonon */
function act_qr_elutasit(array $be): array
{
    $k = kerelem_betolt((string)($be['token'] ?? ''), true);
    if ($k['tipus'] === 'BELEPES' && $k['statusz'] === 'PENDING') {
        db_exec('UPDATE belepesi_kerelmek SET statusz = "DENIED", jovahagyo_ip = ? WHERE id = ?', [kliens_ip(), (int)$k['id']]);
        naplo('QR_ELUTASITAS', 'belépési kérés elutasítva telefonról – kérő gép: ' . ua_rovid($k['keres_ua']) . " ({$k['keres_ip']})", 'OK', $k['felhasznalonev']);
    }
    return ['elutasitva' => true];
}

// ---------------------------------------------------------------------------
//  3) UGYANAZON AZ ESZKÖZÖN: passkey-jel belépés (Face ID / Touch ID / Windows Hello)
// ---------------------------------------------------------------------------
function act_passkey_belepes_opciok(array $be): array
{
    qr_belepes_kell();                      // 1.15: az admin kikapcsolhatja a QR-kódos belépést
    kerelmek_takarit();
    ip_limit('PASSKEY_BELEPES', QR_KERELEM_LIMIT);
    $nev = mb_strtolower(trim((string)($be['felhasznalonev'] ?? '')));
    if ($nev === '' || !preg_match('/^[a-z0-9._-]{3,64}$/', $nev)) {
        hiba('Add meg a felhasználónevet!');
    }
    $u = db_row('SELECT id, felhasznalonev, aktiv FROM felhasznalok WHERE felhasznalonev = ?', [$nev]);
    $pkk = $u && (int)$u['aktiv'] === 1 ? passkeyek_felhasznalohoz((int)$u['id']) : [];
    $token = kerelem_token();
    $challenge = kerelem_challenge();
    db_exec(
        'INSERT INTO belepesi_kerelmek (token, tipus, felhasznalonev, felhasznalo_id, session_hash, challenge, kod, keres_ip, keres_ua, statusz, lejar)
         VALUES (?, "HELYI", ?, ?, ?, ?, "00", ?, ?, "PENDING", NOW() + INTERVAL 120 SECOND)',
        [$token, $nev, $pkk ? (int)$u['id'] : null, session_hash_akt(), $challenge, kliens_ip(), kerelem_ua()]
    );
    return [
        'token' => $token,
        'van_passkey' => count($pkk) > 0,
        'opciok' => $pkk ? ['challenge' => $challenge, 'rpId' => wa_rp_id(), 'allowCredentials' => allow_credentials($pkk), 'userVerification' => 'required', 'timeout' => 90000] : null,
    ];
}

function act_passkey_belepes(array $be): array
{
    qr_belepes_kell();                      // 1.15: az admin kikapcsolhatja a QR-kódos belépést
    $token = (string)($be['token'] ?? '');
    $asr = $be['assertion'] ?? null;
    if (!is_array($asr)) {
        hiba('Hiányzó hitelesítési adat.', 'WEBAUTHN');
    }
    $e = db_tx(function () use ($token, $asr) {
        $k = kerelem_betolt($token, true);
        if ($k['tipus'] !== 'HELYI' || $k['statusz'] !== 'PENDING' || !hash_equals((string)$k['session_hash'], session_hash_akt()) || !$k['felhasznalo_id']) {
            hiba('A belépési kérés nem érvényes. Próbáld újra.', 'TOKEN', [], 410);
        }
        $u = db_row('SELECT * FROM felhasznalok WHERE id = ?', [(int)$k['felhasznalo_id']]);
        $pk = passkey_ellenoriz_belepes($u, $asr, $k['challenge']);
        db_exec('UPDATE belepesi_kerelmek SET statusz = "USED", jovahagyva = NOW(), jovahagyo_passkey_id = ?, jovahagyo_ip = ? WHERE id = ?', [(int)$pk['id'], kliens_ip(), (int)$k['id']]);
        return ['u' => $u, 'pk' => $pk];
    });
    $felh = munkamenet_beleptet((int)$e['u']['id'], "passkey ezen az eszközön, eszköz: „{$e['pk']['eszkoz_nev']}”");
    return ['felhasznalo' => felhasznalo_publikus($felh)];
}

// ---------------------------------------------------------------------------
//  4) ESZKÖZ-REGISZTRÁCIÓ
// ---------------------------------------------------------------------------

/** Bejelentkezve: ennek az eszköznek a regisztrálása – opciók */
function act_passkey_regisztracio_opciok(array $be): array
{
    $u = csak_bejelentkezve();
    $challenge = kerelem_challenge();
    $_SESSION['pk_reg_challenge'] = $challenge;
    $_SESSION['pk_reg_ido'] = time();
    $teljes = db_row('SELECT * FROM felhasznalok WHERE id = ?', [$u['id']]);
    return ['opciok' => regisztracio_opciok($teljes, $challenge)];
}

/** Bejelentkezve: regisztráció végrehajtása */
function act_passkey_regisztracio(array $be): array
{
    $u = csak_bejelentkezve();
    $challenge = (string)($_SESSION['pk_reg_challenge'] ?? '');
    if ($challenge === '' || time() - (int)($_SESSION['pk_reg_ido'] ?? 0) > 300) {
        hiba('A regisztráció lejárt, kezdd újra.', 'WEBAUTHN');
    }
    unset($_SESSION['pk_reg_challenge'], $_SESSION['pk_reg_ido']);
    $att = $be['attestation'] ?? null;
    if (!is_array($att)) {
        hiba('Hiányzó regisztrációs adat.', 'WEBAUTHN');
    }
    $teljes = db_row('SELECT * FROM felhasznalok WHERE id = ?', [$u['id']]);
    $id = passkey_ment($teljes, $att, $challenge, isset($be['eszkoz_nev']) ? (string)$be['eszkoz_nev'] : null);
    return ['id' => $id, 'passkeyek' => array_map('passkey_publikus', passkeyek_felhasznalohoz((int)$u['id']))];
}

/** Saját eszközök listája */
function act_passkeyek(array $be): array
{
    $u = csak_bejelentkezve();
    $van_jelszo = !empty(db_val('SELECT jelszo_hash FROM felhasznalok WHERE id = ?', [$u['id']]));
    return ['passkeyek' => array_map('passkey_publikus', passkeyek_felhasznalohoz((int)$u['id'])), 'van_jelszo' => $van_jelszo];
}

/** Saját eszköz törlése */
function act_passkey_torol(array $be): array
{
    $u = csak_bejelentkezve();
    $id = be_int($be, 'id');
    $pk = db_row('SELECT * FROM passkeyek WHERE id = ? AND felhasznalo_id = ?', [$id, $u['id']]);
    if (!$pk) {
        hiba('Az eszköz nem található.');
    }
    db_exec('DELETE FROM passkeyek WHERE id = ?', [$id]);
    naplo('PASSKEY_TOROL', "eszköz törölve: „{$pk['eszkoz_nev']}” (#$id)");
    return ['torolve' => true];
}

/** ADMIN: eszköz-regisztrációs kód (QR/link) kiadása egy felhasználónak */
function act_admin_regisztracios_kod(array $be): array
{
    $admin = csak_admin();
    $id = be_int($be, 'id');
    $u = db_row('SELECT id, felhasznalonev, nev, aktiv FROM felhasznalok WHERE id = ?', [$id]);
    if (!$u) {
        hiba('A felhasználó nem található.');
    }
    kerelmek_takarit();
    $token = kerelem_token();
    db_exec(
        'INSERT INTO belepesi_kerelmek (token, tipus, felhasznalonev, felhasznalo_id, challenge, kod, keres_ip, keres_ua, statusz, lejar, letrehozta)
         VALUES (?, "REGISZTRACIO", ?, ?, ?, "00", ?, ?, "PENDING", NOW() + INTERVAL ? MINUTE, ?)',
        [$token, $u['felhasznalonev'], (int)$u['id'], kerelem_challenge(), kliens_ip(), kerelem_ua(), REGKOD_LEJARAT_PERC, $admin['id']]
    );
    naplo('ADMIN_REGKOD', "eszköz-regisztrációs kód kiadva: {$u['felhasznalonev']} (érvényes " . REGKOD_LEJARAT_PERC . ' percig)');
    return ['token' => $token, 'url' => wa_app_url() . '#/regisztral/' . $token, 'lejarat_perc' => REGKOD_LEJARAT_PERC, 'felhasznalonev' => $u['felhasznalonev']];
}

/** ADMIN: egy felhasználó eszközei */
function act_admin_passkeyek(array $be): array
{
    csak_admin();
    $id = be_int($be, 'id');
    return ['passkeyek' => array_map('passkey_publikus', passkeyek_felhasznalohoz($id))];
}

/** ADMIN: egy felhasználó összes eszközének törlése (utána jelszóval / új kóddal léphet be) */
function act_admin_passkey_torol(array $be): array
{
    csak_admin();
    $id = be_int($be, 'id');
    $u = db_row('SELECT felhasznalonev FROM felhasznalok WHERE id = ?', [$id]);
    if (!$u) {
        hiba('A felhasználó nem található.');
    }
    $n = db_exec('DELETE FROM passkeyek WHERE felhasznalo_id = ?', [$id]);
    naplo('ADMIN_PASSKEY_TOROL', "{$u['felhasznalonev']} összes eszköze törölve ($n db)");
    return ['torolve' => $n];
}

/** Telefon: regisztrációs kód adatai + create opciók */
function act_regisztracio_info(array $be): array
{
    $k = kerelem_betolt((string)($be['token'] ?? ''));
    if ($k['tipus'] !== 'REGISZTRACIO') {
        hiba('Érvénytelen kód.', 'TOKEN');
    }
    $ki = ['statusz' => $k['statusz'], 'felhasznalonev' => $k['felhasznalonev'], 'lejar_mp' => max(0, strtotime($k['lejar']) - time()), 'opciok' => null];
    if ($k['statusz'] === 'PENDING') {
        $u = db_row('SELECT * FROM felhasznalok WHERE id = ?', [(int)$k['felhasznalo_id']]);
        if ($u) {
            $ki['nev'] = $u['nev'];
            $ki['opciok'] = regisztracio_opciok($u, $k['challenge']);
        }
    }
    return $ki;
}

/** Telefon: regisztráció végrehajtása a kóddal; siker után be is lépteti ezt az eszközt */
function act_regisztracio_vegrehajt(array $be): array
{
    $token = (string)($be['token'] ?? '');
    $att = $be['attestation'] ?? null;
    if (!is_array($att)) {
        hiba('Hiányzó regisztrációs adat.', 'WEBAUTHN');
    }
    $e = db_tx(function () use ($token, $att, $be) {
        $k = kerelem_betolt($token, true);
        if ($k['tipus'] !== 'REGISZTRACIO' || $k['statusz'] !== 'PENDING') {
            hiba('Ez a regisztrációs kód már nem érvényes. Kérj újat az admintól.', 'TOKEN', [], 410);
        }
        $u = db_row('SELECT * FROM felhasznalok WHERE id = ?', [(int)$k['felhasznalo_id']]);
        if (!$u || (int)$u['aktiv'] !== 1) {
            hiba('A felhasználó nem aktív.', 'INAKTIV', [], 403);
        }
        $id = passkey_ment($u, $att, $k['challenge'], isset($be['eszkoz_nev']) ? (string)$be['eszkoz_nev'] : null);
        db_exec('UPDATE belepesi_kerelmek SET statusz = "USED", jovahagyva = NOW(), jovahagyo_passkey_id = ?, jovahagyo_ip = ? WHERE id = ?', [$id, kliens_ip(), (int)$k['id']]);
        return ['u' => $u, 'id' => $id];
    });
    $felh = munkamenet_beleptet((int)$e['u']['id'], 'eszköz-regisztrációs kód + biometria');
    return ['id' => $e['id'], 'felhasznalo' => felhasznalo_publikus($felh)];
}
