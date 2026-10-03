<?php
declare(strict_types=1);

/**
 * BaninaPRO – szerepkörök és jogosultságok (1.10)
 *
 *   admin        (4) – minden: adatok, felhasználók, biztonság, DB-mentés / visszaállítás
 *   irodavezeto  (3) – minden adatművelet, törlés is; admin jog nincs (felhasználók, biztonság, DB-visszaállítás nem)
 *   rogzito      (2) – új adat felvitele és a meglévők szerkesztése; NEM törölhet semmit;
 *                      a más által írt megjegyzést nem írhatja át, csak hozzáfűzhet
 *   uzletkoto    (1) – mindent lát, szűrhet, PDF-et készíthet; NEM vihet fel, NEM módosít, NEM töröl –
 *                      kivétel: BESZÁM (üresbe beírhat, meglévőt módosíthat, de nem törölhet) és
 *                      megjegyzés hozzáfűzése (a más által írt szöveg érintetlen marad)
 *
 * Jogszintek a műveletekhez: nez (1) < ir (2) < torol (3) < admin (4).
 * A „megj” szint = mindenki hívhatja, de a mezőszintű szabályokat a művelet maga érvényesíti.
 */

const SZEREPEK = ['admin' => 'Admin', 'irodavezeto' => 'Irodavezető', 'rogzito' => 'Rögzítő', 'uzletkoto' => 'Üzletkötő'];
const SZEREP_SZINT = ['admin' => 4, 'irodavezeto' => 3, 'rogzito' => 2, 'uzletkoto' => 1];
const JOG_SZINT = ['nez' => 1, 'megj' => 1, 'ir' => 2, 'torol' => 3, 'admin' => 4];

/** Szerep → magyar név */
function szerep_nev(?string $szerep): string
{
    return SZEREPEK[$szerep ?? ''] ?? 'Rögzítő';
}

/** A felhasználó jogszintje (1–4); ismeretlen szerep = Rögzítő (2) */
function jog_szint(?array $u = null): int
{
    $u = $u ?? aktualis_felhasznalo();
    if ($u === null) {
        return 0;
    }
    return SZEREP_SZINT[$u['szerep'] ?? ''] ?? 2;
}

/** Van-e a felhasználónak adott joga? ('nez' | 'ir' | 'torol' | 'admin') */
function jog_van(string $jog, ?array $u = null): bool
{
    return jog_szint($u) >= (JOG_SZINT[$jog] ?? 4);
}

/** Csak adott joggal hívható művelet – különben 403 NINCS_JOG */
function csak_jog(string $jog, ?string $uzenet = null): array
{
    $u = csak_bejelentkezve();
    if (!jog_van($jog, $u)) {
        $mi = ['ir' => 'Rögzítő, Irodavezető vagy Admin', 'torol' => 'Irodavezető vagy Admin', 'admin' => 'Admin'][$jog] ?? 'magasabb';
        hiba($uzenet ?? "Ehhez a művelethez $mi jogosultság kell (a te szereped: " . szerep_nev($u['szerep']) . ').', 'NINCS_JOG', ['szerep' => $u['szerep']], 403);
    }
    return $u;
}

/** A kliens felé küldött jog-összefoglaló */
function jogok_publikus(?array $u): array
{
    return ['ir' => jog_van('ir', $u), 'torol' => jog_van('torol', $u), 'admin' => jog_van('admin', $u)];
}

/**
 * Melyik művelethez milyen jog kell. Ami nincs a listában, az csak adminnak megy
 * (így egy elfelejtett bejegyzés nem nyit rést, hanem a teszten bukik).
 */
const MUVELET_JOGOK = [
    // --- nyilvános / saját fiók
    'belepes' => 'nez', 'en' => 'nez', 'ping' => 'nez', 'lap_zaras' => 'nez', 'kilepes' => 'nez', 'jelszo_modositas' => 'nez',
    'webauthn_beallitasok' => 'nez', 'qr_kerelem' => 'nez', 'qr_allapot' => 'nez', 'qr_info' => 'nez', 'qr_jovahagy' => 'nez', 'qr_elutasit' => 'nez',
    'passkey_belepes_opciok' => 'nez', 'passkey_belepes' => 'nez', 'regisztracio_info' => 'nez', 'regisztracio_vegrehajt' => 'nez',
    'passkeyek' => 'nez', 'passkey_torol' => 'nez', 'passkey_regisztracio_opciok' => 'nez', 'passkey_regisztracio' => 'nez',
    // --- megtekintés, szűrés, keresés, napló, PDF-hez adatok (mindenki)
    'cegek' => 'nez', 'ceg' => 'nez', 'kotesek' => 'nez', 'kotes' => 'nez', 'bejovo_szamlak' => 'nez', 'bejovo_szamlak_idoszak' => 'nez', 'bejovo_szamla' => 'nez',
    'szamlaszam_ellenoriz' => 'nez', 'regi_kod_ellenoriz' => 'nez', 'bank_azonosito_ellenoriz' => 'nez',
    'kimeno_szamlak' => 'nez', 'kimeno_szamla' => 'nez', 'kimeno_szamlak_lista' => 'nez', 'bank_kivonatok' => 'nez', 'bank_kivonat' => 'nez',
    'utalasok' => 'nez', 'utalas' => 'nez', 'utalas_nyitottak' => 'nez', 'utalas_hatarertek_elonezet' => 'nez',
    'hataridok' => 'nez', 'osszefoglalo' => 'nez', 'arfolyam' => 'nez', 'osszevetes_egyenleg' => 'nez', 'osszevetes_reszletek' => 'nez',
    'kereses' => 'nez', 'kereses_ugras' => 'nez', 'naplo_rekord' => 'nez', 'reszteljesitesek' => 'nez', 'import_allapot' => 'nez',
    // --- módosítás megjegyzés-/BESZÁM-szabállyal (Üzletkötő is hívhatja, a művelet korlátozza a mezőket)
    'ceg_modosit' => 'megj', 'kotes_modosit' => 'megj', 'bejovo_szamla_modosit' => 'megj', 'kimeno_modosit' => 'megj', 'utalas_modosit' => 'megj',
    // --- felvitel, szerkesztés, státuszváltás (Rögzítő és felette)
    'ceg_letrehoz' => 'ir', 'kotes_letrehoz' => 'ir', 'bejovo_szamla_letrehoz' => 'ir', 'kimeno_letrehoz' => 'ir',
    'utalas_letrehoz' => 'ir', 'utalas_hatarertek_letrehoz' => 'ir', 'utalas_szamla_hozzaad' => 'ir', 'utalas_kotes_hozzaad' => 'ir', 'utalas_szamla_eltavolit' => 'ir', 'utalas_utalva' => 'ir', 'utalas_lezar' => 'ir',
    'bank_hozzarendel' => 'ir', 'reszteljesites_hozzaad' => 'ir',
    'import_elemzes' => 'ir', 'import_csoportok' => 'ir', 'import_vegrehajt' => 'ir', 'archiv_szamlak_athelyez' => 'ir',
    // --- törlés, visszavonás (Irodavezető és Admin)
    'kotes_torol' => 'torol', 'bejovo_szamla_torol' => 'torol', 'kimeno_torol' => 'torol', 'utalas_torol' => 'torol', 'reszteljesites_torol' => 'torol',
    'utalas_visszanyit' => 'torol', 'kimeno_fizetes_visszavon' => 'torol',
    // --- admin: felhasználók, beállítások, napló, DB-mentés, biztonság
    'admin_felhasznalok' => 'admin', 'admin_felhasznalo_letrehoz' => 'admin', 'admin_felhasznalo_modosit' => 'admin',
    'admin_beallitasok' => 'admin', 'admin_beallitas_ment' => 'admin', 'admin_naplo_fajlok' => 'admin', 'admin_naplo_olvas' => 'admin',
    'admin_mentesek' => 'admin', 'admin_mentes_most' => 'admin', 'admin_mentes_info' => 'admin', 'admin_mentes_torol' => 'admin', 'admin_db_visszaallit' => 'admin', 'admin_cron_kulcs_uj' => 'admin',
    'admin_regisztracios_kod' => 'admin', 'admin_passkeyek' => 'admin', 'admin_passkey_torol' => 'admin',
];

function muvelet_jog(string $action): string
{
    return MUVELET_JOGOK[$action] ?? 'admin';
}

/**
 * Megjegyzés-szabály (Rögzítő, Üzletkötő): a más által írt szöveg nem írható át és nem törölhető,
 * csak HOZZÁFŰZNI lehet (az új szöveg a régivel kezdődik). Irodavezető / Admin: szabadon.
 *
 * Visszaad: ['megjegyzes' => a mentendő szöveg, 'irta' => a szerző-oszlop új értéke]
 *   – ha nem változott: a régi szerző marad
 *   – üresből írt vagy saját szöveg: a mostani felhasználó
 *   – máséhoz hozzáfűzés: NULL (több szerző → ezután csak hozzáfűzhető)
 */
function megjegyzes_szabaly(array $rekord, ?string $uj, ?array $u = null): array
{
    $u = $u ?? csak_bejelentkezve();
    $regi = trim((string)($rekord['megjegyzes'] ?? ''));
    $ujT = trim((string)($uj ?? ''));
    $irta = isset($rekord['megjegyzes_irta']) && $rekord['megjegyzes_irta'] !== null ? (int)$rekord['megjegyzes_irta'] : null;
    if ($ujT === $regi) {
        return ['megjegyzes' => $rekord['megjegyzes'] ?? $uj, 'irta' => $irta];           // nem változott
    }
    $en = (int)$u['id'];
    if (jog_van('torol', $u) || $regi === '' || $irta === $en) {
        return ['megjegyzes' => $uj, 'irta' => $ujT === '' ? null : $en];               // szabadon írható
    }
    if (!str_starts_with($ujT, $regi)) {
        hiba('A megjegyzést más írta – a meglévő szöveg nem módosítható és nem törölhető, csak hozzáfűzni lehet hozzá.', 'MEGJEGYZES_VEDETT', [], 403);
    }
    return ['megjegyzes' => $uj, 'irta' => null];                                          // hozzáfűzés más szövegéhez
}

/** BESZÁM-szabály (Üzletkötő): üresbe beírhat, meglévőt módosíthat, de nem törölhet */
function beszam_szabaly(?string $regi, ?string $uj, ?array $u = null): void
{
    $u = $u ?? csak_bejelentkezve();
    if (!jog_van('ir', $u) && trim((string)$regi) !== '' && trim((string)$uj) === '') {
        hiba('A BESZÁM nem törölhető – ha egyszer ki lett töltve, csak módosítani lehet.', 'BESZAM_VEDETT', [], 403);
    }
}

/** A nyilvános (minden bejelentkezettnek látható) beállítások */
function beallitasok_publikus(): array
{
    $v = db_val('SELECT ertek FROM beallitasok WHERE kulcs = ?', ['reszt_bejovo']);
    return ['reszt_bejovo' => $v === '1', 'jelenlet_mp' => ules_korlat('jelenlet'), 'qr_belepes' => qr_belepes_aktiv()];
}

/**
 * QR-kódos (passkey) belépés be van-e kapcsolva (1.15, admin kapcsoló). Alapból IGEN – ha a beállítás sora
 * hiányzik (a frissites_1.15.sql még nem futott le), minden a korábbiak szerint működik.
 * Kikapcsolva: mindenki jelszóval lép be (a regisztrált eszközzel rendelkezők is), a QR / passkey belépés tiltott.
 * A regisztrált eszközök megmaradnak, visszakapcsoláskor újra használhatók.
 */
function qr_belepes_aktiv(): bool
{
    static $aktiv = null;
    if ($aktiv === null) {
        $aktiv = db_val('SELECT ertek FROM beallitasok WHERE kulcs = ?', ['qr_belepes']) !== '0';
    }
    return $aktiv;
}

/** QR / passkey belépési műveletek elején: kikapcsolt QR-belépésnél 403 QR_KIKAPCSOLVA */
function qr_belepes_kell(): void
{
    if (!qr_belepes_aktiv()) {
        hiba('A QR-kódos belépést az admin kikapcsolta – lépj be a felhasználóneveddel és a jelszavaddal.', 'QR_KIKAPCSOLVA', [], 403);
    }
}

/** Aktív felhasználók, akiknek nincs jelszavuk (kikapcsolt QR-belépésnél nem tudnának belépni) */
function jelszo_nelkuli_felhasznalok(): array
{
    return array_column(db_all("SELECT felhasznalonev FROM felhasznalok WHERE aktiv = 1 AND (jelszo_hash IS NULL OR jelszo_hash = '') ORDER BY felhasznalonev"), 'felhasznalonev');
}

/** Bejövő részteljesítés engedélyezett-e (admin kapcsoló, alapból nem) */
function reszt_bejovo_engedelyezett(): bool
{
    return beallitasok_publikus()['reszt_bejovo'];
}
