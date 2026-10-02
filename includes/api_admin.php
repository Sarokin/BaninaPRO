<?php
declare(strict_types=1);

/** Szerep beolvasása (admin / irodavezeto / rogzito / uzletkoto; a régi 'user' = irodavezeto) */
function admin_szerep_be($ertek): string
{
    $sz = mb_strtolower(trim((string)$ertek));
    if ($sz === 'user') {
        $sz = 'irodavezeto';
    }
    if (!isset(SZEREPEK[$sz])) {
        hiba('Ismeretlen szerepkör. Választható: Admin, Irodavezető, Rögzítő, Üzletkötő.');
    }
    return $sz;
}

/** Felhasználók listája (csak admin) */
function act_admin_felhasznalok(array $be): array
{
    csak_admin();
    $lista = db_all('SELECT f.id, f.felhasznalonev, f.nev, f.szerep, f.aktiv, f.letrehozva, f.utolso_belepes, (SELECT COUNT(*) FROM passkeyek p WHERE p.felhasznalo_id = f.id AND p.aktiv = 1) AS passkey_db, (f.jelszo_hash IS NOT NULL) AS van_jelszo FROM felhasznalok f ORDER BY FIELD(f.szerep, "admin", "irodavezeto", "rogzito", "uzletkoto"), f.felhasznalonev');
    foreach ($lista as &$u) {
        $u['id'] = (int)$u['id'];
        $u['szerep_nev'] = szerep_nev($u['szerep']);
        $u['aktiv'] = (int)$u['aktiv'] === 1;
        $u['passkey_db'] = (int)$u['passkey_db'];
        $u['van_jelszo'] = (int)$u['van_jelszo'] === 1;
    }
    naplo('ADMIN_FELHASZNALOK', 'lista megtekintve');
    return ['felhasznalok' => $lista];
}

/** Új felhasználó (csak admin) */
function act_admin_felhasznalo_letrehoz(array $be): array
{
    $admin = csak_admin();
    $nev  = be_szoveg($be, 'felhasznalonev', 64, true, 'felhasználónév');
    $nev  = mb_strtolower($nev);
    if (!preg_match('/^[a-z0-9._-]{3,64}$/', $nev)) {
        hiba('A felhasználónév 3–64 karakter, csak kisbetű, szám, pont, kötőjel, aláhúzás.');
    }
    $teljes = be_szoveg($be, 'nev', 128, false, 'név') ?? '';
    $jelszo = (string)($be['jelszo'] ?? '');
    jelszo_ellenorzes($jelszo);
    $szerep = admin_szerep_be($be['szerep'] ?? 'rogzito');
    db_exec(
        'INSERT INTO felhasznalok (felhasznalonev, nev, jelszo_hash, szerep, aktiv, letrehozta) VALUES (?, ?, ?, ?, 1, ?)',
        [$nev, $teljes, password_hash($jelszo, PASSWORD_BCRYPT), $szerep, $admin['id']]
    );
    $id = (int)db()->lastInsertId();
    naplo('ADMIN_FELHASZNALO_LETREHOZ', "új felhasználó: $nev (#$id, szerep=$szerep)");
    return ['id' => $id];
}

/** Felhasználó módosítása: név, szerep, aktív, új jelszó (csak admin) */
function act_admin_felhasznalo_modosit(array $be): array
{
    $admin = csak_admin();
    $id = be_int($be, 'id');
    $u = db_row('SELECT * FROM felhasznalok WHERE id = ?', [$id]);
    if (!$u) {
        hiba('A felhasználó nem található.');
    }
    $valtozas = [];
    if (array_key_exists('nev', $be)) {
        $teljes = be_szoveg($be, 'nev', 128, false, 'név') ?? '';
        db_exec('UPDATE felhasznalok SET nev = ? WHERE id = ?', [$teljes, $id]);
        $valtozas[] = "név=$teljes";
    }
    if (array_key_exists('szerep', $be)) {
        $szerep = admin_szerep_be($be['szerep']);
        if ($id === (int)$admin['id'] && $szerep !== 'admin') {
            hiba('A saját admin jogodat nem veheted el.');
        }
        db_exec('UPDATE felhasznalok SET szerep = ? WHERE id = ?', [$szerep, $id]);
        $valtozas[] = "szerep=$szerep";
    }
    if (array_key_exists('aktiv', $be)) {
        $aktiv = be_bool($be, 'aktiv') ? 1 : 0;
        if ($id === (int)$admin['id'] && !$aktiv) {
            hiba('Saját magadat nem tilthatod le.');
        }
        db_exec('UPDATE felhasznalok SET aktiv = ? WHERE id = ?', [$aktiv, $id]);
        $valtozas[] = 'aktív=' . ($aktiv ? 'igen' : 'nem');
    }
    if (!empty($be['uj_jelszo'])) {
        $uj = (string)$be['uj_jelszo'];
        jelszo_ellenorzes($uj);
        db_exec('UPDATE felhasznalok SET jelszo_hash = ? WHERE id = ?', [password_hash($uj, PASSWORD_BCRYPT), $id]);
        $valtozas[] = 'jelszó visszaállítva';
    }
    naplo('ADMIN_FELHASZNALO_MODOSIT', "felhasználó #{$id} ({$u['felhasznalonev']}): " . implode(', ', $valtozas));
    return ['modositva' => true];
}

/** Beállítások lekérése (csak admin) */
function act_admin_beallitasok(array $be): array
{
    csak_admin();
    $sorok = db_all('SELECT kulcs, ertek, modositva FROM beallitasok');
    $ki = [];
    foreach ($sorok as $s) {
        $ki[$s['kulcs']] = ['ertek' => $s['ertek'], 'modositva' => $s['modositva']];
    }
    return ['beallitasok' => $ki, 'arfolyam' => arfolyam_eur_huf()];
}

/** Beállítás mentése (csak admin) – jelenleg: eur_huf_kezi */
function act_admin_beallitas_ment(array $be): array
{
    csak_admin();
    $kulcs = be_szoveg($be, 'kulcs', 64);
    if (!in_array($kulcs, ['eur_huf_kezi', 'import_mentes', 'reszt_bejovo'], true)) {
        hiba('Ismeretlen beállítás.');
    }
    $ertek = $be['ertek'] ?? null;
    if ($kulcs === 'import_mentes' || $kulcs === 'reszt_bejovo') {
        $ertek = be_bool($be, 'ertek') ? '1' : '0';
        db_exec('INSERT INTO beallitasok (kulcs, ertek) VALUES (?, ?) ON DUPLICATE KEY UPDATE ertek = VALUES(ertek)', [$kulcs, $ertek]);
        naplo('ADMIN_BEALLITAS', ($kulcs === 'import_mentes' ? 'import előtti DB-mentés: ' : 'részteljesítés a bejövő számláknál: ') . ($ertek === '1' ? 'BEKAPCSOLVA' : 'KIKAPCSOLVA'));
        return ['mentve' => true, 'beallitasok' => beallitasok_publikus()];
    }
    if ($ertek !== null && trim((string)$ertek) !== '') {
        $ertek = str_replace([' ', ','], ['', '.'], (string)$ertek);
        if (!is_numeric($ertek) || (float)$ertek <= 0) {
            hiba('Az árfolyam pozitív szám legyen (pl. 395,5).');
        }
        $ertek = number_format((float)$ertek, 4, '.', '');
    } else {
        $ertek = null;
    }
    db_exec('INSERT INTO beallitasok (kulcs, ertek) VALUES (?, ?) ON DUPLICATE KEY UPDATE ertek = VALUES(ertek)', [$kulcs, $ertek]);
    naplo('ADMIN_BEALLITAS', "$kulcs = " . ($ertek ?? 'üres'));
    return ['mentve' => true];
}

/** Naplófájlok listája (csak admin) */
function act_admin_naplo_fajlok(array $be): array
{
    csak_admin();
    $fajlok = [];
    foreach (glob(rtrim(LOG_DIR, '/') . '/*.txt') ?: [] as $f) {
        $fajlok[] = ['nev' => basename($f), 'meret' => filesize($f), 'modositva' => date('Y-m-d H:i:s', filemtime($f))];
    }
    usort($fajlok, fn($a, $b) => strcmp($b['nev'], $a['nev']));
    return ['fajlok' => $fajlok];
}

/** Egy naplófájl utolsó N sora (csak admin) */
function act_admin_naplo_olvas(array $be): array
{
    csak_admin();
    $nev = be_szoveg($be, 'fajl', 32);
    if (!preg_match('/^\d{8}\.txt$/', $nev)) {
        hiba('Érvénytelen fájlnév.');
    }
    $sorok = be_int($be, 'sorok', false) ?? 300;
    $sorok = max(10, min(5000, $sorok));
    $ut = rtrim(LOG_DIR, '/') . '/' . $nev;
    if (!is_file($ut)) {
        hiba('A naplófájl nem található.');
    }
    $tartalom = file($ut, FILE_IGNORE_NEW_LINES) ?: [];
    $osszes = count($tartalom);
    $tartalom = array_slice($tartalom, -$sorok);
    naplo('ADMIN_NAPLO_OLVAS', "$nev (utolsó $sorok sor)");
    return ['fajl' => $nev, 'osszes_sor' => $osszes, 'sorok' => $tartalom];
}

// ---------------------------------------------------------------------------
//  ADATBÁZIS-MENTÉSEK (DBBCKP) – csak admin
// ---------------------------------------------------------------------------

/** Mentések listája + állapot + cron-parancsok */
function act_admin_mentesek(array $be): array
{
    csak_admin();
    $lista = db_mentesek_lista();
    foreach ($lista as &$m) {
        unset($m['utvonal']);
        $m['ido_szoveg'] = date('Y-m-d H:i', $m['ido']);
    }
    unset($m);
    $a = mentes_allapot();
    foreach (['utolso', 'utolso_auto', 'utolso_cron'] as $k) {
        if ($a[$k]) {
            unset($a[$k]['utvonal']);
            $a[$k]['ido_szoveg'] = date('Y-m-d H:i', $a[$k]['ido']);
        }
    }
    return ['mentesek' => $lista, 'allapot' => $a];
}

/** Kézi teljes mentés most */
function act_admin_mentes_most(array $be): array
{
    $admin = csak_admin();
    $r = db_mentes('kezi', $admin['felhasznalonev']);
    unset($r['utvonal']);
    return $r;
}

/** Egy mentésfájl adatai (visszaállítás előtti megerősítéshez) */
function act_admin_mentes_info(array $be): array
{
    csak_admin();
    $nev = be_szoveg($be, 'fajl', 120, true, 'fájl');
    $ut = backup_fajl_utvonal($nev);
    $info = db_mentes_info($ut);
    $info['fajl'] = $nev;
    return $info;
}

/** Mentésfájl törlése */
function act_admin_mentes_torol(array $be): array
{
    $admin = csak_admin();
    $nev = be_szoveg($be, 'fajl', 120, true, 'fájl');
    $ut = backup_fajl_utvonal($nev);
    $meret = (int)filesize($ut);
    if (!@unlink($ut)) {
        hiba('A fájl nem törölhető (jogosultság?).');
    }
    naplo('DB_MENTES_TOROL', "$nev törölve (" . round($meret / 1024) . ' kB)');
    return ['torolve' => true];
}

/**
 * Visszaállítás a kiválasztott mentésből.
 * Előtte AUTOMATIKUSAN teljes mentés készül (…_visszaallitas_elott.sql); hiba esetén az töltődik vissza.
 */
function act_admin_db_visszaallit(array $be): array
{
    $admin = csak_admin();
    $nev = be_szoveg($be, 'fajl', 120, true, 'fájl');
    if (($be['megerosites'] ?? '') !== 'VISSZAÁLLÍTÁS') {
        hiba('A visszaállításhoz megerősítés kell.');
    }
    $r = db_visszaallit($nev, $admin['felhasznalonev']);
    // a visszaállított adatbázisban lehet, hogy más a felhasználók listája – a munkamenetet ellenőrizzük
    $meg = db_row('SELECT id, szerep, aktiv FROM felhasznalok WHERE id = ?', [(int)$admin['id']]);
    $r['bejelentkezve_marad'] = $meg && $meg['szerep'] === 'admin' && (int)$meg['aktiv'] === 1;
    return $r;
}

/** Új cron-kulcs generálása (a régi URL-hívások ezután érvénytelenek) */
function act_admin_cron_kulcs_uj(array $be): array
{
    csak_admin();
    $k = cron_kulcs(true);
    naplo('DB_MENTES', 'új cron-kulcs generálva');
    return ['cron_kulcs' => $k, 'allapot' => mentes_allapot()];
}
