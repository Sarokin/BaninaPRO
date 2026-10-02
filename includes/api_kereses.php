<?php
declare(strict_types=1);

/**
 * BaninaPRO – főoldali multifunkciós kereső
 *
 * act_kereses {q}            → találatok típusonként: bejövő számla, kimenő számla, cég, kötés, utalás
 * act_kereses_ugras {q,tipus,id,cim} → naplózás: a felhasználó melyik találatra ugrott
 *
 * Mire keres (töredékre is, kis/nagybetű és ékezet nélkül is):
 *   - teljes rendszer-azonosító (2026-EUR-000001-K0001, 2026-EUR-000001, U-EUR-2026-000001)
 *   - szabadkezes számlaszám (2626335, 2611240 [ANTON DÜRBECK], 926301189365/2026) – elválasztók nélkül is
 *   - cégnév, partnerkód, adószám → a céghez ugrik
 *   - régi K azonosító (85V7), régi rendszerbeli kötés ID, BESZÁM, kötés megnevezése
 *   - banki azonosító (kimenő), banki hivatkozás (utalás)
 *   - pontos összeg: 55 EUR, 4003,20 EUR, 4 003,20, -120000 (pénznemmel vagy anélkül)
 *   - dátum: 2026.03.15, 2026-03-15, 2026.03. (hónap), 03.15 (nap, bármely év) – kelt / teljesítés / határidő / fizetés
 */

if (!function_exists('imp_ekezet_nelkul')) {
    require_once __DIR__ . '/import.php';
}

const KERESES_MIN_HOSSZ = 3;   // ennyi karaktertől keresünk
const KERESES_LIMIT = 8;       // típusonként ennyi találatot adunk vissza
const KERESES_NYERS = 80;      // típusonként ennyit kérünk le pontozás előtt

/** LIKE-minta: a felhasználói szöveg speciális jelei (%, _, \) szó szerint értendők */
function kereses_like(string $s): string
{
    return '%' . strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}

/** Összehasonlításhoz: kisbetű, ékezet nélkül, felesleges szóközök nélkül */
function kereses_hajt(string $s): string
{
    $s = imp_ekezet_nelkul(mb_strtolower(trim($s), 'UTF-8'));
    return preg_replace('/\s+/u', ' ', $s) ?? $s;
}

/**
 * Dátum-minta a keresőszóból.
 *  2026.03.15 / 2026-03-15 / 2026. 03. 15. / 2026/03/15  → ['teljes' => '2026-03-15']
 *  2026.03 / 2026-03 / 2026. 03.                         → ['like' => '2026-03-%']
 *  03.15 / 03-15 / 3.15                                   → ['like' => '%-03-15']
 */
function kereses_datum_minta(string $q): ?array
{
    $s = preg_replace('/\s+/u', '', $q) ?? '';
    if (preg_match('/^(\d{4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})\.?$/', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return ['teljes' => sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), 'szoveg' => sprintf('%04d.%02d.%02d.', $m[1], $m[2], $m[3])];
        }
        return null;
    }
    if (preg_match('/^(\d{4})[.\-\/](\d{1,2})\.?$/', $s, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 12) {
        return ['like' => sprintf('%04d-%02d-%%', $m[1], $m[2]), 'szoveg' => sprintf('%04d.%02d.', $m[1], $m[2])];
    }
    if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})\.?$/', $s, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 12 && (int)$m[2] >= 1 && (int)$m[2] <= 31) {
        return ['like' => sprintf('%%-%02d-%02d', $m[1], $m[2]), 'szoveg' => sprintf('%02d.%02d.', $m[1], $m[2])];
    }
    return null;
}

/**
 * Összeg-minta: "55 EUR", "4003,20 EUR", "4 003,20", "4.003,20 Ft", "-120000", "€ 55".
 * Több értelmezést is visszaad, ha a pont/vessző kétértelmű (1.000 → 1000 vagy 1.00).
 * → ['ertekek' => [float...], 'penznem' => 'EUR'|'HUF'|null]  vagy null
 */
function kereses_osszeg_minta(string $q): ?array
{
    $s = mb_strtoupper(trim($q), 'UTF-8');
    $penznem = null;
    if (preg_match('/^(.*?)\s*(EUR|€|HUF|FT|FORINT|EURO|EURÓ)\.?\s*$/u', $s, $m)) {
        $s = $m[1];
        $penznem = in_array($m[2], ['EUR', '€', 'EURO', 'EURÓ'], true) ? 'EUR' : 'HUF';
    } elseif (preg_match('/^(EUR|€|HUF|FT)\s*(.*)$/u', $s, $m)) {
        $s = $m[2];
        $penznem = in_array($m[1], ['EUR', '€'], true) ? 'EUR' : 'HUF';
    }
    $s = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $s) ?? '';
    $s = str_replace(['−', '–'], '-', $s);
    if ($s === '' || !preg_match('/^[+-]?[\d.,]+$/', $s) || !preg_match('/\d/', $s)) {
        return null;
    }
    $neg = str_starts_with($s, '-');
    $s = ltrim($s, '+-');
    $ertekek = [];
    $vesszo = strrpos($s, ',');
    $pont = strrpos($s, '.');
    if ($vesszo !== false && $pont !== false) {
        // amelyik hátrébb van, az a tizedes jel, a másik ezres elválasztó
        $tizedes = $vesszo > $pont ? ',' : '.';
        $ezres = $tizedes === ',' ? '.' : ',';
        $ertekek[] = str_replace($ezres, '', $s);
        $ertekek[count($ertekek) - 1] = str_replace($tizedes, '.', $ertekek[count($ertekek) - 1]);
    } elseif ($vesszo !== false) {
        $ertekek[] = str_replace(',', '.', $s);          // 4003,20 → 4003.20
        if (substr_count($s, ',') === 1 && strlen($s) - $vesszo - 1 === 3) {
            $ertekek[] = str_replace(',', '', $s);       // 4,003 → 4003 (ezres vessző)
        }
    } elseif ($pont !== false) {
        $ertekek[] = $s;                                 // 4003.20
        if (strlen($s) - $pont - 1 === 3) {
            $ertekek[] = str_replace('.', '', $s);       // 4.003 → 4003 (ezres pont)
        }
    } else {
        $ertekek[] = $s;
    }
    $ki = [];
    foreach ($ertekek as $e) {
        if (!is_numeric($e)) {
            continue;
        }
        $f = round((float)$e, 2);
        if ($neg) {
            $f = -$f;
        }
        $ki[] = $f;
    }
    if (!$ki) {
        return null;
    }
    return ['ertekek' => array_values(array_unique($ki, SORT_REGULAR)), 'penznem' => $penznem, 'negativ' => $neg];
}

/**
 * Egy találat pontja: 0 = pontos egyezés valamelyik kulcsmezőben, 1 = a mező így kezdődik,
 * 2 = tartalmazza, 3 = csak dátum/összeg egyezés. Visszaadja azt is, melyik mező talált.
 * $mezok: [cimke => érték]
 */
function kereses_pont(array $mezok, string $qh, string $tomor): array
{
    $legjobb = 9;
    $mi = '';
    foreach ($mezok as $cimke => $ertek) {
        if ($ertek === null || $ertek === '') {
            continue;
        }
        $eh = kereses_hajt((string)$ertek);
        $p = 9;
        if ($eh === $qh) {
            $p = 0;
        } elseif (str_starts_with($eh, $qh)) {
            $p = 1;
        } elseif (str_contains($eh, $qh)) {
            $p = 2;
        } elseif ($tomor !== '' && str_contains(norm_azonosito((string)$ertek), $tomor)) {
            $p = norm_azonosito((string)$ertek) === $tomor ? 0 : 2;
        }
        if ($p < $legjobb) {
            $legjobb = $p;
            $mi = (string)$cimke;
        }
    }
    return [$legjobb, $mi];
}

/** Dátum- és összegfeltétel-darabok SQL-hez */
function kereses_extra_feltetel(?array $datum, ?array $osszeg, array $datumOszlopok, ?string $osszegOszlop, ?string $penznemOszlop, array &$p): array
{
    $w = [];
    if ($datum) {
        foreach ($datumOszlopok as $o) {
            if (isset($datum['teljes'])) {
                $w[] = "$o = ?";
                $p[] = $datum['teljes'];
            } else {
                $w[] = "$o LIKE ?";
                $p[] = $datum['like'];
            }
        }
    }
    if ($osszeg && $osszegOszlop) {
        $r = [];
        $hatralekOszlop = $osszegOszlop === 'b.osszeg' ? '(b.osszeg - COALESCE(rb.reszt, 0))' : ($osszegOszlop === 's.osszeg' ? '(s.osszeg - COALESCE(rk.reszt, 0))' : null);
        foreach ($osszeg['ertekek'] as $e) {
            if ($osszeg['negativ']) {
                $r[] = "$osszegOszlop = ?";
                $p[] = $e;
            } else {
                $r[] = "ABS($osszegOszlop) = ?";
                $p[] = abs($e);
                if ($hatralekOszlop) {
                    $r[] = "$hatralekOszlop = ?";
                    $p[] = abs($e);
                }
            }
        }
        $f = '(' . implode(' OR ', $r) . ')';
        if ($osszeg['penznem'] && $penznemOszlop) {
            $f = "($f AND $penznemOszlop = ?)";
            $p[] = $osszeg['penznem'];
        }
        $w[] = $f;
    }
    return $w;
}

/** Melyik dátummező egyezik (a találat magyarázatához) */
function kereses_datum_talalat(array $sor, array $mezok, ?array $datum): string
{
    if (!$datum) {
        return '';
    }
    foreach ($mezok as $oszlop => $cimke) {
        $v = (string)($sor[$oszlop] ?? '');
        if ($v === '') {
            continue;
        }
        if (isset($datum['teljes']) ? $v === $datum['teljes'] : (bool)preg_match('/^' . str_replace('%', '.*', preg_quote($datum['like'], '/')) . '$/', $v)) {
            return $cimke . ' ' . kereses_datum_hu($v);
        }
    }
    return '';
}

function kereses_datum_hu(?string $d): string
{
    if (!$d || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) {
        return (string)$d;
    }
    return "{$m[1]}.{$m[2]}.{$m[3]}.";
}

function kereses_osszeg_hu(float $n, string $penznem): string
{
    $s = number_format(abs($n), (abs($n) - floor(abs($n)) > 0.004) ? 2 : 0, ',', ' ');
    return ($n < 0 ? '−' : '') . $s . ($penznem !== '' ? ' ' . $penznem : '');
}

/** Összeg egyezik-e a keresett összeggel */
function kereses_osszeg_talalat(float $osszeg, string $penznem, ?array $minta): bool
{
    if (!$minta || ($minta['penznem'] && $minta['penznem'] !== $penznem)) {
        return false;
    }
    foreach ($minta['ertekek'] as $e) {
        if ($minta['negativ'] ? abs($osszeg - $e) < 0.005 : abs(abs($osszeg) - abs($e)) < 0.005) {
            return true;
        }
    }
    return false;
}

function act_kereses(array $be): array
{
    csak_bejelentkezve();
    $q = be_szoveg($be, 'q', 100, false, 'keresőszó') ?? '';
    $q = trim($q);
    if (mb_strlen($q, 'UTF-8') < KERESES_MIN_HOSSZ) {
        return ['q' => $q, 'min' => KERESES_MIN_HOSSZ, 'talalatok' => (object)[], 'osszes' => 0, 'ertelmezes' => ['datum' => null, 'osszeg' => null]];
    }
    $t0 = microtime(true);
    $like = kereses_like($q);
    $qh = kereses_hajt($q);
    $tomor = norm_azonosito($q);
    $tomorLike = $tomor !== '' && $tomor !== mb_strtoupper($q, 'UTF-8') ? kereses_like($tomor) : null;
    $datum = kereses_datum_minta($q);
    $osszeg = kereses_osszeg_minta($q);
    $talalatok = [];

    // ---------------------------------------------------------------- bejövő számlák
    $p = [$like, $like, $like, $like];
    $w = ['b.kod LIKE ?', 'b.szamlaszam LIKE ?', 'b.regi_k LIKE ?', 'b.beszam LIKE ?'];
    if ($tomorLike) {
        $w[] = 'b.szamlaszam_norm LIKE ?';
        $p[] = $tomorLike;
    }
    $w = array_merge($w, kereses_extra_feltetel($datum, $osszeg, ['b.kelt', 'b.teljesites_datum', 'b.fizetesi_hatarido'], 'b.osszeg', 'k.penznem', $p));
    $sorok = db_all(
        'SELECT b.id, b.kod, b.szamlaszam, b.osszeg, b.statusz, b.kelt, b.teljesites_datum, b.fizetesi_hatarido, b.regi_k, b.beszam,
                COALESCE(b.fizetve_datum, u.utalva_datum) AS fizetve_datum,
                k.id AS kotes_pk, k.kod AS kotes_kod, k.regi_kod, k.megnevezes AS kotes_megnevezes, k.penznem, k.archiv,
                c.id AS ceg_id, c.nev AS ceg_nev,' . RESZT_BEJOVO_MEZOK . '
           FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id LEFT JOIN utalasok u ON u.id = b.utalas_id' . RESZT_BEJOVO_JOIN . '
          WHERE ' . implode(' OR ', $w) . '
          ORDER BY b.kelt DESC, b.id DESC LIMIT ' . KERESES_NYERS,
        $p
    );
    $lista = [];
    foreach ($sorok as $s) {
        [$pont, $mi] = kereses_pont(['azonosító' => $s['kod'], 'számlaszám' => $s['szamlaszam'], 'régi K' => $s['regi_k'], 'BESZÁM' => $s['beszam']], $qh, $tomor);
        $talalat = $mi;
        if ($pont > 2) {
            $d = kereses_datum_talalat($s, ['kelt' => 'kelt', 'teljesites_datum' => 'teljesítés', 'fizetesi_hatarido' => 'határidő'], $datum);
            if ($d !== '') {
                $pont = 3;
                $talalat = $d;
            } elseif (kereses_osszeg_talalat((float)$s['osszeg'], $s['penznem'], $osszeg)) {
                $pont = 3;
                $talalat = 'összeg ' . kereses_osszeg_hu((float)$s['osszeg'], $s['penznem']);
            } elseif ((float)$s['reszt'] > 0 && kereses_osszeg_talalat((float)$s['hatralek'], $s['penznem'], $osszeg)) {
                $pont = 3;
                $talalat = 'hátralék ' . kereses_osszeg_hu((float)$s['hatralek'], $s['penznem']);
            }
        } elseif ($mi === 'régi K') {
            $talalat = 'régi K: ' . $s['regi_k'];
        } elseif ($mi === 'BESZÁM') {
            $talalat = 'BESZÁM: ' . $s['beszam'];
        }
        $lista[] = [
            'tipus' => 'bejovo', 'id' => (int)$s['id'], 'pont' => $pont, 'kelt' => $s['kelt'],
            'href' => "#/bejovo/ceg/{$s['ceg_id']}/kotes/{$s['kotes_pk']}?szamla={$s['id']}",
            'cim' => $s['kod'], 'szamlaszam' => $s['szamlaszam'], 'ceg_nev' => $s['ceg_nev'],
            'osszeg' => (float)$s['osszeg'], 'penznem' => $s['penznem'], 'statusz' => $s['statusz'],
            'reszt' => (float)$s['reszt'], 'hatralek' => (float)$s['hatralek'], 'fizetve_datum' => $s['fizetve_datum'],
            'datum' => $s['kelt'], 'regi_k' => $s['regi_k'], 'kotes_kod' => $s['kotes_kod'], 'archiv' => (int)$s['archiv'] === 1,
            'talalat' => $talalat,
        ];
    }
    $talalatok['bejovo'] = kereses_rendez($lista, count($sorok));

    // ---------------------------------------------------------------- kimenő számlák
    $p = [$like, $like, $like];
    $w = ['s.kod LIKE ?', 's.szamlaszam LIKE ?', 'bk.azonosito LIKE ?'];
    if ($tomorLike) {
        $w[] = 's.szamlaszam_norm LIKE ?';
        $p[] = $tomorLike;
        $w[] = 'bk.azonosito_norm LIKE ?';
        $p[] = $tomorLike;
    }
    $w = array_merge($w, kereses_extra_feltetel($datum, $osszeg, ['s.kelt', 's.teljesites_datum', 's.fizetesi_hatarido', 's.fizetve_datum'], 's.osszeg', 's.penznem', $p));
    $sorok = db_all(
        'SELECT s.id, s.kod, s.szamlaszam, s.osszeg, s.penznem, s.statusz, s.kelt, s.teljesites_datum, s.fizetesi_hatarido, s.fizetve_datum,
                c.id AS ceg_id, c.nev AS ceg_nev, bk.azonosito AS banki_azonosito,' . RESZT_KIMENO_MEZOK . '
           FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id LEFT JOIN banki_kivonatok bk ON bk.id = s.banki_kivonat_id' . RESZT_KIMENO_JOIN . '
          WHERE ' . implode(' OR ', $w) . '
          ORDER BY s.kelt DESC, s.id DESC LIMIT ' . KERESES_NYERS,
        $p
    );
    $lista = [];
    foreach ($sorok as $s) {
        [$pont, $mi] = kereses_pont(['azonosító' => $s['kod'], 'számlaszám' => $s['szamlaszam'], 'banki azonosító' => $s['banki_azonosito']], $qh, $tomor);
        $talalat = $mi;
        if ($pont > 2) {
            $d = kereses_datum_talalat($s, ['kelt' => 'kelt', 'teljesites_datum' => 'teljesítés', 'fizetesi_hatarido' => 'határidő', 'fizetve_datum' => 'fizetve'], $datum);
            if ($d !== '') {
                $pont = 3;
                $talalat = $d;
            } elseif (kereses_osszeg_talalat((float)$s['osszeg'], $s['penznem'], $osszeg)) {
                $pont = 3;
                $talalat = 'összeg ' . kereses_osszeg_hu((float)$s['osszeg'], $s['penznem']);
            } elseif ((float)$s['reszt'] > 0 && kereses_osszeg_talalat((float)$s['hatralek'], $s['penznem'], $osszeg)) {
                $pont = 3;
                $talalat = 'hátralék ' . kereses_osszeg_hu((float)$s['hatralek'], $s['penznem']);
            }
        } elseif ($mi === 'banki azonosító') {
            $talalat = 'banki azonosító: ' . $s['banki_azonosito'];
        }
        $lista[] = [
            'tipus' => 'kimeno', 'id' => (int)$s['id'], 'pont' => $pont, 'kelt' => $s['kelt'],
            'href' => "#/kimeno/ceg/{$s['ceg_id']}?szamla={$s['id']}",
            'cim' => $s['kod'], 'szamlaszam' => $s['szamlaszam'], 'ceg_nev' => $s['ceg_nev'],
            'osszeg' => (float)$s['osszeg'], 'penznem' => $s['penznem'], 'statusz' => $s['statusz'],
            'reszt' => (float)$s['reszt'], 'hatralek' => (float)$s['hatralek'], 'fizetve_datum' => $s['fizetve_datum'],
            'datum' => $s['kelt'], 'banki_azonosito' => $s['banki_azonosito'],
            'talalat' => $talalat,
        ];
    }
    $talalatok['kimeno'] = kereses_rendez($lista, count($sorok));

    // ---------------------------------------------------------------- cégek
    $p = [$like, $like, $like];
    $sorok = db_all(
        'SELECT c.id, c.nev, c.partnerkod, c.adoszam, c.aktiv,
                (SELECT COUNT(*) FROM kotesek k WHERE k.ceg_id = c.id) AS kotes_db,
                (SELECT COUNT(*) FROM kimeno_szamlak s WHERE s.ceg_id = c.id) AS kimeno_db
           FROM cegek c
          WHERE c.nev LIKE ? OR c.partnerkod LIKE ? OR c.adoszam LIKE ?
          ORDER BY c.nev LIMIT ' . KERESES_NYERS,
        $p
    );
    $lista = [];
    foreach ($sorok as $c) {
        [$pont, $mi] = kereses_pont(['név' => $c['nev'], 'partnerkód' => $c['partnerkod'], 'adószám' => $c['adoszam']], $qh, $tomor);
        $talalat = $mi === 'partnerkód' ? 'partnerkód: ' . $c['partnerkod'] : ($mi === 'adószám' ? 'adószám: ' . $c['adoszam'] : '');
        $alap = [
            'tipus' => 'ceg', 'id' => (int)$c['id'], 'pont' => $pont, 'kelt' => '',
            'cim' => $c['nev'], 'partnerkod' => $c['partnerkod'], 'aktiv' => (int)$c['aktiv'] === 1,
            'kotes_db' => (int)$c['kotes_db'], 'kimeno_db' => (int)$c['kimeno_db'], 'talalat' => $talalat,
        ];
        // bejövő oldal mindig; kimenő oldal csak ha van kimenő számlája
        $lista[] = $alap + ['irany' => 'bejovo', 'href' => "#/bejovo/ceg/{$c['id']}"];
        if ((int)$c['kimeno_db'] > 0) {
            $lista[] = $alap + ['irany' => 'kimeno', 'href' => "#/kimeno/ceg/{$c['id']}"];
        }
    }
    $talalatok['ceg'] = kereses_rendez($lista, count($sorok), KERESES_LIMIT * 2);
    $talalatok['ceg']['db'] = count($sorok); // cégek száma (nem a sorok száma)

    // ---------------------------------------------------------------- kötések
    $p = [$like, $like, $like];
    $sorok = db_all(
        'SELECT k.id, k.kod, k.regi_kod, k.megnevezes, k.penznem, k.archiv, k.ev, c.id AS ceg_id, c.nev AS ceg_nev,
                (SELECT COUNT(*) FROM bejovo_szamlak b WHERE b.kotes_id = k.id) AS szamla_db,
                (SELECT COALESCE(SUM(b.osszeg), 0) FROM bejovo_szamlak b WHERE b.kotes_id = k.id) AS osszeg
           FROM kotesek k JOIN cegek c ON c.id = k.ceg_id
          WHERE k.kod LIKE ? OR k.regi_kod LIKE ? OR k.megnevezes LIKE ?
          ORDER BY k.id DESC LIMIT ' . KERESES_NYERS,
        $p
    );
    $lista = [];
    foreach ($sorok as $k) {
        [$pont, $mi] = kereses_pont(['azonosító' => $k['kod'], 'régi kötés ID' => $k['regi_kod'], 'megnevezés' => $k['megnevezes']], $qh, $tomor);
        $talalat = $mi === 'régi kötés ID' ? 'régi kötés ID: ' . $k['regi_kod'] : ($mi === 'megnevezés' ? 'megnevezés' : '');
        $lista[] = [
            'tipus' => 'kotes', 'id' => (int)$k['id'], 'pont' => $pont, 'kelt' => (string)$k['ev'],
            'href' => "#/bejovo/ceg/{$k['ceg_id']}/kotes/{$k['id']}",
            'cim' => $k['kod'], 'megnevezes' => $k['megnevezes'], 'regi_kod' => $k['regi_kod'], 'ceg_nev' => $k['ceg_nev'],
            'penznem' => $k['penznem'], 'osszeg' => (float)$k['osszeg'], 'szamla_db' => (int)$k['szamla_db'], 'archiv' => (int)$k['archiv'] === 1,
            'talalat' => $talalat,
        ];
    }
    $talalatok['kotes'] = kereses_rendez($lista, count($sorok));

    // ---------------------------------------------------------------- utalások
    $p = [$like, $like];
    $w = ['u.uid LIKE ?', 'u.banki_hivatkozas LIKE ?'];
    if ($datum) {
        $w = array_merge($w, kereses_extra_feltetel($datum, null, ['u.utalva_datum'], null, null, $p));
    }
    $sorok = db_all(
        'SELECT u.id, u.uid, u.penznem, u.statusz, u.utalas_mod, u.utalva_datum, u.banki_hivatkozas, c.nev AS ceg_nev,
                (SELECT COUNT(*) FROM bejovo_szamlak b WHERE b.utalas_id = u.id) AS szamla_db,
                (SELECT COALESCE(SUM(b.osszeg), 0) FROM bejovo_szamlak b WHERE b.utalas_id = u.id) AS osszeg
           FROM utalasok u JOIN cegek c ON c.id = u.ceg_id
          WHERE ' . implode(' OR ', $w) . '
          ORDER BY u.id DESC LIMIT ' . KERESES_NYERS,
        $p
    );
    $lista = [];
    foreach ($sorok as $u) {
        [$pont, $mi] = kereses_pont(['azonosító' => $u['uid'], 'banki hivatkozás' => $u['banki_hivatkozas']], $qh, $tomor);
        $talalat = $mi === 'banki hivatkozás' ? 'banki hivatkozás: ' . $u['banki_hivatkozas'] : '';
        if ($pont > 2 && $datum) {
            $d = kereses_datum_talalat($u, ['utalva_datum' => 'utalva'], $datum);
            if ($d !== '') {
                $pont = 3;
                $talalat = $d;
            }
        }
        $lista[] = [
            'tipus' => 'utalas', 'id' => (int)$u['id'], 'pont' => $pont, 'kelt' => (string)$u['utalva_datum'],
            'href' => "#/utalas/{$u['id']}",
            'cim' => $u['uid'], 'ceg_nev' => $u['ceg_nev'], 'penznem' => $u['penznem'], 'statusz' => $u['statusz'],
            'osszeg' => (float)$u['osszeg'], 'szamla_db' => (int)$u['szamla_db'], 'utalva_datum' => $u['utalva_datum'],
            'talalat' => $talalat,
        ];
    }
    $talalatok['utalas'] = kereses_rendez($lista, count($sorok));

    $osszes = 0;
    foreach ($talalatok as $t) {
        $osszes += $t['db'];
    }
    // napló: csak az „új” keresések (a gépelés közbeni bővítéseket nem írjuk ki egyenként)
    $elozo = (string)($_SESSION['kereses_utolso'] ?? '');
    if ($elozo === '' || !str_starts_with($qh, kereses_hajt($elozo))) {
        naplo('KERESES', "„{$q}” → {$osszes} találat (" . round((microtime(true) - $t0) * 1000) . ' ms)');
    }
    $_SESSION['kereses_utolso'] = $q;

    return [
        'q' => $q, 'min' => KERESES_MIN_HOSSZ, 'talalatok' => $talalatok, 'osszes' => $osszes,
        'ertelmezes' => [
            'datum' => $datum['szoveg'] ?? null,
            'osszeg' => $osszeg ? (implode(' / ', array_map(fn($e) => kereses_osszeg_hu($e, $osszeg['penznem'] ?? ''), $osszeg['ertekek']))) : null,
        ],
    ];
}

/** Pontszám, majd dátum szerint csökkenő; a típusonkénti limitre vágva; jelzi, ha van több */
function kereses_rendez(array $lista, int $nyersDb, int $limit = KERESES_LIMIT): array
{
    usort($lista, function ($a, $b) {
        if ($a['pont'] !== $b['pont']) {
            return $a['pont'] <=> $b['pont'];
        }
        return strcmp((string)$b['kelt'], (string)$a['kelt']) ?: ($b['id'] <=> $a['id']);
    });
    $db = count($lista);
    $tobb = $nyersDb >= KERESES_NYERS; // a nyers lekérés is tele volt → biztosan van több
    if ($db > $limit) {
        $lista = array_slice($lista, 0, $limit);
        $tobb = true;
    }
    foreach ($lista as &$x) {
        unset($x['kelt']);
    }
    unset($x);
    return ['lista' => $lista, 'db' => $db, 'tobb' => $tobb];
}

/** A felhasználó rákattintott egy találatra – naplózzuk, hova ugrott */
function act_kereses_ugras(array $be): array
{
    csak_bejelentkezve();
    $q = be_szoveg($be, 'q', 100, false) ?? '';
    $tipus = be_szoveg($be, 'tipus', 20, false) ?? '';
    $id = be_int($be, 'id', false) ?? 0;
    $cim = be_szoveg($be, 'cim', 191, false) ?? '';
    naplo('KERESES_UGRAS', "„{$q}” → {$tipus} #{$id} {$cim}");
    return ['ok' => true];
}
