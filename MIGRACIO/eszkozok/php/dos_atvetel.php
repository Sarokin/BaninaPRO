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
      'javitva' => [], 'fizetve' => [], 'datum_potolva' => [], 'utkozes' => [], 'hianyzik' => [], 'figyelem' => [], 'hiba' => [], 'egyezik' => 0, 'ib_marad' => [],
      'megj_frissitve' => 0, 'megj_marad' => []];
$AUDIT = [];   // [tipus, rekordId, szuloId, muvelet, leiras, valtozasok]
// A dos_koztes.py köztes fájlja a 10-07-i import kiszámolt értékeit is viszi (állapot, fizetési dátum, részteljesítés, megjegyzés):
// ilyenkor ezek a mérvadók – az új rekordok pontosan a 10-07-i import formájában jönnek létre.
$UJF = isset($IR['ig']);

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
foreach (db_all('SELECT id, uid, ceg_id, ev, penznem, sorszam, statusz, utalva_datum, banki_hivatkozas, megjegyzes, utalta, letrehozta FROM utalasok') as $r) {
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
$BANK_ID = [];
foreach (db_all('SELECT id, azonosito, azonosito_norm, datum FROM banki_kivonatok') as $r) {
    $BANK[$r['azonosito_norm']] = $r;
    $BANK_ID[(int)$r['id']] = $r;
}
$RESZT = [];                  // "BEJOVO|id" → [összeg, darab]
foreach (db_all('SELECT irany, szamla_id, SUM(osszeg) ossz, COUNT(*) db FROM reszteljesitesek GROUP BY irany, szamla_id') as $r) {
    $RESZT["{$r['irany']}|{$r['szamla_id']}"] = [$r['ossz'], (int)$r['db']];
}
$RESZT_SOR = [];              // "BEJOVO|id" → a részteljesítés sorai (a DOS-ból jöttek: letrehozta üres, „DOS …” azonosító)
foreach (db_all('SELECT id, irany, szamla_id, osszeg, datum, banki_azonosito, letrehozta FROM reszteljesitesek ORDER BY id') as $r) {
    $RESZT_SOR["{$r['irany']}|{$r['szamla_id']}"][] = $r;
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
    if (isset($p['csoport'])) {
        // a DOS-átalakító (dos_koztes.py) a 10-07-i import cégösszevonását adja (azonos adószám, kézi lista): ha a csoport egy
        // másik DOS-kódja már egy meglévő céghez tartozik, az új kód is oda kerül. Név szerint NEM párosít (a felhasználó szabálya).
        foreach ($p['csoport'] as $tk) {
            [$t2, $k2] = explode(' ', (string)$tk, 2) + [1 => ''];
            $id = $t2 === 'VEVO' ? ($VEVO[$k2] ?? null) : ($UGYF[$k2] ?? null);
            if ($id !== null) {
                db_exec('UPDATE cegek SET megjegyzes = IF(COALESCE(megjegyzes, \'\') = \'\', TRIM(LEADING CHAR(10) FROM ?), CONCAT(megjegyzes, ?)) WHERE id = ?',
                    array_merge(array_fill(0, 2, "\nDOS-kód: $tabla $kod (DOS import $NAP, a DOS-ban azonos cég: $tk)"), [$id]));
                if ($tabla === 'VEVO') { $VEVO[$kod] = $id; } else { $UGYF[$kod] = $id; }
                jel('figyelem', "új DOS-kód ($tabla $kod) a meglévő céghez került (#$id {$CEG[$id]['nev']}), mert a DOS-ban azonos cég ($tk)");
                return $id;
            }
        }
        $c = $p['ceg'];
        $nev = mb_substr(trim((string)$c['nev']), 0, 191);
        if (db_val('SELECT id FROM cegek WHERE nev = ?', [$nev])) {
            $nev = mb_substr($nev, 0, 170) . " (DOS $tabla $kod)";
        }
        $pk = $c['partnerkod'] ?? null;
        if ($pk !== null && db_val('SELECT id FROM cegek WHERE partnerkod = ?', [$pk])) {
            $pk = null;
        }
        db_exec('INSERT INTO cegek (nev, adoszam, partnerkod, megjegyzes) VALUES (?, ?, ?, ?)', [$nev, $c['adoszam'] ?? null, $pk, $c['megjegyzes']]);
        $id = (int)db()->lastInsertId();
        $CEG[$id] = ['id' => $id, 'nev' => $nev];
        if ($tabla === 'VEVO') { $VEVO[$kod] = $id; } else { $UGYF[$kod] = $id; }
        uj('ceg', "#$id $nev ($tabla $kod" . (count($p['csoport']) > 1 ? ', a DOS-ban azonos: ' . implode(', ', $p['csoport']) : '') . ')');
        return $id;
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
    if (array_key_exists('utalva', $x)) {
        $utalva = mig_datum($x['utalva']);      // a 10-07-i import: devizánál a terhelés, forintnál az utalás napja
    }
    // év: a 10-07-i import a DOS-utalás keltét (megbízás; ha nincs, a terhelést) vette
    $ev = !empty($x['datum']) ? (int)substr((string)$x['datum'], 0, 4) : (int)substr($utalva ?? $d1 ?? date('Y-m-d'), 0, 4);
    $n = kovetkezo_sorszam('UTALAS', $ev, $penznem);
    $uid = utalas_uid($penznem, $ev, $n);
    $hiv = $x['hivatkozas'] ?? ('DOS ' . $ref['tipus'] . 'utalás #' . trim((string)$ref['szam']));
    db_exec("INSERT INTO utalasok (ceg_id, ev, penznem, sorszam, uid, utalas_mod, statusz, utalva_datum, banki_hivatkozas, megjegyzes, lezarva)
             VALUES (?, ?, ?, ?, ?, 'MANUAL', 'UTALVA', ?, ?, ?, 1)", [$cegId, $ev, $penznem, $n, $uid, $utalva, $hiv, $x['megjegyzes'] ?? utalas_megj($x, $penznem)]);
    $u = ['id' => (int)db()->lastInsertId(), 'uid' => $uid, 'ceg_id' => $cegId, 'ev' => $ev, 'penznem' => $penznem, 'statusz' => 'UTALVA', 'utalva_datum' => $utalva,
          'banki_hivatkozas' => $hiv, 'sorszam' => $n, '_uj' => true, '_sorrend' => $x['sorrend'] ?? null];
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

/**
 * A rekord DOS-megjegyzése a 10-07-i import formájában (a köztes fájlból): ha a DOS-adat azóta változott (pl. azóta átutalták,
 * beírták a terhelést), a megjegyzés is a DOS mostani állapotát mutassa. Csak ha a megjegyzéshez azóta senki (munkatárs, IB
 * import) nem nyúlt – egyébként marad, és a jelentés felsorolja.
 */
function megj_frissit(string $auditTipus, array $db, ?string $uj, array &$set, array &$p, array &$valt, string $azon): void
{
    global $J;
    if ($uj === null || $uj === '' || (string)$db['megjegyzes'] === $uj) {
        return;
    }
    $e = erintett($auditTipus, (int)$db['id'], 'megjegyzes');
    if ($e !== null) {
        $J['megj_marad'][] = "$azon: a megjegyzést azóta {$e['ki']} módosította – a DOS-adatok változását nem írtam bele";
        return;
    }
    $set[] = 'megjegyzes = ?';
    $p[] = $uj;
    $valt[] = ['m' => AUDIT_CIMKEK['megjegyzes'], 'r' => audit_ertek('megjegyzes', $db['megjegyzes']), 'u' => audit_ertek('megjegyzes', $uj)];
    $J['megj_frissitve']++;
}

/** A köztes rekord 10-07-i állapota: ['statusz', 'fizetve' (ÉÉÉÉ-HH-NN|null), 'reszt' => [összeg, dátum, azonosító]|null] */
function dos_allapot(array $t): array
{
    $r = $t['reszt'] ?? null;
    return ['statusz' => (string)$t['statusz'], 'fizetve' => mig_datum($t['fizetve_datum'] ?? null),
            'reszt' => $r ? [mig_osszeg((string)$r['osszeg']), mig_datum($r['datum'] ?? null), (string)($r['azonosito'] ?? 'DOS import')] : null];
}

/** Részteljesítés rögzítése (a 10-07-i import formájában) */
function reszt_ir(string $irany, int $szamlaId, ?int $szuloId, array $reszt, string $kod, string $penznem): void
{
    global $AUDIT, $NAP;
    db_exec('INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito) VALUES (?, ?, ?, ?, ?)', [$irany, $szamlaId, $reszt[0], $reszt[1], $reszt[2]]);
    $AUDIT[] = [$irany, $szamlaId, $szuloId, 'RESZT', "DOS import ($NAP): részteljesítés " . mig_osszeg_hu($reszt[0]) . " $penznem ({$reszt[2]})", []];
    uj('reszt', "$kod: " . mig_osszeg_hu($reszt[0]) . " $penznem ({$reszt[2]})");
}

/**
 * A számla DOS-ból jött részteljesítése a DOS mostani állapota szerint: $cel = [összeg, dátum, azonosító] vagy null (a DOS-ban
 * nincs részteljesítés: teljesen kifizetve, vagy nincs átutalás). Csak a DOS-importból jött sorokhoz nyúl (letrehozta üres,
 * „DOS …” azonosító); ha egy munkatárs is rögzített részteljesítést, semmit nem változtat, csak jelenti.
 */
function dos_reszt_igazit(string $irany, array $sz, ?array $cel, string $tipusNev, string $azon): void
{
    global $RESZT_SOR, $AUDIT, $NAP, $RESZT;
    $sorok = $RESZT_SOR["$irany|{$sz['id']}"] ?? [];
    $dos = array_values(array_filter($sorok, fn($r) => $r['letrehozta'] === null && str_starts_with((string)$r['banki_azonosito'], 'DOS ')));
    $szulo = $irany === 'BEJOVO' ? (int)$sz['kotes_id'] : null;
    if (count($dos) !== count($sorok)) {
        $osszes = array_sum(array_map(fn($r) => (float)$r['osszeg'], $sorok));
        if ($cel === null || abs($osszes - (float)$cel[0]) >= 0.005) {
            jel('figyelem', "$tipusNev $azon: a BaninaPRO-ban kézzel rögzített részteljesítés is van (" . mig_osszeg_hu((string)$osszes) . '), a DOS szerint '
                . ($cel ? mig_osszeg_hu($cel[0]) : 'nincs részteljesítés') . ' – marad');
        }
        return;
    }
    if ($cel === null) {
        foreach ($dos as $r) {
            db_exec('DELETE FROM reszteljesitesek WHERE id = ?', [$r['id']]);
            $AUDIT[] = [$irany, (int)$sz['id'], $szulo, 'RESZT', "DOS import ($NAP): a DOS-ból jött részteljesítés (" . mig_osszeg_hu((string)$r['osszeg']) . ", {$r['banki_azonosito']}) törölve – a DOS-ban már "
                . ($sz['_dos_fizetve'] ?? false ? 'teljesen kifizetve' : 'nincs részteljesítés'), []];
            jel('javitva', ['tipus' => $tipusNev, 'kod' => $sz['kod'], 'dos' => $azon, 'mezo' => 'részteljesítés', 'regi' => $r['osszeg'] . " ({$r['banki_azonosito']})", 'uj' => '–']);
        }
        unset($RESZT["$irany|{$sz['id']}"], $RESZT_SOR["$irany|{$sz['id']}"]);
        return;
    }
    if (count($dos) === 1 && abs((float)$dos[0]['osszeg'] - (float)$cel[0]) < 0.005 && $dos[0]['datum'] === $cel[1] && $dos[0]['banki_azonosito'] === $cel[2]) {
        return;   // egyezik
    }
    foreach ($dos as $r) {
        db_exec('DELETE FROM reszteljesitesek WHERE id = ?', [$r['id']]);
    }
    db_exec('INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito) VALUES (?, ?, ?, ?, ?)', [$irany, $sz['id'], $cel[0], $cel[1], $cel[2]]);
    if ($dos) {
        $regi = implode(', ', array_map(fn($r) => mig_osszeg_hu((string)$r['osszeg']) . " ({$r['datum']}, {$r['banki_azonosito']})", $dos));
        $AUDIT[] = [$irany, (int)$sz['id'], $szulo, 'RESZT', "DOS import ($NAP): részteljesítés a DOS szerint: $regi → " . mig_osszeg_hu($cel[0]) . " ({$cel[1]}, {$cel[2]})", []];
        jel('javitva', ['tipus' => $tipusNev, 'kod' => $sz['kod'], 'dos' => $azon, 'mezo' => 'részteljesítés', 'regi' => $regi, 'uj' => "{$cel[0]} ({$cel[1]}, {$cel[2]})"]);
    } else {
        $AUDIT[] = [$irany, (int)$sz['id'], $szulo, 'RESZT', "DOS import ($NAP): részteljesítés " . mig_osszeg_hu($cel[0]) . " {$sz['penznem']} ({$cel[2]})", []];
        uj('reszt', "{$sz['kod']}: " . mig_osszeg_hu($cel[0]) . " {$sz['penznem']} ({$cel[2]})");
    }
}

/** Hány ütközés / hiba van eddig (a rekordonkénti változás észleléséhez) */
function gond_db(): int
{
    global $J;
    return count($J['utkozes']) + count($J['hiba']);
}

/**
 * Meglévő bejövő számla (devizás tétel vagy forintszámla) fizetése a DOS mostani állapota szerint (új köztes formátum).
 * A DOS a mérvadó a fizetésben; a BaninaPRO-ban azóta történt fizetési lépést (utalásra vár, más utalás) nem írja felül.
 */
function bejovo_fizetes_dos(array $b, array $t, array $fiz, array $st, string $kulcs, string $tipusNev, array &$set, array &$p, array &$valt): ?string
{
    global $RESZT, $NAP;
    $ki = erintett_ki('BEJOVO', (int)$b['id']);
    $azon = "{$b['kod']} ($kulcs)";
    $fizetett = in_array($st['statusz'], ['FIZETVE', 'BESZAMITVA'], true);
    $dbFizetett = in_array($b['statusz'], ['FIZETVE', 'BESZAMITVA'], true);
    $refKulcs = $fiz['mod'] !== 'nincs' ? $fiz['ref']['tipus'] . '|' . trim((string)$fiz['ref']['szam']) : null;
    $dbRef = mig_utalas_ref((string)$b['banki_hivatkozas']);
    $dbRefKulcs = $dbRef ? "{$dbRef['tipus']}|{$dbRef['szam']}" : null;
    $voltReszt = $RESZT["BEJOVO|{$b['id']}"] ?? null;
    if ($fizetett) {
        if ($b['statusz'] === 'FIZETENDO' && $b['utalas_id'] === null) {
            $u = null;
            if ($refKulcs !== null) {
                $u = utalas_dos($fiz['ref'], (int)$b['ceg_id'], $b['penznem']);
                if ($u === null) {
                    return null;
                }
            }
            array_push($set, 'statusz = ?', 'fizetve_datum = ?', 'utalas_id = ?');
            array_push($p, $st['statusz'], $st['fizetve'], $u['id'] ?? null);
            jel('fizetve', "$tipusNev $azon → {$st['statusz']}" . ($u ? ", {$u['uid']} ({$u['banki_hivatkozas']})" : ' (DOS-utalás nélkül)') . ($st['fizetve'] ? ', ' . mig_datum_hu($st['fizetve']) : ', terhelés nélkül'));
            if ($voltReszt !== null) {
                dos_reszt_igazit('BEJOVO', $b + ['_dos_fizetve' => true], null, $tipusNev, $kulcs);
            }
            return "DOS import ($NAP): " . ny_statusz_nev($st['statusz']) . ($u ? " – {$u['banki_hivatkozas']} ({$u['uid']}" . ($st['fizetve'] ? ', ' . mig_datum_hu($st['fizetve']) : ', terhelés nélkül') . ')' : '');
        }
        if ($b['statusz'] === 'UTALASHOZ_ADVA') {
            jel('utkozes', ['tipus' => $tipusNev, 'azon' => $azon, 'mezo' => 'utalás', 'baninapro' => "utalásra vár: {$b['utalas_uid']}", 'dos' => "átutalva: $refKulcs", 'modositotta' => $ki]);
            return null;
        }
        if ($dbFizetett && $dbRefKulcs !== null && $dbRefKulcs !== $refKulcs) {
            jel('utkozes', ['tipus' => $tipusNev, 'azon' => $azon, 'mezo' => 'utalás', 'baninapro' => (string)$b['banki_hivatkozas'], 'dos' => (string)$refKulcs, 'modositotta' => $ki]);
            return null;
        }
        if ($dbFizetett && $dbRefKulcs === null) {
            jel('figyelem', "$tipusNev $azon: a BaninaPRO-ban {$b['statusz']}" . ($b['utalas_uid'] ? " ({$b['utalas_uid']})" : ' (utalás nélkül)') . ", a DOS-ban " . ($refKulcs ?? 'utalás nélkül') . ' – marad');
            return null;
        }
        if ($dbFizetett && (string)$b['fizetve_datum'] !== (string)$st['fizetve']) {
            // a fizetés napja a DOS-ban azóta rögzítve / javítva (pl. a 00.00.00 terhelés helyett)
            $e = erintett('BEJOVO', (int)$b['id'], 'fizetve_datum');
            if ($e === null) {
                $set[] = 'fizetve_datum = ?';
                $p[] = $st['fizetve'];
                $valt[] = ['m' => AUDIT_CIMKEK['fizetve_datum'], 'r' => audit_ertek('fizetve_datum', $b['fizetve_datum']), 'u' => audit_ertek('fizetve_datum', $st['fizetve'])];
                jel('javitva', ['tipus' => $tipusNev, 'kod' => $b['kod'], 'dos' => $kulcs, 'mezo' => 'fizetve_datum', 'regi' => $b['fizetve_datum'], 'uj' => $st['fizetve']]);
            } elseif (!$e['excel']) {
                jel('utkozes', ['tipus' => $tipusNev, 'azon' => $azon, 'mezo' => 'fizetve_datum', 'baninapro' => $b['fizetve_datum'], 'dos' => $st['fizetve'], 'modositotta' => $e['ki']]);
            }
        }
        if ($dbFizetett && $b['statusz'] !== $st['statusz']) {
            jel('figyelem', "$tipusNev $azon: a BaninaPRO-ban {$b['statusz']}, a DOS szerint {$st['statusz']} (az összeg előjele?) – marad");
        }
        if ($dbFizetett && $voltReszt !== null) {
            dos_reszt_igazit('BEJOVO', $b + ['_dos_fizetve' => true], null, $tipusNev, $kulcs);
        }
        return null;
    }
    if ($st['reszt'] !== null) {
        if ($b['statusz'] === 'FIZETENDO' && $b['utalas_id'] === null) {
            dos_reszt_igazit('BEJOVO', $b, $st['reszt'], $tipusNev, $kulcs);
        } elseif ($dbFizetett && $dbRefKulcs !== null) {
            jel('utkozes', ['tipus' => $tipusNev, 'azon' => $azon, 'mezo' => 'utalás', 'baninapro' => "{$b['statusz']} ({$b['banki_hivatkozas']})",
                'dos' => 'a DOS-ban csak részben átutalt: ' . mig_osszeg_hu($st['reszt'][0]), 'modositotta' => $ki]);
        }
        return null;
    }
    if ($dbFizetett && $dbRefKulcs !== null) {
        jel('utkozes', ['tipus' => $tipusNev, 'azon' => $azon, 'mezo' => 'utalás', 'baninapro' => "{$b['statusz']} ({$b['banki_hivatkozas']})",
            'dos' => 'a DOS-ban már nincs átutalva', 'modositotta' => $ki]);
    } elseif ($voltReszt !== null && $b['statusz'] === 'FIZETENDO') {
        dos_reszt_igazit('BEJOVO', $b, null, $tipusNev, $kulcs);
    }
    return null;
}

/**
 * Meglévő kimenő számla befolyása a DOS mostani állapota szerint (új köztes formátum). A DOS-ból jött („DOS …” banki kivonatú)
 * befolyást a DOS szerint igazítja; a BaninaPRO-ban kézzel rögzített banki kivonatot nem írja felül.
 */
function kimeno_fizetes_dos(array $db, array $s, array $st, string $azon, array &$set, array &$p, array &$valt): ?string
{
    global $RESZT, $NAP, $BANK_ID;
    $ki = erintett_ki('KIMENO', (int)$db['id']);
    $a = "{$db['kod']} ($azon)";
    $volt = $RESZT["KIMENO|{$db['id']}"][0] ?? null;
    $kiv = $db['banki_kivonat_id'] !== null ? ($BANK_ID[(int)$db['banki_kivonat_id']] ?? null) : null;
    $dosKiv = $kiv !== null && str_starts_with((string)$kiv['azonosito'], 'DOS ');
    if ($st['statusz'] === 'FIZETVE') {
        if ($db['statusz'] === 'NYITOTT' && $db['banki_kivonat_id'] === null) {
            $bk = $st['fizetve'] !== null ? bank_dos($st['fizetve'], $db['penznem']) : null;
            array_push($set, "statusz = 'FIZETVE'", 'banki_kivonat_id = ?', 'fizetve_datum = ?');
            array_push($p, $bk, $st['fizetve']);
            jel('fizetve', "kimenő $a → FIZETVE" . ($st['fizetve'] ? ' (' . mig_datum_hu($st['fizetve']) . ')' : ' (a DOS-ban befolyási dátum nélkül)'));
            if ($volt !== null) {
                dos_reszt_igazit('KIMENO', $db + ['_dos_fizetve' => true], null, 'kimenő', $azon);
            }
            return "DOS import ($NAP): FIZETVE – befolyt " . mig_osszeg_hu((string)mig_osszeg($s['befolyt'] ?? null)) . " {$db['penznem']} (" . mig_datum_hu($st['fizetve']) . ')';
        }
        if ($db['statusz'] === 'FIZETVE' && (string)$db['fizetve_datum'] !== (string)$st['fizetve']) {
            $e = erintett('KIMENO', (int)$db['id'], 'fizetve_datum');
            if (($kiv === null || $dosKiv) && $e === null) {
                $bk = $st['fizetve'] !== null ? bank_dos($st['fizetve'], $db['penznem']) : null;
                array_push($set, 'banki_kivonat_id = ?', 'fizetve_datum = ?');
                array_push($p, $bk, $st['fizetve']);
                $valt[] = ['m' => AUDIT_CIMKEK['fizetve_datum'], 'r' => audit_ertek('fizetve_datum', $db['fizetve_datum']), 'u' => audit_ertek('fizetve_datum', $st['fizetve'])];
                jel('javitva', ['tipus' => 'kimenő', 'kod' => $db['kod'], 'dos' => $azon, 'mezo' => 'fizetve_datum', 'regi' => $db['fizetve_datum'], 'uj' => $st['fizetve']]);
            } else {
                jel('utkozes', ['tipus' => 'kimenő', 'azon' => $a, 'mezo' => 'befolyás dátuma', 'baninapro' => $db['fizetve_datum'] . ($kiv ? " ({$kiv['azonosito']})" : ''),
                    'dos' => $st['fizetve'], 'modositotta' => $e['ki'] ?? $ki]);
            }
        }
        return null;
    }
    if ($st['reszt'] !== null) {
        if ($db['statusz'] === 'NYITOTT') {
            dos_reszt_igazit('KIMENO', $db, $st['reszt'], 'kimenő', $azon);
        } elseif ($dosKiv) {
            jel('utkozes', ['tipus' => 'kimenő', 'azon' => $a, 'mezo' => 'befolyás', 'baninapro' => "FIZETVE ({$kiv['azonosito']})",
                'dos' => 'a DOS-ban csak részben befolyt: ' . mig_osszeg_hu($st['reszt'][0]), 'modositotta' => $ki]);
        }
        return null;
    }
    if ($db['statusz'] === 'FIZETVE' && $dosKiv) {
        jel('utkozes', ['tipus' => 'kimenő', 'azon' => $a, 'mezo' => 'befolyás', 'baninapro' => "FIZETVE ({$kiv['azonosito']})", 'dos' => 'a DOS-ban már nincs befolyás', 'modositotta' => $ki]);
    } elseif ($volt !== null && $db['statusz'] === 'NYITOTT') {
        dos_reszt_igazit('KIMENO', $db, null, 'kimenő', $azon);
    }
    return null;
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
        if (array_key_exists('utalva', $x)) {
            $utalva = mig_datum($x['utalva']);
        }
        if ($u['utalva_datum'] === null && $utalva !== null) {
            db_exec('UPDATE utalasok SET utalva_datum = ?, megjegyzes = IF(COALESCE(megjegyzes, \'\') = \'\', TRIM(LEADING CHAR(10) FROM ?), CONCAT(megjegyzes, ?)) WHERE id = ?',
                array_merge([$utalva], array_fill(0, 2, "\nDOS import ($NAP): a terhelés napja a DOS-ban azóta rögzítve: " . mig_datum_hu($utalva)), [$u['id']]));
            if (!$UJF) {
                // régi köztes formátum: a számlák fizetési napja itt; az újban a (2) lépés veti össze a DOS-sal (és írja a megjegyzést)
                foreach (db_all("SELECT b.id, b.kod, b.kotes_id FROM bejovo_szamlak b WHERE b.utalas_id = ? AND b.fizetve_datum IS NULL AND b.statusz IN ('FIZETVE', 'BESZAMITVA')", [$u['id']]) as $b) {
                    db_exec('UPDATE bejovo_szamlak SET fizetve_datum = ? WHERE id = ?', [$utalva, $b['id']]);
                    $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'MODOSIT', "DOS import ($NAP): a fizetés napja a DOS-utalás terhelése szerint ({$u['uid']})",
                                [['m' => AUDIT_CIMKEK['fizetve_datum'], 'r' => '–', 'u' => audit_ertek('fizetve_datum', $utalva)]]];
                }
            }
            $UTAL[$kulcs]['utalva_datum'] = $utalva;
            jel('datum_potolva', "{$u['uid']} ({$u['banki_hivatkozas']}): terhelés " . mig_datum_hu($utalva));
        } elseif ($UJF && $u['utalva_datum'] !== null && $utalva !== $u['utalva_datum']) {
            // a DOS-ban azóta más a terhelés napja (vagy törölték): a DOS-utalást a BaninaPRO-ban senki nem kezeli (lakatos, UTALVA), a DOS a mérvadó
            if ($u['utalta'] === null && $u['letrehozta'] === null) {
                db_exec('UPDATE utalasok SET utalva_datum = ?, megjegyzes = CONCAT(COALESCE(megjegyzes, \'\'), ?) WHERE id = ?',
                    [$utalva, "\nDOS import ($NAP): a terhelés napja a DOS-ban azóta " . ($utalva ? mig_datum_hu($utalva) : 'törölve (00.00.00)') . ' (korábban ' . mig_datum_hu($u['utalva_datum']) . ')', $u['id']]);
                jel('datum_potolva', "{$u['uid']} ({$u['banki_hivatkozas']}): terhelés " . mig_datum_hu($u['utalva_datum']) . ' → ' . mig_datum_hu($utalva));
                $UTAL[$kulcs]['utalva_datum'] = $utalva;
            } else {
                jel('utkozes', ['tipus' => 'utalás', 'azon' => "{$u['uid']} ({$u['banki_hivatkozas']})", 'mezo' => 'utalva_datum', 'baninapro' => $u['utalva_datum'], 'dos' => $utalva, 'modositotta' => 'a BaninaPRO-ban kezelték']);
            }
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
            if ($UJF) {
                // új köztes formátum: a fizetés a DOS mostani állapota szerint, a megjegyzés a 10-07-i import formájában
                $st = dos_allapot($t);
                $g0 = gond_db();
                $r0 = count($J['uj']['reszt']) + count($J['javitva']);
                $statuszLeiras = bejovo_fizetes_dos($b, $t, $fiz, $st, $kulcs, 'bejövő', $set, $p, $valt);
                if (gond_db() === $g0) {
                    megj_frissit('BEJOVO', $b, $t['megjegyzes'] ?? null, $set, $p, $valt, "{$b['kod']} ($kulcs)");
                }
                if ($set) {
                    $p[] = $b['id'];
                    db_exec('UPDATE bejovo_szamlak SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
                    if ($valt) {
                        $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'MODOSIT', "DOS import ($NAP): javítva a DOS szerint", $valt];
                    }
                    if ($statuszLeiras) {
                        $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'STATUSZ', $statuszLeiras, [['m' => 'Státusz', 'r' => ny_statusz_nev($b['statusz']), 'u' => ny_statusz_nev($st['statusz'])]]];
                    }
                } elseif (count($J['uj']['reszt']) + count($J['javitva']) === $r0) {
                    $J['egyezik']++;
                }
                continue;
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
        if (!empty($t['elozo_kulcs']) && isset($TETEL[$t['elozo_kulcs']])) {
            // a DOS-ban azóta átnevezett tétel (más kötésszám / kisszám): nem jön létre másodszor – a kötés-áthelyezés döntést kér
            $latott['tetel'][$t['elozo_kulcs']] = true;
            jel('utkozes', ['tipus' => 'bejövő', 'azon' => "{$TETEL[$t['elozo_kulcs']]['kod']} ({$t['elozo_kulcs']})", 'mezo' => 'DOS-kulcs',
                'baninapro' => $t['elozo_kulcs'], 'dos' => "$kulcs – a DOS-ban átnevezve (nem jött létre másodszor)", 'modositotta' => null]);
            continue;
        }
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
        $statusz = 'FIZETENDO';
        $fd = null;
        $uid = null;
        $x = null;
        $st = $UJF ? dos_allapot($t) : null;
        if ($fiz['mod'] !== 'nincs') {
            $x = $IRU[$fiz['ref']['tipus'] . '|' . trim((string)$fiz['ref']['szam'])] ?? null;
        }
        if ($UJF) {
            // a 10-07-i import állapota; a fizető DOS-utalás nélkül nem jön létre (a DOS-ban fizetett számla nem lehet nálunk nyitott)
            [$statusz, $fd] = [$st['statusz'], $st['fizetve']];
            if (in_array($statusz, ['FIZETVE', 'BESZAMITVA'], true) && $fiz['mod'] !== 'nincs') {
                $u = utalas_dos($fiz['ref'], $cegId, $penznem);
                if ($u === null) {
                    jel('hiba', "tétel $kulcs: a DOS-ban fizetett, de a DOS-utalása nem kapcsolható – nem jött létre");
                    continue;
                }
                $uid = (int)$u['id'];
            }
        } elseif ($fiz['mod'] === 'teljes') {
            $u = utalas_dos($fiz['ref'], $cegId, $penznem);
            if ($u) {
                [$statusz, $fd, $uid] = bejovo_fizetve($u, $osszeg);
            }
        }
        $kk = trim((string)$t['kotesszam']) . "|$cegId|$penznem";
        if (!isset($KOTES[$kk])) {
            $ev = isset($t['kotes_ev']) ? (int)$t['kotes_ev'] : mig_kotes_ev(trim((string)$t['kotesszam']), $kelt);
            $n = kovetkezo_sorszam('KOTES', $ev, $penznem);
            $kod = kotes_kod($ev, $penznem, $n);
            $megnev = array_key_exists('kotes_megnevezes', $t) ? (string)$t['kotes_megnevezes']
                : (trim((string)($t['aru'] ?? '')) !== '' ? trim((string)$t['aru']) : ($irKotesAru[trim((string)$t['kotesszam'])] ?? null));
            db_exec('INSERT INTO kotesek (ceg_id, ev, penznem, sorszam, kod, megnevezes, megjegyzes, archiv, regi_kod) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
                [$cegId, $ev, $penznem, $n, $kod, $megnev !== '' ? mb_substr((string)$megnev, 0, 191) : null, "DOS import ($NAP)", trim((string)$t['kotesszam'])]);
            $KOTES[$kk] = ['id' => (int)db()->lastInsertId(), 'kod' => $kod, 'ceg_id' => $cegId, 'penznem' => $penznem, 'regi_kod' => trim((string)$t['kotesszam'])];
            $AUDIT[] = ['KOTES', $KOTES[$kk]['id'], null, 'IMPORT', "DOS importból létrehozva: $kod – régi (DOS) kötésszám: " . trim((string)$t['kotesszam']), []];
            uj('kotes', "$kod ← " . trim((string)$t['kotesszam']) . " ({$CEG[$cegId]['nev']})");
        }
        $kot = $KOTES[$kk];
        $ujMegj = $UJF && isset($t['megjegyzes']) ? (string)$t['megjegyzes'] : tetel_megj($t, $fiz, $x, $penznem, $dm, $tobb);
        $ksz = kovetkezo_k_sorszam((int)$kot['id']);
        $kod = szamla_kod($kot['kod'], $ksz);
        if ($ibSzamla !== null) {
            // áthelyezés az IB-archív kötésből a DOS-kötésbe (mint az app „archív számlák áthelyezése”): új azonosító, az IB-adatok
            // (számlaszám, összeg, dátumok) maradnak, a DOS-ból a kisszám, a BESZÁM (ha üres), a fizetés és a DOS-adatok jönnek
            $szsz = egyedi_szamlaszam((int)$kot['id'], $ibSzamla['szamlaszam'], trim((string)$t['kisszam']));
            db_exec('UPDATE bejovo_szamlak SET kotes_id = ?, k_sorszam = ?, kod = ?, szamlaszam = ?, szamlaszam_norm = ?, regi_k = ?, beszam = COALESCE(beszam, ?),
                            statusz = ?, fizetve_datum = ?, utalas_id = ?, megjegyzes = IF(COALESCE(megjegyzes, \'\') = \'\', TRIM(LEADING CHAR(10) FROM ?), CONCAT(megjegyzes, ?)) WHERE id = ?',
                array_merge([$kot['id'], $ksz, $kod, $szsz, norm_azonosito($szsz), trim((string)$t['kisszam']), $adat['beszam'], $statusz, $fd, $uid],
                    array_fill(0, 2, "\n" . $ujMegj), [$ibSzamla['id']]));
            $bid = (int)$ibSzamla['id'];
            $AUDIT[] = ['BEJOVO', $bid, (int)$kot['id'], 'ATHELYEZ', "DOS import ($NAP): kötésbe helyezve az IB-archív kötésből: {$ibSzamla['kotes_kod']} → {$kot['kod']} (DOS: $kulcs)",
                        [['m' => 'Kötés', 'r' => $ibSzamla['kotes_kod'], 'u' => $kot['kod']], ['m' => 'Azonosító', 'r' => $ibSzamla['kod'], 'u' => $kod]]
                        + ($statusz !== 'FIZETENDO' ? [2 => ['m' => 'Státusz', 'r' => ny_statusz_nev('FIZETENDO'), 'u' => ny_statusz_nev($statusz)]] : [])];
            $AUDIT[] = ['KOTES', (int)$ibSzamla['kotes_id'], null, 'ATHELYEZ', "DOS import ($NAP): számla áthelyezve innen a(z) {$kot['kod']} kötésbe: {$ibSzamla['kod']} „{$ibSzamla['szamlaszam']}” → $kod", []];
            foreach ($IB_ARCHIV["$cegId|$penznem|" . mig_laza_kulcs($adat['szamlaszam'])] as &$x2) {
                if ((int)$x2['id'] === $bid) { $x2['_athelyezve'] = true; }
            }
            unset($x2);
            if ($UJF && $st['reszt'] !== null) {
                reszt_ir('BEJOVO', $bid, (int)$kot['id'], $st['reszt'], $kod, $penznem);
            } elseif (!$UJF && $fiz['mod'] === 'resz') {
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
             $ujMegj]);
        $bid = (int)db()->lastInsertId();
        if ($UJF) {
            if ($st['reszt'] !== null) {
                reszt_ir('BEJOVO', $bid, (int)$kot['id'], $st['reszt'], $kod, $penznem);
            }
        } elseif ($fiz['mod'] === 'resz') {
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
            if ($UJF) {
                $st = dos_allapot($t);
                $fiz = $ref ? ['mod' => 'teljes', 'ref' => $ref, 'osszeg' => $osszeg] : ['mod' => 'nincs'];
                $g0 = gond_db();
                $r0 = count($J['uj']['reszt']) + count($J['javitva']);
                $statuszLeiras = bejovo_fizetes_dos($b, $t, $fiz, $st, $azon, 'forintszámla', $set, $p, $valt);
                if (gond_db() === $g0) {
                    megj_frissit('BEJOVO', $b, $t['megjegyzes'] ?? null, $set, $p, $valt, "{$b['kod']} ($azon)");
                }
                if ($set) {
                    $p[] = $b['id'];
                    db_exec('UPDATE bejovo_szamlak SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
                    if ($valt) { $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'MODOSIT', "DOS import ($NAP): javítva a DOS szerint", $valt]; }
                    if ($statuszLeiras) {
                        $AUDIT[] = ['BEJOVO', (int)$b['id'], (int)$b['kotes_id'], 'STATUSZ', $statuszLeiras, [['m' => 'Státusz', 'r' => ny_statusz_nev($b['statusz']), 'u' => ny_statusz_nev($st['statusz'])]]];
                    }
                } elseif (count($J['uj']['reszt']) + count($J['javitva']) === $r0) {
                    $J['egyezik']++;
                }
                continue;
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
        if (!empty($t['elozo_kulcs']) && isset($FORINT[$t['elozo_kulcs']])) {
            $latott['forint'][$t['elozo_kulcs']] = true;
            jel('utkozes', ['tipus' => 'forintszámla', 'azon' => "{$FORINT[$t['elozo_kulcs']]['kod']} ({$t['elozo_kulcs']})", 'mezo' => 'DOS-kulcs',
                'baninapro' => $t['elozo_kulcs'], 'dos' => "$azon – a DOS-ban átnevezve (nem jött létre másodszor)", 'modositotta' => null]);
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
        $st = $UJF ? dos_allapot($t) : null;
        if ($UJF) {
            [$statusz, $fd] = [$st['statusz'], $st['fizetve']];
            if (in_array($statusz, ['FIZETVE', 'BESZAMITVA'], true) && $ref) {
                $u = utalas_dos($ref, $cegId, 'HUF');
                if ($u === null) {
                    jel('hiba', "forintszámla $azon: a DOS-ban fizetett, de a DOS-utalása nem kapcsolható – nem jött létre");
                    continue;
                }
                $uid = (int)$u['id'];
            }
        } elseif ($ref) {
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
            [$kot['id'], $ksz, $kod, $szsz, norm_azonosito($szsz), $telj, $kelt, $osszeg, $azon, $hat, $statusz, $fd, $uid,
             $UJF && isset($t['megjegyzes']) ? (string)$t['megjegyzes'] : implode(' · ', $megj)]);
        $bid = (int)db()->lastInsertId();
        if ($UJF && $st['reszt'] !== null) {
            reszt_ir('BEJOVO', $bid, (int)$kot['id'], $st['reszt'], $kod, 'HUF');
        }
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
        if ($UJF && isset($s['szamlaszam'])) {
            // a 10-07-i import számlaszáma (sorozatbetű-szám/a kelt éve), a köztes fájlban kiszámolva
            $szamlaszam = (string)$s['szamlaszam'];
            $ev = (int)substr($kelt, 0, 4);
        } else {
            $ksz = mig_kimeno_szamlaszam($azon, $kelt);
            if ($ksz === null && !isset($KIM[$azon])) {
                jel('hiba', "kimenő „{$azon}”: a DOS-azonosítóból nem képezhető számlaszám (várt alak: „KE-26 1126”)");
                continue;
            }
            $ev = $ksz['ev'] ?? (int)substr($kelt, 0, 4);
            $szamlaszam = $ksz['szamlaszam'] ?? '';
        }
        $befolyt = mig_osszeg($s['befolyt'] ?? null);
        $befDatum = mig_datum($s['befolyt_datum'] ?? null);
        if ($befolyt !== null && abs((float)$befolyt) < 0.005) { $befolyt = null; }
        $adat = ['kelt' => $kelt, 'teljesites_datum' => $telj, 'fizetesi_hatarido' => $hat, 'osszeg' => $osszeg];
        $db = $KIM[$azon] ?? null;
        $atnevezve = null;
        if ($db === null && !empty($s['elozo_kulcs']) && isset($KIM[preg_replace('/\s+/', ' ', trim((string)$s['elozo_kulcs']))])) {
            // a DOS-ban azóta átnevezett rekord (ugyanaz a DOS-rekord, ugyanaz a vevő, összeg, kelt): a régi kulcson megvan
            $atnevezve = preg_replace('/\s+/', ' ', trim((string)$s['elozo_kulcs']));
            $db = $KIM[$atnevezve];
            $latott['kimeno'][$atnevezve] = true;
            $adat['szamlaszam'] = $szamlaszam;     // a DOS-ban javított azonosító → javított számlaszám (ha az IB nem javította már)
        }
        if ($db === null && isset($KIMN[norm_azonosito($szamlaszam)])) {
            // azonos számlaszám DOS-azonosító nélkül: az IB System importjából jött ugyanez a számla – a DOS ehhez adja a befolyást.
            // Egy MÁSIK DOS-rekord azonos számlaszáma viszont elírás a DOS-ban (új köztes formátumban külön számla lesz, toldalékkal).
            $jelolt = $KIMN[norm_azonosito($szamlaszam)];
            if (!$UJF || !isset($jelolt['_dos']['DOS azonosító'])) {
                $db = $jelolt;
            }
        }
        if ($db !== null && $UJF) {
            $ki = erintett_ki('KIMENO', (int)$db['id']);
            $javit = mezok_osszevet('kimenő', $db, $adat, 'KIMENO', "{$db['kod']} ($azon)");
            $set = [];
            $p = [];
            $valt = [];
            foreach ($javit as $mm => $v) {
                if ($mm === 'szamlaszam') {
                    $foglalt = $KIMN[norm_azonosito((string)$v)] ?? null;
                    if ($foglalt !== null && (int)$foglalt['id'] !== (int)$db['id']) {
                        jel('utkozes', ['tipus' => 'kimenő', 'azon' => "{$db['kod']} ($azon)", 'mezo' => 'számlaszám', 'baninapro' => $db['szamlaszam'],
                            'dos' => "$v – foglalt: {$foglalt['kod']}", 'modositotta' => null]);
                        continue;
                    }
                    $set[] = 'szamlaszam_norm = ?';
                    $p[] = norm_azonosito((string)$v);
                    unset($KIMN[norm_azonosito((string)$db['szamlaszam'])]);
                    $KIMN[norm_azonosito((string)$v)] = $db;
                }
                $set[] = "$mm = ?";
                $p[] = $v;
                $valt[] = ['m' => AUDIT_CIMKEK[$mm] ?? $mm, 'r' => audit_ertek($mm, $db[$mm], $db['penznem']), 'u' => audit_ertek($mm, $v, $db['penznem'])];
                jel('javitva', ['tipus' => 'kimenő', 'kod' => $db['kod'], 'dos' => $azon, 'mezo' => $mm, 'regi' => $db[$mm], 'uj' => $v]);
            }
            if ($atnevezve !== null) {
                jel('figyelem', "kimenő {$db['kod']}: a DOS-ban azóta átnevezve: „{$atnevezve}” → „{$azon}”");
            }
            $st = dos_allapot($s);
            $g0 = gond_db();
            $r0 = count($J['uj']['reszt']) + count($J['javitva']);
            $statuszLeiras = kimeno_fizetes_dos($db, $s, $st, $azon, $set, $p, $valt);
            if (gond_db() === $g0 && isset($db['_dos']['DOS azonosító'])) {
                megj_frissit('KIMENO', $db, $s['megjegyzes'] ?? null, $set, $p, $valt, "{$db['kod']} ($azon)");
            }
            if ($set) {
                $p[] = $db['id'];
                db_exec('UPDATE kimeno_szamlak SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
                if ($valt) { $AUDIT[] = ['KIMENO', (int)$db['id'], null, 'MODOSIT', "DOS import ($NAP): javítva a DOS szerint", $valt]; }
                if ($statuszLeiras) { $AUDIT[] = ['KIMENO', (int)$db['id'], null, 'STATUSZ', $statuszLeiras, [['m' => 'Státusz', 'r' => 'NYITOTT', 'u' => 'FIZETVE']]]; }
            } elseif (count($J['uj']['reszt']) + count($J['javitva']) === $r0) {
                $J['egyezik']++;
            }
            if (!isset($db['_dos']['DOS azonosító'])) {
                jel('figyelem', "kimenő {$db['kod']} „{$db['szamlaszam']}”: az IB System importjából jött, a DOS-ban $azon – a DOS-ból a befolyás (" . $st['statusz'] . ') került hozzá');
            }
            continue;
        }
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
        $cegId = $UJF && isset($s['vevo_tabla']) ? ceg_dos((string)$s['vevo_tabla'], (string)$s['vevo_kod']) : ceg_dos('UGYF', (string)($s['vevo'] ?? ''));
        if ($cegId === null) {
            jel('hiba', "kimenő $azon: ismeretlen vevő ({$s['vevo']})");
            continue;
        }
        if ($UJF) {
            // a 10-07-i import állapota, megjegyzése; ismétlődő számlaszámnál (a DOS-ban elírt szám) a cég nevével megkülönböztetve
            $st = dos_allapot($s);
            $fd = $st['statusz'] === 'FIZETVE' ? $st['fizetve'] : null;
            $bk = $fd !== null ? bank_dos($fd, $penznem) : null;
            if (isset($KIMN[norm_azonosito($szamlaszam)])) {
                $foglalt = $KIMN[norm_azonosito($szamlaszam)]['kod'];
                $tag = trim(mb_substr(preg_replace('/[^\p{L}\p{N}_ ]+/u', '', (string)$CEG[$cegId]['nev']) ?? '', 0, 14));
                $sz2 = "$szamlaszam [$tag]";
                for ($j = 2; isset($KIMN[norm_azonosito($sz2)]); $j++) {
                    $sz2 = "$szamlaszam [$tag] $j";
                }
                jel('figyelem', "kimenő $azon: a számlaszám ($szamlaszam) már egy másik kimenő számláé ($foglalt) – „{$sz2}” néven jött létre, mint a 10-07-i importban (a DOS-ban elírt szám?)");
                $szamlaszam = $sz2;
            }
            $n = kovetkezo_sorszam('KIMENO', $ev, $penznem);
            $kod = kimeno_kod($ev, $penznem, $n);
            db_exec('INSERT INTO kimeno_szamlak (ceg_id, ev, penznem, sorszam, kod, szamlaszam, szamlaszam_norm, teljesites_datum, kelt, fizetesi_hatarido, osszeg, statusz,
                                                 banki_kivonat_id, fizetve_datum, megjegyzes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$cegId, $ev, $penznem, $n, $kod, $szamlaszam, norm_azonosito($szamlaszam), $telj, $kelt, $hat, $osszeg, $st['statusz'], $bk, $fd, (string)$s['megjegyzes']]);
            $sid = (int)db()->lastInsertId();
            $KIMN[norm_azonosito($szamlaszam)] = ['id' => $sid, 'kod' => $kod, '_dos' => ['DOS azonosító' => $azon]];
            if ($st['reszt'] !== null) {
                reszt_ir('KIMENO', $sid, null, $st['reszt'], $kod, $penznem);
            }
            $AUDIT[] = ['KIMENO', $sid, null, 'IMPORT', "DOS importból létrehozva: $kod „{$szamlaszam}” ($osszeg $penznem)", []];
            uj('kimeno', "$kod „{$szamlaszam}” $osszeg $penznem · {$st['statusz']} ({$CEG[$cegId]['nev']})");
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

    // (6) az új DOS-utalások sorszáma a 10-07-i import rendje szerint (dátum, pénznem, forrás, azonosító), nem a létrehozás
    // sorrendjében: a most kiosztott sorszámokat pénznemenként és évenként a köztes fájl sorrendjében osztjuk újra
    $csop = [];
    foreach ($UTAL as $u) {
        if (!empty($u['_uj']) && $u['_sorrend'] !== null) {
            $csop["{$u['penznem']}|{$u['ev']}"][] = $u;
        }
    }
    $atszam = [];
    foreach ($csop as $lista) {
        $szamok = array_map('intval', array_column($lista, 'sorszam'));
        sort($szamok);
        usort($lista, fn($a, $b) => $a['_sorrend'] <=> $b['_sorrend']);
        foreach ($lista as $i => $u) {
            if ((int)$u['sorszam'] !== $szamok[$i]) {
                $atszam[(int)$u['id']] = [$u, $szamok[$i]];
            }
        }
    }
    if ($atszam) {
        foreach (array_keys($atszam) as $id) {
            db_exec('UPDATE utalasok SET sorszam = sorszam + 1000000, uid = ? WHERE id = ?', ['TMP-' . $id, $id]);
        }
        $csere = [];
        foreach ($atszam as $id => [$u, $n]) {
            $ujUid = utalas_uid($u['penznem'], (int)$u['ev'], $n);
            db_exec('UPDATE utalasok SET sorszam = ?, uid = ? WHERE id = ?', [$n, $ujUid, $id]);
            $csere[$u['uid']] = $ujUid;
        }
        // a napló és a jelentés szövegeiben is (strtr: egy lépésben, a cserék nem láncolódnak)
        $AUDIT = array_map(fn($a) => [$a[0], $a[1], $a[2], $a[3], strtr((string)$a[4], $csere), $a[5]], $AUDIT);
        array_walk_recursive($J, function (&$v) use ($csere) {
            if (is_string($v)) {
                $v = strtr($v, $csere);
            }
        });
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
$sor[] = sprintf('DOS-MEGJEGYZÉS: %d frissítve a DOS mostani állapotára, %d maradt (azóta a BaninaPRO-ban módosították)', $J['megj_frissitve'], $db('megj_marad'));
$sor[] = sprintf('DÖNTÉSRE: %d ütközés (a BaninaPRO-ban azóta módosítva) · %d a DOS-ból eltűnt · %d figyelmeztetés · %d HIBA (kihagyva)',
    $db('utkozes'), $db('hianyzik'), $db('figyelem'), $db('hiba'));
foreach (['hiba' => 'HIBA', 'utkozes' => 'ÜTKÖZÉS', 'hianyzik' => 'A DOS-BÓL ELTŰNT', 'figyelem' => 'FIGYELEM', 'megj_marad' => 'MEGJEGYZÉS MARADT'] as $kk => $cim) {
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
    naplo('DOS_IMPORT', strip_tags($sor[1] . ' · ' . $sor[2] . ' · ' . $sor[3] . ' · ' . $sor[4]), 'OK', $FELH);
}
echo $szoveg;
