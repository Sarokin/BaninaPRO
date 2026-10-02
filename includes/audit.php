<?php
declare(strict_types=1);

/**
 * BaninaPRO – VÁLTOZÁSNAPLÓ (ki, mikor, mit módosított egy kötésen / számlán)
 *
 * Tábla: valtozasnaplo (tipus KOTES/BEJOVO/KIMENO, rekord_id, szulo_id = bejövő számlánál a kötés,
 * muvelet, leiras, valtozasok JSON [{m, r, u}], felhasznalo, idopont).
 *
 *   audit_ir($tipus, $rekordId, $muvelet, $leiras, $valtozasok, $szuloId)
 *   audit_diff($regi, $uj, $mezok)           – csak a tényleg megváltozott mezők, formázott értékekkel
 *   audit_kezdo($adat, $mezok)               – létrehozáskor: a kitöltött mezők (üres → érték)
 *   act_naplo_rekord {tipus, id}             – egy kötés / számla teljes története (kötésnél a számláié is)
 */

const AUDIT_MUVELETEK = ['LETREHOZ', 'MODOSIT', 'TOROL', 'STATUSZ', 'RESZT', 'ATHELYEZ', 'IMPORT'];

/** Mezőcímkék (számla + kötés) */
const AUDIT_CIMKEK = [
    'szamlaszam' => 'Számlaszám', 'kelt' => 'Kelt', 'teljesites_datum' => 'Teljesítés', 'fizetesi_hatarido' => 'Fizetési határidő',
    'osszeg' => 'Összeg', 'beszam' => 'BESZÁM', 'megjegyzes' => 'Megjegyzés', 'statusz' => 'Státusz', 'fizetve_datum' => 'Utalás / fizetés dátuma',
    'megnevezes' => 'Megnevezés', 'regi_kod' => 'Régi kötés ID', 'regi_k' => 'Régi K', 'kod' => 'Azonosító', 'kotes_kod' => 'Kötés',
    'utalas_uid' => 'Utalás', 'banki_azonosito' => 'Banki azonosító', 'penznem' => 'Pénznem', 'ev' => 'Év',
];

/** Érték megjelenítése a naplóban (dátum → ÉÉÉÉ.HH.NN., összeg → 12 345,50, státusz → magyar név, üres → „–”) */
function audit_ertek(string $mezo, $v, string $penznem = ''): string
{
    if ($v === null || $v === '') {
        return '–';
    }
    if (in_array($mezo, ['kelt', 'teljesites_datum', 'fizetesi_hatarido', 'fizetve_datum'], true) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$v, $m)) {
        return "{$m[1]}.{$m[2]}.{$m[3]}.";
    }
    if ($mezo === 'osszeg') {
        return fmt_osszeg((float)$v) . ($penznem !== '' ? ' ' . $penznem : '');
    }
    if ($mezo === 'statusz') {
        return ny_statusz_nev((string)$v);
    }
    $s = trim((string)$v);
    return mb_strlen($s, 'UTF-8') > 120 ? mb_substr($s, 0, 117, 'UTF-8') . '…' : $s;
}

/** Két állapot különbsége a megadott mezőkben: [['m' => címke, 'r' => régi, 'u' => új], …] */
function audit_diff(array $regi, array $uj, array $mezok, string $penznem = ''): array
{
    $ki = [];
    foreach ($mezok as $m) {
        $r = $regi[$m] ?? null;
        $n = $uj[$m] ?? null;
        $rs = $r === null ? '' : (string)$r;
        $ns = $n === null ? '' : (string)$n;
        if ($m === 'osszeg') {
            if (abs((float)$rs - (float)$ns) < 0.005) {
                continue;
            }
        } elseif (trim($rs) === trim($ns)) {
            continue;
        }
        $ki[] = ['m' => AUDIT_CIMKEK[$m] ?? $m, 'r' => audit_ertek($m, $r, $penznem), 'u' => audit_ertek($m, $n, $penznem)];
    }
    return $ki;
}

/** Létrehozáskor: a kitöltött mezők értékei (régi = „–”) */
function audit_kezdo(array $adat, array $mezok, string $penznem = ''): array
{
    $ki = [];
    foreach ($mezok as $m) {
        $v = $adat[$m] ?? null;
        if ($v === null || $v === '') {
            continue;
        }
        $ki[] = ['m' => AUDIT_CIMKEK[$m] ?? $m, 'r' => '–', 'u' => audit_ertek($m, $v, $penznem)];
    }
    return $ki;
}

/** Egy bejegyzés írása (a hívó tranzakcióján belül fut, ha van) */
function audit_ir(string $tipus, int $rekordId, string $muvelet, string $leiras, array $valtozasok = [], ?int $szuloId = null, ?array $u = null): void
{
    if (!in_array($tipus, ['KOTES', 'BEJOVO', 'KIMENO'], true)) {
        return;
    }
    if (!in_array($muvelet, AUDIT_MUVELETEK, true)) {
        $muvelet = 'MODOSIT';
    }
    if ($u === null) {
        $u = function_exists('aktualis_felhasznalo') ? aktualis_felhasznalo() : null;
    }
    db_exec(
        'INSERT INTO valtozasnaplo (tipus, rekord_id, szulo_id, muvelet, leiras, valtozasok, felhasznalo_id, felhasznalonev) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$tipus, $rekordId, $szuloId, $muvelet, mb_substr($leiras, 0, 500, 'UTF-8'),
         $valtozasok ? json_encode(array_values($valtozasok), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
         $u['id'] ?? null, (string)($u['felhasznalonev'] ?? '')]
    );
}

/** Több bejegyzés egyszerre (importhoz): [[tipus, rekordId, szuloId, muvelet, leiras, valtozasok], …] */
function audit_ir_tobb(array $sorok, ?array $u = null): void
{
    if (!$sorok) {
        return;
    }
    if ($u === null) {
        $u = function_exists('aktualis_felhasznalo') ? aktualis_felhasznalo() : null;
    }
    $uid = $u['id'] ?? null;
    $unev = (string)($u['felhasznalonev'] ?? '');
    foreach (array_chunk($sorok, 200) as $adag) {
        $ph = [];
        $p = [];
        foreach ($adag as $r) {
            $ph[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($p, $r[0], (int)$r[1], $r[2], $r[3], mb_substr((string)$r[4], 0, 500, 'UTF-8'),
                !empty($r[5]) ? json_encode(array_values($r[5]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $uid, $unev);
        }
        db_exec('INSERT INTO valtozasnaplo (tipus, rekord_id, szulo_id, muvelet, leiras, valtozasok, felhasznalo_id, felhasznalonev) VALUES ' . implode(', ', $ph), $p);
    }
}

function audit_sor_feldolgoz(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['rekord_id'] = (int)$r['rekord_id'];
    $r['szulo_id'] = $r['szulo_id'] === null ? null : (int)$r['szulo_id'];
    $r['felhasznalo_id'] = $r['felhasznalo_id'] === null ? null : (int)$r['felhasznalo_id'];
    $v = $r['valtozasok'] !== null ? json_decode((string)$r['valtozasok'], true) : null;
    $r['valtozasok'] = is_array($v) ? $v : [];
    return $r;
}

/**
 * Egy kötés / számla története időrendben.
 * Kötésnél a kötés saját bejegyzései + a hozzá tartozó (vagy valaha hozzá tartozott) számlák bejegyzései.
 */
function act_naplo_rekord(array $be): array
{
    csak_bejelentkezve();
    $tipus = mb_strtoupper(trim((string)($be['tipus'] ?? '')), 'UTF-8');
    if (!in_array($tipus, ['KOTES', 'BEJOVO', 'KIMENO'], true)) {
        hiba('Érvénytelen típus.');
    }
    $id = be_int($be, 'id');
    if ($tipus === 'KOTES') {
        $fej = db_row('SELECT k.id, k.kod, k.megnevezes, k.penznem, c.nev AS ceg_nev, k.letrehozva, k.modositva, fl.felhasznalonev AS letrehozta_nev, fm.felhasznalonev AS modositotta_nev
                         FROM kotesek k JOIN cegek c ON c.id = k.ceg_id LEFT JOIN felhasznalok fl ON fl.id = k.letrehozta LEFT JOIN felhasznalok fm ON fm.id = k.modositotta WHERE k.id = ?', [$id]);
        $sorok = db_all('SELECT * FROM valtozasnaplo WHERE (tipus = "KOTES" AND rekord_id = ?) OR (tipus = "BEJOVO" AND szulo_id = ?) ORDER BY idopont, id LIMIT 2000', [$id, $id]);
    } elseif ($tipus === 'BEJOVO') {
        $fej = db_row('SELECT b.id, b.kod, b.szamlaszam, b.statusz, k.penznem, c.nev AS ceg_nev, b.letrehozva, b.modositva, fl.felhasznalonev AS letrehozta_nev, fm.felhasznalonev AS modositotta_nev
                         FROM bejovo_szamlak b JOIN kotesek k ON k.id = b.kotes_id JOIN cegek c ON c.id = k.ceg_id LEFT JOIN felhasznalok fl ON fl.id = b.letrehozta LEFT JOIN felhasznalok fm ON fm.id = b.modositotta WHERE b.id = ?', [$id]);
        $sorok = db_all('SELECT * FROM valtozasnaplo WHERE tipus = "BEJOVO" AND rekord_id = ? ORDER BY idopont, id LIMIT 2000', [$id]);
    } else {
        $fej = db_row('SELECT s.id, s.kod, s.szamlaszam, s.statusz, s.penznem, c.nev AS ceg_nev, s.letrehozva, s.modositva, fl.felhasznalonev AS letrehozta_nev, fm.felhasznalonev AS modositotta_nev
                         FROM kimeno_szamlak s JOIN cegek c ON c.id = s.ceg_id LEFT JOIN felhasznalok fl ON fl.id = s.letrehozta LEFT JOIN felhasznalok fm ON fm.id = s.modositotta WHERE s.id = ?', [$id]);
        $sorok = db_all('SELECT * FROM valtozasnaplo WHERE tipus = "KIMENO" AND rekord_id = ? ORDER BY idopont, id LIMIT 2000', [$id]);
    }
    if (!$fej) {
        hiba('A rekord nem található.');
    }
    $fej['id'] = (int)$fej['id'];
    naplo('VALTOZASNAPLO', "$tipus #{$id} ({$fej['kod']}) története megtekintve (" . count($sorok) . ' bejegyzés)');
    return ['tipus' => $tipus, 'rekord' => $fej, 'bejegyzesek' => array_map('audit_sor_feldolgoz', $sorok)];
}
