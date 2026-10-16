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

/* Az utalás összege = a bejövő tételek utalt része − a beszámított kimenő számlák levont összege (1.25): ennyit kell ténylegesen elutalni.
   Bejövő tétel (1.27): a számla egészben (utalas_id – a tétel a számla maradéka: összeg − utalt részteljesítések − más utalásokban függő
   részek), vagy egy részösszege (reszteljesitesek.utalas_id: függő, dátum nélkül, amíg az utalás nyitott; teljesítéskor dátumot kap).
   Egy számla egyszerre több nyitott utalásban is lehet részenként. */
const UTALAS_TETEL_SQL = 'SELECT COALESCE(SUM(b.osszeg - COALESCE(rb.reszt, 0) - COALESCE(rb.fuggo, 0)), 0) FROM bejovo_szamlak b' . RESZT_BEJOVO_JOIN . 'WHERE b.utalas_id = u.id';
const UTALAS_RESZ_SQL = 'SELECT COALESCE(SUM(r.osszeg), 0) FROM reszteljesitesek r WHERE r.irany = "BEJOVO" AND r.utalas_id = u.id';
const UTALAS_BEJOVO_SQL = '(' . UTALAS_TETEL_SQL . ') + (' . UTALAS_RESZ_SQL . ')';
const UTALAS_BESZAMITAS_SQL = 'SELECT COALESCE(SUM(bz.osszeg), 0) FROM beszamitasok bz WHERE bz.utalas_id = u.id';
const UTALAS_OSSZEG_SQL = '(' . UTALAS_BEJOVO_SQL . ') - (' . UTALAS_BESZAMITAS_SQL . ')';
const UTALAS_SQL = 'SELECT u.*, c.nev AS ceg_nev,
        (' . UTALAS_OSSZEG_SQL . ') AS osszeg,
        (' . UTALAS_BEJOVO_SQL . ') AS szamlak_osszeg,
        (' . UTALAS_BESZAMITAS_SQL . ') AS beszamitas_osszeg,
        (SELECT COUNT(*) FROM beszamitasok bz WHERE bz.utalas_id = u.id) AS beszamitas_db,
        (SELECT COUNT(*) FROM bejovo_szamlak b WHERE b.utalas_id = u.id) + (SELECT COUNT(*) FROM reszteljesitesek r WHERE r.irany = "BEJOVO" AND r.utalas_id = u.id) AS db,
        (SELECT COUNT(*) FROM reszteljesitesek r WHERE r.irany = "BEJOVO" AND r.utalas_id = u.id) AS reszosszeg_db,
        fl.felhasznalonev AS letrehozta_nev, fu.felhasznalonev AS utalta_nev, fz.felhasznalonev AS lezarta_nev
   FROM utalasok u
   JOIN cegek c ON c.id = u.ceg_id
   LEFT JOIN felhasznalok fl ON fl.id = u.letrehozta
   LEFT JOIN felhasznalok fu ON fu.id = u.utalta
   LEFT JOIN felhasznalok fz ON fz.id = u.lezarta ';

function utalas_sor_feldolgoz(array $u): array
{
    foreach (['id', 'ceg_id', 'ev', 'sorszam', 'db', 'beszamitas_db', 'reszosszeg_db', 'letrehozta', 'utalta'] as $c) {
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
    return round((float)db_val('SELECT COALESCE(SUM(b.osszeg - COALESCE(rb.reszt, 0) - COALESCE(rb.fuggo, 0)), 0) FROM bejovo_szamlak b' . RESZT_BEJOVO_JOIN . 'WHERE b.utalas_id = ?', [$id])
        + (float)db_val('SELECT COALESCE(SUM(osszeg), 0) FROM reszteljesitesek WHERE irany = "BEJOVO" AND utalas_id = ?', [$id])
        - (float)db_val('SELECT COALESCE(SUM(osszeg), 0) FROM beszamitasok WHERE utalas_id = ?', [$id]), 2);
}

/** Az utalás tételeinek száma: egészben benne lévő számlák + részösszegek */
function utalas_db(int $id): int
{
    return (int)db_val('SELECT (SELECT COUNT(*) FROM bejovo_szamlak WHERE utalas_id = ?) + (SELECT COUNT(*) FROM reszteljesitesek WHERE irany = "BEJOVO" AND utalas_id = ?)', [$id, $id]);
}

/** Egy bejövő számla függő részei szövegesen: „U-… (6 000 EUR), U-… (9 500 EUR)” */
function utalas_fuggo_lista(int $szamlaId, string $pn): string
{
    return implode(', ', array_map(fn(array $r): string => $r['uid'] . ' (' . fmt_osszeg((float)$r['osszeg']) . " $pn)",
        db_all('SELECT ut.uid, r.osszeg FROM reszteljesitesek r JOIN utalasok ut ON ut.id = r.utalas_id WHERE r.irany = "BEJOVO" AND r.szamla_id = ? AND r.datum IS NULL ORDER BY ut.id', [$szamlaId])));
}

/**
 * Az utalás bejövő tételei (1.27): az egészben benne lévő számlák (utalas_id – a tétel a számla maradéka: összeg − utalt részek − más
 * utalásokban függő részek) + a részösszegek (reszteljesitesek.utalas_id: függő, amíg az utalás nyitott; teljesítéskor dátumot kap).
 * Minden sorban: tetel_osszeg = ami az utalásban számít, reszosszeg = részösszeg-e, reszt_id = a részösszeg sora, fuggo_sor = még
 * függő-e, hatralek = a számla még szabad része, mas_fuggo = a számla más utalásokban függő része. Kötés, majd K sorszám szerint.
 * Ugyanezt használja az utalás oldala és a PDF – egy adatforrás.
 */
function utalas_tetelek(int $id): array
{
    $mezok = 'b.id, b.kod, b.k_sorszam, b.szamlaszam, b.osszeg, b.statusz, b.fizetesi_hatarido, b.kelt, b.teljesites_datum, b.beszam, b.utalas_id,
              k.id AS kotes_pk, k.kod AS kotes_kod, k.megnevezes AS kotes_megnevezes,' . RESZT_BEJOVO_MEZOK;
    $sorok = db_all('SELECT ' . $mezok . ', COALESCE(b.fizetve_datum, u.utalva_datum) AS fizetve_datum, NULL AS reszt_id, NULL AS resz, 0 AS fuggo_sor
                       FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN utalasok u ON u.id = b.utalas_id' . RESZT_BEJOVO_JOIN . 'WHERE b.utalas_id = ?', [$id]);
    $reszek = db_all('SELECT ' . $mezok . ', r.datum AS fizetve_datum, r.id AS reszt_id, r.osszeg AS resz, (r.datum IS NULL) AS fuggo_sor
                       FROM reszteljesitesek r JOIN bejovo_szamlak b ON b.id = r.szamla_id JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN . 'WHERE r.irany = "BEJOVO" AND r.utalas_id = ?', [$id]);
    $sorok = reszt_csatol(array_merge($sorok, $reszek), 'BEJOVO');
    foreach ($sorok as &$s) {
        foreach (['id', 'k_sorszam', 'kotes_pk'] as $c) {
            $s[$c] = (int)$s[$c];
        }
        $s['utalas_id'] = $s['utalas_id'] === null ? null : (int)$s['utalas_id'];
        $s['reszt_id'] = $s['reszt_id'] === null ? null : (int)$s['reszt_id'];
        $s['fuggo_sor'] = (int)$s['fuggo_sor'] === 1;
        $s['reszosszeg'] = $s['reszt_id'] !== null;
        $s['tetel_osszeg'] = $s['reszosszeg'] ? (float)$s['resz'] : (float)$s['hatralek'];
        $s['mas_fuggo'] = round($s['fuggo'] - ($s['reszosszeg'] && $s['fuggo_sor'] ? (float)$s['resz'] : 0), 2);
        unset($s['resz']);
    }
    unset($s);
    usort($sorok, fn(array $a, array $b): int => [$a['kotes_kod'], $a['k_sorszam'], $a['reszt_id'] ?? 0] <=> [$b['kotes_kod'], $b['k_sorszam'], $b['reszt_id'] ?? 0]);
    return $sorok;
}

/**
 * Részösszeg az utaláshoz (1.27): mennyi mehet a számlából ebbe az utalásba. $szabad = a számla még szabad része, $sajat = a tétel
 * mostani összege ebben az utalásban (módosításnál; hozzáadásnál 0). Vissza: a részösszeg DECIMAL-szövegként, vagy null = a teljes
 * szabad rész megy, egészben.
 */
function reszosszeg_ertek(array $s, ?float $kert, float $szabad, float $sajat, string $pn): ?string
{
    if ($kert === null) {
        return null;
    }
    $max = round($szabad + $sajat, 2);
    $kert = round($kert, 2);
    if ($max <= 0) {
        hiba("A(z) {$s['kod']} számla maradéka nem pozitív (" . fmt_osszeg($max) . " $pn) – részösszeg nem adható belőle, csak az egész.");
    }
    if ($kert > $max + 0.005) {
        hiba('Az utaláshoz adott összeg (' . fmt_osszeg($kert) . " $pn) több, mint amennyi a(z) {$s['kod']} számlából még szabad (" . fmt_osszeg($max) . " $pn).");
    }
    if ($kert >= $max - 0.005) {
        return null;   // a teljes szabad rész: egészben
    }
    if ($kert <= 0) {
        hiba('A részösszeg pozitív szám legyen (a(z) ' . $s['kod'] . ' számlából még szabad: ' . fmt_osszeg($max) . " $pn).");
    }
    return number_format($kert, 2, '.', '');
}

/** 1.26 → 1.27: a számlán tárolt részösszeg (utalas_osszeg) függő részteljesítéssé alakul – egyszer, az első bejelentkezett kérésnél */
function reszosszeg_konverzio(): void
{
    static $kesz = false;
    if ($kesz) {
        return;
    }
    $kesz = true;
    try {
        if (!db_val('SELECT 1 FROM bejovo_szamlak WHERE utalas_osszeg IS NOT NULL LIMIT 1')) {
            return;
        }
        $db = db_tx(function (): int {
            $n = 0;
            foreach (db_all('SELECT id, statusz, utalas_id, utalas_osszeg, modositotta FROM bejovo_szamlak WHERE utalas_osszeg IS NOT NULL FOR UPDATE') as $s) {
                if ($s['statusz'] === 'UTALASHOZ_ADVA' && $s['utalas_id'] !== null) {
                    db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, letrehozta, utalas_id) VALUES ('BEJOVO', ?, ?, NULL, ?, ?)", [$s['id'], $s['utalas_osszeg'], $s['modositotta'], $s['utalas_id']]);
                    db_exec('UPDATE bejovo_szamlak SET statusz = "FIZETENDO", utalas_id = NULL, utalas_osszeg = NULL WHERE id = ?', [$s['id']]);
                    $n++;
                } else {
                    db_exec('UPDATE bejovo_szamlak SET utalas_osszeg = NULL WHERE id = ?', [$s['id']]);
                }
            }
            return $n;
        });
        naplo('RESZOSSZEG_KONVERZIO', "1.26-os részösszegek függő részteljesítéssé alakítva: $db számla");
    } catch (Throwable $e) {
        naplo('RESZOSSZEG_KONVERZIO', 'nem futott le: ' . $e->getMessage(), 'HIBA');   // pl. a séma még nincs frissítve – a következő kérésnél újra próbálja
        $kesz = false;
    }
}

/** A beszámított kimenő számlák egy utalásban (1.25): a levont összeggel és a számla aktuális hátralékával */
function utalas_beszamitasok(int $id): array
{
    // hatralek = a számla még fizetendő része (utalt részteljesítések nélkül), fuggo = a nyitott utalásokban beszámításra adott részek
    // (nyitott utalásnál ezzel a levonással együtt), marad = ami minden függő levonás után nyitott kintlévőség marad (1.27)
    $sorok = db_all('SELECT bz.id AS beszamitas_id, bz.osszeg AS levonva, bz.reszteljesites_id, s.id, s.kod, s.szamlaszam, s.osszeg, s.statusz, s.kelt, s.teljesites_datum,
                            s.fizetesi_hatarido, s.fizetve_datum, COALESCE(rk.reszt, 0) AS reszt, (s.osszeg - COALESCE(rk.reszt, 0)) AS hatralek, COALESCE(rkf.fuggo, 0) AS fuggo, rkf.fuggo_uid
                       FROM beszamitasok bz JOIN kimeno_szamlak s ON s.id = bz.kimeno_id' . RESZT_KIMENO_JOIN . 'WHERE bz.utalas_id = ? ORDER BY s.kod', [$id]);
    $bejovok = [];   // beszámítás id → a megjelölt bejövő számlák (hatralek = a számla tétele ebben az utalásban: a részösszeg, vagy egészben a maradék)
    if ($sorok) {
        foreach (db_all('SELECT bb.beszamitas_id, bb.osszeg, b.id, b.kod, b.k_sorszam, b.szamlaszam, b.statusz, b.kotes_id, k.kod AS kotes_kod,
                                (SELECT r.osszeg FROM reszteljesitesek r WHERE r.irany = "BEJOVO" AND r.szamla_id = b.id AND r.utalas_id = bz.utalas_id LIMIT 1) AS resz,
                                (b.osszeg - COALESCE(rb.reszt, 0) - COALESCE(rb.fuggo, 0)) AS szabad
                           FROM beszamitas_bejovok bb JOIN beszamitasok bz ON bz.id = bb.beszamitas_id JOIN bejovo_szamlak b ON b.id = bb.bejovo_id JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN . '
                          WHERE bz.utalas_id = ? ORDER BY k.kod, b.k_sorszam', [$id]) as $r) {
            $bejovok[(int)$r['beszamitas_id']][] = ['id' => (int)$r['id'], 'kod' => $r['kod'], 'k' => sprintf('K%04d', (int)$r['k_sorszam']), 'szamlaszam' => $r['szamlaszam'], 'statusz' => $r['statusz'],
                                                   'kotes_id' => (int)$r['kotes_id'], 'kotes_kod' => $r['kotes_kod'], 'osszeg' => (float)$r['osszeg'],
                                                   'egesz' => $r['resz'] === null, 'hatralek' => $r['resz'] === null ? (float)$r['szabad'] : (float)$r['resz']];
        }
    }
    foreach ($sorok as &$s) {
        foreach (['id', 'beszamitas_id'] as $c) {
            $s[$c] = (int)$s[$c];
        }
        $s['reszteljesites_id'] = $s['reszteljesites_id'] === null ? null : (int)$s['reszteljesites_id'];
        foreach (['levonva', 'osszeg', 'reszt', 'hatralek', 'fuggo'] as $c) {
            $s[$c] = (float)$s[$c];
        }
        $s['marad'] = round($s['hatralek'] - $s['fuggo'], 2);
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
    $csoportok = [];
    foreach (utalas_tetelek($id) as $s) {
        $kk = $s['kotes_kod'];
        if (!isset($csoportok[$kk])) {
            $csoportok[$kk] = ['kotes_pk' => $s['kotes_pk'], 'kotes_kod' => $kk, 'kotes_megnevezes' => $s['kotes_megnevezes'], 'osszeg' => 0.0, 'szamlak' => []];
        }
        // az utalásban a tétel utalt része szerepel: a hátralék (a korábbi részteljesítés már kifizetésre került), vagy a részösszeg (1.26)
        $csoportok[$kk]['osszeg'] = round($csoportok[$kk]['osszeg'] + $s['tetel_osszeg'], 2);
        $csoportok[$kk]['szamlak'][] = [
            'id' => $s['id'], 'kod' => $s['kod'], 'k' => sprintf('K%04d', $s['k_sorszam']), 'szamlaszam' => $s['szamlaszam'],
            'osszeg' => (float)$s['osszeg'], 'reszt' => $s['reszt'], 'reszt_db' => $s['reszt_db'], 'hatralek' => $s['hatralek'], 'reszteljesitesek' => $s['reszteljesitesek'],
            'tetel_osszeg' => $s['tetel_osszeg'], 'reszosszeg' => $s['reszosszeg'], 'reszt_id' => $s['reszt_id'], 'fuggo_sor' => $s['fuggo_sor'],
            'fuggo' => $s['fuggo'], 'fuggo_db' => $s['fuggo_db'], 'mas_fuggo' => $s['mas_fuggo'], 'utalas_id' => $s['utalas_id'],
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
        $s['fuggo'] = (float)$s['fuggo'];
        if ($s['fuggo'] > 0 && abs($s['hatralek']) <= 0.005) {
            continue;   // a teljes összege már utalás(ok)ban függ (1.27) – nincs mit hozzáadni
        }
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
            "SELECT b.id, b.kod, b.osszeg, b.statusz, k.penznem, k.ceg_id, (b.osszeg - COALESCE(rb.reszt, 0) - COALESCE(rb.fuggo, 0)) AS hatralek
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
        db_exec("UPDATE bejovo_szamlak SET statusz = 'UTALASHOZ_ADVA', utalas_id = ?, utalas_osszeg = NULL, modositotta = ? WHERE id IN ($in) AND statusz = 'FIZETENDO'", array_merge([$utalasId, $u['id']], $ids));
        $lista = db_all("SELECT id, kotes_id, statusz, osszeg FROM bejovo_szamlak WHERE id IN ($in)", $ids);
        audit_utalas_szamlak($lista, "Utaláshoz adva (határértékes): $uid", 'FIZETENDO', 'UTALASHOZ_ADVA', [['m' => 'Utalás', 'r' => '–', 'u' => $uid]], $u);
        return ['id' => $utalasId, 'uid' => $uid, 'osszeg' => $osszeg, 'db' => count($ids)];
    });
    naplo('UTALAS_HATARERTEK_LETREHOZ', "határértékes utalás {$e['uid']} – cég: {$ceg['nev']}, limit " . fmt_osszeg($limit) . " $penznem, {$e['db']} számla, összeg " . fmt_osszeg($e['osszeg']) . " $penznem");
    return $e;
}

/**
 * Egy számla hozzáadása az utaláshoz → "UTALÁSHOZ ADVA! UTALÁS ÖSSZEGE EDDIG: ..."
 * osszeg (nem kötelező, 1.27): részösszeg – a számla még szabad részénél kisebb összeg: függő részteljesítés lesz ebben az utalásban,
 * a számla FIZETENDŐ marad, a többi része bármikor másik utalásba mehet. Üresen (vagy a teljes szabad résszel) a számla maradéka
 * egészben kerül az utalásba (UTALÁSHOZ ADVA). Ha a számla ebben az utalásban már szerepel részösszeggel, az módosul / beleolvad.
 */
function act_utalas_szamla_hozzaad(array $be): array
{
    $u = csak_bejelentkezve();
    $utalasId = be_int($be, 'utalas_id');
    $szamlaId = be_int($be, 'szamla_id');
    $kert = isset($be['osszeg']) && trim((string)$be['osszeg']) !== '' ? (float)be_osszeg($be, 'osszeg', false, 'utaláshoz adott összeg') : null;
    $e = db_tx(function () use ($utalasId, $szamlaId, $kert, $u) {
        $ut = utalas_betolt($utalasId, true);
        if ($ut['statusz'] !== 'NYITOTT') {
            hiba("Az utalás ({$ut['uid']}) már nem nyitott, nem adható hozzá számla.");
        }
        utalas_lakat_ellenoriz($ut, 'nem adható hozzá számla');
        $s = db_row('SELECT b.*, k.penznem, k.ceg_id,' . RESZT_BEJOVO_MEZOK . 'FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN . 'WHERE b.id = ? FOR UPDATE', [$szamlaId]);
        if (!$s) {
            hiba('A számla nem található.');
        }
        if ($s['statusz'] !== 'FIZETENDO') {
            hiba("A(z) {$s['kod']} számla nem FIZETENDŐ státuszú (jelenleg: " . ny_statusz_nev($s['statusz']) . ')' . ($s['statusz'] === 'UTALASHOZ_ADVA' ? ' – a maradéka már egészben egy utalásban van.' : '.'));
        }
        if ($s['penznem'] !== $ut['penznem']) {
            hiba("PÉNZNEM-ÜTKÖZÉS! A(z) {$s['kod']} számla {$s['penznem']} pénznemű, az utalás ({$ut['uid']}) pedig {$ut['penznem']}. Egy utaláson belül a pénznemek nem keverhetők!", 'PENZNEM');
        }
        if ((int)$s['ceg_id'] !== $ut['ceg_id']) {
            hiba('A számla másik céghez tartozik, mint az utalás.');
        }
        $pn = $ut['penznem'];
        $szabad = round((float)$s['hatralek'], 2);
        $fuggo = round((float)$s['fuggo'], 2);
        // ha a számla ebben az utalásban már szerepel függő részösszeggel, az beleszámít (az összeg módosul, vagy az egészbe olvad)
        $sajat = db_row('SELECT id, osszeg FROM reszteljesitesek WHERE irany = "BEJOVO" AND szamla_id = ? AND utalas_id = ? AND datum IS NULL FOR UPDATE', [$szamlaId, $utalasId]);
        $sajatOsszeg = $sajat ? round((float)$sajat['osszeg'], 2) : 0.0;
        if (abs($szabad) <= 0.005 && !$sajat) {
            hiba("A(z) {$s['kod']} számla teljes összege már utalás(ok)ban van (" . utalas_fuggo_lista($szamlaId, $pn) . ') – nincs mit hozzáadni.');
        }
        $resz = reszosszeg_ertek($s, $kert, $szabad, $sajatOsszeg, $pn);
        $masFuggo = round($fuggo - $sajatOsszeg, 2);
        if ($resz !== null) {
            if ($sajat) {
                db_exec('UPDATE reszteljesitesek SET osszeg = ?, letrehozta = ? WHERE id = ?', [$resz, $u['id'], $sajat['id']]);
            } else {
                db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito, letrehozta, utalas_id) VALUES ('BEJOVO', ?, ?, NULL, NULL, ?, ?)", [$szamlaId, $resz, $u['id'], $utalasId]);
            }
            db_exec('UPDATE bejovo_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
            $tetel = (float)$resz;
            $marad = round($szabad + $sajatOsszeg - $tetel, 2);
            audit_ir('BEJOVO', $szamlaId, 'RESZT', "Részösszeg az utaláshoz: {$ut['uid']} – " . fmt_osszeg($tetel) . " $pn (a számlából még szabad: " . fmt_osszeg($marad) . " $pn" . ($masFuggo > 0.005 ? ', más utalásban: ' . fmt_osszeg($masFuggo) . " $pn" : '') . '); a számla FIZETENDŐ marad, az utalás teljesítésekor a rész részteljesítés lesz',
                [['m' => 'Utalás alatt', 'r' => $sajat ? fmt_osszeg($sajatOsszeg) . " $pn ({$ut['uid']})" : '–', 'u' => fmt_osszeg($tetel) . " $pn ({$ut['uid']})"]], (int)$s['kotes_id'], $u);
        } else {
            if ($sajat) {
                db_exec('DELETE FROM reszteljesitesek WHERE id = ?', [$sajat['id']]);   // a függő rész beleolvad az egészbe
            }
            db_exec('UPDATE bejovo_szamlak SET statusz = "UTALASHOZ_ADVA", utalas_id = ?, utalas_osszeg = NULL, modositotta = ? WHERE id = ?', [$utalasId, $u['id'], $szamlaId]);
            $tetel = round($szabad + $sajatOsszeg, 2);
            $marad = 0.0;
            audit_utalas_szamlak([$s], "Utaláshoz adva: {$ut['uid']}" . ($masFuggo > 0.005 ? ' – a maradék (' . fmt_osszeg($tetel) . " $pn); a számla más része más utalásban: " . fmt_osszeg($masFuggo) . " $pn" : ''), 'FIZETENDO', 'UTALASHOZ_ADVA', [['m' => 'Utalás', 'r' => '–', 'u' => $ut['uid']]], $u);
        }
        return ['uid' => $ut['uid'], 'penznem' => $pn, 'osszeg' => utalas_osszeg($utalasId), 'kod' => $s['kod'], 'szamla_osszeg' => $tetel, 'hatralek' => round($szabad + $sajatOsszeg, 2),
                'reszosszeg' => $resz !== null, 'marad' => $marad, 'mas_utalas' => $masFuggo, 'utalas_id' => $utalasId, 'utalas_db' => utalas_db($utalasId)];
    });
    naplo('UTALASHOZ_AD', "számla {$e['kod']} (" . fmt_osszeg($e['szamla_osszeg']) . " {$e['penznem']}" . ($e['reszosszeg'] ? ' részösszeg, még szabad ' . fmt_osszeg($e['marad']) : '') . ") → utalás {$e['uid']}; utalás összege eddig: " . fmt_osszeg($e['osszeg']) . " {$e['penznem']}");
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
        // a kötés FIZETENDŐ számlái egészben (a maradékukkal); amelyiknek ebben az utalásban függő része van, az beleolvad; amelyiknek
        // a teljes összege már utalás(ok)ban függ, kimarad (1.27)
        $lista = [];
        foreach (db_all('SELECT b.id, b.kotes_id, b.statusz, b.osszeg, b.kod,' . RESZT_BEJOVO_MEZOK . ',
                                (SELECT r.osszeg FROM reszteljesitesek r WHERE r.irany = "BEJOVO" AND r.szamla_id = b.id AND r.utalas_id = ? AND r.datum IS NULL LIMIT 1) AS sajat
                           FROM bejovo_szamlak b' . RESZT_BEJOVO_JOIN . 'WHERE b.kotes_id = ? AND b.statusz = "FIZETENDO" FOR UPDATE', [$utalasId, $kotesId]) as $s) {
            if (abs((float)$s['hatralek'] + (float)($s['sajat'] ?? 0)) > 0.005) {
                $lista[] = $s;
            }
        }
        if (!$lista) {
            hiba("A kötésben ({$k['kod']}) nincs utaláshoz adható FIZETENDŐ számla.");
        }
        $ids = array_map(fn(array $s): int => (int)$s['id'], $lista);
        $in = implode(',', array_fill(0, count($ids), '?'));
        db_exec("DELETE FROM reszteljesitesek WHERE irany = 'BEJOVO' AND utalas_id = ? AND datum IS NULL AND szamla_id IN ($in)", array_merge([$utalasId], $ids));
        $db = db_exec("UPDATE bejovo_szamlak SET statusz = 'UTALASHOZ_ADVA', utalas_id = ?, utalas_osszeg = NULL, modositotta = ? WHERE id IN ($in) AND statusz = 'FIZETENDO'", array_merge([$utalasId, $u['id']], $ids));
        audit_utalas_szamlak($lista, "Utaláshoz adva (a kötés minden FIZETENDŐ számlája): {$ut['uid']}", 'FIZETENDO', 'UTALASHOZ_ADVA', [['m' => 'Utalás', 'r' => '–', 'u' => $ut['uid']]], $u);
        return ['uid' => $ut['uid'], 'penznem' => $ut['penznem'], 'osszeg' => utalas_osszeg($utalasId), 'db' => $db, 'kotes_kod' => $k['kod'], 'utalas_id' => $utalasId, 'utalas_db' => utalas_db($utalasId)];
    });
    naplo('UTALASHOZ_AD_KOTES', "kötés {$e['kotes_kod']} {$e['db']} db FIZETENDŐ számlája → utalás {$e['uid']}; utalás összege eddig: " . fmt_osszeg($e['osszeg']) . " {$e['penznem']}");
    return $e;
}

/**
 * Számla (vagy részösszege) eltávolítása nyitott utalásból. Egészben benne lévő számla → vissza FIZETENDŐ; részösszeg (1.27) → a függő
 * részteljesítés törlődik, a rész újra szabad. utalas_id: melyik utalásból (ha a számla több nyitott utalásban is szerepel részenként).
 */
function act_utalas_szamla_eltavolit(array $be): array
{
    $u = csak_bejelentkezve();
    $szamlaId = be_int($be, 'szamla_id');
    $utalasId = be_int($be, 'utalas_id', false);
    $e = db_tx(function () use ($szamlaId, $utalasId, $u) {
        $s = db_row('SELECT b.*, k.penznem, ut.uid, ut.statusz AS ut_statusz, ut.id AS ut_id, ut.lezarva AS ut_lezarva FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id LEFT JOIN utalasok ut ON ut.id = b.utalas_id WHERE b.id = ? FOR UPDATE', [$szamlaId]);
        if (!$s) {
            hiba('A számla nem található.');
        }
        if ($s['statusz'] === 'UTALASHOZ_ADVA' && $s['ut_id'] && ($utalasId === null || (int)$s['ut_id'] === $utalasId)) {
            // egészben az utalásban
            if ($s['ut_statusz'] !== 'NYITOTT') {
                hiba('Az utalás már teljesült, a számla nem vehető ki belőle. (Irodavezető / admin: utalás visszanyitása.)');
            }
            utalas_lakat_ellenoriz(['lezarva' => $s['ut_lezarva'], 'uid' => $s['uid'], 'id' => $s['ut_id']], 'nem vehető ki belőle számla');
            db_exec('UPDATE bejovo_szamlak SET statusz = "FIZETENDO", utalas_id = NULL, utalas_osszeg = NULL, modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
            if (utalas_osszeg((int)$s['ut_id']) < -0.005) {
                hiba("A(z) {$s['kod']} számla nélkül az utalás ({$s['uid']}) összege negatív lenne: a beszámított kimenő számlák többet tesznek ki a maradék bejövő tételeknél. Előbb vedd ki (vagy csökkentsd) a beszámítást, aztán ezt a számlát.", 'BESZAMITAS_TOBB');
            }
            audit_utalas_szamlak([$s], "Kivéve az utalásból: {$s['uid']}", 'UTALASHOZ_ADVA', 'FIZETENDO', [['m' => 'Utalás', 'r' => $s['uid'], 'u' => '–']], $u);
            return ['uid' => $s['uid'], 'kod' => $s['kod'], 'osszeg' => utalas_osszeg((int)$s['ut_id']), 'utalas_id' => (int)$s['ut_id'], 'reszosszeg' => false];
        }
        // függő részösszeg
        $sorok = db_all('SELECT r.id, r.osszeg, ut.id AS ut_id, ut.uid, ut.lezarva AS ut_lezarva FROM reszteljesitesek r JOIN utalasok ut ON ut.id = r.utalas_id
                          WHERE r.irany = "BEJOVO" AND r.szamla_id = ? AND r.datum IS NULL' . ($utalasId !== null ? ' AND r.utalas_id = ?' : '') . ' ORDER BY ut.id FOR UPDATE', $utalasId !== null ? [$szamlaId, $utalasId] : [$szamlaId]);
        if (!$sorok) {
            hiba('A számla nincs nyitott utalásban.');
        }
        if (count($sorok) > 1) {
            hiba("A(z) {$s['kod']} számla több nyitott utalásban is szerepel részösszeggel (" . implode(', ', array_column($sorok, 'uid')) . ') – add meg, melyikből vegyem ki.', 'TOBB_UTALAS');
        }
        $r = $sorok[0];
        utalas_lakat_ellenoriz(['lezarva' => $r['ut_lezarva'], 'uid' => $r['uid'], 'id' => $r['ut_id']], 'nem vehető ki belőle a részösszeg');
        db_exec('DELETE FROM reszteljesitesek WHERE id = ?', [$r['id']]);
        db_exec('UPDATE bejovo_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
        if (utalas_osszeg((int)$r['ut_id']) < -0.005) {
            hiba("A(z) {$s['kod']} részösszege nélkül az utalás ({$r['uid']}) összege negatív lenne: a beszámított kimenő számlák többet tesznek ki a maradék bejövő tételeknél. Előbb vedd ki (vagy csökkentsd) a beszámítást.", 'BESZAMITAS_TOBB');
        }
        audit_ir('BEJOVO', $szamlaId, 'RESZT', "Részösszeg kivéve az utalásból: {$r['uid']} (" . fmt_osszeg((float)$r['osszeg']) . " {$s['penznem']}) – a rész újra szabad", [['m' => 'Utalás alatt', 'r' => fmt_osszeg((float)$r['osszeg']) . " {$s['penznem']} ({$r['uid']})", 'u' => '–']], (int)$s['kotes_id'], $u);
        return ['uid' => $r['uid'], 'kod' => $s['kod'], 'osszeg' => utalas_osszeg((int)$r['ut_id']), 'utalas_id' => (int)$r['ut_id'], 'reszosszeg' => true];
    });
    naplo('UTALASBOL_KIVESZ', "számla {$e['kod']}" . ($e['reszosszeg'] ? ' részösszege' : '') . " kivéve az utalásból ({$e['uid']}); utalás összege: " . fmt_osszeg($e['osszeg']));
    return $e;
}

/**
 * Részösszeg módosítása (1.27): mennyi legyen a számlából ebben az utalásban. Egészben benne lévő számlánál kisebb összeg → részösszeg
 * (a számla FIZETENDŐ lesz, a többi szabad); részösszegnél a teljes szabad rész → egészben. utalas_id: melyik utalásban (ha a számla
 * több nyitott utalásban is szerepel). Csak nyitott, nem lelakatolt utalásban.
 */
function act_utalas_szamla_osszeg(array $be): array
{
    $u = csak_bejelentkezve();
    $szamlaId = be_int($be, 'szamla_id');
    $utalasId = be_int($be, 'utalas_id', false);
    $kert = isset($be['osszeg']) && trim((string)$be['osszeg']) !== '' ? (float)be_osszeg($be, 'osszeg', false, 'utaláshoz adott összeg') : null;
    $e = db_tx(function () use ($szamlaId, $utalasId, $kert, $u) {
        $s = db_row('SELECT b.*, k.penznem,' . RESZT_BEJOVO_MEZOK . 'FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN . 'WHERE b.id = ? FOR UPDATE', [$szamlaId]);
        if (!$s) {
            hiba('A számla nem található.');
        }
        $pn = $s['penznem'];
        $egesz = $s['statusz'] === 'UTALASHOZ_ADVA' && $s['utalas_id'] !== null && ($utalasId === null || (int)$s['utalas_id'] === $utalasId);
        $sajat = null;
        if ($egesz) {
            $ut = utalas_betolt((int)$s['utalas_id'], true);
            $sajatOsszeg = round((float)$s['hatralek'], 2);   // egészben: a tétel a számla maradéka
            $szabad = 0.0;
        } else {
            $sorok = db_all('SELECT r.id, r.osszeg, r.utalas_id FROM reszteljesitesek r WHERE r.irany = "BEJOVO" AND r.szamla_id = ? AND r.datum IS NULL' . ($utalasId !== null ? ' AND r.utalas_id = ?' : '') . ' ORDER BY r.utalas_id FOR UPDATE', $utalasId !== null ? [$szamlaId, $utalasId] : [$szamlaId]);
            if (!$sorok) {
                hiba('A számla nincs nyitott utalásban – részösszeget csak az utalásban lévő számlánál lehet megadni.');
            }
            if (count($sorok) > 1) {
                hiba("A(z) {$s['kod']} számla több nyitott utalásban is szerepel részösszeggel – add meg, melyikben módosítom.", 'TOBB_UTALAS');
            }
            $sajat = $sorok[0];
            $ut = utalas_betolt((int)$sajat['utalas_id'], true);
            $sajatOsszeg = round((float)$sajat['osszeg'], 2);
            $szabad = round((float)$s['hatralek'], 2);
        }
        if ($ut['statusz'] !== 'NYITOTT') {
            hiba("Az utalás ({$ut['uid']}) már teljesült, a részösszeg nem módosítható. (Irodavezető / admin: utalás visszanyitása.)");
        }
        utalas_lakat_ellenoriz($ut, 'a részösszeg nem módosítható');
        // ha a számla maradéka egészben egy MÁSIK utalásban van, annak legalább 1 fillér maradjon: ide legfeljebb a szabad rész − 0,01 jöhet át
        $masikEgesz = !$egesz && $s['statusz'] === 'UTALASHOZ_ADVA' && $s['utalas_id'] !== null;
        if ($masikEgesz) {
            $szabad = max(0.0, round($szabad - 0.01, 2));
        }
        $resz = reszosszeg_ertek($s, $kert, $szabad, $sajatOsszeg, $pn);
        if ($masikEgesz && $resz === null) {
            $masikUid = db_val('SELECT uid FROM utalasok WHERE id = ?', [$s['utalas_id']]);
            hiba("A(z) {$s['kod']} számla maradéka egészben a(z) $masikUid utalásban van – ebben az utalásban legfeljebb " . fmt_osszeg(round($szabad + $sajatOsszeg, 2)) . " $pn lehet.");
        }
        $uj = $resz === null ? null : (float)$resz;
        $valtozott = $egesz ? $uj !== null : ($uj === null || abs($uj - $sajatOsszeg) > 0.005);
        $tetel = $uj ?? round($szabad + $sajatOsszeg, 2);
        if ($valtozott) {
            if ($egesz) {
                // egészben → részösszeg: a számla FIZETENDŐ lesz, a maradék többi része szabad
                db_exec('UPDATE bejovo_szamlak SET statusz = "FIZETENDO", utalas_id = NULL, utalas_osszeg = NULL, modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
                db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito, letrehozta, utalas_id) VALUES ('BEJOVO', ?, ?, NULL, NULL, ?, ?)", [$szamlaId, $resz, $u['id'], $ut['id']]);
                audit_ir('BEJOVO', $szamlaId, 'STATUSZ', "Részösszeg az utaláshoz módosítva: {$ut['uid']} – az egész helyett " . fmt_osszeg($uj) . " $pn; a számla FIZETENDŐ, még szabad: " . fmt_osszeg(round($sajatOsszeg - $uj, 2)) . " $pn",
                    [['m' => 'Státusz', 'r' => ny_statusz_nev('UTALASHOZ_ADVA'), 'u' => ny_statusz_nev('FIZETENDO')], ['m' => 'Utalás alatt', 'r' => 'egészben (' . fmt_osszeg($sajatOsszeg) . " $pn, {$ut['uid']})", 'u' => fmt_osszeg($uj) . " $pn ({$ut['uid']})"]], (int)$s['kotes_id'], $u);
            } elseif ($uj === null) {
                // részösszeg → egészben: a teljes szabad rész is ebbe az utalásba
                db_exec('DELETE FROM reszteljesitesek WHERE id = ?', [$sajat['id']]);
                db_exec('UPDATE bejovo_szamlak SET statusz = "UTALASHOZ_ADVA", utalas_id = ?, utalas_osszeg = NULL, modositotta = ? WHERE id = ?', [$ut['id'], $u['id'], $szamlaId]);
                audit_ir('BEJOVO', $szamlaId, 'STATUSZ', "Részösszeg az utaláshoz módosítva: {$ut['uid']} – a teljes maradék (" . fmt_osszeg($tetel) . " $pn) egészben",
                    [['m' => 'Státusz', 'r' => ny_statusz_nev('FIZETENDO'), 'u' => ny_statusz_nev('UTALASHOZ_ADVA')], ['m' => 'Utalás alatt', 'r' => fmt_osszeg($sajatOsszeg) . " $pn ({$ut['uid']})", 'u' => 'egészben (' . fmt_osszeg($tetel) . " $pn, {$ut['uid']})"]], (int)$s['kotes_id'], $u);
            } else {
                db_exec('UPDATE reszteljesitesek SET osszeg = ?, letrehozta = ? WHERE id = ?', [$resz, $u['id'], $sajat['id']]);
                db_exec('UPDATE bejovo_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
                audit_ir('BEJOVO', $szamlaId, 'RESZT', "Részösszeg az utaláshoz módosítva: {$ut['uid']} – " . fmt_osszeg($sajatOsszeg) . ' → ' . fmt_osszeg($uj) . " $pn; még szabad: " . fmt_osszeg(round($szabad + $sajatOsszeg - $uj, 2)) . " $pn",
                    [['m' => 'Utalás alatt', 'r' => fmt_osszeg($sajatOsszeg) . " $pn ({$ut['uid']})", 'u' => fmt_osszeg($uj) . " $pn ({$ut['uid']})"]], (int)$s['kotes_id'], $u);
            }
            // a beszámítás (1.25) erre a számlára megjelölt része legfeljebb a tétel lehet
            db_exec('UPDATE beszamitas_bejovok bb JOIN beszamitasok bz ON bz.id = bb.beszamitas_id SET bb.osszeg = LEAST(bb.osszeg, ?) WHERE bb.bejovo_id = ? AND bz.utalas_id = ?', [number_format($tetel, 2, '.', ''), $szamlaId, $ut['id']]);
            if (utalas_osszeg($ut['id']) < -0.005) {
                hiba("Ezzel az összeggel az utalás ({$ut['uid']}) összege negatív lenne: a beszámított kimenő számlák többet tesznek ki a bejövő tételeknél. Írj nagyobb összeget, vagy vedd ki (csökkentsd) a beszámítást.", 'BESZAMITAS_TOBB');
            }
        }
        return ['uid' => $ut['uid'], 'penznem' => $pn, 'osszeg' => utalas_osszeg($ut['id']), 'kod' => $s['kod'], 'szamla_osszeg' => $tetel, 'hatralek' => round($szabad + $sajatOsszeg, 2),
                'reszosszeg' => $uj !== null, 'marad' => $uj !== null ? round($szabad + $sajatOsszeg - $uj, 2) : 0.0, 'mas_utalas' => round((float)$s['fuggo'] - ($sajat ? $sajatOsszeg : 0), 2),
                'utalas_id' => $ut['id'], 'valtozott' => $valtozott, 'utalas_db' => utalas_db($ut['id'])];
    });
    if ($e['valtozott']) {
        naplo('UTALAS_RESZOSSZEG', "számla {$e['kod']}: " . ($e['reszosszeg'] ? 'részösszeg ' . fmt_osszeg($e['szamla_osszeg']) . " {$e['penznem']} (még szabad " . fmt_osszeg($e['marad']) . ')' : 'egészben (' . fmt_osszeg($e['szamla_osszeg']) . " {$e['penznem']})") . " az utalásban {$e['uid']}; utalás összege: " . fmt_osszeg($e['osszeg']) . " {$e['penznem']}");
    }
    return $e;
}

/**
 * Utalás teljesítése (elutalva): az egészben benne lévő számlák → FIZETVE (negatív összeg → BESZÁMÍTVA), a részösszegek (1.27) →
 * dátumot kapnak (utalt részteljesítés); amelyik számlának ezzel minden része utalva van, FIZETVE lesz. Ha egy egészben benne lévő
 * számlának más nyitott utalásban is van függő része, a maradéka itt részteljesítésként záródik, és a számla FIZETENDŐ marad, amíg a
 * másik utalás is teljesül. A beszámított kimenő számlák (1.25) ugyanígy: teljes levonás → FIZETVE, rész → részteljesítés.
 */
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
        $pn = $ut['penznem'];
        $hiv2 = 'Utalás: ' . $ut['uid'] . ($hiv ? " ($hiv)" : '');
        $bzLista = utalas_beszamitasok($utalasId);   // a státuszváltás előtt: a megjelölt bejövő tételek és a kimenő számlák függő részei
        // 1) a részösszegek (1.27): a függő részteljesítés dátumot kap = utalva
        $reszek = db_all('SELECT r.id AS reszt_id, r.osszeg AS resz, b.id, b.kod, b.kotes_id FROM reszteljesitesek r JOIN bejovo_szamlak b ON b.id = r.szamla_id
                           WHERE r.irany = "BEJOVO" AND r.utalas_id = ? AND r.datum IS NULL FOR UPDATE', [$utalasId]);
        $reszIds = [];
        foreach ($reszek as $s) {
            db_exec('UPDATE reszteljesitesek SET datum = ?, banki_azonosito = ? WHERE id = ?', [$datum, $hiv2, $s['reszt_id']]);
            db_exec('UPDATE bejovo_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $s['id']]);
            $reszIds[] = (int)$s['id'];
            audit_ir('BEJOVO', (int)$s['id'], 'RESZT', "Részösszeg utalva – az utalás teljesítve: {$ut['uid']}: " . fmt_osszeg((float)$s['resz']) . " $pn (" . audit_ertek('kelt', $datum) . ') – részteljesítés',
                [['m' => 'Utalás alatt', 'r' => fmt_osszeg((float)$s['resz']) . " $pn ({$ut['uid']})", 'u' => '–'], ['m' => 'Részteljesítés', 'r' => '–', 'u' => fmt_osszeg((float)$s['resz']) . " $pn (" . audit_ertek('kelt', $datum) . ')']], (int)$s['kotes_id'], $u);
        }
        // 2) az egészben benne lévő számlák: FIZETVE – kivéve, ha más nyitott utalásban is van még függő része: akkor a maradéka itt
        //    részteljesítésként záródik, a számla FIZETENDŐ marad
        $egeszek = db_all('SELECT b.id, b.kod, b.kotes_id, b.statusz, b.osszeg,' . RESZT_BEJOVO_MEZOK . 'FROM bejovo_szamlak b' . RESZT_BEJOVO_JOIN . 'WHERE b.utalas_id = ? AND b.statusz = "UTALASHOZ_ADVA" FOR UPDATE', [$utalasId]);
        $lista = [];
        $atalakitott = 0;
        foreach ($egeszek as $s) {
            if ((float)$s['fuggo'] > 0.005) {
                $tetel = round((float)$s['hatralek'], 2);
                db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito, letrehozta, utalas_id) VALUES ('BEJOVO', ?, ?, ?, ?, ?, ?)", [$s['id'], number_format($tetel, 2, '.', ''), $datum, $hiv2, $u['id'], $utalasId]);
                db_exec('UPDATE bejovo_szamlak SET statusz = "FIZETENDO", utalas_id = NULL, utalas_osszeg = NULL, modositotta = ? WHERE id = ?', [$u['id'], $s['id']]);
                $reszIds[] = (int)$s['id'];
                $atalakitott++;
                audit_ir('BEJOVO', (int)$s['id'], 'RESZT', "A maradék utalva – az utalás teljesítve: {$ut['uid']}: " . fmt_osszeg($tetel) . " $pn (" . audit_ertek('kelt', $datum) . ') részteljesítésként; a számla más része még utalás alatt (' . fmt_osszeg((float)$s['fuggo']) . " $pn), ezért FIZETENDŐ marad",
                    [['m' => 'Státusz', 'r' => ny_statusz_nev('UTALASHOZ_ADVA'), 'u' => ny_statusz_nev('FIZETENDO')], ['m' => 'Utalás', 'r' => $ut['uid'], 'u' => '–'], ['m' => 'Részteljesítés', 'r' => '–', 'u' => fmt_osszeg($tetel) . " $pn"]], (int)$s['kotes_id'], $u);
            } else {
                $lista[] = $s;
            }
        }
        db_exec('UPDATE bejovo_szamlak SET statusz = IF(osszeg < 0, "BESZAMITVA", "FIZETVE"), fizetve_datum = ?, utalas_osszeg = NULL, modositotta = ? WHERE utalas_id = ? AND statusz = "UTALASHOZ_ADVA"', [$datum, $u['id'], $utalasId]);
        db_exec('UPDATE utalasok SET statusz = "UTALVA", utalva_datum = ?, banki_hivatkozas = ?, utalva_at = NOW(), utalta = ? WHERE id = ?', [$datum, $hiv, $u['id'], $utalasId]);
        audit_utalas_szamlak($lista, "Utalás teljesítve: {$ut['uid']}" . ($hiv ? " (hiv.: $hiv)" : ''), 'UTALASHOZ_ADVA', 'AUTO', [['m' => 'Utalás / fizetés dátuma', 'r' => '–', 'u' => audit_ertek('fizetve_datum', $datum)]], $u);
        // 3) amelyik számlának ezzel minden része utalva lett (nincs szabad és függő része), FIZETVE
        $lezart = 0;
        if ($reszIds) {
            $in = implode(',', array_fill(0, count($reszIds), '?'));
            foreach (db_all('SELECT b.id, b.kod, b.kotes_id, b.osszeg,' . RESZT_BEJOVO_MEZOK . "FROM bejovo_szamlak b" . RESZT_BEJOVO_JOIN . "WHERE b.id IN ($in) AND b.statusz = 'FIZETENDO'", $reszIds) as $s) {
                if ((float)$s['fuggo'] <= 0.005 && abs((float)$s['hatralek']) <= 0.005) {
                    db_exec('UPDATE bejovo_szamlak SET statusz = "FIZETVE", fizetve_datum = ?, modositotta = ? WHERE id = ?', [$datum, $u['id'], $s['id']]);
                    $lezart++;
                    audit_ir('BEJOVO', (int)$s['id'], 'STATUSZ', "Minden része utalva (részteljesítésekkel) – az utolsó utalás: {$ut['uid']}", [['m' => 'Státusz', 'r' => ny_statusz_nev('FIZETENDO'), 'u' => ny_statusz_nev('FIZETVE')], ['m' => 'Utalás / fizetés dátuma', 'r' => '–', 'u' => audit_ertek('fizetve_datum', $datum)]], (int)$s['kotes_id'], $u);
                }
            }
        }
        // 4) a megjelölt bejövő tételek (1.25): amelyik egészben benne lévő számla teljes tételét a beszámítás(ok) fedezik, BESZÁMÍTVA –
        //    nem utalással, hanem a kimenő számlával rendezve; részösszegnél csak a napló jegyzi
        $fedezet = [];
        foreach ($bzLista as $b) {
            foreach ($b['bejovok'] as $bb) {
                if ($bb['statusz'] !== 'UTALASHOZ_ADVA' && $bb['statusz'] !== 'FIZETENDO') {
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
            $teljes = $f['bejovo']['egesz'] && !in_array((int)$bid, $reszIds, true) && $f['osszeg'] >= $f['bejovo']['hatralek'] - 0.005;
            if ($teljes) {
                db_exec('UPDATE bejovo_szamlak SET statusz = "BESZAMITVA" WHERE id = ? AND utalas_id = ? AND statusz = "FIZETVE"', [$bid, $utalasId]);
            }
            audit_ir('BEJOVO', (int)$bid, 'STATUSZ', ($teljes ? 'Beszámítással rendezve (BESZÁMÍTVA): a teljes összeget a kimenő számla fedezi: ' : 'Részben beszámítással rendezve: ' . fmt_osszeg($f['osszeg']) . " $pn a kimenő számlából, a többi utalással: ")
                . implode(', ', $f['kimeno']) . " – {$ut['uid']}", $teljes ? [['m' => 'Státusz', 'r' => ny_statusz_nev('FIZETVE'), 'u' => ny_statusz_nev('BESZAMITVA')]] : [], (int)$f['bejovo']['kotes_id'], $u);
        }
        // 5) a beszámított kimenő számlák (1.25/1.27): a teljes hátralék levonása (és nincs más függő levonás) → FIZETVE az utalás napjával;
        //    különben részteljesítés – és ha ezzel a számla minden része rendezve, FIZETVE
        $bz = 0;
        foreach ($bzLista as $b) {
            db_row('SELECT id FROM kimeno_szamlak WHERE id = ? FOR UPDATE', [$b['id']]);
            $bz++;
            if ($b['statusz'] !== 'NYITOTT') {
                continue;   // közben más úton rendeződött – a beszámítás az utalásban marad, a számlához nem nyúlunk
            }
            $hiv3 = 'Beszámítás: ' . $ut['uid'];
            $masFuggo = round($b['fuggo'] - $b['levonva'], 2);   // más nyitott utalásban beszámításra adott része
            if ($b['levonva'] >= $b['hatralek'] - 0.005 && $masFuggo <= 0.005) {
                db_exec('UPDATE kimeno_szamlak SET statusz = "FIZETVE", fizetve_datum = ?, modositotta = ? WHERE id = ?', [$datum, $u['id'], $b['id']]);
                audit_ir('KIMENO', $b['id'], 'STATUSZ', "Fizetve beszámítással – az utalás teljesítve: {$ut['uid']} (levonva " . fmt_osszeg($b['levonva']) . " $pn)",
                    [['m' => 'Státusz', 'r' => 'NYITOTT', 'u' => 'FIZETVE'], ['m' => 'Fizetés dátuma', 'r' => '–', 'u' => audit_ertek('fizetve_datum', $datum)], ['m' => 'Banki azonosító', 'r' => '–', 'u' => $hiv3]], null, $u);
            } else {
                db_exec("INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito, letrehozta, utalas_id) VALUES ('KIMENO', ?, ?, ?, ?, ?, ?)",
                    [$b['id'], number_format($b['levonva'], 2, '.', ''), $datum, $hiv3, $u['id'], $utalasId]);
                $rid = (int)db()->lastInsertId();
                db_exec('UPDATE beszamitasok SET reszteljesites_id = ? WHERE id = ?', [$rid, $b['beszamitas_id']]);
                db_exec('UPDATE kimeno_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $b['id']]);
                $marad = round($b['hatralek'] - $b['levonva'], 2);
                audit_ir('KIMENO', $b['id'], 'RESZT', "Részbeszámítás – az utalás teljesítve: {$ut['uid']}: " . fmt_osszeg($b['levonva']) . " $pn levonva (" . audit_ertek('kelt', $datum) . ')' . ($masFuggo > 0.005 ? ' – más utalásban beszámításra adva: ' . fmt_osszeg($masFuggo) . " $pn" : ''),
                    [['m' => 'Hátralék', 'r' => fmt_osszeg($b['hatralek']) . " $pn", 'u' => fmt_osszeg($marad) . " $pn"]], null, $u);
                if ($marad <= 0.005) {
                    db_exec('UPDATE kimeno_szamlak SET statusz = "FIZETVE", fizetve_datum = ?, modositotta = ? WHERE id = ?', [$datum, $u['id'], $b['id']]);
                    audit_ir('KIMENO', $b['id'], 'STATUSZ', "Minden része beszámítva – az utolsó utalás: {$ut['uid']}", [['m' => 'Státusz', 'r' => 'NYITOTT', 'u' => 'FIZETVE'], ['m' => 'Fizetés dátuma', 'r' => '–', 'u' => audit_ertek('fizetve_datum', $datum)]], null, $u);
                }
            }
        }
        $ut['beszamitas_db'] = $bz;
        $ut['reszosszeg_db'] = count($reszek) + $atalakitott;
        $ut['lezart_db'] = $lezart;
        return $ut;
    });
    naplo('UTALAS_TELJESITVE', "utalás {$e['uid']} teljesítve ($datum" . ($hiv ? ", hiv.: $hiv" : '') . "), {$e['db']} tétel" . ($e['reszosszeg_db'] ? " (ebből {$e['reszosszeg_db']} részösszeg → részteljesítés" . ($e['lezart_db'] ? ", {$e['lezart_db']} számla ezzel teljesen rendezve" : '') . ')' : '') . ($e['beszamitas_db'] ? " + {$e['beszamitas_db']} beszámított kimenő" : '') . ', összeg ' . fmt_osszeg($e['osszeg']) . " {$e['penznem']} – számlák FIZETVE/BESZÁMÍTVA");
    return ['uid' => $e['uid'], 'osszeg' => $e['osszeg'], 'db' => $e['db'], 'beszamitas_db' => $e['beszamitas_db'], 'reszosszeg_db' => $e['reszosszeg_db'], 'lezart_db' => $e['lezart_db']];
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
        // hatralek = a számla még szabad része: összeg − részteljesítések − más nyitott utalásokban beszámításra adott részek (1.27)
        $s = db_row('SELECT s.*,' . RESZT_KIMENO_MEZOK . 'FROM kimeno_szamlak s' . RESZT_KIMENO_JOIN . 'WHERE s.id = ? FOR UPDATE', [$szamlaId]);
        if (!$s) {
            hiba('A kimenő számla nem található.');
        }
        if ($s['statusz'] !== 'NYITOTT') {
            hiba("A(z) {$s['kod']} kimenő számla már FIZETVE státuszú – csak nyitott számla számítható be.");
        }
        if (db_val('SELECT 1 FROM beszamitasok WHERE utalas_id = ? AND kimeno_id = ?', [$utalasId, $szamlaId])) {
            hiba("A(z) {$s['kod']} kimenő számla már beszámításra van adva ebben az utalásban ({$ut['uid']}) – a levonás az utalás oldalán vehető ki, és új összeggel adható hozzá újra.");
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
            // a számla tétele ebben az utalásban: a függő részösszeg, vagy egészben a maradék (1.27) – ennyit rendezhet a beszámítás
            $bejovok = db_all("SELECT b.id, b.kod, b.k_sorszam, b.szamlaszam, b.kotes_id,
                                      COALESCE((SELECT r.osszeg FROM reszteljesitesek r WHERE r.irany = \"BEJOVO\" AND r.szamla_id = b.id AND r.utalas_id = ? AND r.datum IS NULL LIMIT 1), b.osszeg - COALESCE(rb.reszt, 0) - COALESCE(rb.fuggo, 0)) AS hatralek
                                 FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id" . RESZT_BEJOVO_JOIN
                . "WHERE b.id IN ($in) AND ((b.utalas_id = ? AND b.statusz = \"UTALASHOZ_ADVA\") OR EXISTS (SELECT 1 FROM reszteljesitesek r2 WHERE r2.irany = \"BEJOVO\" AND r2.szamla_id = b.id AND r2.utalas_id = ? AND r2.datum IS NULL)) ORDER BY k.kod, b.k_sorszam",
                array_merge([$utalasId], $bejovoIds, [$utalasId, $utalasId]));
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

/** Beszámítás kivétele nyitott utalásból – a kimenő számla NYITOTT marad, csak a levonás szűnik meg */
function act_utalas_kimeno_eltavolit(array $be): array
{
    $u = csak_bejelentkezve();
    $szamlaId = be_int($be, 'szamla_id');
    $utalasId = be_int($be, 'utalas_id', false);
    $uid = be_szoveg($be, 'uid', 24, false, 'UID');
    $uid = $uid !== null && trim($uid) !== '' ? trim($uid) : null;
    $e = db_tx(function () use ($szamlaId, $utalasId, $uid, $u) {
        // melyik utalásból: utalas_id vagy uid – ha a számla több nyitott utalásban is beszámításra van adva (1.27), meg kell adni
        $sorok = db_all('SELECT s.id, s.kod, s.penznem, bz.id AS bz_id, bz.osszeg AS levonva, ut.uid, ut.statusz AS ut_statusz, ut.id AS ut_id, ut.lezarva AS ut_lezarva
                           FROM kimeno_szamlak s JOIN beszamitasok bz ON bz.kimeno_id = s.id JOIN utalasok ut ON ut.id = bz.utalas_id AND ut.statusz = "NYITOTT"
                          WHERE s.id = ?' . ($utalasId !== null ? ' AND ut.id = ?' : '') . ($uid !== null ? ' AND ut.uid = ?' : '') . ' ORDER BY ut.id FOR UPDATE',
            array_merge([$szamlaId], $utalasId !== null ? [$utalasId] : [], $uid !== null ? [$uid] : []));
        if (!$sorok) {
            hiba('A kimenő számla nincs nyitott utalásban beszámításra adva. (Ha az utalás már teljesült: irodavezető / admin az utalás visszanyitásával vonhatja vissza.)');
        }
        if (count($sorok) > 1) {
            hiba('A kimenő számla több nyitott utalásban is beszámításra van adva (' . implode(', ', array_column($sorok, 'uid')) . ') – add meg, melyikből vegyem ki.', 'TOBB_UTALAS');
        }
        $s = $sorok[0];
        utalas_lakat_ellenoriz(['lezarva' => $s['ut_lezarva'], 'uid' => $s['uid'], 'id' => $s['ut_id']], 'nem vehető ki belőle a beszámítás');
        db_exec('DELETE FROM beszamitasok WHERE id = ?', [$s['bz_id']]);
        db_exec('UPDATE kimeno_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
        audit_ir('KIMENO', $szamlaId, 'STATUSZ', "Beszámítás kivéve az utalásból: {$s['uid']} (" . fmt_osszeg((float)$s['levonva']) . " {$s['penznem']}) – a számla NYITOTT marad", [['m' => 'Utalás', 'r' => $s['uid'], 'u' => '–']], null, $u);
        return ['uid' => $s['uid'], 'kod' => $s['kod'], 'osszeg' => utalas_osszeg((int)$s['ut_id']), 'utalas_id' => (int)$s['ut_id'], 'penznem' => $s['penznem']];
    });
    naplo('BESZAMITAS_KIVESZ', "kimenő számla {$e['kod']} beszámítása kivéve az utalásból ({$e['uid']}); utalandó összeg: " . fmt_osszeg($e['osszeg']) . " {$e['penznem']}");
    return $e;
}

/** Teljesített utalás visszanyitása (Irodavezető / Admin): a tételek vissza UTALÁSHOZ ADVA / függő státuszba */
function act_utalas_visszanyit(array $be): array
{
    $u = csak_jog('torol');
    $utalasId = be_int($be, 'utalas_id');
    $e = db_tx(function () use ($utalasId, $u) {
        $ut = utalas_betolt($utalasId, true);
        if ($ut['statusz'] !== 'UTALVA') {
            hiba('Az utalás nem teljesített státuszú.');
        }
        $pn = $ut['penznem'];
        $lista = db_all('SELECT id, kotes_id, statusz, osszeg FROM bejovo_szamlak WHERE utalas_id = ?', [$utalasId]);
        db_exec('UPDATE bejovo_szamlak SET statusz = "UTALASHOZ_ADVA", fizetve_datum = NULL, modositotta = ? WHERE utalas_id = ?', [$u['id'], $utalasId]);
        // a részösszegek (1.27): az utalt részteljesítés újra függő (dátum nélkül); amelyik számla ezekkel lett FIZETVE, újra FIZETENDŐ
        foreach (db_all('SELECT r.id AS reszt_id, r.osszeg, r.datum, b.id, b.kod, b.kotes_id, b.statusz, b.utalas_id AS b_utalas FROM reszteljesitesek r JOIN bejovo_szamlak b ON b.id = r.szamla_id
                          WHERE r.irany = "BEJOVO" AND r.utalas_id = ? FOR UPDATE', [$utalasId]) as $r) {
            db_exec('UPDATE reszteljesitesek SET datum = NULL, banki_azonosito = NULL WHERE id = ?', [$r['reszt_id']]);
            $v = [['m' => 'Részteljesítés', 'r' => fmt_osszeg((float)$r['osszeg']) . " $pn (" . audit_ertek('kelt', $r['datum']) . ')', 'u' => '–'], ['m' => 'Utalás alatt', 'r' => '–', 'u' => fmt_osszeg((float)$r['osszeg']) . " $pn ({$ut['uid']})"]];
            if (in_array($r['statusz'], ['FIZETVE', 'BESZAMITVA'], true) && $r['b_utalas'] === null) {
                db_exec('UPDATE bejovo_szamlak SET statusz = "FIZETENDO", fizetve_datum = NULL, modositotta = ? WHERE id = ?', [$u['id'], $r['id']]);
                $v[] = ['m' => 'Státusz', 'r' => ny_statusz_nev($r['statusz']), 'u' => ny_statusz_nev('FIZETENDO')];
            } else {
                db_exec('UPDATE bejovo_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $r['id']]);
            }
            audit_ir('BEJOVO', (int)$r['id'], 'RESZT', "Utalás visszanyitva: {$ut['uid']} – a részösszeg (" . fmt_osszeg((float)$r['osszeg']) . " $pn) újra függő, az utalásban van", $v, (int)$r['kotes_id'], $u);
        }
        // a beszámított kimenő számlák (1.25/1.27): a részbeszámítás részteljesítése törlődik; a beszámítással (banki azonosító nélkül)
        // fizetett számla, ha ezután van még rendezetlen része, vissza NYITOTT-ba
        foreach (utalas_beszamitasok($utalasId) as $b) {
            if ($b['reszteljesites_id'] !== null) {
                $rt = db_row('SELECT osszeg FROM reszteljesitesek WHERE id = ?', [$b['reszteljesites_id']]);
                db_exec('DELETE FROM reszteljesitesek WHERE id = ?', [$b['reszteljesites_id']]);
                db_exec('UPDATE beszamitasok SET reszteljesites_id = NULL WHERE id = ?', [$b['beszamitas_id']]);
                db_exec('UPDATE kimeno_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], $b['id']]);
                audit_ir('KIMENO', $b['id'], 'RESZT', "Utalás visszanyitva: {$ut['uid']} – a részbeszámítás (" . fmt_osszeg((float)($rt['osszeg'] ?? $b['levonva'])) . " $pn) törölve, a számla újra beszámításra adva", [], null, $u);
            }
            $k = db_row('SELECT s.statusz, s.banki_kivonat_id, s.fizetve_datum, s.osszeg, COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r WHERE r.irany = "KIMENO" AND r.szamla_id = s.id AND r.datum IS NOT NULL), 0) AS reszt FROM kimeno_szamlak s WHERE s.id = ?', [$b['id']]);
            if ($k && $k['statusz'] === 'FIZETVE' && $k['banki_kivonat_id'] === null && (float)$k['osszeg'] - (float)$k['reszt'] > 0.005) {
                db_exec('UPDATE kimeno_szamlak SET statusz = "NYITOTT", fizetve_datum = NULL, modositotta = ? WHERE id = ?', [$u['id'], $b['id']]);
                audit_ir('KIMENO', $b['id'], 'STATUSZ', "Utalás visszanyitva: {$ut['uid']} – a beszámítással fizetett számla újra NYITOTT (beszámításra adva)",
                    [['m' => 'Státusz', 'r' => 'FIZETVE', 'u' => 'NYITOTT'], ['m' => 'Fizetés dátuma', 'r' => audit_ertek('fizetve_datum', $k['fizetve_datum']), 'u' => '–']], null, $u);
            }
        }
        db_exec('UPDATE utalasok SET statusz = "NYITOTT", utalva_datum = NULL, utalva_at = NULL, utalta = NULL, lezarva = 0, lezarta = NULL, lezarva_at = NULL WHERE id = ?', [$utalasId]);
        audit_utalas_szamlak($lista, "Utalás visszanyitva: {$ut['uid']}", 'AUTO', 'UTALASHOZ_ADVA', [['m' => 'Utalás / fizetés dátuma', 'r' => audit_ertek('fizetve_datum', $ut['utalva_datum']), 'u' => '–']], $u);
        return $ut;
    });
    naplo('UTALAS_VISSZANYIT', "utalás {$e['uid']} visszanyitva (" . szerep_nev($u['szerep']) . ") – a tételek vissza UTALÁSHOZ ADVA / függő státuszba");
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
        db_exec('UPDATE bejovo_szamlak SET statusz = "FIZETENDO", utalas_id = NULL, utalas_osszeg = NULL, modositotta = ? WHERE utalas_id = ?', [$u['id'], $utalasId]);
        // a részösszegek (1.27): a függő részek törlődnek, a számlákon újra szabadok
        foreach (db_all('SELECT r.id AS reszt_id, r.osszeg, b.id, b.kod, b.kotes_id FROM reszteljesitesek r JOIN bejovo_szamlak b ON b.id = r.szamla_id WHERE r.irany = "BEJOVO" AND r.utalas_id = ? AND r.datum IS NULL', [$utalasId]) as $r) {
            db_exec('DELETE FROM reszteljesitesek WHERE id = ?', [$r['reszt_id']]);
            audit_ir('BEJOVO', (int)$r['id'], 'RESZT', "Az utalás törölve ({$ut['uid']}) – a részösszeg (" . fmt_osszeg((float)$r['osszeg']) . " {$ut['penznem']}) megszűnt, a rész újra szabad", [['m' => 'Utalás alatt', 'r' => fmt_osszeg((float)$r['osszeg']) . " {$ut['penznem']} ({$ut['uid']})", 'u' => '–']], (int)$r['kotes_id'], $u);
        }
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
