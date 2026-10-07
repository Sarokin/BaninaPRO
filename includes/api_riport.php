<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  FIZETÉSI HATÁRIDŐK
// ---------------------------------------------------------------------------

/**
 * Minden még nem rendezett számla lejárat szerint növekvő sorrendben:
 *  bejövő: FIZETENDŐ (és UTALÁSHOZ ADVA – a kliens alapból elrejti)
 *  kimenő: NYITOTT
 */
function act_hataridok(array $be): array
{
    csak_bejelentkezve();
    $sorok = db_all(
        'SELECT * FROM (
            SELECT "BE" AS irany, b.id AS szamla_id, b.fizetesi_hatarido AS hatarido, c.id AS ceg_id, c.nev AS ceg_nev,
                   b.szamlaszam, b.osszeg, k.penznem, b.kod, k.id AS kotes_pk, k.kod AS kotes_kod, b.statusz, u.uid AS utalas_uid,
                   b.teljesites_datum, b.kelt, COALESCE(rb.reszt, 0) AS reszt, COALESCE(rb.reszt_db, 0) AS reszt_db, (b.osszeg - COALESCE(rb.reszt, 0)) AS hatralek
              FROM bejovo_szamlak b
              JOIN kotesek k ON k.id = b.kotes_id
              JOIN cegek c ON c.id = k.ceg_id
              LEFT JOIN utalasok u ON u.id = b.utalas_id' . RESZT_BEJOVO_JOIN . '
             WHERE b.statusz IN ("FIZETENDO","UTALASHOZ_ADVA")
            UNION ALL
            SELECT "KI", s.id, s.fizetesi_hatarido, c.id, c.nev,
                   s.szamlaszam, s.osszeg, s.penznem, s.kod, NULL, NULL, s.statusz, NULL,
                   s.teljesites_datum, s.kelt, COALESCE(rk.reszt, 0), COALESCE(rk.reszt_db, 0), (s.osszeg - COALESCE(rk.reszt, 0))
              FROM kimeno_szamlak s
              JOIN cegek c ON c.id = s.ceg_id' . RESZT_KIMENO_JOIN . '
             WHERE s.statusz = "NYITOTT"
         ) x ORDER BY x.hatarido ASC, x.ceg_nev ASC, x.kod ASC'
    );
    foreach ($sorok as &$s) {
        $s['szamla_id'] = (int)$s['szamla_id'];
        $s['ceg_id'] = (int)$s['ceg_id'];
        $s['kotes_pk'] = $s['kotes_pk'] === null ? null : (int)$s['kotes_pk'];
        $s['osszeg'] = (float)$s['osszeg'];
        $s['reszt'] = (float)$s['reszt'];
        $s['reszt_db'] = (int)$s['reszt_db'];
        $s['hatralek'] = (float)$s['hatralek'];
    }
    naplo('HATARIDOK', 'fizetési határidők lista megtekintve (' . count($sorok) . ' tétel)');
    return ['tetelek' => $sorok, 'ma' => date('Y-m-d')];
}

/** Főoldali számlálók (fizetendő, nyitott kimenő, nyitott utalás, lejárt) */
function act_osszefoglalo(array $be): array
{
    csak_bejelentkezve();
    $ma = date('Y-m-d');
    return [
        'fizetendo_db'      => (int)db_val('SELECT COUNT(*) FROM bejovo_szamlak WHERE statusz = "FIZETENDO"'),
        'kimeno_nyitott_db' => (int)db_val('SELECT COUNT(*) FROM kimeno_szamlak WHERE statusz = "NYITOTT"'),
        'nyitott_utalas_db' => (int)db_val('SELECT COUNT(*) FROM utalasok WHERE statusz = "NYITOTT"'),
        'lejart_db'         => (int)db_val('SELECT COUNT(*) FROM bejovo_szamlak WHERE statusz = "FIZETENDO" AND fizetesi_hatarido < ?', [$ma])
                             + (int)db_val('SELECT COUNT(*) FROM kimeno_szamlak WHERE statusz = "NYITOTT" AND fizetesi_hatarido < ?', [$ma]),
    ];
}

// ---------------------------------------------------------------------------
//  ÖSSZEVETÉS / ÖSSZESÍTŐ
// ---------------------------------------------------------------------------

/** Aktuális árfolyam (frissítés kérhető) */
function act_arfolyam(array $be): array
{
    csak_bejelentkezve();
    return ['arfolyam' => arfolyam_eur_huf(be_bool($be, 'frissit'))];
}

/**
 * Egy cég egyenlege pénznemenként (minden időszak):
 *   bejovo_nyitott = FIZETENDŐ + UTALÁSHOZ ADVA (ennyivel tartozom én)
 *   kimeno_nyitott = NYITOTT kimenő (ennyivel tartozik ő)
 *   egyenleg       = bejovo_nyitott − kimeno_nyitott  (pozitív: én tartozom; negatív: ő tartozik)
 * + árfolyamos átszámítás HUF-ra és EUR-ra.
 */
function act_osszevetes_egyenleg(array $be): array
{
    csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    $p = ['HUF' => ['bejovo_nyitott' => 0.0, 'bejovo_fizetve' => 0.0, 'kimeno_nyitott' => 0.0, 'kimeno_fizetve' => 0.0],
          'EUR' => ['bejovo_nyitott' => 0.0, 'bejovo_fizetve' => 0.0, 'kimeno_nyitott' => 0.0, 'kimeno_fizetve' => 0.0]];
    // nyitott = hátralék (részteljesítés után); fizetve = rendezett számlák + a nyitottak részteljesítései
    foreach (db_all(
        'SELECT k.penznem,
                SUM(CASE WHEN b.statusz IN ("FIZETENDO","UTALASHOZ_ADVA") THEN b.osszeg - COALESCE(rb.reszt, 0) ELSE 0 END) AS nyitott,
                SUM(CASE WHEN b.statusz IN ("FIZETVE","BESZAMITVA") THEN b.osszeg ELSE COALESCE(rb.reszt, 0) END) AS fizetve
           FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN . 'WHERE k.ceg_id = ? GROUP BY k.penznem', [$cegId]) as $r) {
        $p[$r['penznem']]['bejovo_nyitott'] = (float)$r['nyitott'];
        $p[$r['penznem']]['bejovo_fizetve'] = (float)$r['fizetve'];
    }
    foreach (db_all(
        'SELECT s.penznem,
                SUM(CASE WHEN s.statusz = "NYITOTT" THEN s.osszeg - COALESCE(rk.reszt, 0) ELSE 0 END) AS nyitott,
                SUM(CASE WHEN s.statusz = "FIZETVE" THEN s.osszeg ELSE COALESCE(rk.reszt, 0) END) AS fizetve
           FROM kimeno_szamlak s' . RESZT_KIMENO_JOIN . 'WHERE s.ceg_id = ? GROUP BY s.penznem', [$cegId]) as $r) {
        $p[$r['penznem']]['kimeno_nyitott'] = (float)$r['nyitott'];
        $p[$r['penznem']]['kimeno_fizetve'] = (float)$r['fizetve'];
    }
    foreach ($p as $pn => &$v) {
        $v['egyenleg'] = round($v['bejovo_nyitott'] - $v['kimeno_nyitott'], 2);
    }
    unset($v);
    $arf = arfolyam_eur_huf();
    $osszes = null;
    if ($arf && $arf['eur_huf'] > 0) {
        $osszes = [
            'HUF' => round($p['HUF']['egyenleg'] + $p['EUR']['egyenleg'] * $arf['eur_huf'], 2),
            'EUR' => round($p['EUR']['egyenleg'] + $p['HUF']['egyenleg'] / $arf['eur_huf'], 2),
        ];
    }
    naplo('OSSZEVETES_EGYENLEG', "cég #{$cegId} ({$ceg['nev']}) egyenlege megtekintve");
    return ['ceg' => ['id' => (int)$ceg['id'], 'nev' => $ceg['nev']], 'penznemek' => $p, 'arfolyam' => $arf, 'osszesen' => $osszes];
}

/**
 * Részletes összevetés: cég + pénznem + teljesítési dátum tól–ig (kötelező).
 * Bejövő számlák kötésenként csoportosítva, kimenő számlák listája,
 * NYITOTT és FIZETVE egyenlegek az időszakra.
 */
function act_osszevetes_reszletek(array $be): array
{
    csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $penznem = be_penznem($be);
    $tol = be_datum($be, 'tol', true, 'dátum (-tól)');
    $ig = be_datum($be, 'ig', true, 'dátum (-ig)');
    if ($tol > $ig) {
        hiba('A kezdő dátum nem lehet későbbi a záró dátumnál.');
    }
    $d = osszevetes_reszletek_adat($cegId, $penznem, $tol, $ig);
    naplo('OSSZEVETES_RESZLETEK', "cég #{$cegId} ({$d['ceg']['nev']}), $penznem, teljesítés $tol – $ig");
    return $d;
}

/**
 * Az összevetés adatai (cég + pénznem + teljesítési időszak) – az oldal és a PDF (nyomtatási kosár) is ezt használja,
 * így a kettő mindig ugyanazt mutatja. A bemenetet a hívó ellenőrzi; ha a cég nem létezik, hibát dob.
 */
function osszevetes_reszletek_adat(int $cegId, string $penznem, string $tol, string $ig): array
{
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }

    $bej = db_all(
        'SELECT b.id, b.kod, b.k_sorszam, b.szamlaszam, b.osszeg, b.statusz, b.teljesites_datum, b.kelt, b.fizetesi_hatarido, b.beszam,
                COALESCE(b.fizetve_datum, u.utalva_datum) AS fizetve_datum,
                k.id AS kotes_pk, k.kod AS kotes_kod, k.megnevezes AS kotes_megnevezes, u.uid AS utalas_uid,' . RESZT_BEJOVO_MEZOK . '
           FROM bejovo_szamlak b
           JOIN kotesek k ON k.id = b.kotes_id
           LEFT JOIN utalasok u ON u.id = b.utalas_id' . RESZT_BEJOVO_JOIN . '
          WHERE k.ceg_id = ? AND k.penznem = ? AND b.teljesites_datum BETWEEN ? AND ?
          ORDER BY k.kod, b.k_sorszam',
        [$cegId, $penznem, $tol, $ig]
    );
    $bej = reszt_csatol($bej, 'BEJOVO');
    $kotesek = [];
    $bejOssz = ['nyitott' => 0.0, 'fizetve' => 0.0, 'osszes' => 0.0, 'db' => 0];
    foreach ($bej as $s) {
        $kk = $s['kotes_kod'];
        if (!isset($kotesek[$kk])) {
            $kotesek[$kk] = ['kotes_pk' => (int)$s['kotes_pk'], 'kotes_kod' => $kk, 'kotes_megnevezes' => $s['kotes_megnevezes'], 'osszeg' => 0.0, 'nyitott' => 0.0, 'fizetve' => 0.0, 'szamlak' => []];
        }
        $o = (float)$s['osszeg'];
        $nyitott = in_array($s['statusz'], ['FIZETENDO', 'UTALASHOZ_ADVA'], true);
        // nyitott = hátralék, fizetve = rendezett összeg + a nyitott számla részteljesítése
        $ny = $nyitott ? (float)$s['hatralek'] : 0.0;
        $fi = $nyitott ? (float)$s['reszt'] : $o;
        $kotesek[$kk]['osszeg'] = round($kotesek[$kk]['osszeg'] + $o, 2);
        $kotesek[$kk]['nyitott'] = round($kotesek[$kk]['nyitott'] + $ny, 2);
        $kotesek[$kk]['fizetve'] = round($kotesek[$kk]['fizetve'] + $fi, 2);
        $bejOssz['nyitott'] = round($bejOssz['nyitott'] + $ny, 2);
        $bejOssz['fizetve'] = round($bejOssz['fizetve'] + $fi, 2);
        $bejOssz['osszes'] = round($bejOssz['osszes'] + $o, 2);
        $bejOssz['db']++;
        $kotesek[$kk]['szamlak'][] = [
            'id' => (int)$s['id'], 'kod' => $s['kod'], 'k' => sprintf('K%04d', $s['k_sorszam']), 'szamlaszam' => $s['szamlaszam'],
            'osszeg' => $o, 'reszt' => $s['reszt'], 'reszt_db' => $s['reszt_db'], 'hatralek' => $s['hatralek'], 'reszteljesitesek' => $s['reszteljesitesek'],
            'statusz' => $s['statusz'], 'teljesites_datum' => $s['teljesites_datum'], 'kelt' => $s['kelt'], 'fizetve_datum' => $s['fizetve_datum'],
            'fizetesi_hatarido' => $s['fizetesi_hatarido'], 'beszam' => $s['beszam'], 'utalas_uid' => $s['utalas_uid'],
        ];
    }

    $kim = db_all(
        'SELECT s.id, s.kod, s.szamlaszam, s.osszeg, s.statusz, s.teljesites_datum, s.kelt, s.fizetesi_hatarido, s.fizetve_datum, bk.azonosito AS banki_azonosito,' . RESZT_KIMENO_MEZOK . '
           FROM kimeno_szamlak s LEFT JOIN banki_kivonatok bk ON bk.id = s.banki_kivonat_id' . RESZT_KIMENO_JOIN . '
          WHERE s.ceg_id = ? AND s.penznem = ? AND s.teljesites_datum BETWEEN ? AND ?
          ORDER BY s.kod',
        [$cegId, $penznem, $tol, $ig]
    );
    $kimOssz = ['nyitott' => 0.0, 'fizetve' => 0.0, 'osszes' => 0.0, 'db' => 0];
    foreach ($kim as &$s) {
        $s['id'] = (int)$s['id'];
        $s['osszeg'] = (float)$s['osszeg'];
        $s['reszt'] = (float)$s['reszt'];
        $s['reszt_db'] = (int)$s['reszt_db'];
        $s['hatralek'] = (float)$s['hatralek'];
        if ($s['statusz'] === 'NYITOTT') {
            $kimOssz['nyitott'] = round($kimOssz['nyitott'] + $s['hatralek'], 2);
            $kimOssz['fizetve'] = round($kimOssz['fizetve'] + $s['reszt'], 2);
        } else {
            $kimOssz['fizetve'] = round($kimOssz['fizetve'] + $s['osszeg'], 2);
        }
        $kimOssz['osszes'] = round($kimOssz['osszes'] + $s['osszeg'], 2);
        $kimOssz['db']++;
    }
    unset($s);
    $kim = reszt_csatol($kim, 'KIMENO');

    return [
        'ceg' => ['id' => (int)$ceg['id'], 'nev' => $ceg['nev']],
        'penznem' => $penznem, 'tol' => $tol, 'ig' => $ig,
        'bejovo' => ['osszesites' => $bejOssz, 'kotesek' => array_values($kotesek)],
        'kimeno' => ['osszesites' => $kimOssz, 'szamlak' => $kim],
        'egyenleg_nyitott' => round($bejOssz['nyitott'] - $kimOssz['nyitott'], 2),
        'egyenleg_fizetve' => round($bejOssz['fizetve'] - $kimOssz['fizetve'], 2),
    ];
}

// ---------------------------------------------------------------------------
//  ÁLLAPOT VIZSGÁLAT (1.19) – a számlák állapota és a cég egyenlege egy adott napon
// ---------------------------------------------------------------------------

/** A vizsgált napon rendezettnek számító (mostani) státuszok – bejövő: FIZETVE / BESZÁMÍTVA, kimenő: FIZETVE */
const ALLAPOT_RENDEZETT = ['FIZETVE', 'BESZAMITVA'];

/** Üres egyenleg-gyűjtő – ugyanazok a mezők, mint a bejovo_osszesito_szamok() eredményében (+ a még nem létezett számlák) */
function allapot_ures_osszesito(): array
{
    return ['db' => 0, 'nyitott_db' => 0, 'fizetett_db' => 0, 'nem_letezett_db' => 0, 'nyitott' => 0.0, 'fizetendo' => 0.0, 'utalas_alatt' => 0.0,
            'fizetett' => 0.0, 'fizetett_szamlak' => 0.0, 'reszt' => 0.0, 'teljes' => 0.0, 'nem_letezett' => 0.0];
}

/** Egy számla hozzáadása az összesítőhöz a vizsgált napi állapota szerint */
function allapot_gyujt(array &$o, array $s): void
{
    $o['db']++;
    if ($s['allapot'] === 'MEG_NEM_LETEZETT') {
        $o['nem_letezett_db']++;
        $o['nem_letezett'] = round($o['nem_letezett'] + $s['osszeg'], 2);
        return;
    }
    if ($s['allapot'] === 'FIZETETLEN') {
        $o['nyitott_db']++;
        $o['nyitott'] = round($o['nyitott'] + $s['hatralek_napon'], 2);
        $o['reszt'] = round($o['reszt'] + $s['reszt_napon'], 2);
    } else {
        $o['fizetett_db']++;
        $o['fizetett_szamlak'] = round($o['fizetett_szamlak'] + $s['osszeg'], 2);
    }
    $o['fizetendo'] = $o['nyitott'];   // a vizsgált napon az utalás alatti rész nem ismert – minden fizetetlen fizetendő
    $o['fizetett'] = round($o['fizetett'] + $s['fizetett_napon'], 2);
    $o['teljes'] = round($o['teljes'] + $s['osszeg'], 2);
}

/**
 * ÁLLAPOT VIZSGÁLAT: egy cég bejövő vagy kimenő számlái (bejövőnél egy kötésre is szűkíthető), amelyek KELTE a
 * tól–ig időszakba esik – azzal az állapottal és egyenleggel, ami a VIZSGÁLT NAPON ($nap) volt érvényes.
 * Az oldal és a PDF (nyomtatási kosár) is ezt használja, így a kettő mindig ugyanazt mutatja.
 * Új adat nem kell hozzá, a meglévő dátumokból számol:
 *   - kelt > nap                                             → MEG_NEM_LETEZETT (az egyenlegbe nem számít bele)
 *   - most rendezett (FIZETVE / BESZÁMÍTVA), fizetés napja ≤ nap → FIZETVE / BESZAMITVA
 *   - minden más                                             → FIZETETLEN; hátraléka = összeg − a nap végéig érkezett
 *                                                              részteljesítések
 * A fizetés napja: bejövőnél a számla fizetve_datum-a, ennek híján az utalás dátuma; kimenőnél a fizetve_datum.
 * A régi, Excelből FIZETVE-ként importált bejövő számláknak nincs ilyen dátuma: náluk a fizetési határidő számít
 * (mint a kimenő importnál), a sor fizetve_becsult jelzést kap.
 * Egyenleg pénznemenként (EUR elöl), bejövőnél kötésenként is – a mezők a bejovo_osszesito_szamok() mezői.
 */
function allapot_vizsgalat_adat(string $irany, int $cegId, ?int $kotesId, string $tol, string $ig, string $nap): array
{
    $kotes = null;
    if ($irany === 'BEJOVO' && $kotesId !== null) {
        // kötésre szűkítve a cég a kötésé
        $kotes = db_row('SELECT id, ceg_id, kod, megnevezes, penznem FROM kotesek WHERE id = ?', [$kotesId]);
        if (!$kotes) {
            hiba('A kötés nem található.');
        }
        $cegId = (int)$kotes['ceg_id'];
        $kotes = ['id' => (int)$kotes['id'], 'kod' => $kotes['kod'], 'megnevezes' => $kotes['megnevezes'], 'penznem' => $kotes['penznem']];
    }
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    if ($irany === 'BEJOVO') {
        $sorok = db_all(
            'SELECT b.id, b.kod, b.k_sorszam, b.szamlaszam, b.osszeg, b.statusz, b.kelt, b.teljesites_datum, b.fizetesi_hatarido, b.beszam,
                    COALESCE(b.fizetve_datum, u.utalva_datum) AS fizetve_datum, k.penznem, k.id AS kotes_pk, k.kod AS kotes_kod, u.uid AS utalas_uid
               FROM bejovo_szamlak b
               JOIN kotesek k ON k.id = b.kotes_id
               LEFT JOIN utalasok u ON u.id = b.utalas_id
              WHERE k.ceg_id = ? AND b.kelt BETWEEN ? AND ?' . ($kotes ? ' AND b.kotes_id = ?' : '') . '
              ORDER BY b.kelt, b.kod',
            array_merge([$cegId, $tol, $ig], $kotes ? [$kotes['id']] : [])
        );
    } else {
        $sorok = db_all(
            'SELECT s.id, s.kod, s.szamlaszam, s.osszeg, s.statusz, s.kelt, s.teljesites_datum, s.fizetesi_hatarido, s.fizetve_datum, s.penznem,
                    bk.azonosito AS banki_azonosito
               FROM kimeno_szamlak s
               LEFT JOIN banki_kivonatok bk ON bk.id = s.banki_kivonat_id
              WHERE s.ceg_id = ? AND s.kelt BETWEEN ? AND ?
              ORDER BY s.kelt, s.kod',
            [$cegId, $tol, $ig]
        );
    }
    $resztek = reszteljesitesek_betolt($irany, array_column($sorok, 'id'));

    $szamlak = [];
    $egyenleg = [];
    $kotesek = [];
    foreach ($sorok as $s) {
        $id = (int)$s['id'];
        $o = (float)$s['osszeg'];
        $rendezett = in_array($s['statusz'], ALLAPOT_RENDEZETT, true);
        $fizNap = $s['fizetve_datum'];
        $becsult = $rendezett && !$fizNap;
        if ($becsult) {
            $fizNap = $s['fizetesi_hatarido'];
        }
        // csak a vizsgált nap végéig érkezett részteljesítések
        $rl = array_values(array_filter($resztek[$id] ?? [], fn(array $r): bool => $r['datum'] <= $nap));
        $reszt = round(array_sum(array_column($rl, 'osszeg')), 2);
        if ($s['kelt'] > $nap) {
            [$allapot, $hatralek, $fizetett, $rl, $reszt] = ['MEG_NEM_LETEZETT', 0.0, 0.0, [], 0.0];
        } elseif ($rendezett && $fizNap <= $nap) {
            [$allapot, $hatralek, $fizetett] = [$s['statusz'], 0.0, $o];
        } else {
            [$allapot, $hatralek, $fizetett] = ['FIZETETLEN', round($o - $reszt, 2), $reszt];
        }
        $sor = [
            'id' => $id, 'kod' => $s['kod'], 'szamlaszam' => $s['szamlaszam'], 'osszeg' => $o, 'penznem' => $s['penznem'],
            'kelt' => $s['kelt'], 'teljesites_datum' => $s['teljesites_datum'], 'fizetesi_hatarido' => $s['fizetesi_hatarido'],
            'statusz' => $s['statusz'], 'fizetve_datum' => $rendezett ? $fizNap : null, 'fizetve_becsult' => $becsult,
            'allapot' => $allapot, 'hatralek_napon' => $hatralek, 'fizetett_napon' => $fizetett, 'reszt_napon' => $reszt, 'resztek_napon' => $rl,
        ];
        if ($irany === 'BEJOVO') {
            $sor += ['k' => sprintf('K%04d', $s['k_sorszam']), 'kotes_pk' => (int)$s['kotes_pk'], 'kotes_kod' => $s['kotes_kod'],
                     'utalas_uid' => $s['utalas_uid'], 'beszam' => $s['beszam']];
            $kotesek[$sor['kotes_pk']] = $kotesek[$sor['kotes_pk']] ?? allapot_ures_osszesito();
            allapot_gyujt($kotesek[$sor['kotes_pk']], $sor);
        } else {
            $sor['banki_azonosito'] = $s['banki_azonosito'];
        }
        $egyenleg[$sor['penznem']] = $egyenleg[$sor['penznem']] ?? allapot_ures_osszesito();
        allapot_gyujt($egyenleg[$sor['penznem']], $sor);
        $szamlak[] = $sor;
    }
    uksort($egyenleg, fn(string $a, string $b): int => [$a !== 'EUR', $a] <=> [$b !== 'EUR', $b]);

    return [
        'irany' => $irany, 'ceg' => ['id' => (int)$ceg['id'], 'nev' => $ceg['nev']], 'kotes' => $kotes,
        'mezo' => 'allapot', 'tol' => $tol, 'ig' => $ig, 'nap' => $nap,
        'szamlak' => $szamlak, 'egyenleg' => $egyenleg, 'kotesek' => $irany === 'BEJOVO' ? $kotesek : null,
    ];
}

/** Állapot vizsgálat: {irany: BEJOVO|KIMENO, ceg_id (kötésnél nem kell), kotes_id?, tol, ig, nap} – lásd allapot_vizsgalat_adat() */
function act_allapot_vizsgalat(array $be): array
{
    csak_bejelentkezve();
    $irany = be_valaszt($be, 'irany', ['BEJOVO', 'KIMENO'], 'BEJOVO', 'irány');
    $kotesId = $irany === 'BEJOVO' ? be_int($be, 'kotes_id', false, 'kötés') : null;
    $cegId = be_int($be, 'ceg_id', $kotesId === null, 'cég') ?? 0;
    $tol = be_datum($be, 'tol', true, 'kezdő dátum');
    $ig = be_datum($be, 'ig', true, 'záró dátum');
    $nap = be_datum($be, 'nap', true, 'vizsgált időpont');
    if ($tol > $ig) {
        hiba('A kezdő dátum nem lehet későbbi a záró dátumnál.');
    }
    $d = allapot_vizsgalat_adat($irany, $cegId, $kotesId, $tol, $ig, $nap);
    naplo('ALLAPOT_VIZSGALAT', ($irany === 'BEJOVO' ? 'bejövő' : 'kimenő') . " számlák, cég #{$cegId} ({$d['ceg']['nev']})"
        . ($d['kotes'] ? ", kötés {$d['kotes']['kod']}" : '') . ", kelt $tol – $ig, állapot $nap napon: " . count($d['szamlak']) . ' db');
    return $d;
}
