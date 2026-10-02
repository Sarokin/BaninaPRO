<?php
declare(strict_types=1);

/**
 * Cégek listája összesítésekkel:
 *  be_HUF / be_EUR  – bejövő, még nem fizetett (FIZETENDŐ + UTALÁSHOZ ADVA) összeg
 *  ki_HUF / ki_EUR  – kimenő NYITOTT összeg (ennyivel tartozik nekem)
 *  kotes_db, nyitott_kotes_db, kimeno_nyitott_db
 */
function act_cegek(array $be): array
{
    csak_bejelentkezve();
    $mind = be_bool($be, 'inaktiv_is');
    $cegek = db_all('SELECT id, nev, adoszam, partnerkod, megjegyzes, megjegyzes_irta, aktiv, letrehozva FROM cegek ' . ($mind ? '' : 'WHERE aktiv = 1 ') . 'ORDER BY nev');

    $be_sum = db_all(
        'SELECT k.ceg_id, k.penznem,
                SUM(CASE WHEN b.statusz IN ("FIZETENDO","UTALASHOZ_ADVA") THEN b.osszeg - COALESCE(rb.reszt, 0) ELSE 0 END) AS nyitott,
                SUM(CASE WHEN b.statusz = "FIZETENDO" THEN 1 ELSE 0 END) AS fizetendo_db
           FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id' . RESZT_BEJOVO_JOIN . '
          GROUP BY k.ceg_id, k.penznem'
    );
    $kotes_db = db_all(
        'SELECT k.ceg_id, COUNT(*) AS db,
                SUM(CASE WHEN EXISTS (SELECT 1 FROM bejovo_szamlak b WHERE b.kotes_id = k.id AND b.statusz IN ("FIZETENDO","UTALASHOZ_ADVA"))
                          OR NOT EXISTS (SELECT 1 FROM bejovo_szamlak b2 WHERE b2.kotes_id = k.id) THEN 1 ELSE 0 END) AS nyitott_db
           FROM kotesek k GROUP BY k.ceg_id'
    );
    $ki_sum = db_all(
        'SELECT s.ceg_id, s.penznem,
                SUM(CASE WHEN s.statusz = "NYITOTT" THEN s.osszeg - COALESCE(rk.reszt, 0) ELSE 0 END) AS nyitott,
                SUM(CASE WHEN s.statusz = "NYITOTT" THEN 1 ELSE 0 END) AS nyitott_db
           FROM kimeno_szamlak s' . RESZT_KIMENO_JOIN . 'GROUP BY s.ceg_id, s.penznem'
    );
    $ut_db = db_all('SELECT ceg_id, COUNT(*) AS db FROM utalasok WHERE statusz = "NYITOTT" GROUP BY ceg_id');

    $map = [];
    foreach ($cegek as $c) {
        $c['id'] = (int)$c['id'];
        $c['aktiv'] = (int)$c['aktiv'] === 1;
        $c += ['be_HUF' => 0.0, 'be_EUR' => 0.0, 'ki_HUF' => 0.0, 'ki_EUR' => 0.0,
               'kotes_db' => 0, 'nyitott_kotes_db' => 0, 'fizetendo_db' => 0, 'kimeno_nyitott_db' => 0, 'nyitott_utalas_db' => 0];
        $map[$c['id']] = $c;
    }
    foreach ($be_sum as $s) {
        $id = (int)$s['ceg_id'];
        if (isset($map[$id])) {
            $map[$id]['be_' . $s['penznem']] = (float)$s['nyitott'];
            $map[$id]['fizetendo_db'] += (int)$s['fizetendo_db'];
        }
    }
    foreach ($kotes_db as $s) {
        $id = (int)$s['ceg_id'];
        if (isset($map[$id])) {
            $map[$id]['kotes_db'] = (int)$s['db'];
            $map[$id]['nyitott_kotes_db'] = (int)$s['nyitott_db'];
        }
    }
    foreach ($ki_sum as $s) {
        $id = (int)$s['ceg_id'];
        if (isset($map[$id])) {
            $map[$id]['ki_' . $s['penznem']] = (float)$s['nyitott'];
            $map[$id]['kimeno_nyitott_db'] += (int)$s['nyitott_db'];
        }
    }
    foreach ($ut_db as $s) {
        $id = (int)$s['ceg_id'];
        if (isset($map[$id])) {
            $map[$id]['nyitott_utalas_db'] = (int)$s['db'];
        }
    }
    naplo('CEGEK', 'céglista megtekintve');
    return ['cegek' => array_values($map)];
}

/** Egy cég adatai + nyitott utalásai */
function act_ceg(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id');
    $c = db_row('SELECT id, nev, adoszam, partnerkod, megjegyzes, megjegyzes_irta, aktiv, letrehozva FROM cegek WHERE id = ?', [$id]);
    if (!$c) {
        hiba('A cég nem található.');
    }
    $c['id'] = (int)$c['id'];
    $c['aktiv'] = (int)$c['aktiv'] === 1;
    $nyitott = db_all(
        'SELECT u.id, u.uid, u.penznem, u.utalas_mod, u.hatarertek, u.statusz, u.lezarva,
                COALESCE((SELECT SUM(b.osszeg) FROM bejovo_szamlak b WHERE b.utalas_id = u.id), 0) AS osszeg,
                (SELECT COUNT(*) FROM bejovo_szamlak b WHERE b.utalas_id = u.id) AS db
           FROM utalasok u WHERE u.ceg_id = ? AND u.statusz = "NYITOTT" ORDER BY u.id DESC',
        [$id]
    );
    foreach ($nyitott as &$u) {
        $u['id'] = (int)$u['id'];
        $u['osszeg'] = (float)$u['osszeg'];
        $u['db'] = (int)$u['db'];
        $u['lezarva'] = (int)$u['lezarva'] === 1;
        $u['hatarertek'] = $u['hatarertek'] === null ? null : (float)$u['hatarertek'];
    }
    return ['ceg' => $c, 'nyitott_utalasok' => $nyitott];
}

/** Új cég */
function act_ceg_letrehoz(array $be): array
{
    $u = csak_bejelentkezve();
    $nev = be_szoveg($be, 'nev', 191, true, 'cégnév');
    $adoszam = be_szoveg($be, 'adoszam', 32, false, 'adószám');
    $partnerkod = be_szoveg($be, 'partnerkod', 32, false, 'partnerkód');
    $megj = be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés');
    if (db_row('SELECT id FROM cegek WHERE nev = ?', [$nev])) {
        hiba('Ilyen nevű cég már létezik.');
    }
    if ($partnerkod !== null && $partnerkod !== '' && ($m = db_row('SELECT nev FROM cegek WHERE partnerkod = ?', [$partnerkod]))) {
        hiba("Ez a partnerkód már a(z) „{$m['nev']}” céghez tartozik.");
    }
    db_exec('INSERT INTO cegek (nev, adoszam, partnerkod, megjegyzes, megjegyzes_irta, letrehozta) VALUES (?, ?, ?, ?, ?, ?)', [$nev, $adoszam, $partnerkod !== '' ? $partnerkod : null, $megj, trim((string)$megj) !== '' ? $u['id'] : null, $u['id']]);
    $id = (int)db()->lastInsertId();
    naplo('CEG_LETREHOZ', "új cég: $nev (#$id)");
    return ['id' => $id];
}

/** Cég módosítása */
function act_ceg_modosit(array $be): array
{
    $u = csak_bejelentkezve();
    $id = be_int($be, 'id');
    $c = db_row('SELECT * FROM cegek WHERE id = ?', [$id]);
    if (!$c) {
        hiba('A cég nem található.');
    }
    $mj = megjegyzes_szabaly($c, be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés'), $u);   // más megjegyzése: csak hozzáfűzés
    $megj = $mj['megjegyzes'];
    if (!jog_van('ir', $u)) {
        // Üzletkötő: csak a megjegyzéshez fűzhet hozzá, a cég többi adata marad
        db_exec('UPDATE cegek SET megjegyzes = ?, megjegyzes_irta = ? WHERE id = ?', [$megj, $mj['irta'], $id]);
        naplo('CEG_MODOSIT', "cég #$id ({$c['nev']}): megjegyzés (üzletkötő)");
        return ['modositva' => true, 'korlatozott' => true];
    }
    $nev = be_szoveg($be, 'nev', 191, true, 'cégnév');
    $adoszam = be_szoveg($be, 'adoszam', 32, false, 'adószám');
    $partnerkod = array_key_exists('partnerkod', $be) ? be_szoveg($be, 'partnerkod', 32, false, 'partnerkód') : $c['partnerkod'];
    $partnerkod = $partnerkod !== null && $partnerkod !== '' ? $partnerkod : null;
    $aktiv = array_key_exists('aktiv', $be) ? (be_bool($be, 'aktiv') ? 1 : 0) : (int)$c['aktiv'];
    if (db_row('SELECT id FROM cegek WHERE nev = ? AND id <> ?', [$nev, $id])) {
        hiba('Ilyen nevű cég már létezik.');
    }
    if ($partnerkod !== null && ($m = db_row('SELECT nev FROM cegek WHERE partnerkod = ? AND id <> ?', [$partnerkod, $id]))) {
        hiba("Ez a partnerkód már a(z) „{$m['nev']}” céghez tartozik.");
    }
    db_exec('UPDATE cegek SET nev = ?, adoszam = ?, partnerkod = ?, megjegyzes = ?, megjegyzes_irta = ?, aktiv = ? WHERE id = ?', [$nev, $adoszam, $partnerkod, $megj, $mj['irta'], $aktiv, $id]);
    naplo('CEG_MODOSIT', "cég #$id: {$c['nev']} → $nev, aktív=" . ($aktiv ? 'igen' : 'nem'));
    return ['modositva' => true];
}
