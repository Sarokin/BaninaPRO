<?php
/**
 * Az adatbázis épségének ellenőrzése (a migráció végén, az átadás előtt). Csak olvas.
 *   kornyezet.sh php <db> /migracio/eszkozok/php/ellenorzes.php [kimeneti mappa]
 * HIBA: a BaninaPRO szabályait sérti (az átadás előtt javítani kell) · FIGY: gyanús, átnézendő · INFO: tájékoztatás.
 * Kilépési kód: 1, ha van HIBA.
 */
declare(strict_types=1);

// csak parancssorból (a szerveren a repó a webgyökér – webről ne fusson)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db.php';
require_once '/var/www/html/includes/helpers.php';

$ki = rtrim($argv[1] ?? ('/migracio/4_EREDMENY/' . DB_NAME), '/');
@mkdir($ki, 0775, true);
$talalat = [];   // [szint, kód, leírás, darab, minták]

function ell(string $szint, string $kod, string $leiras, string $sql, array $p = []): void
{
    global $talalat;
    $sorok = db_all($sql, $p);
    if ($sorok) {
        $talalat[] = [$szint, $kod, $leiras, count($sorok), array_slice($sorok, 0, 8)];
    }
}

function ell_php(string $szint, string $kod, string $leiras, array $hibasak): void
{
    global $talalat;
    if ($hibasak) {
        $talalat[] = [$szint, $kod, $leiras, count($hibasak), array_slice($hibasak, 0, 8)];
    }
}

// ---- 1. hivatkozások (a betöltés FOREIGN_KEY_CHECKS = 0 mellett is hibátlan-e) -------------------------------
ell('HIBA', 'FK_KOTES_CEG', 'kötés nem létező céggel', 'SELECT k.id, k.kod, k.ceg_id FROM kotesek k LEFT JOIN cegek c ON c.id = k.ceg_id WHERE c.id IS NULL');
ell('HIBA', 'FK_BEJOVO_KOTES', 'bejövő számla nem létező kötéssel', 'SELECT b.id, b.kod FROM bejovo_szamlak b LEFT JOIN kotesek k ON k.id = b.kotes_id WHERE k.id IS NULL');
ell('HIBA', 'FK_BEJOVO_UTALAS', 'bejövő számla nem létező utalással', 'SELECT b.id, b.kod, b.utalas_id FROM bejovo_szamlak b LEFT JOIN utalasok u ON u.id = b.utalas_id WHERE b.utalas_id IS NOT NULL AND u.id IS NULL');
ell('HIBA', 'FK_KIMENO_CEG', 'kimenő számla nem létező céggel', 'SELECT s.id, s.kod FROM kimeno_szamlak s LEFT JOIN cegek c ON c.id = s.ceg_id WHERE c.id IS NULL');
ell('HIBA', 'FK_KIMENO_BANK', 'kimenő számla nem létező banki kivonattal', 'SELECT s.id, s.kod FROM kimeno_szamlak s LEFT JOIN banki_kivonatok b ON b.id = s.banki_kivonat_id WHERE s.banki_kivonat_id IS NOT NULL AND b.id IS NULL');
ell('HIBA', 'FK_UTALAS_CEG', 'utalás nem létező céggel', 'SELECT u.id, u.uid FROM utalasok u LEFT JOIN cegek c ON c.id = u.ceg_id WHERE c.id IS NULL');
ell('HIBA', 'FK_RESZT', 'részteljesítés nem létező számlához',
    "SELECT r.id, r.irany, r.szamla_id FROM reszteljesitesek r LEFT JOIN bejovo_szamlak b ON r.irany = 'BEJOVO' AND b.id = r.szamla_id
       LEFT JOIN kimeno_szamlak s ON r.irany = 'KIMENO' AND s.id = r.szamla_id WHERE b.id IS NULL AND s.id IS NULL");

// ---- 2. azonosítók: formátum, normalizált számlaszám, sorszámok --------------------------------------------------
$h = [];
foreach (db_all('SELECT id, ev, penznem, sorszam, kod, utolso_k FROM kotesek') as $k) {
    if ($k['kod'] !== kotes_kod((int)$k['ev'], $k['penznem'], (int)$k['sorszam'])) { $h[] = $k; }
}
ell_php('HIBA', 'KOTES_KOD', 'a kötés kódja nem az év/pénznem/sorszám szerinti', $h);
$h = [];
foreach (db_all('SELECT b.id, b.kod, b.k_sorszam, k.kod AS kotes_kod FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id') as $b) {
    if ($b['kod'] !== szamla_kod($b['kotes_kod'], (int)$b['k_sorszam'])) { $h[] = $b; }
}
ell_php('HIBA', 'BEJOVO_KOD', 'a bejövő számla kódja nem a kötés kódja + K sorszám', $h);
$h = [];
foreach (db_all('SELECT id, ev, penznem, sorszam, kod FROM kimeno_szamlak') as $s) {
    if ($s['kod'] !== kimeno_kod((int)$s['ev'], $s['penznem'], (int)$s['sorszam'])) { $h[] = $s; }
}
ell_php('HIBA', 'KIMENO_KOD', 'a kimenő számla kódja nem az év/pénznem/sorszám szerinti', $h);
$h = [];
foreach (db_all('SELECT id, ev, penznem, sorszam, uid FROM utalasok') as $u) {
    if ($u['uid'] !== utalas_uid($u['penznem'], (int)$u['ev'], (int)$u['sorszam'])) { $h[] = $u; }
}
ell_php('HIBA', 'UTALAS_UID', 'az utalás azonosítója nem a pénznem/év/sorszám szerinti', $h);
foreach (['bejovo_szamlak', 'kimeno_szamlak'] as $t) {
    $h = [];
    foreach (db_all("SELECT id, kod, szamlaszam, szamlaszam_norm FROM $t") as $s) {
        if ($s['szamlaszam_norm'] !== norm_azonosito($s['szamlaszam'])) { $h[] = $s; }
    }
    ell_php('HIBA', 'NORM_' . strtoupper($t), "$t: a szamlaszam_norm nem a számlaszám normalizált alakja", $h);
}
ell('HIBA', 'UTOLSO_K', 'a kötés utolso_k kisebb, mint a legnagyobb K sorszáma (a következő új számla ütközne)',
    'SELECT k.id, k.kod, k.utolso_k, MAX(b.k_sorszam) AS max_k FROM kotesek k JOIN bejovo_szamlak b ON b.kotes_id = k.id GROUP BY k.id HAVING MAX(b.k_sorszam) > k.utolso_k');
foreach (['KOTES' => 'kotesek', 'UTALAS' => 'utalasok', 'KIMENO' => 'kimeno_szamlak'] as $tipus => $t) {
    ell('HIBA', 'SORSZAM_' . $tipus, "a sorszamok tábla ($tipus) kisebb, mint a ténylegesen kiadott legnagyobb sorszám (a következő új rekord ütközne)",
        "SELECT x.ev, x.penznem, x.maxs, s.utolso FROM (SELECT ev, penznem, MAX(sorszam) maxs FROM $t GROUP BY ev, penznem) x
           LEFT JOIN sorszamok s ON s.tipus = ? AND s.ev = x.ev AND s.penznem = x.penznem WHERE s.utolso IS NULL OR s.utolso < x.maxs", [$tipus]);
}

// ---- 3. státuszok összhangja -------------------------------------------------------------------------------------
ell('HIBA', 'BESZAMITVA_POZ', 'BESZÁMÍTVA státusz nem negatív összeggel', "SELECT id, kod, osszeg FROM bejovo_szamlak WHERE statusz = 'BESZAMITVA' AND osszeg >= 0");
ell('HIBA', 'UTALASHOZ_ADVA_NINCS', 'UTALÁSHOZ ADVA, de nincs utalása', "SELECT id, kod FROM bejovo_szamlak WHERE statusz = 'UTALASHOZ_ADVA' AND utalas_id IS NULL");
ell('HIBA', 'UTALASHOZ_ADVA_UTALVA', 'UTALÁSHOZ ADVA, de az utalása már UTALVA',
    "SELECT b.id, b.kod, u.uid FROM bejovo_szamlak b JOIN utalasok u ON u.id = b.utalas_id WHERE b.statusz = 'UTALASHOZ_ADVA' AND u.statusz = 'UTALVA'");
ell('HIBA', 'UTALVA_NYITOTT_SZAMLA', 'UTALVA utalásban FIZETENDŐ / UTALÁSHOZ ADVA számla',
    "SELECT b.id, b.kod, b.statusz, u.uid FROM bejovo_szamlak b JOIN utalasok u ON u.id = b.utalas_id WHERE u.statusz = 'UTALVA' AND b.statusz IN ('FIZETENDO', 'UTALASHOZ_ADVA')");
ell('HIBA', 'FIZETENDO_UTALASBAN', 'FIZETENDŐ számla utaláshoz kötve',
    "SELECT b.id, b.kod, u.uid, u.statusz FROM bejovo_szamlak b JOIN utalasok u ON u.id = b.utalas_id WHERE b.statusz = 'FIZETENDO'");
ell('HIBA', 'UTALAS_PENZNEM', 'az utalás pénzneme más, mint a számla kötéséé (egy utalás = egy pénznem)',
    'SELECT b.id, b.kod, u.uid, u.penznem, k.penznem AS kotes_penznem FROM bejovo_szamlak b JOIN utalasok u ON u.id = b.utalas_id JOIN kotesek k ON k.id = b.kotes_id WHERE u.penznem <> k.penznem');
ell('FIGY', 'UTALAS_CEG', 'az utalás cége más, mint a számla kötésének cége',
    'SELECT b.id, b.kod, u.uid, u.ceg_id, k.ceg_id AS kotes_ceg FROM bejovo_szamlak b JOIN utalasok u ON u.id = b.utalas_id JOIN kotesek k ON k.id = b.kotes_id WHERE u.ceg_id <> k.ceg_id');
ell('FIGY', 'UTALAS_URES', 'számla nélküli utalás', 'SELECT u.id, u.uid, u.statusz FROM utalasok u WHERE NOT EXISTS (SELECT 1 FROM bejovo_szamlak b WHERE b.utalas_id = u.id)');
ell('INFO', 'FIZETVE_UTALAS_NELKUL', 'FIZETVE / BESZÁMÍTVA bejövő számla utalás nélkül (pl. FIZETVE-ként importált régi számla)',
    "SELECT id, kod, statusz FROM bejovo_szamlak WHERE statusz IN ('FIZETVE', 'BESZAMITVA') AND utalas_id IS NULL");
ell('INFO', 'FIZETVE_DATUM_NELKUL', 'FIZETVE / BESZÁMÍTVA bejövő számla fizetési dátum nélkül (a DOS-ban 00.00.00 volt a terhelés)',
    "SELECT id, kod, statusz FROM bejovo_szamlak WHERE statusz IN ('FIZETVE', 'BESZAMITVA') AND fizetve_datum IS NULL");
ell('FIGY', 'KIMENO_FIZETVE_BANK', 'FIZETVE kimenő számla banki kivonat nélkül', "SELECT id, kod FROM kimeno_szamlak WHERE statusz = 'FIZETVE' AND banki_kivonat_id IS NULL");
ell('FIGY', 'KIMENO_NYITOTT_BANK', 'NYITOTT kimenő számla banki kivonattal', "SELECT id, kod FROM kimeno_szamlak WHERE statusz = 'NYITOTT' AND banki_kivonat_id IS NOT NULL");
ell('FIGY', 'RESZT_TOBB', 'a részteljesítések összege eléri / meghaladja a számla összegét, a számla mégsem rendezett',
    "SELECT r.irany, r.szamla_id, SUM(r.osszeg) reszt, COALESCE(b.osszeg, s.osszeg) osszeg FROM reszteljesitesek r
       LEFT JOIN bejovo_szamlak b ON r.irany = 'BEJOVO' AND b.id = r.szamla_id AND b.statusz IN ('FIZETENDO', 'UTALASHOZ_ADVA')
       LEFT JOIN kimeno_szamlak s ON r.irany = 'KIMENO' AND s.id = r.szamla_id AND s.statusz = 'NYITOTT'
      WHERE b.id IS NOT NULL OR s.id IS NOT NULL GROUP BY r.irany, r.szamla_id HAVING SUM(r.osszeg) >= MAX(ABS(COALESCE(b.osszeg, s.osszeg)))");

// ---- 4. dátumok -----------------------------------------------------------------------------------------------------
foreach (['bejovo_szamlak' => 'bejövő', 'kimeno_szamlak' => 'kimenő'] as $t => $n) {
    ell('FIGY', 'DATUM_' . strtoupper($t), "$n számla valószínűtlen dátummal (2020 előtti vagy 2027 utáni)",
        "SELECT id, kod, szamlaszam, kelt, teljesites_datum, fizetesi_hatarido FROM $t WHERE kelt < '2020-01-01' OR kelt > '2027-12-31' OR teljesites_datum < '2020-01-01'
            OR teljesites_datum > '2027-12-31' OR fizetesi_hatarido < '2020-01-01' OR fizetesi_hatarido > '2028-12-31'");
}
ell('FIGY', 'UTALVA_JOVO', 'jövőbeli utalási dátum', 'SELECT id, uid, utalva_datum FROM utalasok WHERE utalva_datum > CURDATE()');

// ---- 5. cégek ---------------------------------------------------------------------------------------------------------
ell('FIGY', 'CEG_URES', 'cég számla, kötés és utalás nélkül',
    'SELECT c.id, c.nev FROM cegek c WHERE NOT EXISTS (SELECT 1 FROM kotesek k WHERE k.ceg_id = c.id) AND NOT EXISTS (SELECT 1 FROM kimeno_szamlak s WHERE s.ceg_id = c.id)
        AND NOT EXISTS (SELECT 1 FROM utalasok u WHERE u.ceg_id = c.id)');
ell('FIGY', 'CEG_PARTNERKOD_DUPLA', 'ugyanaz a partnerkód több cégnél', 'SELECT partnerkod, GROUP_CONCAT(id) cegek FROM cegek WHERE partnerkod IS NOT NULL AND partnerkod <> \'\' GROUP BY partnerkod HAVING COUNT(*) > 1');

// ---- összesítés ----------------------------------------------------------------------------------------------------------
$szint = ['HIBA' => 0, 'FIGY' => 0, 'INFO' => 0];
$szoveg = [];
foreach ($talalat as [$sz, $kod, $leiras, $db, $minta]) {
    $szint[$sz]++;
    $szoveg[] = sprintf('%-4s %-24s %6d  %s', $sz, $kod, $db, $leiras);
    foreach (array_slice($minta, 0, 3) as $m) {
        $szoveg[] = '        ' . json_encode($m, JSON_UNESCAPED_UNICODE);
    }
}
$fej = sprintf("Adatbázis-ellenőrzés: %s (%s) – HIBA %d, FIGYELMEZTETÉS %d, INFO %d", DB_NAME, date('Y-m-d H:i'), $szint['HIBA'], $szint['FIGY'], $szint['INFO']);
$jelentes = $fej . "\n" . ($szoveg ? implode("\n", $szoveg) : 'Minden ellenőrzés rendben.') . "\n";
file_put_contents("$ki/ellenorzes.txt", $jelentes);
file_put_contents("$ki/ellenorzes.json", json_encode(array_map(fn($t) => ['szint' => $t[0], 'kod' => $t[1], 'leiras' => $t[2], 'db' => $t[3], 'minta' => $t[4]], $talalat),
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo $jelentes;
exit($szint['HIBA'] > 0 ? 1 : 0);
