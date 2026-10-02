<?php
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/api_reszteljesites.php';

/**
 * Nyomtatási lista PDF: a kosárba gyűjtött tételek ({t, id, e?}) FRISS adatbázis-adatokból,
 * típusonként csoportosítva, A4 álló, helytakarékos táblázatokkal.
 * KÉTNYELVŰ (1.9-től): minden magyar felirat / mondat alatt halványabban az angol megfelelője
 * (oszlopfejlécek, szakaszcímek, státuszok, összesítők, alsorok, egyenleg, fej- és lábléc).
 */

const NY_TIPUSOK = ['ceg', 'kotes', 'bejovo', 'utalas', 'kimeno', 'kivonat'];
const NY_MAX_TETEL = 500;

/** Angol megfelelők (státusz, mód) */
const NY_EN_STATUSZ = [
    'FIZETENDO' => 'PAYABLE', 'UTALASHOZ_ADVA' => 'IN TRANSFER', 'FIZETVE' => 'PAID', 'BESZAMITVA' => 'OFFSET',
    'NYITOTT' => 'OPEN', 'FIZETETT' => 'PAID', 'UTALVA' => 'TRANSFERRED', 'MANUAL' => 'manual', 'HATARERTEK' => 'threshold',
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
            'NYITOTT' => 'NYITOTT', 'FIZETETT' => 'FIZETETT', 'UTALVA' => 'UTALVA', 'MANUAL' => 'manuális', 'HATARERTEK' => 'határértékes'][$s] ?? $s;
}

function ny_en(string $s): string
{
    return NY_EN_STATUSZ[$s] ?? $s;
}

function ny_in(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
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
    foreach ($tetelek as $t) {
        $ids[$t['t']][] = (int)$t['id'];
        if ($t['t'] === 'kimeno' && isset($t['e'])) {
            $egyenlegIds[(int)$t['id']] = (string)$t['e'];
        }
    }
    foreach ($ids as $k => $v) {
        $ids[$k] = array_values(array_unique(array_filter($v, fn($x) => $x > 0)));
    }
    $vanValami = false;

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
