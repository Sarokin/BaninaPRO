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
