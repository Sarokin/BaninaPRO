<?php
declare(strict_types=1);

/**
 * BaninaPRO – minimális .xlsx olvasó, függőség nélkül (nem kell zip/ZipArchive bővítmény).
 *  - a ZIP konténert saját kóddal olvassa (stored / deflate → gzinflate)
 *  - a munkalapot XMLReader-rel, sorról sorra dolgozza fel (kis memória)
 *  - shared string-ek, inline string-ek, számok, logikai értékek; _x000D_ jelölések feloldva
 *
 *  xlsx_olvas($fajl) → ['lapok' => [['nev' => 'Munka1', 'sorok' => [ sorszám => [ 'A' => érték, 'B' => … ] ]], …]]
 */

final class XlsxHiba extends RuntimeException {}

/** ZIP közepes könyvtár beolvasása → [név => [offset, tömörített méret, méret, módszer]] */
function xlsx_zip_bejegyzesek(string $adat): array
{
    $meret = strlen($adat);
    // End of central directory rekord keresése (a fájl vége felől, max 64 kB + 22 bájt komment)
    $eocd = false;
    for ($p = $meret - 22; $p >= max(0, $meret - 65557); $p--) {
        if (substr($adat, $p, 4) === "PK\x05\x06") {
            $eocd = $p;
            break;
        }
    }
    if ($eocd === false) {
        throw new XlsxHiba('A fájl nem érvényes .xlsx (nem ZIP-konténer).');
    }
    $e = unpack('vlemez/vlemezcd/vdb/vosszes/Vmeret/Voffset', substr($adat, $eocd + 4, 18));
    $p = (int)$e['offset'];
    $ki = [];
    for ($i = 0; $i < (int)$e['osszes']; $i++) {
        if (substr($adat, $p, 4) !== "PK\x01\x02") {
            throw new XlsxHiba('Sérült ZIP-könyvtár az .xlsx fájlban.');
        }
        $h = unpack('vverzio/vszukseges/vflag/vmodszer/vido/vdatum/Vcrc/Vtomoritett/Veredeti/vnevhossz/vextrahossz/vkommenthossz/vlemez/vbelso/Vkulso/Vhelyi', substr($adat, $p + 4, 42));
        $nev = substr($adat, $p + 46, (int)$h['nevhossz']);
        $ki[$nev] = ['helyi' => (int)$h['helyi'], 'tomoritett' => (int)$h['tomoritett'], 'eredeti' => (int)$h['eredeti'], 'modszer' => (int)$h['modszer']];
        $p += 46 + (int)$h['nevhossz'] + (int)$h['extrahossz'] + (int)$h['kommenthossz'];
    }
    return $ki;
}

/** Egy ZIP-bejegyzés kicsomagolása */
function xlsx_zip_kiolvas(string $adat, array $b): string
{
    $p = $b['helyi'];
    if (substr($adat, $p, 4) !== "PK\x03\x04") {
        throw new XlsxHiba('Sérült ZIP-bejegyzés az .xlsx fájlban.');
    }
    $h = unpack('vverzio/vflag/vmodszer/vido/vdatum/Vcrc/Vtomoritett/Veredeti/vnevhossz/vextrahossz', substr($adat, $p + 4, 26));
    $kezdet = $p + 30 + (int)$h['nevhossz'] + (int)$h['extrahossz'];
    $nyers = substr($adat, $kezdet, $b['tomoritett']);
    if ($b['modszer'] === 0) {
        return $nyers;
    }
    if ($b['modszer'] !== 8) {
        throw new XlsxHiba('Nem támogatott tömörítés az .xlsx fájlban (' . $b['modszer'] . ').');
    }
    $ki = @gzinflate($nyers);
    if ($ki === false) {
        throw new XlsxHiba('A fájl tartalma nem csomagolható ki (sérült .xlsx?).');
    }
    return $ki;
}

/** Oszlopbetű → sorszám (A=1, Z=26, AA=27 …) */
function xlsx_oszlop_szam(string $betu): int
{
    $n = 0;
    foreach (str_split(strtoupper($betu)) as $c) {
        $n = $n * 26 + (ord($c) - 64);
    }
    return $n;
}

/** Sorszám → oszlopbetű */
function xlsx_oszlop_betu(int $n): string
{
    $s = '';
    while ($n > 0) {
        $m = ($n - 1) % 26;
        $s = chr(65 + $m) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

/** Excel _x000D_ jellegű escape-ek és felesleges CR-ek eltávolítása */
function xlsx_szoveg_tisztit(string $s): string
{
    $s = preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', fn($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $s) ?? $s;
    return str_replace("\r", '', $s);
}

/** Excel dátum-sorszám → ÉÉÉÉ-HH-NN (1900-as rendszer) */
function xlsx_serial_datum(float $n): ?string
{
    if ($n < 1 || $n > 2958465) {
        return null;
    }
    $t = (int)round(($n - 25569) * 86400);   // 25569 = 1970-01-01
    return gmdate('Y-m-d', $t);
}

/**
 * .xlsx beolvasása. $csakElso = true: csak az első munkalap.
 * Visszatérés: ['lapok' => [ ['nev' => …, 'sorok' => [ sor => ['A' => érték …] ], 'max_sor' => n] ]]
 * Az értékek: string (shared/inline), float/int (szám), bool; a dátumformátumú számok NEM konvertálódnak
 * (a hívó a dátum-oszlopoknál xlsx_serial_datum-mal kezeli).
 */
function xlsx_olvas(string $fajl, bool $csakElso = true, int $maxSor = 100000): array
{
    $adat = @file_get_contents($fajl);
    if ($adat === false || $adat === '') {
        throw new XlsxHiba('A fájl nem olvasható.');
    }
    if (substr($adat, 0, 2) !== 'PK') {
        throw new XlsxHiba('A fájl nem .xlsx formátumú (régi .xls vagy más fájl). Excelben: Mentés másként → „Excel-munkafüzet (*.xlsx)”.');
    }
    $bej = xlsx_zip_bejegyzesek($adat);
    if (!isset($bej['xl/workbook.xml'])) {
        throw new XlsxHiba('A fájlban nincs munkafüzet (xl/workbook.xml) – nem Excel-munkafüzet.');
    }
    if (!class_exists('XMLReader')) {
        throw new XlsxHiba('A szerveren hiányzik az XMLReader PHP-bővítmény (xml).');
    }

    // shared strings
    $ss = [];
    if (isset($bej['xl/sharedStrings.xml'])) {
        $x = new XMLReader();
        $x->XML(xlsx_zip_kiolvas($adat, $bej['xl/sharedStrings.xml']), 'UTF-8', LIBXML_NONET);
        $akt = null;
        while ($x->read()) {
            if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'si') {
                $akt = '';
            } elseif ($x->nodeType === XMLReader::ELEMENT && $x->localName === 't' && $akt !== null) {
                $akt .= $x->readString();
            } elseif ($x->nodeType === XMLReader::END_ELEMENT && $x->localName === 'si') {
                $ss[] = xlsx_szoveg_tisztit($akt);
                $akt = null;
            }
        }
        $x->close();
    }

    // munkalapok sorrendje: workbook.xml (sheet r:id) + rels (r:id → fájl)
    $wb = simplexml_load_string(xlsx_zip_kiolvas($adat, $bej['xl/workbook.xml']), 'SimpleXMLElement', LIBXML_NONET);
    $rels = [];
    if (isset($bej['xl/_rels/workbook.xml.rels'])) {
        $rx = simplexml_load_string(xlsx_zip_kiolvas($adat, $bej['xl/_rels/workbook.xml.rels']), 'SimpleXMLElement', LIBXML_NONET);
        if ($rx) {
            foreach ($rx->Relationship as $r) {
                $cel = (string)$r['Target'];
                $cel = str_starts_with($cel, '/') ? ltrim($cel, '/') : 'xl/' . $cel;
                $rels[(string)$r['Id']] = $cel;
            }
        }
    }
    $lapok = [];
    if ($wb && isset($wb->sheets)) {
        foreach ($wb->sheets->sheet as $sh) {
            $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $lapok[] = ['nev' => (string)$sh['name'], 'fajl' => $rels[$rid] ?? null];
        }
    }
    if (!$lapok) {
        // tartalék: sheet1.xml
        $lapok[] = ['nev' => 'Munka1', 'fajl' => 'xl/worksheets/sheet1.xml'];
    }

    $ki = [];
    foreach ($lapok as $lap) {
        if (!$lap['fajl'] || !isset($bej[$lap['fajl']]) || !str_contains($lap['fajl'], 'worksheets/')) {
            continue;   // diagramlap (chartsheet) vagy hiányzó fájl
        }
        $xml = xlsx_zip_kiolvas($adat, $bej[$lap['fajl']]);
        $x = new XMLReader();
        $x->XML($xml, 'UTF-8', LIBXML_NONET);
        $sorok = [];
        $sor = 0;
        $maxSorLatott = 0;
        $cellaRef = null;
        $cellaTip = null;
        $ertek = null;
        $inlineSzoveg = null;
        while ($x->read()) {
            if ($x->nodeType === XMLReader::ELEMENT) {
                $ln = $x->localName;
                if ($ln === 'row') {
                    $sor = (int)$x->getAttribute('r') ?: $sor + 1;
                    if ($sor > $maxSor) {
                        break;
                    }
                } elseif ($ln === 'c') {
                    $cellaRef = (string)$x->getAttribute('r');
                    $cellaTip = (string)$x->getAttribute('t');
                    $ertek = null;
                    $inlineSzoveg = null;
                    if ($x->isEmptyElement) {
                        $cellaRef = null;
                    }
                } elseif ($ln === 'v' && $cellaRef !== null) {
                    $ertek = $x->readString();
                } elseif ($ln === 't' && $cellaRef !== null && $cellaTip === 'inlineStr') {
                    $inlineSzoveg = ($inlineSzoveg ?? '') . $x->readString();
                }
            } elseif ($x->nodeType === XMLReader::END_ELEMENT && $x->localName === 'c' && $cellaRef !== null) {
                $v = null;
                if ($cellaTip === 's') {
                    $v = $ss[(int)$ertek] ?? '';
                } elseif ($cellaTip === 'inlineStr') {
                    $v = xlsx_szoveg_tisztit((string)$inlineSzoveg);
                } elseif ($cellaTip === 'str') {
                    $v = xlsx_szoveg_tisztit((string)$ertek);
                } elseif ($cellaTip === 'b') {
                    $v = $ertek === '1';
                } elseif ($cellaTip === 'e') {
                    $v = null; // #N/A stb.
                } elseif ($ertek !== null && $ertek !== '') {
                    $v = is_numeric($ertek) ? ($ertek + 0) : $ertek;
                }
                if ($v !== null && $v !== '') {
                    if (preg_match('/^([A-Z]+)(\d+)$/', $cellaRef, $m)) {
                        $r = (int)$m[2];
                        $sorok[$r][$m[1]] = $v;
                        $maxSorLatott = max($maxSorLatott, $r);
                    }
                }
                $cellaRef = null;
            }
        }
        $x->close();
        $ki[] = ['nev' => $lap['nev'], 'sorok' => $sorok, 'max_sor' => $maxSorLatott];
        if ($csakElso && $sorok) {
            break;   // az első munkalap, amelyen van adat
        }
    }
    if (!$ki) {
        throw new XlsxHiba('A munkafüzetben nincs olvasható munkalap.');
    }
    if ($csakElso) {
        // ha több üres lap után jött az adatlap, csak azt adjuk vissza
        foreach ($ki as $lap) {
            if ($lap['sorok']) {
                return ['lapok' => [$lap]];
            }
        }
        return ['lapok' => [$ki[0]]];
    }
    return ['lapok' => $ki];
}
