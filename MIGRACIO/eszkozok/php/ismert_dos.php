<?php
/**
 * Az adatbázisban már meglévő (2026.10.07-i DOS importból származó) rekordok DOS-beli azonosítói és értékei,
 * a megjegyzésekből visszafejtve. Ez a „kulcs”: holnap a DOS-fájlok értelmezését ehhez mérjük (minden régi rekordnak
 * pontosan egyeznie kell), és ebből tudjuk, mi új a DOS-ban.
 *
 *   kornyezet.sh php <db> /migracio/eszkozok/php/ismert_dos.php [kimeneti mappa]
 * Kimenet: <mappa>/ismert_dos.json (alapból /migracio/4_EREDMENY/<db>/)
 */
declare(strict_types=1);

// csak parancssorból (a szerveren a repó a webgyökér – webről ne fusson)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db.php';
require_once __DIR__ . '/kozos.php';

$ki = rtrim($argv[1] ?? ('/migracio/4_EREDMENY/' . DB_NAME), '/');
@mkdir($ki, 0775, true);

$t0 = microtime(true);
$kulcsDb = [];   // típus → kulcs → előfordulás (az értelmezés ellenőrzéséhez)
$szamol = static function (string $tipus, array $mezok) use (&$kulcsDb): void {
    foreach (array_keys($mezok) as $k) {
        $kulcsDb[$tipus][$k] = ($kulcsDb[$tipus][$k] ?? 0) + 1;
    }
};

// ---- cégek -----------------------------------------------------------------
$cegek = [];
foreach (db_all('SELECT id, nev, partnerkod, adoszam, megjegyzes FROM cegek ORDER BY id') as $c) {
    $m = mig_megj_bont((string)$c['megjegyzes']);
    $szamol('ceg', $m['mezok']);
    $cegek[] = ['id' => (int)$c['id'], 'nev' => $c['nev'], 'partnerkod' => $c['partnerkod'], 'adoszam' => $c['adoszam'],
                'dos_kodok' => mig_dos_kodok($m['mezok']['DOS-kód'] ?? ''), 'dos' => $m['mezok'], 'egyeb' => $m['egyeb']];
}

// ---- kötések ---------------------------------------------------------------
$kotesek = [];
foreach (db_all('SELECT k.id, k.kod, k.ev, k.penznem, k.regi_kod, k.megnevezes, k.archiv, k.ceg_id, k.letrehozva FROM kotesek k ORDER BY k.id') as $k) {
    $kotesek[] = ['id' => (int)$k['id'], 'kod' => $k['kod'], 'ev' => (int)$k['ev'], 'penznem' => $k['penznem'], 'dos_kotesszam' => $k['regi_kod'],
                  'megnevezes' => $k['megnevezes'], 'archiv' => (int)$k['archiv'], 'ceg_id' => (int)$k['ceg_id'], 'letrehozva' => $k['letrehozva']];
}

// ---- bejövő számlák ----------------------------------------------------------
$bejovo = [];
foreach (db_all('SELECT b.id, b.kod, b.kotes_id, k.regi_kod, k.ceg_id, k.penznem, b.regi_k, b.szamlaszam, b.teljesites_datum, b.kelt, b.fizetesi_hatarido,
                        b.osszeg, b.beszam, b.statusz, b.fizetve_datum, b.utalas_id, u.uid AS utalas_uid, u.banki_hivatkozas, b.megjegyzes, b.letrehozva
                   FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id LEFT JOIN utalasok u ON u.id = b.utalas_id ORDER BY b.id') as $b) {
    $m = mig_megj_bont((string)$b['megjegyzes']);
    $szamol('bejovo', $m['mezok']);
    $azon = $m['mezok']['DOS azonosító'] ?? '';
    [$kotesszam, $kisszam] = str_contains($azon, '/') ? explode('/', $azon, 2) : [$azon, ''];
    $bejovo[] = ['id' => (int)$b['id'], 'kod' => $b['kod'], 'kotes_id' => (int)$b['kotes_id'], 'ceg_id' => (int)$b['ceg_id'], 'penznem' => $b['penznem'],
                 'dos_kotesszam' => $kotesszam, 'dos_kisszam' => $kisszam, 'regi_kod' => $b['regi_kod'], 'regi_k' => $b['regi_k'],
                 'szamlaszam' => $b['szamlaszam'], 'teljesites' => $b['teljesites_datum'], 'kelt' => $b['kelt'], 'hatarido' => $b['fizetesi_hatarido'],
                 'osszeg' => $b['osszeg'], 'beszam' => $b['beszam'], 'statusz' => $b['statusz'], 'fizetve' => $b['fizetve_datum'],
                 'utalas_id' => $b['utalas_id'] === null ? null : (int)$b['utalas_id'], 'utalas_uid' => $b['utalas_uid'],
                 'dos_utalas' => mig_utalas_ref($m['mezok']['Utalás'] ?? ''), 'dos' => $m['mezok'], 'egyeb' => $m['egyeb'], 'letrehozva' => $b['letrehozva']];
}

// ---- kimenő számlák ----------------------------------------------------------
$kimeno = [];
foreach (db_all('SELECT s.id, s.kod, s.ceg_id, s.penznem, s.szamlaszam, s.teljesites_datum, s.kelt, s.fizetesi_hatarido, s.osszeg, s.statusz, s.fizetve_datum,
                        s.banki_kivonat_id, bk.azonosito AS bank_azon, s.megjegyzes, s.letrehozva
                   FROM kimeno_szamlak s LEFT JOIN banki_kivonatok bk ON bk.id = s.banki_kivonat_id ORDER BY s.id') as $s) {
    $m = mig_megj_bont((string)$s['megjegyzes']);
    $szamol('kimeno', $m['mezok']);
    $kimeno[] = ['id' => (int)$s['id'], 'kod' => $s['kod'], 'ceg_id' => (int)$s['ceg_id'], 'penznem' => $s['penznem'], 'szamlaszam' => $s['szamlaszam'],
                 'dos_azonosito' => $m['mezok']['DOS azonosító'] ?? '', 'teljesites' => $s['teljesites_datum'], 'kelt' => $s['kelt'],
                 'hatarido' => $s['fizetesi_hatarido'], 'osszeg' => $s['osszeg'], 'statusz' => $s['statusz'], 'fizetve' => $s['fizetve_datum'],
                 'bank_azon' => $s['bank_azon'], 'dos' => $m['mezok'], 'egyeb' => $m['egyeb'], 'letrehozva' => $s['letrehozva']];
}

// ---- utalások -----------------------------------------------------------------
$utalasok = [];
foreach (db_all('SELECT u.id, u.uid, u.ceg_id, u.penznem, u.statusz, u.utalva_datum, u.banki_hivatkozas, u.lezarva, u.megjegyzes, u.letrehozva,
                        (SELECT COUNT(*) FROM bejovo_szamlak b WHERE b.utalas_id = u.id) AS szamla_db,
                        (SELECT COALESCE(SUM(b.osszeg), 0) FROM bejovo_szamlak b WHERE b.utalas_id = u.id) AS szamla_ossz
                   FROM utalasok u ORDER BY u.id') as $u) {
    $m = mig_megj_bont((string)$u['megjegyzes']);
    $szamol('utalas', $m['mezok']);
    $utalasok[] = ['id' => (int)$u['id'], 'uid' => $u['uid'], 'ceg_id' => (int)$u['ceg_id'], 'penznem' => $u['penznem'], 'statusz' => $u['statusz'],
                   'utalva' => $u['utalva_datum'], 'lezarva' => (int)$u['lezarva'], 'dos_utalas' => mig_utalas_ref((string)$u['banki_hivatkozas']),
                   'banki_hivatkozas' => $u['banki_hivatkozas'], 'szamla_db' => (int)$u['szamla_db'], 'szamla_ossz' => $u['szamla_ossz'],
                   'dos' => $m['mezok'], 'egyeb' => $m['egyeb'], 'letrehozva' => $u['letrehozva']];
}

$ki_adat = ['adatbazis' => DB_NAME, 'keszult' => date('c'), 'cegek' => $cegek, 'kotesek' => $kotesek, 'bejovo' => $bejovo, 'kimeno' => $kimeno, 'utalasok' => $utalasok];
file_put_contents("$ki/ismert_dos.json", json_encode($ki_adat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

// ---- összesítés: mennyi rekordnál ismerhető fel a DOS-kulcs ------------------------
$van = static fn(array $t, callable $f) => count(array_filter($t, $f));
printf("Kimenet: %s/ismert_dos.json (%.1f mp)\n", $ki, microtime(true) - $t0);
printf("cégek:     %5d  (DOS-kóddal: %d)\n", count($cegek), $van($cegek, fn($c) => $c['dos_kodok'] !== []));
printf("kötések:   %5d  (DOS kötésszámmal: %d, archív: %d)\n", count($kotesek), $van($kotesek, fn($k) => $k['dos_kotesszam'] !== null), $van($kotesek, fn($k) => $k['archiv'] === 1));
printf("bejövő:    %5d  (DOS kötésszám/kisszám: %d, DOS-utalással: %d)\n", count($bejovo), $van($bejovo, fn($b) => $b['dos_kisszam'] !== ''), $van($bejovo, fn($b) => $b['dos_utalas'] !== null));
printf("kimenő:    %5d  (DOS azonosítóval: %d)\n", count($kimeno), $van($kimeno, fn($s) => $s['dos_azonosito'] !== ''));
printf("utalások:  %5d  (DOS-utalásszámmal: %d)\n", count($utalasok), $van($utalasok, fn($u) => $u['dos_utalas'] !== null));
echo "\nA megjegyzésekben talált mezők (típus: mező × előfordulás):\n";
foreach ($kulcsDb as $tipus => $mezok) {
    arsort($mezok);
    echo "  $tipus: " . implode(', ', array_map(fn($k, $n) => "{$k}×{$n}", array_keys($mezok), $mezok)) . "\n";
}
