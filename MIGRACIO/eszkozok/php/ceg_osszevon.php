<?php
/**
 * Cégek összevonása a felhasználó döntése szerint (ugyanaz a partner a DOS-ban szállítóként és vevőként is szerepelt).
 * A cégeket a DOS-kódjuk azonosítja (a megjegyzés „DOS-kód: …” része), így bármelyik adatbázison (próba, élő mentés)
 * ugyanazt a párt találja meg. A 2026.10.06-i kézi összevonás mintájára: az összevonandó cég kötései, kimenő számlái és
 * utalásai átkerülnek a megmaradó céghez (a kötések és kimenő számlák naplójában ÁTHELYEZÉS bejegyzéssel), a megmaradó cég
 * megjegyzése megkapja a másik DOS-kódjait (a későbbi DOS-átvétel így ide párosít), az IB-import megjegyzett párosításai is
 * átíródnak, végül az összevont cég törlődik. Ahol már nincs mit összevonni, nem változtat semmit (megismételhető).
 *
 *   kornyezet.sh php <db> /migracio/eszkozok/php/ceg_osszevon.php <parok.json> [--szaraz] [--datum=2026.10.16]
 *   parok.json: [{"cel": "VEVO NL-00001234", "forras": "UGYF 123456789B0", "nev": "Minta B.V."}, …]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db.php';
require_once '/var/www/html/includes/helpers.php';
require_once '/var/www/html/includes/logger.php';
require_once '/var/www/html/includes/audit.php';
require_once __DIR__ . '/kozos.php';

$fajl = null;
$k = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $k[$m[1]] = $m[2] ?? '1';
    } else {
        $fajl = $a;
    }
}
if ($fajl === null || !is_file($fajl)) {
    fwrite(STDERR, "Használat: ceg_osszevon.php <parok.json> [--szaraz] [--datum=ÉÉÉÉ.HH.NN]\n");
    exit(1);
}
$SZARAZ = isset($k['szaraz']);
$NAP = $k['datum'] ?? date('Y.m.d');
$FELH = 'DOS import';
$U = ['id' => null, 'felhasznalonev' => $FELH, 'szerep' => 'admin'];
$parok = json_decode((string)file_get_contents($fajl), true, 512, JSON_THROW_ON_ERROR);

/** DOS-kód („VEVO NL-00001234”) → cég id (a megjegyzés „DOS-kód:” részei szerint) */
$kodCeg = [];
foreach (db_all('SELECT id, megjegyzes FROM cegek') as $c) {
    foreach (mig_dos_kodok(mig_megj_bont((string)$c['megjegyzes'])['mezok']['DOS-kód'] ?? '') as [$t, $kod]) {
        $kodCeg["$t $kod"] = (int)$c['id'];
    }
}

$sorok = [];
$pdo = db();
$pdo->beginTransaction();
try {
    foreach ($parok as $p) {
        $cel = $kodCeg[trim((string)$p['cel'])] ?? null;
        $forras = $kodCeg[trim((string)$p['forras'])] ?? null;
        if ($cel === null || $forras === null) {
            $sorok[] = sprintf('NINCS MEG: %s ← %s (%s) – %s', $p['cel'], $p['forras'], $p['nev'], $cel === null ? 'a cél cég nincs az adatbázisban' : 'az összevonandó cég nincs (már összevonva?)');
            continue;
        }
        if ($cel === $forras) {
            $sorok[] = "MÁR EGY CÉG: „{$p['nev']}” (#$cel) – {$p['cel']} és {$p['forras']}";
            continue;
        }
        $c = db_row('SELECT * FROM cegek WHERE id = ?', [$cel]);
        $f = db_row('SELECT * FROM cegek WHERE id = ?', [$forras]);
        $nev = trim((string)$p['nev']);
        if (db_val('SELECT id FROM cegek WHERE nev = ? AND id NOT IN (?, ?)', [$nev, $cel, $forras])) {
            $sorok[] = "FIGYELEM: „{$nev}” néven már van másik cég – a név marad: „{$c['nev']}”";
            $nev = (string)$c['nev'];
        }
        $leiras = "Cégösszevonás (DOS import $NAP): átkerült „{$f['nev']}” (P" . ($f['partnerkod'] ?? '–') . ") cégtől ide: „{$nev}” (P" . ($c['partnerkod'] ?? '–') . ')';
        $kot = db_all('SELECT id FROM kotesek WHERE ceg_id = ?', [$forras]);
        $kim = db_all('SELECT id FROM kimeno_szamlak WHERE ceg_id = ?', [$forras]);
        $aud = [];
        foreach ($kot as $r) {
            $aud[] = ['KOTES', (int)$r['id'], null, 'ATHELYEZ', $leiras, [['m' => 'Cég', 'r' => $f['nev'], 'u' => $nev]]];
        }
        foreach ($kim as $r) {
            $aud[] = ['KIMENO', (int)$r['id'], null, 'ATHELYEZ', $leiras, [['m' => 'Cég', 'r' => $f['nev'], 'u' => $nev]]];
        }
        $nu = db_exec('UPDATE utalasok SET ceg_id = ? WHERE ceg_id = ?', [$cel, $forras]);
        db_exec('UPDATE kotesek SET ceg_id = ? WHERE ceg_id = ?', [$cel, $forras]);
        db_exec('UPDATE kimeno_szamlak SET ceg_id = ? WHERE ceg_id = ?', [$cel, $forras]);
        audit_ir_tobb($aud, $U);
        // a megmaradó cég: név, adószám és partnerkód (ha üres) az összevontból, a megjegyzésbe a DOS-kódjai (a DOS-átvétel ezek szerint párosít)
        $fMegj = preg_replace('/^DOS import \([^)]*\)( · )?/u', '', trim((string)$f['megjegyzes'])) ?? '';
        $ujMegj = rtrim((string)$c['megjegyzes']) . "\nÖsszevonva ($NAP): „{$f['nev']}” (P" . ($f['partnerkod'] ?? '–') . ')' . ($fMegj !== '' ? " · $fMegj" : '');
        db_exec('DELETE FROM cegek WHERE id = ?', [$forras]);
        db_exec('UPDATE cegek SET nev = ?, adoszam = COALESCE(adoszam, ?), partnerkod = COALESCE(partnerkod, ?), megjegyzes = ? WHERE id = ?',
            [$nev, $f['adoszam'], $f['partnerkod'], $ujMegj, $cel]);
        // az IB-import megjegyzett párosításai (partnerkód → cég): az összevont cég helyett a megmaradó
        $par = json_decode((string)db_val('SELECT ertek FROM beallitasok WHERE kulcs = ?', ['import_parositas']), true) ?: [];
        $atirt = 0;
        foreach ($par as $kk => $v) {
            if ((int)$v === $forras) {
                $par[$kk] = $cel;
                $atirt++;
            }
        }
        if ($atirt) {
            db_exec('UPDATE beallitasok SET ertek = ? WHERE kulcs = ?', [json_encode($par, JSON_FORCE_OBJECT), 'import_parositas']);
        }
        // azonos DOS-kötésszám kétszer ugyanannál a cégnél és pénznemnél (a két DOS-partner ugyanabban a kötésben)
        $dupla = db_all('SELECT regi_kod, penznem, GROUP_CONCAT(kod ORDER BY kod) kodok FROM kotesek WHERE ceg_id = ? AND regi_kod IS NOT NULL GROUP BY regi_kod, penznem HAVING COUNT(*) > 1', [$cel]);
        $sorok[] = sprintf('ÖSSZEVONVA: „%s” (#%d) ← „%s” (#%d): %d kötés, %d kimenő számla, %d utalás átkerült%s%s', $nev, $cel, $f['nev'], $forras, count($kot), count($kim), $nu,
            $atirt ? ", $atirt IB-párosítás átírva" : '', $dupla ? ' · FIGYELEM: azonos DOS-kötésszám két kötésben: ' . implode('; ', array_map(fn($d) => "{$d['regi_kod']} {$d['penznem']}: {$d['kodok']}", $dupla)) : '');
        naplo('CEG_OSSZEVONAS', "„{$f['nev']}” (#$forras) → „{$nev}” (#$cel): " . count($kot) . ' kötés, ' . count($kim) . " kimenő, $nu utalás", 'OK', $FELH);
    }
    if ($SZARAZ) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'HIBA (semmi nem változott): ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
echo ($SZARAZ ? "SZÁRAZ FUTÁS – az adatbázis nem változott\n" : '') . implode("\n", $sorok) . "\n";
