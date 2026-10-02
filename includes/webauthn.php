<?php
declare(strict_types=1);

/**
 * BaninaPRO – WebAuthn / passkey ellenőrzés (függőség nélkül).
 *
 * - Regisztráció: attestationObject (CBOR) → authData → hitelesítő nyilvános kulcs (COSE) → PEM
 *   (attestation formátumot NEM ellenőrzünk – "none" attestation, ahogy a passkey-rendszerek általában)
 * - Bejelentkezés (assertion): aláírás-ellenőrzés OpenSSL-lel (ES256 / RS256), challenge, origin,
 *   rpIdHash, UP+UV (biometria / eszközzár KÖTELEZŐ), aláírás-számláló.
 */

// ---------------------------------------------------------------------------
//  base64url
// ---------------------------------------------------------------------------
function b64url_enc(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_dec(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $r = base64_decode($s, true);
    if ($r === false) {
        hiba('Érvénytelen base64url adat.', 'WEBAUTHN');
    }
    return $r;
}

// ---------------------------------------------------------------------------
//  Minimál CBOR dekóder (RFC 8949) – csak ami a WebAuthn-hoz kell
// ---------------------------------------------------------------------------
final class CborDekoder
{
    private string $b;
    private int $p = 0;

    public function __construct(string $bin)
    {
        $this->b = $bin;
    }

    public static function dekodol(string $bin): mixed
    {
        $d = new self($bin);
        return $d->ertek();
    }

    /** Dekódol egy értéket, és visszaadja az elfogyasztott bájtok számát is */
    public static function dekodolHossz(string $bin): array
    {
        $d = new self($bin);
        $v = $d->ertek();
        return [$v, $d->p];
    }

    private function byte(): int
    {
        if ($this->p >= strlen($this->b)) {
            hiba('Hibás CBOR adat (váratlan vég).', 'WEBAUTHN');
        }
        return ord($this->b[$this->p++]);
    }

    private function bytes(int $n): string
    {
        if ($this->p + $n > strlen($this->b)) {
            hiba('Hibás CBOR adat (rövid).', 'WEBAUTHN');
        }
        $s = substr($this->b, $this->p, $n);
        $this->p += $n;
        return $s;
    }

    private function uintArg(int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        return match ($info) {
            24 => $this->byte(),
            25 => (int)unpack('n', $this->bytes(2))[1],
            26 => (int)unpack('N', $this->bytes(4))[1],
            27 => (int)unpack('J', $this->bytes(8))[1],
            default => hiba('Nem támogatott CBOR hossz.', 'WEBAUTHN'),
        };
    }

    private function ertek(): mixed
    {
        $ib = $this->byte();
        $major = $ib >> 5;
        $info = $ib & 0x1f;
        switch ($major) {
            case 0:
                return $this->uintArg($info);
            case 1:
                return -1 - $this->uintArg($info);
            case 2:
                return $this->bytes($this->uintArg($info));
            case 3:
                return $this->bytes($this->uintArg($info));
            case 4:
                $n = $this->uintArg($info);
                $a = [];
                for ($i = 0; $i < $n; $i++) {
                    $a[] = $this->ertek();
                }
                return $a;
            case 5:
                $n = $this->uintArg($info);
                $m = [];
                for ($i = 0; $i < $n; $i++) {
                    $k = $this->ertek();
                    $v = $this->ertek();
                    $m[is_int($k) || is_string($k) ? $k : json_encode($k)] = $v;
                }
                return $m;
            case 6:
                $this->uintArg($info); // tag – kihagyjuk
                return $this->ertek();
            case 7:
                return match ($info) {
                    20 => false, 21 => true, 22 => null, 23 => null,
                    24 => $this->byte(),
                    25 => $this->bytes(2), 26 => $this->bytes(4), 27 => $this->bytes(8),
                    default => null,
                };
        }
        hiba('Hibás CBOR adat.', 'WEBAUTHN');
    }
}

// ---------------------------------------------------------------------------
//  DER / PEM segédek
// ---------------------------------------------------------------------------
function der_hossz(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    $h = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($h)) . $h;
}

function der_elem(int $tag, string $tartalom): string
{
    return chr($tag) . der_hossz(strlen($tartalom)) . $tartalom;
}

function der_integer(string $bin): string
{
    $bin = ltrim($bin, "\0");
    if ($bin === '' || (ord($bin[0]) & 0x80)) {
        $bin = "\0" . $bin;
    }
    return der_elem(0x02, $bin);
}

function pem_burkol(string $der): string
{
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * COSE kulcs (CBOR map) → ['pem' => ..., 'alg' => -7|-257]
 */
function cose_to_pem(array $cose): array
{
    $kty = $cose[1] ?? null;
    $alg = $cose[3] ?? null;
    if ($kty === 2) { // EC2
        if ($alg !== -7 || ($cose[-1] ?? null) !== 1) {
            hiba('Csak ES256 (P-256) elliptikus kulcs támogatott.', 'WEBAUTHN');
        }
        $x = $cose[-2] ?? '';
        $y = $cose[-3] ?? '';
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            hiba('Hibás EC kulcs.', 'WEBAUTHN');
        }
        $algId = der_elem(0x30, der_elem(0x06, "\x2a\x86\x48\xce\x3d\x02\x01") . der_elem(0x06, "\x2a\x86\x48\xce\x3d\x03\x01\x07"));
        $bits = der_elem(0x03, "\0" . "\x04" . $x . $y);
        return ['pem' => pem_burkol(der_elem(0x30, $algId . $bits)), 'alg' => -7];
    }
    if ($kty === 3) { // RSA
        if ($alg !== -257) {
            hiba('Csak RS256 RSA kulcs támogatott.', 'WEBAUTHN');
        }
        $n = $cose[-1] ?? '';
        $e = $cose[-2] ?? '';
        if ($n === '' || $e === '') {
            hiba('Hibás RSA kulcs.', 'WEBAUTHN');
        }
        $algId = der_elem(0x30, der_elem(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
        $rsaKey = der_elem(0x30, der_integer($n) . der_integer($e));
        $bits = der_elem(0x03, "\0" . $rsaKey);
        return ['pem' => pem_burkol(der_elem(0x30, $algId . $bits)), 'alg' => -257];
    }
    hiba('Nem támogatott kulcstípus.', 'WEBAUTHN');
}

// ---------------------------------------------------------------------------
//  authenticatorData feldolgozás
// ---------------------------------------------------------------------------
function authdata_feldolgoz(string $ad): array
{
    if (strlen($ad) < 37) {
        hiba('Hibás authenticatorData.', 'WEBAUTHN');
    }
    $r = [
        'rpIdHash'  => substr($ad, 0, 32),
        'flags'     => ord($ad[32]),
        'signCount' => (int)unpack('N', substr($ad, 33, 4))[1],
        'credId'    => null,
        'cose'      => null,
        'aaguid'    => null,
    ];
    $r['up'] = (bool)($r['flags'] & 0x01);
    $r['uv'] = (bool)($r['flags'] & 0x04);
    $r['at'] = (bool)($r['flags'] & 0x40);
    if ($r['at']) {
        if (strlen($ad) < 55) {
            hiba('Hibás attested credential data.', 'WEBAUTHN');
        }
        $r['aaguid'] = substr($ad, 37, 16);
        $len = (int)unpack('n', substr($ad, 53, 2))[1];
        $r['credId'] = substr($ad, 55, $len);
        $rest = substr($ad, 55 + $len);
        [$cose, $used] = CborDekoder::dekodolHossz($rest);
        if (!is_array($cose)) {
            hiba('Hibás nyilvános kulcs.', 'WEBAUTHN');
        }
        $r['cose'] = $cose;
    }
    return $r;
}

function aaguid_szoveg(?string $bin): ?string
{
    if ($bin === null || strlen($bin) !== 16) {
        return null;
    }
    $h = bin2hex($bin);
    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
}

/** clientDataJSON ellenőrzése: típus, challenge, origin */
function clientdata_ellenoriz(string $clientDataJSON, string $vartTipus, string $vartChallengeB64u, string $vartOrigin): array
{
    $cd = json_decode($clientDataJSON, true);
    if (!is_array($cd)) {
        hiba('Hibás clientDataJSON.', 'WEBAUTHN');
    }
    if (($cd['type'] ?? '') !== $vartTipus) {
        hiba('Hibás WebAuthn művelettípus.', 'WEBAUTHN');
    }
    if (!hash_equals($vartChallengeB64u, (string)($cd['challenge'] ?? ''))) {
        hiba('A biztonsági kihívás (challenge) nem egyezik – próbáld újra.', 'WEBAUTHN');
    }
    $origin = rtrim((string)($cd['origin'] ?? ''), '/');
    if (!hash_equals(rtrim($vartOrigin, '/'), $origin)) {
        hiba("Az eredet (origin) nem egyezik: $origin ≠ $vartOrigin. Ellenőrizd az APP_ORIGIN beállítást.", 'WEBAUTHN');
    }
    return $cd;
}

/**
 * Regisztráció (navigator.credentials.create eredménye) ellenőrzése.
 * @return array{credId:string, pem:string, alg:int, signCount:int, aaguid:?string, transports:string}
 */
function webauthn_regisztracio_ellenoriz(array $att, string $vartChallengeB64u, string $rpId, string $origin): array
{
    $resp = $att['response'] ?? [];
    $clientDataJSON = b64url_dec((string)($resp['clientDataJSON'] ?? ''));
    clientdata_ellenoriz($clientDataJSON, 'webauthn.create', $vartChallengeB64u, $origin);

    $attObj = CborDekoder::dekodol(b64url_dec((string)($resp['attestationObject'] ?? '')));
    if (!is_array($attObj) || !isset($attObj['authData'])) {
        hiba('Hibás attestationObject.', 'WEBAUTHN');
    }
    $ad = authdata_feldolgoz((string)$attObj['authData']);
    if (!hash_equals(hash('sha256', $rpId, true), $ad['rpIdHash'])) {
        hiba("Az rpIdHash nem egyezik (RP_ID = $rpId). Ellenőrizd a beállítást.", 'WEBAUTHN');
    }
    if (!$ad['up']) {
        hiba('Hiányzik a felhasználói jelenlét (UP).', 'WEBAUTHN');
    }
    if (!$ad['uv']) {
        hiba('A regisztrációhoz biometrikus azonosítás vagy eszközzár (UV) szükséges.', 'WEBAUTHN');
    }
    if (!$ad['at'] || $ad['credId'] === null || $ad['cose'] === null) {
        hiba('Hiányzik a hitelesítő adat.', 'WEBAUTHN');
    }
    $kulcs = cose_to_pem($ad['cose']);
    $credIdB64u = b64url_enc($ad['credId']);
    if (!empty($att['id']) && !hash_equals($credIdB64u, (string)$att['id'])) {
        hiba('A credential azonosító nem egyezik.', 'WEBAUTHN');
    }
    $transports = '';
    if (!empty($resp['transports']) && is_array($resp['transports'])) {
        $transports = implode(',', array_map(fn($t) => preg_replace('/[^a-z\-]/', '', strtolower((string)$t)), $resp['transports']));
    }
    return [
        'credId'     => $credIdB64u,
        'pem'        => $kulcs['pem'],
        'alg'        => $kulcs['alg'],
        'signCount'  => $ad['signCount'],
        'aaguid'     => aaguid_szoveg($ad['aaguid']),
        'transports' => substr($transports, 0, 100),
    ];
}

/**
 * Bejelentkezés (navigator.credentials.get eredménye) ellenőrzése.
 * @param array $passkey  ['credential_id','public_key','alg','sign_count']
 * @return int  az új aláírás-számláló
 */
function webauthn_assertion_ellenoriz(array $asr, array $passkey, string $vartChallengeB64u, string $rpId, string $origin): int
{
    $resp = $asr['response'] ?? [];
    $clientDataJSON = b64url_dec((string)($resp['clientDataJSON'] ?? ''));
    clientdata_ellenoriz($clientDataJSON, 'webauthn.get', $vartChallengeB64u, $origin);

    $authData = b64url_dec((string)($resp['authenticatorData'] ?? ''));
    $sig = b64url_dec((string)($resp['signature'] ?? ''));
    $ad = authdata_feldolgoz($authData);
    if (!hash_equals(hash('sha256', $rpId, true), $ad['rpIdHash'])) {
        hiba("Az rpIdHash nem egyezik (RP_ID = $rpId).", 'WEBAUTHN');
    }
    if (!$ad['up']) {
        hiba('Hiányzik a felhasználói jelenlét (UP).', 'WEBAUTHN');
    }
    if (!$ad['uv']) {
        hiba('A belépéshez biometrikus azonosítás (Face ID / ujjlenyomat) vagy eszközzár szükséges – a telefon nem igazolta.', 'WEBAUTHN');
    }
    if (!hash_equals((string)$passkey['credential_id'], (string)($asr['id'] ?? ''))) {
        hiba('A credential azonosító nem egyezik.', 'WEBAUTHN');
    }
    $alairt = $authData . hash('sha256', $clientDataJSON, true);
    $pub = openssl_pkey_get_public((string)$passkey['public_key']);
    if ($pub === false) {
        hiba('A tárolt nyilvános kulcs nem olvasható.', 'WEBAUTHN');
    }
    $ok = openssl_verify($alairt, $sig, $pub, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        hiba('Érvénytelen aláírás – a belépés elutasítva.', 'WEBAUTHN');
    }
    $regi = (int)$passkey['sign_count'];
    $uj = $ad['signCount'];
    if ($uj !== 0 && $regi !== 0 && $uj <= $regi) {
        hiba('Aláírás-számláló hiba (lehetséges klónozott hitelesítő) – a belépés elutasítva.', 'WEBAUTHN');
    }
    return $uj;
}
