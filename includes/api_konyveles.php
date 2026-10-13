<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  MINDEN SZÁMLA – könyvelésnek kiküldve egyeztetésre (1.24)
//  Számlánként tartjuk nyilván, mit küldtünk ki a könyvelésnek (bejovo_szamlak / kimeno_szamlak.konyveles_kikuldve,
//  konyveles_kikuldte). A számla többi adata, státusza ettől nem változik.
// ---------------------------------------------------------------------------

const KONYV_MAX_SOR = 3000;   // ennyi sort ad vissza a lista egyszerre (a többi: szűkítsd a szűrőt)

/**
 * A számlák listája (bejövő és kimenő egyben, csak számlák): irány, kiküldve-szűrő, dátummező + -tól/-ig.
 *   irany: BEJOVO | KIMENO | MIND · kikuldve: nincs (még nem kiküldött) | mind | kikuldve · mezo: kelt | teljesites_datum | fizetesi_hatarido
 */
function act_szamlak_lista(array $be): array
{
    csak_bejelentkezve();
    $irany = be_valaszt($be, 'irany', ['BEJOVO', 'KIMENO', 'MIND'], 'BEJOVO');
    $kikuldve = be_valaszt($be, 'kikuldve', ['nincs', 'mind', 'kikuldve'], 'mind');
    $mezo = be_valaszt($be, 'mezo', ['kelt', 'teljesites_datum', 'fizetesi_hatarido'], 'kelt');
    $tol = be_datum($be, 'tol', false);
    $ig = be_datum($be, 'ig', false);
    if ($tol && $ig && $tol > $ig) {
        hiba('A -tól dátum nem lehet későbbi az -ig dátumnál.');
    }
    $felt = fn(string $a) => implode(' AND ', array_filter([
        $tol ? "$a.$mezo >= ?" : '',
        $ig ? "$a.$mezo <= ?" : '',
        $kikuldve === 'nincs' ? "$a.konyveles_kikuldve IS NULL" : ($kikuldve === 'kikuldve' ? "$a.konyveles_kikuldve IS NOT NULL" : ''),
    ])) ?: '1=1';
    $p = array_values(array_filter([$tol, $ig], fn($v) => $v !== null));
    $reszek = [];
    $params = [];
    if ($irany !== 'KIMENO') {
        $reszek[] = 'SELECT "BEJOVO" AS irany, b.id, b.kod, b.szamlaszam, c.id AS ceg_id, c.nev AS ceg_nev, k.id AS kotes_id, k.kod AS kotes_kod, k.penznem,
                            b.osszeg, b.kelt, b.teljesites_datum, b.fizetesi_hatarido, b.statusz, b.fizetve_datum, u.uid AS utalas_uid, NULL AS bank_azon,
                            b.konyveles_kikuldve, f.nev AS kikuldte_nev, COALESCE(r.reszt, 0) AS reszt, b.beszam, b.megjegyzes
                       FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id
                       LEFT JOIN utalasok u ON u.id = b.utalas_id LEFT JOIN felhasznalok f ON f.id = b.konyveles_kikuldte
                       LEFT JOIN (SELECT szamla_id, SUM(osszeg) AS reszt FROM reszteljesitesek WHERE irany = "BEJOVO" GROUP BY szamla_id) r ON r.szamla_id = b.id
                      WHERE ' . $felt('b');
        $params = array_merge($params, $p);
    }
    if ($irany !== 'BEJOVO') {
        // (az oszlopnevek itt is ki vannak írva: csak kimenő iránynál ez az egyetlen SELECT, és abból lesznek a mezőnevek)
        $reszek[] = 'SELECT "KIMENO" AS irany, s.id, s.kod, s.szamlaszam, c.id AS ceg_id, c.nev AS ceg_nev, NULL AS kotes_id, NULL AS kotes_kod, s.penznem,
                            s.osszeg, s.kelt, s.teljesites_datum, s.fizetesi_hatarido, s.statusz, s.fizetve_datum, NULL AS utalas_uid, bk.azonosito AS bank_azon,
                            s.konyveles_kikuldve, f.nev AS kikuldte_nev, COALESCE(r.reszt, 0) AS reszt, NULL AS beszam, s.megjegyzes
                       FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id
                       LEFT JOIN banki_kivonatok bk ON bk.id = s.banki_kivonat_id LEFT JOIN felhasznalok f ON f.id = s.konyveles_kikuldte
                       LEFT JOIN (SELECT szamla_id, SUM(osszeg) AS reszt FROM reszteljesitesek WHERE irany = "KIMENO" GROUP BY szamla_id) r ON r.szamla_id = s.id
                      WHERE ' . $felt('s');
        $params = array_merge($params, $p);
    }
    $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $reszek) . ') x ORDER BY irany, kod LIMIT ' . (KONYV_MAX_SOR + 1);
    $sorok = db_all($sql, $params);
    $csonka = count($sorok) > KONYV_MAX_SOR;
    if ($csonka) {
        array_pop($sorok);
    }
    // összesítés a teljes találatra (a lista csonkítása nélkül)
    $osszes = 0;
    $kikuldveDb = 0;
    $osszegek = [];
    $szamlalo = [];
    if ($irany !== 'KIMENO') {
        $szamlalo[] = ['SELECT COUNT(*) AS db, SUM(b.konyveles_kikuldve IS NOT NULL) AS kik, k.penznem, "BEJOVO" AS irany, SUM(b.osszeg) AS osszeg FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id WHERE ' . $felt('b') . ' GROUP BY k.penznem', $p];
    }
    if ($irany !== 'BEJOVO') {
        $szamlalo[] = ['SELECT COUNT(*) AS db, SUM(s.konyveles_kikuldve IS NOT NULL) AS kik, s.penznem, "KIMENO" AS irany, SUM(s.osszeg) AS osszeg FROM kimeno_szamlak s WHERE ' . $felt('s') . ' GROUP BY s.penznem', $p];
    }
    foreach ($szamlalo as [$q, $qp]) {
        foreach (db_all($q, $qp) as $r) {
            $osszes += (int)$r['db'];
            $kikuldveDb += (int)$r['kik'];
            $osszegek[] = ['irany' => $r['irany'], 'penznem' => $r['penznem'], 'db' => (int)$r['db'], 'osszeg' => (string)$r['osszeg']];
        }
    }
    foreach ($sorok as &$s) {
        $s['id'] = (int)$s['id'];
        $s['ceg_id'] = (int)$s['ceg_id'];
        $s['kotes_id'] = $s['kotes_id'] === null ? null : (int)$s['kotes_id'];
        $s['reszt'] = (string)$s['reszt'];
    }
    unset($s);
    return ['sorok' => $sorok, 'osszes' => $osszes, 'kikuldve_db' => $kikuldveDb, 'csonka' => $csonka, 'max' => KONYV_MAX_SOR, 'osszegek' => $osszegek,
            'szuro' => ['irany' => $irany, 'kikuldve' => $kikuldve, 'mezo' => $mezo, 'tol' => $tol, 'ig' => $ig]];
}

/**
 * Kijelölt számlák „könyvelésnek kiküldve” jelzése (vagy visszavonása): tetelek = [{irany, id}, …], visszavon = true/false.
 * Csak azokon változtat, amelyeknél tényleg változik (még nincs / már van jelzés). Változásnapló + tevékenységnapló.
 */
function act_konyveles_kikuld(array $be): array
{
    $u = csak_jog('ir');
    $visszavon = be_bool($be, 'visszavon');
    if ($visszavon) {
        $u = csak_jog('torol', 'A kiküldés visszavonásához Irodavezető vagy Admin jogosultság kell.');
    }
    $tetelek = is_array($be['tetelek'] ?? null) ? $be['tetelek'] : [];
    if (!$tetelek) {
        hiba('Nincs kijelölt számla.');
    }
    if (count($tetelek) > 5000) {
        hiba('Egyszerre legfeljebb 5000 számla jelölhető.');
    }
    $idk = ['BEJOVO' => [], 'KIMENO' => []];
    foreach ($tetelek as $t) {
        $ir = (($t['irany'] ?? '') === 'KIMENO') ? 'KIMENO' : 'BEJOVO';
        $id = (int)($t['id'] ?? 0);
        if ($id > 0) {
            $idk[$ir][$id] = true;
        }
    }
    $most = date('Y-m-d H:i:s');
    $db = db_tx(function () use ($idk, $visszavon, $u, $most) {
        $n = 0;
        $audit = [];
        foreach (['BEJOVO' => 'bejovo_szamlak', 'KIMENO' => 'kimeno_szamlak'] as $ir => $tabla) {
            $ids = array_keys($idk[$ir]);
            foreach (array_chunk($ids, 500) as $adag) {
                $ph = implode(',', array_fill(0, count($adag), '?'));
                $sorok = db_all("SELECT id, kod, szamlaszam, konyveles_kikuldve" . ($ir === 'BEJOVO' ? ', kotes_id' : '') . " FROM $tabla WHERE id IN ($ph) AND konyveles_kikuldve IS " . ($visszavon ? 'NOT NULL' : 'NULL') . ' FOR UPDATE', $adag);
                if (!$sorok) {
                    continue;
                }
                $ids2 = array_map(fn($r) => (int)$r['id'], $sorok);
                $ph2 = implode(',', array_fill(0, count($ids2), '?'));
                if ($visszavon) {
                    db_exec("UPDATE $tabla SET konyveles_kikuldve = NULL, konyveles_kikuldte = NULL WHERE id IN ($ph2)", $ids2);
                } else {
                    db_exec("UPDATE $tabla SET konyveles_kikuldve = ?, konyveles_kikuldte = ? WHERE id IN ($ph2)", array_merge([$most, $u['id']], $ids2));
                }
                foreach ($sorok as $r) {
                    $regi = $r['konyveles_kikuldve'] ? preg_replace('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*$/', '$1.$2.$3. $4', (string)$r['konyveles_kikuldve']) : '–';
                    $uj = $visszavon ? '–' : date('Y.m.d. H:i', strtotime($most));
                    $audit[] = [$ir, (int)$r['id'], $ir === 'BEJOVO' ? (int)$r['kotes_id'] : null, 'MODOSIT',
                        ($visszavon ? 'Könyvelésnek kiküldés visszavonva: ' : 'Könyvelésnek kiküldve egyeztetésre: ') . "{$r['kod']} „{$r['szamlaszam']}”",
                        [['m' => 'Könyvelésnek kiküldve', 'r' => $regi, 'u' => $uj]]];
                    $n++;
                }
            }
        }
        audit_ir_tobb($audit, $u);
        return $n;
    });
    naplo($visszavon ? 'KONYVELES_VISSZAVON' : 'KONYVELES_KIKULD', "$db számla (" . count($idk['BEJOVO']) . ' bejövő, ' . count($idk['KIMENO']) . ' kimenő kijelölve)');
    return ['db' => $db, 'visszavon' => $visszavon, 'mikor' => $most];
}
