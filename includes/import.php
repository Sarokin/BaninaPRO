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
 * BaninaPRO – számlák importja és egyeztetése Excel (.xlsx) számlanaplóból.
 *
 * Oszlopok (a könyvelőprogram – IB System – „Számla napló” exportja szerint):
 *   A  irány: BE / BH (bejövő EUR / HUF), KE / KH (kimenő EUR / HUF)
 *   B  tárgyév          C  sorszám (kimenőnél a számlaszám része)
 *   D  eredeti bizonylatszám = bejövő számla számlaszáma (szabad szöveg)
 *   J  számla kelte     K  teljesítés     M  fizetési határidő
 *   N  partnerkód       O  partner (cég) neve      P  partner adószám      Q  partner EU adószám
 *   Z  bruttó összeg devizában             AA deviza kód (ellenőrzés)     AB megjegyzés
 *   Kimenő számlaszám: A-C/B  (pl. KE-41/2026)
 *
 * 1) import_elemez(): a fájl beolvasása, minden sor ellenőrzése, cégek egyeztetése (megjegyzett párosítás →
 *    partnerkód → a rendszerben már meglévő számláik → adószám → név). Semmit nem ír az adatbázisba.
 * 2) import_sorok_allapot(): minden sor megfeleltetése a rendszerben lévő számláknak. Ami megvan és egyezik:
 *    „letezik”; ami megvan, de az összege / dátuma más: „elter” (1.22) – ezt Irodavezető / Admin az Excel
 *    szerinti értékre javíthatja; számlaszám-ütközések (adatbázis + fájl).
 * 3) import_vegrehajt(): a kiválasztott új sorok rögzítése és az eltérő számlák javítása egy tranzakcióban;
 *    bejövő számlák cégenként / pénznemenként / évenként egy ARCHÍV kötésbe kerülnek.
 */

const IMP_TIPUSOK = ['BE' => ['BEJOVO', 'EUR'], 'BH' => ['BEJOVO', 'HUF'], 'KE' => ['KIMENO', 'EUR'], 'KH' => ['KIMENO', 'HUF']];
const IMP_MAX_SOR = 20000;
const IMP_LEJARAT_MP = 7200;
/** A meglévő számlákon az Excel alapján javítható mezők (oszlopnév → címke); a sorrend a megjelenítésé */
const IMP_JAVITHATO = ['osszeg' => 'Összeg', 'teljesites_datum' => 'Teljesítés', 'fizetesi_hatarido' => 'Fizetési határidő', 'kelt' => 'Kelt', 'szamlaszam' => 'Számlaszám'];
/** A megjegyzett partnerkód → cég párosítások helye a beallitasok táblában (JSON) */
const IMP_PAROSITAS_KULCS = 'import_parositas';

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

/**
 * Adószám egyeztető kulcsa ('' ha nem használható). A magyar adószám két alakja (12345678-2-41 és HU12345678)
 * ugyanazt a kulcsot adja; a „nincs”-jellegű beírások (számjegy nélkül) nem számítanak.
 */
function imp_adoszam_kulcs($v): string
{
    $n = norm_azonosito((string)($v ?? ''));
    if (preg_match('/^(?:HU)?(\d{8})(?:\d{3})?$/', $n, $m)) {
        return 'HU' . $m[1];
    }
    return (strlen($n) >= 6 && preg_match_all('/\d/', $n) >= 4) ? $n : '';
}

/** Számlaszám az import toldaléka („ [partnerkód]”) nélkül – így a toldalékkal rögzített számla is megtalálható */
function imp_szamlaszam_alap(string $szamlaszam): string
{
    return trim(preg_replace('/\s*\[[^\[\]]*\]\s*$/u', '', $szamlaszam) ?? $szamlaszam);
}

/**
 * A számlaszám „laza” kulcsa: mint norm_azonosito(), de a számcsoportok bevezető nulláit elhagyja – a DOS-ból átvett
 * „26/0001” és a könyvelőprogram „26/001” írásmódja így ugyanazt adja („261”). A „4/26” ↔ „4/2026” nem egyezik (más szám).
 */
function imp_laza_kulcs(string $szamlaszam): string
{
    // előbb a számcsoportok (még az elválasztókkal: „26/001”), csak utána a normalizálás – különben „26001” és „260001” lenne
    return norm_azonosito(preg_replace_callback('/\d+/', fn($m) => ltrim($m[0], '0') === '' ? '0' : ltrim($m[0], '0'), $szamlaszam) ?? '');
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
 * A fájl cégneveinek csoportosítása + javaslat: megjegyzett párosítás → partnerkód → a név számlái a rendszerben
 * (szavazat) → adószám → pontos név → hasonló név (≥ IMP_HASONLO_HATAR) → egyébként új cég.
 * A fájlon belül a hasonló nevek egy csoportba kerülnek.
 *   $parositas: a korábbi importokban jóváhagyott partnerkód (kód nélkül: név) → cég id párosítások (import_parositas_betolt)
 *   $n['szavazat']: cég id → a név hány számlája van már annál a cégnél; $n['adok']: adószám-kulcsok
 *   $biztos (kimenet): ugyanilyen kulcs → cég id, ahol a számlák egyértelműen megmutatták a céget – ezt a feltöltés megjegyzi,
 *     hogy a partner későbbi, még ismeretlen számlái is a jó céghez kerüljenek (a név szerinti párosítás tévedhet)
 */
const IMP_HASONLO_HATAR = 55;
/** Számlák szerinti cég: a találatok legalább ekkora hányada egy cégnél (%), és ennyitől számít biztosnak */
const IMP_SZAVAZAT_HATAR = 60;
const IMP_SZAVAZAT_BIZTOS = 90;

function import_ceg_csoportok(array $nevek, array $cegekDb, array $parositas = [], ?array &$biztos = null): array
{
    $biztos = [];
    $kodTerkep = [];
    $cegById = [];
    $adoTerkep = [];   // adószám-kulcs → cégek
    foreach ($cegekDb as $c) {
        $cegById[(int)$c['id']] = $c;
        if ($c['partnerkod'] !== null && $c['partnerkod'] !== '') {
            $kodTerkep[(string)$c['partnerkod']] = $c;
        }
        $ak = imp_adoszam_kulcs($c['adoszam'] ?? '');
        if ($ak !== '') {
            $adoTerkep[$ak][] = $c;
        }
    }
    usort($nevek, fn($a, $b) => ($b['sorok'] <=> $a['sorok']) ?: strcmp($a['nev'], $b['nev']));
    $csoportok = [];
    $dbCsoport = [];   // ceg_id → csoport index
    foreach ($nevek as $n) {
        $javaslat = null;
        $jeloltek = [];
        // a név számlái melyik cégnél vannak már a rendszerben
        $szavCeg = null;
        $szavPont = 0;
        $szav = array_filter(is_array($n['szavazat'] ?? null) ? $n['szavazat'] : [], fn($db, $id) => isset($cegById[(int)$id]), ARRAY_FILTER_USE_BOTH);
        if ($szav) {
            arsort($szav);
            $elso = array_key_first($szav);
            $pont = (int)floor(100 * $szav[$elso] / array_sum($szav));
            if ($pont >= IMP_SZAVAZAT_HATAR) {
                $szavCeg = (int)$elso;
                $szavPont = $pont;
            }
        }
        $adoCeg = null;
        foreach (is_array($n['adok'] ?? null) ? $n['adok'] : [] as $ak) {
            if (isset($adoTerkep[$ak]) && count($adoTerkep[$ak]) === 1) {
                $adoCeg = $adoTerkep[$ak][0];
                break;
            }
        }
        $pk = $n['partnerkod'];
        $irany = count($n['iranyok'] ?? []) === 1 ? $n['iranyok'][0] : '';
        $mk = imp_parositas_kulcs($pk, $n['nev'], $irany);
        // a megjegyzett párosítás / partnerkód / adószám szerinti cég (ha van)
        $kulcsCeg = null;
        $kulcsMod = '';
        if (isset($parositas[$mk]) && isset($cegById[(int)$parositas[$mk]])) {
            $kulcsCeg = $cegById[(int)$parositas[$mk]];
            $kulcsMod = 'megjegyzett';
        } elseif ($pk !== '' && isset($kodTerkep[$pk])) {
            $kulcsCeg = $kodTerkep[$pk];
            $kulcsMod = 'kod';
        } elseif ($adoCeg !== null) {
            $kulcsCeg = $adoCeg;
            $kulcsMod = 'ado';
        }
        if ($szavCeg !== null && $szavPont >= IMP_SZAVAZAT_BIZTOS) {
            // 1) a számlái biztosan ennél a cégnél vannak – ez a legerősebb bizonyíték (a DOS-ból átvett cégek partnerkódja és
            //    névírása más, és ugyanaz a partner a bejövő és a kimenő oldalon két külön cég is lehet)
            $javaslat = ['tipus' => 'db', 'ceg_id' => $szavCeg, 'db_nev' => $cegById[$szavCeg]['nev'], 'pont' => $szavPont, 'mod' => 'szamla'];
            if ($irany !== '') {
                $biztos[$mk] = $szavCeg;
            }
            if ($kulcsCeg !== null && (int)$kulcsCeg['id'] !== $szavCeg) {
                // a megjegyzett párosítás / kód / adószám másik céget mond (pl. a cég kétszer van meg) → nézze meg valaki
                $javaslat['bizonytalan'] = true;
                $jeloltek[] = ['ceg_id' => (int)$kulcsCeg['id'], 'nev' => $kulcsCeg['nev'], 'pont' => 100];
            }
        } elseif ($kulcsCeg !== null && $kulcsMod !== 'ado') {
            // 2) egy korábbi importban jóváhagyott párosítás, 3) partnerkód
            $javaslat = ['tipus' => 'db', 'ceg_id' => (int)$kulcsCeg['id'], 'db_nev' => $kulcsCeg['nev'], 'pont' => 100, 'mod' => $kulcsMod];
        } elseif ($szavCeg !== null) {
            // 4) a számlái nagyrészt (60–89 %) ennél a cégnél vannak
            $javaslat = ['tipus' => 'db', 'ceg_id' => $szavCeg, 'db_nev' => $cegById[$szavCeg]['nev'], 'pont' => $szavPont, 'mod' => 'szamla', 'bizonytalan' => true];
        } elseif ($kulcsCeg !== null) {
            // 5) adószám
            $javaslat = ['tipus' => 'db', 'ceg_id' => (int)$kulcsCeg['id'], 'db_nev' => $kulcsCeg['nev'], 'pont' => 100, 'mod' => 'ado'];
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
        // a megjegyzett / kód / adószám szerinti cég nem az, amelyiknél a számlái nagyrészt vannak → nézze meg valaki
        if ($javaslat && in_array($javaslat['mod'], ['megjegyzett', 'kod', 'ado'], true) && $szavCeg !== null && $szavCeg !== $javaslat['ceg_id']) {
            $javaslat['bizonytalan'] = true;
            $jeloltek[] = ['ceg_id' => $szavCeg, 'nev' => $cegById[$szavCeg]['nev'], 'pont' => $szavPont];
        }
        $bizonytalan = $javaslat !== null && ($javaslat['mod'] === 'hasonlo' || !empty($javaslat['bizonytalan']));
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
                // név szerinti párosításnál a gyengébb egyezés is gyanús; a kód / számla / adószám szerinti csak akkor, ha maga bizonytalan
                if ($bizonytalan || (in_array($javaslat['mod'], ['nev', 'hasonlo'], true) && $javaslat['pont'] < $cs['javaslat']['pont'])) {
                    $cs['bizonytalan'] = true;
                }
                if ($cs['partnerkod'] === '' && $n['partnerkod'] !== '') { $cs['partnerkod'] = $n['partnerkod']; }
                if ($cs['adoszam'] === '' && $n['adoszam'] !== '') { $cs['adoszam'] = $n['adoszam']; }
                unset($cs);
                continue;
            }
            $dbCsoport[$cid] = count($csoportok);
            $csoportok[] = ['id' => count($csoportok), 'nevek' => [$n['nev']], 'uj_nev' => $n['nev'], 'partnerkod' => $n['partnerkod'], 'adoszam' => $n['adoszam'],
                'cel' => ['tipus' => 'db', 'ceg_id' => $cid], 'javaslat' => $javaslat, 'jeloltek' => $jeloltek, 'sorok' => $n['sorok'], 'bizonytalan' => $bizonytalan];
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
//  Megfeleltetés a rendszerben lévő számláknak (1.22)
// ---------------------------------------------------------------------------

/**
 * A rendszer számlái az egyeztetéshez: ['BEJOVO' => [norm => [számlák]], 'KIMENO' => […]].
 * A toldalékos számlaszámú számla („KE-1/2026 [10081]”) a toldalék nélküli alakja alatt is megtalálható.
 */
function import_db_szamlak(): array
{
    $ki = ['BEJOVO' => [], 'KIMENO' => []];
    $lekerdezesek = [
        'BEJOVO' => 'SELECT b.id, b.kod, b.szamlaszam, b.szamlaszam_norm AS n, b.osszeg, b.kelt, b.teljesites_datum, b.fizetesi_hatarido, b.statusz, b.kotes_id,
                            k.ceg_id, k.penznem, c.nev AS ceg_nev, COALESCE(r.reszt, 0) AS reszt
                       FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id
                       LEFT JOIN (SELECT szamla_id, SUM(osszeg) AS reszt FROM reszteljesitesek WHERE irany = "BEJOVO" GROUP BY szamla_id) r ON r.szamla_id = b.id
                      ORDER BY b.id',
        'KIMENO' => 'SELECT s.id, s.kod, s.szamlaszam, s.szamlaszam_norm AS n, s.osszeg, s.kelt, s.teljesites_datum, s.fizetesi_hatarido, s.statusz, NULL AS kotes_id,
                            s.ceg_id, s.penznem, c.nev AS ceg_nev, COALESCE(r.reszt, 0) AS reszt
                       FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id
                       LEFT JOIN (SELECT szamla_id, SUM(osszeg) AS reszt FROM reszteljesitesek WHERE irany = "KIMENO" GROUP BY szamla_id) r ON r.szamla_id = s.id
                      ORDER BY s.id',
    ];
    foreach ($lekerdezesek as $irany => $sql) {
        foreach (db_all($sql) as $r) {
            $n = (string)$r['n'];
            unset($r['n']);
            $r['irany'] = $irany;
            $r['id'] = (int)$r['id'];
            $r['ceg_id'] = (int)$r['ceg_id'];
            $r['kotes_id'] = $r['kotes_id'] === null ? null : (int)$r['kotes_id'];
            $ki[$irany][$n][] = $r;
            $alap = norm_azonosito(imp_szamlaszam_alap($r['szamlaszam']));
            if ($alap !== '' && $alap !== $n) {
                $ki[$irany][$alap][] = $r;
            }
        }
    }
    return $ki;
}

/** A megtalált számla a kliensnek (és a javításnak) */
function import_letezo_nezet(array $f): array
{
    return ['irany' => $f['irany'], 'id' => $f['id'], 'kod' => $f['kod'], 'szamlaszam' => $f['szamlaszam'], 'ceg_id' => $f['ceg_id'], 'ceg_nev' => $f['ceg_nev'],
            'statusz' => $f['statusz'], 'penznem' => $f['penznem'], 'osszeg' => $f['osszeg'], 'reszt' => $f['reszt']];
}

/**
 * A fájl sora és a rendszerben lévő számla eltérései a javítható mezőkben:
 *   [['mezo' => oszlop, 'cimke', 'regi' => a rendszerben, 'uj' => az Excelben, 'akadaly' => null | miért nem javítható automatikusan], …]
 * A fájlból hiányzó (pótolt) dátum nem számít eltérésnek.
 */
function import_elteresek(array $s, array $db): array
{
    $uj = ['osszeg' => $s['osszeg'], 'kelt' => $s['kelt'], 'teljesites_datum' => $s['teljesites'], 'fizetesi_hatarido' => $s['hatarido'], 'szamlaszam' => $s['szamlaszam']];
    $potolt = is_array($s['potolt'] ?? null) ? $s['potolt'] : [];
    $ki = [];
    foreach (IMP_JAVITHATO as $m => $cimke) {
        $u = $uj[$m] ?? null;
        if ($u === null || $u === '' || in_array($m, $potolt, true)) {
            continue;
        }
        $r = (string)$db[$m];
        $akadaly = null;
        if ($m === 'szamlaszam') {
            // csak az írásmód számít (laza egyezéssel megtalált számla: „26/0001” ↔ „26/001”); a kis/nagybetű, szóköz, toldalék nem
            if (norm_azonosito(imp_szamlaszam_alap($r)) === norm_azonosito($u)) {
                continue;
            }
        } elseif ($m === 'osszeg') {
            if (abs((float)$r - (float)$u) < 0.005) {
                continue;
            }
            if ((float)$db['reszt'] > 0 && (float)$u <= (float)$db['reszt']) {
                $akadaly = 'az új összeg nem nagyobb a már felvezetett részteljesítéseknél (' . fmt_osszeg($db['reszt']) . " {$db['penznem']}) – előbb azt rendezd";
            } elseif ($s['irany'] === 'BEJOVO' && ((float)$r < 0) !== ((float)$u < 0)) {
                $akadaly = 'az előjele is más, és a bejövő számla státusza ettől függ – kézzel javítsd';
            }
        } elseif ($r === $u) {
            continue;
        }
        $ki[] = ['mezo' => $m, 'cimke' => $cimke, 'regi' => $r, 'uj' => (string)$u, 'akadaly' => $akadaly];
    }
    return $ki;
}

/**
 * A megjegyzett párosítás kulcsa: irányonként (B: bejövő, K: kimenő) a partnerkód, kód nélküli partnernél („0”) a neve.
 * Irányonként, mert a DOS-ból átvett cégek között ugyanaz a partner a bejövő és a kimenő oldalon két külön cég lehet
 * (pl. „Sawari Fresh Int.bv.” a bejövő, „SAWARI FRESH INTERNATIONAL B.V.” a kimenő számlákkal); vegyes irányú név: X (nincs párosítás).
 */
function imp_parositas_kulcs(string $partnerkod, string $nev, string $irany): string
{
    return ($irany === 'BEJOVO' ? 'B:' : ($irany === 'KIMENO' ? 'K:' : 'X:')) . ($partnerkod !== '' ? $partnerkod : 'n:' . imp_ceg_norm($nev));
}

/** A korábbi importokban jóváhagyott (partnerkód vagy név) → cég id párosítások */
function import_parositas_betolt(): array
{
    $v = db_val('SELECT ertek FROM beallitasok WHERE kulcs = ?', [IMP_PAROSITAS_KULCS]);
    $j = is_string($v) ? json_decode($v, true) : null;
    return is_array($j) ? $j : [];
}

/** Párosítások megjegyzése (a meglévőkhöz adva; ugyanarra a kódra az újabb az érvényes) */
function import_parositas_ment(array $uj): void
{
    $regi = import_parositas_betolt();
    $ossz = $regi;
    foreach ($uj as $kod => $cegId) {
        $ossz[(string)$kod] = (int)$cegId;
    }
    if ($ossz == $regi) {
        return;
    }
    db_exec('INSERT INTO beallitasok (kulcs, ertek) VALUES (?, ?) ON DUPLICATE KEY UPDATE ertek = VALUES(ertek)', [IMP_PAROSITAS_KULCS, json_encode($ossz, JSON_FORCE_OBJECT)]);
}

/**
 * Egy meglévő számla javítása a fájl sora szerint, a megadott mezőkben (a hívó tranzakciójában).
 * A számlát zárolja és újra összeveti – időközben változhatott. Visszaad: null, ha nincs mit javítani,
 * különben ['id', 'szulo', 'kod', 'szamlaszam', 'ceg_id', 'ceg_nev', 'penznem', 'statusz', 'valtozasok' => [{m, r, u}]].
 */
function import_szamla_javit(array $s, array $mezok, array $u): ?array
{
    $id = (int)($s['letezo']['id'] ?? 0);
    if ($s['irany'] === 'BEJOVO') {
        $db = db_row('SELECT b.id, b.kod, b.szamlaszam, b.osszeg, b.kelt, b.teljesites_datum, b.fizetesi_hatarido, b.statusz, b.kotes_id, k.ceg_id, k.penznem, c.nev AS ceg_nev,
                             COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r WHERE r.irany = "BEJOVO" AND r.szamla_id = b.id), 0) AS reszt
                        FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id WHERE b.id = ? FOR UPDATE', [$id]);
    } else {
        $db = db_row('SELECT s.id, s.kod, s.szamlaszam, s.osszeg, s.kelt, s.teljesites_datum, s.fizetesi_hatarido, s.statusz, NULL AS kotes_id, s.ceg_id, s.penznem, c.nev AS ceg_nev,
                             COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r WHERE r.irany = "KIMENO" AND r.szamla_id = s.id), 0) AS reszt
                        FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id WHERE s.id = ? FOR UPDATE', [$id]);
    }
    if (!$db || $db['penznem'] !== $s['penznem']) {
        return null;
    }
    $set = [];
    $p = [];
    $valt = [];
    foreach (import_elteresek($s, $db) as $e) {
        if ($e['akadaly'] !== null || !in_array($e['mezo'], $mezok, true)) {
            continue;
        }
        $set[] = "`{$e['mezo']}` = ?";   // az oszlopnév az IMP_JAVITHATO kulcsa
        $p[] = $e['uj'];
        if ($e['mezo'] === 'szamlaszam') {
            $set[] = 'szamlaszam_norm = ?';   // az egyediség-ellenőrzés és a keresés ezt használja
            $p[] = norm_azonosito($e['uj']);
        }
        $valt[] = ['m' => $e['cimke'], 'r' => audit_ertek($e['mezo'], $e['regi'], $db['penznem']), 'u' => audit_ertek($e['mezo'], $e['uj'], $db['penznem'])];
    }
    if (!$set) {
        return null;
    }
    $p[] = $u['id'];
    $p[] = $id;
    db_exec('UPDATE ' . ($s['irany'] === 'BEJOVO' ? 'bejovo_szamlak' : 'kimeno_szamlak') . ' SET ' . implode(', ', $set) . ', modositotta = ? WHERE id = ?', $p);
    return ['id' => $id, 'szulo' => $db['kotes_id'] === null ? null : (int)$db['kotes_id'], 'kod' => $db['kod'], 'szamlaszam' => $db['szamlaszam'], 'ceg_id' => (int)$db['ceg_id'],
            'ceg_nev' => $db['ceg_nev'], 'penznem' => $db['penznem'], 'statusz' => $db['statusz'], 'valtozasok' => $valt];
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
 * Fájl elemzése (nyers sorok + cégnevek + csoport-javaslatok). Nem ír az adatbázisba; a számlák alapján biztosan
 * felismert partnerkód → cég párosításokat a `parositas_biztos` adja vissza, a feltöltés (import.php) jegyzi meg.
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
            $potolt = [];   // a fájlból hiányzó, pótolt dátumok – ezek a meglévő számlával nem vethetők össze
            if (!$telj && $kelt) { $telj = $kelt; $potolt[] = 'teljesites_datum'; $figy[] = 'teljesítés (K) hiányzik → a kelt dátumot kapja'; }
            if (!$hat && $kelt) { $hat = $kelt; $potolt[] = 'fizetesi_hatarido'; $figy[] = 'fizetési határidő (M) hiányzik → a kelt dátumot kapja'; }
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
            $euAdoszam = imp_szoveg($c['Q'] ?? null, 32);
            $adok = array_values(array_unique(array_filter([imp_adoszam_kulcs($adoszam), imp_adoszam_kulcs($euAdoszam)], fn($a) => $a !== '')));
            if ($adoszam === '') { $adoszam = $euAdoszam; }   // külföldi partnernek csak EU adószáma van
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
                'regi_kotes' => '', 'regi_k' => '', 'kotes_megnevezes' => '', 'potolt' => $potolt];
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
            $adok = [];
            $potolt = ['fizetesi_hatarido'];   // a kötéskönyvben nincs határidő (import: kelt + N nap)
            if (!$telj && $kelt) {
                $telj = $kelt;
                $potolt[] = 'teljesites_datum';
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
                'beszam' => imp_szoveg($c['J'] ?? null, 191), 'regi_kotes' => $regiKotes, 'regi_k' => $b, 'kotes_megnevezes' => imp_szoveg($c['D'] ?? null, 191), 'potolt' => $potolt];
        }
        $sor['i'] = count($nyers);
        $sor['sor'] = $sorSzam;
        $sor['hibak'] = $hibak;
        $sor['figyelmeztetes'] = $figy;
        $nyers[] = $sor;
        if ($sor['ceg_nev'] !== '') {
            $k = $sor['ceg_nev'];
            if (!isset($nevek[$k])) {
                $nevek[$k] = ['nev' => $k, 'partnerkod' => $sor['partnerkod'], 'adoszam' => $sor['adoszam'], 'sorok' => 0, 'adok' => [], 'szavazat' => [], 'iranyok' => []];
            }
            $nevek[$k]['sorok']++;
            if (!in_array($sor['irany'], $nevek[$k]['iranyok'], true)) { $nevek[$k]['iranyok'][] = $sor['irany']; }
            $nevek[$k]['adok'] = array_values(array_unique(array_merge($nevek[$k]['adok'], $adok)));
            if ($nevek[$k]['partnerkod'] === '' && $sor['partnerkod'] !== '') { $nevek[$k]['partnerkod'] = $sor['partnerkod']; }
            if ($nevek[$k]['adoszam'] === '' && $sor['adoszam'] !== '') { $nevek[$k]['adoszam'] = $sor['adoszam']; }
        }
    }
    // melyik cégnél vannak már a rendszerben a név számlái (kimenőnél a számlaszám egyedi; bejövőnél más cég is
    // használhatja ugyanazt a számot, ezért ott csak az azonos összegű számla számít)
    $dbSz = import_db_szamlak();
    foreach ($nyers as $sor) {
        if ($sor['hibak'] || $sor['ceg_nev'] === '') {
            continue;
        }
        foreach ($dbSz[$sor['irany']][norm_azonosito($sor['szamlaszam'])] ?? [] as $f) {
            if ($sor['irany'] === 'BEJOVO' && abs((float)$f['osszeg'] - (float)$sor['osszeg']) >= 0.005) {
                continue;
            }
            $nevek[$sor['ceg_nev']]['szavazat'][$f['ceg_id']] = ($nevek[$sor['ceg_nev']]['szavazat'][$f['ceg_id']] ?? 0) + 1;
        }
    }
    unset($dbSz);
    $nevekLista = array_values($nevek);
    $csoportok = import_ceg_csoportok($nevekLista, $cegekDb, import_parositas_betolt(), $biztos);
    $el = [
        'fajl' => $fajlnev, 'lap' => $lap['nev'], 'formatum' => $formatum, 'fejlec_sor' => $fejlecSor, 'osszes_sor' => $osszes, 'kihagyott' => $kihagyott,
        'tipusok' => $tipusDb, 'nyers' => $nyers, 'nevek' => $nevekLista, 'csoportok' => $csoportok,
        'db_cegek' => array_map(fn($c) => ['id' => $c['id'], 'nev' => $c['nev'], 'partnerkod' => $c['partnerkod']], $cegekDb),
        'elemezve' => date('Y-m-d H:i:s'), 'parositas_biztos' => $biztos,
    ];
    return $el;
}

/**
 * Sorstátuszok kiszámítása az aktuális cégcsoportok szerint (adatbázis + fájlon belüli ütközések).
 *   uj       – nincs még a rendszerben
 *   letezik  – megvan és egyezik (vagy több egyező számla van, nem dönthető el, melyik)
 *   elter    – megvan, de a javítható mezők (IMP_JAVITHATO) valamelyike más: `elteresek`, a számla: `letezo`
 *   utkozik  – a számlaszámot másik cég / a másik irány használja · dupla – kétszer a fájlban · hiba
 * Visszaadja az elemzést kiegészítve: sorok, osszesites, elteres_db (mezőnként), ceg_osszesites, kotesek (kötéskönyvnél).
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
    $dbSz = import_db_szamlak();
    // a cégek számlái irányonként (az esetleg más számlaszámmal már meglévő számla kereséséhez: azonos cég, összeg és dátum)
    $cegSzamlak = ['BEJOVO' => [], 'KIMENO' => []];
    foreach ($dbSz as $irany => $normok) {
        foreach ($normok as $lista) {
            foreach ($lista as $f) {
                $cegSzamlak[$irany][$f['ceg_id']][$f['id']] = $f;
            }
        }
    }
    $talaltId = [];   // irány:id → a fájl egy sora megtalálta (ezek nem „más számlaszámú” jelöltek)
    // meglévő kötések régi ID szerint (kötéskönyvhöz)
    $regiKotesek = [];
    if ($el['formatum'] === 'koteskonyv') {
        foreach (db_all('SELECT id, kod, ceg_id, penznem, regi_kod FROM kotesek WHERE regi_kod IS NOT NULL AND regi_kod <> ""') as $k) {
            $regiKotesek[mb_strtolower($k['regi_kod'], 'UTF-8') . '|' . (int)$k['ceg_id'] . '|' . $k['penznem']] = ['id' => (int)$k['id'], 'kod' => $k['kod']];
        }
    }
    $sorok = [];
    $fajlNorm = [];
    $ossz = ['uj' => 0, 'letezik' => 0, 'elter' => 0, 'utkozik' => 0, 'dupla' => 0, 'hiba' => 0, 'figyelmeztetes' => 0, 'mas_ceg' => 0, 'hasonlo' => 0];
    $eltDb = array_fill_keys(array_keys(IMP_JAVITHATO), 0);
    $kotesek = [];   // kötéskönyv: régi ID|identitás|pénznem → info
    foreach ($el['nyers'] as $ny) {
        $s = $ny;
        $s['allapot'] = 'uj';
        $s['uzenet'] = '';
        $s['toldalekos'] = null;
        $s['toldalek_szabad'] = false;
        $s['letezo'] = null;
        $s['elteresek'] = [];
        $s['mas_ceg'] = false;
        $csId = $nevCsoport[$s['ceg_nev']] ?? null;
        $s['csoport_id'] = $csId;
        $ident = $csId !== null ? $identitas[$csId] : null;
        $cegId = $ident !== null && str_starts_with($ident, 'db:') ? (int)substr($ident, 3) : null;
        $s['ceg_id'] = $cegId;
        $s['kotes_cel'] = null;
        do {
            if ($s['hibak']) {
                $s['allapot'] = 'hiba';
                $s['uzenet'] = implode('; ', $s['hibak']);
                break;
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
                break;
            }
            $s['szamlaszam_norm'] = $norm;
            // 1) ugyanaz a számla kétszer a fájlban (a kimenő számlaszám egyedi, ott a cég sem számít)
            $fajlUtkozes = null;
            if (isset($fajlNorm[$norm])) {
                $elozo = $sorok[$fajlNorm[$norm]];
                if ($elozo['irany'] === $s['irany'] && ($s['irany'] === 'KIMENO' || ($elozo['csoport_id'] !== null && $elozo['csoport_id'] === $csId))) {
                    $s['allapot'] = 'dupla';
                    $s['uzenet'] = 'duplikált sor a fájlban (ugyanez a számlaszám a ' . $elozo['sor'] . '. sorban' . ($s['irany'] === 'KIMENO' ? '' : ', ugyanannál a cégnél') . ')';
                    break;
                }
                $fajlUtkozes = 'ugyanez a számlaszám a fájl ' . $elozo['sor'] . '. sorában is szerepel (' . $elozo['ceg_nev'] . ')';
            } else {
                $fajlNorm[$norm] = count($sorok);
            }
            // 2) megvan-e már a rendszerben: kimenőnél a számlaszám azonosít (bármelyik cégnél van), bejövőnél a számlaszám + a cég
            $sajat = $dbSz[$s['irany']][$norm] ?? [];
            $ugyanott = $cegId === null ? [] : array_values(array_filter($sajat, fn($f) => $f['ceg_id'] === $cegId));
            $talalat = null;
            $tobb = [];
            if (count($ugyanott) === 1) {
                $talalat = $ugyanott[0];
            } elseif ($ugyanott) {
                $tobb = $ugyanott;
            } elseif ($s['irany'] === 'KIMENO' && count($sajat) === 1) {
                $talalat = $sajat[0];
                $s['mas_ceg'] = true;
            } elseif ($s['irany'] === 'KIMENO' && $sajat) {
                $tobb = $sajat;
            } elseif ($s['irany'] === 'BEJOVO' && $sajat) {
                // bejövőnél a számlaszám önmagában nem azonosít, de ugyanaz a szám ugyanazzal az összeggel és dátummal másik cégnél
                // szinte biztosan ugyanaz a számla (a DOS-ban az alvállalkozó, a könyvelőprogramban a számlázó cég neve alatt;
                // vagy egy partner két cégként) – így nem kerül be duplán
                $masutt = array_values(array_filter($sajat, fn($f) => abs((float)$f['osszeg'] - (float)$s['osszeg']) < 0.005
                    && ($f['teljesites_datum'] === $s['teljesites'] || $f['kelt'] === $s['kelt'] || $f['kelt'] === $s['teljesites'])));
                if (count($masutt) === 1) {
                    $talalat = $masutt[0];
                    $s['mas_ceg'] = true;
                }
            }
            if ($talalat !== null) {
                $talaltId[$talalat['irany'] . ':' . $talalat['id']] = true;
                $s['allapot'] = 'letezik';
                $s['letezo'] = import_letezo_nezet($talalat);
                $s['uzenet'] = 'már a rendszerben van: ' . $talalat['kod'];
                if ($s['mas_ceg']) {
                    $s['figyelmeztetes'][] = 'a rendszerben másik cégnél szerepel: ' . $talalat['ceg_nev'] . ' – a cégek egyeztetésénél nézd meg, melyik a helyes (a cég nem íródik át)';
                }
                if ($talalat['penznem'] !== $s['penznem']) {
                    $s['figyelmeztetes'][] = "a rendszerben {$talalat['penznem']} számlaként szerepel – az adatai nem vethetők össze";
                    break;
                }
                $s['elteresek'] = import_elteresek($s, $talalat);
                if ($s['elteresek']) {
                    $s['allapot'] = 'elter';
                    $s['uzenet'] .= ' – eltér: ' . implode(', ', array_map(fn($e) => mb_strtolower($e['cimke'], 'UTF-8'), $s['elteresek']));
                }
                break;
            }
            if ($tobb) {
                // több egyező számla (pl. ugyanaz a számlaszám a cég több kötésében): nem tudni, melyiket kellene javítani
                $s['allapot'] = 'letezik';
                $s['letezo'] = import_letezo_nezet($tobb[0]);
                $s['uzenet'] = 'már a rendszerben van, több számlaként: ' . implode(', ', array_map(fn($f) => $f['kod'], array_slice($tobb, 0, 4))) . (count($tobb) > 4 ? ' …' : '');
                $egyezik = false;
                $sum = 0.0;
                foreach ($tobb as $f) {
                    $talaltId[$f['irany'] . ':' . $f['id']] = true;
                    $sum = round($sum + (float)$f['osszeg'], 2);
                    $egyezik = $egyezik || abs((float)$f['osszeg'] - (float)$s['osszeg']) < 0.005;
                }
                if (!$egyezik && abs($sum - (float)$s['osszeg']) >= 0.005) {
                    $s['figyelmeztetes'][] = 'az összege egyik egyező számláéval (és azok összegével) sem azonos – kézzel ellenőrizd';
                }
                break;
            }
            // 3) a számlaszámot más (másik cég / a másik irány) már használja, vagy a fájlban másik cégnél is szerepel
            $idegen = array_merge($sajat, $dbSz[$s['irany'] === 'BEJOVO' ? 'KIMENO' : 'BEJOVO'][$norm] ?? []);
            $utkozes = $fajlUtkozes;
            if ($idegen) {
                $f = $idegen[0];
                $utkozes = 'a számlaszám már foglalt: ' . $f['kod'] . ' (' . $f['ceg_nev'] . ($f['irany'] === 'BEJOVO' ? ', bejövő' : ', kimenő') . ')';
                $s['letezo'] = import_letezo_nezet($f);
            }
            if ($utkozes === null) {
                break;   // új számla
            }
            $s['allapot'] = 'utkozik';
            $s['uzenet'] = $utkozes;
            $cs = $csId !== null ? $csById[$csId] : null;
            $tag = $s['partnerkod'] !== '' ? $s['partnerkod'] : mb_substr(preg_replace('/[^\p{L}\p{N} ]+/u', '', $cs ? $cs['uj_nev'] : $s['ceg_nev']) ?? '', 0, 14, 'UTF-8');
            $told = mb_substr($s['szamlaszam'] . ' [' . $tag . ']', 0, 100, 'UTF-8');
            $tn = norm_azonosito($told);
            $s['toldalekos'] = $told;
            if (!isset($dbSz['BEJOVO'][$tn]) && !isset($dbSz['KIMENO'][$tn]) && !isset($fajlNorm[$tn])) {
                $s['toldalek_szabad'] = true;
                $fajlNorm[$tn] = count($sorok);
            }
        } while (false);
        $ossz[$s['allapot']]++;
        if ($s['figyelmeztetes']) {
            $ossz['figyelmeztetes']++;
        }
        if ($s['mas_ceg']) {
            $ossz['mas_ceg']++;
        }
        foreach ($s['elteresek'] as $e) {
            $eltDb[$e['mezo']]++;
        }
        $sorok[] = $s;
    }
    // új sor, de a cégnek van ugyanilyen összegű és dátumú számlája, amelyet a fájl egyetlen sora sem talált meg:
    //   – ha pontosan egy ilyen van, és a számlaszáma lazán egyezik („26/0001” ↔ „26/001”), az ugyanaz a számla: megtalált
    //     (letezik / elter, a számlaszám írásmódja javítható eltérés);
    //   – különben (pl. a DOS-ból „4/2026”, az Excelben „4/26”) csak figyelmeztetés, a sor új marad
    foreach ($sorok as &$s) {
        if ($s['allapot'] !== 'uj' || $s['ceg_id'] === null) {
            continue;
        }
        $jeloltek = [];
        $laza = [];
        $lk = imp_laza_kulcs($s['szamlaszam']);
        foreach ($cegSzamlak[$s['irany']][$s['ceg_id']] ?? [] as $f) {
            if (isset($talaltId[$f['irany'] . ':' . $f['id']]) || $f['penznem'] !== $s['penznem'] || abs((float)$f['osszeg'] - (float)$s['osszeg']) >= 0.005) {
                continue;
            }
            if ($f['teljesites_datum'] === $s['teljesites'] || $f['kelt'] === $s['kelt'] || $f['kelt'] === $s['teljesites']) {
                $jeloltek[] = $f;
                if ($lk !== '' && imp_laza_kulcs(imp_szamlaszam_alap($f['szamlaszam'])) === $lk) {
                    $laza[] = $f;
                }
            }
        }
        if (!$jeloltek) {
            continue;
        }
        if (!$s['figyelmeztetes']) {
            $ossz['figyelmeztetes']++;
        }
        if (count($laza) === 1) {
            $f = $laza[0];
            $talaltId[$f['irany'] . ':' . $f['id']] = true;
            $ossz['uj']--;
            $s['letezo'] = import_letezo_nezet($f);
            $s['figyelmeztetes'][] = 'a számlaszám írásmódja más, mint a rendszerben („' . $f['szamlaszam'] . '”) – azonos cég, összeg és dátum alapján ugyanaz a számla';
            $s['elteresek'] = import_elteresek($s, $f);
            $s['allapot'] = $s['elteresek'] ? 'elter' : 'letezik';
            $ossz[$s['allapot']]++;
            $s['uzenet'] = 'már a rendszerben van: ' . $f['kod'] . ($s['elteresek'] ? ' – eltér: ' . implode(', ', array_map(fn($e) => mb_strtolower($e['cimke'], 'UTF-8'), $s['elteresek'])) : '');
            foreach ($s['elteresek'] as $e) {
                $eltDb[$e['mezo']]++;
            }
            continue;
        }
        $ossz['hasonlo']++;
        $s['figyelmeztetes'][] = 'lehet, hogy már megvan más számlaszámmal: ' . implode(', ', array_map(fn($f) => $f['kod'] . ' „' . $f['szamlaszam'] . '”', array_slice($jeloltek, 0, 3)))
            . (count($jeloltek) > 3 ? ' …' : '') . ' (ugyanannál a cégnél azonos összeggel és dátummal) – ellenőrizd, mielőtt importálod';
    }
    unset($s);
    $cegOssz = ['uj' => 0, 'letezik' => 0, 'bizonytalan' => 0];
    foreach ($el['csoportok'] as $cs) {
        $cegOssz[str_starts_with($identitas[$cs['id']], 'db:') ? 'letezik' : 'uj']++;
        if (!empty($cs['bizonytalan'])) {
            $cegOssz['bizonytalan']++;
        }
    }
    $el['sorok'] = $sorok;
    $el['osszesites'] = $ossz;
    $el['elteres_db'] = $eltDb;
    $el['ceg_osszesites'] = $cegOssz;
    $el['kotesek_osszesites'] = ['db' => count($kotesek), 'meglevo' => count(array_filter($kotesek, fn($k) => $k['meglevo'] !== null))];
    return $el;
}

/** A kliensnek küldött elemzés (a nyers sorok nélkül, azok a szerveren maradnak) */
function import_kliens_nezet(array $el): array
{
    unset($el['nyers'], $el['parositas_biztos']);
    return $el;
}

// ---------------------------------------------------------------------------
//  Végrehajtás
// ---------------------------------------------------------------------------

/**
 * A kiválasztott sorok rögzítése és az eltérő (elter) meglévő számlák javítása (egy tranzakcióban).
 * $opciok: bejovo_statusz FIZETENDO|FIZETVE, kimeno_statusz NYITOTT|FIZETVE, utkozes kihagy|toldalek, hatarido_nap (kötéskönyv),
 *          mervado: eltérés esetén melyik adat a mérvadó – 'db' (alap: a meglévő számla nem módosul, csak az új sorok kerülnek be)
 *                   vagy 'import' (a meglévő számla az Excel szerinti értéket kapja),
 *          javit: 'import' mérvadónál a meglévő számlákon javítandó mezők (IMP_JAVITHATO kulcsai; alapból mind)
 * Meglévő számlát csak Irodavezető / Admin javíthat – és bármilyen státuszban (a kézi szerkesztés a rendezett
 * számla összegét nem engedi átírni; itt a könyvelőprogram adata a mérvadó).
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
    // a mérvadó kapcsoló (1.22): 'db' = a meglévő számlák érintetlenek, 'import' = az Excel szerint javulnak (csak Irodavezető / Admin)
    $mervado = ($opciok['mervado'] ?? 'db') === 'import' ? 'import' : 'db';
    $javitMezok = array_values(array_intersect(array_keys(IMP_JAVITHATO), is_array($opciok['javit'] ?? null) ? $opciok['javit'] : array_keys(IMP_JAVITHATO)));
    $javithat = $mervado === 'import' && jog_van('torol', $u);
    $valasztott = array_fill_keys(array_map('intval', $kivalasztott), true);
    $csById = [];
    foreach ($el['csoportok'] as $cs) {
        $csById[$cs['id']] = $cs;
    }
    $ma = date('Y-m-d');
    $importJel = 'Excel import: ' . $el['fajl'] . ' (' . date('Y.m.d. H:i') . ', ' . $u['felhasznalonev'] . ')';
    $koteskonyv = $el['formatum'] === 'koteskonyv';

    $e = db_tx(function () use ($el, $valasztott, $bejovoSt, $kimenoSt, $toldalek, $utkozesMod, $hataridoNap, $mervado, $javitMezok, $javithat, $csById, $u, $ma, $importJel, $koteskonyv) {
        $stat = ['bejovo' => 0, 'kimeno' => 0, 'uj_ceg' => 0, 'uj_kotes' => 0, 'uj_archiv' => 0, 'kihagyott' => 0, 'toldalekos' => 0, 'fizetve' => 0, 'javitva' => 0];
        $auditSorok = [];
        $javitasok = [];
        $parositas = [];   // partnerkód (vagy név) → cég id: amit ebben az importban tényleg használtunk
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
            if ($s['allapot'] === 'elter') {
                // meglévő számla javítása az Excel szerint – csak ha az import a mérvadó
                $j = $javithat ? import_szamla_javit($s, $javitMezok, $u) : null;
                if ($j === null) {
                    $stat['kihagyott']++;
                    $kihagyva[] = ['sor' => $s['sor'], 'szamlaszam' => $s['szamlaszam'], 'ok' => $mervado !== 'import'
                        ? 'az adatbázis a mérvadó – a meglévő számla nem módosul'
                        : ($javithat ? 'nincs javítható eltérés (a kiválasztott mezőkben egyezik, vagy kézi javítást igényel)' : 'meglévő számlát csak Irodavezető vagy Admin javíthat')];
                    continue;
                }
                $stat['javitva']++;
                $javitasok[] = $j + ['irany' => $s['irany']];
                $auditSorok[] = [$s['irany'], $j['id'], $j['szulo'], 'IMPORT', "Excel importból javítva: {$j['kod']} „{$j['szamlaszam']}” ({$el['fajl']})", $j['valtozasok']];
                if (!$s['mas_ceg']) {
                    $parositas[imp_parositas_kulcs($s['partnerkod'], $s['ceg_nev'], $s['irany'])] = $j['ceg_id'];
                }
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
            $parositas[imp_parositas_kulcs($s['partnerkod'], $s['ceg_nev'], $s['irany'])] = $cid;
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
        import_parositas_ment($parositas);
        return ['stat' => $stat, 'uj_kotesek' => $ujKotesek, 'kihagyva' => $kihagyva, 'osszegek' => $osszegek, 'cegek' => count($cegId), 'javitasok' => $javitasok];
    });

    $st = $e['stat'];
    $oss = [];
    foreach ($e['osszegek'] as $k => $v) {
        $oss[] = "$k " . fmt_osszeg($v);
    }
    naplo('EXCEL_IMPORT', "{$el['fajl']} ({$el['formatum']}): {$st['bejovo']} bejövő + {$st['kimeno']} kimenő számla rögzítve ({$st['uj_ceg']} új cég, {$st['uj_kotes']} új kötés régi ID-val, {$st['uj_archiv']} új archív kötés, {$st['toldalekos']} toldalékos számlaszám, {$st['javitva']} meglévő számla javítva, {$st['kihagyott']} kihagyva; mérvadó: " . ($mervado === 'import' ? 'import (' . implode(', ', $javitMezok) . ')' : 'adatbázis') . ", bejövő státusz: $bejovoSt, kimenő: $kimenoSt" . ($koteskonyv ? ", határidő: kelt + $hataridoNap nap" : '') . ')' . ($oss ? ' – ' . implode(', ', $oss) : ''));
    foreach ($e['javitasok'] as $j) {
        naplo('EXCEL_JAVITAS', ($j['irany'] === 'BEJOVO' ? 'bejövő' : 'kimenő') . " számla {$j['kod']} „{$j['szamlaszam']}” ({$j['ceg_nev']}, " . ny_statusz_nev($j['statusz']) . '): '
            . implode('; ', array_map(fn($v) => "{$v['m']} {$v['r']} → {$v['u']}", $j['valtozasok'])) . " – {$el['fajl']}");
    }
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
