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
 *     --dupla=torol            a DOS-ban kétszer rögzített kimenő számla nyitott, IB-ben nem szereplő másodpéldánya törlődik, ha a
 *                              kifizetett ikre (azonos cég, összeg, nap) az IB-ben megvan (alap: marad)
 *     --vevo=ib                a kimenő számla vevője az IB System szerint: ha a rendszerben MÁS cégnél van, de a száma, összege
 *                              és dátuma egyezik (a DOS-ban rossz vevőnél rögzítve), az IB szerinti céghez kerül (alap: marad)
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
// ---- előlépés (--vevo=ib): minden IB-partner (partnerkód; ha nincs, a név) a SAJÁT cégéhez. Az app a fájl neveit a számláik szerint
// csoportosítja („szavazás”), így egy DOS-ban rossz vevőnél rögzített számla egy másik IB-partnert is ugyanahhoz a céghez húzhat (pl. egy
// saját IB-partnerkódú és adószámú vevő számlái a DOS-ban egy hasonló nevű másik vevőnél). A cég: 1) azonos adószám (törzsszám, egyértelműen),
// 2) ahol a számlái vannak, ha annak az adószáma nem mond ellent, 3) azonos név, 4) új cég (az IB adataival). Az IB a pontos.
$pdo = db();
$partnerDontes = [];   // a jelentéshez
$partnerPar = [];      // megjegyzett párosítás: IB-partner → cég
if (($k['vevo'] ?? '') === 'ib') {
    $adoTorzs = static function (?string $a): ?string {
        preg_match_all('/\d+/', (string)$a, $m);
        $h = '';
        foreach ($m[0] as $d) {
            if (strlen($d) > strlen($h)) {
                $h = $d;
            }
        }
        return strlen($h) >= 8 ? substr($h, 0, 8) : null;
    };
    $torzsCeg = [];
    $cegTorzs = [];
    $nevCeg = [];
    $cegNev = [];
    foreach (db_all('SELECT id, nev, adoszam FROM cegek') as $c) {
        $cid = (int)$c['id'];
        $cegNev[$cid] = $c['nev'];
        if (($t = $adoTorzs($c['adoszam'])) !== null) {
            $torzsCeg[$t][] = $cid;
            $cegTorzs[$cid] = $t;
        }
        $nevCeg[imp_ceg_norm((string)$c['nev'])][] = $cid;
    }
    $partnerek = [];
    foreach ($el['nevek'] as $n) {
        $partnerek[$n['partnerkod'] !== '' ? 'K' . $n['partnerkod'] : 'N' . $n['nev']][] = $n;
    }
    $regiCel = [];   // név → a szavazásos csoportosítás célja (a jelentéshez)
    foreach ($el['csoportok'] as $cs) {
        foreach ($cs['nevek'] as $v) {
            $regiCel[$v] = $cs['cel'];
        }
    }
    $pdo->beginTransaction();
    $ujCsoportok = [];
    foreach ($partnerek as $nevek) {
        $torzs = null;
        foreach ($nevek as $n) {
            foreach (array_merge([$n['adoszam']], $n['adok']) as $a) {
                if (($torzs = $adoTorzs($a)) !== null) {
                    break 2;
                }
            }
        }
        $szav = [];
        foreach ($nevek as $n) {
            foreach ($n['szavazat'] as $cid => $db) {
                $szav[(int)$cid] = ($szav[(int)$cid] ?? 0) + (int)$db;
            }
        }
        arsort($szav);
        $cel = null;
        $mod = '';
        if ($torzs !== null && count($torzsCeg[$torzs] ?? []) === 1) {
            [$cel, $mod] = [$torzsCeg[$torzs][0], 'azonos adószám'];
        }
        if ($cel === null && $szav) {
            // ahol a számlái vannak – kivéve, ha annak a cégnek más az adószáma, és ez csak néhány számla (egy DOS-ban rossz vevőnél
            // rögzített számla húzza oda). Sok számla következetesen egy cégnél erősebb bizonyíték (pl. a felhasználó által összevont
            // cég a szállítói rekord adószámát viszi, a vevői rekordnak nem volt).
            $c1 = (int)array_key_first($szav);
            $eros = $szav[$c1] >= 5 && $szav[$c1] >= 0.9 * array_sum($szav);
            if ($torzs === null || !isset($cegTorzs[$c1]) || $cegTorzs[$c1] === $torzs || $eros) {
                [$cel, $mod] = [$c1, 'ott vannak a számlái' . ($torzs !== null && isset($cegTorzs[$c1]) && $cegTorzs[$c1] !== $torzs ? ' (más adószámmal)' : '')];
            }
        }
        if ($cel === null) {
            foreach ($nevek as $n) {
                $nc = $nevCeg[imp_ceg_norm($n['nev'])] ?? [];
                if (count($nc) === 1) {
                    [$cel, $mod] = [$nc[0], 'azonos név'];
                    break;
                }
            }
        }
        if ($cel === null) {
            // új cég az IB adataival (a számlái a DOS-ban más vevőnél voltak, vagy még nincs számlája a rendszerben)
            $n0 = $nevek[0];
            db_exec('INSERT INTO cegek (nev, adoszam, partnerkod, megjegyzes) VALUES (?, ?, ?, ?)', [$n0['nev'], $n0['adoszam'] !== '' ? $n0['adoszam'] : null,
                $n0['partnerkod'] !== '' ? $n0['partnerkod'] : null, 'Excel import (IB System, migráció, ' . date('Y.m.d') . '): az IB System partnere' . ($n0['partnerkod'] !== '' ? " (partnerkód {$n0['partnerkod']})" : '')]);
            $cel = (int)$pdo->lastInsertId();
            $mod = 'új cég';
            $cegNev[$cel] = $n0['nev'];
            $el['db_cegek'][] = ['id' => $cel, 'nev' => $n0['nev'], 'partnerkod' => $n0['partnerkod']];
        }
        $ujCsoportok[] = ['id' => count($ujCsoportok), 'nevek' => array_column($nevek, 'nev'), 'uj_nev' => $nevek[0]['nev'], 'cel' => ['tipus' => 'db', 'ceg_id' => $cel], 'bizonytalan' => false];
        foreach ($nevek as $n) {
            $partnerPar[imp_parositas_kulcs($n['partnerkod'], $n['nev'], 'KIMENO')] = $cel;
            $r = $regiCel[$n['nev']] ?? null;
            if ($r === null || $r['tipus'] !== 'db' || (int)$r['ceg_id'] !== $cel) {
                $partnerDontes[] = sprintf('IB %s „%s” (%d sor) → #%d „%s” (%s; a számlák szerinti csoportosítás: %s)', $n['partnerkod'] ?: '–', $n['nev'], $n['sorok'], $cel, $cegNev[$cel], $mod,
                    $r === null ? '–' : ($r['tipus'] === 'db' ? "#{$r['ceg_id']} „" . ($cegNev[(int)$r['ceg_id']] ?? '?') . '”' : 'új cég'));
            }
        }
    }
    $el['csoportok'] = import_csoportok_ellenoriz($ujCsoportok, $el);
    foreach ($partnerDontes as $x) {
        echo "IB-partner: $x\n";
    }
}

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

// ---- előlépés: a DOS-ban elírt kimenő számlaszám felszabadítása. Az app a kimenő számlát a számlaszáma azonosítja, akkor is,
// ha az a rendszerben más cégnél van. Ha a cég MELLETT az összeg is más, az nem ugyanaz a számla, hanem a DOS-ban elírt szám
// (pl. a DOS „KE-26 9999” egy júniusi 100 EUR-s számla, az IB KE-9999 egy októberi 20 000 EUR-s): a rendszerbeli számla
// „ (DOS)” jelölést kap (kerek zárójel: a szögletes toldalékot az app az összevetésnél levágja), és az egyeztetés újraszámolódik – az IB-sor új számla lesz, vagy megtalálja a valódi párját.
$elirasok = [];
foreach ($teljes['sorok'] as $s) {
    $l = $s['letezo'] ?? null;
    if ($s['irany'] !== 'KIMENO' || empty($s['mas_ceg']) || $l === null || !empty($s['osszevon']) || abs((float)$l['osszeg'] - (float)$s['osszeg']) < 0.005) {
        continue;
    }
    $elirasok[] = ['sor' => $s['sor'], 'ib' => "{$s['szamlaszam']} ({$s['ceg_nev']}, {$s['kelt']}, {$s['osszeg']} {$s['penznem']})",
                   'rendszer' => "{$l['kod']} „{$l['szamlaszam']}” ({$l['ceg_nev']}, {$l['kelt']}, {$l['osszeg']} {$l['penznem']})", 'id' => (int)$l['id'], 'szamlaszam' => $l['szamlaszam']];
}
if ($elirasok) {
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }
    foreach ($elirasok as &$x) {
        $uj = mb_substr($x['szamlaszam'], 0, 92, 'UTF-8') . ' (DOS)';
        for ($j = 2; db_val('SELECT id FROM kimeno_szamlak WHERE szamlaszam_norm = ?', [norm_azonosito($uj)]); $j++) {
            $uj = mb_substr($x['szamlaszam'], 0, 90, 'UTF-8') . " (DOS $j)";
        }
        $megj = "\nIB System egyeztetés (" . date('Y.m.d') . "): a DOS-beli számlaszám ({$x['szamlaszam']}) az IB System szerint egy másik vevő számlájáé: {$x['ib']} – ez a számla az IB-ben ezen a számon nincs, ellenőrizendő";
        db_exec('UPDATE kimeno_szamlak SET szamlaszam = ?, szamlaszam_norm = ?, megjegyzes = CONCAT(COALESCE(megjegyzes, \'\'), ?) WHERE id = ?', [$uj, norm_azonosito($uj), $megj, $x['id']]);
        audit_ir_tobb([['KIMENO', $x['id'], null, 'MODOSIT', "Excel import (IB System, migráció): a DOS-ban elírt számlaszám felszabadítva – az IB-ben ez a szám: {$x['ib']}",
            [['m' => 'Számlaszám', 'r' => $x['szamlaszam'], 'u' => $uj], ['m' => 'Megjegyzés', 'r' => '…', 'u' => '… + IB System egyeztetés']]]], $u);
        $x['uj'] = $uj;
        printf("DOS-elírás: %s → „%s” (az IB-ben ez a szám: %s)\n", $x['rendszer'], $uj, $x['ib']);
    }
    unset($x);
    $teljes = import_sorok_allapot($el);
}

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
    'dos_elirasok' => $elirasok,
    'ib_partner_dontes' => $partnerDontes,
    'mas_cegnel_azonos' => $csop(fn($s) => $s['irany'] === 'KIMENO' && !empty($s['mas_ceg']) && isset($s['letezo']) && abs((float)$s['letezo']['osszeg'] - (float)$s['osszeg']) < 0.005),
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
    if ($pdo->inTransaction()) {
        $pdo->rollBack();   // az előlépések (új IB-partner cégek, DOS-elírások felszabadítása) is csak próbák voltak
    }
    printf("SZÁRAZ FUTÁS – az adatbázis nem változott. Jelentés: %s/elemzes.json (%.1f mp)\n", $jelentesDir, microtime(true) - $t0);
    exit(0);
}

$opciok = ['bejovo_statusz' => 'FIZETENDO', 'kimeno_statusz' => 'NYITOTT', 'utkozes' => $utkozes, 'hatarido_nap' => 30, 'mervado' => $mervado, 'javit' => $javit,
           'felulir' => $felulir, 'osszevon' => $osszevon, 'ceg_frissit' => $cegFrissit];
if ($pdo->inTransaction()) {
    $pdo->commit();   // az előlépések (új IB-partner cégek, DOS-elírások) – az import saját tranzakcióban fut
}
import_parositas_ment($el['parositas_biztos']);   // a biztos (≥ 90 %) párosítások – csak éles futásban (a száraz nem ír)
$r = import_vegrehajt($el, $kivalasztott, $opciok, $u);
$st = $r['stat'];
if ($partnerPar) {
    import_parositas_ment($partnerPar);   // a napi IB-importok is így párosítsanak (az import a szavazás szerintit jegyezte meg)
}

// ---- utólépés: a vevő az IB System szerint (--vevo=ib; a felhasználó döntése, 2026-10-16). Ha az IB-sor számlája a rendszerben más
// cégnél van, de a száma és az összege egyezik, az a DOS-ban rossz vevőnél rögzített számla: az IB szerinti céghez kerül
$vevoAt = [];
if (($k['vevo'] ?? '') === 'ib') {
    foreach (import_sorok_allapot($el)['sorok'] as $s) {
        $l = $s['letezo'] ?? null;
        if ($s['irany'] !== 'KIMENO' || $l === null || empty($s['mas_ceg']) || $s['ceg_id'] === null || (int)$l['ceg_id'] === (int)$s['ceg_id']
            || abs((float)$l['osszeg'] - (float)$s['osszeg']) >= 0.005) {
            continue;
        }
        $ujNev = (string)db_val('SELECT nev FROM cegek WHERE id = ?', [$s['ceg_id']]);
        db_exec('UPDATE kimeno_szamlak SET ceg_id = ? WHERE id = ?', [$s['ceg_id'], $l['id']]);
        audit_ir_tobb([['KIMENO', (int)$l['id'], null, 'ATHELYEZ', "Excel import (IB System, migráció): a vevő az IB System szerint „{$ujNev}” (a DOS-ban: „{$l['ceg_nev']}”)",
            [['m' => 'Cég', 'r' => $l['ceg_nev'], 'u' => $ujNev]]]], $u);
        $vevoAt[] = "{$l['kod']} „{$s['szamlaszam']}” {$s['osszeg']} {$s['penznem']}: „{$l['ceg_nev']}” → „{$ujNev}”";
        printf("vevő az IB szerint: %s\n", end($vevoAt));
    }
}
$r['vevo_ib_szerint'] = $vevoAt;


// ---- utólépés: a DOS-ban kétszer rögzített kimenő számla nyitott másodpéldánya (--dupla=torol; a felhasználó döntése, 2026-10-16).
// Feltétel: a DOS-ból jött, NYITOTT, befolyás és részteljesítés nélkül, az IB-ben NINCS (a fájl egyetlen sora sem találta meg), és
// ugyanannál a cégnél van egy kifizetett (FIZETVE) ikre ugyanazzal az összeggel és nappal (a DOS-ban a kelt a teljesítés napja), amelyet
// az IB megtalált. Pl. a DOS „KE-26 5555” = a kifizetett KE-4555 elírt számmal. Teljes adattal a változásnaplóba kerül.
$duplaTorolve = [];
if (($k['dupla'] ?? '') === 'torol') {
    $utan = import_sorok_allapot($el);
    $talalt = [];
    foreach ($utan['sorok'] as $s) {
        if ($s['irany'] === 'KIMENO' && isset($s['letezo']['id'])) {
            $talalt[(int)$s['letezo']['id']] = true;
        }
    }
    foreach ($utan['felesleg']['lista'] ?? [] as $f) {
        $d = db_row("SELECT s.*, c.nev AS ceg_nev FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id WHERE s.id = ?", [$f['id']]);
        if ($f['irany'] !== 'KIMENO' || $d === null || $d['statusz'] !== 'NYITOTT' || $d['banki_kivonat_id'] !== null || !str_contains((string)$d['megjegyzes'], 'DOS azonosító:')
            || db_val("SELECT COUNT(*) FROM reszteljesitesek WHERE irany = 'KIMENO' AND szamla_id = ?", [$d['id']])) {
            continue;
        }
        $ikrek = array_values(array_filter(db_all("SELECT id, kod, szamlaszam, kelt, teljesites_datum, fizetve_datum FROM kimeno_szamlak
                     WHERE ceg_id = ? AND penznem = ? AND osszeg = ? AND id <> ? AND statusz = 'FIZETVE'
                       AND (teljesites_datum IN (?, ?) OR kelt IN (?, ?))", [$d['ceg_id'], $d['penznem'], $d['osszeg'], $d['id'], $d['kelt'], $d['teljesites_datum'], $d['kelt'], $d['teljesites_datum']]),
            fn($t) => isset($talalt[(int)$t['id']])));
        if (!$ikrek) {
            continue;
        }
        $t = $ikrek[0];
        $leiras = "Excel import (IB System, migráció): TÖRÖLVE – a DOS-ban kétszer rögzített számla nyitott másodpéldánya: „{$d['szamlaszam']}” ({$d['ceg_nev']}, kelt {$d['kelt']}, "
            . "{$d['osszeg']} {$d['penznem']}, NYITOTT); a kifizetett példánya: {$t['kod']} „{$t['szamlaszam']}” (fizetve {$t['fizetve_datum']}); az IB System csak azt ismeri";
        db_exec('DELETE FROM kimeno_szamlak WHERE id = ?', [$d['id']]);
        audit_ir_tobb([['KIMENO', (int)$d['id'], null, 'TOROL', $leiras, [['m' => 'Azonosító', 'r' => $d['kod'], 'u' => '–'], ['m' => 'Számlaszám', 'r' => $d['szamlaszam'], 'u' => '–'],
            ['m' => 'Összeg', 'r' => audit_ertek('osszeg', $d['osszeg'], $d['penznem']), 'u' => '–'], ['m' => 'Megjegyzés', 'r' => audit_ertek('megjegyzes', $d['megjegyzes']), 'u' => '–']]]], $u);
        naplo('EXCEL_DUPLA_TOROL', "{$d['kod']} „{$d['szamlaszam']}” {$d['osszeg']} {$d['penznem']} ({$d['ceg_nev']}) – párja: {$t['kod']} „{$t['szamlaszam']}”", 'OK', $felhNev);
        $duplaTorolve[] = "{$d['kod']} „{$d['szamlaszam']}” {$d['ceg_nev']}, {$d['kelt']}, {$d['osszeg']} {$d['penznem']} – kifizetett párja: {$t['kod']} „{$t['szamlaszam']}” ({$t['fizetve_datum']})";
        printf("dupla törölve: %s\n", end($duplaTorolve));
    }
}
$r['dupla_torolve'] = $duplaTorolve;

// ---- utólépés: az összeg-javítás, amit az app a részteljesítés miatt nem végzett el („az új összeg nem nagyobb a már felvezetett
// részteljesítéseknél”): ha a DOS-ból jött befolyás legalább az IB szerinti összeg, a számla az IB összegével TELJESEN ki van fizetve
// (pl. a DOS-ban 100,50, befolyt 100,00; az IB szerint a számla 100,00) → az IB összege, FIZETVE a befolyás napjával
$resztRendezve = [];
foreach (import_sorok_allapot($el)['sorok'] as $s) {
    $l = $s['letezo'] ?? null;
    if ($s['irany'] !== 'KIMENO' || $l === null || $s['allapot'] !== 'elter') {
        continue;
    }
    $ak = array_values(array_filter($s['elteresek'], fn($e) => $e['mezo'] === 'osszeg' && $e['akadaly'] !== null));
    $d = $ak ? db_row('SELECT * FROM kimeno_szamlak WHERE id = ?', [$l['id']]) : null;
    if ($d === null || $d['statusz'] !== 'NYITOTT') {
        continue;
    }
    $rt = db_all("SELECT id, osszeg, datum, banki_azonosito, letrehozta FROM reszteljesitesek WHERE irany = 'KIMENO' AND szamla_id = ? ORDER BY datum, id", [$d['id']]);
    $sum = array_sum(array_map(fn($x) => (float)$x['osszeg'], $rt));
    $dosRt = !array_filter($rt, fn($x) => $x['letrehozta'] !== null || !str_starts_with((string)$x['banki_azonosito'], 'DOS '));
    if (!$rt || !$dosRt || $sum + 0.005 < (float)$s['osszeg']) {
        continue;
    }
    $fd = end($rt)['datum'];
    $az = 'DOS ' . str_replace('-', '.', $fd) . ' ' . $d['penznem'];
    $bk = db_val('SELECT id FROM banki_kivonatok WHERE azonosito_norm = ?', [norm_azonosito($az)]);
    if (!$bk) {
        db_exec('INSERT INTO banki_kivonatok (azonosito, azonosito_norm, datum) VALUES (?, ?, ?)', [$az, norm_azonosito($az), $fd]);
        $bk = (int)db()->lastInsertId();
    }
    db_exec("DELETE FROM reszteljesitesek WHERE irany = 'KIMENO' AND szamla_id = ?", [$d['id']]);
    db_exec("UPDATE kimeno_szamlak SET osszeg = ?, statusz = 'FIZETVE', fizetve_datum = ?, banki_kivonat_id = ? WHERE id = ?", [$s['osszeg'], $fd, $bk, $d['id']]);
    audit_ir_tobb([['KIMENO', (int)$d['id'], null, 'MODOSIT', "Excel import (IB System, migráció): az összeg az IB szerint " . fmt_osszeg((float)$s['osszeg']) . " {$d['penznem']}; a DOS-ban befolyt "
        . fmt_osszeg($sum) . " {$d['penznem']} ezt teljesen fedezi → FIZETVE ($fd), a részteljesítés helyett", [['m' => 'Összeg', 'r' => audit_ertek('osszeg', $d['osszeg'], $d['penznem']), 'u' => audit_ertek('osszeg', $s['osszeg'], $d['penznem'])],
        ['m' => 'Státusz', 'r' => ny_statusz_nev('NYITOTT'), 'u' => ny_statusz_nev('FIZETVE')], ['m' => AUDIT_CIMKEK['fizetve_datum'], 'r' => '–', 'u' => audit_ertek('fizetve_datum', $fd)]]]], $u);
    $resztRendezve[] = "{$d['kod']} „{$d['szamlaszam']}”: {$d['osszeg']} → {$s['osszeg']} {$d['penznem']}, befolyt " . number_format($sum, 2, '.', '') . " ($fd) → FIZETVE";
    printf("részteljesítés rendezve: %s\n", end($resztRendezve));
}
$r['reszt_rendezve'] = $resztRendezve;

// ---- utólépés: a 0 összegű IB-számla (az app importja hibának veszi, a felhasználó kérése: „semmi ne maradjon ki”). NYITOTT, mert
// fizetést nem találunk ki; 0 összeg miatt a hátraléka 0, egyenleget nem változtat.
$nullasFelveve = [];
foreach (import_sorok_allapot($el)['sorok'] as $s) {
    if ($s['irany'] !== 'KIMENO' || $s['allapot'] !== 'hiba' || $s['hibak'] !== ['az összeg nulla (Z)'] || $s['ceg_id'] === null || $s['szamlaszam'] === ''
        || db_val('SELECT id FROM kimeno_szamlak WHERE szamlaszam_norm = ?', [norm_azonosito($s['szamlaszam'])])) {
        continue;
    }
    $ev = (int)substr((string)$s['kelt'], 0, 4);
    $n = kovetkezo_sorszam('KIMENO', $ev, $s['penznem']);
    $kod = kimeno_kod($ev, $s['penznem'], $n);
    db_exec("INSERT INTO kimeno_szamlak (ceg_id, ev, penznem, sorszam, kod, szamlaszam, szamlaszam_norm, teljesites_datum, kelt, fizetesi_hatarido, osszeg, statusz, megjegyzes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'NYITOTT', ?)",
        [$s['ceg_id'], $ev, $s['penznem'], $n, $kod, $s['szamlaszam'], norm_azonosito($s['szamlaszam']), $s['teljesites'], $s['kelt'], $s['hatarido'],
         'Excel import (IB System, migráció, ' . date('Y.m.d') . '): az IB System-ben 0 összegű számla (nettó, ÁFA és bruttó is 0) – a teljes számsor miatt felvéve, pénzügyi hatása nincs']);
    $sid = (int)db()->lastInsertId();
    audit_ir_tobb([['KIMENO', $sid, null, 'IMPORT', "Excel import (IB System, migráció): 0 összegű számla felvéve: $kod „{$s['szamlaszam']}”", []]], $u);
    $nullasFelveve[] = "$kod „{$s['szamlaszam']}” ({$s['ceg_nev']}, {$s['kelt']}, 0 {$s['penznem']})";
    printf("0 összegű számla felvéve: %s\n", end($nullasFelveve));
}
$r['nullas_felveve'] = $nullasFelveve;
// ---- utólépés (utolsó): a kimenő számlaszám írásmódja az IB szerint. Az app az összevetésnél a toldalékot („ [cégnév]”) és az
// írásjeleket nem tekinti eltérésnek, így a DOS-ból jött „KE-1234/2026 [Minta GmbH]” (a DOS-ban két rekordnak ugyanaz volt a száma)
// vagy „KE-1234+/2026” (a DOS-azonosítóban „+” jel) megmaradna. Ha az IB szerint ez a számla viseli a számot (azonos cég és összeg), és a
// szám már szabad (az összevonások és a dupla-törlés után), az IB írásmódja kerül be.
$toldalekNelkul = [];
foreach (import_sorok_allapot($el)['sorok'] as $s) {
    $l = $s['letezo'] ?? null;
    if ($s['irany'] !== 'KIMENO' || $l === null || !in_array($s['allapot'], ['letezik', 'elter'], true) || (string)$l['szamlaszam'] === $s['szamlaszam']
        || norm_azonosito(imp_szamlaszam_alap((string)$l['szamlaszam'])) !== $s['szamlaszam_norm']
        || (int)$l['ceg_id'] !== (int)$s['ceg_id'] || abs((float)$l['osszeg'] - (float)$s['osszeg']) >= 0.005
        || db_val('SELECT id FROM kimeno_szamlak WHERE szamlaszam_norm = ? AND id <> ?', [$s['szamlaszam_norm'], $l['id']])) {
        continue;
    }
    db_exec('UPDATE kimeno_szamlak SET szamlaszam = ?, szamlaszam_norm = ? WHERE id = ?', [$s['szamlaszam'], $s['szamlaszam_norm'], $l['id']]);
    audit_ir_tobb([['KIMENO', (int)$l['id'], null, 'MODOSIT', 'Excel import (IB System, migráció): a számlaszám az IB System írásmódja szerint (toldalék / írásjel nélkül; a szám már szabad, az IB-ben ez a számla viseli)',
        [['m' => 'Számlaszám', 'r' => $l['szamlaszam'], 'u' => $s['szamlaszam']]]]], $u);
    $toldalekNelkul[] = "{$l['kod']}: „{$l['szamlaszam']}” → „{$s['szamlaszam']}”";
    printf("számlaszám az IB szerint: %s: „%s” → „%s”\n", $l['kod'], $l['szamlaszam'], $s['szamlaszam']);
}
$r['szamlaszam_ib_szerint'] = $toldalekNelkul;
$r['dos_elirasok'] = $elirasok;
file_put_contents("$jelentesDir/eredmeny.json", json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
printf("utólépések: %d számla az IB szerinti vevőhöz, %d számlaszám az IB írásmódjára javítva, %d dupla (nyitott másodpéldány) törölve, %d részteljesítés rendezve, %d nulla összegű számla felvéve\n", count($vevoAt), count($toldalekNelkul), count($duplaTorolve), count($resztRendezve), count($nullasFelveve));
printf("KÉSZ: %d bejövő + %d kimenő új számla, %d javítás (ebből %d összevonás), %d új cég, %d új archív kötés, %d cég frissítve, %d védett kihagyva, %d kihagyva (%.1f mp)\n",
    $st['bejovo'], $st['kimeno'], $st['javitva'], $st['osszevont'], $st['uj_ceg'], $st['uj_archiv'], $st['ceg_frissitve'], $st['vedett'], $st['kihagyott'], microtime(true) - $t0);
echo "Jelentés: $jelentesDir/\n";
