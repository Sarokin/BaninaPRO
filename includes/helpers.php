<?php
declare(strict_types=1);

/**
 * Üzleti / validációs hiba, amit a kliens felé emberi üzenetként adunk vissza.
 */
class ApiError extends RuntimeException
{
    public string $kod;
    public array $extra;

    public function __construct(string $uzenet, string $kod = 'HIBA', array $extra = [], int $http = 400)
    {
        parent::__construct($uzenet, $http);
        $this->kod   = $kod;
        $this->extra = $extra;
    }
}

function hiba(string $uzenet, string $kod = 'HIBA', array $extra = [], int $http = 400): void
{
    throw new ApiError($uzenet, $kod, $extra, $http);
}

// ---------------------------------------------------------------------------
//  Bemenet
// ---------------------------------------------------------------------------

/** Kötelező, nem üres szöveg (trimmelve, max hossz) */
function be_szoveg(array $in, string $kulcs, int $max = 191, bool $kotelezo = true, string $cimke = null): ?string
{
    $cimke = $cimke ?? $kulcs;
    $v = $in[$kulcs] ?? null;
    if ($v === null || (is_string($v) && trim($v) === '')) {
        if ($kotelezo) {
            hiba("Hiányzó adat: $cimke");
        }
        return null;
    }
    if (!is_string($v) && !is_numeric($v)) {
        hiba("Érvénytelen adat: $cimke");
    }
    $v = trim((string)$v);
    // több szóköz → egy szóköz, vezérlő karakterek ki
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    $v = preg_replace('/\s+/u', ' ', $v);
    if (mb_strlen($v) > $max) {
        hiba("Túl hosszú adat: $cimke (max. $max karakter)");
    }
    return $v;
}

/**
 * Többsoros szöveg (megjegyzés): a sortöréseket megtartja (CRLF → LF, legfeljebb egy üres sor),
 * a soron belüli szóköz-sorozatokat összevonja, vezérlő karaktereket kiszűri.
 */
function be_szoveg_tobbsoros(array $in, string $kulcs, int $max = 2000, bool $kotelezo = false, string $cimke = null): ?string
{
    $cimke = $cimke ?? $kulcs;
    $v = $in[$kulcs] ?? null;
    if ($v === null || (is_string($v) && trim($v) === '')) {
        if ($kotelezo) {
            hiba("Hiányzó adat: $cimke");
        }
        return null;
    }
    if (!is_string($v) && !is_numeric($v)) {
        hiba("Érvénytelen adat: $cimke");
    }
    $v = str_replace(["\r\n", "\r"], "\n", (string)$v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    $v = preg_replace('/[ \t]+/u', ' ', $v);
    $v = preg_replace('/ *\n */u', "\n", $v);
    $v = preg_replace('/\n{3,}/u', "\n\n", $v);
    $v = trim($v);
    if (mb_strlen($v) > $max) {
        hiba("Túl hosszú adat: $cimke (max. $max karakter)");
    }
    return $v;
}

/** Egész szám (id) */
function be_int(array $in, string $kulcs, bool $kotelezo = true, string $cimke = null): ?int
{
    $cimke = $cimke ?? $kulcs;
    $v = $in[$kulcs] ?? null;
    if ($v === null || $v === '') {
        if ($kotelezo) {
            hiba("Hiányzó adat: $cimke");
        }
        return null;
    }
    if (!is_numeric($v) || (int)$v != $v) {
        hiba("Érvénytelen szám: $cimke");
    }
    return (int)$v;
}

/** Dátum ÉÉÉÉ-HH-NN formátumban */
function be_datum(array $in, string $kulcs, bool $kotelezo = true, string $cimke = null): ?string
{
    $cimke = $cimke ?? $kulcs;
    $v = $in[$kulcs] ?? null;
    if ($v === null || trim((string)$v) === '') {
        if ($kotelezo) {
            hiba("Hiányzó dátum: $cimke");
        }
        return null;
    }
    $v = trim((string)$v);
    // elfogadjuk a magyar 2026.09.29. alakot is
    if (preg_match('/^(\d{4})\.\s?(\d{1,2})\.\s?(\d{1,2})\.?$/', $v, $m)) {
        $v = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        hiba("Érvénytelen dátum: $cimke (ÉÉÉÉ-HH-NN)");
    }
    return $v;
}

/** Pénznem: HUF vagy EUR */
function be_penznem(array $in, string $kulcs = 'penznem'): string
{
    $v = strtoupper(trim((string)($in[$kulcs] ?? '')));
    if ($v !== 'HUF' && $v !== 'EUR') {
        hiba('A pénznem csak HUF vagy EUR lehet!');
    }
    return $v;
}

/**
 * Összeg: elfogadja a "1 234,56", "1234.56", "-2 000" alakokat is.
 * Visszaadja szövegként 2 tizedessel (DECIMAL-hoz), lehet negatív.
 */
function be_osszeg(array $in, string $kulcs = 'osszeg', bool $lehetNegativ = true, string $cimke = 'összeg'): string
{
    $v = $in[$kulcs] ?? null;
    if ($v === null || trim((string)$v) === '') {
        hiba("Hiányzó adat: $cimke");
    }
    $s = (string)$v;
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' ', "'"], '', $s); // szóközök, NBSP
    $s = str_replace(',', '.', $s);
    // 1.234.567.89 típusú ezreselválasztó pontok: ha több pont van, az utolsó a tizedes
    if (substr_count($s, '.') > 1) {
        $utolso = strrpos($s, '.');
        $s = str_replace('.', '', substr($s, 0, $utolso)) . substr($s, $utolso);
    }
    if (!preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $s)) {
        hiba("Érvénytelen összeg: $cimke (pl. 12 500 vagy -2 000,50)");
    }
    if (!$lehetNegativ && (float)$s < 0) {
        hiba("Az összeg nem lehet negatív: $cimke");
    }
    return number_format((float)$s, 2, '.', '');
}

/** Logikai */
function be_bool(array $in, string $kulcs): bool
{
    $v = $in[$kulcs] ?? false;
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
}

/** Egész számok listája */
function be_int_lista(array $in, string $kulcs, string $cimke = null): array
{
    $cimke = $cimke ?? $kulcs;
    $v = $in[$kulcs] ?? null;
    if (!is_array($v) || count($v) === 0) {
        hiba("Hiányzó lista: $cimke");
    }
    $ki = [];
    foreach ($v as $e) {
        if (!is_numeric($e)) {
            hiba("Érvénytelen azonosító a listában: $cimke");
        }
        $ki[] = (int)$e;
    }
    return array_values(array_unique($ki));
}

// ---------------------------------------------------------------------------
//  Normalizálás / azonosítók
// ---------------------------------------------------------------------------

/**
 * Szabad formátumú azonosító (számlaszám, banki azonosító) normalizált alakja:
 * nagybetűs, csak betűk és számok maradnak. Így az "SZ-2026/17", "sz 2026 17" és
 * "SZ202617" ugyanannak számít → egyik sem rögzíthető kétszer.
 */
function norm_azonosito(string $s): string
{
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
    return $s;
}

function kotes_kod(int $ev, string $penznem, int $sorszam): string
{
    return sprintf('%04d-%s-%06d', $ev, $penznem, $sorszam);
}

function szamla_kod(string $kotesKod, int $k): string
{
    return sprintf('%s-K%04d', $kotesKod, $k);
}

function utalas_uid(string $penznem, int $ev, int $sorszam): string
{
    return sprintf('U-%s-%04d-%06d', $penznem, $ev, $sorszam);
}

function kimeno_kod(int $ev, string $penznem, int $sorszam): string
{
    return sprintf('%04d-%s-%06d', $ev, $penznem, $sorszam);
}

// ---------------------------------------------------------------------------
//  Kimenet
// ---------------------------------------------------------------------------

function json_valasz(array $adat, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
    echo json_encode($adat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function ok(mixed $adat = null, array $tobbi = []): void
{
    json_valasz(['ok' => true, 'adat' => $adat] + $tobbi);
}

/** Kliens IP (proxy mögött is) */
function kliens_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr((string)$ip, 0, 45);
}

/** Státusz magyar neve (naplóhoz, hibaüzenethez) */
function ny_statusz_nev(string $s): string
{
    return ['FIZETENDO' => 'FIZETENDŐ', 'UTALASHOZ_ADVA' => 'UTALÁSHOZ ADVA', 'FIZETVE' => 'FIZETVE', 'BESZAMITVA' => 'BESZÁMÍTVA', 'NYITOTT' => 'NYITOTT',
            'FIZETETT' => 'FIZETETT', 'UTALVA' => 'UTALVA'][$s] ?? $s;
}

/** Összeg formázása naplóhoz: 12 345,50 */
function fmt_osszeg(string|float|int $o): string
{
    $f = (float)$o;
    $s = number_format($f, 2, ',', ' ');
    return str_ends_with($s, ',00') ? substr($s, 0, -3) : $s;
}

/** DECIMAL sztringek összege (float pontatlanság nélkül, 2 tizedesre) */
function dec_sum(array $ertekek): string
{
    $sum = 0.0;
    foreach ($ertekek as $v) {
        $sum = round($sum + (float)$v, 2);
    }
    return number_format($sum, 2, '.', '');
}
