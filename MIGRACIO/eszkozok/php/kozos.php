<?php
/**
 * A migrációs eszközök közös függvényei (a 2026.10.07-i DOS import megjegyzés-formátumának értelmezése,
 * DOS-dátumok és -összegek).
 */
declare(strict_types=1);

// csak parancssorból (a szerveren a repó a webgyökér – webről ne fusson)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

/**
 * Egy „DOS import · Kulcs: érték · Kulcs: érték · szabad szöveg” megjegyzés bontása.
 * @return array{mezok: array<string,string>, egyeb: list<string>}
 */
function mig_megj_bont(string $megj): array
{
    $mezok = [];
    $egyeb = [];
    // a későbbi hozzáfűzések (pl. az Excel import „Régi partnerkód: …” sora) új sorba kerülnek
    foreach (preg_split('/ · |\R/u', $megj) ?: [] as $resz) {
        $resz = trim($resz);
        if ($resz === '' || str_starts_with($resz, 'DOS import')) {
            continue;
        }
        $p = mb_strpos($resz, ': ');
        if ($p === false || $p > 60) {
            $egyeb[] = $resz;
            continue;
        }
        $k = mb_substr($resz, 0, $p);
        $v = mb_substr($resz, $p + 2);
        if (isset($mezok[$k])) {
            $mezok[$k] .= ' · ' . $v;
        } else {
            $mezok[$k] = $v;
        }
    }
    return ['mezok' => $mezok, 'egyeb' => $egyeb];
}

/**
 * „UGYF 12345678901, VEVO HU-00000123” → [['UGYF','12345678901'], ['VEVO','HU-00000123']]. Összevont cégnél több
 * „DOS-kód:” rész is lehet, ezeket a megjegyzés-bontás „ · ”-tal fűzi össze – az is elválasztó.
 */
function mig_dos_kodok(string $s): array
{
    $ki = [];
    foreach (preg_split('/,\s*| · /u', trim($s)) ?: [] as $d) {
        if (preg_match('/^(UGYF|VEVO)\s+(.+)$/u', trim($d), $m)) {
            $ki[] = [$m[1], trim($m[2])];
        }
    }
    return $ki;
}

/** „DOS devizautalás #012345” → ['tipus' => 'deviza', 'szam' => '012345'] (forintutalás → 'forint'), különben null */
function mig_utalas_ref(string $s): ?array
{
    if (preg_match('/DOS (deviza|forint)utalás #(\S+)/u', $s, $m)) {
        return ['tipus' => $m[1], 'szam' => $m[2]];
    }
    return null;
}

/**
 * DOS-dátum → ÉÉÉÉ-HH-NN. Elfogadja: 2026.03.26, 26.03.26, 20260326, 2026-03-26.
 * Nem létező nap (2026.04.31): a hónap utolsó napja, és $megj-be kerül a magyarázat (ahogy az első import is tette).
 * Üres / 00.00.00 → null.
 */
function mig_datum(?string $s, ?string &$megj = null): ?string
{
    $s = trim((string)$s);
    if ($s === '' || preg_match('/^[0. \-\/]+$/', $s)) {
        return null;
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        [$e, $h, $n] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{2,4})[.\-\/ ](\d{1,2})[.\-\/ ](\d{1,2})\.?$/', $s, $m)) {
        [$e, $h, $n] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($e < 100) {
            $e += $e >= 80 ? 1900 : 2000;
        }
    } else {
        $megj = "érvénytelen dátum a DOS-ban: „{$s}”";
        return null;
    }
    if ($h < 1 || $h > 12 || $n < 1) {
        $megj = "érvénytelen dátum a DOS-ban: „{$s}”";
        return null;
    }
    $utolso = (int)date('t', mktime(0, 0, 0, $h, 1, $e));
    if ($n > $utolso) {
        $megj = sprintf('%04d.%02d.%02d (nem létező nap) – a hónap utolsó napja került be', $e, $h, $n);
        $n = $utolso;
    }
    return sprintf('%04d-%02d-%02d', $e, $h, $n);
}

/** „2 159,00 EUR”, „-152.00”, „1.234,56” → „2159.00” (2 tizedes, string); érvénytelen → null */
function mig_osszeg(?string $s): ?string
{
    $s = trim(preg_replace('/[A-Za-z\x{00A0}\x{202F}]+/u', ' ', (string)$s) ?? '');
    $s = str_replace(' ', '', $s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
        $s = str_replace(['.', ','], ['', '.'], $s);       // 1.234,56
    } elseif (str_contains($s, ',')) {
        $s = str_replace(',', '.', $s);                    // 2159,00
    }
    if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        return null;
    }
    return number_format(round((float)$s, 2), 2, '.', '');
}

/** Pénzösszeg magyar formában a megjegyzésekhez: 2159.00 → „2 159,00” */
function mig_osszeg_hu(string $o): string
{
    return number_format((float)$o, 2, ',', ' ');
}

/** DOS-dátum a megjegyzésekhez: 2026-03-26 → „2026.03.26” */
function mig_datum_hu(?string $d): string
{
    return $d ? str_replace('-', '.', $d) : '–';
}

/** A számlaszám „laza” kulcsa (mint az app imp_laza_kulcs-a): a számcsoportok bevezető nullái nélkül – „26/0001” = „26/001” */
function mig_laza_kulcs(string $szamlaszam): string
{
    $s = preg_replace('/\s*\[[^\[\]]*\]\s*$/u', '', $szamlaszam) ?? $szamlaszam;   // a kötésen belüli toldalék nélkül
    return norm_azonosito(preg_replace_callback('/\d+/', fn($m) => ltrim($m[0], '0') === '' ? '0' : ltrim($m[0], '0'), $s) ?? '');
}

/** Cégnév összevetéshez: kisbetű, ékezet és írásjel nélkül („Példa Kft.” = „pelda kft”) */
function mig_nev_kulcs(string $nev): string
{
    $s = mb_strtolower(trim($nev), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u', 'ä' => 'a', 'ß' => 'ss', 'ô' => 'o', 'û' => 'u']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
}

/** VEVO-kód → a 10-07-i import partnerkódja: „HU-00000321” → „HU-321”; UGYF-kód → „U-<kód>” */
function mig_partnerkod(string $tabla, string $kod): string
{
    if ($tabla === 'VEVO' && preg_match('/^([A-Z]{2})-0*(\d+)$/', $kod, $m)) {
        return $m[1] . '-' . $m[2];
    }
    return 'U-' . $kod;
}

/** Magyar adószám tagolva (11 jegy → 12345678-1-23), más változatlan */
function mig_adoszam(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') {
        return null;
    }
    return preg_match('/^(\d{8})(\d)(\d{2})$/', $s, $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : $s;
}

/**
 * Kimenő DOS-azonosító → BaninaPRO számlaszám, ahogy a 10-07-i import képezte: „KE-26 1126” → „KE-1126/2026”.
 * Az év mindig a számla keltéből jön (a DOS-ban a középső szám gyakran elírt – „KE-19 312” = január 19.), a szám betű
 * szerint marad (vezető nullával, „05,04” is), csak a végéről esik le a „,” / „*”; hiányzó kötőjel: „KE26 126”.
 * @return array{elotag: string, ev: int, szamlaszam: string}|null
 */
function mig_kimeno_szamlaszam(string $azon, ?string $kelt): ?array
{
    if (!preg_match('/^([A-Z]+)-?(\d{2})\s+(.+)$/u', trim($azon), $m)) {
        return null;
    }
    $szam = rtrim(trim($m[3]), ',*. ');
    if ($szam === '') {
        return null;
    }
    $ev = $kelt ? (int)substr($kelt, 0, 4) : 2000 + (int)$m[2];
    return ['elotag' => $m[1], 'ev' => $ev, 'szamlaszam' => "{$m[1]}-$szam/$ev"];
}

/** A kötés éve a DOS-kötésszámból (0-25-0088 → 2025); elírt évnél (0-29, 0-23) a számla éve – ahogy a 10-07-i import */
function mig_kotes_ev(string $kotesszam, ?string $kelt): int
{
    $szamlaEv = $kelt ? (int)substr($kelt, 0, 4) : (int)date('Y');
    if (preg_match('/^\d-(\d{2})-/', $kotesszam, $m)) {
        $ev = 2000 + (int)$m[1];
        if (abs($ev - $szamlaEv) <= 1) {
            return $ev;
        }
    }
    return $szamlaEv;
}
