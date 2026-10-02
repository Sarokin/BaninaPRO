<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Következő 6 jegyű sorszám (típus, év, pénznem) szerint – atomi művelet,
 * tranzakción belül hívandó. Soha nem ad ki kétszer ugyanazt a számot,
 * és törlés után sem használja újra a "kiégett" sorszámot.
 */
function kovetkezo_sorszam(string $tipus, int $ev, string $penznem): int
{
    $pdo = db();
    $st = $pdo->prepare(
        'INSERT INTO sorszamok (tipus, ev, penznem, utolso) VALUES (?, ?, ?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE utolso = LAST_INSERT_ID(utolso + 1)'
    );
    $st->execute([$tipus, $ev, $penznem]);
    $n = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($n < 1 || $n > 999999) {
        hiba('A sorszám-tartomány betelt ehhez az évhez/pénznemhez (999999).');
    }
    return $n;
}

/**
 * Következő K sorszám egy kötésen belül (a kötés sorát zárolva).
 */
function kovetkezo_k_sorszam(int $kotesPk): int
{
    $pdo = db();
    $st = $pdo->prepare('SELECT utolso_k FROM kotesek WHERE id = ? FOR UPDATE');
    $st->execute([$kotesPk]);
    $utolso = $st->fetchColumn();
    if ($utolso === false) {
        hiba('A kötés nem található.');
    }
    $k = (int)$utolso + 1;
    if ($k > 9999) {
        hiba('Ebben a kötésben betelt a K sorszám-tartomány (K9999).');
    }
    $pdo->prepare('UPDATE kotesek SET utolso_k = ? WHERE id = ?')->execute([$k, $kotesPk]);
    return $k;
}

/**
 * Számlaszám-egyediség (1.9-től):
 *   - egy KÖTÉSEN BELÜL ugyanaz a számlaszám csak egyszer szerepelhet (tiltás, UNIQUE index is védi),
 *   - másik kötésben / a kimenő számlák között ugyanaz a számlaszám MEGENGEDETT, csak figyelmeztetünk,
 *   - kimenő számlák között a számlaszám egyedi (a saját számlázásunk), bejövővel ütközés csak figyelmeztetés.
 */

/** Minden előfordulás (bejövő + kimenő), max. 10 – a figyelmeztetéshez */
function szamlaszam_talalatok(string $szamlaszam, ?int $kiveveBejovoId = null, ?int $kiveveKimenoId = null): array
{
    $norm = norm_azonosito($szamlaszam);
    if ($norm === '') {
        hiba('Érvénytelen számlaszám: legalább egy betűt vagy számot tartalmaznia kell.');
    }
    $ki = [];
    foreach (db_all(
        'SELECT b.id, b.kod, b.szamlaszam, b.statusz, c.nev AS ceg_nev, c.id AS ceg_id, k.id AS kotes_pk, k.kod AS kotes_kod
           FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id
          WHERE b.szamlaszam_norm = ?' . ($kiveveBejovoId ? ' AND b.id <> ?' : '') . ' ORDER BY b.id LIMIT 10',
        $kiveveBejovoId ? [$norm, $kiveveBejovoId] : [$norm]
    ) as $b) {
        $ki[] = ['irany' => 'BEJOVO', 'id' => (int)$b['id'], 'kod' => $b['kod'], 'szamlaszam' => $b['szamlaszam'], 'statusz' => $b['statusz'],
                 'ceg_nev' => $b['ceg_nev'], 'ceg_id' => (int)$b['ceg_id'], 'kotes_pk' => (int)$b['kotes_pk'], 'kotes_kod' => $b['kotes_kod']];
    }
    foreach (db_all(
        'SELECT s.id, s.kod, s.szamlaszam, s.statusz, c.nev AS ceg_nev, c.id AS ceg_id
           FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id
          WHERE s.szamlaszam_norm = ?' . ($kiveveKimenoId ? ' AND s.id <> ?' : '') . ' ORDER BY s.id LIMIT 10',
        $kiveveKimenoId ? [$norm, $kiveveKimenoId] : [$norm]
    ) as $k) {
        $ki[] = ['irany' => 'KIMENO', 'id' => (int)$k['id'], 'kod' => $k['kod'], 'szamlaszam' => $k['szamlaszam'], 'statusz' => $k['statusz'],
                 'ceg_nev' => $k['ceg_nev'], 'ceg_id' => (int)$k['ceg_id']];
    }
    return $ki;
}

/** Ugyanabban a kötésben szerepel-e már ez a számlaszám? (TILTÁS) */
function szamlaszam_kotesben_foglalt(string $szamlaszam, int $kotesId, ?int $kiveveBejovoId = null): ?array
{
    $norm = norm_azonosito($szamlaszam);
    if ($norm === '') {
        hiba('Érvénytelen számlaszám: legalább egy betűt vagy számot tartalmaznia kell.');
    }
    $b = db_row(
        'SELECT b.id, b.kod, b.k_sorszam, b.szamlaszam, b.statusz, k.kod AS kotes_kod, c.nev AS ceg_nev
           FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id
          WHERE b.kotes_id = ? AND b.szamlaszam_norm = ?' . ($kiveveBejovoId ? ' AND b.id <> ?' : ''),
        $kiveveBejovoId ? [$kotesId, $norm, $kiveveBejovoId] : [$kotesId, $norm]
    );
    if (!$b) {
        return null;
    }
    return ['irany' => 'BEJOVO', 'id' => (int)$b['id'], 'kod' => $b['kod'], 'k' => sprintf('K%04d', $b['k_sorszam']), 'szamlaszam' => $b['szamlaszam'],
            'statusz' => $b['statusz'], 'kotes_kod' => $b['kotes_kod'], 'ceg_nev' => $b['ceg_nev']];
}

/** A kimenő számlák között szerepel-e már ez a számlaszám? (TILTÁS) */
function szamlaszam_kimeno_foglalt(string $szamlaszam, ?int $kiveveKimenoId = null): ?array
{
    $norm = norm_azonosito($szamlaszam);
    if ($norm === '') {
        hiba('Érvénytelen számlaszám: legalább egy betűt vagy számot tartalmaznia kell.');
    }
    $k = db_row(
        'SELECT s.id, s.kod, s.szamlaszam, s.statusz, c.nev AS ceg_nev, c.id AS ceg_id FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id
          WHERE s.szamlaszam_norm = ?' . ($kiveveKimenoId ? ' AND s.id <> ?' : ''),
        $kiveveKimenoId ? [$norm, $kiveveKimenoId] : [$norm]
    );
    if (!$k) {
        return null;
    }
    return ['irany' => 'KIMENO', 'id' => (int)$k['id'], 'kod' => $k['kod'], 'szamlaszam' => $k['szamlaszam'], 'statusz' => $k['statusz'], 'ceg_nev' => $k['ceg_nev'], 'ceg_id' => (int)$k['ceg_id']];
}

/** Kötésen belüli ütközés hibaüzenete */
function szamlaszam_kotes_uzenet(array $t): string
{
    return sprintf('Ebben a kötésben (%s) már szerepel ez a számlaszám: %s „%s” (%s). Egy kötésen belül ugyanaz a számlaszám csak egyszer rögzíthető – egy másik kötésben igen.',
        $t['kotes_kod'], $t['k'], $t['szamlaszam'], ny_statusz_nev($t['statusz']));
}

/** Kimenő ütközés hibaüzenete */
function szamlaszam_kimeno_uzenet(array $t): string
{
    return sprintf('Ez a kimenő számlaszám már szerepel: %s – %s („%s”). A kimenő számlák számlaszáma egyedi.', $t['kod'], $t['ceg_nev'], $t['szamlaszam']);
}

/** Figyelmeztető szöveg más kötésbeli / más irányú előfordulásokhoz (nem tilt) */
function szamlaszam_figyelmeztetes(array $talalatok): string
{
    if (!$talalatok) {
        return '';
    }
    $r = [];
    foreach (array_slice($talalatok, 0, 3) as $t) {
        $r[] = $t['kod'] . ' – ' . $t['ceg_nev'] . ($t['irany'] === 'BEJOVO' ? ' (bejövő, ' . $t['kotes_kod'] . ' kötés)' : ' (kimenő)');
    }
    return 'Figyelem: ez a számlaszám már szerepel a rendszerben: ' . implode('; ', $r) . (count($talalatok) > 3 ? ' és még ' . (count($talalatok) - 3) . ' helyen' : '') . '. Másik kötésben rögzíthető – ellenőrizd, hogy tényleg új számla-e.';
}

/** Régi, általános ellenőrzés (bármelyik irányban) – az import és a kompatibilitás miatt marad */
function szamlaszam_foglalt(string $szamlaszam, ?int $kiveveBejovoId = null, ?int $kiveveKimenoId = null): ?array
{
    $t = szamlaszam_talalatok($szamlaszam, $kiveveBejovoId, $kiveveKimenoId);
    return $t ? $t[0] : null;
}

function szamlaszam_foglalt_uzenet(array $t): string
{
    $hol = $t['irany'] === 'BEJOVO' ? 'bejövő számla' : 'kimenő számla';
    return sprintf('Ez a számlaszám már szerepel a rendszerben: %s – %s (%s, „%s”).', $t['kod'], $t['ceg_nev'], $hol, $t['szamlaszam']);
}
