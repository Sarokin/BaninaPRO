<?php
declare(strict_types=1);

/**
 * BaninaPRO – RÉSZTELJESÍTÉSEK
 *
 * Nyitott (bejövő: FIZETENDŐ, kimenő: NYITOTT) számlára érkezett részfizetések.
 * A számla státusza NEM változik, de a FŐÉRTÉKE (hátralék) = összeg − részteljesítések.
 *   - hozzáadás:  act_reszteljesites_hozzaad {irany, szamla_id, osszeg, datum, banki_azonosito?}
 *   - törlés:     act_reszteljesites_torol {id}
 *   - lista:      act_reszteljesitesek {irany, szamla_id}
 *
 * A számla-lekérdezések (BEJOVO_SQL, KIMENO_SQL, …) a RESZT_*_JOIN segítségével minden sorhoz
 * megadják: reszt (részteljesítések összege), reszt_db, hatralek (= osszeg − reszt).
 */

/** LEFT JOIN-darab: bejövő számlák részteljesítés-összege (rb.reszt, rb.reszt_db) – a b alias-ú bejovo_szamlak-hoz */
require_once __DIR__ . '/audit.php';

const RESZT_BEJOVO_JOIN = ' LEFT JOIN (SELECT szamla_id, SUM(osszeg) AS reszt, COUNT(*) AS reszt_db FROM reszteljesitesek WHERE irany = "BEJOVO" GROUP BY szamla_id) rb ON rb.szamla_id = b.id ';
/** LEFT JOIN-darab: kimenő számlák részteljesítés-összege (rk.reszt, rk.reszt_db) – az s alias-ú kimeno_szamlak-hoz */
const RESZT_KIMENO_JOIN = ' LEFT JOIN (SELECT szamla_id, SUM(osszeg) AS reszt, COUNT(*) AS reszt_db FROM reszteljesitesek WHERE irany = "KIMENO" GROUP BY szamla_id) rk ON rk.szamla_id = s.id ';
/** SELECT-darabok */
const RESZT_BEJOVO_MEZOK = ' COALESCE(rb.reszt, 0) AS reszt, COALESCE(rb.reszt_db, 0) AS reszt_db, (b.osszeg - COALESCE(rb.reszt, 0)) AS hatralek ';
const RESZT_KIMENO_MEZOK = ' COALESCE(rk.reszt, 0) AS reszt, COALESCE(rk.reszt_db, 0) AS reszt_db, (s.osszeg - COALESCE(rk.reszt, 0)) AS hatralek ';

function reszt_irany(array $be): string
{
    $i = mb_strtoupper(trim((string)($be['irany'] ?? '')), 'UTF-8');
    if (!in_array($i, ['BEJOVO', 'KIMENO'], true)) {
        hiba('Érvénytelen irány (BEJOVO / KIMENO).');
    }
    return $i;
}

function reszt_sor_feldolgoz(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['szamla_id'] = (int)$r['szamla_id'];
    $r['osszeg'] = (float)$r['osszeg'];
    $r['letrehozta'] = $r['letrehozta'] === null ? null : (int)$r['letrehozta'];
    return $r;
}

/** Egy irány több számlájának részteljesítései: szamla_id => [sorok] (dátum szerint) */
function reszteljesitesek_betolt(string $irany, array $szamlaIds): array
{
    $szamlaIds = array_values(array_unique(array_map('intval', $szamlaIds)));
    if (!$szamlaIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($szamlaIds), '?'));
    $sorok = db_all(
        "SELECT r.*, f.felhasznalonev AS letrehozta_nev FROM reszteljesitesek r LEFT JOIN felhasznalok f ON f.id = r.letrehozta
          WHERE r.irany = ? AND r.szamla_id IN ($in) ORDER BY r.datum, r.id",
        array_merge([$irany], $szamlaIds)
    );
    $ki = [];
    foreach ($sorok as $r) {
        $r = reszt_sor_feldolgoz($r);
        $ki[$r['szamla_id']][] = $r;
    }
    return $ki;
}

/**
 * Számla-sorok kiegészítése: reszt/hatralek típusosítása + a részteljesítés-lista csatolása
 * azokhoz a sorokhoz, ahol van (reszt_db > 0). A sorok 'id' mezője a számla azonosítója.
 */
function reszt_csatol(array $sorok, string $irany): array
{
    $ids = [];
    foreach ($sorok as &$s) {
        $s['reszt'] = (float)($s['reszt'] ?? 0);
        $s['reszt_db'] = (int)($s['reszt_db'] ?? 0);
        $s['hatralek'] = array_key_exists('hatralek', $s) ? (float)$s['hatralek'] : round((float)$s['osszeg'] - $s['reszt'], 2);
        $s['reszteljesitesek'] = [];
        if ($s['reszt_db'] > 0) {
            $ids[] = (int)$s['id'];
        }
    }
    unset($s);
    if ($ids) {
        $lista = reszteljesitesek_betolt($irany, $ids);
        foreach ($sorok as &$s) {
            if ($s['reszt_db'] > 0) {
                $s['reszteljesitesek'] = $lista[(int)$s['id']] ?? [];
            }
        }
        unset($s);
    }
    return $sorok;
}

/** A számla + jogosultsági ellenőrzés (zárolással) */
function reszt_szamla_betolt(string $irany, int $szamlaId, bool $zarol = false): array
{
    $z = $zarol ? ' FOR UPDATE' : '';
    if ($irany === 'BEJOVO') {
        $s = db_row('SELECT b.id, b.kod, b.szamlaszam, b.osszeg, b.statusz, b.kotes_id, k.penznem, c.nev AS ceg_nev,
                            COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r WHERE r.irany = "BEJOVO" AND r.szamla_id = b.id), 0) AS reszt
                       FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id WHERE b.id = ?' . $z, [$szamlaId]);
    } else {
        $s = db_row('SELECT s.id, s.kod, s.szamlaszam, s.osszeg, s.statusz, s.penznem, c.nev AS ceg_nev,
                            COALESCE((SELECT SUM(r.osszeg) FROM reszteljesitesek r WHERE r.irany = "KIMENO" AND r.szamla_id = s.id), 0) AS reszt
                       FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id WHERE s.id = ?' . $z, [$szamlaId]);
    }
    if (!$s) {
        hiba('A számla nem található.');
    }
    $s['id'] = (int)$s['id'];
    $s['osszeg'] = (float)$s['osszeg'];
    $s['reszt'] = (float)$s['reszt'];
    $s['hatralek'] = round($s['osszeg'] - $s['reszt'], 2);
    $s['nyitott'] = $irany === 'BEJOVO' ? $s['statusz'] === 'FIZETENDO' : $s['statusz'] === 'NYITOTT';
    return $s;
}

/** Egy számla részteljesítései + hátralék */
function act_reszteljesitesek(array $be): array
{
    csak_bejelentkezve();
    $irany = reszt_irany($be);
    $szamlaId = be_int($be, 'szamla_id');
    $s = reszt_szamla_betolt($irany, $szamlaId);
    $lista = reszteljesitesek_betolt($irany, [$szamlaId]);
    return ['szamla' => $s, 'reszteljesitesek' => $lista[$szamlaId] ?? []];
}

/** Részteljesítés felvezetése – csak nyitott számlára, csak a hátraléknál kisebb összeggel */
function act_reszteljesites_hozzaad(array $be): array
{
    $u = csak_bejelentkezve();
    $irany = reszt_irany($be);
    if ($irany === 'BEJOVO' && !reszt_bejovo_engedelyezett()) {
        hiba('A bejövő számláknál a részteljesítés jelenleg ki van kapcsolva – az Admin oldalon kapcsolható be.', 'RESZT_BEJOVO_KI', [], 403);
    }
    $szamlaId = be_int($be, 'szamla_id');
    $osszeg = (float)be_osszeg($be, 'osszeg', false, 'részteljesítés összege');
    $datum = be_datum($be, 'datum', true, 'utalás dátuma');
    $bank = be_szoveg($be, 'banki_azonosito', 100, false, 'banki azonosító');
    $bank = $bank !== null && trim($bank) !== '' ? trim($bank) : null;
    if ($osszeg <= 0) {
        hiba('A részteljesítés összege pozitív szám legyen.');
    }
    $e = db_tx(function () use ($irany, $szamlaId, $osszeg, $datum, $bank, $u) {
        $s = reszt_szamla_betolt($irany, $szamlaId, true);
        if (!$s['nyitott']) {
            hiba($irany === 'BEJOVO'
                ? "A(z) {$s['kod']} számla nem FIZETENDŐ státuszú (" . ny_statusz_nev($s['statusz']) . ') – részteljesítés csak nyitott számlára vezethető fel.' . ($s['statusz'] === 'UTALASHOZ_ADVA' ? ' Előbb vedd ki az utalásból.' : '')
                : "A(z) {$s['kod']} számla már FIZETVE státuszú – részteljesítés csak nyitott számlára vezethető fel.");
        }
        if ($s['osszeg'] <= 0) {
            hiba('Negatív (jóváíró) számlára nem vezethető fel részteljesítés.');
        }
        if ($osszeg > $s['hatralek'] - 0.005) {
            hiba('A részteljesítés (' . fmt_osszeg($osszeg) . " {$s['penznem']}) nem lehet nagyobb vagy egyenlő a számla hátralékánál (" . fmt_osszeg($s['hatralek']) . " {$s['penznem']}). Ha a teljes hátralék megérkezett, a számlát a rendes úton jelöld FIZETVE-nek" . ($irany === 'KIMENO' ? ' (bankkivonati azonosítóhoz rendelés)' : ' (utalás teljesítése)') . '.');
        }
        db_exec('INSERT INTO reszteljesitesek (irany, szamla_id, osszeg, datum, banki_azonosito, letrehozta) VALUES (?, ?, ?, ?, ?, ?)',
            [$irany, $szamlaId, number_format($osszeg, 2, '.', ''), $datum, $bank, $u['id']]);
        $id = (int)db()->lastInsertId();
        $regiHatralek = $s['hatralek'];
        $s['reszt'] = round($s['reszt'] + $osszeg, 2);
        $s['hatralek'] = round($s['osszeg'] - $s['reszt'], 2);
        db_exec(($irany === 'BEJOVO' ? 'UPDATE bejovo_szamlak' : 'UPDATE kimeno_szamlak') . ' SET modositotta = ? WHERE id = ?', [$u['id'], $szamlaId]);
        audit_ir($irany, $szamlaId, 'RESZT', 'Részteljesítés felvezetve: ' . fmt_osszeg($osszeg) . " {$s['penznem']} (" . audit_ertek('kelt', $datum) . ($bank ? ", bank: $bank" : '') . ')',
            [['m' => 'Hátralék', 'r' => fmt_osszeg($regiHatralek) . " {$s['penznem']}", 'u' => fmt_osszeg($s['hatralek']) . " {$s['penznem']}"]], $irany === 'BEJOVO' ? (int)($s['kotes_id'] ?? 0) ?: null : null, $u);
        return ['id' => $id, 'szamla' => $s];
    });
    $s = $e['szamla'];
    naplo('RESZTELJESITES', "{$irany} számla {$s['kod']} („{$s['szamlaszam']}”, {$s['ceg_nev']}): részteljesítés +" . fmt_osszeg($osszeg) . " {$s['penznem']} ($datum" . ($bank ? ", bank: $bank" : '') . ') – hátralék: ' . fmt_osszeg($s['hatralek']) . " {$s['penznem']}");
    $lista = reszteljesitesek_betolt($irany, [$szamlaId]);
    return ['id' => $e['id'], 'szamla' => $s, 'reszteljesitesek' => $lista[$szamlaId] ?? []];
}

/** Részteljesítés törlése – csak amíg a számla nyitott */
function act_reszteljesites_torol(array $be): array
{
    csak_bejelentkezve();
    $id = be_int($be, 'id');
    $r = db_row('SELECT * FROM reszteljesitesek WHERE id = ?', [$id]);
    if (!$r) {
        hiba('A részteljesítés nem található.');
    }
    $irany = $r['irany'];
    $szamlaId = (int)$r['szamla_id'];
    $s = db_tx(function () use ($irany, $szamlaId, $id) {
        $s = reszt_szamla_betolt($irany, $szamlaId, true);
        if (!$s['nyitott']) {
            hiba('A számla már rendezett (' . ny_statusz_nev($s['statusz']) . ') – a részteljesítés nem törölhető. (Admin: fizetés visszavonása / utalás visszanyitása után lehet.)');
        }
        db_exec('DELETE FROM reszteljesitesek WHERE id = ?', [$id]);
        return $s;
    });
    $regi = $s;
    $s = reszt_szamla_betolt($irany, $szamlaId); // friss hátralék a törlés után
    $u = aktualis_felhasznalo();
    db_exec(($irany === 'BEJOVO' ? 'UPDATE bejovo_szamlak' : 'UPDATE kimeno_szamlak') . ' SET modositotta = ? WHERE id = ?', [$u['id'] ?? null, $szamlaId]);
    audit_ir($irany, $szamlaId, 'RESZT', 'Részteljesítés törölve: ' . fmt_osszeg($r['osszeg']) . " {$s['penznem']} (" . audit_ertek('kelt', $r['datum']) . ($r['banki_azonosito'] ? ", bank: {$r['banki_azonosito']}" : '') . ')',
        [['m' => 'Hátralék', 'r' => fmt_osszeg($regi['hatralek']) . " {$s['penznem']}", 'u' => fmt_osszeg($s['hatralek']) . " {$s['penznem']}"]], $irany === 'BEJOVO' ? (int)($s['kotes_id'] ?? 0) ?: null : null, $u);
    naplo('RESZTELJESITES_TOROL', "{$irany} számla {$s['kod']} („{$s['szamlaszam']}”): részteljesítés törölve −" . fmt_osszeg($r['osszeg']) . " {$s['penznem']} ({$r['datum']}" . ($r['banki_azonosito'] ? ", bank: {$r['banki_azonosito']}" : '') . ') – hátralék: ' . fmt_osszeg($s['hatralek']) . " {$s['penznem']}");
    $lista = reszteljesitesek_betolt($irany, [$szamlaId]);
    return ['torolve' => true, 'szamla' => $s, 'reszteljesitesek' => $lista[$szamlaId] ?? []];
}
