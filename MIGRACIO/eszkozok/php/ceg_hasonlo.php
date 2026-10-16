<?php
/**
 * Összevonásra jelölt cégek (döntésre): hasonló név, azonos adószám-törzs, illetve az IB System bizonyítéka (ugyanannak
 * az IB-partnernek a számlái több cégnél). Csak jelent, semmit nem módosít – az összevonás a felhasználó döntése.
 *
 *   kornyezet.sh php <db> /migracio/eszkozok/php/ceg_hasonlo.php [ib elemzes.json] [--ki=<mappa>]
 * Kimenet: <mappa>/ceg_hasonlo.txt + .json (alapból /migracio/4_EREDMENY/<db>/)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db.php';
require_once '/var/www/html/includes/helpers.php';
require_once __DIR__ . '/kozos.php';

$ibFajl = null;
$ki = '/migracio/4_EREDMENY/' . DB_NAME;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--ki=')) {
        $ki = rtrim(substr($a, 5), '/');
    } else {
        $ibFajl = $a;
    }
}
@mkdir($ki, 0775, true);

/** A név lényege: cégforma, országkód, pénznem-jelölés, írásjel és ékezet nélkül („Minta Romania SCS” → „minta romania”) */
function nev_lenyeg(string $nev): string
{
    $s = mig_nev_kulcs($nev);
    $formak = ['b v', 'bv', 'n v', 'nv', 'gmbh', 'co', 'kg', 'gmbhco', 'gmbh co kg', 'ohg', 'ag', 'kft', 'zrt', 'nyrt', 'rt', 'bt', 'ev', 'e v', 'srl', 's r l',
               'sa', 's a', 'spa', 's p a', 'slu', 's l u', 'sl', 's l', 'scs', 'doo', 'd o o', 'sro', 's r o', 'ltd', 'limited', 'sp z o o', 'eood', 'kd', 'k d',
               'sca', 'coop', 'inc', 'llc', 'bvba', 'vof', 'and', 'und', 'en', 'eur', 'huf', 'euro', 'deviza', 'ft', 'nl', 'hu', 'de', 'it', 'es', 'ro', 'at', 'bg',
               'hr', 'cz', 'pl', 'gr', 'international', 'intl', 'handelsgesellschaft', 'handels', 'mbh', 'v o s', 'vos', 'a s', 'as', 'ab', 'oy', 'as'];
    $szavak = array_values(array_filter(explode(' ', $s), fn($w) => $w !== '' && !in_array($w, $formak, true)));
    return implode(' ', $szavak);
}

$cegek = [];
foreach (db_all('SELECT c.id, c.nev, c.adoszam, c.partnerkod, c.megjegyzes,
                        (SELECT COUNT(*) FROM kotesek k WHERE k.ceg_id = c.id) AS kotes,
                        (SELECT COUNT(*) FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id WHERE k.ceg_id = c.id) AS bejovo,
                        (SELECT COUNT(*) FROM kimeno_szamlak s WHERE s.ceg_id = c.id) AS kimeno,
                        (SELECT COUNT(*) FROM utalasok u WHERE u.ceg_id = c.id) AS utalas
                   FROM cegek c ORDER BY c.id') as $c) {
    $m = mig_megj_bont((string)$c['megjegyzes'])['mezok'];
    $c['dos_kodok'] = array_map(fn($x) => "{$x[0]} {$x[1]}", mig_dos_kodok($m['DOS-kód'] ?? ''));
    $c['lenyeg'] = nev_lenyeg((string)$c['nev']);
    $c['ado_torzs'] = null;
    if (preg_match_all('/\d+/', (string)$c['adoszam'], $mm) && $mm[0]) {
        $h = max(array_map('strlen', $mm[0]));
        foreach ($mm[0] as $d) {
            if (strlen($d) === $h && $h >= 8) {
                $c['ado_torzs'] = substr($d, 0, 8);
            }
        }
    }
    $cegek[(int)$c['id']] = $c;
}

// ---- párok: hasonló név (azonos lényeg, egyik tartalmazza a másikat, vagy kevés betű eltérés), azonos adószám-törzs
$parok = [];
$ids = array_keys($cegek);
foreach ($ids as $i => $a) {
    foreach (array_slice($ids, $i + 1) as $b) {
        $x = $cegek[$a];
        $y = $cegek[$b];
        $ok = [];
        if ($x['lenyeg'] !== '' && $x['lenyeg'] === $y['lenyeg']) {
            $ok[] = 'azonos név (cégforma / írásjel nélkül)';
        } elseif (mb_strlen($x['lenyeg']) >= 5 && mb_strlen($y['lenyeg']) >= 5) {
            $rx = str_replace(' ', '', $x['lenyeg']);
            $ry = str_replace(' ', '', $y['lenyeg']);
            $hossz = max(strlen($rx), strlen($ry));
            $lev = levenshtein($rx, $ry);
            if (str_contains($rx, $ry) || str_contains($ry, $rx)) {
                $ok[] = 'az egyik név a másikat tartalmazza';
            } elseif ($hossz > 0 && $lev <= max(2, (int)floor($hossz * 0.2))) {
                $ok[] = "hasonló név ($lev betű eltérés)";
            } else {
                $ex = explode(' ', $x['lenyeg']);
                $ey = explode(' ', $y['lenyeg']);
                if ($ex[0] === $ey[0] && mb_strlen($ex[0]) >= 4) {
                    $ok[] = 'azonos első szó';
                }
            }
        }
        if ($x['ado_torzs'] !== null && $x['ado_torzs'] === $y['ado_torzs']) {
            $ok[] = 'azonos adószám-törzs';
        }
        if ($ok) {
            $parok["$a-$b"] = ['a' => $a, 'b' => $b, 'ok' => $ok, 'ib' => []];
        }
    }
}

// ---- IB-bizonyíték: egy IB-partner (partnerkód) számlái több cégnél
$ibPartner = [];
if ($ibFajl !== null && is_file($ibFajl)) {
    $e = json_decode((string)file_get_contents($ibFajl), true);
    $lista = array_merge($e['elter'] ?? [], $e['osszevonas'] ?? []);
    // a „letezik” (egyező) sorok nincsenek az elemzésben külön – a cégcsoport → cég párosítás adja a fő céget
    foreach ($e['cegcsoportok'] ?? [] as $cs) {
        if (($cs['cel']['tipus'] ?? '') === 'db') {
            $ibPartner[$cs['partnerkod'] . ' ' . implode(' / ', $cs['nevek'])]['fo'] = (int)$cs['cel']['ceg_id'];
        }
    }
    $nevDb = [];
    foreach ($e['elter'] ?? [] as $s) {
        foreach ($s['figyelmeztetes'] ?? [] as $f) {
            if (preg_match('/másik cégnél szerepel: (.+?) – /u', $f, $m)) {
                $nevDb[$s['ceg']][] = ['szamla' => $s['szamlaszam'], 'db_ceg' => $m[1]];
            }
        }
    }
    foreach ($nevDb as $ibNev => $v) {
        $ibPartner["IB: $ibNev"]['mas_cegnel'] = $v;
    }
}

// ---- jelentés
$sorok = [];
$leiras = static function (array $c): string {
    return sprintf('#%d „%s” · P%s · adószám %s · DOS: %s · %d kötés, %d bejövő, %d kimenő, %d utalás', $c['id'], $c['nev'], $c['partnerkod'] ?? '–',
        $c['adoszam'] ?? '–', implode(', ', $c['dos_kodok']) ?: '–', $c['kotes'], $c['bejovo'], $c['kimeno'], $c['utalas']);
};
$sorok[] = 'ÖSSZEVONÁSRA JELÖLT CÉGPÁROK (' . count($parok) . ') – ' . DB_NAME . ', ' . date('Y-m-d H:i');
$sorok[] = str_repeat('=', 100);
$n = 0;
foreach ($parok as $p) {
    $n++;
    $sorok[] = sprintf('%3d. %s', $n, implode(' + ', $p['ok']));
    $sorok[] = '     A: ' . $leiras($cegek[$p['a']]);
    $sorok[] = '     B: ' . $leiras($cegek[$p['b']]);
}
if ($ibPartner) {
    $sorok[] = '';
    $sorok[] = 'IB SYSTEM: SZÁMLÁK MÁS CÉGNÉL, MINT AZ IB-PARTNER CÉGE';
    $sorok[] = str_repeat('=', 100);
    foreach ($ibPartner as $k => $v) {
        if (!empty($v['mas_cegnel'])) {
            $sorok[] = "  $k: " . implode('; ', array_map(fn($x) => "{$x['szamla']} → a DOS-ban: {$x['db_ceg']}", $v['mas_cegnel']));
        }
    }
}
file_put_contents("$ki/ceg_hasonlo.txt", implode("\n", $sorok) . "\n");
file_put_contents("$ki/ceg_hasonlo.json", json_encode(['parok' => array_values(array_map(fn($p) => $p + ['a_ceg' => $cegek[$p['a']], 'b_ceg' => $cegek[$p['b']]], $parok)),
    'ib' => $ibPartner], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo implode("\n", $sorok) . "\n";
