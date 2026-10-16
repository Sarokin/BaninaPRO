<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  UTALÁSOK
// ---------------------------------------------------------------------------

require_once __DIR__ . '/api_reszteljesites.php';
require_once __DIR__ . '/audit.php';

/** Utalás-státuszváltás naplózása a számlákra (id → kötés, kód) */
function audit_utalas_szamlak(array $szamlak, string $leiras, string $regi, string $uj, array $extra = [], ?array $u = null): void
{
    foreach ($szamlak as $s) {
        $v = [['m' => 'Státusz', 'r' => ny_statusz_nev($regi === 'AUTO' ? $s['statusz'] : $regi), 'u' => ny_statusz_nev($uj === 'AUTO' ? ((float)$s['osszeg'] < 0 ? 'BESZAMITVA' : 'FIZETVE') : $uj)]];
        foreach ($extra as $e) {
            $v[] = $e;
        }
        audit_ir('BEJOVO', (int)$s['id'], 'STATUSZ', $leiras, $v, (int)$s['kotes_id'], $u);
    }
}

/* Az utalás összege = a bejövő számlák hátraléka (összeg − a hozzáadás előtt felvezetett részteljesítések)
   − a beszámított kimenő számlák levont összege (1.25): ennyit kell ténylegesen elutalni */
const UTALAS_BEJOVO_SQL = 'SELECT COALESCE(SUM(b.osszeg - COALESCE(rb.reszt, 0)), 0) FROM bejovo_szamlak b' . RESZT_BEJOVO_JOIN . 'WHERE b.utalas_id = u.id';
const UTALAS_BESZAMITAS_SQL = 'SELECT COALESCE(SUM(bz.osszeg), 0) FROM beszamitasok bz WHERE bz.utalas_id = u.id';
const UTALAS_OSSZEG_SQL = '(' . UTALAS_BEJOVO_SQL . ') - (' . UTALAS_BESZAMITAS_SQL . ')';
const UTALAS_SQL = 'SELECT u.*, c.nev AS ceg_nev,
        (' . UTALAS_OSSZEG_SQL . ') AS osszeg,
        (' . UTALAS_BEJOVO_SQL . ') AS szamlak_osszeg,
        (' . UTALAS_BESZAMITAS_SQL . ') AS beszamitas_osszeg,
        (SELECT COUNT(*) FROM beszamitasok bz WHERE bz.utalas_id = u.id) AS beszamitas_db,
        (SELECT COUNT(*) FROM bejovo_szamlak b WHERE b.utalas_id = u.id) AS db,
        fl.felhasznalonev AS letrehozta_nev, fu.felhasznalonev AS utalta_nev, fz.felhasznalonev AS lezarta_nev
   FROM utalasok u
   JOIN cegek c ON c.id = u.ceg_id
   LEFT JOIN felhasznalok fl ON fl.id = u.letrehozta
   LEFT JOIN felhasznalok fu ON fu.id = u.utalta
   LEFT JOIN felhasznalok fz ON fz.id = u.lezarta ';

function utalas_sor_feldolgoz(array $u): array
{
    foreach (['id', 'ceg_id', 'ev', 'sorszam', 'db', 'beszamitas_db', 'letrehozta', 'utalta'] as $c) {
        if (array_key_exists($c, $u)) {
            $u[$c] = $u[$c] === null ? null : (int)$u[$c];
        }
    }
    $u['osszeg'] = (float)$u['osszeg'];
    $u['szamlak_osszeg'] = (float)($u['szamlak_osszeg'] ?? $u['osszeg']);
    $u['beszamitas_osszeg'] = (float)($u['beszamitas_osszeg'] ?? 0);
    $u['hatarertek'] = $u['hatarertek'] === null ? null : (float)$u['hatarertek'];
    $u['lezarva'] = (int)($u['lezarva'] ?? 0) === 1;
    return $u;
}

/** Lelakatolt utalásba nem lehet számlát tenni / abból kivenni, és nem törölhető – előbb ki kell nyitni a lakatot */
function utalas_lakat_ellenoriz(array $ut, string $mit): void
{
    if (!empty($ut['lezarva'])) {
        hiba("Az utalás ({$ut['uid']}) le van lakatolva – a gyűjtés befejeződött, ezért $mit. Ha mégis kell, előbb nyisd ki a lakatot az utalás oldalán.", 'LEZARVA', ['utalas_id' => (int)$ut['id'], 'uid' => $ut['uid']]);
    }
}

/** Lakat be / ki (a státusz NYITOTT marad) */
function act_utalas_lezar(array $be): array
{
    $u = csak_bejelentkezve();
    $utalasId = be_int($be, 'utalas_id');
    $lezar = be_bool($be, 'lezar');
    $e = db_tx(function () use ($utalasId, $lezar, $u) {
        $ut = utalas_betolt($utalasId, true);
        if ($ut['statusz'] !== 'NYITOTT') {
            hiba('Csak nyitott utalás lakatolható le / nyitható ki.');
        }
        if ($lezar && $ut['db'] === 0) {
            hiba('Üres utalást nem érdemes lelakatolni – előbb adj hozzá számlákat.');
        }
        db_exec('UPDATE utalasok SET lezarva = ?, lezarta = ?, lezarva_at = ? WHERE id = ?', [$lezar ? 1 : 0, $lezar ? $u['id'] : null, $lezar ? date('Y-m-d H:i:s') : null, $utalasId]);
        return utalas_betolt($utalasId);
    });
    naplo($lezar ? 'UTALAS_LEZAR' : 'UTALAS_KINYIT', "utalás {$e['uid']} " . ($lezar ? 'LELAKATOLVA (a gyűjtés befejezve, státusz NYITOTT marad)' : 'lakat kinyitva') . " – {$e['db']} számla, " . fmt_osszeg($e['osszeg']) . " {$e['penznem']}");
    return ['utalas' => $e];
}

function utalas_betolt(int $id, bool $zarol = false): array
{
    $u = db_row(UTALAS_SQL . 'WHERE u.id = ?' . ($zarol ? ' FOR UPDATE' : ''), [$id]);
    if (!$u) {
        hiba('Az utalás nem található.');
    }
    return utalas_sor_feldolgoz($u);
}

function utalas_osszeg(int $id): float
{
    return round((float)db_val('SELECT COALESCE(SUM(b.osszeg - COALESCE(rb.reszt, 0)), 0) FROM bejovo_szamlak b' . RESZT_BEJOVO_JOIN . 'WHERE b.utalas_id = ?', [$id])
        - (float)db_val('SELECT COALESCE(SUM(osszeg), 0) FROM beszamitasok WHERE utalas_id = ?', [$id]), 2);
}

/** A beszámított kimenő számlák egy utalásban (1.25): a levont összeggel és a számla aktuális hátralékával */
function utalas_beszamitasok(int $id): array
{
    $sorok = db_all('SELECT bz.id AS beszamitas_id, bz.osszeg AS levonva, bz.reszteljesites_id, s.id, s.kod, s.szamlaszam, s.osszeg, s.statusz, s.kelt, s.teljesites_datum,
                            s.fizetesi_hatarido, s.fizetve_datum, COALESCE(rk.reszt, 0) AS reszt, (s.osszeg - COALESCE(rk.reszt, 0)) AS hatralek
                       FROM beszamitasok bz JOIN kimeno_szamlak s ON s.id = bz.kimeno_id' . RESZT_KIMENO_JOIN . 'WHERE bz.utalas_id = ? ORDER BY s.kod', [$id]);
    $bejovok = [];   // beszámítás id → a megjelölt bejövő számlák
    if ($sorok) {
        foreach (db_all('SELECT bb.beszamitas_id, bb.osszeg, b.id, b.kod, b.k_sorszam, b.szamlaszam, b.statusz, b.kotes_id, k.kod AS kotes_kod,
                                (b.osszeg - COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r WHERE r.irany = "BEJOVO" AND r.szamla_id = b.id), 0)) AS hatralek
                           FROM beszamitas_bejovok bb JOIN beszamitasok bz ON bz.id = bb.beszamitas_id JOIN bejovo_szamlak b ON b.id = bb.bejovo_id JOIN kotesek k ON k.id = b.kotes_id
                          WHERE bz.utalas_id = ? ORDER BY k.kod, b.k_sorszam', [$id]) as $r) {
            $bejovok[(int)$r['beszamitas_id']][] = ['id' => (int)$r['id'], 'kod' => $r['kod'], 'k' => sprintf('K%04d', (int)$r['k_sorszam']), 'szamlaszam' => $r['szamlaszam'], 'statusz' => $r['statusz'],
                                                   'kotes_id' => (int)$r['kotes_id'], 'kotes_kod' => $r['kotes_kod'], 'osszeg' => (float)$r['osszeg'], 'hatralek' => (float)$r['hatralek']];
        }
    }
    foreach ($sorok as &$s) {
        foreach (['id', 'beszamitas_id'] as $c) {
            $s[$c] = (int)$s[$c];
        }
        $s['reszteljesites_id'] = $s['reszteljesites_id'] === null ? null : (int)$s['reszteljesites_id'];
        foreach (['levonva', 'osszeg', 'reszt', 'hatralek'] as $c) {
            $s[$c] = (float)$s[$c];
        }
        $s['bejovok'] = $bejovok[$s['beszamitas_id']] ?? [];
    }
    unset($s);
    return $sorok;
}

/** Utalások listája – szűrhető cégre, státuszra, pénznemre */
function act_utalasok(array $be): array
{
    csak_bejelentkezve();
    $w = [];
    $p = [];
    if (($cegId = be_int($be, 'ceg_id', false)) !== null) {
        $w[] = 'u.ceg_id = ?';
        $p[] = $cegId;
    }
    if (!empty($be['statusz']) && in_array($be['statusz'], ['NYITOTT', 'UTALVA'], true)) {
        $w[] = 'u.statusz = ?';
        $p[] = $be['statusz'];
    }
    if (!empty($be['penznem']) && in_array($be['penznem'], ['HUF', 'EUR'], true)) {
        $w[] = 'u.penznem = ?';
        $p[] = $be['penznem'];
    }
    $sql = UTALAS_SQL . ($w ? 'WHERE ' . implode(' AND ', $w) . ' ' : '') . 'ORDER BY (u.statusz = "NYITOTT") DESC, u.id DESC LIMIT 1000';
    $sorok = array_map('utalas_sor_feldolgoz', db_all($sql, $p));
    naplo('UTALASOK', 'utaláslista megtekintve' . ($cegId ? " (cég #$cegId)" : ' (minden utalás)'));
    return ['utalasok' => $sorok];
}

/** Egy cég nyitott utalásai adott pénznemben (az "UTALÁSHOZ" gombhoz) */
function act_utalas_nyitottak(array $be): array
{
    csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $penznem = be_penznem($be);
    $sorok = array_map('utalas_sor_feldolgoz', db_all(UTALAS_SQL . 'WHERE u.ceg_id = ? AND u.penznem = ? AND u.statusz = "NYITOTT" ORDER BY u.id DESC', [$cegId, $penznem]));
    return ['utalasok' => $sorok];
}

/** Utalás összesítő nézete: kötésenként csoportosítva a számlák */
function act_utalas(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id', false);
    if ($id === null) {
        $uid = be_szoveg($be, 'uid', 24, true, 'UID');
        $id = (int)(db_val('SELECT id FROM utalasok WHERE uid = ?', [$uid]) ?? 0);
        if ($id === 0) {
            hiba('Az utalás nem található.');
        }
    }
    $u = utalas_betolt($id);
    $szamlak = db_all(
        'SELECT b.id, b.kod, b.k_sorszam, b.szamlaszam, b.osszeg, b.statusz, b.fizetesi_hatarido, b.kelt, b.teljesites_datum, b.beszam,
                COALESCE(b.fizetve_datum, u.utalva_datum) AS fizetve_datum,
                k.id AS kotes_pk, k.kod AS kotes_kod, k.megnevezes AS kotes_megnevezes,' . RESZT_BEJOVO_MEZOK . '
           FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN utalasok u ON u.id = b.utalas_id' . RESZT_BEJOVO_JOIN . '
          WHERE b.utalas_id = ? ORDER BY k.kod, b.k_sorszam',
        [$id]
    );
    $szamlak = reszt_csatol($szamlak, 'BEJOVO');
    $csoportok = [];
    foreach ($szamlak as $s) {
        $kk = $s['kotes_kod'];
        if (!isset($csoportok[$kk])) {
            $csoportok[$kk] = ['kotes_pk' => (int)$s['kotes_pk'], 'kotes_kod' => $kk, 'kotes_megnevezes' => $s['kotes_megnevezes'], 'osszeg' => 0.0, 'szamlak' => []];
        }
        // az utalásban a hátralék szerepel (a részteljesítés már korábban kifizetésre került)
        $csoportok[$kk]['osszeg'] = round($csoportok[$kk]['osszeg'] + (float)$s['hatralek'], 2);
        $csoportok[$kk]['szamlak'][] = [
            'id' => (int)$s['id'], 'kod' => $s['kod'], 'k' => sprintf('K%04d', $s['k_sorszam']), 'szamlaszam' => $s['szamlaszam'],
            'osszeg' => (float)$s['osszeg'], 'reszt' => $s['reszt'], 'reszt_db' => $s['reszt_db'], 'hatralek' => $s['hatralek'], 'reszteljesitesek' => $s['reszteljesitesek'],
            'statusz' => $s['statusz'], 'fizetesi_hatarido' => $s['fizetesi_hatarido'], 'fizetve_datum' => $s['fizetve_datum'],
            'kelt' => $s['kelt'], 'teljesites_datum' => $s['teljesites_datum'], 'beszam' => $s['beszam'],
        ];
    }
    naplo('UTALAS', "utalás {$u['uid']} megtekintve");
    return ['utalas' => $u, 'kotesek' => array_values($csoportok), 'beszamitasok' => utalas_beszamitasok($id)];
}

/** Új MANUÁLIS (üres) utalás */
function act_utalas_letrehoz(array $be): array
{
    $u = csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $penznem = be_penznem($be);
    $megj = be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés');
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    $e = db_tx(function () use ($cegId, $penznem, $megj, $u) {
        $ev = (int)date('Y');
        $n = kovetkezo_sorszam('UTALAS', $ev, $penznem);
        $uid = utalas_uid($penznem, $ev, $n);
        db_exec(
            'INSERT INTO utalasok (ceg_id, ev, penznem, sorszam, uid, utalas_mod, statusz, megjegyzes, megjegyzes_irta, letrehozta) VALUES (?, ?, ?, ?, ?, "MANUAL", "NYITOTT", ?, ?, ?)',
            [$cegId, $ev, $penznem, $n, $uid, $megj, trim((string)$megj) !== '' ? $u['id'] : null, $u['id']]
        );
        return ['id' => (int)db()->lastInsertId(), 'uid' => $uid];
    });
    naplo('UTALAS_LETREHOZ', "új manuális utalás {$e['uid']} – cég: {$ceg['nev']}, pénznem: $penznem");
    return $e;
}

/**
 * Határértékes utalás jelöltjei: a legrégebb óta várakozó FIZETENDŐ számlák
 * (fizetési határidő, majd kelte szerint), amíg az összeg belefér a limitbe.
 * A limitbe nem férő számlát kihagyja, és a következőkkel folytatja.
 */
function hatarertek_jeloltek(int $cegId, string $penznem, float $limit): array
{
    // a határértékbe a számlák HÁTRALÉKA számít (részteljesítés után)
    $sorok = db_all(
        'SELECT b.id, b.kod, b.szamlaszam, b.osszeg, b.fizetesi_hatarido, b.kelt, k.kod AS kotes_kod, k.id AS kotes_pk,' . RESZT_BEJOVO_MEZOK . '
           FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN . '
          WHERE k.ceg_id = ? AND k.penznem = ? AND b.statusz = "FIZETENDO"
          ORDER BY b.fizetesi_hatarido ASC, b.kelt ASC, b.id ASC',
        [$cegId, $penznem]
    );
    $osszeg = 0.0;
    $valasztott = [];
    $kihagyott = [];
    foreach ($sorok as $s) {
        $s['id'] = (int)$s['id'];
        $s['kotes_pk'] = (int)$s['kotes_pk'];
        $s['osszeg'] = (float)$s['osszeg'];
        $s['reszt'] = (float)$s['reszt'];
        $s['reszt_db'] = (int)$s['reszt_db'];
        $s['hatralek'] = (float)$s['hatralek'];
        $uj = round($osszeg + $s['hatralek'], 2);
        if ($uj <= $limit + 0.000001) {
            $osszeg = $uj;
            $valasztott[] = $s;
        } else {
            $kihagyott[] = $s;
        }
    }
    return ['valasztott' => $valasztott, 'kihagyott' => $kihagyott, 'osszeg' => $osszeg];
}

/** Határértékes utalás előnézete (még nem hoz létre semmit) */
function act_utalas_hatarertek_elonezet(array $be): array
{
    csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $penznem = be_penznem($be);
    $limit = (float)be_osszeg($be, 'hatarertek', false, 'határérték');
    if ($limit <= 0) {
        hiba('A határérték pozitív szám legyen.');
    }
    return hatarertek_jeloltek($cegId, $penznem, $limit) + ['hatarertek' => $limit];
}

/** Határértékes utalás létrehozása a kiválasztott számlákkal */
function act_utalas_hatarertek_letrehoz(array $be): array
{
    $u = csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $penznem = be_penznem($be);
    $limit = (float)be_osszeg($be, 'hatarertek', false, 'határérték');
    if ($limit <= 0) {
        hiba('A határérték pozitív szám legyen.');
    }
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    $ids = isset($be['szamla_ids']) && is_array($be['szamla_ids']) && count($be['szamla_ids']) > 0 ? be_int_lista($be, 'szamla_ids') : null;

    $e = db_tx(function () use ($cegId, $penznem, $limit, $ids, $u, $ceg) {
        if ($ids === null) {
            $j = hatarertek_jeloltek($cegId, $penznem, $limit);
            $ids = array_map(fn($s) => $s['id'], $j['valasztott']);
        }
        if (count($ids) === 0) {
            hiba('Nincs olyan FIZETENDŐ számla, ami beleférne a határértékbe.');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $szamlak = db_all(
            "SELECT b.id, b.kod, b.osszeg, b.statusz, k.penznem, k.ceg_id, (b.osszeg - COALESCE(rb.reszt, 0)) AS hatralek
               FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id" . RESZT_BEJOVO_JOIN . "WHERE b.id IN ($in) FOR UPDATE",
            $ids
        );
        if (count($szamlak) !== count($ids)) {
            hiba('Valamelyik számla nem található.');
        }
        $osszeg = 0.0;
        foreach ($szamlak as $s) {
            if ($s['statusz'] !== 'FIZETENDO') {
                hiba("A(z) {$s['kod']} számla már nem FIZETENDŐ státuszú.");
            }
            if ($s['penznem'] !== $penznem) {
                hiba("A(z) {$s['kod']} számla pénzneme ({$s['penznem']}) nem egyezik az utalás pénznemével ($penznem)!");
            }
            if ((int)$s['ceg_id'] !== $cegId) {
                hiba("A(z) {$s['kod']} számla másik céghez tartozik.");
            }
            $osszeg = round($osszeg + (float)$s['hatralek'], 2);
        }
        if ($osszeg > $limit + 0.000001) {
            hiba('A kiválasztott számlák összege (' . fmt_osszeg($osszeg) . " $penznem) meghaladja a határértéket (" . fmt_osszeg($limit) . " $penznem)!");
        }
        $ev = (int)date('Y');
        $n = kovetkezo_sorszam('UTALAS', $ev, $penznem);
        $uid = utalas_uid($penznem, $ev, $n);
        db_exec(
            'INSERT INTO utalasok (ceg_id, ev, penznem, sorszam, uid, utalas_mod, hatarertek, statusz, letrehozta) VALUES (?, ?, ?, ?, ?, "HATARERTEK", ?, "NYITOTT", ?)',
            [$cegId, $ev, $penznem, $n, $uid, number_format($limit, 2, '.', ''), $u['id']]
        );
        $utalasId = (int)db()->lastInsertId();
        db_exec("UPDATE bejovo_szamlak SET statusz = 'UTALASHOZ_ADVA', utalas_id = ?, modositotta = ? WHERE id IN ($in) AND statusz = 'FIZETENDO'", array_merge([$utalasId, $u['id']], $ids));
        $lista = db_all("SELECT id, kotes_id, statusz, osszeg FROM bejovo_szamlak WHERE id IN ($in)", $ids);
        audit_utalas_szamlak($lista, "Utaláshoz adva (határértékes): $uid", 'FIZETENDO', 'UTALASHOZ_ADVA', [['m' => 'Utalás', 'r' => '–', 'u' => $uid]], $u);
        return ['id' => $utalasId, 'uid' => $uid, 'osszeg' => $osszeg, 'db' => count($ids)];
    });
    naplo('UTALAS_HATARERTEK_LETREHOZ', "határértékes utalás {$e['uid']} – cég: {$ceg['nev']}, limit " . fmt_osszeg($limit) . " $penznem, {$e['db']} számla, összeg " . fmt_osszeg($e['osszeg']) . " $penznem");
    return $e;
}

/** Egy számla hozzáadása az utaláshoz → "UTALÁSHOZ ADVA! UTALÁS ÖSSZEGE EDDIG: ..." */
function act_utalas_szamla_hozzaad(array $be): array
{
    $u = csak_bejelentkezve();
    $utalasId = be_int($be, 'utalas_id');
    $szamlaId = be_int($be, 'szamla_id');
    $e = db_tx(function () use ($utalasId, $szamlaId, $u) {
        $ut = utalas_betolt($utalasId, true);
        if ($ut['statusz'] !== 'NYITOTT') {
            hiba("Az utalás ({$ut['uid']}) már nem nyitott, nem adható hozzá számla.");
        }
        utalas_lakat_ellenoriz($ut, 'nem adható hozzá számla');
        $s = db_row('SELECT b.*, k.penznem, k.ceg_id, (b.osszeg - COALESCE(rb.reszt, 0)) AS hatralek FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN . 'WHERE b.id = ? FOR UPDATE', [$szamlaId]);
        if (!$s) {
            hiba('A számla nem található.');
        }
        if ($s['statusz'] !== 'FIZETENDO') {
            hiba("A(z) {$s['kod']} számla nem FIZETENDŐ státuszú (jelenleg: {$s['statusz']}).");
        }
        if ($s['penznem'] !== $ut['penznem']) {
            hiba("PÉNZNEM-ÜTKÖZÉS! A(z) {$s['kod']} számla {$s['penznem']} pénznemű, az utalás ({$ut['uid']}) pedig {$ut['penznem']}. Egy utaláson belül a pénznemek nem keverhetők!", 'PENZNEM');
        }
        if ((int)$s['ceg_id'] !== $ut['ceg_id']) {
            hiba('A számla másik céghez tartozik, mint az utalás.');
        }
        db_exec('UPDATE bejovo_szamlak SET statusz = "UTALASHOZ_ADVA", utalas_id = ?, modositotta = ? WHERE id = ?', [$utalasId, $u['id'], $szamlaId]);
        audit_utalas_szamlak([$s], "Utaláshoz adva: {$ut['uid']}", 'FIZETENDO', 'UTALASHOZ_ADVA', [['m' => 'Utalás', 'r' => '–', 'u' => $ut['uid']]], $u);
        return ['uid' => $ut['uid'], 'penznem' => $ut['penznem'], 'osszeg' => utalas_osszeg($utalasId), 'kod' => $s['kod'], 'szamla_osszeg' => (float)$s['hatralek'], 'utalas_id' => $utalasId, 'utalas_db' => (int)db_val('SELECT COUNT(*) FROM bejovo_szamlak WHERE utalas_id = ?', [$utalasId])];
    });
    naplo('UTALASHOZ_AD', "számla {$e['kod']} (" . fmt_osszeg($e['szamla_osszeg']) . " {$e['penznem']}) → utalás {$e['uid']}; utalás összege eddig: " . fmt_osszeg($e['osszeg']) . " {$e['penznem']}");
    return $e;
}

/** Egy kötés összes FIZETENDŐ számlájának hozzáadása az utaláshoz */
function act_utalas_kotes_hozzaad(array $be): array
{
    $u = csak_bejelentkezve();
    $utalasId = be_int($be, 'utalas_id');
    $kotesId = be_int($be, 'kotes_id');
    $e = db_tx(function () use ($utalasId, $kotesId, $u) {
        $ut = utalas_betolt($utalasId, true);
        if ($ut['statusz'] !== 'NYITOTT') {
            hiba("Az utalás ({$ut['uid']}) már nem nyitott.");
        }
        utalas_lakat_ellenoriz($ut, 'nem adható hozzá számla');
        $k = db_row('SELECT * FROM kotesek WHERE id = ? FOR UPDATE', [$kotesId]);
        if (!$k) {
            hiba('A kötés nem található.');
        }
        if ($k['penznem'] !== $ut['penznem']) {
            hiba("PÉNZNEM-ÜTKÖZÉS! A kötés ({$k['kod']}) {$k['penznem']} pénznemű, az utalás ({$ut['uid']}) pedig {$ut['penznem']}. Egy utaláson belül a pénznemek nem keverhetők!", 'PENZNEM');
        }
        if ((int)$k['ceg_id'] !== $ut['ceg_id']) {
            hiba('A kötés másik céghez tartozik, mint az utalás.');
        }
        $lista = db_all('SELECT id, kotes_id, statusz, osszeg FROM bejovo_szamlak WHERE kotes_id = ? AND statusz = "FIZETENDO"', [$kotesId]);
        $db = db_exec('UPDATE bejovo_szamlak SET statusz = "UTALASHOZ_ADVA", utalas_id = ?, modositotta = ? WHERE kotes_id = ? AND statusz = "FIZETENDO"', [$utalasId, $u['id'], $kotesId]);
        if ($db === 0) {
            hiba("A kötésben ({$k['kod']}) nincs FIZETENDŐ státuszú számla.");
        }
        audit_utalas_szamlak($lista, "Utaláshoz adva (a kötés minden FIZETENDŐ számlája): {$ut['uid']}", 'FIZETENDO', 'UTALASHOZ_ADVA', [['m' => 'Utalás', 'r' => '–', 'u' => $ut['uid']]], $u);
        return ['uid' => $ut['uid'], 'penznem' => $ut['penznem'], 'osszeg' => utalas_osszeg($utalasId), 'db' => $db, 'kotes_kod' => $k['kod'], 'utalas_id' => $utalasId, 'utalas_db' => (int)db_val('SELECT COUNT(*) FROM bejovo_szamlak WHERE utalas_id = ?', [$utalasId])];
    });
    naplo('UTALASHOZ_AD_KOTES', "kötés {$e['kotes_kod']} {$e['db']} db FIZETENDŐ számlája → utalás {$e['uid']}; utalás összege eddig: " . fmt_osszeg($e['osszeg']) . " {$e['penznem']}");
    return $e;
}

/** Számla eltávolítása nyitott utalásból → vissza FIZETENDŐ státuszba */
function act_utalas_szamla_eltavolit(array $be): array
{
    $u = csak_bejelentkezve();
    $szamlaId = be_int($be, 'szamla_id');
    $e = db_tx(function () use ($szamlaId, $u) {
        $s = db_row('SELECT b.*, ut.uid, ut.statusz AS ut_statusz, ut.id AS ut_id, ut.lezarva AS ut_lezarva FROM bejovo_szamlak b LEFT JOIN utalasok ut ON ut.id = b.utalas_id WHERE b.id = ? FOR UPDATE', [$szamlaId]);
        if (!$s) {
            hiba('A számla nem található.');
        }
        if ($s['statusz'] !== 'UTALASHOZ_ADVA' || !$s['ut_id']) {
            hiba('A számla nincs nyitott utalásban.');
        }
        if ($s['ut_statusz'] !== 'NYITOTT') {
            hiba('Az utalás már teljesült, a számla nem vehető ki belőle. (Irodavezető / admin: utalás visszanyitása.)');
        }
        utalas_lakat_ellenoriz(['lezarva' => $s['ut_lezarva'], 'uid' => $s['uid'], 'id' => $s['ut_id']], 'nem vehető ki belőle számla');
        db_exec('UPDATE bejovo_szamlak SET statusz = "FIZETENDO", utalas_id = NULL, modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
        if (utalas_osszeg((int)$s['ut_id']) < -0.005) {
            hiba("A(z) {$s['kod']} számla nélkül az utalás ({$s['uid']}) összege negatív lenne: a beszámított kimenő számlák többet tesznek ki a maradék bejövő számláknál. Előbb vedd ki (vagy csökkentsd) a beszámítást, aztán ezt a számlát.", 'BESZAMITAS_TOBB');
        }
        audit_utalas_szamlak([$s], "Kivéve az utalásból: {$s['uid']}", 'UTALASHOZ_ADVA', 'FIZETENDO', [['m' => 'Utalás', 'r' => $s['uid'], 'u' => '–']], $u);
        return ['uid' => $s['uid'], 'kod' => $s['kod'], 'osszeg' => utalas_osszeg((int)$s['ut_id']), 'utalas_id' => (int)$s['ut_id']];
    });
    naplo('UTALASBOL_KIVESZ', "számla {$e['kod']} kivéve az utalásból ({$e['uid']}); utalás összege: " . fmt_osszeg($e['osszeg']));
    return $e;
}

/** Utalás teljesítése (elutalva): számlák → FIZETVE, negatív összeg → BESZÁMÍTVA */
function act_utalas_utalva(array $be): array
{
    $u = csak_bejelentkezve();
    $utalasId = be_int($be, 'utalas_id');
    $datum = be_datum($be, 'datum', false, 'utalás dátuma') ?? date('Y-m-d');
    $hiv = be_szoveg($be, 'banki_hivatkozas', 100, false, 'banki hivatkozás');
    $e = db_tx(function () use ($utalasId, $datum, $hiv, $u) {
        $ut = utalas_betolt($utalasId, true);
        if ($ut['statusz'] !== 'NYITOTT') {
            hiba('Az utalás már teljesítve van.');
        }
        if ($ut['db'] === 0) {
            hiba('Az utalás üres – előbb adj hozzá számlákat.');
        }
        if ($ut['osszeg'] < -0.005) {
            hiba('Az utalás összege negatív: a beszámított kimenő számlák többet tesznek ki a bejövő számláknál. Vedd ki vagy csökkentsd a beszámítást, vagy adj hozzá bejövő számlát.', 'BESZAMITAS_TOBB');
        }
        $lista = db_all('SELECT id, kotes_id, statusz, osszeg FROM bejovo_szamlak WHERE utalas_id = ? AND statusz = "UTALASHOZ_ADVA"', [$utalasId]);
        $bzLista = utalas_beszamitasok($utalasId);   // a státuszváltás előtt: a megjelölt bejövő számlák még UTALÁSHOZ ADVA állapotban, a hátralékukkal
        db_exec('UPDATE bejovo_szamlak SET statusz = IF(osszeg < 0, "BESZAMITVA", "FIZETVE"), fizetve_datum = ?, modositotta = ? WHERE utalas_id = ? AND statusz = "UTALASHOZ_ADVA"', [$datum, $u['id'], $utalasId]);
        db_exec('UPDATE utalasok SET statusz = "UTALVA", utalva_datum = ?, banki_hivatkozas = ?, utalva_at = NOW(), utalta = ? WHERE id = ?', [$datum, $hiv, $u['id'], $utalasId]);
        audit_utalas_szamlak($lista, "Utalás teljesítve: {$ut['uid']}" . ($hiv ? " (hiv.: $hiv)" : ''), 'UTALASHOZ_ADVA', 'AUTO', [['m' => 'Utalás / fizetés dátuma', 'r' => '–', 'u' => audit_ertek('fizetve_datum', $datum)]], $u);
        // a megjelölt bejövő számlák (1.25): amelyiknek a teljes hátralékát a beszámítás(ok) fedezik, BESZÁMÍTVA – nem utalással, hanem a kimenő számlával rendezve
        $fedezet = [];
        foreach ($bzLista as $b) {
            foreach ($b['bejovok'] as $bb) {
                if ($bb['statusz'] !== 'UTALASHOZ_ADVA') {
                    continue;
                }
                $f = &$fedezet[$bb['id']];
                $f = $f ?? ['bejovo' => $bb, 'osszeg' => 0.0, 'kimeno' => []];
                $f['osszeg'] = round($f['osszeg'] + $bb['osszeg'], 2);
                $f['kimeno'][] = "{$b['kod']} „{$b['szamlaszam']}”";
                unset($f);
            }
        }
        foreach ($fedezet as $bid => $f) {
            $teljes = $f['osszeg'] >= $f['bejovo']['hatralek'] - 0.005;
            if ($teljes) {
                db_exec('UPDATE bejovo_szamlak SET statusz = "BESZAMITVA" WHERE id = ? AND utalas_id = ? AND statusz = "FIZETVE"', [$bid, $utalasId]);
            }
            audit_ir('BEJOVO', (int)$bid, 'STATUSZ', ($teljes ? 'Beszámítással rendezve (BESZÁMÍTVA): a teljes összeget a kimenő számla fedezi: ' : 'Részben beszámítással rendezve: ' . fmt_osszeg($f['osszeg']) . " {$ut['penznem']} a kimenő számlából, a többi utalással: ")
                . implode(', ', $f['kimeno']) . " – {$ut['uid']}", $teljes ? [['m' => 'Státusz', 'r' => ny_statusz_nev('FIZETVE'), 'u' => ny_statusz_nev('BESZAMITVA')]] : [], (int)$f['bejovo']['kotes_id'], $u);
        }
        // a beszámított kimenő számlák (1.25): a teljes hátralék levonása → FIZETVE az utalás napjával; a részleges levonás → részteljesítés
        $bz = 0;
        foreach ($bzLista as $b) {
            db_row('SELECT id FROM kimeno_szamlak WHERE id = ? FOR UPDATE', [$b['id']]);
            $bz++;
            if ($b['statusz'] !== 'NYITOTT') {
                continue;   // közben más úton rendeződött – a beszámítás az utalásban marad, a számlához nem nyúlunk
            }
            $hiv2 = 'Beszámítás: ' . $ut['uid'];
            if ($b['levonva'] >= $b['hatralek'] - 0.005) {
                db_exec('UPDATE kimeno_szamlak SET statusz = "FIZETVE", fizetve_datum = ?, modositotta = ? WHERE id = ?', [$datum, $u['id'], $b['id']]);
                audit_ir('KIMENO', $b['id'], 'STATUSZ', "Fizetve beszámítással – az utalás teljesítve: {$ut['uid']} (levonva " . fmt_osszeg($b['levonva']) . " {$ut['penznem']})",
                    [['m' => 'Státusz', 'r' => 'NYITOTT', 'u' => 'FIZETVE'], ['m' => 'Fizetés dátuma', 'r' => '–', 'u' => audit_ertek('fizetve_datum', $datum)], ['m' => 'Banki azonosító', 'r' => '–', 'u' => $hiv2]], null, $u);
            } else {
                db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito, letrehozta) VALUES ('KIMENO', ?, ?, ?, ?, ?)",
                    [$b['id'], number_format($b['levonva'], 2, '.', ''), $datum, $hiv2, $u['id']]);
                $rid = (int)db()->lastInsertId();
                db_exec('UPDATE beszamitasok SET reszteljesites_id = ? WHERE id = ?', [$rid, $b['beszamitas_id']]);
                db_exec('UPDATE kimeno_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $b['id']]);
                audit_ir('KIMENO', $b['id'], 'RESZT', "Részbeszámítás – az utalás teljesítve: {$ut['uid']}: " . fmt_osszeg($b['levonva']) . " {$ut['penznem']} levonva (" . audit_ertek('kelt', $datum) . ')',
                    [['m' => 'Hátralék', 'r' => fmt_osszeg($b['hatralek']) . " {$ut['penznem']}", 'u' => fmt_osszeg(round($b['hatralek'] - $b['levonva'], 2)) . " {$ut['penznem']}"]], null, $u);
            }
        }
        $ut['beszamitas_db'] = $bz;
        return $ut;
    });
    naplo('UTALAS_TELJESITVE', "utalás {$e['uid']} teljesítve ($datum" . ($hiv ? ", hiv.: $hiv" : '') . "), {$e['db']} számla" . ($e['beszamitas_db'] ? " + {$e['beszamitas_db']} beszámított kimenő" : '') . ', összeg ' . fmt_osszeg($e['osszeg']) . " {$e['penznem']} – számlák FIZETVE/BESZÁMÍTVA");
    return ['uid' => $e['uid'], 'osszeg' => $e['osszeg'], 'db' => $e['db'], 'beszamitas_db' => $e['beszamitas_db']];
}

/**
 * Beszámítás (1.25): egy NYITOTT kimenő számla levonása az utalásból – a megadott összeggel (alapból a számla teljes
 * hátraléka, de legfeljebb annyi, amennyi az utalásba belefér: az utalás nem lehet negatív). A számla NYITOTT marad
 * („beszámításra adva”), az utalás teljesítésekor rendeződik: teljes hátralék → FIZETVE, rész → részteljesítés.
 */
function act_utalas_kimeno_hozzaad(array $be): array
{
    $u = csak_bejelentkezve();
    $utalasId = be_int($be, 'utalas_id');
    $szamlaId = be_int($be, 'szamla_id');
    $kert = isset($be['osszeg']) && trim((string)$be['osszeg']) !== '' ? be_osszeg($be, 'osszeg', false, 'beszámított összeg') : null;   // üresen: amennyi belefér
    $bejovoIds = isset($be['bejovo_ids']) && is_array($be['bejovo_ids']) ? array_values(array_unique(array_map('intval', $be['bejovo_ids']))) : [];   // a megjelölt bejövő számlák (nem kötelező)
    $e = db_tx(function () use ($utalasId, $szamlaId, $kert, $bejovoIds, $u) {
        $ut = utalas_betolt($utalasId, true);
        if ($ut['statusz'] !== 'NYITOTT') {
            hiba("Az utalás ({$ut['uid']}) már nem nyitott, nem adható hozzá beszámítás.");
        }
        utalas_lakat_ellenoriz($ut, 'nem adható hozzá beszámítás');
        $s = db_row('SELECT s.*, (s.osszeg - COALESCE(rk.reszt, 0)) AS hatralek FROM kimeno_szamlak s' . RESZT_KIMENO_JOIN . 'WHERE s.id = ? FOR UPDATE', [$szamlaId]);
        if (!$s) {
            hiba('A kimenő számla nem található.');
        }
        if ($s['statusz'] !== 'NYITOTT') {
            hiba("A(z) {$s['kod']} kimenő számla már FIZETVE státuszú – csak nyitott számla számítható be.");
        }
        $fuggo = kimeno_fuggo_beszamitas($szamlaId);
        if ($fuggo !== null) {
            hiba("A(z) {$s['kod']} kimenő számla már beszámításra van adva: {$fuggo['uid']} (" . fmt_osszeg((float)$fuggo['osszeg']) . " {$s['penznem']}). Egyszerre egy nyitott utalásban lehet.");
        }
        if ($s['penznem'] !== $ut['penznem']) {
            hiba("PÉNZNEM-ÜTKÖZÉS! A(z) {$s['kod']} kimenő számla {$s['penznem']} pénznemű, az utalás ({$ut['uid']}) pedig {$ut['penznem']}. Csak azonos pénznemű számla számítható be!", 'PENZNEM');
        }
        if ((int)$s['ceg_id'] !== $ut['ceg_id']) {
            hiba('A kimenő számla másik céghez tartozik, mint az utalás – csak ugyanannak a partnernek a számlája számítható be.');
        }
        $hatralek = round((float)$s['hatralek'], 2);
        if ($hatralek <= 0) {
            hiba("A(z) {$s['kod']} kimenő számla hátraléka nem pozitív (" . fmt_osszeg($hatralek) . " {$s['penznem']}) – jóváíró számla nem számítható be.");
        }
        $belefer = round(max(0.0, $ut['osszeg']), 2);
        $osszeg = $kert === null ? round(min($hatralek, $belefer), 2) : round((float)$kert, 2);
        if ($osszeg <= 0) {
            hiba($kert === null
                ? "Az utalás ({$ut['uid']}) összege most " . fmt_osszeg($ut['osszeg']) . " {$ut['penznem']} – nincs mit beszámítani. Előbb adj hozzá bejövő számlát."
                : 'A beszámított összeg pozitív szám legyen.');
        }
        if ($osszeg > $hatralek + 0.005) {
            hiba('A beszámított összeg (' . fmt_osszeg($osszeg) . " {$ut['penznem']}) nem lehet több a számla hátralékánál (" . fmt_osszeg($hatralek) . " {$ut['penznem']}).");
        }
        if ($osszeg > $belefer + 0.005) {
            hiba('A beszámított összeg (' . fmt_osszeg($osszeg) . " {$ut['penznem']}) több, mint amennyi az utalásba belefér (" . fmt_osszeg($belefer) . " {$ut['penznem']}) – az utalás nem lehet negatív. Írj kisebb összeget, vagy adj hozzá bejövő számlát.", 'BESZAMITAS_TOBB');
        }
        $reszleges = $osszeg < $hatralek - 0.005;
        // a megjelölt bejövő számlák: az utalásban lévő, pozitív hátralékú UTALÁSHOZ ADVA számlák, az utalás sorrendjében; a levonás sorban szétosztva
        $felosztas = [];
        if ($bejovoIds) {
            $in = implode(',', array_fill(0, count($bejovoIds), '?'));
            $bejovok = db_all("SELECT b.id, b.kod, b.k_sorszam, b.szamlaszam, b.kotes_id, (b.osszeg - COALESCE(rb.reszt, 0)) AS hatralek FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id" . RESZT_BEJOVO_JOIN
                . "WHERE b.id IN ($in) AND b.utalas_id = ? AND b.statusz = \"UTALASHOZ_ADVA\" ORDER BY k.kod, b.k_sorszam", array_merge($bejovoIds, [$utalasId]));
            if (count($bejovok) !== count($bejovoIds)) {
                hiba('A megjelölt bejövő számlák valamelyike nincs ebben az utalásban (vagy már nem utalásra vár).');
            }
            $marad = $osszeg;
            foreach ($bejovok as $b) {
                $jut = round(min((float)$b['hatralek'], $marad), 2);
                if ($jut <= 0) {
                    continue;
                }
                $felosztas[] = ['bejovo' => $b, 'osszeg' => $jut, 'fedezi' => $jut >= (float)$b['hatralek'] - 0.005];
                $marad = round($marad - $jut, 2);
            }
            if (!$felosztas) {
                hiba('A megjelölt bejövő számlák hátraléka nem pozitív – beszámítás nem rendezheti őket.');
            }
        }
        db_exec('INSERT INTO beszamitasok (utalas_id, kimeno_id, osszeg, letrehozta) VALUES (?, ?, ?, ?)', [$utalasId, $szamlaId, number_format($osszeg, 2, '.', ''), $u['id']]);
        $bzId = (int)db()->lastInsertId();
        foreach ($felosztas as $f) {
            db_exec('INSERT INTO beszamitas_bejovok (beszamitas_id, bejovo_id, osszeg) VALUES (?, ?, ?)', [$bzId, $f['bejovo']['id'], number_format($f['osszeg'], 2, '.', '')]);
        }
        db_exec('UPDATE kimeno_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
        $bejSzoveg = $felosztas ? ' – rendezi: ' . implode(', ', array_map(fn($f) => "{$f['bejovo']['kod']} „{$f['bejovo']['szamlaszam']}” " . fmt_osszeg($f['osszeg']) . ($f['fedezi'] ? ' (teljesen)' : ' (részben, a többi utalással)'), $felosztas)) : '';
        audit_ir('KIMENO', $szamlaId, 'STATUSZ', "Beszámításra adva az utalásba: {$ut['uid']} – levonva " . fmt_osszeg($osszeg) . " {$ut['penznem']}"
            . ($reszleges ? ' (részbeszámítás, a számla hátraléka ' . fmt_osszeg($hatralek) . " {$ut['penznem']})" : ' (a teljes hátralék)') . $bejSzoveg,
            [['m' => 'Utalás', 'r' => '–', 'u' => $ut['uid']]], null, $u);
        foreach ($felosztas as $f) {
            audit_ir('BEJOVO', (int)$f['bejovo']['id'], 'STATUSZ', "Beszámítás vár rá az utalásban ({$ut['uid']}): a(z) {$s['kod']} „{$s['szamlaszam']}” kimenő számlából " . fmt_osszeg($f['osszeg']) . " {$ut['penznem']}"
                . ($f['fedezi'] ? ' – teljesítéskor BESZÁMÍTVA lesz' : ' (a többi utalással)'), [['m' => 'Beszámítás', 'r' => '–', 'u' => $s['kod']]], (int)$f['bejovo']['kotes_id'], $u);
        }
        return ['uid' => $ut['uid'], 'penznem' => $ut['penznem'], 'osszeg' => utalas_osszeg($utalasId), 'kod' => $s['kod'], 'szamlaszam' => $s['szamlaszam'], 'szamla_osszeg' => $osszeg,
                'hatralek' => $hatralek, 'reszleges' => $reszleges, 'utalas_id' => $utalasId,
                'bejovok' => array_map(fn($f) => ['kod' => $f['bejovo']['kod'], 'szamlaszam' => $f['bejovo']['szamlaszam'], 'osszeg' => $f['osszeg'], 'fedezi' => $f['fedezi']], $felosztas),
                'utalas_db' => (int)db_val('SELECT COUNT(*) FROM bejovo_szamlak WHERE utalas_id = ?', [$utalasId]), 'beszamitas_db' => (int)db_val('SELECT COUNT(*) FROM beszamitasok WHERE utalas_id = ?', [$utalasId])];
    });
    naplo('BESZAMITAS_AD', "kimenő számla {$e['kod']} („{$e['szamlaszam']}”): " . fmt_osszeg($e['szamla_osszeg']) . " {$e['penznem']} beszámítva az utalásba {$e['uid']}" . ($e['reszleges'] ? ' (részbeszámítás, hátralék ' . fmt_osszeg($e['hatralek']) . ')' : '') . '; utalandó összeg: ' . fmt_osszeg($e['osszeg']) . " {$e['penznem']}");
    return $e;
}

/** A kimenő számla függő (nyitott utalásban lévő) beszámítása, vagy null */
function kimeno_fuggo_beszamitas(int $szamlaId): ?array
{
    $r = db_row('SELECT bz.id, bz.osszeg, ut.id AS utalas_id, ut.uid FROM beszamitasok bz JOIN utalasok ut ON ut.id = bz.utalas_id WHERE bz.kimeno_id = ? AND ut.statusz = "NYITOTT" LIMIT 1', [$szamlaId]);
    return $r ?: null;
}

/** Beszámítás kivétele nyitott utalásból – a kimenő számla NYITOTT marad, csak a levonás szűnik meg */
function act_utalas_kimeno_eltavolit(array $be): array
{
    $u = csak_bejelentkezve();
    $szamlaId = be_int($be, 'szamla_id');
    $e = db_tx(function () use ($szamlaId, $u) {
        $s = db_row('SELECT s.id, s.kod, s.penznem, bz.id AS bz_id, bz.osszeg AS levonva, ut.uid, ut.statusz AS ut_statusz, ut.id AS ut_id, ut.lezarva AS ut_lezarva
                       FROM kimeno_szamlak s LEFT JOIN beszamitasok bz ON bz.kimeno_id = s.id LEFT JOIN utalasok ut ON ut.id = bz.utalas_id AND ut.statusz = "NYITOTT"
                      WHERE s.id = ? AND ut.id IS NOT NULL LIMIT 1 FOR UPDATE', [$szamlaId]);
        if (!$s) {
            hiba('A kimenő számla nincs nyitott utalásban beszámításra adva. (Ha az utalás már teljesült: irodavezető / admin az utalás visszanyitásával vonhatja vissza.)');
        }
        utalas_lakat_ellenoriz(['lezarva' => $s['ut_lezarva'], 'uid' => $s['uid'], 'id' => $s['ut_id']], 'nem vehető ki belőle a beszámítás');
        db_exec('DELETE FROM beszamitasok WHERE id = ?', [$s['bz_id']]);
        db_exec('UPDATE kimeno_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
        audit_ir('KIMENO', $szamlaId, 'STATUSZ', "Beszámítás kivéve az utalásból: {$s['uid']} (" . fmt_osszeg((float)$s['levonva']) . " {$s['penznem']}) – a számla NYITOTT marad", [['m' => 'Utalás', 'r' => $s['uid'], 'u' => '–']], null, $u);
        return ['uid' => $s['uid'], 'kod' => $s['kod'], 'osszeg' => utalas_osszeg((int)$s['ut_id']), 'utalas_id' => (int)$s['ut_id'], 'penznem' => $s['penznem']];
    });
    naplo('BESZAMITAS_KIVESZ', "kimenő számla {$e['kod']} beszámítása kivéve az utalásból ({$e['uid']}); utalandó összeg: " . fmt_osszeg($e['osszeg']) . " {$e['penznem']}");
    return $e;
}

/** Teljesített utalás visszanyitása (Irodavezető / Admin) */
function act_utalas_visszanyit(array $be): array
{
    $u = csak_jog('torol');
    $utalasId = be_int($be, 'utalas_id');
    $e = db_tx(function () use ($utalasId, $u) {
        $ut = utalas_betolt($utalasId, true);
        if ($ut['statusz'] !== 'UTALVA') {
            hiba('Az utalás nem teljesített státuszú.');
        }
        $lista = db_all('SELECT id, kotes_id, statusz, osszeg FROM bejovo_szamlak WHERE utalas_id = ?', [$utalasId]);
        db_exec('UPDATE bejovo_szamlak SET statusz = "UTALASHOZ_ADVA", fizetve_datum = NULL, modositotta = ? WHERE utalas_id = ?', [$u['id'], $utalasId]);
        // a beszámított kimenő számlák (1.25): a beszámítással fizetett vissza NYITOTT-ba, a részbeszámítás részteljesítése törlődik
        foreach (utalas_beszamitasok($utalasId) as $b) {
            if ($b['reszteljesites_id'] !== null) {
                $r = db_row('SELECT * FROM reszteljesitesek WHERE id = ?', [$b['reszteljesites_id']]);
                db_exec('DELETE FROM reszteljesitesek WHERE id = ?', [$b['reszteljesites_id']]);
                db_exec('UPDATE beszamitasok SET reszteljesites_id = NULL WHERE id = ?', [$b['beszamitas_id']]);
                db_exec('UPDATE kimeno_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $b['id']]);
                audit_ir('KIMENO', $b['id'], 'RESZT', "Utalás visszanyitva: {$ut['uid']} – a részbeszámítás (" . fmt_osszeg((float)($r['osszeg'] ?? $b['levonva'])) . " {$ut['penznem']}) törölve, a számla újra beszámításra adva", [], null, $u);
            } elseif ($b['statusz'] === 'FIZETVE' && $b['fizetve_datum'] === $ut['utalva_datum'] && !db_val('SELECT banki_kivonat_id FROM kimeno_szamlak WHERE id = ?', [$b['id']])) {
                db_exec('UPDATE kimeno_szamlak SET statusz = "NYITOTT", fizetve_datum = NULL, modositotta = ? WHERE id = ?', [$u['id'], $b['id']]);
                audit_ir('KIMENO', $b['id'], 'STATUSZ', "Utalás visszanyitva: {$ut['uid']} – a beszámítással fizetett számla újra NYITOTT (beszámításra adva)",
                    [['m' => 'Státusz', 'r' => 'FIZETVE', 'u' => 'NYITOTT'], ['m' => 'Fizetés dátuma', 'r' => audit_ertek('fizetve_datum', $ut['utalva_datum']), 'u' => '–']], null, $u);
            }
        }
        db_exec('UPDATE utalasok SET statusz = "NYITOTT", utalva_datum = NULL, utalva_at = NULL, utalta = NULL, lezarva = 0, lezarta = NULL, lezarva_at = NULL WHERE id = ?', [$utalasId]);
        audit_utalas_szamlak($lista, "Utalás visszanyitva: {$ut['uid']}", 'AUTO', 'UTALASHOZ_ADVA', [['m' => 'Utalás / fizetés dátuma', 'r' => audit_ertek('fizetve_datum', $ut['utalva_datum']), 'u' => '–']], $u);
        return $ut;
    });
    naplo('UTALAS_VISSZANYIT', "utalás {$e['uid']} visszanyitva (" . szerep_nev($u['szerep']) . ") – számlák vissza UTALÁSHOZ ADVA státuszba");
    return ['uid' => $e['uid']];
}

/** Nyitott utalás törlése – a számlái visszakerülnek FIZETENDŐ státuszba */
function act_utalas_torol(array $be): array
{
    $u = csak_bejelentkezve();
    $utalasId = be_int($be, 'utalas_id');
    $e = db_tx(function () use ($utalasId, $u) {
        $ut = utalas_betolt($utalasId, true);
        if ($ut['statusz'] !== 'NYITOTT') {
            hiba('Csak nyitott utalás törölhető.');
        }
        utalas_lakat_ellenoriz($ut, 'nem törölhető');
        $lista = db_all('SELECT id, kotes_id, statusz, osszeg FROM bejovo_szamlak WHERE utalas_id = ?', [$utalasId]);
        db_exec('UPDATE bejovo_szamlak SET statusz = "FIZETENDO", utalas_id = NULL, modositotta = ? WHERE utalas_id = ?', [$u['id'], $utalasId]);
        foreach (utalas_beszamitasok($utalasId) as $b) {   // a beszámítások az utalással együtt törlődnek (a kimenő számla NYITOTT marad)
            audit_ir('KIMENO', $b['id'], 'STATUSZ', "Az utalás törölve ({$ut['uid']}) – a beszámítás (" . fmt_osszeg($b['levonva']) . " {$ut['penznem']}) megszűnt, a számla NYITOTT marad", [['m' => 'Utalás', 'r' => $ut['uid'], 'u' => '–']], null, $u);
        }
        db_exec('DELETE FROM beszamitasok WHERE utalas_id = ?', [$utalasId]);
        db_exec('DELETE FROM utalasok WHERE id = ?', [$utalasId]);
        audit_utalas_szamlak($lista, "Az utalás törölve ({$ut['uid']}) – a számla vissza FIZETENDŐ státuszba", 'UTALASHOZ_ADVA', 'FIZETENDO', [['m' => 'Utalás', 'r' => $ut['uid'], 'u' => '–']], $u);
        return $ut;
    });
    naplo('UTALAS_TOROL', "utalás {$e['uid']} törölve ({$e['db']} számla visszakerült FIZETENDŐ státuszba)");
    return ['torolve' => true];
}

/** Utalás megjegyzés / banki hivatkozás módosítása */
function act_utalas_modosit(array $be): array
{
    $u = csak_bejelentkezve();
    $utalasId = be_int($be, 'utalas_id');
    $ut = utalas_betolt($utalasId);
    $mj = megjegyzes_szabaly($ut, be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés'), $u);   // más megjegyzése: csak hozzáfűzés
    $hiv = jog_van('ir', $u) ? be_szoveg($be, 'banki_hivatkozas', 100, false, 'banki hivatkozás') : $ut['banki_hivatkozas'];   // Üzletkötő: csak megjegyzés
    db_exec('UPDATE utalasok SET megjegyzes = ?, megjegyzes_irta = ?, banki_hivatkozas = ? WHERE id = ?', [$mj['megjegyzes'], $mj['irta'], $hiv, $utalasId]);
    naplo('UTALAS_MODOSIT', "utalás {$ut['uid']}: megjegyzés/banki hivatkozás módosítva");
    return ['modositva' => true];
}
