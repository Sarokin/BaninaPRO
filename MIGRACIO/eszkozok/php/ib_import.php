<?php
/**
 * IB System „Számla napló” xlsx importja parancssorból – az app SAJÁT importkódjával (import_elemez →
 * import_sorok_allapot → import_vegrehajt), ugyanazzal az alapkijelöléssel, mint az Import oldal (app.js
 * importAlapKijelolt): új sorok, egyértelmű összevonások, és – Import mérvadónál – a javítható eltérések, KIVÉVE
 * az utalásban lévő számlákat (vedett) és a döntést váró sorokat (több összevonási jelölt).
 *
 *   kornyezet.sh php <db> /migracio/eszkozok/php/ib_import.php <xlsx> [kapcsolók]
 *     --szaraz                 csak elemzés és jelentés, az adatbázis nem változik
 *     --mervado=import|db      (alap: import)
 *     --javit=osszeg,teljesites_datum,fizetesi_hatarido,kelt,szamlaszam   (alap: mind)
 *     --felulir=nincs|mind|<sorindexek vesszővel>   utalásban lévő számlák javítása (alap: nincs)
 *     --osszevon=<json fájl>   {sorindex: számla id} a döntést váró sorokhoz
 *     --csoportok=<json fájl>  cégegyeztetés: {"Excel-beli cégnév": {"tipus":"db","ceg_id":123} | {"tipus":"uj"}}
 *     --ceg-frissit=1|0        (alap: 1)
 *     --utkozes=enged|kihagy|toldalek   (alap: enged)
 *     --felhasznalo="IB System import (migráció)"
 *     --jelentes=<mappa>       (alap: /migracio/4_EREDMENY/<db>/ib_<fájlnév>)
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
require_once '/var/www/html/includes/jogok.php';
require_once '/var/www/html/includes/ids.php';
require_once '/var/www/html/includes/audit.php';
require_once '/var/www/html/includes/import.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

$args = array_slice($argv, 1);
$fajl = null;
$k = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $k[$m[1]] = $m[2] ?? '1';
    } else {
        $fajl = $a;
    }
}
if ($fajl === null || !is_file($fajl)) {
    fwrite(STDERR, "Használat: ib_import.php <xlsx> [--szaraz] [--mervado=import|db] [--felulir=nincs|mind|1,2] [--osszevon=fajl.json] …\n");
    exit(1);
}
$szaraz = isset($k['szaraz']);
$mervado = ($k['mervado'] ?? 'import') === 'db' ? 'db' : 'import';
$javit = isset($k['javit']) ? array_values(array_intersect(array_keys(IMP_JAVITHATO), explode(',', $k['javit']))) : array_keys(IMP_JAVITHATO);
$utkozes = in_array($k['utkozes'] ?? 'enged', ['enged', 'kihagy', 'toldalek'], true) ? ($k['utkozes'] ?? 'enged') : 'enged';
$cegFrissit = ($k['ceg-frissit'] ?? '1') !== '0';
$felhNev = $k['felhasznalo'] ?? 'IB System import (migráció)';
$osszevon = [];
if (isset($k['osszevon'])) {
    $osszevon = json_decode((string)file_get_contents($k['osszevon']), true) ?: [];
}
$nev = basename($fajl);
$jelentesDir = rtrim($k['jelentes'] ?? ('/migracio/4_EREDMENY/' . DB_NAME . '/ib_' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($nev, PATHINFO_FILENAME))), '/');
@mkdir($jelentesDir, 0775, true);

// a migráció „felhasználója”: nincs valódi fiókja (id NULL), Admin jogú – a naplóban ezen a néven látszik
$u = ['id' => null, 'felhasznalonev' => $felhNev, 'nev' => $felhNev, 'szerep' => 'admin'];
$_SESSION = ['felhasznalonev' => $felhNev];

$t0 = microtime(true);
$el = import_elemez($fajl, mb_substr($nev, 0, 120, 'UTF-8'));
import_parositas_ment($el['parositas_biztos']);
// --csoportok=<json>: a cégegyeztetés kézi döntései, mint az Import oldalon: {"Excel-beli név": {"tipus":"db","ceg_id":123} | {"tipus":"uj"}}
if (isset($k['csoportok'])) {
    $dontesek = json_decode((string)file_get_contents($k['csoportok']), true) ?: [];
    $cs = $el['csoportok'];
    foreach ($cs as &$c) {
        foreach ($c['nevek'] as $v) {
            if (isset($dontesek[$v])) {
                $c['cel'] = $dontesek[$v];
                $c['bizonytalan'] = false;
                break;
            }
        }
    }
    unset($c);
    $el['csoportok'] = import_csoportok_ellenoriz($cs, $el);
    printf("cégegyeztetés: %d kézi döntés alkalmazva\n", count($dontesek));
}
$teljes = import_sorok_allapot($el);

// --felulir
$felulir = [];
$fk = $k['felulir'] ?? 'nincs';
if ($fk === 'mind') {
    foreach ($teljes['sorok'] as $s) {
        if (!empty($s['vedett'])) {
            $felulir[] = $s['i'];
        }
    }
} elseif ($fk !== 'nincs' && $fk !== '') {
    $felulir = array_map('intval', explode(',', $fk));
}
$felulirSet = array_fill_keys($felulir, true);

// az Import oldal alapkijelölése (app.js: importAlapKijelolt / importSorValaszthato / importJavitando)
$valasztottJelolt = static function (array $s) use ($osszevon): ?array {
    $id = isset($osszevon[$s['i']]) ? (int)$osszevon[$s['i']] : 0;
    if (!$id || !$s['osszevon_jeloltek']) {
        return null;
    }
    foreach ($s['osszevon_jeloltek'] as $j) {
        if ((int)$j['id'] === $id) {
            return $j;
        }
    }
    return null;
};
$javitando = static fn(array $s) => $s['allapot'] === 'elter' && (bool)array_filter($s['elteresek'], fn($e) => $e['akadaly'] === null && in_array($e['mezo'], $javit, true));
$valaszthato = static function (array $s) use ($mervado, $felulirSet, $utkozes, $valasztottJelolt, $javitando): bool {
    if ($s['allapot'] === 'uj') {
        $j = $valasztottJelolt($s);
        return $j ? $mervado === 'import' && (empty($j['utalas_id']) || isset($felulirSet[$s['i']])) : true;
    }
    if ($s['allapot'] === 'utkozik') {
        return $utkozes === 'enged' || ($utkozes === 'toldalek' && !empty($s['toldalek_szabad']));
    }
    return $mervado === 'import' && $javitando($s) && (empty($s['vedett']) || isset($felulirSet[$s['i']]));
};
$kivalasztott = [];
$dontesre = [];
foreach ($teljes['sorok'] as $s) {
    $dontes = $s['allapot'] === 'uj' && $s['osszevon_jeloltek'] && !$valasztottJelolt($s);
    if ($dontes) {
        $dontesre[] = $s;
    }
    if ($valaszthato($s) && !$dontes) {
        $kivalasztott[] = $s['i'];
    }
}

// ---- elemzés-jelentés --------------------------------------------------------------
$o = $teljes['osszesites'];
$sorRov = static fn(array $s) => ['i' => $s['i'], 'sor' => $s['sor'], 'tipus' => $s['tipus'], 'szamlaszam' => $s['szamlaszam'], 'ceg' => $s['ceg_nev'],
    'kelt' => $s['kelt'], 'osszeg' => $s['osszeg'], 'allapot' => $s['allapot'], 'uzenet' => $s['uzenet'] ?? '', 'hibak' => $s['hibak'] ?? [],
    'figyelmeztetes' => $s['figyelmeztetes'] ?? [], 'letezo' => isset($s['letezo']) ? ['kod' => $s['letezo']['kod'] ?? null, 'szamlaszam' => $s['letezo']['szamlaszam'] ?? null] : null,
    'elteresek' => array_map(fn($e) => "{$e['cimke']}: {$e['regi']} → {$e['uj']}" . ($e['akadaly'] ? " (nem javítható: {$e['akadaly']})" : ''), $s['elteresek'] ?? []),
    'osszevon' => $s['osszevon'] ?? null, 'vedett' => $s['vedett'] ?? null,
    'jeloltek' => array_map(fn($j) => "{$j['kod']} „{$j['szamlaszam']}” (id {$j['id']}, rokonság {$j['rokonsag']})", $s['osszevon_jeloltek'] ?? [])];
$csop = static fn(callable $f) => array_values(array_map($sorRov, array_filter($teljes['sorok'], $f)));
$elemzes = [
    'fajl' => $nev, 'formatum' => $el['formatum'], 'adatbazis' => DB_NAME, 'mervado' => $mervado, 'javit' => $javit, 'felulir' => $fk,
    'osszesites' => $o, 'ceg_osszesites' => $teljes['ceg_osszesites'], 'kivalasztott' => count($kivalasztott),
    'dontesre_var' => array_map($sorRov, $dontesre),
    'vedett_kihagyva' => $csop(fn($s) => !empty($s['vedett']) && !isset($felulirSet[$s['i']]) && $javitando($s)),
    'osszevonas' => $csop(fn($s) => !empty($s['osszevon'])),
    'elter' => $csop(fn($s) => $s['allapot'] === 'elter'),
    'hiba' => $csop(fn($s) => $s['allapot'] === 'hiba'),
    'dupla' => $csop(fn($s) => $s['allapot'] === 'dupla'),
    'utkozik' => $csop(fn($s) => $s['allapot'] === 'utkozik'),
    'uj' => $csop(fn($s) => $s['allapot'] === 'uj'),
    'felesleg' => $teljes['felesleg'],
    'cegcsoportok' => array_map(fn($cs) => ['nevek' => $cs['nevek'], 'partnerkod' => $cs['partnerkod'] ?? '', 'cel' => $cs['cel'], 'javaslat' => $cs['javaslat'] ?? null,
        'bizonytalan' => !empty($cs['bizonytalan']), 'sorok' => $cs['sorok'] ?? 0], $teljes['csoportok']),
];
file_put_contents("$jelentesDir/elemzes.json", json_encode($elemzes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

printf("%s (%s): %d adatsor – új %d, megvan %d, eltér %d, ütköző %d, dupla %d, hibás %d; összevonás %d, utalásban (védett) %d, más cégnél %d\n",
    $nev, $el['formatum'], count($teljes['sorok']), $o['uj'], $o['letezik'], $o['elter'], $o['utkozik'], $o['dupla'], $o['hiba'], $o['osszevon'], $o['vedett'], $o['mas_ceg']);
printf("cégek: %d meglévő, %d új, %d bizonytalan · kijelölve %d sor · döntést vár %d · utalás miatt kihagyva %d · felesleg (DB-ben van, fájlban nincs) %d\n",
    $teljes['ceg_osszesites']['letezik'], $teljes['ceg_osszesites']['uj'], $teljes['ceg_osszesites']['bizonytalan'], count($kivalasztott), count($dontesre),
    count($elemzes['vedett_kihagyva']), $teljes['felesleg']['db'] ?? 0);

if ($szaraz) {
    printf("SZÁRAZ FUTÁS – az adatbázis nem változott. Jelentés: %s/elemzes.json (%.1f mp)\n", $jelentesDir, microtime(true) - $t0);
    exit(0);
}

$opciok = ['bejovo_statusz' => 'FIZETENDO', 'kimeno_statusz' => 'NYITOTT', 'utkozes' => $utkozes, 'hatarido_nap' => 30, 'mervado' => $mervado, 'javit' => $javit,
           'felulir' => $felulir, 'osszevon' => $osszevon, 'ceg_frissit' => $cegFrissit];
$r = import_vegrehajt($el, $kivalasztott, $opciok, $u);
file_put_contents("$jelentesDir/eredmeny.json", json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
$st = $r['stat'];
printf("KÉSZ: %d bejövő + %d kimenő új számla, %d javítás (ebből %d összevonás), %d új cég, %d új archív kötés, %d cég frissítve, %d védett kihagyva, %d kihagyva (%.1f mp)\n",
    $st['bejovo'], $st['kimeno'], $st['javitva'], $st['osszevont'], $st['uj_ceg'], $st['uj_archiv'], $st['ceg_frissitve'], $st['vedett'], $st['kihagyott'], microtime(true) - $t0);
echo "Jelentés: $jelentesDir/\n";
