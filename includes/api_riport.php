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
 * Egy cég egyenlege pénznemenként (minden időszak) a VIZSGÁLT NAPON (nap, alapból ma – 1.20, allapot_napon):
 *   bejovo_nyitott = a vizsgált napon fizetetlen bejövő számlák hátraléka (ennyivel tartoztam én)
 *   kimeno_nyitott = a vizsgált napon fizetetlen kimenő számlák hátraléka (ennyivel tartozott ő)
 *   egyenleg       = bejovo_nyitott − kimeno_nyitott  (pozitív: én tartozom; negatív: ő tartozik)
 * A vizsgált nap után teljesült számlák nem számítanak bele. + árfolyamos átszámítás HUF-ra és EUR-ra.
 */
function act_osszevetes_egyenleg(array $be): array
{
    csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $nap = be_datum($be, 'nap', false, 'vizsgált időpont') ?? date('Y-m-d');
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    $p = ['HUF' => ['bejovo_nyitott' => 0.0, 'bejovo_fizetve' => 0.0, 'kimeno_nyitott' => 0.0, 'kimeno_fizetve' => 0.0],
          'EUR' => ['bejovo_nyitott' => 0.0, 'bejovo_fizetve' => 0.0, 'kimeno_nyitott' => 0.0, 'kimeno_fizetve' => 0.0]];
    // nyitott = hátralék (a vizsgált napig érkezett részteljesítések után); fizetve = rendezett számlák + a nyitottak részteljesítései
    foreach (['BEJOVO' => 'bejovo', 'KIMENO' => 'kimeno'] as $irany => $elotag) {
        foreach (allapot_sorok($irany, $cegId, null, null, null, $nap) as $s) {
            $p[$s['penznem']][$elotag . '_nyitott'] = round($p[$s['penznem']][$elotag . '_nyitott'] + $s['hatralek_napon'], 2);
            $p[$s['penznem']][$elotag . '_fizetve'] = round($p[$s['penznem']][$elotag . '_fizetve'] + $s['fizetett_napon'], 2);
        }
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
    naplo('OSSZEVETES_EGYENLEG', "cég #{$cegId} ({$ceg['nev']}) egyenlege megtekintve, állapot $nap napon");
    return ['ceg' => ['id' => (int)$ceg['id'], 'nev' => $ceg['nev']], 'penznemek' => $p, 'arfolyam' => $arf, 'osszesen' => $osszes, 'nap' => $nap];
}

/**
 * Részletes összevetés: cég + pénznem + teljesítési dátum tól–ig (kötelező) + vizsgált időpont (nap, alapból ma – 1.20).
 * Bejövő számlák kötésenként csoportosítva, kimenő számlák listája,
 * NYITOTT és FIZETVE egyenlegek az időszakra – a vizsgált napi állapot szerint.
 */
function act_osszevetes_reszletek(array $be): array
{
    csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $penznem = be_penznem($be);
    $tol = be_datum($be, 'tol', true, 'dátum (-tól)');
    $ig = be_datum($be, 'ig', true, 'dátum (-ig)');
    $nap = be_datum($be, 'nap', false, 'vizsgált időpont') ?? date('Y-m-d');
    if ($tol > $ig) {
        hiba('A kezdő dátum nem lehet későbbi a záró dátumnál.');
    }
    $d = osszevetes_reszletek_adat($cegId, $penznem, $tol, $ig, $nap);
    naplo('OSSZEVETES_RESZLETEK', "cég #{$cegId} ({$d['ceg']['nev']}), $penznem, teljesítés $tol – $ig, állapot $nap napon");
    return $d;
}

/**
 * Az összevetés adatai (cég + pénznem + teljesítési időszak + vizsgált nap) – az oldal és a PDF (nyomtatási kosár) is ezt
 * használja, így a kettő mindig ugyanazt mutatja. A számlák állapota és összegei a vizsgált napon (allapot_napon, 1.20):
 * a még nem létezett (később teljesült) számlák listában maradnak, de egyik összegbe sem számítanak bele.
 * A bemenetet a hívó ellenőrzi; ha a cég nem létezik, hibát dob.
 */
function osszevetes_reszletek_adat(int $cegId, string $penznem, string $tol, string $ig, ?string $nap = null): array
{
    $nap = $nap ?? date('Y-m-d');
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    $ures = fn(): array => ['nyitott' => 0.0, 'fizetve' => 0.0, 'osszes' => 0.0, 'reszt' => 0.0, 'db' => 0, 'nem_letezett_db' => 0];
    // nyitott = a vizsgált napi hátralék, fizetve = rendezett összeg + a fizetetlen számla addigi részteljesítése;
    // $osszegKulcs: a teljes forgalom mezője (összesítő: osszes, kötés: osszeg)
    $gyujt = function (array &$o, array $s, string $osszegKulcs = 'osszes'): void {
        $o['db']++;
        if ($s['allapot'] === 'MEG_NEM_LETEZETT') {
            $o['nem_letezett_db']++;
            return;
        }
        $o['nyitott'] = round($o['nyitott'] + $s['hatralek_napon'], 2);
        $o['fizetve'] = round($o['fizetve'] + $s['fizetett_napon'], 2);
        $o['reszt'] = round($o['reszt'] + ($s['allapot'] === 'FIZETETLEN' ? $s['reszt_napon'] : 0), 2);   // a fizetetlenek addigi részteljesítései
        $o[$osszegKulcs] = round($o[$osszegKulcs] + $s['osszeg'], 2);
    };

    $bej = allapot_sorok('BEJOVO', $cegId, null, $tol, $ig, $nap, $penznem);
    usort($bej, fn(array $a, array $b): int => [$a['kotes_kod'], $a['k']] <=> [$b['kotes_kod'], $b['k']]);
    $kotesek = [];
    $bejOssz = $ures();
    foreach ($bej as $s) {
        $kk = $s['kotes_kod'];
        $kotesek[$kk] = $kotesek[$kk] ?? ['kotes_pk' => $s['kotes_pk'], 'kotes_kod' => $kk, 'kotes_megnevezes' => $s['kotes_megnevezes'],
                                           'osszeg' => 0.0, 'nyitott' => 0.0, 'fizetve' => 0.0, 'reszt' => 0.0, 'db' => 0, 'nem_letezett_db' => 0, 'szamlak' => []];
        $gyujt($kotesek[$kk], $s, 'osszeg');
        $gyujt($bejOssz, $s);
        $kotesek[$kk]['szamlak'][] = $s;
    }
    $kim = allapot_sorok('KIMENO', $cegId, null, $tol, $ig, $nap, $penznem);
    usort($kim, fn(array $a, array $b): int => $a['kod'] <=> $b['kod']);
    $kimOssz = $ures();
    foreach ($kim as $s) {
        $gyujt($kimOssz, $s);
    }

    return [
        'ceg' => ['id' => (int)$ceg['id'], 'nev' => $ceg['nev']],
        'penznem' => $penznem, 'tol' => $tol, 'ig' => $ig, 'nap' => $nap,
        'bejovo' => ['osszesites' => $bejOssz, 'kotesek' => array_values($kotesek)],
        'kimeno' => ['osszesites' => $kimOssz, 'szamlak' => $kim],
        'egyenleg_nyitott' => round($bejOssz['nyitott'] - $kimOssz['nyitott'], 2),
        'egyenleg_fizetve' => round($bejOssz['fizetve'] - $kimOssz['fizetve'], 2),
    ];
}

// ---------------------------------------------------------------------------
//  ÁLLAPOT VIZSGÁLAT (1.19) – a számlák állapota és a cég egyenlege egy adott napon
//  (1.20-tól az összevetés és a cég egyenlege is ezzel számol)
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
 * Egy számla állapota a VIZSGÁLT NAPON ($nap) – az állapot vizsgálat, az összevetés és a cég egyenlege közös logikája.
 * Új adat nem kell hozzá, a meglévő dátumokból számol:
 *   - teljesítés > nap                                          → MEG_NEM_LETEZETT (az egyenlegbe nem számít bele)
 *   - most rendezett (FIZETVE / BESZÁMÍTVA), fizetés napja ≤ nap → FIZETVE / BESZAMITVA
 *   - minden más                                                → FIZETETLEN; hátraléka = összeg − a nap végéig érkezett
 *                                                                 részteljesítések
 * A fizetés napja: bejövőnél a számla fizetve_datum-a, ennek híján az utalás dátuma; kimenőnél a fizetve_datum.
 * A régi, Excelből FIZETVE-ként importált bejövő számláknak nincs ilyen dátuma: náluk a fizetési határidő számít
 * (mint a kimenő importnál), de legkésőbb a mai nap – ma már biztosan rendezettek (fizetve_becsult jelzés).
 * $s: osszeg, statusz, teljesites_datum, fizetesi_hatarido, fizetve_datum; $resztek: a számla részteljesítései
 */
function allapot_napon(array $s, array $resztek, string $nap): array
{
    $o = (float)$s['osszeg'];
    $rendezett = in_array($s['statusz'], ALLAPOT_RENDEZETT, true);
    $fizNap = $s['fizetve_datum'] ?: null;
    $becsult = $rendezett && $fizNap === null;
    if ($becsult) {
        $fizNap = min((string)$s['fizetesi_hatarido'], date('Y-m-d'));
    }
    // csak a vizsgált nap végéig érkezett részteljesítések
    $rl = array_values(array_filter($resztek, fn(array $r): bool => $r['datum'] <= $nap));
    $reszt = round(array_sum(array_column($rl, 'osszeg')), 2);
    if ($s['teljesites_datum'] > $nap) {
        [$allapot, $hatralek, $fizetett, $rl, $reszt] = ['MEG_NEM_LETEZETT', 0.0, 0.0, [], 0.0];
    } elseif ($rendezett && $fizNap <= $nap) {
        [$allapot, $hatralek, $fizetett] = [$s['statusz'], 0.0, $o];
    } else {
        [$allapot, $hatralek, $fizetett] = ['FIZETETLEN', round($o - $reszt, 2), $reszt];
    }
    return ['fizetve_datum' => $rendezett ? $fizNap : null, 'fizetve_becsult' => $becsult, 'allapot' => $allapot,
            'hatralek_napon' => $hatralek, 'fizetett_napon' => $fizetett, 'reszt_napon' => $reszt, 'resztek_napon' => $rl];
}

/**
 * Egy cég bejövő vagy kimenő számlái a vizsgált napi állapotukkal (allapot_napon). Szűkítés: kötés (bejövő), pénznem,
 * teljesítési időszak – időszak nélkül ($tol = $ig = null) a vizsgált napig teljesült összes számla (a cég egyenlegéhez).
 * Sorrend: teljesítés dátuma, azonosító.
 */
function allapot_sorok(string $irany, int $cegId, ?int $kotesId, ?string $tol, ?string $ig, string $nap, ?string $penznem = null): array
{
    $b = $irany === 'BEJOVO';
    $w = [$b ? 'k.ceg_id = ?' : 's.ceg_id = ?'];
    $p = [$cegId];
    $telj = $b ? 'b.teljesites_datum' : 's.teljesites_datum';
    if ($tol !== null && $ig !== null) {
        $w[] = "$telj BETWEEN ? AND ?";
        array_push($p, $tol, $ig);
    } else {
        $w[] = "$telj <= ?";
        $p[] = $nap;
    }
    if ($b && $kotesId !== null) {
        $w[] = 'b.kotes_id = ?';
        $p[] = $kotesId;
    }
    if ($penznem !== null) {
        $w[] = $b ? 'k.penznem = ?' : 's.penznem = ?';
        $p[] = $penznem;
    }
    $sorok = $b
        ? db_all('SELECT b.id, b.kod, b.k_sorszam, b.szamlaszam, b.osszeg, b.statusz, b.kelt, b.teljesites_datum, b.fizetesi_hatarido, b.beszam,
                         COALESCE(b.fizetve_datum, u.utalva_datum) AS fizetve_datum, k.penznem, k.id AS kotes_pk, k.kod AS kotes_kod,
                         k.megnevezes AS kotes_megnevezes, u.uid AS utalas_uid
                    FROM bejovo_szamlak b
                    JOIN kotesek k ON k.id = b.kotes_id
                    LEFT JOIN utalasok u ON u.id = b.utalas_id
                   WHERE ' . implode(' AND ', $w) . ' ORDER BY b.teljesites_datum, b.kod', $p)
        : db_all('SELECT s.id, s.kod, s.szamlaszam, s.osszeg, s.statusz, s.kelt, s.teljesites_datum, s.fizetesi_hatarido, s.fizetve_datum, s.penznem,
                         bk.azonosito AS banki_azonosito
                    FROM kimeno_szamlak s
                    LEFT JOIN banki_kivonatok bk ON bk.id = s.banki_kivonat_id
                   WHERE ' . implode(' AND ', $w) . ' ORDER BY s.teljesites_datum, s.kod', $p);
    $resztek = [];
    foreach (array_chunk(array_column($sorok, 'id'), 5000) as $ids) {
        $resztek += reszteljesitesek_betolt($irany, $ids);
    }
    $ki = [];
    foreach ($sorok as $s) {
        $id = (int)$s['id'];
        $sor = [
            'id' => $id, 'kod' => $s['kod'], 'szamlaszam' => $s['szamlaszam'], 'osszeg' => (float)$s['osszeg'], 'penznem' => $s['penznem'],
            'kelt' => $s['kelt'], 'teljesites_datum' => $s['teljesites_datum'], 'fizetesi_hatarido' => $s['fizetesi_hatarido'], 'statusz' => $s['statusz'],
        ] + allapot_napon($s, $resztek[$id] ?? [], $nap);
        if ($b) {
            $sor += ['k' => sprintf('K%04d', $s['k_sorszam']), 'kotes_pk' => (int)$s['kotes_pk'], 'kotes_kod' => $s['kotes_kod'],
                     'kotes_megnevezes' => $s['kotes_megnevezes'], 'utalas_uid' => $s['utalas_uid'], 'beszam' => $s['beszam']];
        } else {
            $sor['banki_azonosito'] = $s['banki_azonosito'];
        }
        $ki[] = $sor;
    }
    return $ki;
}

/**
 * ÁLLAPOT VIZSGÁLAT: egy cég bejövő vagy kimenő számlái (bejövőnél egy kötésre is szűkíthető), amelyek TELJESÍTÉSI DÁTUMA a
 * tól–ig időszakba esik – azzal az állapottal és egyenleggel, ami a VIZSGÁLT NAPON ($nap) volt érvényes (allapot_napon).
 * Az oldal és a PDF (nyomtatási kosár) is ezt használja, így a kettő mindig ugyanazt mutatja.
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
    $szamlak = allapot_sorok($irany, $cegId, $kotes ? $kotes['id'] : null, $tol, $ig, $nap);
    $egyenleg = [];
    $kotesek = [];
    foreach ($szamlak as $sor) {
        if ($irany === 'BEJOVO') {
            $kotesek[$sor['kotes_pk']] = $kotesek[$sor['kotes_pk']] ?? allapot_ures_osszesito();
            allapot_gyujt($kotesek[$sor['kotes_pk']], $sor);
        }
        $egyenleg[$sor['penznem']] = $egyenleg[$sor['penznem']] ?? allapot_ures_osszesito();
        allapot_gyujt($egyenleg[$sor['penznem']], $sor);
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
        . ($d['kotes'] ? ", kötés {$d['kotes']['kod']}" : '') . ", teljesítés $tol – $ig, állapot $nap napon: " . count($d['szamlak']) . ' db');
    return $d;
}
