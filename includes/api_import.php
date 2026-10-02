<?php
declare(strict_types=1);

require_once __DIR__ . '/import.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/backup.php';

// ---------------------------------------------------------------------------
//  EXCEL IMPORT (régi adatok) + archív kötések kezelése
// ---------------------------------------------------------------------------

/** Import oldal állapota: kell-e import előtti mentés, korlátok */
function act_import_allapot(array $be): array
{
    $u = csak_bejelentkezve();
    return ['mentes_kell' => import_mentes_kell(), 'admin' => $u['szerep'] === 'admin', 'max_sor' => IMP_MAX_SOR,
            'max_feltoltes' => ini_get('upload_max_filesize'), 'lejarat_perc' => (int)(IMP_LEJARAT_MP / 60)];
}

/** Korábbi elemzés újratöltése (pl. oldalfrissítés után) */
function act_import_elemzes(array $be): array
{
    csak_bejelentkezve();
    $token = be_szoveg($be, 'token', 32);
    $el = import_betolt($token);
    return ['token' => $token, 'elemzes' => import_kliens_nezet(import_sorok_allapot($el)), 'mentes_kell' => import_mentes_kell()];
}

/** Cégegyeztetés módosítása (csoportok céljai, nevek, összevonás/szétválasztás) → újraszámolt sorok */
function act_import_csoportok(array $be): array
{
    csak_bejelentkezve();
    $token = be_szoveg($be, 'token', 32);
    $el = import_betolt($token);
    $uj = is_array($be['csoportok'] ?? null) ? $be['csoportok'] : [];
    $el['csoportok'] = import_csoportok_ellenoriz($uj, $el);
    import_frissit($token, $el);
    return ['token' => $token, 'elemzes' => import_kliens_nezet(import_sorok_allapot($el))];
}

/**
 * A kiválasztott sorok rögzítése – adagokban (a kliens több kérésben küldi a sorokat).
 *   elso = true: előtte (ha be van kapcsolva) teljes DB-mentés; utolso = true: az elemzés törlése.
 * Minden adag külön tranzakció.
 */
function act_import_vegrehajt(array $be): array
{
    $u = csak_bejelentkezve();
    $token = be_szoveg($be, 'token', 32);
    $el = import_betolt($token);
    $sorok = be_int_lista($be, 'sorok', 'sorok');
    $opciok = is_array($be['opciok'] ?? null) ? $be['opciok'] : [];
    $elso = be_bool($be, 'elso');
    $utolso = be_bool($be, 'utolso');
    if (karbantartas_aktiv()) {
        hiba('Adatbázis-visszaállítás folyamatban – próbáld újra egy perc múlva.', 'KARBANTARTAS', [], 503);
    }
    set_time_limit(600);
    ignore_user_abort(true);
    $mentes = null;
    if ($elso && import_mentes_kell()) {
        try {
            $m = db_mentes('import_elott', $u['felhasznalonev']);
            $mentes = ['fajl' => $m['fajl'], 'meret' => $m['meret']];
        } catch (Throwable $e) {
            hiba('Az import előtti adatbázis-mentés nem sikerült, ezért az import nem indult el: ' . $e->getMessage());
        }
    }
    $r = import_vegrehajt($el, $sorok, $opciok, $u);
    if ($utolso) {
        import_torol($token);
    }
    $r['mentes'] = $mentes;
    $r['fajl'] = $el['fajl'];
    $r['uj_kotesek_db'] = count($r['uj_kotesek']);
    $r['uj_kotesek'] = array_slice($r['uj_kotesek'], 0, 30);
    return $r;
}

/** Régi kötés-azonosító ellenőrzése: ki használja már? */
function act_regi_kod_ellenoriz(array $be): array
{
    csak_bejelentkezve();
    $rk = be_szoveg($be, 'regi_kod', 100, false, 'régi kötés ID') ?? '';
    $kiveve = be_int($be, 'kiveve', false);
    $h = regi_kod_hasznalat($rk, $kiveve);
    return ['regi_kod' => trim($rk), 'foglalt' => count($h) > 0, 'kotesek' => $h];
}

/**
 * Archív kötés kiválasztott számláinak áthelyezése egy kötésbe (újba vagy meglévőbe).
 * A számlák új, a mostani rendszer szerinti teljes azonosítót kapnak (kötés ID + K sorszám).
 */
function act_archiv_szamlak_athelyez(array $be): array
{
    $u = csak_bejelentkezve();
    $ids = be_int_lista($be, 'szamla_ids', 'számlák');
    $cel = ($be['cel'] ?? 'uj') === 'meglevo' ? 'meglevo' : 'uj';
    $regiKod = be_szoveg($be, 'regi_kod', 100, false, 'régi kötés ID');
    $megnevezes = be_szoveg($be, 'megnevezes', 191, false, 'megnevezés');
    $megj = be_szoveg_tobbsoros($be, 'megjegyzes', 2000, false, 'megjegyzés');
    $megerositve = be_bool($be, 'megerositve');
    $celKotesId = $cel === 'meglevo' ? be_int($be, 'kotes_id') : null;

    if ($cel === 'uj' && $regiKod !== null && $regiKod !== '' && !$megerositve) {
        $h = regi_kod_hasznalat($regiKod);
        if ($h) {
            hiba('Ezt a régi kötés ID-t már használtad.', 'REGI_KOD_FOGLALT', ['kotesek' => $h]);
        }
    }

    $e = db_tx(function () use ($ids, $cel, $celKotesId, $regiKod, $megnevezes, $megj, $u) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $szamlak = db_all("SELECT b.*, k.ceg_id, k.penznem, k.archiv, k.kod AS kotes_kod FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id WHERE b.id IN ($in) ORDER BY b.kotes_id, b.k_sorszam FOR UPDATE", $ids);
        if (count($szamlak) !== count($ids)) {
            hiba('Valamelyik számla nem található.');
        }
        $cegId = (int)$szamlak[0]['ceg_id'];
        $penznem = $szamlak[0]['penznem'];
        foreach ($szamlak as $s) {
            if ((int)$s['ceg_id'] !== $cegId || $s['penznem'] !== $penznem) {
                hiba('Csak ugyanannak a cégnek ugyanolyan pénznemű számlái helyezhetők át együtt.');
            }
        }
        if ($cel === 'meglevo') {
            $k = db_row('SELECT * FROM kotesek WHERE id = ? FOR UPDATE', [$celKotesId]);
            if (!$k) {
                hiba('A cél kötés nem található.');
            }
            if ((int)$k['ceg_id'] !== $cegId || $k['penznem'] !== $penznem) {
                hiba('A cél kötés más céghez vagy más pénznemhez tartozik.');
            }
            if ((int)$k['archiv'] === 1) {
                hiba('Archív kötésbe nem lehet áthelyezni – válassz rendes kötést, vagy hozz létre újat.');
            }
            $uj = false;
        } else {
            $ev = (int)date('Y');
            $n = kovetkezo_sorszam('KOTES', $ev, $penznem);
            $kod = kotes_kod($ev, $penznem, $n);
            db_exec('INSERT INTO kotesek (ceg_id, ev, penznem, sorszam, kod, megnevezes, megjegyzes, archiv, regi_kod, letrehozta) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
                [$cegId, $ev, $penznem, $n, $kod, $megnevezes, $megj, $regiKod !== '' ? $regiKod : null, $u['id']]);
            $k = ['id' => (int)db()->lastInsertId(), 'kod' => $kod];
            $uj = true;
        }
        // egy kötésen belül egy számlaszám csak egyszer: a cél kötésben és a kijelöltek között sem lehet ismétlés
        $normak = [];
        foreach ($szamlak as $s) {
            if ((int)$s['kotes_id'] === (int)$k['id']) {
                continue;
            }
            $n = $s['szamlaszam_norm'];
            if (isset($normak[$n])) {
                hiba("A kijelölt számlák között kétszer szerepel ugyanaz a számlaszám („{$s['szamlaszam']}”) – egy kötésbe csak egyszer kerülhet.");
            }
            $normak[$n] = true;
            $t = szamlaszam_kotesben_foglalt($s['szamlaszam'], (int)$k['id']);
            if ($t !== null) {
                hiba("A cél kötésben ({$k['kod']}) már szerepel a(z) „{$s['szamlaszam']}” számlaszám ({$t['k']}) – egy kötésen belül egy számlaszám csak egyszer szerepelhet.");
            }
        }
        if ($uj) {
            audit_ir('KOTES', (int)$k['id'], 'LETREHOZ', "Kötés létrehozva (archív számlák áthelyezéséhez): {$k['kod']} ($penznem)", audit_kezdo(['megnevezes' => $megnevezes, 'regi_kod' => $regiKod, 'megjegyzes' => $megj], ['megnevezes', 'regi_kod', 'megjegyzes']), null, $u);
        }
        $kodok = [];
        foreach ($szamlak as $s) {
            if ((int)$s['kotes_id'] === (int)$k['id']) {
                continue;
            }
            $ksz = kovetkezo_k_sorszam((int)$k['id']);
            $ujKod = szamla_kod($k['kod'], $ksz);
            db_exec('UPDATE bejovo_szamlak SET kotes_id = ?, k_sorszam = ?, kod = ?, modositotta = ? WHERE id = ?', [(int)$k['id'], $ksz, $ujKod, $u['id'], (int)$s['id']]);
            $kodok[] = $s['kod'] . ' → ' . $ujKod;
            audit_ir('BEJOVO', (int)$s['id'], 'ATHELYEZ', "Kötésbe helyezve az archív kötésből: {$s['kotes_kod']} → {$k['kod']}",
                [['m' => 'Kötés', 'r' => $s['kotes_kod'], 'u' => $k['kod']], ['m' => 'Azonosító', 'r' => $s['kod'], 'u' => $ujKod]], (int)$k['id'], $u);
            audit_ir('KOTES', (int)$s['kotes_id'], 'ATHELYEZ', "Számla áthelyezve innen a(z) {$k['kod']} kötésbe: {$s['kod']} „{$s['szamlaszam']}” → $ujKod", [], null, $u);
        }
        return ['kotes_id' => (int)$k['id'], 'kod' => $k['kod'], 'uj' => $uj, 'kodok' => $kodok, 'ceg_id' => $cegId, 'db' => count($kodok)];
    });
    naplo('ARCHIV_ATHELYEZ', "{$e['db']} számla áthelyezve a(z) {$e['kod']} kötésbe" . ($e['uj'] ? ' (új kötés' . ($regiKod ? ", régi ID: $regiKod" . ($megerositve ? ' – MÁR HASZNÁLT, megerősítve' : '') : '') . ')' : '') . ': ' . implode(', ', $e['kodok']));
    return $e;
}
