<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/ids.php';
require_once __DIR__ . '/xlsx.php';

/**
 * BaninaPRO – régi adatok importja Excel (.xlsx) számlanaplóból.
 *
 * Oszlopok (a könyvelőprogram „Számla napló” exportja szerint):
 *   A  irány: BE / BH (bejövő EUR / HUF), KE / KH (kimenő EUR / HUF)
 *   B  tárgyév          C  sorszám (kimenőnél a számlaszám része)
 *   D  eredeti bizonylatszám = bejövő számla számlaszáma (szabad szöveg)
 *   J  számla kelte     K  teljesítés     M  fizetési határidő
 *   N  partnerkód       O  partner (cég) neve      P  partner adószám
 *   Z  bruttó összeg devizában             AA deviza kód (ellenőrzés)     AB megjegyzés
 *   Kimenő számlaszám: A-C/B  (pl. KE-41/2026)
 *
 * 1) import_elemez(): a fájl beolvasása, minden sor ellenőrzése, cégek egyeztetése (partnerkód → név),
 *    számlaszám-ütközések felderítése (adatbázis + fájl). Semmit nem ír az adatbázisba.
 * 2) import_vegrehajt(): a kiválasztott sorok rögzítése egy tranzakcióban; bejövő számlák cégenként /
 *    pénznemenként / évenként egy ARCHÍV kötésbe kerülnek.
 */

const IMP_TIPUSOK = ['BE' => ['BEJOVO', 'EUR'], 'BH' => ['BEJOVO', 'HUF'], 'KE' => ['KIMENO', 'EUR'], 'KH' => ['KIMENO', 'HUF']];
const IMP_MAX_SOR = 20000;
const IMP_LEJARAT_MP = 7200;

/** Excel cellaérték → ÉÉÉÉ-HH-NN (szöveges és sorszámos dátumot is ért) */
function imp_datum($v): ?string
{
    if ($v === null || $v === '' || $v === false) {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return xlsx_serial_datum((float)$v);
    }
    $s = trim((string)$v);
    if (preg_match('/^(\d{4})[.\-\/ ]+(\d{1,2})[.\-\/ ]+(\d{1,2})\.?$/u', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})\.?$/u', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[3], (int)$m[2], (int)$m[1]];
    } elseif (is_numeric($s)) {
        return xlsx_serial_datum((float)$s);
    } else {
        return null;
    }
    if (!checkdate($mo, $d, $y) || $y < 1990 || $y > 2100) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Excel cellaérték → összeg két tizedessel (string), vagy null ha érvénytelen */
function imp_osszeg($v): ?string
{
    if ($v === null || $v === '' || $v === false) {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        if (!is_finite((float)$v) || abs((float)$v) > 9999999999999) {
            return null;
        }
        return number_format((float)$v, 2, '.', '');
    }
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' ', "'"], '', trim((string)$v));
    $s = str_replace(',', '.', $s);
    if (substr_count($s, '.') > 1) {
        $u = strrpos($s, '.');
        $s = str_replace('.', '', substr($s, 0, $u)) . substr($s, $u);
    }
    if (!preg_match('/^-?\d{1,13}(\.\d{1,4})?$/', $s)) {
        return null;
    }
    return number_format((float)$s, 2, '.', '');
}

/** Cégnév egyeztetéshez normalizálva (kisbetű, egy szóköz, végi pontok/vesszők nélkül) */
function imp_ceg_norm(string $nev): string
{
    $n = mb_strtolower(trim($nev), 'UTF-8');
    $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
    $n = rtrim($n, " .,;");
    return $n;
}

/** Cellaszöveg tisztítása */
function imp_szoveg($v, int $max): string
{
    if ($v === null || $v === false) {
        return '';
    }
    $s = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
    $s = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $s)) ?? $s);
    return mb_substr($s, 0, $max, 'UTF-8');
}

/** Partnerkód normalizálva ('' ha nincs vagy 0) */
function imp_partnerkod($v): string
{
    if ($v === null || $v === '' || $v === false) {
        return '';
    }
    if (is_float($v) && floor($v) == $v) {
        $v = (int)$v;
    }
    $s = trim((string)$v);
    if ($s === '' || preg_match('/^0+$/', $s)) {
        return '';
    }
    return mb_substr($s, 0, 32, 'UTF-8');
}

function import_tmp_dir(): string
{
    $d = sys_get_temp_dir();
    if (!is_dir($d) || !is_writable($d)) {
        $d = rtrim(BACKUP_DIR, '/') . '/.tmp';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
    }
    return rtrim($d, '/');
}

function import_tmp_ut(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        hiba('Érvénytelen import-azonosító.');
    }
    return import_tmp_dir() . '/baninapro_import_' . $token . '.json';
}

/** Elemzés elmentése (a munkamenethez kötve) → token */
function import_ment(array $elemzes): string
{
    // lejárt fájlok takarítása
    foreach (glob(import_tmp_dir() . '/baninapro_import_*.json') ?: [] as $f) {
        if (filemtime($f) < time() - IMP_LEJARAT_MP) {
            @unlink($f);
        }
    }
    $token = bin2hex(random_bytes(16));
    $csomag = ['session' => hash('sha256', session_id()), 'lejar' => time() + IMP_LEJARAT_MP, 'elemzes' => $elemzes];
    if (@file_put_contents(import_tmp_ut($token), json_encode($csomag, JSON_UNESCAPED_UNICODE)) === false) {
        hiba('Az import ideiglenes fájlja nem írható a szerveren.');
    }
    return $token;
}

function import_betolt(string $token): array
{
    $ut = import_tmp_ut($token);
    $j = is_file($ut) ? json_decode((string)file_get_contents($ut), true) : null;
    if (!is_array($j) || ($j['session'] ?? '') !== hash('sha256', session_id()) || ($j['lejar'] ?? 0) < time()) {
        hiba('Az elemzés lejárt vagy nem található – töltsd fel újra az Excel fájlt.', 'IMPORT_LEJART', [], 410);
    }
    return $j['elemzes'];
}

function import_torol(string $token): void
{
    @unlink(import_tmp_ut($token));
}

/** Import előtti automatikus DB-mentés kell-e (admin által kapcsolható; alapból igen) */
function import_mentes_kell(): bool
{
    $v = db_val('SELECT ertek FROM beallitasok WHERE kulcs = ?', ['import_mentes']);
    return $v === null || (string)$v !== '0';
}

/** Elemzés frissítése a token alatt (pl. cégcsoportok módosítása után) */
function import_frissit(string $token, array $elemzes): void
{
    $ut = import_tmp_ut($token);
    $j = is_file($ut) ? json_decode((string)file_get_contents($ut), true) : null;
    if (!is_array($j)) {
        hiba('Az elemzés lejárt vagy nem található – töltsd fel újra az Excel fájlt.', 'IMPORT_LEJART', [], 410);
    }
    $j['elemzes'] = $elemzes;
    @file_put_contents($ut, json_encode($j, JSON_UNESCAPED_UNICODE));
}

// ---------------------------------------------------------------------------
//  Cégnév-hasonlóság (a „felügyelt” cégegyeztetéshez)
// ---------------------------------------------------------------------------

const IMP_CEGFORMAK = ['kft', 'zrt', 'nyrt', 'bt', 'kkt', 'ev', 'bv', 'b.v', 'nv', 'n.v', 'gmbh', 'ag', 'srl', 's.r.l', 'sro', 's.r.o', 'sa', 's.a', 'sas',
    'ltd', 'llc', 'inc', 'co', 'kg', 'kd', 'k.d', 'spa', 's.p.a', 'scs', 'sca', 'sc', 'sl', 'slu', 'oy', 'ab', 'as', 'eood', 'ood', 'doo', 'd.o.o', 'plc', 'pty', 'sp', 'z.o.o', 'zoo', 'sa.', 'company', 'handel', 'soc', 'coop', 'agr'];

/** Általános, önmagukban keveset mondó szavak: egyezésük gyenge bizonyíték (Hanka Sped ≠ K-Sped) */
const IMP_ALTALANOS = ['trans', 'sped', 'spedition', 'transport', 'logistic', 'logistics', 'logistik', 'logisztika', 'logistika', 'cargo', 'fruit', 'fruits', 'frucht',
    'fruchtimport', 'fresh', 'food', 'foods', 'import', 'export', 'trade', 'trading', 'group', 'international', 'agro', 'agri', 'vegetables', 'kertesz', 'kerteszek',
    'market', 'marketing', 'services', 'service', 'system', 'systems', 'pool', 'melon', 'holland', 'europe', 'praha', 'wien'];

/** Ékezetek leképezése ASCII-ra (hasonlóság-számításhoz) */
function imp_ekezet_nelkul(string $s): string
{
    static $t = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ß' => 'ss',
        'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'ê' => 'e', 'ì' => 'i', 'î' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ù' => 'u', 'û' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ş' => 's', 'ș' => 's', 'ț' => 't', 'ţ' => 't', 'ă' => 'a', 'ł' => 'l', 'ń' => 'n', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'd', 'ě' => 'e', 'ř' => 'r', 'ů' => 'u'];
    return strtr($s, $t);
}

/** Erős normalizálás: kisbetű, ékezet nélkül, cégforma-szavak nélkül, csak betű+szám; visszaadja a tokeneket is */
function imp_ceg_kulcs(string $nev): array
{
    $n = imp_ekezet_nelkul(mb_strtolower(trim($nev), 'UTF-8'));
    // pontozott rövidítések egybe: d.o.o → doo, s.r.o. → sro, b.v. → bv, a.n. → an
    for ($i = 0; $i < 4; $i++) {
        $n2 = preg_replace('/(?<![\p{L}\p{N}])(\p{L})\.(?=\p{L})/u', '$1', $n) ?? $n;
        if ($n2 === $n) { break; }
        $n = $n2;
    }
    $n = str_replace(['&', '+'], ' ', $n);
    $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n) ?? $n;
    $tokenek = array_values(array_filter(explode(' ', trim($n)), fn($t) => $t !== ''));
    $lenyeg = array_values(array_filter($tokenek, fn($t) => !in_array($t, IMP_CEGFORMAK, true)));
    if (!$lenyeg) {
        $lenyeg = $tokenek;
    }
    // egybetűs tokenek (A.N., s.r.o. maradéka) a szavankénti egyeztetésben nem számítanak, ha van hosszabb szó
    $hosszabb = array_values(array_filter($lenyeg, fn($t) => strlen($t) > 1));
    return ['osszefuzve' => implode('', $lenyeg), 'tokenek' => $hosszabb ?: $lenyeg];
}

/**
 * Két cégnév hasonlósága 0–100 között.
 *  100: azonos (normalizálva) · 95: az egyik (≥4 karakter) benne van a másikban · 90: 2–3 betűs név egész szóként benne
 *  egyébként szavankénti egyezés: a rövidebb név minden szavához a másik név legjobban illő szava
 *  (azonos = 1, előtag ≥4 betű = 0,9, rövid előtag = 0,7, similar_text ≥ 80% = 0,8), százalékban.
 */
function imp_hasonlosag(string $a, string $b): int
{
    $ka = imp_ceg_kulcs($a);
    $kb = imp_ceg_kulcs($b);
    $x = $ka['osszefuzve'];
    $y = $kb['osszefuzve'];
    if ($x === '' || $y === '') {
        return 0;
    }
    if ($x === $y) {
        return 100;
    }
    [$rovid, $hosszu] = strlen($x) <= strlen($y) ? [$x, $y] : [$y, $x];
    if (strlen($rovid) >= 4 && str_contains($hosszu, $rovid)) {
        return 95;
    }
    if (strlen($rovid) <= 3) {
        $hosszuTok = strlen($x) <= strlen($y) ? $kb['tokenek'] : $ka['tokenek'];
        return in_array($rovid, $hosszuTok, true) ? 90 : 0;
    }
    [$ta, $tb] = count($ka['tokenek']) <= count($kb['tokenek']) ? [$ka['tokenek'], $kb['tokenek']] : [$kb['tokenek'], $ka['tokenek']];
    if (!$ta || !$tb) {
        return 0;
    }
    // elgépelés-szintű hasonlóság a teljes néven (ksped ~ kspeed), de csak ha az első szavuk is egyezik (nk ≠ kv)
    similar_text($x, $y, $teljesSz);
    if ($teljesSz >= 85) {
        $e1 = $ta[0];
        $e2 = $tb[0];
        $m = min(strlen($e1), strlen($e2));
        if ($e1 === $e2 || ($m >= 4 && (str_starts_with($e1, $e2) || str_starts_with($e2, $e1)))) {
            return (int)round($teljesSz);
        }
    }
    $ossz = 0.0;
    foreach ($ta as $t) {
        $legjobb = 0.0;
        $altalanos = in_array($t, IMP_ALTALANOS, true);
        foreach ($tb as $u) {
            if ($t === $u) {
                $legjobb = $altalanos ? 0.3 : 1.0;
                break;
            }
            $m = min(strlen($t), strlen($u));
            if ($altalanos) {
                // általános szó elgépelve (logisztika ~ logistika): gyenge egyezés
                if ($m >= 4) {
                    similar_text($t, $u, $sz);
                    if ($sz >= 80) { $legjobb = max($legjobb, 0.3); }
                }
                continue;
            }
            if ($m >= 2 && (str_starts_with($u, $t) || str_starts_with($t, $u))) {
                // előtag: erős, ha hosszú vagy a hosszabb szó nagy részét lefedi (natur/nature igen, euro/eurocirce nem)
                $legjobb = max($legjobb, ($m >= 6 || ($m >= 4 && $m / max(strlen($t), strlen($u)) >= 0.6)) ? 0.9 : 0.5);
                continue;
            }
            if ($m >= 3) {
                similar_text($t, $u, $sz);
                if ($sz >= 80) {
                    $legjobb = max($legjobb, 0.8);
                }
            }
        }
        $ossz += $legjobb;
    }
    $pont = (int)round(100 * $ossz / count($ta));
    // egyszavas név többszavas névvel: csak pontos szóegyezés számít erősnek (fruit ≠ FruitPro, sped ≠ Speed)
    if (count($ta) === 1 && count($tb) >= 2 && $ossz < 1.0) {
        $pont = min($pont, 50);
    }
    return $pont;
}

/**
 * A fájl cégneveinek csoportosítása + javaslat: partnerkód → pontos név → hasonló név (≥ IMP_HASONLO_HATAR)
 * → egyébként új cég. A fájlon belül a hasonló nevek egy csoportba kerülnek.
 */
const IMP_HASONLO_HATAR = 55;

function import_ceg_csoportok(array $nevek, array $cegekDb): array
{
    $kodTerkep = [];
    foreach ($cegekDb as $c) {
        if ($c['partnerkod'] !== null && $c['partnerkod'] !== '') {
            $kodTerkep[(string)$c['partnerkod']] = $c;
        }
    }
    usort($nevek, fn($a, $b) => ($b['sorok'] <=> $a['sorok']) ?: strcmp($a['nev'], $b['nev']));
    $csoportok = [];
    $dbCsoport = [];   // ceg_id → csoport index
    foreach ($nevek as $n) {
        // 1) partnerkód
        $javaslat = null;
        $jeloltek = [];
        if ($n['partnerkod'] !== '' && isset($kodTerkep[$n['partnerkod']])) {
            $c = $kodTerkep[$n['partnerkod']];
            $javaslat = ['tipus' => 'db', 'ceg_id' => (int)$c['id'], 'db_nev' => $c['nev'], 'pont' => 100, 'mod' => 'kod'];
        } else {
            foreach ($cegekDb as $c) {
                $p = imp_hasonlosag($n['nev'], $c['nev']);
                if ($p >= IMP_HASONLO_HATAR) {
                    $jeloltek[] = ['ceg_id' => (int)$c['id'], 'nev' => $c['nev'], 'pont' => $p];
                }
            }
            usort($jeloltek, fn($a, $b) => $b['pont'] <=> $a['pont']);
            $jeloltek = array_slice($jeloltek, 0, 5);
            if ($jeloltek) {
                $j = $jeloltek[0];
                $javaslat = ['tipus' => 'db', 'ceg_id' => $j['ceg_id'], 'db_nev' => $j['nev'], 'pont' => $j['pont'], 'mod' => $j['pont'] >= 95 ? 'nev' : 'hasonlo'];
            }
        }
        // fájlon belüli csoport keresése (erősebb fájlbeli egyezés megelőzi a bizonytalan DB-találatot)
        $talalt = null;
        $talaltPont = 0;
        foreach ($csoportok as $i => $cs) {
            if ($cs['cel']['tipus'] !== 'uj') {
                continue;
            }
            foreach ($cs['nevek'] as $v) {
                $p = imp_hasonlosag($n['nev'], $v);
                if ($p >= IMP_HASONLO_HATAR && $p > $talaltPont) {
                    $talalt = $i;
                    $talaltPont = $p;
                }
            }
        }
        if ($javaslat && $talalt !== null && $javaslat['mod'] === 'hasonlo' && $talaltPont > $javaslat['pont']) {
            $javaslat = null;
        }
        if ($javaslat) {
            $cid = $javaslat['ceg_id'];
            if (isset($dbCsoport[$cid])) {
                $cs = &$csoportok[$dbCsoport[$cid]];
                $cs['nevek'][] = $n['nev'];
                $cs['sorok'] += $n['sorok'];
                if ($javaslat['pont'] < $cs['javaslat']['pont']) {
                    $cs['bizonytalan'] = true;
                }
                if ($cs['partnerkod'] === '' && $n['partnerkod'] !== '') { $cs['partnerkod'] = $n['partnerkod']; }
                if ($cs['adoszam'] === '' && $n['adoszam'] !== '') { $cs['adoszam'] = $n['adoszam']; }
                unset($cs);
                continue;
            }
            $dbCsoport[$cid] = count($csoportok);
            $csoportok[] = ['id' => count($csoportok), 'nevek' => [$n['nev']], 'uj_nev' => $n['nev'], 'partnerkod' => $n['partnerkod'], 'adoszam' => $n['adoszam'],
                'cel' => ['tipus' => 'db', 'ceg_id' => $cid], 'javaslat' => $javaslat, 'jeloltek' => $jeloltek, 'sorok' => $n['sorok'], 'bizonytalan' => $javaslat['mod'] === 'hasonlo'];
            continue;
        }
        // 2) fájlon belüli új csoportok
        if ($talalt !== null) {
            $cs = &$csoportok[$talalt];
            $cs['nevek'][] = $n['nev'];
            $cs['sorok'] += $n['sorok'];
            if (mb_strlen($n['nev'], 'UTF-8') > mb_strlen($cs['uj_nev'], 'UTF-8')) {
                $cs['uj_nev'] = $n['nev'];
            }
            if ($talaltPont < 95) {
                $cs['bizonytalan'] = true;
            }
            if ($cs['partnerkod'] === '' && $n['partnerkod'] !== '') { $cs['partnerkod'] = $n['partnerkod']; }
            if ($cs['adoszam'] === '' && $n['adoszam'] !== '') { $cs['adoszam'] = $n['adoszam']; }
            unset($cs);
            continue;
        }
        $csoportok[] = ['id' => count($csoportok), 'nevek' => [$n['nev']], 'uj_nev' => $n['nev'], 'partnerkod' => $n['partnerkod'], 'adoszam' => $n['adoszam'],
            'cel' => ['tipus' => 'uj'], 'javaslat' => ['tipus' => 'uj', 'pont' => 0, 'mod' => 'uj'], 'jeloltek' => [], 'sorok' => $n['sorok'], 'bizonytalan' => false];
    }
    return $csoportok;
}

/**
 * A kliens által módosított csoportok érvényesítése (nevek ⊆ fájl nevei, cél: db/uj/csoport).
 * Egy csoport „csoport:<id>” célja beolvad a másikba.
 */
function import_csoportok_ellenoriz(array $uj, array $elemzes): array
{
    $nevek = [];
    foreach ($elemzes['nevek'] as $n) {
        $nevek[$n['nev']] = $n;
    }
    $dbIds = array_column($elemzes['db_cegek'], 'id');
    $ki = [];
    $lefedett = [];
    foreach ($uj as $cs) {
        if (!is_array($cs) || !is_array($cs['nevek'] ?? null)) {
            continue;
        }
        $csNevek = [];
        foreach ($cs['nevek'] as $v) {
            $v = (string)$v;
            if (isset($nevek[$v]) && !isset($lefedett[$v])) {
                $csNevek[] = $v;
                $lefedett[$v] = true;
            }
        }
        if (!$csNevek) {
            continue;
        }
        $cel = is_array($cs['cel'] ?? null) ? $cs['cel'] : ['tipus' => 'uj'];
        $tipus = (string)($cel['tipus'] ?? 'uj');
        if ($tipus === 'db') {
            $cid = (int)($cel['ceg_id'] ?? 0);
            if (!in_array($cid, $dbIds, true)) {
                hiba('Ismeretlen cég a cégegyeztetésben.');
            }
            $cel = ['tipus' => 'db', 'ceg_id' => $cid];
        } elseif ($tipus === 'csoport') {
            $cel = ['tipus' => 'csoport', 'id' => (int)($cel['id'] ?? -1)];
        } else {
            $cel = ['tipus' => 'uj'];
        }
        $ujNev = imp_szoveg($cs['uj_nev'] ?? $csNevek[0], 191);
        if ($ujNev === '') {
            $ujNev = $csNevek[0];
        }
        $sorok = 0;
        $pk = '';
        $adoszam = '';
        foreach ($csNevek as $v) {
            $sorok += (int)$nevek[$v]['sorok'];
            if ($pk === '' && $nevek[$v]['partnerkod'] !== '') { $pk = $nevek[$v]['partnerkod']; }
            if ($adoszam === '' && $nevek[$v]['adoszam'] !== '') { $adoszam = $nevek[$v]['adoszam']; }
        }
        $regi = null;
        foreach ($elemzes['csoportok'] as $r) {
            if ((int)$r['id'] === (int)($cs['id'] ?? -1)) { $regi = $r; break; }
        }
        $ki[] = ['id' => (int)($cs['id'] ?? count($ki)), 'nevek' => $csNevek, 'uj_nev' => $ujNev, 'partnerkod' => $pk, 'adoszam' => $adoszam, 'cel' => $cel,
            'javaslat' => $regi['javaslat'] ?? ['tipus' => 'uj', 'pont' => 0, 'mod' => 'uj'], 'jeloltek' => $regi['jeloltek'] ?? [],
            'sorok' => $sorok, 'bizonytalan' => array_key_exists('bizonytalan', $cs) ? (bool)$cs['bizonytalan'] : (bool)($regi['bizonytalan'] ?? false)];
    }
    // ki nem osztott nevek → saját új csoport
    $maxId = 0;
    foreach ($ki as $cs) { $maxId = max($maxId, $cs['id']); }
    foreach ($nevek as $v => $n) {
        if (!isset($lefedett[$v])) {
            $ki[] = ['id' => ++$maxId, 'nevek' => [$v], 'uj_nev' => $v, 'partnerkod' => $n['partnerkod'], 'adoszam' => $n['adoszam'], 'cel' => ['tipus' => 'uj'],
                'javaslat' => ['tipus' => 'uj', 'pont' => 0, 'mod' => 'uj'], 'jeloltek' => [], 'sorok' => (int)$n['sorok'], 'bizonytalan' => false];
        }
    }
    // csoport→csoport hivatkozás feloldása (egy szint)
    $idIndex = [];
    foreach ($ki as $i => $cs) { $idIndex[$cs['id']] = $i; }
    foreach ($ki as $i => $cs) {
        if ($cs['cel']['tipus'] === 'csoport') {
            $cel = $cs['cel']['id'];
            if (!isset($idIndex[$cel]) || $idIndex[$cel] === $i || $ki[$idIndex[$cel]]['cel']['tipus'] === 'csoport') {
                $ki[$i]['cel'] = ['tipus' => 'uj'];
            }
        }
    }
    return $ki;
}

/** Egy csoport „cég-identitása” (db:<id> vagy uj:<csoport id>) – csoport-hivatkozás feloldva */
function imp_csoport_identitas(array $cs, array $csoportokById): string
{
    if ($cs['cel']['tipus'] === 'csoport' && isset($csoportokById[$cs['cel']['id']])) {
        $cs = $csoportokById[$cs['cel']['id']];
    }
    return $cs['cel']['tipus'] === 'db' ? 'db:' . $cs['cel']['ceg_id'] : 'uj:' . $cs['id'];
}

// ---------------------------------------------------------------------------
//  Elemzés
// ---------------------------------------------------------------------------

/** Fájlformátum felismerése az első sorok alapján */
function import_formatum(array $sorok): ?string
{
    $n = 0;
    foreach ($sorok as $c) {
        $a = mb_strtoupper(imp_szoveg($c['A'] ?? null, 30), 'UTF-8');
        if (isset(IMP_TIPUSOK[$a]) || $a === 'SZÁMLAKÖNYV') {
            return 'szamlanaplo';
        }
        $b = mb_strtoupper(imp_szoveg($c['B'] ?? null, 30), 'UTF-8');
        $g = mb_strtoupper(imp_szoveg($c['G'] ?? null, 30), 'UTF-8');
        if (str_starts_with($a, 'KÖTÉS') || $b === 'KISSZÁM' || $g === 'SZÁMLASZÁM') {
            return 'koteskonyv';
        }
        if (preg_match('/^\d{1,3}[A-Z]\d$/', $b) && ($c['G'] ?? null) !== null) {
            return 'koteskonyv';
        }
        if (++$n >= 30) {
            break;
        }
    }
    return null;
}

/**
 * Fájl elemzése (nyers sorok + cégnevek + csoport-javaslatok). Nem ír az adatbázisba.
 */
function import_elemez(string $fajl, string $fajlnev): array
{
    $x = xlsx_olvas($fajl, true, IMP_MAX_SOR + 20);
    $lap = $x['lapok'][0];
    $formatum = import_formatum($lap['sorok']);
    if ($formatum === null) {
        throw new XlsxHiba('Nem ismerem fel a fájl felépítését. Számlanapló: az A oszlopban BE/BH/KE/KH. Kötéskönyv: A régi kötés ID, B régi K azonosító, C cégnév, G számlaszám.');
    }
    $cegekDb = db_all('SELECT id, nev, partnerkod, adoszam FROM cegek ORDER BY nev');
    foreach ($cegekDb as &$c) {
        $c['id'] = (int)$c['id'];
    }
    unset($c);

    $nyers = [];
    $nevek = [];
    $tipusDb = ['BE' => 0, 'BH' => 0, 'KE' => 0, 'KH' => 0];
    $kihagyott = 0;
    $fejlecSor = null;
    $osszes = 0;

    foreach ($lap['sorok'] as $sorSzam => $c) {
        $osszes++;
        if (count($nyers) >= IMP_MAX_SOR) {
            break;
        }
        $hibak = [];
        $figy = [];
        $sor = null;
        if ($formatum === 'szamlanaplo') {
            $a = mb_strtoupper(imp_szoveg($c['A'] ?? null, 20), 'UTF-8');
            if ($fejlecSor === null && $a === 'SZÁMLAKÖNYV') {
                $fejlecSor = $sorSzam;
                continue;
            }
            if (!isset(IMP_TIPUSOK[$a])) {
                $kihagyott++;
                continue;
            }
            [$irany, $penznem] = IMP_TIPUSOK[$a];
            $tipusDb[$a]++;
            $ev = (int)imp_szoveg($c['B'] ?? null, 8);
            $kelt = imp_datum($c['J'] ?? null);
            $telj = imp_datum($c['K'] ?? null);
            $hat = imp_datum($c['M'] ?? null);
            if (!$kelt) { $hibak[] = 'hiányzik vagy érvénytelen a számla kelte (J)'; }
            if (!$telj && $kelt) { $telj = $kelt; $figy[] = 'teljesítés (K) hiányzik → a kelt dátumot kapja'; }
            if (!$hat && $kelt) { $hat = $kelt; $figy[] = 'fizetési határidő (M) hiányzik → a kelt dátumot kapja'; }
            if ($ev < 2000 || $ev > 2099) { $ev = $kelt ? (int)substr($kelt, 0, 4) : (int)date('Y'); $figy[] = 'tárgyév (B) hiányzik → ' . $ev; }
            $osszeg = imp_osszeg($c['Z'] ?? null);
            if ($osszeg === null) { $hibak[] = 'hiányzik vagy érvénytelen az összeg (Z)'; }
            elseif ((float)$osszeg == 0.0) { $hibak[] = 'az összeg nulla (Z)'; }
            elseif ((float)$osszeg < 0) { $figy[] = 'negatív összeg (jóváíró tétel)'; }
            $deviza = mb_strtoupper(imp_szoveg($c['AA'] ?? null, 8), 'UTF-8');
            if ($deviza === '') { $figy[] = 'deviza (AA) üres – az irány szerinti ' . $penznem; }
            elseif ($deviza !== $penznem) { $hibak[] = "a deviza (AA: $deviza) nem egyezik az irány szerinti pénznemmel ($a → $penznem)"; }
            $partnerkod = imp_partnerkod($c['N'] ?? null);
            $cegNev = imp_szoveg($c['O'] ?? null, 191);
            $adoszam = imp_szoveg($c['P'] ?? null, 32);
            if ($cegNev === '') { $hibak[] = 'hiányzik a cég neve (O)'; }
            if ($irany === 'BEJOVO') {
                $szamlaszam = imp_szoveg($c['D'] ?? null, 100);
                if ($szamlaszam === '') { $hibak[] = 'hiányzik a számlaszám (D)'; }
            } else {
                $sorsz = imp_szoveg($c['C'] ?? null, 20);
                if ($sorsz === '' || !preg_match('/^\d+$/', $sorsz)) { $hibak[] = 'hiányzik vagy nem szám a sorszám (C)'; $szamlaszam = ''; }
                else { $szamlaszam = "$a-" . (int)$sorsz . "/$ev"; }
            }
            $sor = ['tipus' => $a, 'irany' => $irany, 'penznem' => $penznem, 'ev' => $ev, 'szamlaszam' => $szamlaszam, 'kelt' => $kelt, 'teljesites' => $telj, 'hatarido' => $hat,
                'osszeg' => $osszeg, 'partnerkod' => $partnerkod, 'ceg_nev' => $cegNev, 'adoszam' => $adoszam, 'megjegyzes' => imp_szoveg($c['AB'] ?? null, 2000), 'beszam' => '',
                'regi_kotes' => '', 'regi_k' => '', 'kotes_megnevezes' => ''];
        } else {
            // KÖTÉSKÖNYV (csak bejövő): A régi kötés ID, B régi K azonosító, C cég, D megnevezés, E kelt, F teljesítés, G számlaszám, H EUR, I HUF, J BESZÁM
            $a = imp_szoveg($c['A'] ?? null, 100);
            $b = imp_szoveg($c['B'] ?? null, 50);
            $cegNev = imp_szoveg($c['C'] ?? null, 191);
            $g = $c['G'] ?? null;
            if ($fejlecSor === null && (str_starts_with(mb_strtoupper($a, 'UTF-8'), 'KÖTÉS') || mb_strtoupper($b, 'UTF-8') === 'KISSZÁM')) {
                $fejlecSor = $sorSzam;
                continue;
            }
            $hEUR = $c['H'] ?? null;
            $iHUF = $c['I'] ?? null;
            if ($cegNev === '' && ($g === null || $g === '') && ($hEUR === null || $hEUR === '') && ($iHUF === null || $iHUF === '') && ($c['E'] ?? null) === null) {
                $kihagyott++;   // előre sorszámozott üres sor
                continue;
            }
            if (is_float($g) && floor($g) == $g) { $g = (int)$g; }
            $szamlaszam = imp_szoveg($g, 100);
            if ($szamlaszam === '') { $hibak[] = 'hiányzik a számlaszám (G)'; }
            if ($cegNev === '') { $hibak[] = 'hiányzik a cég neve (C)'; }
            $kelt = imp_datum($c['E'] ?? null);
            $teljNyers = $c['F'] ?? null;
            $telj = imp_datum($teljNyers);
            if (!$kelt) { $hibak[] = 'hiányzik vagy érvénytelen a számla kelte (E)'; }
            $megjResz = [];
            if (!$telj && $kelt) {
                $telj = $kelt;
                $tSz = imp_szoveg($teljNyers, 60);
                $figy[] = $tSz !== '' ? "teljesítés (F: „{$tSz}”) nem dátum → a kelt dátumot kapja" : 'teljesítés (F) hiányzik → a kelt dátumot kapja';
                if ($tSz !== '') { $megjResz[] = 'Teljesítés: ' . $tSz; }
            }
            $eur = imp_osszeg($hEUR);
            $huf = imp_osszeg($iHUF);
            $iSz = imp_szoveg($iHUF, 100);
            if ($huf === null && $iSz !== '') {
                $figy[] = 'a HUF oszlopban (I) megjegyzés áll: „' . $iSz . '”';
                $megjResz[] = $iSz;
            }
            if ($eur !== null && (float)$eur != 0.0) {
                $penznem = 'EUR';
                $osszeg = $eur;
                if ($huf !== null && (float)$huf != 0.0) { $figy[] = 'EUR (H) és HUF (I) összeg is van → EUR-ként, a HUF érték a megjegyzésbe került'; $megjResz[] = 'HUF érték: ' . $huf; }
            } elseif ($huf !== null && (float)$huf != 0.0) {
                $penznem = 'HUF';
                $osszeg = $huf;
            } else {
                $penznem = 'EUR';
                $osszeg = null;
                $hibak[] = 'hiányzik az összeg (H: EUR vagy I: HUF)';
            }
            if ($osszeg !== null && (float)$osszeg < 0) { $figy[] = 'negatív összeg (jóváíró tétel)'; }
            $regiKotes = $a;
            if ($regiKotes === '') { $figy[] = 'nincs régi kötés ID (A) → az ARCHÍV kötésbe kerül'; }
            $tip = $penznem === 'EUR' ? 'BE' : 'BH';
            $tipusDb[$tip]++;
            $sor = ['tipus' => $tip, 'irany' => 'BEJOVO', 'penznem' => $penznem, 'ev' => $kelt ? (int)substr($kelt, 0, 4) : (int)date('Y'), 'szamlaszam' => $szamlaszam, 'kelt' => $kelt,
                'teljesites' => $telj, 'hatarido' => null, 'osszeg' => $osszeg, 'partnerkod' => '', 'ceg_nev' => $cegNev, 'adoszam' => '', 'megjegyzes' => implode(' · ', $megjResz),
                'beszam' => imp_szoveg($c['J'] ?? null, 191), 'regi_kotes' => $regiKotes, 'regi_k' => $b, 'kotes_megnevezes' => imp_szoveg($c['D'] ?? null, 191)];
        }
        $sor['i'] = count($nyers);
        $sor['sor'] = $sorSzam;
        $sor['hibak'] = $hibak;
        $sor['figyelmeztetes'] = $figy;
        $nyers[] = $sor;
        if ($sor['ceg_nev'] !== '') {
            $k = $sor['ceg_nev'];
            if (!isset($nevek[$k])) {
                $nevek[$k] = ['nev' => $k, 'partnerkod' => $sor['partnerkod'], 'adoszam' => $sor['adoszam'], 'sorok' => 0];
            }
            $nevek[$k]['sorok']++;
            if ($nevek[$k]['partnerkod'] === '' && $sor['partnerkod'] !== '') { $nevek[$k]['partnerkod'] = $sor['partnerkod']; }
            if ($nevek[$k]['adoszam'] === '' && $sor['adoszam'] !== '') { $nevek[$k]['adoszam'] = $sor['adoszam']; }
        }
    }
    $nevekLista = array_values($nevek);
    $csoportok = import_ceg_csoportok($nevekLista, $cegekDb);
    $el = [
        'fajl' => $fajlnev, 'lap' => $lap['nev'], 'formatum' => $formatum, 'fejlec_sor' => $fejlecSor, 'osszes_sor' => $osszes, 'kihagyott' => $kihagyott,
        'tipusok' => $tipusDb, 'nyers' => $nyers, 'nevek' => $nevekLista, 'csoportok' => $csoportok,
        'db_cegek' => array_map(fn($c) => ['id' => $c['id'], 'nev' => $c['nev'], 'partnerkod' => $c['partnerkod']], $cegekDb),
        'elemezve' => date('Y-m-d H:i:s'),
    ];
    return $el;
}

/**
 * Sorstátuszok kiszámítása az aktuális cégcsoportok szerint (adatbázis + fájlon belüli ütközések).
 * Visszaadja az elemzést kiegészítve: sorok, osszesites, ceg_osszesites, kotesek (kötéskönyvnél).
 */
function import_sorok_allapot(array $el): array
{
    $csById = [];
    foreach ($el['csoportok'] as $cs) {
        $csById[$cs['id']] = $cs;
    }
    $nevCsoport = [];
    foreach ($el['csoportok'] as $cs) {
        foreach ($cs['nevek'] as $v) {
            $nevCsoport[$v] = $cs['id'];
        }
    }
    $identitas = [];
    foreach ($el['csoportok'] as $cs) {
        $identitas[$cs['id']] = imp_csoport_identitas($cs, $csById);
    }
    // foglalt számlaszámok
    $foglalt = [];
    foreach (db_all('SELECT b.szamlaszam_norm AS n, b.kod, k.ceg_id, c.nev AS ceg_nev FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id') as $r) {
        $foglalt[$r['n']] = ['irany' => 'BEJOVO', 'kod' => $r['kod'], 'ceg_id' => (int)$r['ceg_id'], 'ceg_nev' => $r['ceg_nev']];
    }
    foreach (db_all('SELECT s.szamlaszam_norm AS n, s.kod, s.ceg_id, c.nev AS ceg_nev FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id') as $r) {
        $foglalt[$r['n']] = ['irany' => 'KIMENO', 'kod' => $r['kod'], 'ceg_id' => (int)$r['ceg_id'], 'ceg_nev' => $r['ceg_nev']];
    }
    // meglévő kötések régi ID szerint (kötéskönyvhöz)
    $regiKotesek = [];
    if ($el['formatum'] === 'koteskonyv') {
        foreach (db_all('SELECT id, kod, ceg_id, penznem, regi_kod FROM kotesek WHERE regi_kod IS NOT NULL AND regi_kod <> ""') as $k) {
            $regiKotesek[mb_strtolower($k['regi_kod'], 'UTF-8') . '|' . (int)$k['ceg_id'] . '|' . $k['penznem']] = ['id' => (int)$k['id'], 'kod' => $k['kod']];
        }
    }
    $sorok = [];
    $fajlNorm = [];
    $ossz = ['uj' => 0, 'letezik' => 0, 'utkozik' => 0, 'dupla' => 0, 'hiba' => 0, 'figyelmeztetes' => 0];
    $kotesek = [];   // kötéskönyv: régi ID|identitás|pénznem → info
    foreach ($el['nyers'] as $ny) {
        $s = $ny;
        $s['allapot'] = 'uj';
        $s['uzenet'] = '';
        $s['toldalekos'] = null;
        $s['toldalek_szabad'] = false;
        $s['letezo'] = null;
        $csId = $nevCsoport[$s['ceg_nev']] ?? null;
        $s['csoport_id'] = $csId;
        $ident = $csId !== null ? $identitas[$csId] : null;
        $cegId = $ident !== null && str_starts_with($ident, 'db:') ? (int)substr($ident, 3) : null;
        $s['ceg_id'] = $cegId;
        $s['kotes_cel'] = null;
        if ($s['figyelmeztetes']) {
            $ossz['figyelmeztetes']++;
        }
        if ($s['hibak']) {
            $s['allapot'] = 'hiba';
            $s['uzenet'] = implode('; ', $s['hibak']);
            $ossz['hiba']++;
            $sorok[] = $s;
            continue;
        }
        if ($el['formatum'] === 'koteskonyv') {
            if ($s['regi_kotes'] !== '') {
                $kk = mb_strtolower($s['regi_kotes'], 'UTF-8') . '|' . $ident . '|' . $s['penznem'];
                if (!isset($kotesek[$kk])) {
                    $meglevo = $cegId !== null ? ($regiKotesek[mb_strtolower($s['regi_kotes'], 'UTF-8') . '|' . $cegId . '|' . $s['penznem']] ?? null) : null;
                    $kotesek[$kk] = ['regi_kotes' => $s['regi_kotes'], 'ceg_nev' => $s['ceg_nev'], 'penznem' => $s['penznem'], 'meglevo' => $meglevo, 'megnevezes' => $s['kotes_megnevezes'], 'sorok' => 0];
                }
                $kotesek[$kk]['sorok']++;
                $s['kotes_cel'] = $kotesek[$kk]['meglevo'] ? 'meglévő kötés: ' . $kotesek[$kk]['meglevo']['kod'] : 'új kötés (régi ID: ' . $s['regi_kotes'] . ')';
            } else {
                $s['kotes_cel'] = 'ARCHÍV kötés';
            }
        }
        $norm = norm_azonosito($s['szamlaszam']);
        if ($norm === '') {
            $s['allapot'] = 'hiba';
            $s['uzenet'] = 'a számlaszámban nincs betű vagy szám';
            $ossz['hiba']++;
            $sorok[] = $s;
            continue;
        }
        $s['szamlaszam_norm'] = $norm;
        $utkozes = null;
        if (isset($foglalt[$norm])) {
            $f = $foglalt[$norm];
            if ($f['irany'] === $s['irany'] && $cegId !== null && $f['ceg_id'] === $cegId) {
                $s['allapot'] = 'letezik';
                $s['uzenet'] = 'már a rendszerben van: ' . $f['kod'];
                $s['letezo'] = $f;
                $ossz['letezik']++;
                $sorok[] = $s;
                continue;
            }
            $utkozes = 'a számlaszám már foglalt: ' . $f['kod'] . ' (' . $f['ceg_nev'] . ($f['irany'] === 'BEJOVO' ? ', bejövő' : ', kimenő') . ')';
            $s['letezo'] = $f;
        } elseif (isset($fajlNorm[$norm])) {
            $elozo = $sorok[$fajlNorm[$norm]];
            if (($elozo['csoport_id'] !== null && $elozo['csoport_id'] === $csId) && $elozo['irany'] === $s['irany']) {
                $s['allapot'] = 'dupla';
                $s['uzenet'] = 'duplikált sor a fájlban (ugyanez a számlaszám a ' . $elozo['sor'] . '. sorban, ugyanannál a cégnél)';
                $ossz['dupla']++;
                $sorok[] = $s;
                continue;
            }
            $utkozes = 'ugyanez a számlaszám a fájl ' . $elozo['sor'] . '. sorában is szerepel (' . $elozo['ceg_nev'] . ')';
        }
        if ($utkozes !== null) {
            $s['allapot'] = 'utkozik';
            $s['uzenet'] = $utkozes;
            $cs = $csId !== null ? $csById[$csId] : null;
            $tag = $s['partnerkod'] !== '' ? $s['partnerkod'] : mb_substr(preg_replace('/[^\p{L}\p{N} ]+/u', '', $cs ? $cs['uj_nev'] : $s['ceg_nev']) ?? '', 0, 14, 'UTF-8');
            $told = mb_substr($s['szamlaszam'] . ' [' . $tag . ']', 0, 100, 'UTF-8');
            $tn = norm_azonosito($told);
            $s['toldalekos'] = $told;
            if (isset($foglalt[$tn])) {
                $f = $foglalt[$tn];
                if ($f['irany'] === $s['irany'] && $cegId !== null && $f['ceg_id'] === $cegId) {
                    $s['allapot'] = 'letezik';
                    $s['uzenet'] = 'már a rendszerben van (toldalékos számlaszámmal): ' . $f['kod'];
                    $s['letezo'] = $f;
                    $ossz['letezik']++;
                    $sorok[] = $s;
                    continue;
                }
            } elseif (!isset($fajlNorm[$tn])) {
                $s['toldalek_szabad'] = true;
                $fajlNorm[$tn] = count($sorok);
            }
            $ossz['utkozik']++;
            $sorok[] = $s;
            continue;
        }
        $fajlNorm[$norm] = count($sorok);
        $ossz['uj']++;
        $sorok[] = $s;
    }
    $cegOssz = ['uj' => 0, 'letezik' => 0, 'bizonytalan' => 0];
    foreach ($el['csoportok'] as $cs) {
        $cegOssz[str_starts_with($identitas[$cs['id']], 'db:') ? 'letezik' : 'uj']++;
        if (!empty($cs['bizonytalan'])) {
            $cegOssz['bizonytalan']++;
        }
    }
    $el['sorok'] = $sorok;
    $el['osszesites'] = $ossz;
    $el['ceg_osszesites'] = $cegOssz;
    $el['kotesek_osszesites'] = ['db' => count($kotesek), 'meglevo' => count(array_filter($kotesek, fn($k) => $k['meglevo'] !== null))];
    return $el;
}

/** A kliensnek küldött elemzés (a nyers sorok nélkül, azok a szerveren maradnak) */
function import_kliens_nezet(array $el): array
{
    unset($el['nyers']);
    return $el;
}

// ---------------------------------------------------------------------------
//  Végrehajtás
// ---------------------------------------------------------------------------

/**
 * A kiválasztott sorok rögzítése (egy tranzakcióban).
 * $opciok: bejovo_statusz FIZETENDO|FIZETVE, kimeno_statusz NYITOTT|FIZETVE, utkozes kihagy|toldalek, hatarido_nap (kötéskönyv)
 */
function import_vegrehajt(array $el, array $kivalasztott, array $opciok, array $u): array
{
    $el = import_sorok_allapot($el);
    $bejovoSt = ($opciok['bejovo_statusz'] ?? 'FIZETENDO') === 'FIZETVE' ? 'FIZETVE' : 'FIZETENDO';
    $kimenoSt = ($opciok['kimeno_statusz'] ?? 'NYITOTT') === 'FIZETVE' ? 'FIZETVE' : 'NYITOTT';
    // ütköző számlaszám (más cégnél / más irányban már szerepel): enged = változatlanul rögzíti (1.9-től másik kötésben megengedett), kihagy, toldalek
    $utkozesMod = in_array($opciok['utkozes'] ?? 'enged', ['enged', 'kihagy', 'toldalek'], true) ? ($opciok['utkozes'] ?? 'enged') : 'enged';
    $toldalek = $utkozesMod === 'toldalek';
    $hataridoNap = max(0, min(365, (int)($opciok['hatarido_nap'] ?? 30)));
    $valasztott = array_fill_keys(array_map('intval', $kivalasztott), true);
    $csById = [];
    foreach ($el['csoportok'] as $cs) {
        $csById[$cs['id']] = $cs;
    }
    $ma = date('Y-m-d');
    $importJel = 'Excel import: ' . $el['fajl'] . ' (' . date('Y.m.d. H:i') . ', ' . $u['felhasznalonev'] . ')';
    $koteskonyv = $el['formatum'] === 'koteskonyv';

    $e = db_tx(function () use ($el, $valasztott, $bejovoSt, $kimenoSt, $toldalek, $utkozesMod, $hataridoNap, $csById, $u, $ma, $importJel, $koteskonyv) {
        $stat = ['bejovo' => 0, 'kimeno' => 0, 'uj_ceg' => 0, 'uj_kotes' => 0, 'uj_archiv' => 0, 'kihagyott' => 0, 'toldalekos' => 0, 'fizetve' => 0];
        $auditSorok = [];
        $cegId = [];       // identitás → id
        $cegNevek = [];
        $kotesek = [];     // kulcs → ['id','kod']
        $ujKotesek = [];
        $kihagyva = [];
        $kivonatId = null;
        $osszegek = [];
        foreach ($el['sorok'] as $s) {
            if (!isset($valasztott[$s['i']])) {
                continue;
            }
            if (in_array($s['allapot'], ['hiba', 'dupla', 'letezik'], true)) {
                $stat['kihagyott']++;
                continue;
            }
            $szamlaszam = $s['szamlaszam'];
            if ($s['allapot'] === 'utkozik' && $utkozesMod !== 'enged') {
                if (!$toldalek || !$s['toldalek_szabad'] || !$s['toldalekos']) {
                    $stat['kihagyott']++;
                    $kihagyva[] = ['sor' => $s['sor'], 'szamlaszam' => $s['szamlaszam'], 'ok' => 'ütköző számlaszám (kihagyva)'];
                    continue;
                }
                $szamlaszam = $s['toldalekos'];
            }
            // cég a csoport szerint
            $cs = $s['csoport_id'] !== null ? ($csById[$s['csoport_id']] ?? null) : null;
            if (!$cs) {
                $stat['kihagyott']++;
                $kihagyva[] = ['sor' => $s['sor'], 'szamlaszam' => $s['szamlaszam'], 'ok' => 'a cég nincs egyeztetve'];
                continue;
            }
            $ident = imp_csoport_identitas($cs, $csById);
            $celCs = $cs['cel']['tipus'] === 'csoport' && isset($csById[$cs['cel']['id']]) ? $csById[$cs['cel']['id']] : $cs;
            if (!isset($cegId[$ident])) {
                if (str_starts_with($ident, 'db:')) {
                    $cid = (int)substr($ident, 3);
                    $meg = db_row('SELECT id, nev, partnerkod, adoszam FROM cegek WHERE id = ?', [$cid]);
                    if (!$meg) {
                        hiba('A cégegyeztetésben megadott cég már nem létezik: #' . $cid);
                    }
                    $cegId[$ident] = $cid;
                    $cegNevek[$ident] = $meg['nev'];
                    if ($celCs['partnerkod'] !== '' && ($meg['partnerkod'] === null || $meg['partnerkod'] === '')) {
                        db_exec('UPDATE cegek SET partnerkod = ? WHERE id = ?', [$celCs['partnerkod'], $cid]);
                    }
                    if ($celCs['adoszam'] !== '' && ($meg['adoszam'] === null || $meg['adoszam'] === '')) {
                        db_exec('UPDATE cegek SET adoszam = ? WHERE id = ?', [$celCs['adoszam'], $cid]);
                    }
                } else {
                    $nev = $celCs['uj_nev'] !== '' ? $celCs['uj_nev'] : $s['ceg_nev'];
                    $meglevo = db_row('SELECT id, nev FROM cegek WHERE nev = ?', [$nev]);
                    if ($meglevo) {
                        $cegId[$ident] = (int)$meglevo['id'];
                        $cegNevek[$ident] = $meglevo['nev'];
                    } else {
                        $megj = $importJel . (count($celCs['nevek']) > 1 ? ' – névváltozatok: ' . implode(' | ', $celCs['nevek']) : '');
                        db_exec('INSERT INTO cegek (nev, adoszam, partnerkod, megjegyzes, letrehozta) VALUES (?, ?, ?, ?, ?)',
                            [$nev, $celCs['adoszam'] !== '' ? $celCs['adoszam'] : null, $celCs['partnerkod'] !== '' ? $celCs['partnerkod'] : null, $megj, $u['id']]);
                        $cegId[$ident] = (int)db()->lastInsertId();
                        $cegNevek[$ident] = $nev;
                        $stat['uj_ceg']++;
                    }
                }
            }
            $cid = $cegId[$ident];
            if ($s['allapot'] === 'utkozik' && $toldalek) {
                $stat['toldalekos']++;
            }
            $megj = $s['megjegyzes'] !== '' ? $s['megjegyzes'] : null;
            if ($s['irany'] === 'BEJOVO') {
                $hatarido = $s['hatarido'] ?? date('Y-m-d', strtotime($s['kelt'] . " +$hataridoNap days"));
                if ($koteskonyv && $s['regi_kotes'] !== '') {
                    $kk = 'R|' . mb_strtolower($s['regi_kotes'], 'UTF-8') . "|$cid|{$s['penznem']}";
                    if (!isset($kotesek[$kk])) {
                        $k = db_row('SELECT id, kod FROM kotesek WHERE ceg_id = ? AND penznem = ? AND LOWER(regi_kod) = LOWER(?) AND archiv = 0 ORDER BY id LIMIT 1 FOR UPDATE', [$cid, $s['penznem'], $s['regi_kotes']]);
                        if (!$k) {
                            $ev = (int)$s['ev'];
                            $n = kovetkezo_sorszam('KOTES', $ev, $s['penznem']);
                            $kod = kotes_kod($ev, $s['penznem'], $n);
                            db_exec('INSERT INTO kotesek (ceg_id, ev, penznem, sorszam, kod, megnevezes, megjegyzes, archiv, regi_kod, letrehozta) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
                                [$cid, $ev, $s['penznem'], $n, $kod, $s['kotes_megnevezes'] !== '' ? $s['kotes_megnevezes'] : null, $importJel, $s['regi_kotes'], $u['id']]);
                            $k = ['id' => (int)db()->lastInsertId(), 'kod' => $kod];
                            $auditSorok[] = ['KOTES', $k['id'], null, 'IMPORT', "Excel importból létrehozva (kötéskönyv): $kod – régi ID: {$s['regi_kotes']} ({$el['fajl']})", []];
                            $stat['uj_kotes']++;
                            $ujKotesek[] = ['id' => $k['id'], 'kod' => $kod, 'ceg_id' => $cid, 'ceg_nev' => $cegNevek[$ident], 'penznem' => $s['penznem'], 'regi_kod' => $s['regi_kotes']];
                        }
                        $kotesek[$kk] = ['id' => (int)$k['id'], 'kod' => $k['kod']];
                    }
                } else {
                    $kk = "A|$cid|{$s['penznem']}|{$s['ev']}";
                    if (!isset($kotesek[$kk])) {
                        $k = db_row('SELECT id, kod FROM kotesek WHERE ceg_id = ? AND penznem = ? AND ev = ? AND archiv = 1 ORDER BY id LIMIT 1 FOR UPDATE', [$cid, $s['penznem'], $s['ev']]);
                        if (!$k) {
                            $n = kovetkezo_sorszam('KOTES', (int)$s['ev'], $s['penznem']);
                            $kod = kotes_kod((int)$s['ev'], $s['penznem'], $n);
                            db_exec('INSERT INTO kotesek (ceg_id, ev, penznem, sorszam, kod, megnevezes, megjegyzes, archiv, letrehozta) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
                                [$cid, (int)$s['ev'], $s['penznem'], $n, $kod, 'ARCHÍV – Excel import ' . $s['ev'], $importJel, $u['id']]);
                            $k = ['id' => (int)db()->lastInsertId(), 'kod' => $kod];
                            $auditSorok[] = ['KOTES', $k['id'], null, 'IMPORT', "ARCHÍV kötés létrehozva az Excel importból: $kod ({$el['fajl']})", []];
                            $stat['uj_archiv']++;
                            $ujKotesek[] = ['id' => $k['id'], 'kod' => $kod, 'ceg_id' => $cid, 'ceg_nev' => $cegNevek[$ident], 'penznem' => $s['penznem'], 'regi_kod' => null];
                        }
                        $kotesek[$kk] = ['id' => (int)$k['id'], 'kod' => $k['kod']];
                    }
                }
                $kt = $kotesek[$kk];
                // egy kötésen belül egy számlaszám csak egyszer (időközben rögzített / ugyanabba a kötésbe kerülő sor)
                $t = szamlaszam_kotesben_foglalt($szamlaszam, $kt['id']);
                if ($t !== null) {
                    $stat['kihagyott']++;
                    $kihagyva[] = ['sor' => $s['sor'], 'szamlaszam' => $szamlaszam, 'ok' => 'ebben a kötésben már szerepel: ' . $t['kod']];
                    continue;
                }
                $ksz = kovetkezo_k_sorszam($kt['id']);
                $ujKod = szamla_kod($kt['kod'], $ksz);
                db_exec(
                    'INSERT INTO bejovo_szamlak (kotes_id, k_sorszam, kod, szamlaszam, szamlaszam_norm, teljesites_datum, kelt, osszeg, beszam, fizetesi_hatarido, statusz, megjegyzes, regi_k, letrehozta)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$kt['id'], $ksz, $ujKod, $szamlaszam, norm_azonosito($szamlaszam), $s['teljesites'], $s['kelt'], $s['osszeg'],
                     $s['beszam'] !== '' ? $s['beszam'] : null, $hatarido, ($bejovoSt === 'FIZETVE' && (float)$s['osszeg'] < 0) ? 'BESZAMITVA' : $bejovoSt, $megj,
                     $s['regi_k'] !== '' ? $s['regi_k'] : null, $u['id']]
                );
                $auditSorok[] = ['BEJOVO', (int)db()->lastInsertId(), $kt['id'], 'IMPORT', "Excel importból létrehozva: $ujKod „{$szamlaszam}” (" . fmt_osszeg($s['osszeg']) . " {$s['penznem']}, {$el['fajl']})", []];
                $stat['bejovo']++;
                if ($bejovoSt === 'FIZETVE') {
                    $stat['fizetve']++;
                }
            } else {
                $n = kovetkezo_sorszam('KIMENO', (int)$s['ev'], $s['penznem']);
                $kod = kimeno_kod((int)$s['ev'], $s['penznem'], $n);
                $fizetve = $kimenoSt === 'FIZETVE';
                $t = szamlaszam_kimeno_foglalt($szamlaszam);
                if ($t !== null) {
                    $stat['kihagyott']++;
                    $kihagyva[] = ['sor' => $s['sor'], 'szamlaszam' => $szamlaszam, 'ok' => 'kimenő számlaszám már szerepel: ' . $t['kod']];
                    continue;
                }
                if ($fizetve && $kivonatId === null) {
                    $az = 'IMPORT-' . date('Ymd-Hi');
                    $norm = norm_azonosito($az);
                    $kiv = db_row('SELECT id FROM banki_kivonatok WHERE azonosito_norm = ?', [$norm]);
                    if ($kiv) {
                        $kivonatId = (int)$kiv['id'];
                    } else {
                        db_exec('INSERT INTO banki_kivonatok (azonosito, azonosito_norm, datum, letrehozta) VALUES (?, ?, ?, ?)', [$az, $norm, $ma, $u['id']]);
                        $kivonatId = (int)db()->lastInsertId();
                    }
                }
                db_exec(
                    'INSERT INTO kimeno_szamlak (ceg_id, ev, penznem, sorszam, kod, szamlaszam, szamlaszam_norm, teljesites_datum, kelt, fizetesi_hatarido, osszeg, statusz, banki_kivonat_id, fizetve_datum, megjegyzes, letrehozta)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$cid, (int)$s['ev'], $s['penznem'], $n, $kod, $szamlaszam, norm_azonosito($szamlaszam), $s['teljesites'], $s['kelt'], $s['hatarido'], $s['osszeg'],
                     $fizetve ? 'FIZETVE' : 'NYITOTT', $fizetve ? $kivonatId : null, $fizetve ? $s['hatarido'] : null, $megj, $u['id']]
                );
                $auditSorok[] = ['KIMENO', (int)db()->lastInsertId(), null, 'IMPORT', "Excel importból létrehozva: $kod „{$szamlaszam}” (" . fmt_osszeg($s['osszeg']) . " {$s['penznem']}, {$el['fajl']})", []];
                $stat['kimeno']++;
                if ($fizetve) {
                    $stat['fizetve']++;
                }
            }
            $ok = $s['irany'] . ' ' . $s['penznem'];
            $osszegek[$ok] = ($osszegek[$ok] ?? 0) + (float)$s['osszeg'];
        }
        audit_ir_tobb($auditSorok, $u);
        return ['stat' => $stat, 'uj_kotesek' => $ujKotesek, 'kihagyva' => $kihagyva, 'osszegek' => $osszegek, 'cegek' => count($cegId)];
    });

    $st = $e['stat'];
    $oss = [];
    foreach ($e['osszegek'] as $k => $v) {
        $oss[] = "$k " . fmt_osszeg($v);
    }
    naplo('EXCEL_IMPORT', "{$el['fajl']} ({$el['formatum']}): {$st['bejovo']} bejövő + {$st['kimeno']} kimenő számla rögzítve ({$st['uj_ceg']} új cég, {$st['uj_kotes']} új kötés régi ID-val, {$st['uj_archiv']} új archív kötés, {$st['toldalekos']} toldalékos számlaszám, {$st['kihagyott']} kihagyva; bejövő státusz: $bejovoSt, kimenő: $kimenoSt" . ($koteskonyv ? ", határidő: kelt + $hataridoNap nap" : '') . ')' . ($oss ? ' – ' . implode(', ', $oss) : ''));
    return $e;
}

/**
 * Régi kötés-azonosító használata: mely kötések használják már (a számláikkal)?
 */
function regi_kod_hasznalat(string $regiKod, ?int $kiveveKotes = null): array
{
    $rk = trim($regiKod);
    if ($rk === '') {
        return [];
    }
    $k = db_all('SELECT k.id, k.kod, k.regi_kod, k.megnevezes, k.penznem, c.nev AS ceg_nev FROM kotesek k JOIN cegek c ON c.id = k.ceg_id WHERE LOWER(k.regi_kod) = LOWER(?)' . ($kiveveKotes ? ' AND k.id <> ?' : '') . ' ORDER BY k.kod', $kiveveKotes ? [$rk, $kiveveKotes] : [$rk]);
    foreach ($k as &$x) {
        $x['id'] = (int)$x['id'];
        $x['szamlak'] = db_all('SELECT id, kod, szamlaszam, osszeg, statusz FROM bejovo_szamlak WHERE kotes_id = ? ORDER BY k_sorszam', [$x['id']]);
        foreach ($x['szamlak'] as &$sz) {
            $sz['id'] = (int)$sz['id'];
            $sz['osszeg'] = (float)$sz['osszeg'];
        }
        unset($sz);
    }
    unset($x);
    return $k;
}
