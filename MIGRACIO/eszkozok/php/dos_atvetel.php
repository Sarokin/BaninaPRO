<?php
/**
 * DOS-adatok átvétele a BaninaPRO-ba a 2026.10.07-i DOS import szabályai szerint (DOS_LEKEPEZES.md).
 * Bemenet: a DOS-mappából készült KÖZTES JSON (formátuma: DOS_LEKEPEZES.md → „Köztes formátum”).
 *
 *   kornyezet.sh php <db> /migracio/eszkozok/php/dos_atvetel.php <dos_koztes.json> [--szaraz] [--datum=2026.10.16]
 *                [--felhasznalo="DOS import"] [--jelentes=<mappa>]
 *
 * Ami a DOS-kulcsa (kötésszám/kisszám, F-szám, KE-azonosító, utalásszám) szerint már megvan, azt összeveti:
 *   • a BaninaPRO-ban azóta senki által nem módosított rekordot a DOS szerint javítja (a DOS-ban azóta javított adat);
 *   • a FIZETENDŐ (utalásban nem lévő) számlát, amit a DOS-ban azóta átutaltak, FIZETVE-re állítja a DOS-utalással;
 *   • a 00.00.00 terhelésű DOS-utalás dátumát pótolja, ha a DOS-ban azóta beírták;
 *   • a kézzel / IB importtal módosított rekordot NEM írja felül: ütközésként jelenti (döntésre).
 * Ami új, létrehozza (cég, kötés, bejövő számla, forintszámla, utalás, kimenő számla, banki kivonat, részteljesítés).
 * Semmit nem töröl: ami a DOS-ból azóta eltűnt, azt csak jelenti.
 * --szaraz: minden ugyanígy lefut, a végén visszagörgeti (a jelentés a pontos eredményt mutatja, a kiosztandó kódokkal).
 */
declare(strict_types=1);

// csak parancssorból (a szerveren a repó a webgyökér – webről ne fusson)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db.php';
require_once '/var/www/html/includes/helpers.php';
require_once '/var/www/html/includes/logger.php';
require_once '/var/www/html/includes/ids.php';
require_once '/var/www/html/includes/audit.php';
require_once __DIR__ . '/kozos.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

$fajl = null;
$k = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $k[$m[1]] = $m[2] ?? '1';
    } else {
        $fajl = $a;
    }
}
if ($fajl === null || !is_file($fajl)) {
    fwrite(STDERR, "Használat: dos_atvetel.php <dos_koztes.json> [--szaraz] [--datum=ÉÉÉÉ.HH.NN] [--jelentes=<mappa>]\n");
    exit(1);
}
$SZARAZ = isset($k['szaraz']);
$FELH = $k['felhasznalo'] ?? 'DOS import';
$NAP = $k['datum'] ?? date('Y.m.d');
$KI = rtrim($k['jelentes'] ?? ('/migracio/4_EREDMENY/' . DB_NAME . '/dos_atvetel' . ($SZARAZ ? '_szaraz' : '')), '/');
@mkdir($KI, 0775, true);
$U = ['id' => null, 'felhasznalonev' => $FELH, 'szerep' => 'admin'];
$_SESSION = ['felhasznalonev' => $FELH];
$IR = json_decode((string)file_get_contents($fajl), true, 512, JSON_THROW_ON_ERROR);
$t0 = microtime(true);

// ---- jelentés -------------------------------------------------------------------------------------------------------
$J = ['uj' => ['ceg' => [], 'kotes' => [], 'bejovo' => [], 'forintszamla' => [], 'utalas' => [], 'kimeno' => [], 'bank' => [], 'reszt' => [], 'athelyezve' => []],
      'javitva' => [], 'fizetve' => [], 'datum_potolva' => [], 'utkozes' => [], 'hianyzik' => [], 'figyelem' => [], 'hiba' => [], 'egyezik' => 0, 'ib_marad' => []];
$AUDIT = [];   // [tipus, rekordId, szuloId, muvelet, leiras, valtozasok]

function jel(string $hova, array|string $mit): void
{
    global $J;
    $J[$hova][] = $mit;
}

/** Új rekord a jelentésbe (típusonként) */
function uj(string $tipus, string $mit): void
{
    global $J;
    $J['uj'][$tipus][] = $mit;
}

// ---- a meglévő állapot ------------------------------------------------------------------------------------------------
$CEG = [];                    // id → sor
$VEVO = [];                   // DOS VEVO-kód → cég id
$UGYF = [];                   // DOS UGYF-kód → cég id
$NEVK = [];                   // névkulcs → cég id
foreach (db_all('SELECT id, nev, partnerkod, adoszam, megjegyzes FROM cegek') as $c) {
    $id = (int)$c['id'];
    $CEG[$id] = $c;
    foreach (mig_dos_kodok(mig_megj_bont((string)$c['megjegyzes'])['mezok']['DOS-kód'] ?? '') as [$t, $kod]) {
        if ($t === 'VEVO') { $VEVO[$kod] = $id; } else { $UGYF[$kod] = $id; }
    }
    $NEVK[mig_nev_kulcs($c['nev'])] = $id;
}
$IRP = ['VEVO' => [], 'UGYF' => []];
foreach ($IR['partnerek'] ?? [] as $p) {
    $IRP[$p['tabla']][trim((string)$p['kod'])] = $p;
}

// október 7. óta a BaninaPRO-ban módosított MEZŐK, a változásnapló szerint (rekord → mező → [ki, mikor, excel?]).
// Az IB System (Excel) import javítása szándékosan mérvadó: azt a DOS nem írja felül, és nem is ütközés.
// Egy munkatárs kézi módosítása ütközés (döntésre). Mező nélküli módosító bejegyzés = minden adatmező érintett.
$ERINTETT = ['KOTES' => [], 'BEJOVO' => [], 'KIMENO' => []];
$CIMKE_MEZO = array_flip(AUDIT_CIMKEK);
foreach (db_all("SELECT tipus, rekord_id, muvelet, leiras, valtozasok, felhasznalonev, idopont FROM valtozasnaplo
                  WHERE felhasznalonev NOT LIKE 'DOS import%' AND muvelet IN ('MODOSIT', 'IMPORT', 'ATHELYEZ') ORDER BY id") as $r) {
    $excel = str_starts_with((string)$r['leiras'], 'Excel import');
    if ($r['muvelet'] === 'IMPORT' && !$excel) {
        continue;   // pl. egy Excel importból létrehozott rekord – nem módosítás
    }
    $mezok = [];
    foreach (json_decode((string)$r['valtozasok'], true) ?: [] as $v) {
        $mezok[] = $CIMKE_MEZO[$v['m'] ?? ''] ?? ($v['m'] ?? '?');
    }
    if (!$mezok) {
        $mezok = ['*'];
    }
    foreach ($mezok as $m) {
        $ERINTETT[$r['tipus']][(int)$r['rekord_id']][$m] = ['ki' => "{$r['felhasznalonev']} ({$r['idopont']})", 'excel' => $excel];
    }
}

/** Ki módosította a rekord adott mezőjét október 7. óta: null (senki) vagy ['ki', 'excel'] */
function erintett(string $tipus, int $id, string $mezo): ?array
{
    global $ERINTETT;
    return $ERINTETT[$tipus][$id][$mezo] ?? $ERINTETT[$tipus][$id]['*'] ?? null;
}

/** Kik módosították a rekordot október 7. óta (a jelentéshez), vagy null */
function erintett_ki(string $tipus, int $id): ?string
{
    global $ERINTETT;
    return isset($ERINTETT[$tipus][$id]) ? implode(', ', array_unique(array_column($ERINTETT[$tipus][$id], 'ki'))) : null;
}

$KOTES = [];                  // "kötésszám|cég|pénznem" → kötés
$ARCHIV = [];                 // "cég|HUF|év" → archív kötés (a DOS importé előnyben)
foreach (db_all('SELECT id, kod, ceg_id, ev, penznem, regi_kod, megnevezes, archiv FROM kotesek') as $r) {
    if ($r['regi_kod'] !== null) {
        $KOTES["{$r['regi_kod']}|{$r['ceg_id']}|{$r['penznem']}"] = $r;
    }
    if ((int)$r['archiv'] === 1) {
        $kk = "{$r['ceg_id']}|{$r['penznem']}|{$r['ev']}";
        if (!isset($ARCHIV[$kk]) || str_starts_with((string)$r['megnevezes'], 'ARCHÍV – DOS import')) {
            $ARCHIV[$kk] = $r;
        }
    }
}
$TETEL = [];                  // "kötésszám/kisszám" → bejövő számla
$FORINT = [];                 // "F-…" → bejövő (forintszámla)
$BEJOVO_CEG_SZAM = [];        // "cég|számlaszám_norm" → [számla id…] (áthelyezés-figyeléshez)
foreach (db_all('SELECT b.id, b.kotes_id, b.k_sorszam, b.kod, b.szamlaszam, b.szamlaszam_norm, b.teljesites_datum, b.kelt, b.osszeg, b.beszam, b.regi_k,
                        b.fizetesi_hatarido, b.statusz, b.fizetve_datum, b.utalas_id, b.megjegyzes, k.regi_kod, k.ceg_id, k.penznem, k.kod AS kotes_kod,
                        u.uid AS utalas_uid, u.banki_hivatkozas
                   FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id LEFT JOIN utalasok u ON u.id = b.utalas_id') as $b) {
    $m = mig_megj_bont((string)$b['megjegyzes'])['mezok'];
    $b['_dos'] = $m;
    if (isset($m['DOS azonosító'])) {
        $TETEL[trim($m['DOS azonosító'])] = $b;
    } elseif ($b['regi_kod'] !== null && $b['regi_k'] !== null && !str_starts_with((string)$b['regi_k'], 'F-')) {
        $TETEL["{$b['regi_kod']}/{$b['regi_k']}"] = $b;
    }
    if (isset($m['DOS forintszámla'])) {
        $FORINT[trim($m['DOS forintszámla'])] = $b;
    }
    $BEJOVO_CEG_SZAM["{$b['ceg_id']}|{$b['szamlaszam_norm']}"][] = (int)$b['id'];
}
// az IB (Excel) importból ARCHÍV kötésbe került bejövő számlák: ha a DOS-ban azóta kötésben szerepelnek, ugyanazok a számlák
// (az irodavezető napi importja) – a DOS-átvétel nem hozza létre őket újra, hanem áthelyezi a DOS-kötésbe
$IB_ARCHIV = [];   // "cég|pénznem|laza számlaszám" → [számla…]
foreach (db_all("SELECT b.id, b.kotes_id, b.kod, b.szamlaszam, b.osszeg, b.statusz, b.utalas_id, b.beszam, b.megjegyzes, k.ceg_id, k.penznem, k.kod AS kotes_kod
                   FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id
                  WHERE k.archiv = 1 AND (k.megnevezes IS NULL OR k.megnevezes NOT LIKE 'ARCHÍV – DOS import%')") as $b) {
    $IB_ARCHIV["{$b['ceg_id']}|{$b['penznem']}|" . mig_laza_kulcs($b['szamlaszam'])][] = $b;
}
$UTAL = [];                   // "deviza|012345" → utalás
foreach (db_all('SELECT id, uid, ceg_id, ev, penznem, statusz, utalva_datum, banki_hivatkozas, megjegyzes FROM utalasok') as $r) {
    $ref = mig_utalas_ref((string)$r['banki_hivatkozas']);
    if ($ref) {
        $UTAL["{$ref['tipus']}|{$ref['szam']}"] = $r;
    }
}
$KIM = [];                    // "KE-26 1126" → kimenő
$KIMN = [];                   // számlaszám_norm → kimenő
foreach (db_all('SELECT s.id, s.ceg_id, s.ev, s.penznem, s.kod, s.szamlaszam, s.szamlaszam_norm, s.teljesites_datum, s.kelt, s.fizetesi_hatarido, s.osszeg,
                        s.statusz, s.banki_kivonat_id, s.fizetve_datum, s.megjegyzes FROM kimeno_szamlak s') as $s) {
    $m = mig_megj_bont((string)$s['megjegyzes'])['mezok'];
    $s['_dos'] = $m;
    if (isset($m['DOS azonosító'])) {
        $KIM[preg_replace('/\s+/', ' ', trim($m['DOS azonosító']))] = $s;
    }
    $KIMN[$s['szamlaszam_norm']] = $s;
}
$BANK = [];
foreach (db_all('SELECT id, azonosito, azonosito_norm, datum FROM banki_kivonatok') as $r) {
    $BANK[$r['azonosito_norm']] = $r;
}
$RESZT = [];                  // "BEJOVO|id" → [összeg, darab]
foreach (db_all('SELECT irany, szamla_id, SUM(osszeg) ossz, COUNT(*) db FROM reszteljesitesek GROUP BY irany, szamla_id') as $r) {
    $RESZT["{$r['irany']}|{$r['szamla_id']}"] = [$r['ossz'], (int)$r['db']];
}
$IRU = [];                    // a köztes formátum utalásai
foreach ($IR['utalasok'] ?? [] as $x) {
    $IRU[$x['tipus'] . '|' . trim((string)$x['szam'])] = $x;
}

// ---- segédek -------------------------------------------------------------------------------------------------------------

/** A DOS-partner cége (VEVO / UGYF kód); ha nincs, a köztes adat partnere alapján név szerint párosít vagy új céget hoz létre. */
function ceg_dos(string $tabla, string $kod): ?int
{
    global $VEVO, $UGYF, $IRP, $NEVK, $CEG, $NAP, $J;
    $kod = trim($kod);
    if ($kod === '') {
        return null;
    }
    $sorrend = $tabla === 'UGYF' ? ['UGYF', 'VEVO'] : ['VEVO', 'UGYF'];
    foreach ($sorrend as $t) {
        $map = $t === 'VEVO' ? $VEVO : $UGYF;
        if (isset($map[$kod])) {
            return $map[$kod];
        }
    }
    $p = null;
    foreach ($sorrend as $t) {
        if (isset($IRP[$t][$kod])) { $p = $IRP[$t][$kod]; $tabla = $t; break; }
    }
    if ($p === null) {
        return null;
    }
    $nk = mig_nev_kulcs((string)$p['nev']);
    if ($nk !== '' && isset($NEVK[$nk])) {
        // ugyanaz a név már megvan: az új DOS-kód ehhez a céghez kerül (a megjegyzésbe)
        $id = $NEVK[$nk];
        db_exec('UPDATE cegek SET megjegyzes = IF(COALESCE(megjegyzes, \'\') = \'\', TRIM(LEADING CHAR(10) FROM ?), CONCAT(megjegyzes, ?)) WHERE id = ?',
            array_merge(array_fill(0, 2, "\nDOS-kód: $tabla $kod (DOS import $NAP, név szerint párosítva)"), [$id]));
        if ($tabla === 'VEVO') { $VEVO[$kod] = $id; } else { $UGYF[$kod] = $id; }
        jel('figyelem', "új DOS-kód ($tabla $kod) név szerint a meglévő céghez párosítva: #$id {$CEG[$id]['nev']}");
        return $id;
    }
    $nev = mb_substr(trim((string)$p['nev']), 0, 191);
    if ($nev === '') {
        $nev = "DOS $tabla $kod";
    }
    if (db_val('SELECT id FROM cegek WHERE nev = ?', [$nev])) {
        $nev = mb_substr($nev, 0, 170) . " (DOS $tabla $kod)";
    }
    $megj = ["DOS import ($NAP)", "DOS-kód: $tabla $kod"];
    if (!empty($p['cim'])) { $megj[] = 'Cím: ' . trim((string)$p['cim']); }
    if (!empty($p['bank'])) { $megj[] = 'Bank: ' . trim((string)$p['bank']); }
    $adoszam = mig_adoszam($p['adoszam'] ?? ($tabla === 'UGYF' && preg_match('/^\d{11}$/', $kod) ? $kod : null));
    db_exec('INSERT INTO cegek (nev, adoszam, partnerkod, megjegyzes) VALUES (?, ?, ?, ?)', [$nev, $adoszam, mig_partnerkod($tabla, $kod), implode(' · ', $megj)]);
    $id = (int)db()->lastInsertId();
    $CEG[$id] = ['id' => $id, 'nev' => $nev];
    $NEVK[$nk] = $id;
    if ($tabla === 'VEVO') { $VEVO[$kod] = $id; } else { $UGYF[$kod] = $id; }
    uj('ceg', "#$id $nev ($tabla $kod)");
    return $id;
}

/** Banki kivonat a kimenő befolyáshoz: „DOS 2026.04.20 EUR” (a 10-07-i import formája) */
function bank_dos(string $datum, string $penznem): int
{
    global $BANK;
    $az = 'DOS ' . mig_datum_hu($datum) . ' ' . $penznem;
    $n = norm_azonosito($az);
    if (!isset($BANK[$n])) {
        db_exec('INSERT INTO banki_kivonatok (azonosito, azonosito_norm, datum) VALUES (?, ?, ?)', [$az, $n, $datum]);
        $BANK[$n] = ['id' => (int)db()->lastInsertId(), 'azonosito' => $az, 'datum' => $datum];
        uj('bank', $az);
    }
    return (int)$BANK[$n]['id'];
}

/** A DOS-utalás dátumai a köztes adatból: [utalva_datum, megbízás/utalás napja, terhelés/2. dátum] */
function utalas_datumok(array $x): array
{
    if ($x['tipus'] === 'deviza') {
        $megb = mig_datum($x['megbizas'] ?? null);
        $terh = mig_datum($x['terheles'] ?? null);
        return [$terh, $megb, $terh];
    }
    $d1 = mig_datum($x['utalas_datum'] ?? null);
    $d2 = mig_datum($x['datum2'] ?? null);
    return [$d1, $d1, $d2];
}

function utalas_megj(array $x, string $penznem): string
{
    [, $d1, $d2] = utalas_datumok($x);
    $osszeg = mig_osszeg($x['osszeg'] ?? null);
    if ($x['tipus'] === 'deviza') {
        $r = ['DOS import', 'Megbízás kelte: ' . mig_datum_hu($d1), 'Terhelés: ' . mig_datum_hu($d2)];
        if ($osszeg !== null) { $r[] = 'Összeg (DOS): ' . mig_osszeg_hu($osszeg) . " $penznem"; }
        if ($d2 === null) {
            $r[] = 'A DOS-ban a terhelés még nincs rögzítve (00.00.00) – a számlák a DOS-ban már átutaltak, ezért UTALVA, dátum nélkül; a terhelés napját a BaninaPRO-ban lehet beírni';
        }
        if (!empty($x['kedvezmenyezett_megj'])) { $r[] = 'Kedvezményezett a DOS-ban: ' . $x['kedvezmenyezett_megj']; }
        if (!empty($x['kedv_bank'])) { $r[] = 'Kedvezményezett bankja (törzsadat): ' . $x['kedv_bank']; }
        if (!empty($x['bank_egyeb'])) { $r[] = 'Bank/egyéb (utalás): ' . $x['bank_egyeb']; }
        if (!empty($x['kedv_szamla'])) { $r[] = 'Kedvezményezett számlája: ' . $x['kedv_szamla']; }
    } else {
        $r = ['DOS import', 'Utalás dátuma: ' . mig_datum_hu($d1), '2. dátum (terhelés): ' . mig_datum_hu($d2)];
        if ($osszeg !== null) { $r[] = 'Összeg (DOS): ' . mig_osszeg_hu($osszeg) . " $penznem"; }
        if (!empty($x['terhelt_szamla'])) { $r[] = 'Terhelt saját számla: ' . $x['terhelt_szamla']; }
        if (!empty($x['kedv_szamla'])) { $r[] = 'Kedvezményezett számlája: ' . $x['kedv_szamla']; }
    }
    $r[] = 'Közlemény: ' . trim((string)($x['kozlemeny'] ?? ''));
    return implode(' · ', $r);
}

/** A DOS-utalás a BaninaPRO-ban (meglévő vagy most létrehozott, UTALVA + lakat). Pénznem-eltérésnél null. */
function utalas_dos(array $ref, int $cegId, string $penznem): ?array
{
    global $UTAL, $IRU, $AUDIT;
    $kulcs = $ref['tipus'] . '|' . trim((string)$ref['szam']);
    if (isset($UTAL[$kulcs])) {
        $u = $UTAL[$kulcs];
        if ($u['penznem'] !== $penznem) {
            jel('hiba', "a DOS-utalás ({$u['uid']}, $kulcs) pénzneme {$u['penznem']}, a számláé $penznem – nem kapcsolható");
            return null;
        }
        if ((int)$u['ceg_id'] !== $cegId) {
            jel('figyelem', "a DOS-utalás ({$u['uid']}) cége #{$u['ceg_id']}, a számláé #$cegId");
        }
        return $u;
    }
    $x = $IRU[$kulcs] ?? null;
    if ($x === null) {
        jel('hiba', "a számla DOS-utalása ($kulcs) nincs a DOS utalásai között – a számla FIZETENDŐ marad");
        return null;
    }
    $xp = strtoupper((string)($x['penznem'] ?? ($ref['tipus'] === 'forint' ? 'HUF' : 'EUR')));
    if ($xp !== $penznem) {
        jel('hiba', "a DOS-utalás ($kulcs) pénzneme $xp, a számláé $penznem – nem kapcsolható");
        return null;
    }
    [$utalva, $d1] = utalas_datumok($x);
    $ev = (int)substr($utalva ?? $d1 ?? date('Y-m-d'), 0, 4);
    $n = kovetkezo_sorszam('UTALAS', $ev, $penznem);
    $uid = utalas_uid($penznem, $ev, $n);
    $hiv = 'DOS ' . $ref['tipus'] . 'utalás #' . trim((string)$ref['szam']);
    db_exec("INSERT INTO utalasok (ceg_id, ev, penznem, sorszam, uid, utalas_mod, statusz, utalva_datum, banki_hivatkozas, megjegyzes, lezarva)
             VALUES (?, ?, ?, ?, ?, 'MANUAL', 'UTALVA', ?, ?, ?, 1)", [$cegId, $ev, $penznem, $n, $uid, $utalva, $hiv, utalas_megj($x, $penznem)]);
    $u = ['id' => (int)db()->lastInsertId(), 'uid' => $uid, 'ceg_id' => $cegId, 'ev' => $ev, 'penznem' => $penznem, 'statusz' => 'UTALVA', 'utalva_datum' => $utalva,
          'banki_hivatkozas' => $hiv, '_uj' => true];
    $UTAL[$kulcs] = $u;
    uj('utalas', "$uid ← $hiv" . ($utalva ? " ($utalva)" : ' (terhelés nélkül)'));
    return $u;
}

/** A DOS-tétel átutalási állapota: ['mod' => nincs|teljes|resz, 'ref', 'osszeg', 'datum'] */
function fizetes_dos(array $t, string $osszeg): array
{
    $ref = $t['utalas'] ?? null;
    if (!$ref || empty($ref['szam'])) {
        return ['mod' => 'nincs'];
    }
    $atutalt = mig_osszeg($t['atutalt'] ?? null);
    if ($atutalt !== null && abs((float)$atutalt) + 0.005 < abs((float)$osszeg) && abs((float)$atutalt) > 0.004) {
        return ['mod' => 'resz', 'ref' => $ref, 'osszeg' => $atutalt];
    }
    return ['mod' => 'teljes', 'ref' => $ref, 'osszeg' => $atutalt];
}

/** A bejövő DOS-tétel megjegyzése, ahogy a 10-07-i import írta */
function tetel_megj(array $t, array $fiz, ?array $x, string $penznem, array $datumMegj, ?string $tobb): string
{
    $r = ['DOS import', 'DOS azonosító: ' . trim($t['kotesszam']) . '/' . trim($t['kisszam']), 'Szállító DOS-kódja: ' . trim((string)$t['szallito'])];
    if (!empty($t['aru'])) { $r[] = 'Áru: ' . trim((string)$t['aru']); }
    if (!empty($t['vtsz'])) { $r[] = 'VTSZ: ' . trim((string)$t['vtsz']); }
    if (!empty($t['jarmu'])) { $r[] = 'Jármű/fuvarozó: ' . trim((string)$t['jarmu']); }
    if (!empty($t['aruertek'])) { $r[] = 'Áruérték (DOS): ' . mig_osszeg_hu((string)mig_osszeg($t['aruertek'])) . " $penznem"; }
    if ($fiz['mod'] === 'nincs') {
        $r[] = 'A DOS-ban nincs átutalva';
    } else {
        [, $d1, $d2] = $x ? utalas_datumok($x) : [null, null, null];
        $hiv = 'DOS ' . $fiz['ref']['tipus'] . 'utalás #' . trim((string)$fiz['ref']['szam']);
        if ($fiz['mod'] === 'resz') {
            $r[] = 'A DOS-ban átutalt: ' . mig_osszeg_hu($fiz['osszeg']) . " $penznem ($hiv, " . mig_datum_hu($d1) . ') – a különbözet a DOS-ban is nyitott';
        } else {
            $r[] = "Utalás: $hiv";
            $r[] = 'utalás napja (DOS): ' . mig_datum_hu($d1);
            $r[] = 'terhelés (DOS): ' . ($d2 ? mig_datum_hu($d2) : 'még nincs (a DOS-ban 00.00.00)');
        }
    }
    if ($tobb !== null) {
        $r[] = "FIGYELEM: a DOS-ban átutaltként $tobb $penznem szerepel (több, mint a számla) – valószínű elírás a DOS-ban";
    }
    foreach ($datumMegj as $dm) {
        $r[] = $dm;
    }
    return implode(' · ', $r);
}

/** A számla FIZETVE / BESZÁMÍTVA státusza a DOS-utalással (új és meglévő számlánál is) */
function bejovo_fizetve(array $u, string $osszeg): array
{
    return [(float)$osszeg < 0 ? 'BESZAMITVA' : 'FIZETVE', $u['utalva_datum'] ?? null, (int)$u['id']];
}

/** A számlaszám a kötésen belül egyedi: ütközésnél „ [kisszám]” toldalék (a 10-07-i import szerint) */
function egyedi_szamlaszam(int $kotesId, string $szamlaszam, string $toldalek): string
{
    if (!db_val('SELECT id FROM bejovo_szamlak WHERE kotes_id = ? AND szamlaszam_norm = ?', [$kotesId, norm_azonosito($szamlaszam)])) {
        return $szamlaszam;
    }
    return mb_substr($szamlaszam, 0, 80) . " [$toldalek]";
}

/**
 * A rekord adatmezőinek összevetése mezőnként: ha a mezőt október 7. óta senki nem módosította, a DOS szerint javul;
 * ha az IB (Excel) import javította, az marad (csak számoljuk); ha egy munkatárs, ütközés.
 */
function mezok_osszevet(string $tipus, array $db, array $uj, string $auditTipus, string $azon): array
{
    global $J;
    $javit = [];
    foreach ($uj as $m => $v) {
        $regi = (string)($db[$m] ?? '');
        $ujv = (string)$v;
        if ($m === 'szamlaszam') {
            $regi = preg_replace('/ \[[^\]]+\]$/u', '', $regi) ?? $regi;   // a kötésen belüli ütközés toldaléka nem eltérés
            if (norm_azonosito($regi) === norm_azonosito($ujv)) {
                continue;
            }
        } elseif ($m === 'osszeg') {
            if (abs((float)$regi - (float)$ujv) < 0.005) {
                continue;
            }
        } elseif ($regi === $ujv) {
            continue;
        }
        $e = erintett($auditTipus, (int)$db['id'], $m);
        if ($e === null) {
            $javit[$m] = $v;
        } elseif ($e['excel']) {
            $J['ib_marad'][$m] = ($J['ib_marad'][$m] ?? 0) + 1;
        } else {
            $J['utkozes'][] = ['tipus' => $tipus, 'azon' => $azon, 'mezo' => $m, 'baninapro' => $db[$m], 'dos' => $v, 'modositotta' => $e['ki']];
        }
    }
    return $javit;
}

// ---- a feldolgozás egy tranzakcióban ------------------------------------------------------------------------------------
$pdo = db();
$pdo->beginTransaction();
try {
    $latott = ['tetel' => [], 'forint' => [], 'kimeno' => [], 'utalas' => []];

    // (1) a meglévő DOS-utalások: a 00.00.00 terhelés pótlása, összeg-eltérés jelzése
    foreach ($IRU as $kulcs => $x) {
        $latott['utalas'][$kulcs] = true;
        if (!isset($UTAL[$kulcs])) {
            continue;
        }
        $u = $UTAL[$kulcs];
        [$utalva] = utalas_datumok($x);
        if ($u['utalva_datum'] === null && $utalva !== null) {
            db_exec('UPDATE utalasok SET utalva_datum = ?, megjegyzes = IF(COALESCE(megjegyzes, \'\') = \'\', TRIM(LEADING CHAR(10) FROM ?), CONCAT(megjegyzes, ?)) WHERE id = ?',
                array_merge([$utalva], array_fill(0, 2, "\nDOS import ($NAP): a terhelés napja a DOS-ban azóta rögzítve: " . mig_datum_hu($utalva)), [$u['id']]));
            foreach (db_all("SELECT b.id, b.kod, b.kotes_id FROM bejovo_szamlak b WHERE b.utalas_id = ? AND b.fizetve_datum IS NULL AND b.statusz IN ('FIZETVE', 'BESZAMITVA')", [$u['id']]) as $b) {
                db_exec('UPDATE bejovo_szamlak SET fizetve_datum = ? WHERE id = ?', [$utalva, $b['id']]);
                $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'MODOSIT', "DOS import ($NAP): a fizetés napja a DOS-utalás terhelése szerint ({$u['uid']})",
                            [['m' => AUDIT_CIMKEK['fizetve_datum'], 'r' => '–', 'u' => audit_ertek('fizetve_datum', $utalva)]]];
            }
            $UTAL[$kulcs]['utalva_datum'] = $utalva;
            jel('datum_potolva', "{$u['uid']} ({$u['banki_hivatkozas']}): terhelés " . mig_datum_hu($utalva));
        }
        $dbOssz = mig_osszeg(mig_megj_bont((string)$u['megjegyzes'])['mezok']['Összeg (DOS)'] ?? null);
        $irOssz = mig_osszeg($x['osszeg'] ?? null);
        if ($dbOssz !== null && $irOssz !== null && abs((float)$dbOssz - (float)$irOssz) >= 0.005) {
            jel('figyelem', "{$u['uid']} ({$u['banki_hivatkozas']}): a DOS-ban az utalás összege azóta $irOssz (korábban $dbOssz)");
        }
    }

    // (2) bejövő tételek (kötésekben) – a DOS rekordsorrendjében (a K sorszám is ezt követi)
    $IR_TETEL_KULCS = [];
    foreach ($IR['tetelek'] ?? [] as $t) {
        $IR_TETEL_KULCS[trim((string)$t['kotesszam']) . '/' . trim((string)$t['kisszam'])] = true;
    }
    $TETEL_ID_KULCS = [];
    foreach ($TETEL as $kk => $b) {
        $TETEL_ID_KULCS[(int)$b['id']] = $kk;
    }
    $irKotesAru = [];
    foreach ($IR['kotesek'] ?? [] as $x) {
        $irKotesAru[trim((string)$x['kotesszam'])] = trim((string)($x['aru'] ?? ''));
    }
    foreach ($IR['tetelek'] ?? [] as $t) {
        $kulcs = trim((string)$t['kotesszam']) . '/' . trim((string)$t['kisszam']);
        $latott['tetel'][$kulcs] = true;
        $penznem = strtoupper(trim((string)($t['penznem'] ?? 'EUR')));
        $osszeg = mig_osszeg($t['osszeg'] ?? null);
        $dm = [];
        $keltMegj = $hatMegj = null;
        $kelt = mig_datum($t['kelt'] ?? null, $keltMegj);
        $telj = mig_datum($t['teljesites'] ?? null) ?? $kelt;
        $hat = mig_datum($t['hatarido'] ?? null, $hatMegj);
        if ($hatMegj !== null && str_contains($hatMegj, 'nem létező nap')) { $dm[] = "A fizetési határidő a DOS-ban $hatMegj"; }
        if ($hat === null) { $hat = $kelt; }
        if (!in_array($penznem, ['EUR', 'HUF'], true) || $osszeg === null || $kelt === null || trim((string)$t['szamlaszam']) === '') {
            jel('hiba', "tétel $kulcs: hiányos adat (pénznem $penznem, összeg " . ($t['osszeg'] ?? '–') . ', kelt ' . ($t['kelt'] ?? '–') . ", számlaszám „{$t['szamlaszam']}”)" . ($keltMegj ? " – $keltMegj" : ''));
            continue;
        }
        $fiz = fizetes_dos($t, $osszeg);
        $tobb = ($fiz['mod'] === 'teljes' && $fiz['osszeg'] !== null && abs((float)$fiz['osszeg']) > abs((float)$osszeg) + 0.005) ? mig_osszeg_hu($fiz['osszeg']) : null;
        $adat = ['szamlaszam' => trim((string)$t['szamlaszam']), 'kelt' => $kelt, 'teljesites_datum' => $telj, 'fizetesi_hatarido' => $hat, 'osszeg' => $osszeg,
                 'beszam' => trim((string)($t['beszam'] ?? '')) !== '' ? trim((string)$t['beszam']) : null];

        if (isset($TETEL[$kulcs])) {
            // --- meglévő tétel
            $b = $TETEL[$kulcs];
            $ki = erintett_ki('BEJOVO', (int)$b['id']);
            $javit = mezok_osszevet('bejövő', $b, $adat, 'BEJOVO', "{$b['kod']} ($kulcs)");
            $valt = [];
            $set = [];
            $p = [];
            foreach ($javit as $m => $v) {
                $set[] = "$m = ?";
                $p[] = $v;
                if ($m === 'szamlaszam') { $set[] = 'szamlaszam_norm = ?'; $p[] = norm_azonosito($v); }
                $valt[] = ['m' => AUDIT_CIMKEK[$m] ?? $m, 'r' => audit_ertek($m, $b[$m], $b['penznem']), 'u' => audit_ertek($m, $v, $b['penznem'])];
                jel('javitva', ['tipus' => 'bejövő', 'kod' => $b['kod'], 'dos' => $kulcs, 'mezo' => $m, 'regi' => $b[$m], 'uj' => $v]);
            }
            // fizetés: a FIZETENDŐ (utalásban nem lévő) számla a DOS-utalással rendeződik
            $statuszLeiras = null;
            $ujStatusz = null;
            $resztIrva = false;
            if ($fiz['mod'] !== 'nincs') {
                $refKulcs = $fiz['ref']['tipus'] . '|' . trim((string)$fiz['ref']['szam']);
                $dbRef = mig_utalas_ref((string)$b['banki_hivatkozas']);
                if ($b['statusz'] === 'FIZETENDO' && $b['utalas_id'] === null) {
                    if ($fiz['mod'] === 'teljes') {
                        $u = utalas_dos($fiz['ref'], (int)$b['ceg_id'], $b['penznem']);
                        if ($u) {
                            [$ujStatusz, $fd, $uid] = bejovo_fizetve($u, (string)$b['osszeg']);
                            array_push($set, 'statusz = ?', 'fizetve_datum = ?', 'utalas_id = ?');
                            array_push($p, $ujStatusz, $fd, $uid);
                            $statuszLeiras = "DOS import ($NAP): " . ny_statusz_nev($ujStatusz) . " – {$u['banki_hivatkozas']} ({$u['uid']}" . ($fd ? ', ' . mig_datum_hu($fd) : ', terhelés nélkül') . ')';
                            jel('fizetve', "{$b['kod']} ($kulcs) → $ujStatusz, {$u['uid']} ({$u['banki_hivatkozas']})");
                        }
                    } elseif (!isset($RESZT["BEJOVO|{$b['id']}"])) {
                        $x = $IRU[$refKulcs] ?? null;
                        [, $d1] = $x ? utalas_datumok($x) : [null, null];
                        db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito) VALUES ('BEJOVO', ?, ?, ?, ?)",
                            [$b['id'], $fiz['osszeg'], $d1 ?? date('Y-m-d'), 'DOS ' . $fiz['ref']['tipus'] . 'utalás #' . trim((string)$fiz['ref']['szam'])]);
                        $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'RESZT', "DOS import ($NAP): részteljesítés " . mig_osszeg_hu($fiz['osszeg']) . " {$b['penznem']} (DOS-utalás $refKulcs)", []];
                        uj('reszt', "{$b['kod']}: " . mig_osszeg_hu($fiz['osszeg']) . " {$b['penznem']}");
                        $resztIrva = true;
                    }
                } elseif ($b['statusz'] === 'UTALASHOZ_ADVA') {
                    jel('utkozes', ['tipus' => 'bejövő', 'azon' => "{$b['kod']} ($kulcs)", 'mezo' => 'utalás',
                        'baninapro' => "utalásra vár: {$b['utalas_uid']}", 'dos' => "átutalva: $refKulcs", 'modositotta' => $ki]);
                } elseif ($dbRef !== null && "{$dbRef['tipus']}|{$dbRef['szam']}" !== $refKulcs) {
                    jel('utkozes', ['tipus' => 'bejövő', 'azon' => "{$b['kod']} ($kulcs)", 'mezo' => 'utalás',
                        'baninapro' => (string)$b['banki_hivatkozas'], 'dos' => $refKulcs, 'modositotta' => $ki]);
                }
            } elseif (in_array($b['statusz'], ['FIZETVE', 'BESZAMITVA'], true) && mig_utalas_ref((string)$b['banki_hivatkozas']) !== null) {
                jel('utkozes', ['tipus' => 'bejövő', 'azon' => "{$b['kod']} ($kulcs)", 'mezo' => 'utalás', 'baninapro' => "{$b['statusz']} ({$b['banki_hivatkozas']})",
                    'dos' => 'a DOS-ban már nincs átutalva', 'modositotta' => $ki]);
            }
            if ($set) {
                $p[] = $b['id'];
                db_exec('UPDATE bejovo_szamlak SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
                if ($valt) {
                    $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'MODOSIT', "DOS import ($NAP): javítva a DOS szerint", $valt];
                }
                if ($statuszLeiras) {
                    $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'STATUSZ', $statuszLeiras, [['m' => 'Státusz', 'r' => ny_statusz_nev('FIZETENDO'), 'u' => ny_statusz_nev($ujStatusz)]]];
                }
            } elseif (!$resztIrva) {
                $J['egyezik']++;
            }
            continue;
        }

        // --- új tétel
        $cegId = ceg_dos('VEVO', (string)$t['szallito']);
        if ($cegId === null) {
            jel('hiba', "tétel $kulcs: ismeretlen szállító ({$t['szallito']}) – nincs a DOS partnerei között");
            continue;
        }
        // ugyanennél a cégnél ugyanez a számlaszám egy olyan DOS-tételként, amely a DOS-ban már nincs meg: a tétel valószínűleg
        // másik kötésbe / kisszámra került a DOS-ban – nem hozzuk létre másodszor, döntésre jelentjük
        $athelyezve = null;
        foreach ($BEJOVO_CEG_SZAM["$cegId|" . norm_azonosito($adat['szamlaszam'])] ?? [] as $mid) {
            $mk = $TETEL_ID_KULCS[$mid] ?? null;
            if ($mk !== null && !isset($IR_TETEL_KULCS[$mk])) {
                $athelyezve = $mk;
                break;
            }
        }
        if ($athelyezve !== null) {
            jel('utkozes', ['tipus' => 'bejövő', 'azon' => "{$TETEL[$athelyezve]['kod']} ($athelyezve)", 'mezo' => 'DOS-kulcs', 'baninapro' => $athelyezve,
                'dos' => "$kulcs – ugyanez a számla a DOS-ban másik kötésbe / kisszámra került? (nem jött létre másodszor)", 'modositotta' => null]);
            continue;
        }
        // az IB importból már bent van ARCHÍV kötésben (azonos cég, pénznem, számlaszám a bevezető nullák nélkül, összeg)?
        $ibJeloltek = array_values(array_filter($IB_ARCHIV["$cegId|$penznem|" . mig_laza_kulcs($adat['szamlaszam'])] ?? [],
            fn($x) => abs((float)$x['osszeg'] - (float)$osszeg) < 0.005 && empty($x['_athelyezve'])));
        $ibSzamla = null;
        if (count($ibJeloltek) > 1) {
            jel('utkozes', ['tipus' => 'bejövő', 'azon' => $kulcs, 'mezo' => 'IB-archív', 'baninapro' => implode(', ', array_column($ibJeloltek, 'kod')),
                'dos' => "több IB-ből importált ARCHÍV számla is lehet ez („{$adat['szamlaszam']}”, $osszeg $penznem) – nem jött létre, válassz", 'modositotta' => null]);
            continue;
        }
        if ($ibJeloltek) {
            $ibSzamla = $ibJeloltek[0];
            if ($ibSzamla['utalas_id'] !== null || $ibSzamla['statusz'] !== 'FIZETENDO') {
                jel('utkozes', ['tipus' => 'bejövő', 'azon' => "{$ibSzamla['kod']} ($kulcs)", 'mezo' => 'IB-archív',
                    'baninapro' => "ARCHÍV, {$ibSzamla['statusz']}" . ($ibSzamla['utalas_id'] !== null ? ', utalásban' : ''),
                    'dos' => 'ugyanez a DOS-tétel – nem helyeztem át, mert a BaninaPRO-ban már utalásban van / rendezett', 'modositotta' => null]);
                continue;
            }
        }
        $kk = trim((string)$t['kotesszam']) . "|$cegId|$penznem";
        if (!isset($KOTES[$kk])) {
            $ev = mig_kotes_ev(trim((string)$t['kotesszam']), $kelt);
            $n = kovetkezo_sorszam('KOTES', $ev, $penznem);
            $kod = kotes_kod($ev, $penznem, $n);
            $megnev = trim((string)($t['aru'] ?? '')) !== '' ? trim((string)$t['aru']) : ($irKotesAru[trim((string)$t['kotesszam'])] ?? null);
            db_exec('INSERT INTO kotesek (ceg_id, ev, penznem, sorszam, kod, megnevezes, megjegyzes, archiv, regi_kod) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
                [$cegId, $ev, $penznem, $n, $kod, $megnev !== '' ? mb_substr((string)$megnev, 0, 191) : null, "DOS import ($NAP)", trim((string)$t['kotesszam'])]);
            $KOTES[$kk] = ['id' => (int)db()->lastInsertId(), 'kod' => $kod, 'ceg_id' => $cegId, 'penznem' => $penznem, 'regi_kod' => trim((string)$t['kotesszam'])];
            $AUDIT[] = ['KOTES', $KOTES[$kk]['id'], null, 'IMPORT', "DOS importból létrehozva: $kod – régi (DOS) kötésszám: " . trim((string)$t['kotesszam']), []];
            uj('kotes', "$kod ← " . trim((string)$t['kotesszam']) . " ({$CEG[$cegId]['nev']})");
        }
        $kot = $KOTES[$kk];
        $statusz = 'FIZETENDO';
        $fd = null;
        $uid = null;
        $x = null;
        if ($fiz['mod'] !== 'nincs') {
            $x = $IRU[$fiz['ref']['tipus'] . '|' . trim((string)$fiz['ref']['szam'])] ?? null;
            if ($fiz['mod'] === 'teljes') {
                $u = utalas_dos($fiz['ref'], $cegId, $penznem);
                if ($u) {
                    [$statusz, $fd, $uid] = bejovo_fizetve($u, $osszeg);
                }
            }
        }
        $ksz = kovetkezo_k_sorszam((int)$kot['id']);
        $kod = szamla_kod($kot['kod'], $ksz);
        if ($ibSzamla !== null) {
            // áthelyezés az IB-archív kötésből a DOS-kötésbe (mint az app „archív számlák áthelyezése”): új azonosító, az IB-adatok
            // (számlaszám, összeg, dátumok) maradnak, a DOS-ból a kisszám, a BESZÁM (ha üres), a fizetés és a DOS-adatok jönnek
            $szsz = egyedi_szamlaszam((int)$kot['id'], $ibSzamla['szamlaszam'], trim((string)$t['kisszam']));
            db_exec('UPDATE bejovo_szamlak SET kotes_id = ?, k_sorszam = ?, kod = ?, szamlaszam = ?, szamlaszam_norm = ?, regi_k = ?, beszam = COALESCE(beszam, ?),
                            statusz = ?, fizetve_datum = ?, utalas_id = ?, megjegyzes = IF(COALESCE(megjegyzes, \'\') = \'\', TRIM(LEADING CHAR(10) FROM ?), CONCAT(megjegyzes, ?)) WHERE id = ?',
                array_merge([$kot['id'], $ksz, $kod, $szsz, norm_azonosito($szsz), trim((string)$t['kisszam']), $adat['beszam'], $statusz, $fd, $uid],
                    array_fill(0, 2, "\n" . tetel_megj($t, $fiz, $x, $penznem, $dm, $tobb)), [$ibSzamla['id']]));
            $bid = (int)$ibSzamla['id'];
            $AUDIT[] = ['BEJOVO', $bid, (int)$kot['id'], 'ATHELYEZ', "DOS import ($NAP): kötésbe helyezve az IB-archív kötésből: {$ibSzamla['kotes_kod']} → {$kot['kod']} (DOS: $kulcs)",
                        [['m' => 'Kötés', 'r' => $ibSzamla['kotes_kod'], 'u' => $kot['kod']], ['m' => 'Azonosító', 'r' => $ibSzamla['kod'], 'u' => $kod]]
                        + ($statusz !== 'FIZETENDO' ? [2 => ['m' => 'Státusz', 'r' => ny_statusz_nev('FIZETENDO'), 'u' => ny_statusz_nev($statusz)]] : [])];
            $AUDIT[] = ['KOTES', (int)$ibSzamla['kotes_id'], null, 'ATHELYEZ', "DOS import ($NAP): számla áthelyezve innen a(z) {$kot['kod']} kötésbe: {$ibSzamla['kod']} „{$ibSzamla['szamlaszam']}” → $kod", []];
            foreach ($IB_ARCHIV["$cegId|$penznem|" . mig_laza_kulcs($adat['szamlaszam'])] as &$x2) {
                if ((int)$x2['id'] === $bid) { $x2['_athelyezve'] = true; }
            }
            unset($x2);
            if ($fiz['mod'] === 'resz') {
                [, $d1] = $x ? utalas_datumok($x) : [null, null];
                db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito) VALUES ('BEJOVO', ?, ?, ?, ?)",
                    [$bid, $fiz['osszeg'], $d1 ?? $kelt, 'DOS ' . $fiz['ref']['tipus'] . 'utalás #' . trim((string)$fiz['ref']['szam'])]);
                uj('reszt', "$kod: " . mig_osszeg_hu($fiz['osszeg']) . " $penznem");
            }
            uj('athelyezve', "{$ibSzamla['kod']} → $kod „{$szsz}” (IB-archívból a DOS-kötésbe, $kulcs) · $statusz");
            continue;
        }
        $szsz = egyedi_szamlaszam((int)$kot['id'], $adat['szamlaszam'], trim((string)$t['kisszam']));
        db_exec('INSERT INTO bejovo_szamlak (kotes_id, k_sorszam, kod, szamlaszam, szamlaszam_norm, teljesites_datum, kelt, osszeg, beszam, regi_k, fizetesi_hatarido,
                                             statusz, fizetve_datum, utalas_id, megjegyzes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$kot['id'], $ksz, $kod, $szsz, norm_azonosito($szsz), $telj, $kelt, $osszeg, $adat['beszam'], trim((string)$t['kisszam']), $hat, $statusz, $fd, $uid,
             tetel_megj($t, $fiz, $x, $penznem, $dm, $tobb)]);
        $bid = (int)db()->lastInsertId();
        if ($fiz['mod'] === 'resz') {
            [, $d1] = $x ? utalas_datumok($x) : [null, null];
            db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito) VALUES ('BEJOVO', ?, ?, ?, ?)",
                [$bid, $fiz['osszeg'], $d1 ?? $kelt, 'DOS ' . $fiz['ref']['tipus'] . 'utalás #' . trim((string)$fiz['ref']['szam'])]);
            uj('reszt', "$kod: " . mig_osszeg_hu($fiz['osszeg']) . " $penznem");
        }
        $AUDIT[] = ['BEJOVO', $bid, (int)$kot['id'], 'IMPORT', "DOS importból létrehozva: $kod „{$szsz}” ($osszeg $penznem)", []];
        $BEJOVO_CEG_SZAM["$cegId|" . norm_azonosito($szsz)][] = $bid;
        uj('bejovo', "$kod „{$szsz}” $osszeg $penznem · $statusz ($kulcs)");
    }

    // (3) DOS forintszámlák (HUF bejövő, cégenként egy ARCHÍV – DOS import kötésben)
    foreach ($IR['forintszamlak'] ?? [] as $t) {
        $azon = trim((string)$t['azon']);
        $latott['forint'][$azon] = true;
        $osszeg = mig_osszeg($t['brutto'] ?? ($t['osszeg'] ?? null));
        $kelt = mig_datum($t['kelt'] ?? null);
        $telj = mig_datum($t['teljesites'] ?? null) ?? $kelt;
        $hatMegj = null;
        $hat = mig_datum($t['hatarido'] ?? null, $hatMegj) ?? $kelt;
        if ($osszeg === null || $kelt === null) {
            jel('hiba', "forintszámla $azon: hiányos adat (összeg / kelt)");
            continue;
        }
        $szamlaszam = trim((string)($t['szamlaszam'] ?? '')) !== '' ? trim((string)$t['szamlaszam']) : $azon;
        $ref = !empty($t['utalas']['szam']) ? $t['utalas'] : null;
        $adat = ['szamlaszam' => $szamlaszam, 'kelt' => $kelt, 'teljesites_datum' => $telj, 'fizetesi_hatarido' => $hat, 'osszeg' => $osszeg];
        if (isset($FORINT[$azon])) {
            $b = $FORINT[$azon];
            $ki = erintett_ki('BEJOVO', (int)$b['id']);
            $javit = mezok_osszevet('forintszámla', $b, $adat, 'BEJOVO', "{$b['kod']} ($azon)");
            $set = [];
            $p = [];
            $valt = [];
            foreach ($javit as $m => $v) {
                $set[] = "$m = ?";
                $p[] = $v;
                if ($m === 'szamlaszam') { $set[] = 'szamlaszam_norm = ?'; $p[] = norm_azonosito($v); }
                $valt[] = ['m' => AUDIT_CIMKEK[$m] ?? $m, 'r' => audit_ertek($m, $b[$m], 'HUF'), 'u' => audit_ertek($m, $v, 'HUF')];
                jel('javitva', ['tipus' => 'forintszámla', 'kod' => $b['kod'], 'dos' => $azon, 'mezo' => $m, 'regi' => $b[$m], 'uj' => $v]);
            }
            $statuszLeiras = null;
            if ($ref && $b['statusz'] === 'FIZETENDO' && $b['utalas_id'] === null) {
                $u = utalas_dos($ref, (int)$b['ceg_id'], 'HUF');
                if ($u) {
                    [$st, $fd, $uid] = bejovo_fizetve($u, (string)$b['osszeg']);
                    array_push($set, 'statusz = ?', 'fizetve_datum = ?', 'utalas_id = ?');
                    array_push($p, $st, $fd, $uid);
                    $statuszLeiras = "DOS import ($NAP): $st – {$u['banki_hivatkozas']} ({$u['uid']})";
                    jel('fizetve', "{$b['kod']} ($azon) → $st, {$u['uid']}");
                }
            } elseif ($ref && $b['statusz'] === 'UTALASHOZ_ADVA') {
                jel('utkozes', ['tipus' => 'forintszámla', 'azon' => "{$b['kod']} ($azon)", 'mezo' => 'utalás', 'baninapro' => "utalásra vár: {$b['utalas_uid']}",
                    'dos' => 'átutalva: forint|' . $ref['szam'], 'modositotta' => $ki]);
            }
            if ($set) {
                $p[] = $b['id'];
                db_exec('UPDATE bejovo_szamlak SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
                if ($valt) { $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'MODOSIT', "DOS import ($NAP): javítva a DOS szerint", $valt]; }
                if ($statuszLeiras) { $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'STATUSZ', $statuszLeiras, []]; }
            } else {
                $J['egyezik']++;
            }
            continue;
        }
        $cegId = ceg_dos((string)($t['partner_tabla'] ?? 'UGYF'), (string)($t['partner'] ?? ''));
        if ($cegId === null) {
            jel('hiba', "forintszámla $azon: ismeretlen partner ({$t['partner']})");
            continue;
        }
        $ev = (int)substr($kelt, 0, 4);
        $ak = "$cegId|HUF|$ev";
        if (!isset($ARCHIV[$ak])) {
            $n = kovetkezo_sorszam('KOTES', $ev, 'HUF');
            $kod = kotes_kod($ev, 'HUF', $n);
            db_exec("INSERT INTO kotesek (ceg_id, ev, penznem, sorszam, kod, megnevezes, megjegyzes, archiv, regi_kod) VALUES (?, ?, 'HUF', ?, ?, ?, ?, 1, NULL)",
                [$cegId, $ev, $n, $kod, "ARCHÍV – DOS import $ev", "DOS import ($NAP)"]);
            $ARCHIV[$ak] = ['id' => (int)db()->lastInsertId(), 'kod' => $kod];
            $AUDIT[] = ['KOTES', $ARCHIV[$ak]['id'], null, 'IMPORT', "DOS importból létrehozva: $kod – ARCHÍV (DOS forintszámlák, {$CEG[$cegId]['nev']})", []];
            uj('kotes', "$kod ARCHÍV ({$CEG[$cegId]['nev']}, HUF $ev)");
        }
        $kot = $ARCHIV[$ak];
        $statusz = 'FIZETENDO';
        $fd = null;
        $uid = null;
        $u = null;
        if ($ref) {
            $u = utalas_dos($ref, $cegId, 'HUF');
            if ($u) {
                [$statusz, $fd, $uid] = bejovo_fizetve($u, $osszeg);
            }
        }
        $megj = ['DOS import', "DOS forintszámla: $azon"];
        if (!empty($t['megnevezes'])) { $megj[] = 'Megnevezés: ' . trim((string)$t['megnevezes']); }
        if (isset($t['netto'])) { $megj[] = 'Nettó: ' . mig_osszeg_hu((string)mig_osszeg($t['netto'])); }
        if (isset($t['afa'])) { $megj[] = 'ÁFA (' . (int)($t['afa_kulcs'] ?? 0) . '%): ' . mig_osszeg_hu((string)mig_osszeg($t['afa'])); }
        $megj[] = 'Bruttó: ' . mig_osszeg_hu($osszeg) . ' HUF';
        if (!empty($t['bankszamla'])) { $megj[] = 'Szállító bankszámlája: ' . trim((string)$t['bankszamla']); }
        if ($ref) {
            $x = $IRU['forint|' . trim((string)$ref['szam'])] ?? null;
            [, $d1] = $x ? utalas_datumok($x) : [null, null];
            $megj[] = 'Utalás: DOS forintutalás #' . trim((string)$ref['szam']) . ($d1 ? ' (' . mig_datum_hu($d1) . ')' : '');
        }
        if ($hatMegj !== null && str_contains($hatMegj, 'nem létező nap')) { $megj[] = "A fizetési határidő a DOS-ban $hatMegj"; }
        $ksz = kovetkezo_k_sorszam((int)$kot['id']);
        $kod = szamla_kod($kot['kod'], $ksz);
        $szsz = egyedi_szamlaszam((int)$kot['id'], $szamlaszam, $azon);
        db_exec('INSERT INTO bejovo_szamlak (kotes_id, k_sorszam, kod, szamlaszam, szamlaszam_norm, teljesites_datum, kelt, osszeg, beszam, regi_k, fizetesi_hatarido,
                                             statusz, fizetve_datum, utalas_id, megjegyzes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
            [$kot['id'], $ksz, $kod, $szsz, norm_azonosito($szsz), $telj, $kelt, $osszeg, $azon, $hat, $statusz, $fd, $uid, implode(' · ', $megj)]);
        $bid = (int)db()->lastInsertId();
        $AUDIT[] = ['BEJOVO', $bid, (int)$kot['id'], 'IMPORT', "DOS importból létrehozva: $kod „{$szsz}” ($osszeg HUF)", []];
        uj('forintszamla', "$kod „{$szsz}” $osszeg HUF · $statusz ($azon)");
    }

    // (4) kimenő számlák
    foreach ($IR['kimeno'] ?? [] as $s) {
        $azon = preg_replace('/\s+/', ' ', trim((string)$s['azon']));
        $latott['kimeno'][$azon] = true;
        $penznem = strtoupper(trim((string)($s['penznem'] ?? 'EUR')));
        $osszeg = mig_osszeg($s['brutto'] ?? null);
        $hatMegj = null;
        $kelt = mig_datum($s['kelt'] ?? null);
        $telj = mig_datum($s['teljesites'] ?? null) ?? $kelt;
        $hat = mig_datum($s['hatarido'] ?? null, $hatMegj) ?? $kelt;
        if ($osszeg === null || $kelt === null || !in_array($penznem, ['EUR', 'HUF'], true)) {
            jel('hiba', "kimenő $azon: hiányos adat (összeg / kelt / pénznem)");
            continue;
        }
        $ksz = mig_kimeno_szamlaszam($azon, $kelt);
        if ($ksz === null && !isset($KIM[$azon])) {
            jel('hiba', "kimenő „{$azon}”: a DOS-azonosítóból nem képezhető számlaszám (várt alak: „KE-26 1126”)");
            continue;
        }
        $ev = $ksz['ev'] ?? (int)substr($kelt, 0, 4);
        $szamlaszam = $ksz['szamlaszam'] ?? '';
        $befolyt = mig_osszeg($s['befolyt'] ?? null);
        $befDatum = mig_datum($s['befolyt_datum'] ?? null);
        if ($befolyt !== null && abs((float)$befolyt) < 0.005) { $befolyt = null; }
        $adat = ['kelt' => $kelt, 'teljesites_datum' => $telj, 'fizetesi_hatarido' => $hat, 'osszeg' => $osszeg];
        $db = $KIM[$azon] ?? ($KIMN[norm_azonosito($szamlaszam)] ?? null);
        if ($db !== null) {
            $ki = erintett_ki('KIMENO', (int)$db['id']);
            $javit = mezok_osszevet('kimenő', $db, $adat, 'KIMENO', "{$db['kod']} ($azon)");
            $set = [];
            $p = [];
            $valt = [];
            foreach ($javit as $mm => $v) {
                $set[] = "$mm = ?";
                $p[] = $v;
                $valt[] = ['m' => AUDIT_CIMKEK[$mm] ?? $mm, 'r' => audit_ertek($mm, $db[$mm], $db['penznem']), 'u' => audit_ertek($mm, $v, $db['penznem'])];
                jel('javitva', ['tipus' => 'kimenő', 'kod' => $db['kod'], 'dos' => $azon, 'mezo' => $mm, 'regi' => $db[$mm], 'uj' => $v]);
            }
            $statuszLeiras = null;
            $resztIrva = false;
            if ($befolyt !== null && $db['statusz'] === 'NYITOTT' && $db['banki_kivonat_id'] === null) {
                $volt = $RESZT["KIMENO|{$db['id']}"][0] ?? null;
                if ((float)$befolyt + 0.005 >= (float)$db['osszeg'] || (float)$db['osszeg'] < 0) {
                    $bk = bank_dos($befDatum ?? $kelt, $db['penznem']);
                    array_push($set, "statusz = 'FIZETVE'", 'banki_kivonat_id = ?', 'fizetve_datum = ?');
                    array_push($p, $bk, $befDatum ?? $kelt);
                    $statuszLeiras = "DOS import ($NAP): FIZETVE – befolyt " . mig_osszeg_hu($befolyt) . " {$db['penznem']} (" . mig_datum_hu($befDatum) . ')';
                    jel('fizetve', "{$db['kod']} ($azon) → FIZETVE, befolyt $befolyt (" . mig_datum_hu($befDatum) . ')');
                } elseif ($volt === null) {
                    db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito) VALUES ('KIMENO', ?, ?, ?, 'DOS import')", [$db['id'], $befolyt, $befDatum ?? $kelt]);
                    $AUDIT[] = ['KIMENO', (int)$db['id'], null, 'RESZT', "DOS import ($NAP): részteljesítés " . mig_osszeg_hu($befolyt) . " {$db['penznem']}", []];
                    uj('reszt', "{$db['kod']}: " . mig_osszeg_hu($befolyt) . " {$db['penznem']}");
                    $resztIrva = true;
                } elseif (abs((float)$volt - (float)$befolyt) >= 0.005) {
                    jel('utkozes', ['tipus' => 'kimenő', 'azon' => "{$db['kod']} ($azon)", 'mezo' => 'részteljesítés', 'baninapro' => $volt, 'dos' => $befolyt, 'modositotta' => $ki]);
                }
            } elseif ($befolyt === null && $db['statusz'] === 'FIZETVE' && isset($db['_dos']['Befolyt'])) {
                jel('utkozes', ['tipus' => 'kimenő', 'azon' => "{$db['kod']} ($azon)", 'mezo' => 'befolyás', 'baninapro' => 'FIZETVE (' . $db['_dos']['Befolyt'] . ')',
                    'dos' => 'a DOS-ban már nincs befolyás', 'modositotta' => $ki]);
            }
            if ($set) {
                $p[] = $db['id'];
                db_exec('UPDATE kimeno_szamlak SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
                if ($valt) { $AUDIT[] = ['KIMENO', (int)$db['id'], null, 'MODOSIT', "DOS import ($NAP): javítva a DOS szerint", $valt]; }
                if ($statuszLeiras) { $AUDIT[] = ['KIMENO', (int)$db['id'], null, 'STATUSZ', $statuszLeiras, [['m' => 'Státusz', 'r' => 'NYITOTT', 'u' => 'FIZETVE']]]; }
            } elseif (!$resztIrva) {
                $J['egyezik']++;
            }
            continue;
        }
        $cegId = ceg_dos('UGYF', (string)($s['vevo'] ?? ''));
        if ($cegId === null) {
            jel('hiba', "kimenő $azon: ismeretlen vevő ({$s['vevo']})");
            continue;
        }
        $statusz = 'NYITOTT';
        $bk = null;
        $fd = null;
        $tobb = null;
        if ($befolyt !== null && ((float)$befolyt + 0.005 >= (float)$osszeg || (float)$osszeg < 0)) {
            $statusz = 'FIZETVE';
            $fd = $befDatum ?? $kelt;
            $bk = bank_dos($fd, $penznem);
            if ((float)$befolyt > (float)$osszeg + 0.005 && (float)$osszeg > 0) { $tobb = mig_osszeg_hu($befolyt); }
        }
        $megj = ['DOS import', "DOS azonosító: $azon", 'Vevő adószáma (DOS): ' . trim((string)($s['vevo'] ?? ''))];
        if (!empty($s['megrendeles'])) { $megj[] = 'Megrendelés: ' . trim((string)$s['megrendeles']); }
        if (!empty($s['fizmod'])) { $megj[] = 'Fizetési mód: ' . trim((string)$s['fizmod']); }
        if (isset($s['netto'])) { $megj[] = 'Nettó: ' . mig_osszeg_hu((string)mig_osszeg($s['netto'])) . " $penznem"; }
        if (isset($s['afa'])) { $megj[] = 'ÁFA (' . (int)($s['afa_kulcs'] ?? 0) . '%): ' . mig_osszeg_hu((string)mig_osszeg($s['afa'])); }
        $megj[] = 'Bruttó: ' . mig_osszeg_hu($osszeg) . " $penznem";
        $megj[] = $befolyt !== null ? 'Befolyt: ' . mig_osszeg_hu($befolyt) . " $penznem (" . mig_datum_hu($befDatum) . ')' : 'Befolyás a DOS-ban nincs rögzítve';
        if ($tobb !== null) { $megj[] = "FIGYELEM: a DOS-ban a befolyt összeg ($tobb) több, mint a számla – valószínű elírás a DOS-ban"; }
        if (!empty($s['egyeb'])) { $megj[] = 'Egyéb: ' . trim((string)$s['egyeb']); }
        if ($hatMegj !== null && str_contains($hatMegj, 'nem létező nap')) { $megj[] = "A fizetési határidő a DOS-ban $hatMegj"; }
        if (isset($KIMN[norm_azonosito($szamlaszam)])) {
            jel('hiba', "kimenő $azon: a számlaszám ($szamlaszam) már egy másik kimenő számláé ({$KIMN[norm_azonosito($szamlaszam)]['kod']})");
            continue;
        }
        $n = kovetkezo_sorszam('KIMENO', $ev, $penznem);
        $kod = kimeno_kod($ev, $penznem, $n);
        db_exec('INSERT INTO kimeno_szamlak (ceg_id, ev, penznem, sorszam, kod, szamlaszam, szamlaszam_norm, teljesites_datum, kelt, fizetesi_hatarido, osszeg, statusz,
                                             banki_kivonat_id, fizetve_datum, megjegyzes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$cegId, $ev, $penznem, $n, $kod, $szamlaszam, norm_azonosito($szamlaszam), $telj, $kelt, $hat, $osszeg, $statusz, $bk, $fd, implode(' · ', $megj)]);
        $sid = (int)db()->lastInsertId();
        $KIMN[norm_azonosito($szamlaszam)] = ['id' => $sid, 'kod' => $kod];
        if ($statusz === 'NYITOTT' && $befolyt !== null) {
            db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito) VALUES ('KIMENO', ?, ?, ?, 'DOS import')", [$sid, $befolyt, $befDatum ?? $kelt]);
            uj('reszt', "$kod: " . mig_osszeg_hu($befolyt) . " $penznem");
        }
        $AUDIT[] = ['KIMENO', $sid, null, 'IMPORT', "DOS importból létrehozva: $kod „{$szamlaszam}” ($osszeg $penznem)", []];
        uj('kimeno', "$kod „{$szamlaszam}” $osszeg $penznem · $statusz ({$CEG[$cegId]['nev']})");
    }

    // (5) ami a DOS-ból azóta eltűnt (csak jelentés)
    foreach ($TETEL as $kk => $b) {
        if (!isset($latott['tetel'][$kk])) { jel('hianyzik', "bejövő {$b['kod']} ($kk)"); }
    }
    foreach ($FORINT as $kk => $b) {
        if (!isset($latott['forint'][$kk])) { jel('hianyzik', "forintszámla {$b['kod']} ($kk)"); }
    }
    foreach ($KIM as $kk => $s) {
        if (!isset($latott['kimeno'][$kk])) { jel('hianyzik', "kimenő {$s['kod']} ($kk)"); }
    }
    foreach ($UTAL as $kk => $u) {
        if (empty($u['_uj']) && !isset($latott['utalas'][$kk])) { jel('hianyzik', "utalás {$u['uid']} ($kk)"); }
    }

    audit_ir_tobb(array_map(fn($a) => [$a[0], $a[1], $a[2], $a[3], $a[4], $a[5]], $AUDIT), $U);
    if ($SZARAZ) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'HIBA (semmi nem változott): ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}

// ---- jelentés ---------------------------------------------------------------------------------------------------------------
$db = static fn(string $k) => count($J[$k]);
$ujDb = array_map('count', $J['uj']);
$sor = [];
$sor[] = sprintf('DOS-átvétel%s: %s → %s (%s, %.1f mp)', $SZARAZ ? ' – SZÁRAZ FUTÁS, az adatbázis nem változott' : '', basename($fajl), DB_NAME, date('Y-m-d H:i'), microtime(true) - $t0);
$sor[] = sprintf('ÚJ: %d cég, %d kötés, %d bejövő számla (+ %d az IB-archívból áthelyezve), %d forintszámla, %d utalás, %d kimenő számla, %d banki kivonat, %d részteljesítés',
    $ujDb['ceg'], $ujDb['kotes'], $ujDb['bejovo'], $ujDb['athelyezve'], $ujDb['forintszamla'], $ujDb['utalas'], $ujDb['kimeno'], $ujDb['bank'], $ujDb['reszt']);
$sor[] = sprintf('MEGLÉVŐ: %d egyezik, %d mező javítva a DOS szerint, %d számla rendeződött (fizetés), %d utalás terhelése pótolva',
    $J['egyezik'], $db('javitva'), $db('fizetve'), $db('datum_potolva'));
$sor[] = sprintf('DÖNTÉSRE: %d ütközés (a BaninaPRO-ban azóta módosítva) · %d a DOS-ból eltűnt · %d figyelmeztetés · %d HIBA (kihagyva)',
    $db('utkozes'), $db('hianyzik'), $db('figyelem'), $db('hiba'));
foreach (['hiba' => 'HIBA', 'utkozes' => 'ÜTKÖZÉS', 'hianyzik' => 'A DOS-BÓL ELTŰNT', 'figyelem' => 'FIGYELEM'] as $kk => $cim) {
    if ($J[$kk]) {
        $sor[] = '';
        $sor[] = "$cim (" . count($J[$kk]) . '):';
        foreach (array_slice($J[$kk], 0, 40) as $x) {
            $sor[] = '  ' . (is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : $x);
        }
    }
}
$szoveg = implode("\n", $sor) . "\n";
file_put_contents("$KI/dos_atvetel.txt", $szoveg);
file_put_contents("$KI/dos_atvetel.json", json_encode($J, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
if (!$SZARAZ) {
    naplo('DOS_IMPORT', strip_tags($sor[1] . ' · ' . $sor[2] . ' · ' . $sor[3]), 'OK', $FELH);
}
echo $szoveg;
