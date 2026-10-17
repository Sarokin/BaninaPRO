<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
//  KIMENŐ SZÁMLÁK
// ---------------------------------------------------------------------------

require_once __DIR__ . '/api_reszteljesites.php';
require_once __DIR__ . '/audit.php';

/* Beszámítás (1.25/1.27): a függő (nyitott utalásokban lévő) beszámítások – egy számla több utalásban is lehet (beszamitas_uid: vesszővel
   elválasztva, beszamitas_osszeg: együtt) –, és a már teljesült (UTALVA) utalások, amelyekben a számlát beszámították */
const KIMENO_BESZAMITAS_MEZOK = '
        rkf.fuggo_uid AS beszamitas_uid, rkf.fuggo_utalas_id AS beszamitas_utalas_id, COALESCE(rkf.fuggo, 0) AS beszamitas_osszeg,
        (SELECT GROUP_CONCAT(ut.uid ORDER BY ut.id SEPARATOR ", ") FROM beszamitasok bz JOIN utalasok ut ON ut.id = bz.utalas_id WHERE bz.kimeno_id = s.id AND ut.statusz = "UTALVA") AS beszamitva_uid,';
const KIMENO_SQL = 'SELECT s.*, c.nev AS ceg_nev, bk.azonosito AS banki_azonosito, bk.datum AS banki_datum,
                           fl.felhasznalonev AS letrehozta_nev, fm.felhasznalonev AS modositotta_nev,' . KIMENO_BESZAMITAS_MEZOK . RESZT_KIMENO_MEZOK . '
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
    $s['fuggo'] = (float)($s['fuggo'] ?? 0);
    $s['fuggo_db'] = (int)($s['fuggo_db'] ?? 0);
    $s['hatralek'] = array_key_exists('hatralek', $s) ? (float)$s['hatralek'] : round($s['osszeg'] - $s['reszt'] - $s['fuggo'], 2);   // a még szabad rész (1.27)
    if (array_key_exists('beszamitas_utalas_id', $s)) {
        $s['beszamitas_utalas_id'] = $s['beszamitas_utalas_id'] === null ? null : (int)$s['beszamitas_utalas_id'];
        $s['beszamitas_osszeg'] = $s['beszamitas_uid'] === null ? null : (float)$s['beszamitas_osszeg'];
    }
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
                SUM(CASE WHEN s.statusz = "NYITOTT" THEN COALESCE(rkf.fuggo, 0) ELSE 0 END) AS beszamitasra_adva,
                SUM(CASE WHEN s.statusz = "NYITOTT" THEN 1 ELSE 0 END) AS nyitott_db,
                SUM(s.osszeg) AS osszes
           FROM kimeno_szamlak s' . RESZT_KIMENO_JOIN . 'WHERE s.ceg_id = ? GROUP BY s.penznem',
        [$cegId]
    );
    $ures = ['nyitott' => 0.0, 'fizetve' => 0.0, 'reszt' => 0.0, 'beszamitasra_adva' => 0.0, 'osszes' => 0.0, 'nyitott_db' => 0];
    $tartozas = ['HUF' => $ures, 'EUR' => $ures];
    foreach ($ossz as $o) {
        $tartozas[$o['penznem']] = ['nyitott' => (float)$o['nyitott'], 'fizetve' => (float)$o['fizetve'], 'reszt' => (float)$o['reszt'], 'beszamitasra_adva' => (float)$o['beszamitasra_adva'], 'osszes' => (float)$o['osszes'], 'nyitott_db' => (int)$o['nyitott_db']];
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
    $fuggo = !empty($s['beszamitas_uid']);   // beszámításra adva (nyitott utalásban): az összeg az utalást érintené – csak a megjegyzés
    $korlatozott = $s['statusz'] === 'FIZETVE' || !jog_van('ir', $u) || $fuggo;   // fizetett számla VAGY Üzletkötő VAGY beszámításra adva: csak a megjegyzés
    if ($korlatozott) {
        $diff = audit_diff($s, ['megjegyzes' => $megj], ['megjegyzes']);
        if ($diff) {
            db_exec('UPDATE kimeno_szamlak SET megjegyzes = ?, megjegyzes_irta = ?, modositotta = ? WHERE id = ?', [$megj, $mj['irta'], $u['id'], $id]);
            audit_ir('KIMENO', $id, 'MODOSIT', "Kimenő számla módosítva: {$s['kod']} (" . ($s['statusz'] === 'FIZETVE' ? 'fizetve' : ($fuggo ? 'beszámításra adva' : 'üzletkötő')) . ' – csak megjegyzés)', $diff, null, $u);
        }
        naplo('KIMENO_MODOSIT', "kimenő számla {$s['kod']} (" . ($s['statusz'] === 'FIZETVE' ? 'fizetve' : ($fuggo ? 'beszámításra adva' : 'üzletkötő')) . '): megjegyzés módosítva');
        return ['modositva' => true, 'korlatozott' => true, 'ok' => $fuggo ? 'beszámításra adva: ' . $s['beszamitas_uid'] : null];
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
    if (!empty($s['beszamitas_uid']) || !empty($s['beszamitva_uid'])) {
        hiba("A számla beszámításra van adva egy utalásban (" . ($s['beszamitas_uid'] ?: $s['beszamitva_uid']) . ') – előbb vedd ki az utalásból (a számla sorában a piros X), aztán törölhető.');
    }
    db_exec('DELETE FROM kimeno_szamlak WHERE id = ?', [$id]);
    audit_ir('KIMENO', $id, 'TOROL', "Kimenő számla törölve: {$s['kod']} „{$s['szamlaszam']}” (" . fmt_osszeg($s['osszeg']) . " {$s['penznem']})");
    naplo('KIMENO_TOROL', "kimenő számla törölve: {$s['kod']} („{$s['szamlaszam']}”, " . fmt_osszeg($s['osszeg']) . " {$s['penznem']})");
    return ['torolve' => true];
}

// ---------------------------------------------------------------------------
//  BANKI KIVONAT azonosítók
// ---------------------------------------------------------------------------

/** SQL-feltétel (s = kimeno_szamlak, bk = banki_kivonatok): a számlát ez a kivonat fedezi – egészében (FIZETVE vele) vagy
    részösszeggel (1.29: hozzá kötött részteljesítés) */
const KIVONAT_SZAMLAI = '(s.banki_kivonat_id = bk.id OR s.id IN (SELECT r.szamla_id FROM reszteljesitesek r WHERE r.irany = "KIMENO" AND r.banki_kivonat_id = bk.id))';

/**
 * Egy kivonat által fedezett kimenő számlák. fedezett = amennyit ez a kivonat fedez (FIZETVE-nél a végső hátralék,
 * részösszegnél a hozzá kötött részteljesítés); reszben = csak részösszeggel (a számla nyitott maradt vagy később más rendezte).
 */
function banki_kivonat_szamlai(int $kivonatId): array
{
    $sorok = db_all(KIMENO_SQL . 'WHERE s.banki_kivonat_id = ? OR s.id IN (SELECT r.szamla_id FROM reszteljesitesek r WHERE r.irany = "KIMENO" AND r.banki_kivonat_id = ?) ORDER BY s.kod', [$kivonatId, $kivonatId]);
    $resz = [];
    foreach (db_all('SELECT szamla_id, SUM(osszeg) AS o FROM reszteljesitesek WHERE irany = "KIMENO" AND banki_kivonat_id = ? GROUP BY szamla_id', [$kivonatId]) as $r) {
        $resz[(int)$r['szamla_id']] = (float)$r['o'];
    }
    $ki = reszt_csatol(array_map('kimeno_sor_feldolgoz', $sorok), 'KIMENO');
    foreach ($ki as &$s) {
        $teljes = $s['banki_kivonat_id'] === $kivonatId;
        $s['fedezett'] = round(($teljes ? $s['hatralek'] : 0) + ($resz[$s['id']] ?? 0), 2);
        $s['reszben'] = !$teljes;
    }
    unset($s);
    return $ki;
}

/** A kivonat-rekord törlése, ha már semmit nem fedez (se FIZETVE számla, se részteljesítés nem tartozik hozzá) */
function bank_kivonat_ures_torol(?int $kivonatId): void
{
    if ($kivonatId && db_val('SELECT 1 FROM kimeno_szamlak WHERE banki_kivonat_id = ? LIMIT 1', [$kivonatId]) === null
        && db_val('SELECT 1 FROM reszteljesitesek WHERE banki_kivonat_id = ? LIMIT 1', [$kivonatId]) === null) {
        db_exec('DELETE FROM banki_kivonatok WHERE id = ?', [$kivonatId]);
    }
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
 * A bankok, amelyekhez a kimenő számlák jóváírása rendelhető (1.28): megjelenített név => a generált azonosító előtagja.
 * A sorszám bankonként, pénznemenként és évenként fut (sorszamok.tipus = 'BANK_' . előtag): új bankhoz a sorszamok.tipus
 * ENUM-ot is bővíteni kell (schema.sql + frissítő). Az app.js BANKOK listája ugyanez.
 */
const BANKOK = ['TATRA BANKA' => 'TATRA', 'UNICREDIT' => 'UNICREDIT', 'RAIFFEISEN' => 'RAIFFEISEN'];

/**
 * A következő szabad generált banki azonosító (bank + pénznem + év). $foglal = true: a sorszámot le is foglalja (csak
 * tranzakcióban, a hozzárendeléskor); false: csak előnézet a képernyőre, semmit nem ír. A korábban kézzel beírt, véletlenül
 * egyező azonosítót átugorja.
 */
function bank_kovetkezo_azonosito(string $elotag, string $penznem, int $ev, bool $foglal): string
{
    $tipus = 'BANK_' . $elotag;
    $n = $foglal ? kovetkezo_sorszam($tipus, $ev, $penznem)
        : (int)(db_val('SELECT utolso FROM sorszamok WHERE tipus = ? AND ev = ? AND penznem = ?', [$tipus, $ev, $penznem]) ?? 0) + 1;
    for ($i = 0; $i < 1000; $i++) {
        $az = bank_azonosito($elotag, $penznem, $ev, $n);
        if (db_val('SELECT id FROM banki_kivonatok WHERE azonosito_norm = ?', [norm_azonosito($az)]) === null) {
            return $az;
        }
        $n = $foglal ? kovetkezo_sorszam($tipus, $ev, $penznem) : $n + 1;
    }
    hiba('Nem sikerült szabad banki azonosítót generálni.');
}

/** A bankhoz adandó kimenő számlák zárolva és ellenőrizve (NYITOTT, nincs nyitott utalásban beszámításra adva) */
function bank_szamlak_zarolva(array $ids): array
{
    $in = implode(',', array_fill(0, count($ids), '?'));
    $szamlak = db_all("SELECT s.id, s.kod, s.szamlaszam, s.statusz, s.osszeg, s.penznem, COALESCE(rk.reszt, 0) AS reszt,
                              (SELECT ut.uid FROM beszamitasok bz JOIN utalasok ut ON ut.id = bz.utalas_id WHERE bz.kimeno_id = s.id AND ut.statusz = 'NYITOTT' LIMIT 1) AS beszamitas_uid
                         FROM kimeno_szamlak s" . RESZT_KIMENO_JOIN . "WHERE s.id IN ($in) FOR UPDATE", $ids);
    if (count($szamlak) !== count($ids)) {
        hiba('Valamelyik számla nem található.');
    }
    foreach ($szamlak as $s) {
        if ($s['statusz'] !== 'NYITOTT') {
            hiba("A(z) {$s['kod']} számla már FIZETVE státuszú.");
        }
        if (!empty($s['beszamitas_uid'])) {
            hiba("A(z) {$s['kod']} számla beszámításra van adva az utalásban ({$s['beszamitas_uid']}) – vagy az utalás teljesítésével rendeződik, vagy előbb vedd ki az utalásból.");
        }
    }
    return $szamlak;
}

/** A számlák egy kivonathoz: FIZETVE a megadott nappal + változásnapló; vissza: a naplóba írható kódok (hátralékkal) */
function bank_szamlak_fizetve(array $szamlak, int $kivId, string $datum, string $az, string $leiras, array $u): array
{
    $ids = array_map(fn(array $s): int => (int)$s['id'], $szamlak);
    $in = implode(',', array_fill(0, count($ids), '?'));
    db_exec("UPDATE kimeno_szamlak SET statusz = 'FIZETVE', banki_kivonat_id = ?, fizetve_datum = ?, modositotta = ? WHERE id IN ($in)", array_merge([$kivId, $datum, $u['id']], $ids));
    $kodok = [];
    foreach ($szamlak as $s) {
        $hatralek = fmt_osszeg((float)$s['osszeg'] - (float)$s['reszt']) . " {$s['penznem']}";
        audit_ir('KIMENO', (int)$s['id'], 'STATUSZ', "Fizetve – $leiras: „{$az}”" . ((float)$s['reszt'] > 0 ? " (a hátralék: $hatralek)" : ''),
            [['m' => 'Státusz', 'r' => 'NYITOTT', 'u' => 'FIZETVE'], ['m' => 'Fizetés dátuma', 'r' => '–', 'u' => audit_ertek('fizetve_datum', $datum)], ['m' => 'Banki azonosító', 'r' => '–', 'u' => $az]], null, $u);
        $kodok[] = "{$s['kod']} ($hatralek" . ((float)$s['reszt'] > 0 ? ', részteljesítés után' : '') . ')';
    }
    return $kodok;
}

/**
 * Részösszeg bankhoz adással (1.29): részteljesítés a jóváírás napjával és a generált azonosítóval, a kivonathoz kötve –
 * a számla NYITOTT marad a maradékkal, azt később egy másik bankhoz adás fedezi. Vissza: a naplóba írható kód.
 */
function bank_szamla_reszben(array $s, float $osszeg, int $kivId, string $datum, string $az, string $bank, array $u): string
{
    db_exec('INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito, banki_kivonat_id, letrehozta) VALUES ("KIMENO", ?, ?, ?, ?, ?, ?)',
        [(int)$s['id'], number_format($osszeg, 2, '.', ''), $datum, $az, $kivId, $u['id']]);
    db_exec('UPDATE kimeno_szamlak SET modositotta = ? WHERE id = ?', [$u['id'], (int)$s['id']]);
    $regi = round((float)$s['osszeg'] - (float)$s['reszt'], 2);
    $marad = round($regi - $osszeg, 2);
    audit_ir('KIMENO', (int)$s['id'], 'RESZT', 'Részösszeg bankhoz adással: ' . fmt_osszeg($osszeg) . " {$s['penznem']} (" . audit_ertek('kelt', $datum) . ", $bank, banki azonosító: $az) – a számla NYITOTT marad",
        [['m' => 'Hátralék', 'r' => fmt_osszeg($regi) . " {$s['penznem']}", 'u' => fmt_osszeg($marad) . " {$s['penznem']}"]], null, $u);
    return "{$s['kod']} (részben " . fmt_osszeg($osszeg) . " {$s['penznem']}, maradék " . fmt_osszeg($marad) . ')';
}

/**
 * A bankhoz adás számlánkénti összege (1.29, osszegek = {szamla_id: összeg}): hiányzik vagy egyenlő a hátralékkal → null
 * (egészében, FIZETVE); kisebb → a részösszeg. Több a hátraléknál, nulla vagy negatív: hiba. Jóváíró (nem pozitív hátralékú)
 * számla csak egészében adható bankhoz.
 */
function bank_szamla_osszeg(array $osszegek, array $s): ?float
{
    $kulcs = (string)$s['id'];
    if (!array_key_exists($kulcs, $osszegek) || trim((string)$osszegek[$kulcs]) === '') {
        return null;
    }
    $hatralek = round((float)$s['osszeg'] - (float)$s['reszt'], 2);
    $o = (float)be_osszeg($osszegek, $kulcs, true, "a(z) {$s['kod']} számla összege");
    if (abs($o - $hatralek) < 0.005) {
        return null;
    }
    if ($hatralek <= 0) {
        hiba("A(z) {$s['kod']} jóváíró számla csak a teljes összegével (" . fmt_osszeg($hatralek) . " {$s['penznem']}) adható bankhoz.");
    }
    if ($o <= 0) {
        hiba("A(z) {$s['kod']} számlánál a bankhoz adott összeg pozitív szám legyen.");
    }
    if ($o > $hatralek) {
        hiba("A(z) {$s['kod']} számlánál a bankhoz adott összeg (" . fmt_osszeg($o) . " {$s['penznem']}) több, mint a hátraléka (" . fmt_osszeg($hatralek) . " {$s['penznem']}).");
    }
    return $o;
}

/** Pénznemenkénti csoportok, EUR elöl (pénznem => számlasorok) */
function bank_penznem_csoportok(array $szamlak): array
{
    $cs = [];
    foreach ($szamlak as $s) {
        $cs[$s['penznem']][] = $s;
    }
    uksort($cs, fn(string $a, string $b): int => [$a !== 'EUR', $a] <=> [$b !== 'EUR', $b]);
    return $cs;
}

/**
 * Előnézet a bankhoz adás ablakához (1.28): a számlák pénzneme(i) és pénznemenként a következő generált azonosító.
 * Csak olvas, semmit nem foglal: a végleges azonosító a hozzárendeléskor születik (ha közben más elvitte ezt a számot,
 * a következőt kapja – a kész ablak a véglegeset mutatja).
 */
function act_bank_azonosito_javaslat(array $be): array
{
    csak_bejelentkezve();
    $ids = be_int_lista($be, 'szamla_ids', 'számlák');
    $datum = be_datum($be, 'datum', false, 'dátum') ?? date('Y-m-d');
    $bank = be_valaszt($be, 'bank', array_keys(BANKOK), '', 'bank');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $szamlak = db_all("SELECT s.id, s.kod, s.szamlaszam, s.penznem, s.osszeg, c.nev AS ceg_nev, COALESCE(rk.reszt, 0) AS reszt
                         FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id" . RESZT_KIMENO_JOIN . "WHERE s.id IN ($in) AND s.statusz = 'NYITOTT' ORDER BY c.nev, s.kod", $ids);
    $ki = [];
    foreach (bank_penznem_csoportok($szamlak) as $pn => $sorok) {
        $lista = array_map(fn(array $s): array => ['id' => (int)$s['id'], 'kod' => $s['kod'], 'szamlaszam' => $s['szamlaszam'], 'ceg_nev' => $s['ceg_nev'],
            'osszeg' => (float)$s['osszeg'], 'reszt' => (float)$s['reszt'], 'hatralek' => round((float)$s['osszeg'] - (float)$s['reszt'], 2)], $sorok);
        $ki[] = ['penznem' => $pn, 'db' => count($sorok), 'osszeg' => round(array_sum(array_column($lista, 'hatralek')), 2), 'szamlak' => $lista,
                 'azonosito' => $bank !== '' ? bank_kovetkezo_azonosito(BANKOK[$bank], $pn, (int)substr($datum, 0, 4), false) : null];
    }
    return ['bank' => $bank, 'datum' => $datum, 'csoportok' => $ki];
}

/**
 * Kimenő számlák bankhoz adása → FIZETVE.
 * bank (1.28, a felület így hívja): a bank a BANKOK közül, az azonosítót a program generálja – pénznemenként egy új kivonat
 *   (TATRA-EUR-2026-000001), a dátum a jóváírás napja, az év is ebből. osszegek (1.29, nem kötelező): {szamla_id: összeg} –
 *   a hátraléknál kisebb összegből részteljesítés lesz a kivonathoz kötve (a számla NYITOTT marad), különben FIZETVE.
 *   Vissza: azonositok = [{penznem, azonosito, db, egeszben, reszben, osszeg}].
 * azonosito (régi, kézzel beírt mód – az API-ban megmaradt): ha az azonosító már használt és nincs `megerositve`,
 *   DUPLA kóddal visszadobja.
 */
function act_bank_hozzarendel(array $be): array
{
    $u = csak_bejelentkezve();
    $ids = be_int_lista($be, 'szamla_ids', 'számlák');
    $datum = be_datum($be, 'datum', true, 'dátum');
    $bank = be_valaszt($be, 'bank', array_keys(BANKOK), '', 'bank');
    if ($bank !== '') {
        $osszegek = is_array($be['osszegek'] ?? null) ? $be['osszegek'] : [];
        $csop = db_tx(function () use ($ids, $datum, $bank, $osszegek, $u) {
            $ki = [];
            foreach (bank_penznem_csoportok(bank_szamlak_zarolva($ids)) as $pn => $sorok) {
                // előbb minden összeg ellenőrzése, hogy hiba esetén sorszám se fogyjon
                $egesz = [];
                $resz = [];
                $jovairas = 0.0;
                foreach ($sorok as $s) {
                    $o = bank_szamla_osszeg($osszegek, $s);
                    if ($o === null) {
                        $egesz[] = $s;
                        $jovairas += (float)$s['osszeg'] - (float)$s['reszt'];
                    } else {
                        $resz[] = [$s, $o];
                        $jovairas += $o;
                    }
                }
                $az = bank_kovetkezo_azonosito(BANKOK[$bank], $pn, (int)substr($datum, 0, 4), true);
                db_exec('INSERT INTO banki_kivonatok (azonosito, azonosito_norm, datum, letrehozta) VALUES (?, ?, ?, ?)', [$az, norm_azonosito($az), $datum, $u['id']]);
                $kivId = (int)db()->lastInsertId();
                $kodok = $egesz ? bank_szamlak_fizetve($egesz, $kivId, $datum, $az, "$bank, banki azonosító", $u) : [];
                foreach ($resz as [$s, $o]) {
                    $kodok[] = bank_szamla_reszben($s, $o, $kivId, $datum, $az, $bank, $u);
                }
                $ki[] = ['penznem' => $pn, 'azonosito' => $az, 'kivonat_id' => $kivId, 'db' => count($sorok), 'egeszben' => count($egesz),
                         'reszben' => count($resz), 'osszeg' => round($jovairas, 2), 'kodok' => $kodok];
            }
            return $ki;
        });
        foreach ($csop as $c) {
            naplo('BANK_HOZZARENDEL', "$bank, generált banki azonosító „{$c['azonosito']}” ($datum, " . fmt_osszeg($c['osszeg']) . " {$c['penznem']}) → "
                . "{$c['egeszben']} kimenő számla FIZETVE, {$c['reszben']} részben: " . implode(', ', $c['kodok']));
        }
        return ['kivonat_id' => $csop[0]['kivonat_id'], 'db' => count($ids), 'uj' => true, 'bank' => $bank,
                'azonositok' => array_map(fn(array $c): array => ['penznem' => $c['penznem'], 'azonosito' => $c['azonosito'], 'db' => $c['db'],
                    'egeszben' => $c['egeszben'], 'reszben' => $c['reszben'], 'osszeg' => $c['osszeg']], $csop)];
    }

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
        $kodok = bank_szamlak_fizetve(bank_szamlak_zarolva($ids), $kivId, $datum, $az, 'bankkivonati azonosító', $u);
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
        $w[] = 'EXISTS (SELECT 1 FROM kimeno_szamlak s WHERE ' . KIVONAT_SZAMLAI . ' AND s.ceg_id = ?)';
        $p[] = $cegId;
    }
    // összeg pénznemenként = a vele FIZETVE számlák végső hátraléka + a hozzá kötött részösszegek (1.29)
    $osszeg = fn(string $pn): string => 'COALESCE((SELECT SUM(s.osszeg - COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r WHERE r.irany = "KIMENO" AND r.szamla_id = s.id), 0)) FROM kimeno_szamlak s WHERE s.banki_kivonat_id = bk.id AND s.penznem = "' . $pn . '"), 0)'
        . ' + COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r JOIN kimeno_szamlak s ON s.id = r.szamla_id WHERE r.irany = "KIMENO" AND r.banki_kivonat_id = bk.id AND s.penznem = "' . $pn . '"), 0)';
    $sorok = db_all(
        'SELECT bk.id, bk.azonosito, bk.datum, bk.letrehozva,
                (SELECT COUNT(*) FROM kimeno_szamlak s WHERE ' . KIVONAT_SZAMLAI . ') AS db,
                ' . $osszeg('HUF') . ' AS osszeg_HUF,
                ' . $osszeg('EUR') . ' AS osszeg_EUR,
                (SELECT GROUP_CONCAT(DISTINCT c.nev ORDER BY c.nev SEPARATOR ", ") FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id WHERE ' . KIVONAT_SZAMLAI . ') AS cegek
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
    if ($s['banki_kivonat_id'] === null && !empty($s['beszamitva_uid'])) {
        hiba("A számla beszámítással lett fizetve ({$s['beszamitva_uid']}) – a fizetés az utalás visszanyitásával vonható vissza (az utalás oldalán).");
    }
    db_tx(function () use ($id, $s, $u) {
        db_exec('UPDATE kimeno_szamlak SET statusz = "NYITOTT", banki_kivonat_id = NULL, fizetve_datum = NULL, modositotta = ? WHERE id = ?', [$u['id'], $id]);
        audit_ir('KIMENO', $id, 'STATUSZ', 'Fizetés visszavonva – a számla újra NYITOTT',
            [['m' => 'Státusz', 'r' => 'FIZETVE', 'u' => 'NYITOTT'], ['m' => 'Fizetés dátuma', 'r' => audit_ertek('fizetve_datum', $s['fizetve_datum']), 'u' => '–'], ['m' => 'Banki azonosító', 'r' => (string)($s['banki_azonosito'] ?? '–'), 'u' => '–']], null, $u);
        // ha a kivonat már semmit nem fedez (számlát sem, részösszeget sem), a kivonat-rekord törlődik
        bank_kivonat_ures_torol($s['banki_kivonat_id'] === null ? null : (int)$s['banki_kivonat_id']);
    });
    naplo('KIMENO_FIZETES_VISSZAVON', "kimenő számla {$s['kod']} fizetése visszavonva (" . szerep_nev($u['szerep']) . "), banki azonosító: „{$s['banki_azonosito']}”");
    return ['visszavonva' => true];
}
