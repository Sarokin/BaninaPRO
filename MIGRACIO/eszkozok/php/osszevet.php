<?php
/**
 * Két adatbázis összevetése rekordonként és mezőnként (a migráció előtti és utáni állapot) – csak olvas.
 *   kornyezet.sh php <utána db> /migracio/eszkozok/php/osszevet.php <előtte db> [kimeneti mappa]
 * Az azonosítók soha nem ismétlődnek (törlés után sem), ezért a rekordokat az id párosítja.
 * Kimenet: osszevetes.txt (összefoglaló) + valtozasok_<tábla>.csv (minden új / módosult / törölt rekord, mezőnként).
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

$elotte = $argv[1] ?? '';
if (!preg_match('/^[a-z][a-z0-9_]{1,40}$/', $elotte)) {
    fwrite(STDERR, "Használat: osszevet.php <előtte db> [kimeneti mappa]\n");
    exit(1);
}
$utana = DB_NAME;
$ki = rtrim($argv[2] ?? "/migracio/4_EREDMENY/$utana/osszevetes_$elotte", '/');
@mkdir($ki, 0775, true);

const OSSZEVET_TABLAK = [
    'cegek'            => ['nev', 'adoszam', 'partnerkod', 'aktiv', 'megjegyzes'],
    'kotesek'          => ['ceg_id', 'ev', 'penznem', 'sorszam', 'kod', 'megnevezes', 'regi_kod', 'archiv', 'utolso_k', 'megjegyzes'],
    'bejovo_szamlak'   => ['kotes_id', 'k_sorszam', 'kod', 'szamlaszam', 'teljesites_datum', 'kelt', 'osszeg', 'beszam', 'regi_k', 'fizetesi_hatarido',
                           'statusz', 'fizetve_datum', 'utalas_id', 'megjegyzes', 'konyveles_kikuldve'],
    'kimeno_szamlak'   => ['ceg_id', 'kod', 'szamlaszam', 'teljesites_datum', 'kelt', 'fizetesi_hatarido', 'osszeg', 'statusz', 'banki_kivonat_id',
                           'fizetve_datum', 'megjegyzes', 'konyveles_kikuldve'],
    'utalasok'         => ['ceg_id', 'uid', 'statusz', 'utalva_datum', 'banki_hivatkozas', 'lezarva', 'utalas_mod', 'hatarertek', 'megjegyzes'],
    'banki_kivonatok'  => ['azonosito', 'datum'],
    'reszteljesitesek' => ['irany', 'szamla_id', 'osszeg', 'datum', 'banki_azonosito'],
];
const OSSZEVET_KOD = ['cegek' => 'nev', 'kotesek' => 'kod', 'bejovo_szamlak' => 'kod', 'kimeno_szamlak' => 'kod', 'utalasok' => 'uid',
                      'banki_kivonatok' => 'azonosito', 'reszteljesitesek' => 'szamla_id'];

function tabla_be(string $db, string $t, array $mezok): array
{
    $ki = [];
    $st = db()->query('SELECT id, ' . implode(', ', array_map(fn($m) => "`$m`", $mezok)) . " FROM `$db`.`$t`");
    foreach ($st as $r) {
        $ki[(int)$r['id']] = $r;
    }
    return $ki;
}

$osszes = [];
$sorok = [];
foreach (OSSZEVET_TABLAK as $t => $mezok) {
    $a = tabla_be($elotte, $t, $mezok);
    $b = tabla_be($utana, $t, $mezok);
    $st = ['uj' => 0, 'modosult' => 0, 'torolt' => 0, 'mezok' => []];
    $f = fopen("$ki/valtozasok_$t.csv", 'w');
    fputcsv($f, ['id', 'azonosító', 'változás', 'mező', 'előtte', 'utána']);
    foreach ($b as $id => $r) {
        if (!isset($a[$id])) {
            $st['uj']++;
            fputcsv($f, [$id, $r[OSSZEVET_KOD[$t]], 'ÚJ', '', '', '']);
            continue;
        }
        $volt = false;
        foreach ($mezok as $m) {
            $x = $a[$id][$m];
            $y = $r[$m];
            if ((string)$x !== (string)$y) {
                $volt = true;
                $st['mezok'][$m] = ($st['mezok'][$m] ?? 0) + 1;
                fputcsv($f, [$id, $r[OSSZEVET_KOD[$t]], 'MÓDOSULT', $m, (string)$x, (string)$y]);
            }
        }
        $st['modosult'] += $volt ? 1 : 0;
    }
    foreach ($a as $id => $r) {
        if (!isset($b[$id])) {
            $st['torolt']++;
            fputcsv($f, [$id, $r[OSSZEVET_KOD[$t]], 'TÖRÖLT', '', '', '']);
        }
    }
    fclose($f);
    $st['elotte'] = count($a);
    $st['utana'] = count($b);
    $osszes[$t] = $st;
}

// egyenlegek pénznemenként: nyitott bejövő (FIZETENDŐ + UTALÁSHOZ ADVA, a részteljesítéssel csökkentve) és nyitott kimenő
function egyenleg(string $db): array
{
    $ki = [];
    foreach (db()->query("SELECT k.penznem, COUNT(*) db, SUM(b.osszeg - COALESCE((SELECT SUM(r.osszeg) FROM `$db`.reszteljesitesek r WHERE r.irany = 'BEJOVO' AND r.szamla_id = b.id), 0)) ossz
                            FROM `$db`.bejovo_szamlak b JOIN `$db`.kotesek k ON k.id = b.kotes_id WHERE b.statusz IN ('FIZETENDO', 'UTALASHOZ_ADVA') GROUP BY k.penznem") as $r) {
        $ki["bejövő nyitott {$r['penznem']}"] = [(int)$r['db'], number_format((float)$r['ossz'], 2, '.', '')];
    }
    foreach (db()->query("SELECT s.penznem, COUNT(*) db, SUM(s.osszeg - COALESCE((SELECT SUM(r.osszeg) FROM `$db`.reszteljesitesek r WHERE r.irany = 'KIMENO' AND r.szamla_id = s.id), 0)) ossz
                            FROM `$db`.kimeno_szamlak s WHERE s.statusz = 'NYITOTT' GROUP BY s.penznem") as $r) {
        $ki["kimenő nyitott {$r['penznem']}"] = [(int)$r['db'], number_format((float)$r['ossz'], 2, '.', '')];
    }
    foreach (db()->query("SELECT k.penznem, COUNT(*) db, SUM(b.osszeg) ossz FROM `$db`.bejovo_szamlak b JOIN `$db`.kotesek k ON k.id = b.kotes_id GROUP BY k.penznem") as $r) {
        $ki["bejövő összes {$r['penznem']}"] = [(int)$r['db'], number_format((float)$r['ossz'], 2, '.', '')];
    }
    foreach (db()->query("SELECT penznem, COUNT(*) db, SUM(osszeg) ossz FROM `$db`.kimeno_szamlak GROUP BY penznem") as $r) {
        $ki["kimenő összes {$r['penznem']}"] = [(int)$r['db'], number_format((float)$r['ossz'], 2, '.', '')];
    }
    ksort($ki);
    return $ki;
}
$ea = egyenleg($elotte);
$eb = egyenleg($utana);

$s = ["Összevetés: $elotte (előtte) → $utana (utána), " . date('Y-m-d H:i'), ''];
$s[] = sprintf('%-18s %8s %8s %8s %8s %8s   módosult mezők', 'tábla', 'előtte', 'utána', 'új', 'módosult', 'törölt');
foreach ($osszes as $t => $st) {
    arsort($st['mezok']);
    $s[] = sprintf('%-18s %8d %8d %8d %8d %8d   %s', $t, $st['elotte'], $st['utana'], $st['uj'], $st['modosult'], $st['torolt'],
        implode(', ', array_map(fn($m, $n) => "$m $n", array_keys($st['mezok']), $st['mezok'])));
}
$s[] = '';
$s[] = 'Egyenlegek (darab, összeg):';
foreach (array_unique(array_merge(array_keys($ea), array_keys($eb))) as $k) {
    $x = $ea[$k] ?? [0, '0.00'];
    $y = $eb[$k] ?? [0, '0.00'];
    $s[] = sprintf('  %-22s %6d db %16s  →  %6d db %16s   (%+.2f)', $k, $x[0], fmt_osszeg($x[1]), $y[0], fmt_osszeg($y[1]), (float)$y[1] - (float)$x[1]);
}
$s[] = '';
$s[] = "Részletek: $ki/valtozasok_<tábla>.csv";
$szoveg = implode("\n", $s) . "\n";
file_put_contents("$ki/osszevetes.txt", $szoveg);
file_put_contents("$ki/osszevetes.json", json_encode(['tablak' => $osszes, 'egyenleg_elotte' => $ea, 'egyenleg_utana' => $eb], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo $szoveg;
