<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

/**
 * BaninaPRO – teljes adatbázis-mentés és visszaállítás (DBBCKP mappa)
 *
 *  - db_mentes():        minden tábla szerkezete (CREATE TABLE) + minden sora egy .sql fájlba,
 *                        egyetlen konzisztens pillanatképből (InnoDB, REPEATABLE READ snapshot).
 *                        Fájlnév: ÉÉÉÉHHNN_ÓÓPP.sql (kézi: …_kezi.sql, visszaállítás előtti:
 *                        …_visszaallitas_elott.sql, cron nélküli pótlás: …_potlas.sql, feltöltött: …_feltoltott.sql)
 *  - db_visszaallit():   ELŐBB automatikus teljes mentés, utána a kiválasztott fájl végrehajtása;
 *                        hiba esetén az előzetes mentést visszatölti.
 *  - mentes_ha_esedekes(): ha a cron 03:00-kor nem futott le, az első használatkor pótolja.
 *
 *  Nincs külső függőség (mysqldump nem kell), a fájl phpMyAdmin-ban is importálható.
 */

const BK_FEJLEC = '-- BaninaPRO adatbázis-mentés';
const BK_LABLEC = '-- Mentés vége';
const BK_MODOK = [
    'auto'                => 'automatikus (éjszakai)',
    'potlas'              => 'automatikus (pótlás)',
    'kezi'                => 'kézi',
    'visszaallitas_elott' => 'visszaállítás előtti',
    'import_elott'        => 'Excel import előtti',
    'feltoltott'          => 'feltöltött',
    'idegen'              => 'külső fájl',
];
const BK_UTASITAS_MAX = 512 * 1024;   // egy INSERT legfeljebb ekkora (bájt) – max_allowed_packet alatt marad
const BK_SOR_MAX = 1000;              // egy INSERT legfeljebb ennyi sor

final class MentesFoglalt extends RuntimeException {}

/** A DBBCKP mappa (létrehozza és webről elzárja, ha kell) */
function backup_dir(): string
{
    $dir = rtrim(BACKUP_DIR, '/');
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("A mentések mappája nem hozható létre: $dir");
        }
    }
    if (!is_file("$dir/.htaccess")) {
        @file_put_contents("$dir/.htaccess", "# Ez a mappa webről NEM érhető el\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file("$dir/index.php")) {
        @file_put_contents("$dir/index.php", "<?php http_response_code(403); exit;\n");
    }
    return realpath($dir) ?: $dir;
}

/** Fájlnév ellenőrzése (csak a DBBCKP mappán belüli .sql, útvonal-trükkök nélkül) → teljes útvonal */
function backup_fajl_utvonal(string $nev, bool $letezzen = true): string
{
    if (!preg_match('/^[A-Za-z0-9._-]{5,120}\.sql$/', $nev) || str_contains($nev, '..') || $nev[0] === '.') {
        throw new RuntimeException('Érvénytelen mentésfájl-név.');
    }
    $ut = backup_dir() . '/' . $nev;
    if ($letezzen && !is_file($ut)) {
        throw new RuntimeException('A mentésfájl nem található: ' . $nev);
    }
    return $ut;
}

/** Zárolás: egyszerre csak egy mentés/visszaállítás futhat */
function backup_zar()
{
    $f = @fopen(backup_dir() . '/.zar', 'c');
    if (!$f) {
        throw new RuntimeException('A mentések mappája nem írható (DBBCKP).');
    }
    if (!flock($f, LOCK_EX | LOCK_NB)) {
        fclose($f);
        throw new MentesFoglalt('Éppen fut egy mentés vagy visszaállítás – próbáld újra egy perc múlva.');
    }
    return $f;
}

function backup_zar_felold($f): void
{
    if (is_resource($f)) {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

/** SQL string-literál (MySQL escape, sortörés nélkül – egy sor = egy rekord) */
function sql_szoveg(string $s): string
{
    return "'" . strtr($s, ["\\" => "\\\\", "'" => "\\'", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z"]) . "'";
}

/**
 * Teljes adatbázis-mentés.
 * @param string $mod  auto | potlas | kezi | visszaallitas_elott
 * @param string $ki   ki kérte (naplóhoz: felhasználónév / cron / app)
 * @param bool   $zarol false, ha a hívó már zárolt (visszaállítás közben)
 */
function db_mentes(string $mod = 'auto', string $ki = 'cron', bool $zarol = true): array
{
    if (!isset(BK_MODOK[$mod]) || in_array($mod, ['idegen', 'feltoltott'], true)) {
        $mod = 'kezi';
    }
    $t0 = microtime(true);
    $dir = backup_dir();
    $zar = $zarol ? backup_zar() : null;
    $tmp = null;
    $pdo = db();
    $snapshot = false;
    try {
        if (!is_writable($dir)) {
            throw new RuntimeException("A mentések mappája nem írható: $dir");
        }
        set_time_limit(0);
        $nev = date('Ymd_Hi') . ($mod === 'auto' ? '' : '_' . $mod) . '.sql';
        $alap = substr($nev, 0, -4);
        for ($i = 2; is_file("$dir/$nev"); $i++) {
            $nev = "{$alap}_$i.sql";
        }
        $tmp = "$dir/.$nev.tmp";
        $f = @fopen($tmp, 'wb');
        if (!$f) {
            throw new RuntimeException("A mentésfájl nem hozható létre: $nev");
        }

        // egyetlen konzisztens pillanatkép az összes tábláról
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $snapshot = true;
        $pdo->exec("SET time_zone = '+00:00'");

        $verzio = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $tablak = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $nezetek = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);
        $triggerek = $pdo->query('SHOW TRIGGERS')->fetchAll();

        $ir = function (string $s) use ($f, $nev) {
            if (fwrite($f, $s) === false) {
                throw new RuntimeException("Írási hiba a mentésfájlban ($nev) – betelt a tárhely?");
            }
        };
        $ir(BK_FEJLEC . "\n");
        $ir('-- Adatbázis: ' . DB_NAME . "\n");
        $ir('-- Készült: ' . date('Y-m-d H:i:s') . ' (' . APP_TIMEZONE . ")\n");
        $ir('-- Típus: ' . $mod . ' – ' . BK_MODOK[$mod] . ' | Kérte: ' . $ki . "\n");
        $ir('-- Alkalmazás: ' . APP_NAME . ' ' . APP_VERSION . ' | Szerver: ' . $verzio . ' | PHP ' . PHP_VERSION . "\n");
        $ir("-- Tartalom: MINDEN tábla teljes szerkezete (CREATE TABLE) és MINDEN sora egyetlen konzisztens pillanatképből.\n");
        $ir("-- Visszaállítás: Admin → Adatbázis-mentések → Visszaállítás (előtte automatikusan új mentés készül),\n");
        $ir("--   vagy phpMyAdmin → Import. FIGYELEM: a fájl a táblákat eldobja és újra létrehozza a mentett tartalommal!\n");
        if ($triggerek) {
            $ir('-- FIGYELEM: az adatbázisban ' . count($triggerek) . " trigger van, ezeket a mentés NEM tartalmazza.\n");
        }
        $ir("\nSET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\nSET time_zone = '+00:00';\nSET sql_mode = 'NO_AUTO_VALUE_ON_ZERO,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n");

        $osszSor = 0;
        foreach ($tablak as [$tabla]) {
            $tq = '`' . str_replace('`', '``', $tabla) . '`';
            $create = $pdo->query("SHOW CREATE TABLE $tq")->fetch(PDO::FETCH_NUM);
            $oszlopok = $pdo->query("SHOW COLUMNS FROM $tq")->fetchAll();
            $nevek = [];
            $binaris = [];
            $szam = [];
            foreach ($oszlopok as $i => $o) {
                $nevek[] = '`' . str_replace('`', '``', $o['Field']) . '`';
                $tip = strtolower((string)$o['Type']);
                $binaris[$i] = (bool)preg_match('/^(tiny|medium|long)?blob|^(var)?binary|^bit\b/', $tip);
                $szam[$i] = (bool)preg_match('/^(tiny|small|medium|big)?int|^decimal|^numeric|^float|^double|^real|^year/', $tip);
            }
            $db = (int)$pdo->query("SELECT COUNT(*) FROM $tq")->fetchColumn();
            $ir("\n-- ---------------------------------------------------------------\n-- Tábla: $tq ($db sor)\n-- ---------------------------------------------------------------\n");
            $ir("DROP TABLE IF EXISTS $tq;\n");
            $ir($create[1] . ";\n");
            if ($db === 0) {
                continue;
            }
            $ir("\n");
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $st = $pdo->query("SELECT * FROM $tq");
                $fej = "INSERT INTO $tq (" . implode(', ', $nevek) . ") VALUES\n";
                $puffer = '';
                $pufferSor = 0;
                while (($sor = $st->fetch(PDO::FETCH_NUM)) !== false) {
                    $ertekek = [];
                    foreach ($sor as $i => $v) {
                        if ($v === null) {
                            $ertekek[] = 'NULL';
                        } elseif ($binaris[$i]) {
                            $ertekek[] = $v === '' ? "''" : '0x' . bin2hex((string)$v);
                        } elseif ($szam[$i] && preg_match('/^-?\d+(\.\d+)?$/', (string)$v)) {
                            $ertekek[] = (string)$v;
                        } else {
                            $ertekek[] = sql_szoveg((string)$v);
                        }
                    }
                    $puffer .= ($pufferSor ? ",\n" : '') . '(' . implode(',', $ertekek) . ')';
                    $pufferSor++;
                    $osszSor++;
                    if ($pufferSor >= BK_SOR_MAX || strlen($puffer) >= BK_UTASITAS_MAX) {
                        $ir($fej . $puffer . ";\n");
                        $puffer = '';
                        $pufferSor = 0;
                    }
                }
                if ($pufferSor) {
                    $ir($fej . $puffer . ";\n");
                }
                $st->closeCursor();
            } finally {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
        foreach ($nezetek as [$nezet]) {
            $nq = '`' . str_replace('`', '``', $nezet) . '`';
            $cv = $pdo->query("SHOW CREATE VIEW $nq")->fetch(PDO::FETCH_NUM);
            $ir("\n-- Nézet: $nq\nDROP VIEW IF EXISTS $nq;\n" . $cv[1] . ";\n");
        }
        $ir("\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n");
        $ir(BK_LABLEC . ': ' . count($tablak) . ' tábla, ' . $osszSor . ' sor, ' . date('Y-m-d H:i:s') . "\n");
        if (!fflush($f) || !fclose($f)) {
            throw new RuntimeException('A mentésfájl lezárása sikertelen.');
        }
        $pdo->exec('COMMIT');
        $snapshot = false;
        $pdo->exec('SET time_zone = ' . $pdo->quote(date('P')));

        // ellenőrzés: a fájl teljes (záró sor a helyén), majd végleges név
        $info = db_mentes_info($tmp);
        if (!$info['teljes'] || $info['tablak'] !== count($tablak)) {
            throw new RuntimeException('A mentésfájl ellenőrzése sikertelen (csonka fájl).');
        }
        if (!@rename($tmp, "$dir/$nev")) {
            throw new RuntimeException('A mentésfájl átnevezése sikertelen.');
        }
        $tmp = null;
        $meret = (int)filesize("$dir/$nev");
        $mp = round(microtime(true) - $t0, 2);
        $eredmeny = ['fajl' => $nev, 'utvonal' => "$dir/$nev", 'meret' => $meret, 'tablak' => count($tablak), 'sorok' => $osszSor, 'mod' => $mod, 'ido' => date('Y-m-d H:i:s'), 'masodperc' => $mp];
        $jelzo = utolso_jelzo();
        $jelzo = ['ido' => time(), 'fajl' => $nev, 'mod' => $mod, 'ki' => $ki, 'meret' => $meret, 'tablak' => count($tablak), 'sorok' => $osszSor,
                  'auto_ido' => ($mod === 'auto' || $mod === 'potlas') ? time() : (int)($jelzo['auto_ido'] ?? 0)];
        @file_put_contents("$dir/.utolso.json", json_encode($jelzo, JSON_UNESCAPED_UNICODE), LOCK_EX);
        naplo('DB_MENTES', "$nev – " . count($tablak) . " tábla, $osszSor sor, " . round($meret / 1024) . " kB, $mp mp (" . BK_MODOK[$mod] . ')', 'OK', $ki);
        if ($mod === 'auto' || $mod === 'potlas') {
            mentesek_takaritas($ki);
        }
        return $eredmeny;
    } catch (Throwable $e) {
        if ($snapshot) {
            try { $pdo->exec('ROLLBACK'); $pdo->exec('SET time_zone = ' . $pdo->quote(date('P'))); } catch (Throwable $x) { /* mindegy */ }
        }
        if ($tmp && is_file($tmp)) {
            @unlink($tmp);
        }
        if (!$e instanceof MentesFoglalt) {
            naplo('DB_MENTES', 'HIBA: ' . $e->getMessage(), 'HIBA', $ki);
        }
        throw $e;
    } finally {
        backup_zar_felold($zar);
    }
}

/** Régi AUTOMATIKUS mentések törlése (BACKUP_MEGORZES_NAP > 0 esetén); kézi / visszaállítás előtti sosem törlődik */
function mentesek_takaritas(string $ki): void
{
    $nap = (int)BACKUP_MEGORZES_NAP;
    if ($nap <= 0) {
        return;
    }
    $lista = array_values(array_filter(db_mentesek_lista(), fn($m) => in_array($m['mod'], ['auto', 'potlas'], true)));
    // a legfrissebb 7 automatikus mentés mindig marad
    $hatar = time() - $nap * 86400;
    foreach (array_slice($lista, 7) as $m) {
        if ($m['ido'] < $hatar) {
            if (@unlink($m['utvonal'])) {
                naplo('DB_MENTES_TOROL', $m['fajl'] . ' automatikusan törölve (' . $nap . ' napnál régebbi)', 'OK', $ki);
            }
        }
    }
}

/** Egy mentésfájl gyors átvizsgálása (fejléc + záró sor) */
function db_mentes_info(string $ut): array
{
    $meret = (int)@filesize($ut);
    $eleje = (string)@file_get_contents($ut, false, null, 0, 2048);
    $vege = $meret > 4096 ? (string)@file_get_contents($ut, false, null, $meret - 4096) : $eleje;
    $sajat = str_starts_with($eleje, BK_FEJLEC);
    $tablak = null;
    $sorok = null;
    $teljes = false;
    if (preg_match('/^' . preg_quote(BK_LABLEC, '/') . ': (\d+) tábla, (\d+) sor/mu', $vege, $m)) {
        $teljes = true;
        $tablak = (int)$m[1];
        $sorok = (int)$m[2];
    }
    $keszult = null;
    if (preg_match('/^-- Készült: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/mu', $eleje, $m)) {
        $keszult = $m[1];
    }
    $verzio = null;
    if (preg_match('/^-- Alkalmazás: BaninaPRO ([0-9.]+)/mu', $eleje, $m)) {
        $verzio = $m[1];
    }
    return ['sajat' => $sajat, 'teljes' => $teljes, 'tablak' => $tablak, 'sorok' => $sorok, 'keszult' => $keszult, 'verzio' => $verzio, 'meret' => $meret];
}

/** A DBBCKP mappa mentései, legfrissebb elöl */
function db_mentesek_lista(): array
{
    $dir = backup_dir();
    $ki = [];
    foreach (glob("$dir/*.sql") ?: [] as $ut) {
        $nev = basename($ut);
        if ($nev[0] === '.') {
            continue;
        }
        $mod = 'idegen';
        $ido = (int)@filemtime($ut);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(?:_([a-z_]+?))?(?:_\d+)?\.sql$/', $nev, $m)) {
            $t = mktime((int)$m[4], (int)$m[5], 0, (int)$m[2], (int)$m[3], (int)$m[1]);
            if ($t !== false) {
                $ido = $t;
            }
            $mod = $m[6] ?? '';
            $mod = $mod === '' ? 'auto' : (isset(BK_MODOK[$mod]) ? $mod : 'idegen');
        }
        $ki[] = ['fajl' => $nev, 'utvonal' => $ut, 'meret' => (int)@filesize($ut), 'ido' => $ido, 'mod' => $mod, 'mod_nev' => BK_MODOK[$mod], 'modositva' => (int)@filemtime($ut)];
    }
    usort($ki, fn($a, $b) => ($b['ido'] <=> $a['ido']) ?: ($b['modositva'] <=> $a['modositva']) ?: strcmp($b['fajl'], $a['fajl']));
    return $ki;
}

/**
 * SQL fájl felbontása utasításokra (idézőjelek, backtick, \-escape, --/# sorkomment, /* * / blokk-komment,
 * a /*!... * / feltételes utasítások megmaradnak). Minden utasításra meghívja $cb(string $sql).
 * Visszaadja a végrehajtott utasítások számát.
 */
function sql_utasitasok(string $ut, callable $cb): int
{
    $f = @fopen($ut, 'rb');
    if (!$f) {
        throw new RuntimeException('A mentésfájl nem olvasható.');
    }
    $db = 0;
    $puffer = '';
    $allapot = 0;          // 0 normál, 1 '…', 2 "…", 3 `…`, 4 blokk-komment (eldobva), 5 blokk-komment (megtartva /*!)
    $kibocsat = function () use (&$puffer, &$db, $cb) {
        $sql = trim($puffer);
        $puffer = '';
        if ($sql === '' || $sql === ';') {
            return;
        }
        $db++;
        $cb($sql);
    };
    try {
        while (($sor = fgets($f)) !== false) {
            $n = strlen($sor);
            $p = 0;
            if ($allapot === 0 && $puffer === '') {
                $t = ltrim($sor);
                if ($t === '' || str_starts_with($t, '--') || $t[0] === '#') {
                    continue;
                }
                if (preg_match('/^DELIMITER\s/i', $t)) {
                    throw new RuntimeException('A fájl DELIMITER utasítást tartalmaz (trigger/eljárás) – ezt a beépített visszaállító nem támogatja, importáld phpMyAdmin-ban.');
                }
            }
            while ($p < $n) {
                if ($allapot === 0) {
                    $k = strcspn($sor, "'\"`;#-/", $p);
                    $puffer .= substr($sor, $p, $k);
                    $p += $k;
                    if ($p >= $n) {
                        break;
                    }
                    $c = $sor[$p];
                    if ($c === "'") { $allapot = 1; $puffer .= $c; $p++; }
                    elseif ($c === '"') { $allapot = 2; $puffer .= $c; $p++; }
                    elseif ($c === '`') { $allapot = 3; $puffer .= $c; $p++; }
                    elseif ($c === ';') { $p++; $kibocsat(); }
                    elseif ($c === '#') { break; } // sor vége komment
                    elseif ($c === '-') {
                        if (substr($sor, $p, 2) === '--' && ($p + 2 >= $n || ctype_space($sor[$p + 2]))) { break; }
                        $puffer .= $c; $p++;
                    } else { // '/'
                        if (substr($sor, $p, 2) === '/*') {
                            $allapot = substr($sor, $p, 3) === '/*!' ? 5 : 4;
                            if ($allapot === 5) { $puffer .= '/*'; }
                            $p += 2;
                        } else { $puffer .= $c; $p++; }
                    }
                } elseif ($allapot === 1 || $allapot === 2 || $allapot === 3) {
                    $q = $allapot === 1 ? "'" : ($allapot === 2 ? '"' : '`');
                    $k = strcspn($sor, $allapot === 3 ? $q : $q . '\\', $p);
                    $puffer .= substr($sor, $p, $k);
                    $p += $k;
                    if ($p >= $n) {
                        break;
                    }
                    if ($sor[$p] === '\\' && $allapot !== 3) {
                        $puffer .= substr($sor, $p, 2);
                        $p += 2;
                    } elseif ($sor[$p] === $q) {
                        // '' és "" dupla idézőjel = escape-elt idézőjel
                        if ($p + 1 < $n && $sor[$p + 1] === $q) {
                            $puffer .= $q . $q;
                            $p += 2;
                        } else {
                            $puffer .= $q;
                            $p++;
                            $allapot = 0;
                        }
                    }
                } else { // blokk-komment
                    $v = strpos($sor, '*/', $p);
                    if ($v === false) {
                        if ($allapot === 5) { $puffer .= substr($sor, $p); }
                        break;
                    }
                    if ($allapot === 5) { $puffer .= substr($sor, $p, $v + 2 - $p); }
                    $p = $v + 2;
                    $allapot = 0;
                }
            }
            if ($allapot === 1 || $allapot === 2 || $allapot === 3) {
                // többsoros string-literál: a sortörés is a string része
                if (!str_ends_with($puffer, "\n")) { $puffer .= "\n"; }
            } elseif ($allapot === 0) {
                if (trim($puffer) === '') { $puffer = ''; }
                elseif (!str_ends_with($puffer, "\n")) { $puffer .= "\n"; }
            }
        }
        if (trim($puffer) !== '') {
            $kibocsat();
        }
    } finally {
        fclose($f);
    }
    return $db;
}

/**
 * Visszaállítás: 1) teljes mentés a mostani állapotról, 2) a kiválasztott fájl végrehajtása,
 * 3) hiba esetén az 1) mentés visszatöltése. Közben a többi kérést a karbantartás-jelző állítja meg.
 */
function db_visszaallit(string $nev, string $ki): array
{
    $t0 = microtime(true);
    $ut = backup_fajl_utvonal($nev);
    $info = db_mentes_info($ut);
    if ($info['meret'] < 100) {
        throw new RuntimeException('A mentésfájl üres vagy sérült.');
    }
    if ($info['sajat'] && !$info['teljes']) {
        throw new RuntimeException('A mentésfájl csonka (hiányzik a záró „Mentés vége” sor) – ebből nem szabad visszaállítani.');
    }
    $eleje = (string)@file_get_contents($ut, false, null, 0, 262144);
    if (!preg_match('/^\s*CREATE TABLE/mi', $eleje) && !preg_match('/^\s*INSERT INTO/mi', $eleje)) {
        throw new RuntimeException('A fájl nem tűnik SQL-mentésnek (nincs benne CREATE TABLE / INSERT INTO).');
    }
    $dir = backup_dir();
    $zar = backup_zar();
    $pdo = db();
    @file_put_contents("$dir/.karbantartas", (string)time());
    set_time_limit(0);
    ignore_user_abort(true);
    try {
        // 1) előzetes mentés – enélkül nincs visszaállítás
        $elotte = db_mentes('visszaallitas_elott', $ki, false);
        naplo('DB_VISSZAALLITAS', "indul: $nev (" . round($info['meret'] / 1024) . ' kB' . ($info['keszult'] ? ', készült ' . $info['keszult'] : '') . ") – előzetes mentés: {$elotte['fajl']}", 'OK', $ki);

        // 2) végrehajtás
        $db = 0;
        $hiba = null;
        try {
            $db = sql_utasitasok($ut, function (string $sql) use ($pdo) { $pdo->exec($sql); });
        } catch (Throwable $e) {
            $hiba = $e;
        }
        if ($hiba) {
            // 3) visszagörgetés az előzetes mentésből
            $vissza = null;
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                sql_utasitasok($elotte['utvonal'], function (string $sql) use ($pdo) { $pdo->exec($sql); });
            } catch (Throwable $e2) {
                $vissza = $e2;
            }
            $uz = 'HIBA a visszaállításkor (' . $nev . '): ' . $hiba->getMessage() . ' – ' . ($vissza
                ? 'és az előzetes mentés visszatöltése is sikertelen: ' . $vissza->getMessage() . ' → állítsd vissza kézzel a(z) ' . $elotte['fajl'] . ' fájlt (phpMyAdmin → Import)!'
                : 'az adatbázis visszaállt a visszaállítás előtti állapotra (' . $elotte['fajl'] . ').');
            naplo('DB_VISSZAALLITAS', $uz, 'HIBA', $ki);
            throw new RuntimeException($uz);
        }
        // 4) kapcsolat alaphelyzetbe, ellenőrzés
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->exec('SET UNIQUE_CHECKS = 1');
        $pdo->exec('SET time_zone = ' . $pdo->quote(date('P')));
        $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
        $tablak = count($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll());
        $mp = round(microtime(true) - $t0, 2);
        naplo('DB_VISSZAALLITAS', "$nev visszaállítva: $db utasítás, $tablak tábla, $mp mp – a visszaállítás előtti állapot mentése: {$elotte['fajl']}", 'OK', $ki);
        return ['fajl' => $nev, 'utasitasok' => $db, 'tablak' => $tablak, 'masodperc' => $mp, 'elotte' => $elotte['fajl'], 'keszult' => $info['keszult']];
    } finally {
        @unlink("$dir/.karbantartas");
        backup_zar_felold($zar);
    }
}

/** Fut-e visszaállítás (a jelző 15 percnél régebben ott ragadt → figyelmen kívül) */
function karbantartas_aktiv(): bool
{
    $f = rtrim(BACKUP_DIR, '/') . '/.karbantartas';
    if (!is_file($f)) {
        return false;
    }
    $t = (int)@file_get_contents($f);
    if (time() - $t > 900) {
        @unlink($f);
        return false;
    }
    return true;
}

/** A legutóbbi esedékes mentési időpont (ma vagy tegnap BACKUP_HOUR órakor) */
function mentes_slot(): int
{
    $slot = mktime((int)BACKUP_HOUR, 0, 0);
    if (time() < $slot) {
        $slot -= 86400;
    }
    return $slot;
}

/** Az utolsó mentés gyors jelzője (.utolso.json) – nem kell hozzá a mappát végigolvasni */
function utolso_jelzo(): array
{
    $f = rtrim(BACKUP_DIR, '/') . '/.utolso.json';
    if (!is_file($f)) {
        return [];
    }
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}

/** Utolsó automatikus (cron vagy pótlás) mentés időpontja */
function utolso_auto_mentes(): ?array
{
    foreach (db_mentesek_lista() as $m) {
        if ($m['mod'] === 'auto' || $m['mod'] === 'potlas') {
            return $m;
        }
    }
    return null;
}

/** Ha a cron nem futott le az esedékes időpontban, pótoljuk (az első használatkor, 20 perc türelmi idő után) */
function mentes_ha_esedekes(): void
{
    if (!BACKUP_FALLBACK) {
        return;
    }
    try {
        $slot = mentes_slot();
        if (time() - $slot < 20 * 60) {
            return;
        }
        // gyors út: a jelzőfájl szerint már van mai automatikus mentés
        $j = utolso_jelzo();
        if ((int)($j['auto_ido'] ?? 0) >= $slot) {
            return;
        }
        $u = utolso_auto_mentes();
        if ($u && $u['ido'] >= $slot) {
            return;
        }
        db_mentes('potlas', 'app');
    } catch (MentesFoglalt $e) {
        // már fut – rendben
    } catch (Throwable $e) {
        // a naplóban már benne van; a felhasználó kérését nem törjük meg
    }
}

/** A cron-hívás kulcsa (a beallitasok táblában; első kéréskor generálódik) */
function cron_kulcs(bool $uj = false): string
{
    $k = $uj ? null : db_val('SELECT ertek FROM beallitasok WHERE kulcs = ?', ['mentes_cron_kulcs']);
    if (!$k || strlen((string)$k) < 32) {
        $k = bin2hex(random_bytes(20));
        db_exec('INSERT INTO beallitasok (kulcs, ertek) VALUES (?, ?) ON DUPLICATE KEY UPDATE ertek = VALUES(ertek)', ['mentes_cron_kulcs', $k]);
    }
    return (string)$k;
}

/** Alap-URL a cron URL-hez (config vagy automatikus) */
function cron_url(): string
{
    $alap = APP_URL !== '' ? rtrim(APP_URL, '/') : '';
    if ($alap === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $utv = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $alap = ($https ? 'https' : 'http') . '://' . $host . $utv;
    }
    return $alap . '/cron_mentes.php';
}

/** Állapot az admin felületnek */
function mentes_allapot(): array
{
    $lista = db_mentesek_lista();
    $osszes = 0;
    foreach ($lista as $m) {
        $osszes += $m['meret'];
    }
    $utolso = $lista[0] ?? null;
    $auto = utolso_auto_mentes();
    $cron = null;
    foreach ($lista as $m) {
        if ($m['mod'] === 'auto') {
            $cron = $m;
            break;
        }
    }
    $slot = mentes_slot();
    $kov = $slot + 86400;
    $dir = backup_dir();
    $kulcs = cron_kulcs();
    $cli = realpath(__DIR__ . '/../cron_mentes.php') ?: (dirname(__DIR__) . '/cron_mentes.php');
    return [
        'mappa'          => $dir,
        'irhato'         => is_writable($dir),
        'db'             => count($lista),
        'osszmeret'      => $osszes,
        'utolso'         => $utolso,
        'utolso_auto'    => $auto,
        'utolso_cron'    => $cron,
        'cron_ok'        => $cron !== null && (time() - $cron['ido']) < 26 * 3600,
        'esedekes'       => !($auto && $auto['ido'] >= $slot),
        'kovetkezo'      => date('Y-m-d H:i', $kov),
        'ora'            => (int)BACKUP_HOUR,
        'potlas'         => (bool)BACKUP_FALLBACK,
        'megorzes_nap'   => (int)BACKUP_MEGORZES_NAP,
        'cron_kulcs'     => $kulcs,
        'cron_cli'       => 'php -q ' . $cli . ' >/dev/null 2>&1',
        'cron_url'       => cron_url() . '?kulcs=' . $kulcs,
        'cron_wget'      => 'wget -q -O /dev/null "' . cron_url() . '?kulcs=' . $kulcs . '" >/dev/null 2>&1',
        'cron_curl'      => 'curl -s "' . cron_url() . '?kulcs=' . $kulcs . '" >/dev/null 2>&1',
        'szerver_ido'    => date('H:i') . ' (' . APP_TIMEZONE . ')',
        'utc_ido'        => gmdate('H:i'),
    ];
}
