<?php
/**
 * A migráció jelentése a felhasználónak (JELENTES.txt) és a betöltési útmutató (OLVASS_EL.txt) a kész adatbázisból és a
 * futás kimeneteiből (4_EREDMENY/<db>/: export, ib, ellenőrzések). Csak olvas.
 *
 *   kornyezet.sh php <db> /migracio/eszkozok/php/jelentes.php <a kész .sql fájl neve> [cégösszevonási lista] [kimeneti mappa]
 * A cégösszevonási lista „# KÜLÖN MARAD: …” sorai a jelentés „nem vontam össze” részébe kerülnek.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db.php';
require_once '/var/www/html/includes/helpers.php';

$sqlFajl = $argv[1] ?? 'BaninaPRO_migralt.sql';
$osszevonasFajl = $argv[2] ?? '';
$ki = rtrim($argv[3] ?? '/migracio/MIGRALT_SQL_EREDMENY_ADATBAZIS', '/');
$mappa = '/migracio/4_EREDMENY/' . DB_NAME;
$jsonOlv = static fn(string $f) => is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
$ered = $jsonOlv("$mappa/ib/eredmeny.json");
$elemz = $jsonOlv("$mappa/ib/elemzes.json");
$olvassel = is_file("$mappa/export/OLVASSEL.txt") ? (string)file_get_contents("$mappa/export/OLVASSEL.txt") : '';
$hu = static fn($o) => number_format((float)$o, 2, ',', ' ');
$L = [];
$a = static function (string $s = '') use (&$L): void {
    $L[] = $s;
};
$cim = static function (string $s) use ($a): void {
    $a('');
    $a($s);
    $a(str_repeat('-', 100));
};
$lista = static function (array $sorok, string $elo = '  • ') use ($a): void {
    foreach ($sorok as $s) {
        $a($elo . $s);
    }
};

$db = static fn(string $sql, array $p = []) => (int)db_val($sql, $p);
$a('BaninaPRO – MIGRÁLT ADATBÁZIS: DOS + IB System, ' . date('Y.m.d H:i'));
$a(str_repeat('=', 100));
$a("A fájl: $sqlFajl – a teljes BaninaPRO-adatbázis (minden tábla), phpMyAdminban importálható (lásd OLVASS_EL.txt).");

$cim('MI VAN BENNE');
$a(sprintf('  Cégek: %d · kötések: %d · bejövő számlák: %d (devizás %d, forintos %d) · kimenő számlák: %d · utalások: %d · banki kivonat-azonosítók: %d · részteljesítések: %d',
    $db('SELECT COUNT(*) FROM cegek'), $db('SELECT COUNT(*) FROM kotesek'), $db('SELECT COUNT(*) FROM bejovo_szamlak'),
    $db("SELECT COUNT(*) FROM bejovo_szamlak WHERE megjegyzes LIKE '%DOS azonosító:%'"), $db("SELECT COUNT(*) FROM bejovo_szamlak WHERE megjegyzes LIKE '%DOS forintszámla:%'"),
    $db('SELECT COUNT(*) FROM kimeno_szamlak'), $db('SELECT COUNT(*) FROM utalasok'), $db('SELECT COUNT(*) FROM banki_kivonatok'), $db('SELECT COUNT(*) FROM reszteljesitesek')));
$a('  Időszak: 2026.01.01-től MINDEN számla, a jövőbeli keltűek is (felső határ nélkül, a kérésed szerint).');
$a('  Felhasználó: csak a kezdő admin – felhasználónév: admin, jelszó: BaninaPRO-2026! Belépés után azonnal változtasd meg,');
$a('  és vedd fel a többieket (Admin → Felhasználók). A belépési eszközök (passkey) és a beállítások alapállapotban vannak.');
$a('');
$a('  Összesítés pénznemenként (a BaninaPRO saját képleteivel: teljes = minden számla, nyitott = a nyitott számlák hátraléka):');
foreach (db_all("SELECT 'bejövő' irany, k.penznem, COUNT(*) db, SUM(b.osszeg) teljes,
                        SUM(CASE WHEN b.statusz IN ('FIZETENDO','UTALASHOZ_ADVA') THEN b.osszeg - COALESCE(r.reszt, 0) ELSE 0 END) nyitott
                   FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id
                   LEFT JOIN (SELECT szamla_id, SUM(osszeg) reszt FROM reszteljesitesek WHERE irany = 'BEJOVO' GROUP BY szamla_id) r ON r.szamla_id = b.id GROUP BY k.penznem
                 UNION ALL
                 SELECT 'kimenő', s.penznem, COUNT(*), SUM(s.osszeg), SUM(CASE WHEN s.statusz = 'NYITOTT' THEN s.osszeg - COALESCE(r.reszt, 0) ELSE 0 END)
                   FROM kimeno_szamlak s LEFT JOIN (SELECT szamla_id, SUM(osszeg) reszt FROM reszteljesitesek WHERE irany = 'KIMENO' GROUP BY szamla_id) r ON r.szamla_id = s.id
                  GROUP BY s.penznem") as $r) {
    $a(sprintf('    %-7s %s  %6d db   teljes %18s   nyitott %18s', $r['irany'], $r['penznem'], $r['db'], $hu($r['teljes']), $hu($r['nyitott'])));
}

$cim('HONNAN JÖTT AZ ADAT');
$a('  BEJÖVŐ: kizárólag a DOS (a mai állapot, a DOS_ADATOK mappából). A 2026.10.07-i, a DOS saját listáival igazolt szabályok szerint:');
$a('    összeg = a DOS „Összesen”, fizetve = a DOS „Átutalva”, a fizetés napja = a DOS „Dátuma” (a terhelés); utalás = a DOS-utalás;');
$a('    a STORNO-tétel nem kerül át (a javított párja igen); kifizetett számla ki nem fizetett DOS-másodpéldánya nem kerül át.');
$a('  KIMENŐ: az IB System a pontos a számlaszámban, összegben, dátumokban és a vevőben; a DOS a fizetésben (befolyás, dátuma).');
$a('    Ami csak az IB-ben van: NYITOTT (fizetési adat nélkül). A DOS-ban fizetett számla fizetett marad (a te szabályod szerint).');
$a('  CÉGEK: a DOS partnertörzséből, az azonos adószámúak egy céggé vonva, plusz a jóváhagyott kézi összevonások.');

$cim('A DÖNTÉSEID SZERINT');
$kezi = [];
if (preg_match('/KÉZI CÉGÖSSZEVONÁSOK.*?\n-+\n(.*?)\n\n/su', $olvassel, $m)) {
    $kezi = array_values(array_filter(array_map('trim', explode("\n", $m[1]))));
}
$a('  Cégösszevonások (' . count($kezi) . '; az első 8 a 10-07-i, a többi a mai döntésed):');
$lista($kezi, '    ');
$kulon = [];
if ($osszevonasFajl !== '' && is_file($osszevonasFajl)) {
    foreach (file($osszevonasFajl) ?: [] as $s) {
        if (preg_match('/^#\s*KÜLÖN MARAD:\s*(.+)$/u', trim($s), $m)) {
            $kulon[] = trim($m[1]);
        }
    }
}
if ($kulon) {
    $a('  Nem vontam össze (a döntésed szerint külön marad):');
    $lista($kulon, '    ');
}
$vevo = $ered['vevo_ib_szerint'] ?? [];
$a('');
$a('  Kimenő számla az IB szerinti vevőnél (' . count($vevo) . ' db; a DOS-ban más vevőnél volt rögzítve – az IB a pontos):');
$lista($vevo, '    ');
$pd = $elemz['ib_partner_dontes'] ?? [];
if ($pd) {
    $a('    Ebből IB-partnerként külön cégre került (a DOS-ban egy másik vevőnél voltak a számláik):');
    $lista($pd, '      ');
}
$dup = $ered['dupla_torolve'] ?? [];
$a('');
$a('  Törölve: a DOS-ban kétszer rögzített számla NYITOTT, elírt számú másodpéldánya (' . count($dup) . ' db; a kifizetett párja megmaradt, az IB csak azt ismeri):');
$lista($dup, '    ');

$cim('AZ IB SYSTEM SZERINT JAVÍTVA (kimenő)');
$ossz = $elemz['osszesites'] ?? [];
$a(sprintf('  %d IB-sor: %d meglévő számla adatai javítva az IB szerint (főleg a kelt: a DOS-ban a kelt a teljesítés napja volt), %d összevonás (a DOS-ban elírt számlaszám),',
    count($elemz['elter'] ?? []) + count($elemz['uj'] ?? []) + ($ossz['letezik'] ?? 0) + count($elemz['hiba'] ?? []), $ered['stat']['javitva'] ?? 0, $ered['stat']['osszevont'] ?? 0));
$a(sprintf('  %d új számla (csak az IB-ben volt), %d cég adata frissítve (IB-partnerkód, adószám).', $ered['stat']['kimeno'] ?? 0, $ered['stat']['ceg_frissitve'] ?? 0));
$a('  Összevont (a DOS-ban elírt) számlaszámok:');
$lista(array_map(fn($s) => "{$s['letezo']['szamlaszam']} → {$s['szamlaszam']} ({$s['ceg']}, " . $hu($s['osszeg']) . ')', $elemz['osszevonas'] ?? []), '    ');
$osszegJav = [];
foreach ($elemz['elter'] ?? [] as $s) {
    foreach ($s['elteresek'] as $e) {
        if (str_starts_with($e, 'Összeg')) {
            $rendezve = (bool)array_filter($ered['reszt_rendezve'] ?? [], fn($x) => str_contains($x, "„{$s['szamlaszam']}”"));
            $osszegJav[] = "{$s['szamlaszam']} ({$s['ceg']}): " . ($rendezve ? preg_replace('/ \(nem javítható:.*$/u', ' – az app importja a részteljesítés miatt nem javította; az utólépés igen (lásd lent)', $e) : $e);
        }
    }
}
$a('  Összeg az IB szerint (' . count($osszegJav) . '):');
$lista($osszegJav, '    ');
$a('    A DOS-ban kifizetett számla az IB-összeggel is kifizetett marad (néhány centes eltérésnél sem lesz belőle nyitott tartozás).');
$lista(array_map(fn($x) => "Részteljesítés helyett FIZETVE: $x", $ered['reszt_rendezve'] ?? []), '    ');
$lista(array_map(fn($x) => "Számlaszám az IB írásmódja szerint: $x", $ered['szamlaszam_ib_szerint'] ?? []), '    ');
foreach ($ered['dos_elirasok'] ?? [] as $x) {
    $a("    DOS-elírás: {$x['rendszer']} – az IB-ben ez a szám: {$x['ib']}");
}
$lista(array_map(fn($x) => "0 összegű IB-számla felvéve (a teljes számsor miatt): $x", $ered['nullas_felveve'] ?? []), '    ');

$cim('CSAK AZ IB-BEN VOLT (új, NYITOTT kimenő számlák) – havonta');
foreach (db_all("SELECT DATE_FORMAT(kelt, '%Y.%m') ho, penznem, COUNT(*) db, SUM(osszeg) osszeg FROM kimeno_szamlak WHERE COALESCE(megjegyzes, '') NOT LIKE '%DOS azonosító:%' GROUP BY ho, penznem ORDER BY ho") as $r) {
    $a(sprintf('  %s  %s  %4d db  %16s', $r['ho'], $r['penznem'], $r['db'], $hu($r['osszeg'])));
}
$a('  Az októberiek a DOS-ban még nem voltak rögzítve. A korábbiak a DOS-ból hiányoznak – érdemes megnézni, befolytak-e:');
$lista(array_map(fn($r) => "{$r['szamlaszam']}  {$r['nev']}  kelt {$r['kelt']}  " . $hu($r['osszeg']) . " {$r['penznem']}",
    db_all("SELECT s.szamlaszam, c.nev, s.kelt, s.osszeg, s.penznem FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id
             WHERE COALESCE(s.megjegyzes, '') NOT LIKE '%DOS azonosító:%' AND s.kelt < '2026-10-01' ORDER BY s.kelt")), '    ');

$cim('DOS-ADATHIBÁK – a BaninaPRO a DOS-t tükrözi; ha a DOS-ban / a BaninaPRO-ban javítjátok, az a helyes');
$sor = static fn(array $rs, callable $f) => array_map($f, $rs);
$lista($sor(db_all("SELECT s.szamlaszam, c.nev, s.fizetve_datum FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id WHERE s.fizetve_datum < '2026-01-01' ORDER BY s.szamlaszam"),
    fn($r) => "Kimenő {$r['szamlaszam']} ({$r['nev']}): a DOS-ban a befolyás dátuma {$r['fizetve_datum']} (elírás?)"));
$lista($sor(db_all("SELECT b.kod, b.szamlaszam, c.nev, b.teljesites_datum FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id
                    WHERE b.teljesites_datum < '2020-01-01' OR b.kelt < '2020-01-01'"), fn($r) => "Bejövő {$r['kod']} „{$r['szamlaszam']}” ({$r['nev']}): a DOS-ban a teljesítés {$r['teljesites_datum']}"));
$lista($sor(db_all("SELECT uid, banki_hivatkozas, utalva_datum FROM utalasok WHERE utalva_datum > CURDATE() ORDER BY uid"),
    fn($r) => "Utalás {$r['uid']} ({$r['banki_hivatkozas']}): a DOS-ban jövőbeli dátum: {$r['utalva_datum']}"));
$lista($sor(db_all("SELECT b.kod, b.szamlaszam, c.nev, b.kelt, b.osszeg, k.penznem FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id
                    WHERE b.kelt > CURDATE() ORDER BY b.kod"), fn($r) => "Bejövő {$r['kod']} „{$r['szamlaszam']}” ({$r['nev']}): jövőbeli kelt a DOS-ban ({$r['kelt']}), " . $hu($r['osszeg']) . " {$r['penznem']}"));
$n = $db("SELECT COUNT(*) FROM bejovo_szamlak WHERE statusz IN ('FIZETVE','BESZAMITVA') AND fizetve_datum IS NULL");
$a("  • $n fizetett bejövő számlánál a DOS-ban még nincs terhelési dátum (00.00.00): FIZETVE, dátum nélkül.");
$lista($sor(db_all("SELECT c.nev FROM cegek c WHERE NOT EXISTS (SELECT 1 FROM kotesek k WHERE k.ceg_id = c.id) AND NOT EXISTS (SELECT 1 FROM kimeno_szamlak s WHERE s.ceg_id = c.id)
                    AND NOT EXISTS (SELECT 1 FROM utalasok u WHERE u.ceg_id = c.id) ORDER BY c.nev"), fn($r) => "Üres cég (az egyetlen számlája az IB szerinti vevőhöz került): {$r['nev']}"));
if (preg_match('/A DOS-BAN TALÁLT ADATHIBÁK.*?\n-+\n(.*?)\n\n/su', $olvassel, $m)) {
    $a('  A DOS saját adathibái (a 10-07-i szabályok szerint jelölve, részletesen: DOS_ATVETEL_RESZLETEK.txt):');
    $lista(array_values(array_filter(array_map('trim', explode("\n", $m[1])))), '    ');
}

$cim('ELLENŐRZÉSEK (a futás tényleges eredményei)');
$fo = static fn(string $f) => is_file("$mappa/$f") ? (string)file_get_contents("$mappa/$f") : '';
$t09 = $fo('09_ellenorzes.txt');
$a(sprintf('  1. DOS-ellenőrző lekérdezés (a tiszta DOS-állapoton: darabszámok, cégenkénti és utalásonkénti DOS-egyenlegek): %d OK, %d ELTÉR.',
    preg_match_all('/\tOK$/m', $t09), substr_count($t09, 'ELTÉR')));
$a('  2. ' . (strtok($fo('ellenorzes.txt'), "\n") ?: 'adatbázis-ellenőrzés: nincs eredmény'));
foreach (['_dos' => 'a tiszta DOS-állapot', '' => 'a végleges adatbázis'] as $ut => $nev) {
    $t = $fo('fuggetlen_' . DB_NAME . "$ut.txt");
    preg_match('/Utalások: .*/u', $t, $u1);
    $elt = preg_match('/ELTÉRÉSEK:\n(.*)/su', $t, $e1) ? trim($e1[1]) : '';
    $csakDupla = preg_match('/^IMELSZ: a DOS szerint kellene, az adatbázisban nincs: (\d+) /u', $elt, $dm) && substr_count($elt, "\n") === 0
        && (int)$dm[1] === count($ered['dupla_torolve'] ?? []);
    $a("  3. Független DOS-ellenőrzés (saját bájtpozíciós DOS-olvasó, soronként) – $nev: " . ($u1[0] ?? '') . '; '
        . (str_contains($t, 'NINCS ELTÉRÉS') ? 'NINCS ELTÉRÉS'
            : ($csakDupla ? "a DOS-hoz képest csak a jóváhagyottan törölt {$dm[1]} másodpéldány hiányzik; minden más (bejövő, utalás, a kimenők fizetése) egyezik"
                : 'eltérés: ' . preg_replace('/\s+/', ' ', mb_substr($elt, 0, 400)))));
}
$z = $jsonOlv("$mappa/ib_zaro/elemzes.json");
if ($z) {
    $zo = $z['osszesites'];
    $a(sprintf('  4. Az IB System újraelemzése a kész adatbázison: új %d, megvan %d, eltér %d, más cégnél %d, hibás %d (a 0 összegű sor, amelyet felvettem).',
        $zo['uj'], $zo['letezik'], $zo['elter'], $zo['mas_ceg'], $zo['hiba']));
}
$a(sprintf('     Kimenő összesen: EUR %s, HUF %s.', $hu(db_val("SELECT SUM(osszeg) FROM kimeno_szamlak WHERE penznem = 'EUR'")), $hu(db_val("SELECT SUM(osszeg) FROM kimeno_szamlak WHERE penznem = 'HUF'"))));
$vt = $fo('visszatoltes_osszevetes.txt');
if ($vt !== '') {
    $nulla = preg_match_all('/^\w+\s+\d+\s+\d+\s+0\s+0\s+0\s*$/m', $vt);
    $a("  5. A mentés visszatöltve egy üres adatbázisba és rekordonként összevetve: $nulla/7 táblában 0 eltérés.");
}
$kezi = $fo('kezi_ellenorzesek.txt');
if ($kezi !== '') {
    $a('  Az átadás előtt kézzel is:');
    $lista(array_values(array_filter(array_map('trim', explode("\n", $kezi)))), '    ');
}
@mkdir($ki, 0775, true);
file_put_contents("$ki/JELENTES.txt", implode("\n", $L) . "\n");
if ($olvassel !== '') {
    file_put_contents("$ki/DOS_ATVETEL_RESZLETEK.txt", $olvassel);
}

$O = <<<TXT
BaninaPRO – A MIGRÁLT ADATBÁZIS BETÖLTÉSE phpMyAdminnal (a belső szerveren)
==============================================================================
Fájl: $sqlFajl

FIGYELEM: a fájl a BaninaPRO adatbázis ÖSSZES tábláját eldobja és újra létrehozza. A mostani (teszt) adatok, a
felhasználók, a passkey-ek és a beállítások helyére a migrált adatbázis kerül – a felhasználókat utána kell felvenni.

1. Biztonsági mentés a mostani állapotról: a szerver asztalán a „DB MENTÉS” ikon (ha bármi nem sikerül, ebből visszaállítható).
2. A betöltés alatt ne dolgozzon senki a BaninaPRO-ban (kb. 1 perc).
3. Böngészőben: http://<a szerver neve vagy IP-címe>:8081 – a phpMyAdmin belépés (BaninaPRO felhasználó és a jelszava).
4. Bal oldalt kattints a BaninaPRO adatbázisára (baninapr_DATA).
5. Felül: Importálás (Import) → „Fájl kiválasztása”: $sqlFajl
   A többi beállítás maradhat az alapértéken (karakterkódolás: utf-8, formátum: SQL). Lent: Importálás (Import / Go).
6. Siker esetén ezt írja: „Az importálás sikeresen befejeződött, 103 lekérdezés végrehajtva.”
   (Kipróbálva phpMyAdminnal, a szerverrel azonos MariaDB 10.11-en: kb. 10 másodperc, utána rekordra pontos egyezés.)
7. BaninaPRO: belépés – felhasználónév: admin, jelszó: BaninaPRO-2026!
   Azonnal: Profil → jelszócsere; Admin → Felhasználók: a munkatársak felvétele.

Ha a phpMyAdmin hibát ír: semmi gond – az 1. lépés mentését a szerver „SQL IMPORT” ikonjával vissza lehet tölteni.
Ugyanezt a fájlt az „SQL IMPORT” ikon is be tudja tölteni (előtte magától mentést készít, hiba esetén visszaállít).

A részletek: JELENTES.txt (mi került át, a döntéseid szerint mi változott, mit érdemes ellenőrizni).
TXT;
file_put_contents("$ki/OLVASS_EL.txt", $O . "\n");
echo implode("\n", $L) . "\n";
