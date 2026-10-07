<?php
declare(strict_types=1);

require_once __DIR__ . '/api_reszteljesites.php';
require_once __DIR__ . '/audit.php';

// ---------------------------------------------------------------------------
//  KÖTÉSEK
// ---------------------------------------------------------------------------

function kotes_sor_feldolgoz(array $k): array
{
    $k['id'] = (int)$k['id'];
    $k['ceg_id'] = (int)$k['ceg_id'];
    $k['ev'] = (int)$k['ev'];
    $k['db'] = (int)($k['db'] ?? 0);
    $k['nyitott_db'] = (int)($k['nyitott_db'] ?? 0);
    $k['fizetendo_db'] = (int)($k['fizetendo_db'] ?? 0);
    foreach (['fizetendo', 'utalas_alatt', 'fizetett', 'teljes', 'reszt'] as $m) {
        $k[$m] = (float)($k[$m] ?? 0);
    }
    $k['statusz'] = ($k['db'] > 0 && $k['nyitott_db'] === 0) ? 'FIZETETT' : 'NYITOTT';
    $k['archiv'] = (int)($k['archiv'] ?? 0) === 1;
    $k['regi_kod'] = $k['regi_kod'] ?? null;
    unset($k['utolso_k']);
    return $k;
}

/*
 * Kötés-összesítők – a részteljesítésekkel:
 *   fizetendo    = FIZETENDŐ számlák hátraléka (összeg − részteljesítés)
 *   utalas_alatt = UTALÁSHOZ ADVA számlák hátraléka
 *   fizetett     = FIZETVE/BESZÁMÍTVA számlák teljes összege + a nyitott számlák részteljesítései
 *   teljes       = minden számla eredeti összege (fizetendo + utalas_alatt + fizetett)
 */
const KOTES_SQL = 'SELECT k.*, c.nev AS ceg_nev, fl.felhasznalonev AS letrehozta_nev, fm.felhasznalonev AS modositotta_nev,
        COUNT(b.id) AS db,
        SUM(CASE WHEN b.statusz IN ("FIZETENDO","UTALASHOZ_ADVA") THEN 1 ELSE 0 END) AS nyitott_db,
        SUM(CASE WHEN b.statusz = "FIZETENDO" THEN 1 ELSE 0 END) AS fizetendo_db,
        COALESCE(SUM(CASE WHEN b.statusz = "FIZETENDO" THEN b.osszeg - COALESCE(rb.reszt, 0) END), 0) AS fizetendo,
        COALESCE(SUM(CASE WHEN b.statusz = "UTALASHOZ_ADVA" THEN b.osszeg - COALESCE(rb.reszt, 0) END), 0) AS utalas_alatt,
        COALESCE(SUM(CASE WHEN b.statusz IN ("FIZETVE","BESZAMITVA") THEN b.osszeg ELSE COALESCE(rb.reszt, 0) END), 0) AS fizetett,
        COALESCE(SUM(b.osszeg), 0) AS teljes,
        COALESCE(SUM(rb.reszt), 0) AS reszt
   FROM kotesek k
   JOIN cegek c ON c.id = k.ceg_id
   LEFT JOIN felhasznalok fl ON fl.id = k.letrehozta
   LEFT JOIN felhasznalok fm ON fm.id = k.modositotta
   LEFT JOIN bejovo_szamlak b ON b.kotes_id = k.id' . RESZT_BEJOVO_JOIN;

/*
 * Számla-összesítő (cég-egyenleg, időszakos kötés-találatok) – ugyanazok a fogalmak, mint a kötés-összesítőben:
 *   nyitott_db / fizetett_db = nyitott (FIZETENDŐ, UTALÁSHOZ ADVA) / rendezett (FIZETVE, BESZÁMÍTVA) számlák
 *   nyitott          = a nyitott számlák hátraléka (ebből fizetendo és utalas_alatt)
 *   fizetett         = a rendezett számlák összege + a nyitott számlák részteljesítései (ebből reszt)
 *   fizetett_szamlak = csak a rendezett számlák összege (a FIZETETT fül listájának összege)
 *   teljes           = minden számla eredeti összege (= nyitott + fizetett)
 */
const BEJOVO_OSSZESITO_MEZOK = ' COUNT(b.id) AS db,
        SUM(CASE WHEN b.statusz IN ("FIZETENDO","UTALASHOZ_ADVA") THEN 1 ELSE 0 END) AS nyitott_db,
        SUM(CASE WHEN b.statusz IN ("FIZETVE","BESZAMITVA") THEN 1 ELSE 0 END) AS fizetett_db,
        COALESCE(SUM(CASE WHEN b.statusz IN ("FIZETENDO","UTALASHOZ_ADVA") THEN b.osszeg - COALESCE(rb.reszt, 0) END), 0) AS nyitott,
        COALESCE(SUM(CASE WHEN b.statusz = "FIZETENDO" THEN b.osszeg - COALESCE(rb.reszt, 0) END), 0) AS fizetendo,
        COALESCE(SUM(CASE WHEN b.statusz = "UTALASHOZ_ADVA" THEN b.osszeg - COALESCE(rb.reszt, 0) END), 0) AS utalas_alatt,
        COALESCE(SUM(CASE WHEN b.statusz IN ("FIZETVE","BESZAMITVA") THEN b.osszeg ELSE COALESCE(rb.reszt, 0) END), 0) AS fizetett,
        COALESCE(SUM(CASE WHEN b.statusz IN ("FIZETVE","BESZAMITVA") THEN b.osszeg END), 0) AS fizetett_szamlak,
        COALESCE(SUM(CASE WHEN b.statusz IN ("FIZETENDO","UTALASHOZ_ADVA") THEN rb.reszt END), 0) AS reszt,
        COALESCE(SUM(b.osszeg), 0) AS teljes ';

function bejovo_osszesito_szamok(array $r): array
{
    $o = [];
    foreach (['db', 'nyitott_db', 'fizetett_db'] as $m) {
        $o[$m] = (int)$r[$m];
    }
    foreach (['nyitott', 'fizetendo', 'utalas_alatt', 'fizetett', 'fizetett_szamlak', 'reszt', 'teljes'] as $m) {
        $o[$m] = (float)$r[$m];
    }
    return $o;
}

/** Egy cég bejövő számláinak egyenlege pénznemenként (EUR elöl); $szukites: további SQL-feltétel (pl. időszak) a paramétereivel */
function bejovo_egyenleg(int $cegId, string $szukites = '', array $param = []): array
{
    $e = [];
    $sorok = db_all('SELECT k.penznem,' . BEJOVO_OSSZESITO_MEZOK . 'FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN
        . 'WHERE k.ceg_id = ?' . $szukites . ' GROUP BY k.penznem ORDER BY k.penznem = "EUR" DESC, k.penznem', array_merge([$cegId], $param));
    foreach ($sorok as $r) {
        $e[$r['penznem']] = bejovo_osszesito_szamok($r);
    }
    return $e;
}

/** Egy cég kötései összesítésekkel és státusszal + a cég egyenlege (a kötések oldal teteje) */
function act_kotesek(array $be): array
{
    csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    $sorok = db_all(KOTES_SQL . 'WHERE k.ceg_id = ? GROUP BY k.id ORDER BY k.id DESC', [$cegId]);
    $sorok = array_map('kotes_sor_feldolgoz', $sorok);
    naplo('KOTESEK', "cég #{$cegId} ({$ceg['nev']}) kötései megtekintve");
    return ['ceg' => ['id' => (int)$ceg['id'], 'nev' => $ceg['nev']], 'kotesek' => $sorok, 'egyenleg' => bejovo_egyenleg($cegId)];
}

/** Egy kötés adatai */
function act_kotes(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id');
    $k = db_row(KOTES_SQL . 'WHERE k.id = ? GROUP BY k.id', [$id]);
    if (!$k) {
        hiba('A kötés nem található.');
    }
    return ['kotes' => kotes_sor_feldolgoz($k)];
}

/** Új kötés: év-pénznem-6 jegyű sorszám */
function act_kotes_letrehoz(array $be): array
{
    $u = csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $penznem = be_penznem($be);
    $ev = be_int($be, 'ev', false) ?? (int)date('Y');
    if ($ev < 2000 || $ev > 2099) {
        hiba('Érvénytelen év.');
    }
    $megnevezes = be_szoveg($be, 'megnevezes', 191, false, 'megnevezés');
    $megj = be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés');
    $regiKod = be_szoveg($be, 'regi_kod', 100, false, 'régi kötés ID');
    $regiKod = $regiKod !== null && trim($regiKod) !== '' ? trim($regiKod) : null;
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    if ($regiKod !== null && !be_bool($be, 'megerositve')) {
        $h = regi_kod_hasznalat($regiKod);
        if ($h) {
            hiba('Ezt a régi kötés ID-t már használtad.', 'REGI_KOD_FOGLALT', ['kotesek' => $h]);
        }
    }
    $eredmeny = db_tx(function () use ($cegId, $penznem, $ev, $megnevezes, $megj, $regiKod, $u) {
        $n = kovetkezo_sorszam('KOTES', $ev, $penznem);
        $kod = kotes_kod($ev, $penznem, $n);
        db_exec(
            'INSERT INTO kotesek (ceg_id, ev, penznem, sorszam, kod, megnevezes, megjegyzes, megjegyzes_irta, regi_kod, letrehozta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$cegId, $ev, $penznem, $n, $kod, $megnevezes, $megj, trim((string)$megj) !== '' ? $u['id'] : null, $regiKod, $u['id']]
        );
        $id = (int)db()->lastInsertId();
        audit_ir('KOTES', $id, 'LETREHOZ', "Kötés létrehozva: $kod ($penznem)", audit_kezdo(['megnevezes' => $megnevezes, 'regi_kod' => $regiKod, 'megjegyzes' => $megj], ['megnevezes', 'regi_kod', 'megjegyzes']), null, $u);
        return ['id' => $id, 'kod' => $kod];
    });
    naplo('KOTES_LETREHOZ', "új kötés {$eredmeny['kod']} (#{$eredmeny['id']}) – cég: {$ceg['nev']}" . ($megnevezes ? " – $megnevezes" : '') . ($regiKod ? " – régi ID: $regiKod" . (be_bool($be, 'megerositve') ? ' (MÁR HASZNÁLT, megerősítve)' : '') : ''));
    return $eredmeny;
}

/** Kötés megnevezés / megjegyzés módosítása */
function act_kotes_modosit(array $be): array
{
    $u = csak_bejelentkezve();
    $id = be_int($be, 'id');
    $k = db_row('SELECT * FROM kotesek WHERE id = ?', [$id]);
    if (!$k) {
        hiba('A kötés nem található.');
    }
    $irhat = jog_van('ir', $u);   // Üzletkötő: csak a megjegyzéshez fűzhet hozzá, a többi mező marad
    $megnevezes = $irhat ? be_szoveg($be, 'megnevezes', 191, false, 'megnevezés') : $k['megnevezes'];
    $mj = megjegyzes_szabaly($k, be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés'), $u);
    $megj = $mj['megjegyzes'];
    $regiKod = $irhat ? be_szoveg($be, 'regi_kod', 100, false, 'régi kötés ID') : $k['regi_kod'];
    $regiKod = $regiKod !== null && trim($regiKod) !== '' ? trim($regiKod) : null;
    if ($irhat && $regiKod !== null && mb_strtolower($regiKod) !== mb_strtolower((string)($k['regi_kod'] ?? '')) && !be_bool($be, 'megerositve')) {
        $h = regi_kod_hasznalat($regiKod, $id);
        if ($h) {
            hiba('Ezt a régi kötés ID-t már használtad.', 'REGI_KOD_FOGLALT', ['kotesek' => $h]);
        }
    }
    $diff = audit_diff($k, ['megnevezes' => $megnevezes, 'megjegyzes' => $megj, 'regi_kod' => $regiKod], ['megnevezes', 'regi_kod', 'megjegyzes']);
    if (!$diff) {
        return ['modositva' => false];   // nem változott semmi
    }
    db_exec('UPDATE kotesek SET megnevezes = ?, megjegyzes = ?, megjegyzes_irta = ?, regi_kod = ?, modositva = NOW(), modositotta = ? WHERE id = ?', [$megnevezes, $megj, $mj['irta'], $regiKod, $u['id'], $id]);
    audit_ir('KOTES', $id, 'MODOSIT', "Kötés módosítva: {$k['kod']}" . ($irhat ? '' : ' (üzletkötő – megjegyzés)'), $diff, null, $u);
    naplo('KOTES_MODOSIT', "kötés {$k['kod']} módosítva: megnevezés=" . ($megnevezes ?? '') . ($regiKod !== ($k['regi_kod'] ?? null) ? ', régi ID=' . ($regiKod ?? 'üres') : ''));
    return ['modositva' => true];
}

/** Kötés törlése – csak ha nincs benne számla */
function act_kotes_torol(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id');
    $k = db_row('SELECT * FROM kotesek WHERE id = ?', [$id]);
    if (!$k) {
        hiba('A kötés nem található.');
    }
    if ((int)db_val('SELECT COUNT(*) FROM bejovo_szamlak WHERE kotes_id = ?', [$id]) > 0) {
        hiba('A kötés nem törölhető, mert számlák tartoznak hozzá.');
    }
    db_exec('DELETE FROM kotesek WHERE id = ?', [$id]);
    audit_ir('KOTES', $id, 'TOROL', "Kötés törölve: {$k['kod']}" . ($k['megnevezes'] ? " – {$k['megnevezes']}" : ''));
    naplo('KOTES_TOROL', "kötés törölve: {$k['kod']}");
    return ['torolve' => true];
}

// ---------------------------------------------------------------------------
//  BEJÖVŐ SZÁMLÁK
// ---------------------------------------------------------------------------

function bejovo_sor_feldolgoz(array $s): array
{
    foreach (['id', 'kotes_id', 'k_sorszam', 'letrehozta', 'modositotta', 'utalas_id', 'ceg_id', 'kotes_pk'] as $c) {
        if (array_key_exists($c, $s)) {
            $s[$c] = $s[$c] === null ? null : (int)$s[$c];
        }
    }
    $s['osszeg'] = (float)$s['osszeg'];
    $s['reszt'] = (float)($s['reszt'] ?? 0);
    $s['reszt_db'] = (int)($s['reszt_db'] ?? 0);
    $s['hatralek'] = array_key_exists('hatralek', $s) ? (float)$s['hatralek'] : round($s['osszeg'] - $s['reszt'], 2);
    $s['k'] = sprintf('K%04d', $s['k_sorszam']);
    unset($s['szamlaszam_norm']);
    return $s;
}

const BEJOVO_SQL = 'SELECT b.*, k.kod AS kotes_kod, k.penznem, k.ceg_id, k.id AS kotes_pk, k.megnevezes AS kotes_megnevezes,
                           c.nev AS ceg_nev, u.uid AS utalas_uid, u.statusz AS utalas_statusz,
                           COALESCE(b.fizetve_datum, u.utalva_datum) AS fizetve_datum,
                           fl.felhasznalonev AS letrehozta_nev, fm.felhasznalonev AS modositotta_nev,' . RESZT_BEJOVO_MEZOK . '
                      FROM bejovo_szamlak b
                      JOIN kotesek k ON k.id = b.kotes_id
                      JOIN cegek c ON c.id = k.ceg_id
                      LEFT JOIN utalasok u ON u.id = b.utalas_id
                      LEFT JOIN felhasznalok fl ON fl.id = b.letrehozta
                      LEFT JOIN felhasznalok fm ON fm.id = b.modositotta' . RESZT_BEJOVO_JOIN;

/** Egy kötés számlái */
function act_bejovo_szamlak(array $be): array
{
    csak_bejelentkezve();
    $kotesId = be_int($be, 'kotes_id');
    $k = db_row(KOTES_SQL . 'WHERE k.id = ? GROUP BY k.id', [$kotesId]);
    if (!$k) {
        hiba('A kötés nem található.');
    }
    $sorok = db_all(BEJOVO_SQL . 'WHERE b.kotes_id = ? ORDER BY b.k_sorszam', [$kotesId]);
    naplo('BEJOVO_SZAMLAK', "kötés {$k['kod']} számlái megtekintve");
    return ['kotes' => kotes_sor_feldolgoz($k), 'szamlak' => reszt_csatol(array_map('bejovo_sor_feldolgoz', $sorok), 'BEJOVO')];
}

const BEJOVO_IDOSZAK_OSZLOP = ['kelt' => 'b.kelt', 'teljesites' => 'b.teljesites_datum', 'hatarido' => 'b.fizetesi_hatarido'];
/** A kötések oldal státusz-füle → számla-státuszok (MIND: nincs szűrés) */
const KOTES_FUL_STATUSZOK = ['NYITOTT' => ['FIZETENDO', 'UTALASHOZ_ADVA'], 'FIZETETT' => ['FIZETVE', 'BESZAMITVA'], 'MIND' => []];
const BEJOVO_IDOSZAK_MAX = 3000;

/**
 * Egy cég bejövő számlái időszak szerint (minden kötésből) – a kötések oldal teljes szűrője:
 *   statusz  = a kötések oldal füle: NYITOTT (fizetendő + utalás alatt) · FIZETETT (fizetve + beszámítva) · MIND
 *   szamlak  = a fülnek megfelelő számlák az időszakban (a nyomtatási kosárhoz; csonka = több volt a korlátnál)
 *   kotesek  = kötésenkénti összesítő az időszak összes számlájáról (a kötéslista szűrése és a fülek darabszámai)
 *   egyenleg = a cég egyenlege az időszakban, pénznemenként; osszegek = a fül listájának összege pénznemenként
 */
function act_bejovo_szamlak_idoszak(array $be): array
{
    csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $mezo = be_valaszt($be, 'mezo', array_keys(BEJOVO_IDOSZAK_OSZLOP), 'kelt', 'dátummező');
    $oszlop = BEJOVO_IDOSZAK_OSZLOP[$mezo];
    $statusz = be_valaszt($be, 'statusz', array_keys(KOTES_FUL_STATUSZOK), 'MIND', 'státusz');
    $tol = be_datum($be, 'tol', true, 'kezdő dátum');
    $ig = be_datum($be, 'ig', true, 'záró dátum');
    if ($tol > $ig) {
        hiba('A kezdő dátum nem lehet későbbi a záró dátumnál.');
    }
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    $idoszak = " AND $oszlop BETWEEN ? AND ?";
    $statuszok = KOTES_FUL_STATUSZOK[$statusz];
    $statuszSql = $statuszok ? ' AND b.statusz IN (' . implode(',', array_fill(0, count($statuszok), '?')) . ')' : '';
    $sorok = db_all(BEJOVO_SQL . "WHERE k.ceg_id = ?$idoszak$statuszSql ORDER BY $oszlop, b.kod LIMIT " . (BEJOVO_IDOSZAK_MAX + 1), array_merge([$cegId, $tol, $ig], $statuszok));
    $csonka = count($sorok) > BEJOVO_IDOSZAK_MAX;
    $sorok = reszt_csatol(array_map('bejovo_sor_feldolgoz', array_slice($sorok, 0, BEJOVO_IDOSZAK_MAX)), 'BEJOVO');
    $kotesek = [];
    foreach (db_all('SELECT b.kotes_id,' . BEJOVO_OSSZESITO_MEZOK . 'FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN
        . "WHERE k.ceg_id = ?$idoszak GROUP BY b.kotes_id", [$cegId, $tol, $ig]) as $r) {
        $kotesek[(int)$r['kotes_id']] = bejovo_osszesito_szamok($r);
    }
    $egyenleg = bejovo_egyenleg($cegId, $idoszak, [$tol, $ig]);
    $osszegek = [];
    foreach ($egyenleg as $pn => $e) {
        [$db, $osszeg] = ['NYITOTT' => [$e['nyitott_db'], $e['nyitott']], 'FIZETETT' => [$e['fizetett_db'], $e['fizetett_szamlak']], 'MIND' => [$e['db'], $e['teljes']]][$statusz];
        if ($db > 0) {
            $osszegek[$pn] = $osszeg;
        }
    }
    naplo('BEJOVO_IDOSZAK', "cég #{$cegId} ({$ceg['nev']}) számlái $mezo szerint $tol – $ig, $statusz: " . count($sorok) . ' db' . ($csonka ? ' (csonka lista)' : ''));
    return ['ceg' => ['id' => (int)$ceg['id'], 'nev' => $ceg['nev']], 'szamlak' => $sorok, 'csonka' => $csonka, 'osszegek' => $osszegek,
            'kotesek' => $kotesek, 'egyenleg' => $egyenleg, 'mezo' => $mezo, 'statusz' => $statusz, 'tol' => $tol, 'ig' => $ig];
}

/** Egy bejövő számla */
function act_bejovo_szamla(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id');
    $s = db_row(BEJOVO_SQL . 'WHERE b.id = ?', [$id]);
    if (!$s) {
        hiba('A számla nem található.');
    }
    return ['szamla' => reszt_csatol([bejovo_sor_feldolgoz($s)], 'BEJOVO')[0]];
}

/**
 * Azonnali számlaszám-ellenőrzés gépelés közben (bejövő ÉS kimenő számlák között).
 * kiveve_bejovo_id / kiveve_kimeno_id: szerkesztésnél a saját sor kihagyása.
 */
function act_szamlaszam_ellenoriz(array $be): array
{
    csak_bejelentkezve();
    $szamlaszam = trim((string)($be['szamlaszam'] ?? ''));
    if ($szamlaszam === '') {
        return ['foglalt' => false, 'talalat' => null];
    }
    if (norm_azonosito($szamlaszam) === '') {
        return ['foglalt' => false, 'talalat' => null, 'ervenytelen' => true];
    }
    $kiveveB = be_int($be, 'kiveve_bejovo_id', false);
    $kiveveK = be_int($be, 'kiveve_kimeno_id', false);
    $kotesId = be_int($be, 'kotes_id', false);
    $kimeno = be_bool($be, 'kimeno');
    $talalatok = szamlaszam_talalatok($szamlaszam, $kiveveB, $kiveveK);
    if ($kotesId !== null) {
        // bejövő számla űrlap: kötésen belül TILTÁS, máshol csak figyelmeztetés
        $hard = szamlaszam_kotesben_foglalt($szamlaszam, $kotesId, $kiveveB);
        $uzenet = $hard ? szamlaszam_kotes_uzenet($hard) : null;
        $tobbi = array_values(array_filter($talalatok, fn($t) => !($t['irany'] === 'BEJOVO' && $t['kotes_pk'] === $kotesId)));
    } elseif ($kimeno) {
        // kimenő számla űrlap: a kimenők között TILTÁS, bejövővel csak figyelmeztetés
        $hard = szamlaszam_kimeno_foglalt($szamlaszam, $kiveveK);
        $uzenet = $hard ? szamlaszam_kimeno_uzenet($hard) : null;
        $tobbi = array_values(array_filter($talalatok, fn($t) => $t['irany'] === 'BEJOVO'));
    } else {
        $hard = $talalatok[0] ?? null;
        $uzenet = $hard ? szamlaszam_foglalt_uzenet($hard) : null;
        $tobbi = [];
    }
    $fig = szamlaszam_figyelmeztetes($tobbi);
    return ['foglalt' => $hard !== null, 'talalat' => $hard, 'uzenet' => $uzenet, 'figyelmeztetes' => $fig !== '', 'figyelmeztetes_uzenet' => $fig !== '' ? $fig : null, 'talalatok' => $tobbi];
}

/** Új bejövő számla a kötésen belül – automatikus K sorszámmal */
function act_bejovo_szamla_letrehoz(array $be): array
{
    $u = csak_bejelentkezve();
    $kotesId = be_int($be, 'kotes_id');
    $k = db_row('SELECT k.*, c.nev AS ceg_nev FROM kotesek k JOIN cegek c ON c.id = k.ceg_id WHERE k.id = ?', [$kotesId]);
    if (!$k) {
        hiba('A kötés nem található.');
    }
    $szamlaszam = be_szoveg($be, 'szamlaszam', 100, true, 'számlaszám');
    $telj = be_datum($be, 'teljesites_datum', true, 'teljesítési dátum');
    $kelt = be_datum($be, 'kelt', true, 'számla kelte');
    $hatarido = be_datum($be, 'fizetesi_hatarido', true, 'fizetési határidő');
    $osszeg = be_osszeg($be, 'osszeg', true, 'számla összege');
    $beszam = be_szoveg($be, 'beszam', 191, false, 'BESZÁM');
    $megj = be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés');

    $eredmeny = db_tx(function () use ($kotesId, $k, $szamlaszam, $telj, $kelt, $hatarido, $osszeg, $beszam, $megj, $u) {
        // egy kötésen belül ugyanaz a számlaszám csak egyszer (másik kötésben megengedett – a felület figyelmeztet)
        $t = szamlaszam_kotesben_foglalt($szamlaszam, $kotesId);
        if ($t !== null) {
            hiba(szamlaszam_kotes_uzenet($t), 'SZAMLASZAM_FOGLALT', ['talalat' => $t]);
        }
        $ksz = kovetkezo_k_sorszam($kotesId);
        $kod = szamla_kod($k['kod'], $ksz);
        db_exec(
            'INSERT INTO bejovo_szamlak (kotes_id, k_sorszam, kod, szamlaszam, szamlaszam_norm, teljesites_datum, kelt, osszeg, beszam, fizetesi_hatarido, statusz, megjegyzes, megjegyzes_irta, letrehozta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "FIZETENDO", ?, ?, ?)',
            [$kotesId, $ksz, $kod, $szamlaszam, norm_azonosito($szamlaszam), $telj, $kelt, $osszeg, $beszam, $hatarido, $megj, trim((string)$megj) !== '' ? $u['id'] : null, $u['id']]
        );
        $id = (int)db()->lastInsertId();
        audit_ir('BEJOVO', $id, 'LETREHOZ', "Számla létrehozva: $kod „{$szamlaszam}”",
            audit_kezdo(['szamlaszam' => $szamlaszam, 'kelt' => $kelt, 'teljesites_datum' => $telj, 'fizetesi_hatarido' => $hatarido, 'osszeg' => $osszeg, 'beszam' => $beszam, 'megjegyzes' => $megj],
                ['szamlaszam', 'kelt', 'teljesites_datum', 'fizetesi_hatarido', 'osszeg', 'beszam', 'megjegyzes'], $k['penznem']), $kotesId, $u);
        return ['id' => $id, 'kod' => $kod];
    });
    naplo('BEJOVO_SZAMLA_LETREHOZ', "új számla {$eredmeny['kod']} – számlaszám „{$szamlaszam}”, " . fmt_osszeg($osszeg) . " {$k['penznem']}, határidő $hatarido, cég: {$k['ceg_nev']}");
    return $eredmeny;
}

/**
 * Bejövő számla módosítása.
 * FIZETENDŐ / UTALÁSHOZ ADVA: minden mező módosítható.
 * FIZETVE / BESZÁMÍTVA: csak BESZÁM és megjegyzés.
 */
function act_bejovo_szamla_modosit(array $be): array
{
    $u = csak_bejelentkezve();
    $id = be_int($be, 'id');
    $s = db_row(BEJOVO_SQL . 'WHERE b.id = ?', [$id]);
    if (!$s) {
        hiba('A számla nem található.');
    }
    $beszam = be_szoveg($be, 'beszam', 191, false, 'BESZÁM');
    beszam_szabaly($s['beszam'], $beszam, $u);                       // Üzletkötő: a kitöltött BESZÁM nem törölhető
    $mj = megjegyzes_szabaly($s, be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés'), $u);   // más megjegyzése: csak hozzáfűzés
    $megj = $mj['megjegyzes'];
    $lezart = in_array($s['statusz'], ['FIZETVE', 'BESZAMITVA'], true);
    $korlatozott = $lezart || !jog_van('ir', $u);                    // rendezett számla VAGY Üzletkötő: csak BESZÁM + megjegyzés

    if ($korlatozott) {
        $diff = audit_diff($s, ['beszam' => $beszam, 'megjegyzes' => $megj], ['beszam', 'megjegyzes'], $s['penznem']);
        if ($diff) {
            db_exec('UPDATE bejovo_szamlak SET beszam = ?, megjegyzes = ?, megjegyzes_irta = ?, modositotta = ? WHERE id = ?', [$beszam, $megj, $mj['irta'], $u['id'], $id]);
            audit_ir('BEJOVO', $id, 'MODOSIT', "Számla módosítva: {$s['kod']} (" . ($lezart ? 'rendezett' : 'üzletkötő') . ' – csak BESZÁM / megjegyzés)', $diff, (int)$s['kotes_id'], $u);
        }
        naplo('BEJOVO_SZAMLA_MODOSIT', "számla {$s['kod']} (" . ($lezart ? 'lezárt' : 'üzletkötő') . '): BESZÁM/megjegyzés módosítva');
        return ['modositva' => true, 'korlatozott' => true];
    }

    $szamlaszam = be_szoveg($be, 'szamlaszam', 100, true, 'számlaszám');
    $telj = be_datum($be, 'teljesites_datum', true, 'teljesítési dátum');
    $kelt = be_datum($be, 'kelt', true, 'számla kelte');
    $hatarido = be_datum($be, 'fizetesi_hatarido', true, 'fizetési határidő');
    $osszeg = be_osszeg($be, 'osszeg', true, 'számla összege');
    if ((float)$s['reszt'] > 0 && (float)$osszeg <= (float)$s['reszt']) {
        hiba('A számla összege (' . fmt_osszeg($osszeg) . " {$s['penznem']}) nem lehet kisebb vagy egyenlő a már felvezetett részteljesítéseknél (" . fmt_osszeg($s['reszt']) . " {$s['penznem']}). Előbb töröld a részteljesítést.");
    }

    db_tx(function () use ($id, $s, $szamlaszam, $telj, $kelt, $hatarido, $osszeg, $beszam, $megj, $mj, $u) {
        $t = szamlaszam_kotesben_foglalt($szamlaszam, (int)$s['kotes_id'], $id);
        if ($t !== null) {
            hiba(szamlaszam_kotes_uzenet($t), 'SZAMLASZAM_FOGLALT', ['talalat' => $t]);
        }
        $uj = ['szamlaszam' => $szamlaszam, 'kelt' => $kelt, 'teljesites_datum' => $telj, 'fizetesi_hatarido' => $hatarido, 'osszeg' => $osszeg, 'beszam' => $beszam, 'megjegyzes' => $megj];
        $diff = audit_diff($s, $uj, ['szamlaszam', 'kelt', 'teljesites_datum', 'fizetesi_hatarido', 'osszeg', 'beszam', 'megjegyzes'], $s['penznem']);
        if (!$diff) {
            return;   // nem változott semmi – nem írunk módosítást
        }
        db_exec(
            'UPDATE bejovo_szamlak SET szamlaszam = ?, szamlaszam_norm = ?, teljesites_datum = ?, kelt = ?, osszeg = ?, beszam = ?, fizetesi_hatarido = ?, megjegyzes = ?, megjegyzes_irta = ?, modositotta = ? WHERE id = ?',
            [$szamlaszam, norm_azonosito($szamlaszam), $telj, $kelt, $osszeg, $beszam, $hatarido, $megj, $mj['irta'], $u['id'], $id]
        );
        audit_ir('BEJOVO', $id, 'MODOSIT', "Számla módosítva: {$s['kod']}", $diff, (int)$s['kotes_id'], $u);
    });
    naplo('BEJOVO_SZAMLA_MODOSIT', "számla {$s['kod']}: számlaszám „{$s['szamlaszam']}”→„{$szamlaszam}”, összeg " . fmt_osszeg($s['osszeg']) . '→' . fmt_osszeg($osszeg) . " {$s['penznem']}, határidő {$s['fizetesi_hatarido']}→{$hatarido}");
    return ['modositva' => true];
}

/** Bejövő számla törlése – csak FIZETENDŐ státuszban */
function act_bejovo_szamla_torol(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id');
    $s = db_row(BEJOVO_SQL . 'WHERE b.id = ?', [$id]);
    if (!$s) {
        hiba('A számla nem található.');
    }
    if ($s['statusz'] !== 'FIZETENDO') {
        hiba('Csak FIZETENDŐ státuszú számla törölhető. (Utalásból előbb távolítsd el.)');
    }
    if ((int)$s['reszt_db'] > 0) {
        hiba('A számlához részteljesítés tartozik (' . fmt_osszeg($s['reszt']) . " {$s['penznem']}) – előbb töröld a részteljesítéseket a szerkesztő ablakban.");
    }
    db_exec('DELETE FROM bejovo_szamlak WHERE id = ?', [$id]);
    audit_ir('BEJOVO', $id, 'TOROL', "Számla törölve: {$s['kod']} „{$s['szamlaszam']}” (" . fmt_osszeg($s['osszeg']) . " {$s['penznem']}, határidő " . audit_ertek('fizetesi_hatarido', $s['fizetesi_hatarido']) . ')', [], (int)$s['kotes_id']);
    naplo('BEJOVO_SZAMLA_TOROL', "számla törölve: {$s['kod']} („{$s['szamlaszam']}”, " . fmt_osszeg($s['osszeg']) . " {$s['penznem']})");
    return ['torolve' => true];
}
