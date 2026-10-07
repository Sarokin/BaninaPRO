<?php
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/api_reszteljesites.php';
require_once __DIR__ . '/api_riport.php';

/**
 * Nyomtatási lista PDF: a kosárba gyűjtött tételek ({t, id, e?}) FRISS adatbázis-adatokból,
 * típusonként csoportosítva, A4 álló, helytakarékos táblázatokkal.
 * KÉTNYELVŰ (1.9-től): minden magyar felirat / mondat alatt halványabban az angol megfelelője
 * (oszlopfejlécek, szakaszcímek, státuszok, összesítők, alsorok, egyenleg, fej- és lábléc).
 */

// 'osszevetes': az Összevetés oldal teljes lekérdezése (cég + pénznem + teljesítési időszak – a tétel 'q' mezőjében)
// 'allapot': állapot vizsgálat (1.19) – irány + cég (+ kötés) + kelt szerinti időszak + vizsgált nap (a 'q' mezőben)
const NY_TIPUSOK = ['osszevetes', 'allapot', 'ceg', 'kotes', 'bejovo', 'utalas', 'kimeno', 'kivonat'];

/** Angol megfelelők (státusz, mód) */
const NY_EN_STATUSZ = [
    'FIZETENDO' => 'PAYABLE', 'UTALASHOZ_ADVA' => 'IN TRANSFER', 'FIZETVE' => 'PAID', 'BESZAMITVA' => 'OFFSET',
    'NYITOTT' => 'OPEN', 'FIZETETT' => 'PAID', 'UTALVA' => 'TRANSFERRED', 'MANUAL' => 'manual', 'HATARERTEK' => 'threshold',
    'FIZETETLEN' => 'UNPAID', 'MEG_NEM_LETEZETT' => 'NOT YET ISSUED',
];

function ny_datum(?string $d): string
{
    if (!$d || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) {
        return '';
    }
    return "{$m[1]}.{$m[2]}.{$m[3]}.";
}

function ny_osszeg($o, string $pn = ''): string
{
    $f = (float)$o;
    $s = number_format(abs($f), 2, ',', ' ');
    if (str_ends_with($s, ',00')) {
        $s = substr($s, 0, -3);
    }
    return ($f < 0 ? '−' : '') . $s . ($pn !== '' ? ' ' . $pn : '');
}

function ny_statusz(string $s): string
{
    return ['FIZETENDO' => 'FIZETENDŐ', 'UTALASHOZ_ADVA' => 'UTALÁSHOZ ADVA', 'FIZETVE' => 'FIZETVE', 'BESZAMITVA' => 'BESZÁMÍTVA',
            'NYITOTT' => 'NYITOTT', 'FIZETETT' => 'FIZETETT', 'UTALVA' => 'UTALVA', 'MANUAL' => 'manuális', 'HATARERTEK' => 'határértékes',
            'FIZETETLEN' => 'FIZETETLEN', 'MEG_NEM_LETEZETT' => 'MÉG NEM LÉTEZETT'][$s] ?? $s;
}

function ny_en(string $s): string
{
    return NY_EN_STATUSZ[$s] ?? $s;
}

function ny_in(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

/** Érvényes ÉÉÉÉ-HH-NN dátum-e (a kosárból érkező összevetés időszakához) */
function ny_datum_ok(string $d): bool
{
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Az egyenleg jelentése szavakban, magyarul és angolul (pozitív: én tartozom a partnernek, negatív: ő tartozik nekem) */
function ny_egyenleg_szoveg(float $v, string $pn): array
{
    if ($v > 0) {
        return [ny_osszeg($v, $pn) . ' – ennyivel tartozom a partnernek', ny_osszeg($v, $pn) . ' – I owe the partner this amount'];
    }
    if ($v < 0) {
        return [ny_osszeg(-$v, $pn) . ' – ennyivel tartozik nekem a partner', ny_osszeg(-$v, $pn) . ' – the partner owes me this amount'];
    }
    return ["0 $pn – rendezve", "0 $pn – settled"];
}

/**
 * ÖSSZEVETÉS – ugyanaz, mint az Összevetés oldalon (cég · pénznem · teljesítési időszak): egyenleg-összesítő,
 * a bejövő számlák kötésenként, a kimenő számlák, mindkettő részteljesítés-alsorokkal és bontott összesítővel.
 * $d: osszevetes_reszletek_adat() eredménye.
 */
function ny_osszevetes(PdfIro $pdf, array $d): void
{
    $pn = $d['penznem'];
    $b = $d['bejovo'];
    $k = $d['kimeno'];
    $zold = [1, 127, 1];
    $narancs = [217, 108, 0];
    $piros = [214, 69, 65];
    $idoszak = ny_datum($d['tol']) . ' – ' . ny_datum($d['ig']);
    $pdf->szakasz('Összevetés – ' . $d['ceg']['nev'], "$pn · teljesítés $idoszak", [254, 131, 2],
        'Reconciliation – ' . $d['ceg']['nev'], "$pn · completion $idoszak");

    // 1) egyenleg-összesítő: bejövő · kimenő · egyenleg (bejövő − kimenő)
    $sor = fn(string $hu, string $en, array $o) => ['cellak' => [$hu, (string)$o['db'], ny_osszeg($o['osszes'], $pn), ny_osszeg($o['fizetve'], $pn), ny_osszeg($o['nyitott'], $pn)],
                                                   'en' => [0 => $en], 'szinek' => [3 => $zold, 4 => $narancs]];
    $pdf->tablazat([
        ['c' => '', 'en' => '', 'w' => 52, 'a' => 'L'], ['c' => 'Számlák', 'en' => 'Invoices', 'w' => 18, 'a' => 'C'],
        ['c' => 'Összes pénzforgalom', 'en' => 'Total turnover', 'w' => 40, 'a' => 'R'], ['c' => 'FIZETVE / beszámítva', 'en' => 'PAID / offset', 'w' => 40, 'a' => 'R'],
        ['c' => 'NYITOTT – még fizetendő', 'en' => 'OPEN – still payable', 'w' => 40, 'a' => 'R'],
    ], [
        $sor('Bejövő számlák', 'Incoming invoices', $b['osszesites']),
        $sor('Kimenő számlák', 'Outgoing invoices', $k['osszesites']),
        ['cellak' => ['EGYENLEG (bejövő − kimenő)', '', ny_osszeg(round($b['osszesites']['osszes'] - $k['osszesites']['osszes'], 2), $pn),
                      ny_osszeg($d['egyenleg_fizetve'], $pn), ny_osszeg($d['egyenleg_nyitott'], $pn)],
         'en' => [0 => 'BALANCE (incoming − outgoing)'], 'stilus' => 'osszes', 'szinek' => [3 => $zold, 4 => $narancs]],
    ], ['pt' => 8.5]);
    [$nyHu, $nyEn] = ny_egyenleg_szoveg((float)$d['egyenleg_nyitott'], $pn);
    $pdf->helyBiztosit(16);
    $y = $pdf->szoveg2($pdf->margoBal, $pdf->y + 3.2, 'NYITOTT egyenleg: ' . $nyHu, 'Open balance: ' . $nyEn, 'bold', 9.5, [23, 23, 23]);
    $y = $pdf->szoveg2($pdf->margoBal, $y + 4.6,
        'Egyenleg = bejövő − kimenő, a teljesítés dátuma szerint · pozitív: én tartozom a partnernek, negatív: a partner tartozik nekem.',
        'Balance = incoming − outgoing, by completion date · positive: I owe the partner, negative: the partner owes me.', 'regular', 7, [115, 115, 115]);
    $pdf->y = $y + 3;

    // 2) bejövő számlák kötésenként (a kötés sora félkövér: nyitott része és teljes összege)
    $pdf->szakasz('Összevetés · bejövő számlák', $b['osszesites']['db'] . ' számla · ' . count($b['kotesek']) . ' kötés', $zold,
        'Reconciliation · incoming invoices', $b['osszesites']['db'] . ' invoices · ' . count($b['kotesek']) . ' contracts');
    $sorok = [];
    $t = [];
    foreach ($b['kotesek'] as $kt) {
        $sorok[] = ['cellak' => ['Kötés ' . $kt['kotes_kod'] . ($kt['kotes_megnevezes'] ? ' · ' . $kt['kotes_megnevezes'] : ''), '', '', '', '', '', '',
                                 'nyitott ' . ny_osszeg($kt['nyitott'], $pn), ny_osszeg($kt['osszeg'], $pn)],
                    'en' => [0 => 'Contract ' . $kt['kotes_kod'], 7 => 'open'], 'stilus' => 'csoport', 'span' => [0 => 7],
                    'szinek' => [7 => $narancs, 8 => (float)$kt['osszeg'] < 0 ? $piros : [23, 23, 23]]];
        foreach ($kt['szamlak'] as $s) {
            $s['penznem'] = $pn;
            $lezart = in_array($s['statusz'], ['FIZETVE', 'BESZAMITVA'], true);
            ny_gyujt($t, $s, !$lezart);
            $extra = trim(($s['utalas_uid'] ?? '') . (!empty($s['beszam']) ? "\nBESZÁM: " . $s['beszam'] : ''));
            $szin = ['FIZETVE' => $zold, 'BESZAMITVA' => $zold, 'UTALASHOZ_ADVA' => [109, 90, 0]][$s['statusz']] ?? $narancs;
            $sorok[] = ['cellak' => [$s['kod'], $s['szamlaszam'], ny_datum($s['kelt']), ny_datum($s['teljesites_datum']), ny_datum($s['fizetesi_hatarido']),
                                     $lezart ? ny_datum($s['fizetve_datum']) : '', ny_statusz($s['statusz']), $extra, ny_osszeg($s['osszeg'], $pn)],
                        'en' => [6 => ny_en($s['statusz'])] + (!empty($s['beszam']) ? [7 => '(BESZÁM = ref. no.)'] : []),
                        'szinek' => [6 => $szin, 8 => (float)$s['osszeg'] < 0 ? $piros : [23, 23, 23], 5 => $zold]];
            $sorok = array_merge($sorok, ny_reszt_sorok($s, !$lezart, 9, 5, 8));
        }
    }
    $sorok = $sorok ? array_merge($sorok, ny_szamla_osszesito($t, 9, 8))
        : [['cellak' => ['Nincs bejövő számla az időszakban.'], 'en' => [0 => 'No incoming invoices in the period.'], 'span' => [0 => 9]]];
    $pdf->tablazat([
        ['c' => 'Számla ID', 'en' => 'Invoice ID', 'w' => 27, 'a' => 'L'], ['c' => 'Számlaszám', 'en' => 'Invoice no.', 'w' => 27, 'a' => 'L'],
        ['c' => 'Kelt', 'en' => 'Issued', 'w' => 14, 'a' => 'C'], ['c' => 'Teljesítés', 'en' => 'Completion', 'w' => 14, 'a' => 'C'], ['c' => 'Határidő', 'en' => 'Due date', 'w' => 14, 'a' => 'C'],
        ['c' => 'Utalva', 'en' => 'Transferred', 'w' => 14, 'a' => 'C'], ['c' => 'Státusz', 'en' => 'Status', 'w' => 16, 'a' => 'C'], ['c' => 'Utalás / BESZÁM', 'en' => 'Transfer / ref.', 'w' => 24, 'a' => 'L'],
        ['c' => 'Összeg', 'en' => 'Amount', 'w' => 22, 'a' => 'R'],
    ], $sorok, ['pt' => 7.0]);

    // 3) kimenő számlák
    $pdf->szakasz('Összevetés · kimenő számlák', $k['osszesites']['db'] . ' számla', $zold,
        'Reconciliation · outgoing invoices', $k['osszesites']['db'] . ' invoices');
    $sorok = [];
    $t = [];
    foreach ($k['szamlak'] as $s) {
        $s['penznem'] = $pn;
        $nyitott = $s['statusz'] === 'NYITOTT';
        ny_gyujt($t, $s, $nyitott);
        $sorok[] = ['cellak' => [$s['kod'], $s['szamlaszam'], ny_datum($s['kelt']), ny_datum($s['teljesites_datum']), ny_datum($s['fizetesi_hatarido']),
                                 $nyitott ? '' : ny_datum($s['fizetve_datum']), ny_statusz($s['statusz']), $s['banki_azonosito'] ?? '', ny_osszeg($s['osszeg'], $pn)],
                    'en' => [6 => ny_en($s['statusz'])],
                    'szinek' => [6 => $nyitott ? $narancs : $zold, 8 => (float)$s['osszeg'] < 0 ? $piros : [23, 23, 23], 5 => $zold]];
        $sorok = array_merge($sorok, ny_reszt_sorok($s, $nyitott, 9, 5, 8));
    }
    $sorok = $sorok ? array_merge($sorok, ny_szamla_osszesito($t, 9, 8))
        : [['cellak' => ['Nincs kimenő számla az időszakban.'], 'en' => [0 => 'No outgoing invoices in the period.'], 'span' => [0 => 9]]];
    $pdf->tablazat([
        ['c' => 'Számla ID', 'en' => 'Invoice ID', 'w' => 27, 'a' => 'L'], ['c' => 'Számlaszám', 'en' => 'Invoice no.', 'w' => 27, 'a' => 'L'],
        ['c' => 'Kelt', 'en' => 'Issued', 'w' => 14, 'a' => 'C'], ['c' => 'Teljesítés', 'en' => 'Completion', 'w' => 14, 'a' => 'C'], ['c' => 'Határidő', 'en' => 'Due date', 'w' => 14, 'a' => 'C'],
        ['c' => 'Fizetve', 'en' => 'Paid on', 'w' => 14, 'a' => 'C'], ['c' => 'Státusz', 'en' => 'Status', 'w' => 16, 'a' => 'C'], ['c' => 'Banki azonosító', 'en' => 'Bank reference', 'w' => 24, 'a' => 'L'],
        ['c' => 'Összeg', 'en' => 'Amount', 'w' => 22, 'a' => 'R'],
    ], $sorok, ['pt' => 7.0]);
}

/** Hosszabb kétnyelvű megjegyzés a lap szélességére tördelve (a magyar sorok, alattuk halványabban az angolok); utána $utana mm hely */
function ny_megjegyzes(PdfIro $pdf, string $hu, string $en, float $utana = 3): void
{
    $w = $pdf->szelesseg();
    $hs = $pdf->tordel($hu, 'regular', 7, $w);
    $es = $pdf->tordel($en, 'regular', 6.2, $w);
    $pdf->helyBiztosit(count($hs) * 3.5 + count($es) * 3.2 + 4);
    $y = $pdf->y + 2.5;
    foreach ($hs as $i => $sor) {
        $y += $i ? 3.5 : 0;
        $pdf->szoveg($pdf->margoBal, $y, $sor, 'regular', 7, [115, 115, 115]);
    }
    foreach ($es as $sor) {
        $y += 3.2;
        $pdf->szoveg($pdf->margoBal, $y, $sor, 'regular', 6.2, $pdf->enSzin);
    }
    $pdf->y = $y + $utana;
}

/**
 * ÁLLAPOT VIZSGÁLAT (1.19) – ugyanaz, mint az oldalon: a teljesítés dátuma szerint az időszakba eső számlák a vizsgált napi állapotukkal,
 * felül az egyenleg a vizsgált napon (pénznemenként), alatta a számlák a részteljesítésekkel (csak a vizsgált napig érkezettek).
 * $d: allapot_vizsgalat_adat() eredménye.
 */
function ny_allapot(PdfIro $pdf, array $d): void
{
    $zold = [1, 127, 1];
    $narancs = [217, 108, 0];
    $piros = [214, 69, 65];
    $szurke = [150, 150, 150];
    $bejovo = $d['irany'] === 'BEJOVO';
    $nap = ny_datum($d['nap']);
    $idoszak = ny_datum($d['tol']) . ' – ' . ny_datum($d['ig']);
    $db = ['FIZETETLEN' => 0, 'rendezett' => 0, 'MEG_NEM_LETEZETT' => 0];
    foreach ($d['szamlak'] as $s) {
        $db[in_array($s['allapot'], ['FIZETETLEN', 'MEG_NEM_LETEZETT'], true) ? $s['allapot'] : 'rendezett']++;
    }
    $kotes = $d['kotes'] ? ' · kötés ' . $d['kotes']['kod'] : '';
    $pdf->szakasz("Állapot $nap – " . $d['ceg']['nev'], ($bejovo ? 'bejövő' : 'kimenő') . " számlák · teljesítés $idoszak$kotes", [254, 131, 2],
        "Status on $nap – " . $d['ceg']['nev'], ($bejovo ? 'incoming' : 'outgoing') . " invoices · completion $idoszak" . ($d['kotes'] ? ' · contract ' . $d['kotes']['kod'] : ''));

    // 1) egyenleg a vizsgált napon, pénznemenként
    $esorok = [];
    foreach ($d['egyenleg'] as $pn => $e) {
        $esorok[] = ['cellak' => [$pn, (string)($e['db'] - $e['nem_letezett_db']), ny_osszeg($e['teljes'], $pn), ny_osszeg($e['fizetett'], $pn), $e['reszt'] > 0 ? ny_osszeg($e['reszt'], $pn) : '–', ny_osszeg($e['nyitott'], $pn)],
                     'stilus' => 'osszes', 'szinek' => [3 => $zold, 5 => $narancs]];
    }
    if (!$esorok) {
        $esorok[] = ['cellak' => ['Nincs számla ebben az időszakban.'], 'en' => [0 => 'No invoices in this period.'], 'span' => [0 => 6]];
    }
    $pdf->tablazat([
        ['c' => 'Pénznem', 'en' => 'Currency', 'w' => 20, 'a' => 'L'], ['c' => 'Számlák', 'en' => 'Invoices', 'w' => 18, 'a' => 'C'],
        ['c' => 'Összes pénzforgalom', 'en' => 'Total turnover', 'w' => 38, 'a' => 'R'], ['c' => 'Fizetett / beszámított', 'en' => 'Paid / offset', 'w' => 38, 'a' => 'R'],
        ['c' => 'ebből részteljesítés', 'en' => 'of which partial payments', 'w' => 38, 'a' => 'R'], ['c' => "NYITOTT $nap", 'en' => "OPEN on $nap", 'w' => 38, 'a' => 'R'],
    ], $esorok, ['pt' => 8.5]);
    ny_megjegyzes($pdf,
        "A vizsgált nap ($nap) állapota: {$db['FIZETETLEN']} fizetetlen · {$db['rendezett']} fizetett / beszámított · {$db['MEG_NEM_LETEZETT']} még nem létezett számla. "
        . 'FIZETETLEN = azon a napon még nem volt kifizetve (a hátralékban csak az addig érkezett részteljesítések számítanak); '
        . 'MÉG NEM LÉTEZETT = a teljesítése a vizsgált nap utáni, az egyenlegbe nem számít bele.',
        "Status on the examined day ($nap): {$db['FIZETETLEN']} unpaid · {$db['rendezett']} paid / offset · {$db['MEG_NEM_LETEZETT']} not yet issued. "
        . 'UNPAID = not yet paid on that day (only partial payments received by then reduce the balance); NOT YET ISSUED = completed after the examined day, not part of the balance.');

    // 2) a számlák a vizsgált napi állapotukkal
    $sorok = [];
    foreach ($d['szamlak'] as $s) {
        $pn = $s['penznem'];
        $allapot = $s['allapot'];
        $nincs = $allapot === 'MEG_NEM_LETEZETT';
        $fizetetlen = $allapot === 'FIZETETLEN';
        $szin = $nincs ? $szurke : ($fizetetlen ? $narancs : $zold);
        $fizetve = $s['fizetve_datum'] ? ($s['fizetve_becsult'] ? '~' : '') . ny_datum($s['fizetve_datum']) : '';
        $sorok[] = ['cellak' => [$s['kod'], $s['szamlaszam'], ny_datum($s['kelt']), ny_datum($s['teljesites_datum']), ny_datum($s['fizetesi_hatarido']),
                                 $fizetve, ny_statusz($allapot), ny_osszeg($s['osszeg'], $pn), $nincs ? '–' : ny_osszeg($s['hatralek_napon'], $pn)],
                    'en' => [6 => ny_en($allapot)] + ($s['fizetve_becsult'] ? [5 => '(≈ due date)'] : []),
                    'szinek' => [6 => $szin, 5 => $s['fizetve_datum'] && $s['fizetve_datum'] <= $d['nap'] ? $zold : $szurke,
                                 7 => $nincs ? $szurke : ((float)$s['osszeg'] < 0 ? $piros : [23, 23, 23]), 8 => $fizetetlen ? $narancs : $szurke]];
        $sorok = array_merge($sorok, ny_reszt_sorok(['reszteljesitesek' => $s['resztek_napon'], 'reszt' => $s['reszt_napon'], 'hatralek' => $s['hatralek_napon'], 'penznem' => $pn],
            $fizetetlen, 9, 5, 7));
    }
    $t = [];
    foreach ($d['egyenleg'] as $pn => $e) {
        $t[$pn] = ['osszes' => $e['teljes'], 'fizetett' => $e['fizetett'], 'nyitott' => $e['nyitott'], 'reszt' => $e['reszt']];
    }
    $sorok = $sorok ? array_merge($sorok, ny_szamla_osszesito($t, 9, 7))
        : [['cellak' => ['Nincs számla ebben az időszakban.'], 'en' => [0 => 'No invoices in this period.'], 'span' => [0 => 9]]];
    $pdf->tablazat([
        ['c' => 'Számla ID', 'en' => 'Invoice ID', 'w' => 30, 'a' => 'L'], ['c' => 'Számlaszám', 'en' => 'Invoice no.', 'w' => 26, 'a' => 'L'],
        ['c' => 'Kelt', 'en' => 'Issued', 'w' => 15, 'a' => 'C'], ['c' => 'Teljesítés', 'en' => 'Completion', 'w' => 15, 'a' => 'C'], ['c' => 'Határidő', 'en' => 'Due date', 'w' => 15, 'a' => 'C'],
        ['c' => $bejovo ? 'Utalva' : 'Fizetve', 'en' => $bejovo ? 'Transferred' : 'Paid on', 'w' => 15, 'a' => 'C'], ['c' => "Állapot $nap", 'en' => "Status on $nap", 'w' => 25, 'a' => 'C'],
        ['c' => 'Összeg', 'en' => 'Amount', 'w' => 24, 'a' => 'R'], ['c' => "Hátralék $nap", 'en' => "Balance due on $nap", 'w' => 25, 'a' => 'R'],
    ], $sorok, ['pt' => 7.0]);
    ny_megjegyzes($pdf,
        'A számlák a mai adatokból, a vizsgált napi állapotukkal. Az Utalva / Fizetve oszlop a tényleges fizetési napot mutatja (szürke: a vizsgált nap utáni; ~ : a régi importált számlánál a határidő).',
        'Invoices from current data, with their status on the examined day. The paid-on column shows the actual payment date (grey: after the examined day; ~ : due date for old imported invoices).', 4);
}

/** „Kelt 2026.01.01. – 2026.09.29.” → „Issued 2026.01.01. – 2026.09.29.” (az időszak-címke angol változata) */
function ny_idoszak_en(string $cimke): string
{
    return strtr($cimke, ['Kelt' => 'Issued', 'Teljesítés' => 'Completion', 'Határidő' => 'Due date']);
}

/** Pénznemenkénti egyszerű összesítő sorok (kötések, utalások, kivonatok) */
function ny_osszesito(array $osszegek, int $oszlopSzam, int $osszegOszlop, string $cimke = 'Összesen', string $cimkeEn = 'Total'): array
{
    $sorok = [];
    ksort($osszegek);
    foreach ($osszegek as $pn => $v) {
        $c = array_fill(0, $oszlopSzam, '');
        $c[0] = $cimke . ' ' . $pn;
        $c[$osszegOszlop] = ny_osszeg($v, $pn);
        $sorok[] = ['cellak' => $c, 'en' => [0 => $cimkeEn . ' ' . $pn], 'stilus' => 'osszes', 'span' => [0 => 2]];
    }
    return $sorok;
}

/**
 * Számlatáblázat összesítő sorai pénznemenként: Összesen (pénzforgalom) · Fizetett / beszámított · Nyitott hátralék.
 * $t[pn] = ['osszes' => , 'fizetett' => , 'nyitott' => , 'reszt' => ]
 */
function ny_szamla_osszesito(array $t, int $oszlopSzam, int $osszegOszlop): array
{
    $sorok = [];
    ksort($t);
    foreach ($t as $pn => $v) {
        $span = [0 => 3];
        $c = array_fill(0, $oszlopSzam, '');
        $c[0] = "Összesen $pn – teljes pénzforgalom";
        $c[$osszegOszlop] = ny_osszeg($v['osszes'], $pn);
        $sorok[] = ['cellak' => $c, 'en' => [0 => "Total $pn – total turnover"], 'stilus' => 'osszes', 'span' => $span];
        $c = array_fill(0, $oszlopSzam, '');
        $c[0] = "Fizetett / beszámított $pn" . ($v['reszt'] > 0 ? ' (ebből részteljesítés ' . ny_osszeg($v['reszt']) . ')' : '');
        $c[$osszegOszlop] = ny_osszeg($v['fizetett'], $pn);
        $sorok[] = ['cellak' => $c, 'en' => [0 => "Paid / offset $pn" . ($v['reszt'] > 0 ? ' (of which partial payments ' . ny_osszeg($v['reszt']) . ')' : '')],
                    'stilus' => 'osszes', 'span' => $span, 'szinek' => [0 => [1, 127, 1], $osszegOszlop => [1, 127, 1]]];
        $c = array_fill(0, $oszlopSzam, '');
        $c[0] = "Nyitott (még fizetendő) $pn";
        $c[$osszegOszlop] = ny_osszeg($v['nyitott'], $pn);
        $sorok[] = ['cellak' => $c, 'en' => [0 => "Open (still payable) $pn"], 'stilus' => 'osszes', 'span' => $span, 'szinek' => [0 => [217, 108, 0], $osszegOszlop => [217, 108, 0]]];
    }
    return $sorok;
}

/** Egy számla részteljesítés-alsorai (+ hátralék sor, ha a számla még nyitott) */
function ny_reszt_sorok(array $s, bool $nyitott, int $oszlopSzam, int $datumOszlop, int $osszegOszlop): array
{
    $sorok = [];
    foreach ($s['reszteljesitesek'] ?? [] as $r) {
        $c = array_fill(0, $oszlopSzam, '');
        $bank = $r['banki_azonosito'] ? ' – bank: ' . $r['banki_azonosito'] : '';
        $c[0] = '» részteljesítés' . $bank;
        $c[$datumOszlop] = ny_datum($r['datum']);
        $c[$osszegOszlop] = ny_osszeg(-(float)$r['osszeg'], $s['penznem']);
        $sorok[] = ['cellak' => $c, 'en' => [0 => '» partial payment' . $bank], 'stilus' => 'al', 'span' => [0 => 3], 'szinek' => [$osszegOszlop => [1, 127, 1]]];
    }
    if ($nyitott && (float)($s['reszt'] ?? 0) > 0) {
        $c = array_fill(0, $oszlopSzam, '');
        $c[0] = '» hátralék (még fizetendő)';
        $c[$osszegOszlop] = ny_osszeg($s['hatralek'], $s['penznem']);
        $sorok[] = ['cellak' => $c, 'en' => [0 => '» balance due (still payable)'], 'stilus' => 'al', 'span' => [0 => 3], 'szinek' => [0 => [217, 108, 0], $osszegOszlop => [217, 108, 0]]];
    }
    return $sorok;
}

/** Pénznemenkénti gyűjtés egy számlához: osszes / fizetett / nyitott / reszt */
function ny_gyujt(array &$t, array $s, bool $nyitott): void
{
    $pn = $s['penznem'];
    $t[$pn] = $t[$pn] ?? ['osszes' => 0.0, 'fizetett' => 0.0, 'nyitott' => 0.0, 'reszt' => 0.0];
    $t[$pn]['osszes'] += (float)$s['osszeg'];
    if ($nyitott) {
        $t[$pn]['nyitott'] += (float)$s['hatralek'];
        $t[$pn]['fizetett'] += (float)$s['reszt'];
        $t[$pn]['reszt'] += (float)$s['reszt'];
    } else {
        $t[$pn]['fizetett'] += (float)$s['osszeg'];
    }
}

function pdf_lista(array $tetelek, array $felhasznalo): string
{
    $pdf = new PdfIro();
    $fontDir = __DIR__ . '/../assets/fonts/';
    $pdf->betuHozzaad('regular', $fontDir . 'banina-regular.ttf');
    $pdf->betuHozzaad('bold', $fontDir . 'banina-bold.ttf');
    $pdf->betuHozzaad('cim', $fontDir . 'banina-cim.ttf');
    $pdf->margoBal = 10;
    $pdf->margoJobb = 10;
    $pdf->margoFel = 28;
    $pdf->margoAl = 18;

    $most = date('Y.m.d. H:i');
    $keszito = $felhasznalo['nev'] ?: $felhasznalo['felhasznalonev'];
    $db = count($tetelek);

    $pdf->fejlecRajzolo = function (PdfIro $p) use ($most, $keszito, $db) {
        $w = $p->szelesseg();
        $x = $p->margoBal;
        // márkasáv (zöld · sárga · narancs)
        $p->teglalap($x, 8, $w / 3, 1.2, [1, 127, 1]);
        $p->teglalap($x + $w / 3, 8, $w / 3, 1.2, [253, 226, 13]);
        $p->teglalap($x + 2 * $w / 3, 8, $w / 3, 1.2, [254, 131, 2]);
        $p->szoveg($x, 17.5, 'Banina', 'cim', 15, [23, 23, 23]);
        $bw = $p->szovegSzelesseg('Banina', 'cim', 15);
        $p->szoveg($x + $bw, 17.5, 'PRO', 'cim', 15, [254, 131, 2]);
        $p->szoveg($x + $w, 13.2, 'Nyomtatási lista', 'bold', 9.5, [23, 23, 23], 'R');
        $p->szoveg($x + $w, 16.6, 'Printed list', 'regular', 7.5, $p->enSzin, 'R');
        $p->szoveg($x + $w, 20.4, "$most · $keszito · $db tétel", 'regular', 7.5, [115, 115, 115], 'R');
        $p->szoveg($x + $w, 23.4, "$db items", 'regular', 6.5, $p->enSzin, 'R');
        $p->vonal($x, 25, $x + $w, 25, [232, 232, 229], 0.3);
    };
    $pdf->lablecRajzolo = function (PdfIro $p, int $n, int $osszes) use ($most) {
        $w = $p->szelesseg();
        $x = $p->margoBal;
        $p->vonal($x, 283.5, $x + $w, 283.5, [232, 232, 229], 0.3);
        $p->szoveg($x, 287, "BaninaPRO · bizalmas · nyomtatva: $most", 'regular', 7, [115, 115, 115]);
        $p->szoveg($x, 290.4, "confidential · printed: $most", 'regular', 6.2, $p->enSzin);
        $p->szoveg($x + $w, 287, "$n / $osszes", 'regular', 7.5, [82, 82, 82], 'R');
        $p->szoveg($x + $w, 290.4, 'oldal / page', 'regular', 6.2, $p->enSzin, 'R');
    };
    $pdf->ujOldal();

    // tételek típusonként
    $ids = array_fill_keys(NY_TIPUSOK, []);
    $egyenlegIds = [];   // kimenő számla id => időszak-címke (a MIND fülről „egyenleg készítés”-sel a kosárba tett számlák)
    $osszevetesek = [];  // az Összevetés oldal lekérdezései: ['ceg', 'pn', 'tol', 'ig']
    $allapotok = [];     // állapot vizsgálatok: ['i', 'ceg', 'kotes', 'tol', 'ig', 'nap']
    foreach ($tetelek as $t) {
        if ($t['t'] === 'osszevetes') {
            $osszevetesek[] = $t['q'];
            continue;
        }
        if ($t['t'] === 'allapot') {
            $allapotok[] = $t['q'];
            continue;
        }
        $ids[$t['t']][] = (int)$t['id'];
        if ($t['t'] === 'kimeno' && isset($t['e'])) {
            $egyenlegIds[(int)$t['id']] = (string)$t['e'];
        }
    }
    foreach ($ids as $k => $v) {
        $ids[$k] = array_values(array_unique(array_filter($v, fn($x) => $x > 0)));
    }
    $vanValami = false;

    // ------------------------------------------------------------- ÖSSZEVETÉSEK (összefoglaló – a lista elején)
    foreach ($osszevetesek as $q) {
        try {
            $d = osszevetes_reszletek_adat((int)$q['ceg'], $q['pn'], $q['tol'], $q['ig']);
        } catch (ApiError $e) {
            continue;   // a cég azóta törlődött
        }
        $vanValami = true;
        ny_osszevetes($pdf, $d);
    }

    // ------------------------------------------------------------- ÁLLAPOT VIZSGÁLATOK (összefoglaló – az összevetések után)
    foreach ($allapotok as $q) {
        try {
            $d = allapot_vizsgalat_adat($q['i'], (int)$q['ceg'], $q['kotes'] > 0 ? (int)$q['kotes'] : null, $q['tol'], $q['ig'], $q['nap']);
        } catch (ApiError $e) {
            continue;   // a cég / kötés azóta törlődött
        }
        $vanValami = true;
        ny_allapot($pdf, $d);
    }

    // ------------------------------------------------------------- CÉGEK
    if ($ids['ceg']) {
        $in = ny_in($ids['ceg']);
        $cegek = db_all("SELECT c.id, c.nev, c.adoszam, c.partnerkod,
                (SELECT COUNT(*) FROM kotesek k WHERE k.ceg_id = c.id) AS kotes_db,
                COALESCE((SELECT SUM(b.osszeg - COALESCE(rb.reszt, 0)) FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id" . RESZT_BEJOVO_JOIN . "WHERE k.ceg_id = c.id AND k.penznem = 'EUR' AND b.statusz IN ('FIZETENDO','UTALASHOZ_ADVA')), 0) AS be_eur,
                COALESCE((SELECT SUM(b.osszeg - COALESCE(rb.reszt, 0)) FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id" . RESZT_BEJOVO_JOIN . "WHERE k.ceg_id = c.id AND k.penznem = 'HUF' AND b.statusz IN ('FIZETENDO','UTALASHOZ_ADVA')), 0) AS be_huf,
                COALESCE((SELECT SUM(s.osszeg - COALESCE(rk.reszt, 0)) FROM kimeno_szamlak s" . RESZT_KIMENO_JOIN . "WHERE s.ceg_id = c.id AND s.penznem = 'EUR' AND s.statusz = 'NYITOTT'), 0) AS ki_eur,
                COALESCE((SELECT SUM(s.osszeg - COALESCE(rk.reszt, 0)) FROM kimeno_szamlak s" . RESZT_KIMENO_JOIN . "WHERE s.ceg_id = c.id AND s.penznem = 'HUF' AND s.statusz = 'NYITOTT'), 0) AS ki_huf
            FROM cegek c WHERE c.id IN ($in) ORDER BY c.nev", $ids['ceg']);
        if ($cegek) {
            $vanValami = true;
            $pdf->szakasz('Cégek', count($cegek) . ' tétel', [1, 127, 1], 'Companies', count($cegek) . ' items');
            $sorok = [];
            $t = ['be_eur' => 0, 'be_huf' => 0, 'ki_eur' => 0, 'ki_huf' => 0];
            foreach ($cegek as $c) {
                foreach ($t as $k => $_) {
                    $t[$k] += (float)$c[$k];
                }
                $sorok[] = ['cellak' => [$c['nev'], trim(($c['adoszam'] ?? '') . ($c['partnerkod'] ? ' · P' . $c['partnerkod'] : '')), (string)$c['kotes_db'], ny_osszeg($c['be_eur'], 'EUR'), ny_osszeg($c['be_huf'], 'HUF'), ny_osszeg($c['ki_eur'], 'EUR'), ny_osszeg($c['ki_huf'], 'HUF')]];
            }
            $sorok[] = ['cellak' => ['Összesen', '', '', ny_osszeg($t['be_eur'], 'EUR'), ny_osszeg($t['be_huf'], 'HUF'), ny_osszeg($t['ki_eur'], 'EUR'), ny_osszeg($t['ki_huf'], 'HUF')], 'en' => [0 => 'Total'], 'stilus' => 'osszes'];
            $pdf->tablazat([
                ['c' => 'Cég', 'en' => 'Company', 'w' => 46, 'a' => 'L'], ['c' => 'Adószám · partnerkód', 'en' => 'Tax no. · partner code', 'w' => 30, 'a' => 'L'], ['c' => 'Kötések', 'en' => 'Contracts', 'w' => 14, 'a' => 'C'],
                ['c' => 'Bejövő nyitott EUR', 'en' => 'Incoming open EUR', 'w' => 25, 'a' => 'R'], ['c' => 'Bejövő nyitott HUF', 'en' => 'Incoming open HUF', 'w' => 25, 'a' => 'R'],
                ['c' => 'Kimenő nyitott EUR', 'en' => 'Outgoing open EUR', 'w' => 25, 'a' => 'R'], ['c' => 'Kimenő nyitott HUF', 'en' => 'Outgoing open HUF', 'w' => 25, 'a' => 'R'],
            ], $sorok);
        }
    }

    // ------------------------------------------------------------- KÖTÉSEK
    if ($ids['kotes']) {
        $in = ny_in($ids['kotes']);
        $kot = db_all(KOTES_SQL . "WHERE k.id IN ($in) GROUP BY k.id ORDER BY k.kod", $ids['kotes']);
        if ($kot) {
            $vanValami = true;
            $pdf->szakasz('Kötések', count($kot) . ' tétel', [1, 127, 1], 'Contracts', count($kot) . ' items');
            $sorok = [];
            $t = [];
            foreach ($kot as $k) {
                $k = kotes_sor_feldolgoz($k);
                $t[$k['penznem']] = ($t[$k['penznem']] ?? 0) + $k['teljes'];
                $sorok[] = ['cellak' => [$k['kod'], $k['ceg_nev'], $k['megnevezes'] ?? '', ny_statusz($k['statusz']), ny_osszeg($k['fizetendo']), ny_osszeg($k['utalas_alatt']), ny_osszeg($k['fizetett']), ny_osszeg($k['teljes'], $k['penznem'])],
                           'en' => [3 => ny_en($k['statusz'])],
                           'szinek' => [3 => $k['statusz'] === 'FIZETETT' ? [1, 127, 1] : [217, 108, 0]]];
            }
            $sorok = array_merge($sorok, ny_osszesito($t, 8, 7));
            $pdf->tablazat([
                ['c' => 'Kötés ID', 'en' => 'Contract ID', 'w' => 24, 'a' => 'L'], ['c' => 'Cég', 'en' => 'Company', 'w' => 35, 'a' => 'L'], ['c' => 'Megnevezés', 'en' => 'Description', 'w' => 36, 'a' => 'L'], ['c' => 'Státusz', 'en' => 'Status', 'w' => 15, 'a' => 'C'],
                ['c' => 'Fizetendő', 'en' => 'Payable', 'w' => 19, 'a' => 'R'], ['c' => 'Utalás alatt', 'en' => 'In transfer', 'w' => 19, 'a' => 'R'], ['c' => 'Fizetett', 'en' => 'Paid', 'w' => 19, 'a' => 'R'], ['c' => 'Teljes', 'en' => 'Total', 'w' => 23, 'a' => 'R'],
            ], $sorok);
        }
    }

    // ------------------------------------------------------------- BEJÖVŐ SZÁMLÁK
    if ($ids['bejovo']) {
        $in = ny_in($ids['bejovo']);
        $sz = db_all(BEJOVO_SQL . "WHERE b.id IN ($in) ORDER BY b.fizetesi_hatarido, b.kod", $ids['bejovo']);
        if ($sz) {
            $vanValami = true;
            $sz = reszt_csatol(array_map('bejovo_sor_feldolgoz', $sz), 'BEJOVO');
            $pdf->szakasz('Bejövő számlák', count($sz) . ' tétel · fizetési határidő szerint', [1, 127, 1], 'Incoming invoices', count($sz) . ' items · by due date');
            $sorok = [];
            $t = [];
            foreach ($sz as $s) {
                $lezart = in_array($s['statusz'], ['FIZETVE', 'BESZAMITVA'], true);
                ny_gyujt($t, $s, !$lezart);
                $extra = trim(($s['utalas_uid'] ? $s['utalas_uid'] : '') . ($s['beszam'] ? "\nBESZÁM: " . $s['beszam'] : ''));
                $szin = ['FIZETVE' => [1, 127, 1], 'BESZAMITVA' => [1, 127, 1], 'UTALASHOZ_ADVA' => [109, 90, 0]][$s['statusz']] ?? [217, 108, 0];
                $sorok[] = ['cellak' => [$s['kod'], $s['ceg_nev'], $s['szamlaszam'], ny_datum($s['kelt']), ny_datum($s['teljesites_datum']), ny_datum($s['fizetesi_hatarido']),
                                         $lezart ? ny_datum($s['fizetve_datum']) : '', ny_osszeg($s['osszeg'], $s['penznem']), ny_statusz($s['statusz']), $extra],
                           'en' => [8 => ny_en($s['statusz'])] + ($s['beszam'] ? [9 => '(BESZÁM = ref. no.)'] : []),
                           'szinek' => [8 => $szin, 7 => (float)$s['osszeg'] < 0 ? [214, 69, 65] : [23, 23, 23], 6 => [1, 127, 1]]];
                $sorok = array_merge($sorok, ny_reszt_sorok($s, !$lezart, 10, 6, 7));
            }
            $sorok = array_merge($sorok, ny_szamla_osszesito($t, 10, 7));
            $pdf->tablazat([
                ['c' => 'Számla ID', 'en' => 'Invoice ID', 'w' => 27.5, 'a' => 'L'], ['c' => 'Cég', 'en' => 'Company', 'w' => 23.5, 'a' => 'L'], ['c' => 'Számlaszám', 'en' => 'Invoice no.', 'w' => 21.5, 'a' => 'L'],
                ['c' => 'Kelt', 'en' => 'Issued', 'w' => 14, 'a' => 'C'], ['c' => 'Teljesítés', 'en' => 'Completion', 'w' => 14, 'a' => 'C'], ['c' => 'Határidő', 'en' => 'Due date', 'w' => 14, 'a' => 'C'], ['c' => 'Utalva', 'en' => 'Transferred', 'w' => 14, 'a' => 'C'],
                ['c' => 'Összeg', 'en' => 'Amount', 'w' => 21.5, 'a' => 'R'], ['c' => 'Státusz', 'en' => 'Status', 'w' => 16, 'a' => 'C'], ['c' => 'Utalás / BESZÁM', 'en' => 'Transfer / ref.', 'w' => 24, 'a' => 'L'],
            ], $sorok, ['pt' => 7.0]);
        }
    }

    // ------------------------------------------------------------- UTALÁSOK
    if ($ids['utalas']) {
        $in = ny_in($ids['utalas']);
        $ut = db_all(UTALAS_SQL . "WHERE u.id IN ($in) ORDER BY u.uid", $ids['utalas']);
        if ($ut) {
            $vanValami = true;
            $pdf->szakasz('Utalások', count($ut) . ' tétel · a hozzájuk tartozó számlákkal', [1, 127, 1], 'Transfers', count($ut) . ' items · with their invoices');
            $sorok = [];
            $t = [];
            foreach ($ut as $u) {
                $u = utalas_sor_feldolgoz($u);
                $t[$u['penznem']] = ($t[$u['penznem']] ?? 0) + $u['osszeg'];
                $sorok[] = ['cellak' => [$u['uid'], $u['ceg_nev'], ny_statusz($u['statusz']), ny_statusz($u['utalas_mod']) . ($u['hatarertek'] !== null ? "\nlimit " . ny_osszeg($u['hatarertek'], $u['penznem']) : ''), (string)$u['db'], ny_datum($u['utalva_datum']), ny_osszeg($u['osszeg'], $u['penznem']), trim(($u['banki_hivatkozas'] ?? '') . ' ' . ($u['megjegyzes'] ?? ''))],
                           'en' => [2 => ny_en($u['statusz']), 3 => ny_en($u['utalas_mod'])],
                           'szinek' => [2 => $u['statusz'] === 'UTALVA' ? [1, 127, 1] : [217, 108, 0]]];
                $szamlak = db_all('SELECT b.kod, b.k_sorszam, b.szamlaszam, b.osszeg, b.statusz, b.fizetesi_hatarido, k.kod AS kotes_kod, COALESCE(rb.reszt, 0) AS reszt, (b.osszeg - COALESCE(rb.reszt, 0)) AS hatralek
                                       FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN . 'WHERE b.utalas_id = ? ORDER BY k.kod, b.k_sorszam', [$u['id']]);
                foreach ($szamlak as $s) {
                    $reszt = (float)$s['reszt'] > 0;
                    $sorok[] = ['cellak' => [sprintf('K%04d · „%s”', $s['k_sorszam'], $s['szamlaszam']), $s['kotes_kod'], ny_statusz($s['statusz']), '', '', ny_datum($s['fizetesi_hatarido']), ny_osszeg($s['hatralek'], $u['penznem']),
                                             $reszt ? 'eredeti ' . ny_osszeg($s['osszeg']) . ', részteljesítés ' . ny_osszeg($s['reszt']) : ''],
                               'en' => [2 => ny_en($s['statusz'])] + ($reszt ? [7 => 'original ' . ny_osszeg($s['osszeg']) . ', partial payments ' . ny_osszeg($s['reszt'])] : []),
                               'stilus' => 'al', 'szinek' => [6 => (float)$s['hatralek'] < 0 ? [214, 69, 65] : [82, 82, 82]]];
                }
            }
            $sorok = array_merge($sorok, ny_osszesito($t, 8, 6));
            $pdf->tablazat([
                ['c' => 'Utalás UID / számla', 'en' => 'Transfer ID / invoice', 'w' => 40, 'a' => 'L'], ['c' => 'Cég / kötés', 'en' => 'Company / contract', 'w' => 30, 'a' => 'L'], ['c' => 'Státusz', 'en' => 'Status', 'w' => 20, 'a' => 'C'], ['c' => 'Mód', 'en' => 'Type', 'w' => 22, 'a' => 'C'],
                ['c' => 'Db', 'en' => 'Qty', 'w' => 8, 'a' => 'C'], ['c' => 'Utalva / határidő', 'en' => 'Transferred / due', 'w' => 22, 'a' => 'C'], ['c' => 'Összeg', 'en' => 'Amount', 'w' => 21, 'a' => 'R'], ['c' => 'Hivatkozás', 'en' => 'Reference', 'w' => 27, 'a' => 'L'],
            ], $sorok);
        }
    }

    // ------------------------------------------------------------- KIMENŐ SZÁMLÁK
    if ($ids['kimeno']) {
        $in = ny_in($ids['kimeno']);
        $sz = db_all(KIMENO_SQL . "WHERE s.id IN ($in) ORDER BY s.fizetesi_hatarido, s.kod", $ids['kimeno']);
        if ($sz) {
            $vanValami = true;
            $sz = reszt_csatol(array_map('kimeno_sor_feldolgoz', $sz), 'KIMENO');
            $pdf->szakasz('Kimenő számlák', count($sz) . ' tétel · fizetési határidő szerint', [1, 127, 1], 'Outgoing invoices', count($sz) . ' items · by due date');
            $sorok = [];
            $t = [];
            $egyenleg = [];   // cég id => ['nev', 'cimke', 'db', 'pn' => gyűjtés]
            foreach ($sz as $s) {
                $nyitott = $s['statusz'] === 'NYITOTT';
                ny_gyujt($t, $s, $nyitott);
                if (isset($egyenlegIds[$s['id']])) {
                    $cid = (int)$s['ceg_id'];
                    $egyenleg[$cid] = $egyenleg[$cid] ?? ['nev' => $s['ceg_nev'], 'cimke' => $egyenlegIds[$s['id']], 'db' => 0, 'pn' => []];
                    $egyenleg[$cid]['db']++;
                    ny_gyujt($egyenleg[$cid]['pn'], $s, $nyitott);
                }
                $sorok[] = ['cellak' => [$s['kod'], $s['ceg_nev'], $s['szamlaszam'], ny_datum($s['kelt']), ny_datum($s['teljesites_datum']), ny_datum($s['fizetesi_hatarido']),
                                         $nyitott ? '' : ny_datum($s['fizetve_datum']), ny_osszeg($s['osszeg'], $s['penznem']), ny_statusz($s['statusz']), $s['banki_azonosito'] ?? ''],
                           'en' => [8 => ny_en($s['statusz'])],
                           'szinek' => [8 => $nyitott ? [217, 108, 0] : [1, 127, 1], 7 => (float)$s['osszeg'] < 0 ? [214, 69, 65] : [23, 23, 23], 6 => [1, 127, 1]]];
                $sorok = array_merge($sorok, ny_reszt_sorok($s, $nyitott, 10, 6, 7));
            }
            $sorok = array_merge($sorok, ny_szamla_osszesito($t, 10, 7));
            $pdf->tablazat([
                ['c' => 'Számla ID', 'en' => 'Invoice ID', 'w' => 21, 'a' => 'L'], ['c' => 'Cég', 'en' => 'Company', 'w' => 27, 'a' => 'L'], ['c' => 'Számlaszám', 'en' => 'Invoice no.', 'w' => 24.5, 'a' => 'L'],
                ['c' => 'Kelt', 'en' => 'Issued', 'w' => 14, 'a' => 'C'], ['c' => 'Teljesítés', 'en' => 'Completion', 'w' => 14, 'a' => 'C'], ['c' => 'Határidő', 'en' => 'Due date', 'w' => 14, 'a' => 'C'], ['c' => 'Fizetve', 'en' => 'Paid on', 'w' => 14, 'a' => 'C'],
                ['c' => 'Összeg', 'en' => 'Amount', 'w' => 21.5, 'a' => 'R'], ['c' => 'Státusz', 'en' => 'Status', 'w' => 14, 'a' => 'C'], ['c' => 'Banki azonosító', 'en' => 'Bank reference', 'w' => 26, 'a' => 'L'],
            ], $sorok, ['pt' => 7.0]);

            // EGYENLEG – a partnernek: összes pénzforgalom · fizetett/beszámított · nyitott
            foreach ($egyenleg as $e) {
                $pdf->szakasz('EGYENLEG – ' . $e['nev'], ($e['cimke'] !== '' ? $e['cimke'] . ' · ' : '') . $e['db'] . ' számla', [254, 131, 2],
                    'BALANCE – ' . $e['nev'], ($e['cimke'] !== '' ? ny_idoszak_en($e['cimke']) . ' · ' : '') . $e['db'] . ' invoices');
                $esorok = [];
                ksort($e['pn']);
                foreach ($e['pn'] as $pn => $v) {
                    $esorok[] = ['cellak' => [$pn, ny_osszeg($v['osszes'], $pn), ny_osszeg($v['fizetett'], $pn), $v['reszt'] > 0 ? ny_osszeg($v['reszt'], $pn) : '–', ny_osszeg($v['nyitott'], $pn)],
                                 'stilus' => 'osszes', 'szinek' => [2 => [1, 127, 1], 4 => [217, 108, 0]]];
                }
                $pdf->tablazat([
                    ['c' => 'Pénznem', 'en' => 'Currency', 'w' => 22, 'a' => 'L'], ['c' => 'Összes pénzforgalom', 'en' => 'Total turnover', 'w' => 42, 'a' => 'R'],
                    ['c' => 'Fizetett / beszámított', 'en' => 'Paid / offset', 'w' => 42, 'a' => 'R'], ['c' => 'ebből részteljesítés', 'en' => 'of which partial payments', 'w' => 42, 'a' => 'R'],
                    ['c' => 'NYITOTT – még fizetendő', 'en' => 'OPEN – still payable', 'w' => 42, 'a' => 'R'],
                ], $esorok, ['pt' => 8.5]);
                $pdf->helyBiztosit(12);
                $y = $pdf->szoveg2($pdf->margoBal, $pdf->y + 2.5,
                    'Összes pénzforgalom = a felsorolt számlák teljes összege · Fizetett / beszámított = a rendezett számlák és a részteljesítések · NYITOTT = amit még fizetni kell.',
                    'Total turnover = total of the listed invoices · Paid / offset = settled invoices and partial payments · OPEN = the amount still to be paid.', 'regular', 7, [115, 115, 115]);
                $pdf->y = $y + 4;
            }
        }
    }

    // ------------------------------------------------------------- BANKI KIVONATOK
    if ($ids['kivonat']) {
        $in = ny_in($ids['kivonat']);
        $kiv = db_all("SELECT id, azonosito, datum FROM banki_kivonatok WHERE id IN ($in) ORDER BY datum DESC, id DESC", $ids['kivonat']);
        if ($kiv) {
            $vanValami = true;
            $pdf->szakasz('Banki kivonatok', count($kiv) . ' tétel · a lefedett kimenő számlákkal', [1, 127, 1], 'Bank statements', count($kiv) . ' items · with the covered outgoing invoices');
            $sorok = [];
            $t = [];
            foreach ($kiv as $k) {
                $szamlak = db_all(KIMENO_SQL . 'WHERE s.banki_kivonat_id = ? ORDER BY s.kod', [(int)$k['id']]);
                $ossz = [];
                foreach ($szamlak as $s) {
                    $ossz[$s['penznem']] = ($ossz[$s['penznem']] ?? 0) + (float)$s['hatralek'];
                    $t[$s['penznem']] = ($t[$s['penznem']] ?? 0) + (float)$s['hatralek'];
                }
                $osszTxt = implode("\n", array_map(fn($pn, $v) => ny_osszeg($v, $pn), array_keys($ossz), $ossz));
                $sorok[] = ['cellak' => [$k['azonosito'], ny_datum($k['datum']), (string)count($szamlak), $osszTxt, '']];
                foreach ($szamlak as $s) {
                    $reszt = (float)$s['reszt'] > 0;
                    $sorok[] = ['cellak' => [$s['kod'] . ' · „' . $s['szamlaszam'] . '”' . ($reszt ? ' (eredeti ' . ny_osszeg($s['osszeg']) . ', részteljesítés ' . ny_osszeg($s['reszt']) . ')' : ''), ny_datum($s['fizetve_datum']), '', ny_osszeg($s['hatralek'], $s['penznem']), $s['ceg_nev']],
                               'en' => $reszt ? [0 => '(original ' . ny_osszeg($s['osszeg']) . ', partial payments ' . ny_osszeg($s['reszt']) . ')'] : [], 'stilus' => 'al'];
                }
            }
            $sorok = array_merge($sorok, ny_osszesito($t, 5, 3));
            $pdf->tablazat([
                ['c' => 'Banki azonosító / számla', 'en' => 'Bank reference / invoice', 'w' => 60, 'a' => 'L'], ['c' => 'Dátum', 'en' => 'Date', 'w' => 22, 'a' => 'C'], ['c' => 'Számlák', 'en' => 'Invoices', 'w' => 16, 'a' => 'C'],
                ['c' => 'Összeg', 'en' => 'Amount', 'w' => 34, 'a' => 'R'], ['c' => 'Cég', 'en' => 'Company', 'w' => 54, 'a' => 'L'],
            ], $sorok);
        }
    }

    if (!$vanValami) {
        $pdf->szakasz('Nincs nyomtatható tétel', '', [1, 127, 1], 'Nothing to print', '');
        $pdf->szoveg2($pdf->margoBal, $pdf->y + 6, 'A kiválasztott tételek már nem léteznek az adatbázisban.', 'The selected items no longer exist in the database.', 'regular', 9, [82, 82, 82]);
    }
    return $pdf->kimenet();
}
