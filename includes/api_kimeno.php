<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  KIMENŐ SZÁMLÁK
// ---------------------------------------------------------------------------

require_once __DIR__ . '/api_reszteljesites.php';
require_once __DIR__ . '/audit.php';

const KIMENO_SQL = 'SELECT s.*, c.nev AS ceg_nev, bk.azonosito AS banki_azonosito, bk.datum AS banki_datum,
                           fl.felhasznalonev AS letrehozta_nev, fm.felhasznalonev AS modositotta_nev,' . RESZT_KIMENO_MEZOK . '
                      FROM kimeno_szamlak s
                      JOIN cegek c ON c.id = s.ceg_id
                      LEFT JOIN banki_kivonatok bk ON bk.id = s.banki_kivonat_id
                      LEFT JOIN felhasznalok fl ON fl.id = s.letrehozta
                      LEFT JOIN felhasznalok fm ON fm.id = s.modositotta' . RESZT_KIMENO_JOIN;

function kimeno_sor_feldolgoz(array $s): array
{
    foreach (['id', 'ceg_id', 'ev', 'sorszam', 'banki_kivonat_id', 'letrehozta', 'modositotta'] as $c) {
        if (array_key_exists($c, $s)) {
            $s[$c] = $s[$c] === null ? null : (int)$s[$c];
        }
    }
    $s['osszeg'] = (float)$s['osszeg'];
    $s['reszt'] = (float)($s['reszt'] ?? 0);
    $s['reszt_db'] = (int)($s['reszt_db'] ?? 0);
    $s['hatralek'] = array_key_exists('hatralek', $s) ? (float)$s['hatralek'] : round($s['osszeg'] - $s['reszt'], 2);
    unset($s['szamlaszam_norm']);
    return $s;
}

/** Egy cég kimenő számlái (+ tartozás-összesítés pénznemenként) */
function act_kimeno_szamlak(array $be): array
{
    csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    $w = 'WHERE s.ceg_id = ? ';
    $p = [$cegId];
    if (!empty($be['statusz']) && in_array($be['statusz'], ['NYITOTT', 'FIZETVE'], true)) {
        $w .= 'AND s.statusz = ? ';
        $p[] = $be['statusz'];
    }
    $sorok = reszt_csatol(array_map('kimeno_sor_feldolgoz', db_all(KIMENO_SQL . $w . 'ORDER BY (s.statusz = "NYITOTT") DESC, s.fizetesi_hatarido ASC, s.id DESC', $p)), 'KIMENO');
    // tartozás: nyitott = a NYITOTT számlák hátraléka; fizetve = FIZETVE számlák + a nyitottak részteljesítései
    $ossz = db_all(
        'SELECT s.penznem,
                SUM(CASE WHEN s.statusz = "NYITOTT" THEN s.osszeg - COALESCE(rk.reszt, 0) ELSE 0 END) AS nyitott,
                SUM(CASE WHEN s.statusz = "FIZETVE" THEN s.osszeg ELSE COALESCE(rk.reszt, 0) END) AS fizetve,
                SUM(CASE WHEN s.statusz = "NYITOTT" THEN COALESCE(rk.reszt, 0) ELSE 0 END) AS reszt,
                SUM(CASE WHEN s.statusz = "NYITOTT" THEN 1 ELSE 0 END) AS nyitott_db,
                SUM(s.osszeg) AS osszes
           FROM kimeno_szamlak s' . RESZT_KIMENO_JOIN . 'WHERE s.ceg_id = ? GROUP BY s.penznem',
        [$cegId]
    );
    $tartozas = ['HUF' => ['nyitott' => 0.0, 'fizetve' => 0.0, 'reszt' => 0.0, 'osszes' => 0.0, 'nyitott_db' => 0], 'EUR' => ['nyitott' => 0.0, 'fizetve' => 0.0, 'reszt' => 0.0, 'osszes' => 0.0, 'nyitott_db' => 0]];
    foreach ($ossz as $o) {
        $tartozas[$o['penznem']] = ['nyitott' => (float)$o['nyitott'], 'fizetve' => (float)$o['fizetve'], 'reszt' => (float)$o['reszt'], 'osszes' => (float)$o['osszes'], 'nyitott_db' => (int)$o['nyitott_db']];
    }
    naplo('KIMENO_SZAMLAK', "cég #{$cegId} ({$ceg['nev']}) kimenő számlái megtekintve");
    return ['ceg' => ['id' => (int)$ceg['id'], 'nev' => $ceg['nev']], 'szamlak' => $sorok, 'tartozas' => $tartozas];
}

/** Egy kimenő számla */
function act_kimeno_szamla(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id');
    $s = db_row(KIMENO_SQL . 'WHERE s.id = ?', [$id]);
    if (!$s) {
        hiba('A számla nem található.');
    }
    return ['szamla' => reszt_csatol([kimeno_sor_feldolgoz($s)], 'KIMENO')[0]];
}

/** Több kimenő számla friss adata id-lista alapján (a banki megfeleltetés gyűjtőjéhez) */
function act_kimeno_szamlak_lista(array $be): array
{
    csak_bejelentkezve();
    $ids = be_int_lista($be, 'ids', 'számlák');
    $ids = array_values(array_unique(array_slice($ids, 0, 500)));
    if (!$ids) {
        return ['szamlak' => []];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sorok = db_all(KIMENO_SQL . "WHERE s.id IN ($in) ORDER BY c.nev, s.kod", $ids);
    return ['szamlak' => reszt_csatol(array_map('kimeno_sor_feldolgoz', $sorok), 'KIMENO')];
}

/** Új kimenő számla: Év-pénznem-6 jegyű sorszám */
function act_kimeno_letrehoz(array $be): array
{
    $u = csak_bejelentkezve();
    $cegId = be_int($be, 'ceg_id');
    $ceg = db_row('SELECT id, nev FROM cegek WHERE id = ?', [$cegId]);
    if (!$ceg) {
        hiba('A cég nem található.');
    }
    $penznem = be_penznem($be);
    $ev = be_int($be, 'ev', false) ?? (int)date('Y');
    if ($ev < 2000 || $ev > 2099) {
        hiba('Érvénytelen év.');
    }
    $szamlaszam = be_szoveg($be, 'szamlaszam', 100, true, 'számlaszám');
    $telj = be_datum($be, 'teljesites_datum', true, 'teljesítési dátum');
    $kelt = be_datum($be, 'kelt', true, 'számla kelte');
    $hatarido = be_datum($be, 'fizetesi_hatarido', true, 'fizetési határidő');
    $osszeg = be_osszeg($be, 'osszeg', true, 'összeg');
    $megj = be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés');

    $e = db_tx(function () use ($cegId, $penznem, $ev, $szamlaszam, $telj, $kelt, $hatarido, $osszeg, $megj, $u) {
        // a kimenő számlák között a számlaszám egyedi (bejövővel egyezés csak figyelmeztetés a felületen)
        $t = szamlaszam_kimeno_foglalt($szamlaszam);
        if ($t !== null) {
            hiba(szamlaszam_kimeno_uzenet($t), 'SZAMLASZAM_FOGLALT', ['talalat' => $t]);
        }
        $n = kovetkezo_sorszam('KIMENO', $ev, $penznem);
        $kod = kimeno_kod($ev, $penznem, $n);
        db_exec(
            'INSERT INTO kimeno_szamlak (ceg_id, ev, penznem, sorszam, kod, szamlaszam, szamlaszam_norm, teljesites_datum, kelt, fizetesi_hatarido, osszeg, statusz, megjegyzes, megjegyzes_irta, letrehozta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "NYITOTT", ?, ?, ?)',
            [$cegId, $ev, $penznem, $n, $kod, $szamlaszam, norm_azonosito($szamlaszam), $telj, $kelt, $hatarido, $osszeg, $megj, trim((string)$megj) !== '' ? $u['id'] : null, $u['id']]
        );
        $id = (int)db()->lastInsertId();
        audit_ir('KIMENO', $id, 'LETREHOZ', "Kimenő számla létrehozva: $kod „{$szamlaszam}”",
            audit_kezdo(['szamlaszam' => $szamlaszam, 'kelt' => $kelt, 'teljesites_datum' => $telj, 'fizetesi_hatarido' => $hatarido, 'osszeg' => $osszeg, 'megjegyzes' => $megj],
                ['szamlaszam', 'kelt', 'teljesites_datum', 'fizetesi_hatarido', 'osszeg', 'megjegyzes'], $penznem), null, $u);
        return ['id' => $id, 'kod' => $kod];
    });
    naplo('KIMENO_LETREHOZ', "új kimenő számla {$e['kod']} – számlaszám „{$szamlaszam}”, " . fmt_osszeg($osszeg) . " $penznem, határidő $hatarido, cég: {$ceg['nev']}");
    return $e;
}

/** Kimenő számla módosítása (FIZETVE státuszban csak a megjegyzés) */
function act_kimeno_modosit(array $be): array
{
    $u = csak_bejelentkezve();
    $id = be_int($be, 'id');
    $s = db_row(KIMENO_SQL . 'WHERE s.id = ?', [$id]);
    if (!$s) {
        hiba('A számla nem található.');
    }
    $mj = megjegyzes_szabaly($s, be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés'), $u);   // más megjegyzése: csak hozzáfűzés
    $megj = $mj['megjegyzes'];
    $korlatozott = $s['statusz'] === 'FIZETVE' || !jog_van('ir', $u);   // fizetett számla VAGY Üzletkötő: csak a megjegyzés
    if ($korlatozott) {
        $diff = audit_diff($s, ['megjegyzes' => $megj], ['megjegyzes']);
        if ($diff) {
            db_exec('UPDATE kimeno_szamlak SET megjegyzes = ?, megjegyzes_irta = ?, modositotta = ? WHERE id = ?', [$megj, $mj['irta'], $u['id'], $id]);
            audit_ir('KIMENO', $id, 'MODOSIT', "Kimenő számla módosítva: {$s['kod']} (" . ($s['statusz'] === 'FIZETVE' ? 'fizetve' : 'üzletkötő') . ' – csak megjegyzés)', $diff, null, $u);
        }
        naplo('KIMENO_MODOSIT', "kimenő számla {$s['kod']} (" . ($s['statusz'] === 'FIZETVE' ? 'fizetve' : 'üzletkötő') . '): megjegyzés módosítva');
        return ['modositva' => true, 'korlatozott' => true];
    }
    $szamlaszam = be_szoveg($be, 'szamlaszam', 100, true, 'számlaszám');
    $telj = be_datum($be, 'teljesites_datum', true, 'teljesítési dátum');
    $kelt = be_datum($be, 'kelt', true, 'számla kelte');
    $hatarido = be_datum($be, 'fizetesi_hatarido', true, 'fizetési határidő');
    $osszeg = be_osszeg($be, 'osszeg', true, 'összeg');
    if ((float)$s['reszt'] > 0 && (float)$osszeg <= (float)$s['reszt']) {
        hiba('A számla összege (' . fmt_osszeg($osszeg) . " {$s['penznem']}) nem lehet kisebb vagy egyenlő a már felvezetett részteljesítéseknél (" . fmt_osszeg($s['reszt']) . " {$s['penznem']}). Előbb töröld a részteljesítést.");
    }
    db_tx(function () use ($id, $s, $szamlaszam, $telj, $kelt, $hatarido, $osszeg, $megj, $mj, $u) {
        $t = szamlaszam_kimeno_foglalt($szamlaszam, $id);
        if ($t !== null) {
            hiba(szamlaszam_kimeno_uzenet($t), 'SZAMLASZAM_FOGLALT', ['talalat' => $t]);
        }
        $diff = audit_diff($s, ['szamlaszam' => $szamlaszam, 'kelt' => $kelt, 'teljesites_datum' => $telj, 'fizetesi_hatarido' => $hatarido, 'osszeg' => $osszeg, 'megjegyzes' => $megj],
            ['szamlaszam', 'kelt', 'teljesites_datum', 'fizetesi_hatarido', 'osszeg', 'megjegyzes'], $s['penznem']);
        if (!$diff) {
            return;
        }
        db_exec(
            'UPDATE kimeno_szamlak SET szamlaszam = ?, szamlaszam_norm = ?, teljesites_datum = ?, kelt = ?, fizetesi_hatarido = ?, osszeg = ?, megjegyzes = ?, megjegyzes_irta = ?, modositotta = ? WHERE id = ?',
            [$szamlaszam, norm_azonosito($szamlaszam), $telj, $kelt, $hatarido, $osszeg, $megj, $mj['irta'], $u['id'], $id]
        );
        audit_ir('KIMENO', $id, 'MODOSIT', "Kimenő számla módosítva: {$s['kod']}", $diff, null, $u);
    });
    naplo('KIMENO_MODOSIT', "kimenő számla {$s['kod']}: számlaszám „{$s['szamlaszam']}”→„{$szamlaszam}”, összeg " . fmt_osszeg($s['osszeg']) . '→' . fmt_osszeg($osszeg) . " {$s['penznem']}");
    return ['modositva' => true];
}

/** Kimenő számla törlése – csak NYITOTT */
function act_kimeno_torol(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id');
    $s = db_row(KIMENO_SQL . 'WHERE s.id = ?', [$id]);
    if (!$s) {
        hiba('A számla nem található.');
    }
    if ($s['statusz'] !== 'NYITOTT') {
        hiba('Csak NYITOTT státuszú kimenő számla törölhető.');
    }
    if ((int)$s['reszt_db'] > 0) {
        hiba('A számlához részteljesítés tartozik (' . fmt_osszeg($s['reszt']) . " {$s['penznem']}) – előbb töröld a részteljesítéseket a szerkesztő ablakban.");
    }
    db_exec('DELETE FROM kimeno_szamlak WHERE id = ?', [$id]);
    audit_ir('KIMENO', $id, 'TOROL', "Kimenő számla törölve: {$s['kod']} „{$s['szamlaszam']}” (" . fmt_osszeg($s['osszeg']) . " {$s['penznem']})");
    naplo('KIMENO_TOROL', "kimenő számla törölve: {$s['kod']} („{$s['szamlaszam']}”, " . fmt_osszeg($s['osszeg']) . " {$s['penznem']})");
    return ['torolve' => true];
}

// ---------------------------------------------------------------------------
//  BANKI KIVONAT azonosítók
// ---------------------------------------------------------------------------

function banki_kivonat_szamlai(int $kivonatId): array
{
    $sorok = db_all(KIMENO_SQL . 'WHERE s.banki_kivonat_id = ? ORDER BY s.kod', [$kivonatId]);
    return reszt_csatol(array_map('kimeno_sor_feldolgoz', $sorok), 'KIMENO');
}

/** Azonnali ellenőrzés gépelés közben: használták-e már ezt a banki azonosítót? */
function act_bank_azonosito_ellenoriz(array $be): array
{
    csak_bejelentkezve();
    $az = trim((string)($be['azonosito'] ?? ''));
    $norm = norm_azonosito($az);
    if ($norm === '') {
        return ['hasznalt' => false, 'kivonat' => null, 'szamlak' => []];
    }
    $kiv = db_row('SELECT id, azonosito, datum FROM banki_kivonatok WHERE azonosito_norm = ?', [$norm]);
    if (!$kiv) {
        return ['hasznalt' => false, 'kivonat' => null, 'szamlak' => []];
    }
    $kiv['id'] = (int)$kiv['id'];
    return ['hasznalt' => true, 'kivonat' => $kiv, 'szamlak' => banki_kivonat_szamlai($kiv['id'])];
}

/**
 * Kimenő számlák bankkivonati azonosítóhoz rendelése → FIZETVE.
 * Ha az azonosító már használt és nincs `megerositve`, DUPLA kóddal visszadobja
 * (a kliens ekkor mutatja a "Itt valami nem stimmel!" ablakot + 5 mp-es "Biztos?" kérdést).
 */
function act_bank_hozzarendel(array $be): array
{
    $u = csak_bejelentkezve();
    $ids = be_int_lista($be, 'szamla_ids', 'számlák');
    $datum = be_datum($be, 'datum', true, 'dátum');
    $az = be_szoveg($be, 'azonosito', 100, true, 'banki azonosító');
    $norm = norm_azonosito($az);
    if ($norm === '') {
        hiba('Érvénytelen banki azonosító: legalább egy betűt vagy számot tartalmaznia kell.');
    }
    $megerositve = be_bool($be, 'megerositve');

    $e = db_tx(function () use ($ids, $datum, $az, $norm, $megerositve, $u) {
        $kiv = db_row('SELECT * FROM banki_kivonatok WHERE azonosito_norm = ? FOR UPDATE', [$norm]);
        if ($kiv && !$megerositve) {
            $meglevo = banki_kivonat_szamlai((int)$kiv['id']);
            hiba('Itt valami nem stimmel! Ezt a banki azonosítót már használtad ezekhez a számlákhoz.', 'DUPLA', [
                'kivonat' => ['id' => (int)$kiv['id'], 'azonosito' => $kiv['azonosito'], 'datum' => $kiv['datum']],
                'szamlak' => $meglevo,
            ]);
        }
        if (!$kiv) {
            db_exec('INSERT INTO banki_kivonatok (azonosito, azonosito_norm, datum, letrehozta) VALUES (?, ?, ?, ?)', [$az, $norm, $datum, $u['id']]);
            $kivId = (int)db()->lastInsertId();
            $uj = true;
        } else {
            $kivId = (int)$kiv['id'];
            $uj = false;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $szamlak = db_all("SELECT s.id, s.kod, s.statusz, s.osszeg, s.penznem, COALESCE(rk.reszt, 0) AS reszt FROM kimeno_szamlak s" . RESZT_KIMENO_JOIN . "WHERE s.id IN ($in) FOR UPDATE", $ids);
        if (count($szamlak) !== count($ids)) {
            hiba('Valamelyik számla nem található.');
        }
        $kodok = [];
        foreach ($szamlak as $s) {
            if ($s['statusz'] !== 'NYITOTT') {
                hiba("A(z) {$s['kod']} számla már FIZETVE státuszú.");
            }
            $kodok[] = $s['kod'] . ' (' . fmt_osszeg((float)$s['osszeg'] - (float)$s['reszt']) . ' ' . $s['penznem'] . ((float)$s['reszt'] > 0 ? ', részteljesítés után' : '') . ')';
        }
        db_exec("UPDATE kimeno_szamlak SET statusz = 'FIZETVE', banki_kivonat_id = ?, fizetve_datum = ?, modositotta = ? WHERE id IN ($in)", array_merge([$kivId, $datum, $u['id']], $ids));
        foreach ($szamlak as $s) {
            audit_ir('KIMENO', (int)$s['id'], 'STATUSZ', "Fizetve – bankkivonati azonosító: „{$az}”" . ((float)$s['reszt'] > 0 ? ' (a hátralék: ' . fmt_osszeg((float)$s['osszeg'] - (float)$s['reszt']) . " {$s['penznem']})" : ''),
                [['m' => 'Státusz', 'r' => 'NYITOTT', 'u' => 'FIZETVE'], ['m' => 'Fizetés dátuma', 'r' => '–', 'u' => audit_ertek('fizetve_datum', $datum)], ['m' => 'Banki azonosító', 'r' => '–', 'u' => $az]], null, $u);
        }
        return ['kivonat_id' => $kivId, 'uj' => $uj, 'kodok' => $kodok, 'db' => count($ids)];
    });
    naplo('BANK_HOZZARENDEL', "banki azonosító „{$az}” ($datum" . ($e['uj'] ? ', új' : ', MÁR HASZNÁLT – megerősítve') . ") → {$e['db']} kimenő számla FIZETVE: " . implode(', ', $e['kodok']));
    return ['kivonat_id' => $e['kivonat_id'], 'db' => $e['db'], 'uj' => $e['uj']];
}

/** Banki kivonatok listája (kereshető), darabszámmal és összegekkel */
function act_bank_kivonatok(array $be): array
{
    csak_bejelentkezve();
    $q = trim((string)($be['q'] ?? ''));
    $cegId = be_int($be, 'ceg_id', false);
    $w = [];
    $p = [];
    if ($q !== '') {
        $w[] = '(bk.azonosito LIKE ? OR bk.azonosito_norm LIKE ?)';
        $p[] = '%' . $q . '%';
        $p[] = '%' . norm_azonosito($q) . '%';
    }
    if ($cegId !== null) {
        $w[] = 'EXISTS (SELECT 1 FROM kimeno_szamlak s2 WHERE s2.banki_kivonat_id = bk.id AND s2.ceg_id = ?)';
        $p[] = $cegId;
    }
    $sorok = db_all(
        'SELECT bk.id, bk.azonosito, bk.datum, bk.letrehozva,
                (SELECT COUNT(*) FROM kimeno_szamlak s WHERE s.banki_kivonat_id = bk.id) AS db,
                COALESCE((SELECT SUM(s.osszeg - COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r WHERE r.irany = "KIMENO" AND r.szamla_id = s.id), 0)) FROM kimeno_szamlak s WHERE s.banki_kivonat_id = bk.id AND s.penznem = "HUF"), 0) AS osszeg_HUF,
                COALESCE((SELECT SUM(s.osszeg - COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r WHERE r.irany = "KIMENO" AND r.szamla_id = s.id), 0)) FROM kimeno_szamlak s WHERE s.banki_kivonat_id = bk.id AND s.penznem = "EUR"), 0) AS osszeg_EUR,
                (SELECT GROUP_CONCAT(DISTINCT c.nev ORDER BY c.nev SEPARATOR ", ") FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id WHERE s.banki_kivonat_id = bk.id) AS cegek
           FROM banki_kivonatok bk ' . ($w ? 'WHERE ' . implode(' AND ', $w) . ' ' : '') . 'ORDER BY bk.datum DESC, bk.id DESC LIMIT 500',
        $p
    );
    foreach ($sorok as &$s) {
        $s['id'] = (int)$s['id'];
        $s['db'] = (int)$s['db'];
        $s['osszeg_HUF'] = (float)$s['osszeg_HUF'];
        $s['osszeg_EUR'] = (float)$s['osszeg_EUR'];
    }
    naplo('BANK_KIVONATOK', 'banki kivonatok listája megtekintve' . ($q !== '' ? " (keresés: $q)" : ''));
    return ['kivonatok' => $sorok];
}

/** Egy banki kivonat által lefedett kimenő számlák */
function act_bank_kivonat(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id');
    $kiv = db_row('SELECT id, azonosito, datum, letrehozva FROM banki_kivonatok WHERE id = ?', [$id]);
    if (!$kiv) {
        hiba('A banki kivonat nem található.');
    }
    $kiv['id'] = (int)$kiv['id'];
    naplo('BANK_KIVONAT', "banki kivonat „{$kiv['azonosito']}” megtekintve");
    return ['kivonat' => $kiv, 'szamlak' => banki_kivonat_szamlai($id)];
}

/** Fizetés visszavonása (Irodavezető / Admin): számla vissza NYITOTT-ba */
function act_kimeno_fizetes_visszavon(array $be): array
{
    $u = csak_jog('torol');
    $id = be_int($be, 'id');
    $s = db_row(KIMENO_SQL . 'WHERE s.id = ?', [$id]);
    if (!$s) {
        hiba('A számla nem található.');
    }
    if ($s['statusz'] !== 'FIZETVE') {
        hiba('A számla nem FIZETVE státuszú.');
    }
    db_tx(function () use ($id, $s, $u) {
        db_exec('UPDATE kimeno_szamlak SET statusz = "NYITOTT", banki_kivonat_id = NULL, fizetve_datum = NULL, modositotta = ? WHERE id = ?', [$u['id'], $id]);
        audit_ir('KIMENO', $id, 'STATUSZ', 'Fizetés visszavonva – a számla újra NYITOTT',
            [['m' => 'Státusz', 'r' => 'FIZETVE', 'u' => 'NYITOTT'], ['m' => 'Fizetés dátuma', 'r' => audit_ertek('fizetve_datum', $s['fizetve_datum']), 'u' => '–'], ['m' => 'Banki azonosító', 'r' => (string)($s['banki_azonosito'] ?? '–'), 'u' => '–']], null, $u);
        // ha a kivonathoz már nem tartozik számla, a kivonat-rekord törlődik
        if ($s['banki_kivonat_id'] && (int)db_val('SELECT COUNT(*) FROM kimeno_szamlak WHERE banki_kivonat_id = ?', [$s['banki_kivonat_id']]) === 0) {
            db_exec('DELETE FROM banki_kivonatok WHERE id = ?', [$s['banki_kivonat_id']]);
        }
    });
    naplo('KIMENO_FIZETES_VISSZAVON', "kimenő számla {$s['kod']} fizetése visszavonva (" . szerep_nev($u['szerep']) . "), banki azonosító: „{$s['banki_azonosito']}”");
    return ['visszavonva' => true];
}
