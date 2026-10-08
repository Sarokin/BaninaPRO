<?php
declare(strict_types=1);

/**
 * BaninaPRO – minimális PDF-író (függőség nélkül).
 *  - A4 álló, mm-alapú koordináták (bal-felső sarokból)
 *  - TrueType betűk beágyazása (Identity-H, CIDFontType2) → teljes magyar ékezetkészlet
 *  - szövegmérés, sortörés, táblázat oldaltöréssel és ismétlődő fejléccel
 *  - FlateDecode tömörítés (gzcompress)
 */

final class TtfBetu
{
    public string $adat;
    public string $nev;
    public int $unitsPerEm = 1000;
    public array $bbox = [0, 0, 1000, 1000];
    public int $ascent = 800;
    public int $descent = -200;
    public int $capHeight = 700;
    public int $glyphSzam = 0;
    /** @var array<int,int> unicode → glyph id */
    public array $cmap = [];
    /** @var array<int,int> glyph id → advance (font unit) */
    public array $adv = [];

    public static function betolt(string $fajl, string $nev): self
    {
        $f = new self();
        $f->adat = (string)file_get_contents($fajl);
        $f->nev = $nev;
        if ($f->adat === '') {
            throw new RuntimeException("Betűtípus nem olvasható: $fajl");
        }
        $f->feldolgoz();
        return $f;
    }

    private function u16(int $o): int { return (int)unpack('n', substr($this->adat, $o, 2))[1]; }
    private function s16(int $o): int { $v = $this->u16($o); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32(int $o): int { return (int)unpack('N', substr($this->adat, $o, 4))[1]; }

    private function feldolgoz(): void
    {
        $tablak = [];
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + $i * 16;
            $tablak[substr($this->adat, $o, 4)] = [$this->u32($o + 8), $this->u32($o + 12)];
        }
        foreach (['head', 'hhea', 'hmtx', 'maxp', 'cmap'] as $t) {
            if (!isset($tablak[$t])) {
                throw new RuntimeException("Hiányzó TTF tábla: $t");
            }
        }
        [$head] = $tablak['head'];
        $this->unitsPerEm = $this->u16($head + 18);
        $this->bbox = [$this->s16($head + 36), $this->s16($head + 38), $this->s16($head + 40), $this->s16($head + 42)];
        [$hhea] = $tablak['hhea'];
        $this->ascent = $this->s16($hhea + 4);
        $this->descent = $this->s16($hhea + 6);
        $numH = $this->u16($hhea + 34);
        [$maxp] = $tablak['maxp'];
        $this->glyphSzam = $this->u16($maxp + 4);
        if (isset($tablak['OS/2'])) {
            [$os2, $os2len] = $tablak['OS/2'];
            if ($os2len >= 90) {
                $this->capHeight = $this->s16($os2 + 88) ?: $this->capHeight;
            }
        }
        [$hmtx] = $tablak['hmtx'];
        $utolso = 0;
        for ($g = 0; $g < $this->glyphSzam; $g++) {
            if ($g < $numH) {
                $utolso = $this->u16($hmtx + $g * 4);
            }
            $this->adv[$g] = $utolso;
        }
        $this->cmapFeldolgoz($tablak['cmap'][0]);
    }

    private function cmapFeldolgoz(int $cm): void
    {
        $n = $this->u16($cm + 2);
        $jelolt = null;
        for ($i = 0; $i < $n; $i++) {
            $o = $cm + 4 + $i * 8;
            $plat = $this->u16($o);
            $enc = $this->u16($o + 2);
            $off = $this->u32($o + 4);
            $fmt = $this->u16($cm + $off);
            if (($plat === 3 && ($enc === 1 || $enc === 10)) || $plat === 0) {
                if ($fmt === 4 || $fmt === 12) {
                    if ($jelolt === null || $fmt === 12) {
                        $jelolt = [$cm + $off, $fmt];
                    }
                }
            }
        }
        if ($jelolt === null) {
            throw new RuntimeException('Nem támogatott cmap tábla.');
        }
        [$st, $fmt] = $jelolt;
        if ($fmt === 4) {
            $segX2 = $this->u16($st + 6);
            $seg = intdiv($segX2, 2);
            $endO = $st + 14;
            $startO = $endO + $segX2 + 2;
            $deltaO = $startO + $segX2;
            $rangeO = $deltaO + $segX2;
            for ($s = 0; $s < $seg; $s++) {
                $end = $this->u16($endO + $s * 2);
                $start = $this->u16($startO + $s * 2);
                $delta = $this->u16($deltaO + $s * 2);
                $range = $this->u16($rangeO + $s * 2);
                if ($start === 0xFFFF) {
                    continue;
                }
                for ($c = $start; $c <= $end; $c++) {
                    if ($range === 0) {
                        $g = ($c + $delta) & 0xFFFF;
                    } else {
                        $ga = $rangeO + $s * 2 + $range + ($c - $start) * 2;
                        $g = $this->u16($ga);
                        if ($g !== 0) {
                            $g = ($g + $delta) & 0xFFFF;
                        }
                    }
                    if ($g !== 0) {
                        $this->cmap[$c] = $g;
                    }
                }
            }
        } else {
            $nGroups = $this->u32($st + 12);
            for ($i = 0; $i < $nGroups; $i++) {
                $o = $st + 16 + $i * 12;
                $sc = $this->u32($o);
                $ec = $this->u32($o + 4);
                $sg = $this->u32($o + 8);
                for ($c = $sc; $c <= $ec && $c - $sc < 65536; $c++) {
                    $this->cmap[$c] = $sg + ($c - $sc);
                }
            }
        }
    }

    /** Szöveg → glyph id lista */
    public function glyphek(string $szoveg): array
    {
        $ki = [];
        $kerdo = $this->cmap[63] ?? 0;
        foreach (mb_str_split($szoveg, 1, 'UTF-8') as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            $ki[] = $this->cmap[$cp] ?? $kerdo;
        }
        return $ki;
    }

    /** Szöveg szélessége pontban */
    public function szelesseg(string $szoveg, float $pt): float
    {
        $w = 0;
        foreach ($this->glyphek($szoveg) as $g) {
            $w += $this->adv[$g] ?? 0;
        }
        return $w * $pt / $this->unitsPerEm;
    }
}

final class PdfIro
{
    public const A4_SZ = 210.0;
    public const A4_MA = 297.0;
    private const PT = 72 / 25.4;

    /** @var TtfBetu[] */
    private array $betuk = [];
    private array $betuKulcs = [];
    private array $oldalak = [];
    private string $tartalom = '';
    private array $hasznaltGlyph = [];
    public float $margoBal = 12.0;
    public float $margoJobb = 12.0;
    public float $margoFel = 14.0;
    public float $margoAl = 14.0;
    public float $y = 0.0;
    public int $oldalSzam = 0;
    /** @var callable|null */
    public $fejlecRajzolo = null;
    /** @var callable|null */
    public $lablecRajzolo = null;
    /**
     * Fekete-fehér, nyomtatóra optimalizált mód (1.20): minden szöveg és vonal tiszta fekete (#000000), a világos
     * háttérkitöltések elmaradnak, a sötét / telített kitöltések (pl. a szakasz-csík) feketék. Kivétel (1.21): a táblázatok
     * fő sora fekete sáv fehér betűkkel, a státusza fehér jelvényben.
     */
    public bool $ff = false;
    /** Táblázatok tételsorai (1.21): a magyar szöveg 9,5 pt, alatta az angol fordítás 7 pt; az oszlopfejléc 8,5 pt, angolja 6,5 pt */
    public float $sorPt = 9.5;
    public float $sorEnPt = 7.0;
    public float $fejPt = 8.5;
    public float $fejEnPt = 6.5;
    /** A fő sor (1.21) sávja: színesben mélyzöld, fekete-fehérben fekete; a betűi fehérek, az angol sora halványabb */
    public array $foSzin = [16, 70, 38];
    public array $foEnSzin = [184, 212, 193];
    /** A fő sor jelvényének (pl. a státusz) betűmérete */
    public float $jelvenyPt = 7.5;

    public function betuHozzaad(string $kulcs, string $fajl): void
    {
        $this->betuk[$kulcs] = TtfBetu::betolt($fajl, 'Banina' . ucfirst($kulcs));
        $this->betuKulcs[$kulcs] = '/F' . (count($this->betuKulcs) + 1);
        $this->hasznaltGlyph[$kulcs] = [];
    }

    public function betu(string $kulcs): TtfBetu
    {
        return $this->betuk[$kulcs];
    }

    // ------------------------------------------------------------ oldalak
    public function ujOldal(): void
    {
        if ($this->oldalSzam > 0) {
            $this->oldalak[] = $this->tartalom;
        }
        $this->tartalom = '';
        $this->oldalSzam++;
        $this->y = $this->margoFel;
        if ($this->fejlecRajzolo) {
            ($this->fejlecRajzolo)($this);
        }
    }

    public function szelesseg(): float
    {
        return self::A4_SZ - $this->margoBal - $this->margoJobb;
    }

    public function alsoHatar(): float
    {
        return self::A4_MA - $this->margoAl;
    }

    /** Ha nem fér el a megadott magasság, új oldal */
    public function helyBiztosit(float $mm): void
    {
        if ($this->y + $mm > $this->alsoHatar()) {
            $this->ujOldal();
        }
    }

    // ------------------------------------------------------------ rajzolás
    private static function szin(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    private static function f(float $v): string
    {
        return rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
    }

    private function px(float $mm): float { return $mm * self::PT; }
    private function py(float $mm): float { return (self::A4_MA - $mm) * self::PT; }

    public function teglalap(float $x, float $y, float $w, float $h, ?array $kitoltes, ?array $korvonal = null, float $vonal = 0.2): void
    {
        if ($this->ff) {
            // fekete-fehér: világos háttér nincs (a nyomtató raszterezné), a sötét / telített kitöltés fekete
            if ($kitoltes && (0.299 * $kitoltes[0] + 0.587 * $kitoltes[1] + 0.114 * $kitoltes[2]) / 255 > 0.75) {
                $kitoltes = null;
            } elseif ($kitoltes) {
                $kitoltes = [0, 0, 0];
            }
            $korvonal = $korvonal ? [0, 0, 0] : null;
            if (!$kitoltes && !$korvonal) {
                return;
            }
        }
        $s = '';
        if ($kitoltes) {
            $s .= self::szin($kitoltes) . " rg\n";
        }
        if ($korvonal) {
            $s .= self::szin($korvonal) . " RG " . self::f($vonal * self::PT) . " w\n";
        }
        $s .= sprintf("%s %s %s %s re %s\n", self::f($this->px($x)), self::f($this->py($y + $h)), self::f($this->px($w)), self::f($this->px($h)),
            $kitoltes && $korvonal ? 'B' : ($kitoltes ? 'f' : 'S'));
        $this->tartalom .= $s;
    }

    /**
     * Lekerekített sarkú, kitöltött téglalap (jelvény). Fekete-fehérben a $ffKitoltes színével (pl. fehér jelvény a fekete
     * sávon) – a világos kitöltés itt szándékos, nem marad el.
     */
    public function lekerekitett(float $x, float $y, float $w, float $h, float $r, array $kitoltes, ?array $ffKitoltes = null): void
    {
        if ($this->ff) {
            $kitoltes = $ffKitoltes ?? [0, 0, 0];
        }
        $r = min($r, $w / 2, $h / 2);
        $k = 0.5523 * $r;   // a negyedkör Bézier-közelítése
        $p = fn(float $px, float $py): string => self::f($this->px($px)) . ' ' . self::f($this->py($py));
        $this->tartalom .= self::szin($kitoltes) . " rg\n"
            . $p($x + $r, $y) . " m\n" . $p($x + $w - $r, $y) . " l\n"
            . $p($x + $w - $r + $k, $y) . ' ' . $p($x + $w, $y + $r - $k) . ' ' . $p($x + $w, $y + $r) . " c\n"
            . $p($x + $w, $y + $h - $r) . " l\n"
            . $p($x + $w, $y + $h - $r + $k) . ' ' . $p($x + $w - $r + $k, $y + $h) . ' ' . $p($x + $w - $r, $y + $h) . " c\n"
            . $p($x + $r, $y + $h) . " l\n"
            . $p($x + $r - $k, $y + $h) . ' ' . $p($x, $y + $h - $r + $k) . ' ' . $p($x, $y + $h - $r) . " c\n"
            . $p($x, $y + $r) . " l\n"
            . $p($x, $y + $r - $k) . ' ' . $p($x + $r - $k, $y) . ' ' . $p($x + $r, $y) . " c\nf\n";
    }

    public function vonal(float $x1, float $y1, float $x2, float $y2, array $rgb, float $vastag = 0.2): void
    {
        if ($this->ff) {
            $rgb = [0, 0, 0];
        }
        $this->tartalom .= sprintf("%s RG %s w %s %s m %s %s l S\n", self::szin($rgb), self::f($vastag * self::PT),
            self::f($this->px($x1)), self::f($this->py($y1)), self::f($this->px($x2)), self::f($this->py($y2)));
    }

    private function hexSzoveg(string $kulcs, string $szoveg): string
    {
        $hex = '';
        foreach ($this->betuk[$kulcs]->glyphek($szoveg) as $g) {
            $this->hasznaltGlyph[$kulcs][$g] = true;
            $hex .= sprintf('%04X', $g);
        }
        return $hex;
    }

    public function szovegSzelesseg(string $szoveg, string $kulcs, float $pt): float
    {
        return $this->betuk[$kulcs]->szelesseg($szoveg, $pt) / self::PT; // mm
    }

    /**
     * Szöveg kiírása. $x: bal szél (L), jobb szél (R) vagy közép (C). $y: alapvonal (mm).
     * $ffRgb: a fekete-fehér PDF színe (alapból fekete; a fő sor fekete sávján fehér).
     */
    public function szoveg(float $x, float $y, string $szoveg, string $kulcs, float $pt, array $rgb = [23, 23, 23], string $igazit = 'L', ?array $ffRgb = null): void
    {
        if ($szoveg === '') {
            return;
        }
        if ($this->ff) {
            $rgb = $ffRgb ?? [0, 0, 0];
        }
        $w = $this->szovegSzelesseg($szoveg, $kulcs, $pt);
        if ($igazit === 'R') {
            $x -= $w;
        } elseif ($igazit === 'C') {
            $x -= $w / 2;
        }
        $this->tartalom .= sprintf("BT %s %s Tf %s rg %s %s Td <%s> Tj ET\n", $this->betuKulcs[$kulcs], self::f($pt), self::szin($rgb),
            self::f($this->px($x)), self::f($this->py($y)), $this->hexSzoveg($kulcs, $szoveg));
    }

    /** Szöveg rövidítése „…”-tal, hogy beleférjen */
    public function rovidit(string $szoveg, string $kulcs, float $pt, float $maxMm): string
    {
        if ($this->szovegSzelesseg($szoveg, $kulcs, $pt) <= $maxMm) {
            return $szoveg;
        }
        $ch = mb_str_split($szoveg, 1, 'UTF-8');
        while (count($ch) > 1) {
            array_pop($ch);
            $p = implode('', $ch) . '…';
            if ($this->szovegSzelesseg($p, $kulcs, $pt) <= $maxMm) {
                return $p;
            }
        }
        return '…';
    }

    /**
     * Sortörés szavak mentén (túl hosszú szót karakterenként tör). Csak a sima szóköz tör: a nem törhető szóköz (U+00A0,
     * az összegekben: 12 500,50 EUR) egyben tartja a számot és a pénznemét (1.21).
     */
    public function tordel(string $szoveg, string $kulcs, float $pt, float $maxMm): array
    {
        $sorok = [];
        foreach (preg_split('/\r?\n/', $szoveg) as $bek) {
            $szavak = preg_split('/[ \t]+/', trim($bek, " \t")) ?: [];
            $akt = '';
            foreach ($szavak as $sz) {
                if ($sz === '') {
                    continue;
                }
                $proba = $akt === '' ? $sz : $akt . ' ' . $sz;
                if ($this->szovegSzelesseg($proba, $kulcs, $pt) <= $maxMm) {
                    $akt = $proba;
                    continue;
                }
                if ($akt !== '') {
                    $sorok[] = $akt;
                    $akt = '';
                }
                // a szó önmagában is túl hosszú → a - . / _ jelek után törik (azonosító, dátum: 2026-EUR- | 000001-K0001,
                // 2026. | 01.01.), és csak a még így is túl hosszú darab karakterenként
                if ($this->szovegSzelesseg($sz, $kulcs, $pt) > $maxMm) {
                    $darab = '';
                    foreach (preg_split('/(?<=[-.\/_])/u', $sz, -1, PREG_SPLIT_NO_EMPTY) as $resz) {
                        if ($darab !== '' && $this->szovegSzelesseg($darab . $resz, $kulcs, $pt) > $maxMm) {
                            $sorok[] = $darab;
                            $darab = '';
                        }
                        if ($this->szovegSzelesseg($darab . $resz, $kulcs, $pt) <= $maxMm) {
                            $darab .= $resz;
                            continue;
                        }
                        foreach (mb_str_split($resz, 1, 'UTF-8') as $c) {
                            if ($this->szovegSzelesseg($darab . $c, $kulcs, $pt) > $maxMm && $darab !== '') {
                                $sorok[] = $darab;
                                $darab = '';
                            }
                            $darab .= $c;
                        }
                    }
                    $akt = $darab;
                } else {
                    $akt = $sz;
                }
            }
            $sorok[] = $akt;
        }
        return $sorok ?: [''];
    }

    /**
     * Egy cella sorai és betűmérete. A nem törhető cella (dátum, összeg – 'nt' oszlop) soronként egyben marad: ha nem fér ki,
     * kisebb betűvel (legfeljebb 75 %-ig), és csak ha még így sem, akkor törik.
     */
    private function cellaTordel(string $t, string $kulcs, float $pt, float $maxMm, bool $nemTorheto): array
    {
        if ($t === '' || !$nemTorheto) {
            return [$t === '' ? [''] : $this->tordel($t, $kulcs, $pt, $maxMm), $pt];
        }
        $sorok = preg_split('/\r?\n/', $t);
        $leg = max(array_map(fn(string $s): float => $this->szovegSzelesseg($s, $kulcs, $pt), $sorok));
        if ($leg <= $maxMm) {
            return [$sorok, $pt];
        }
        $p = floor(max($pt * 0.75, $pt * $maxMm / $leg) * 10) / 10;
        return [$leg * $p / $pt <= $maxMm ? $sorok : $this->tordel($t, $kulcs, $p, $maxMm), $p];
    }

    // ------------------------------------------------------------ táblázat
    /** Az angol (második nyelvű) sorok színe és méretcsökkentése */
    public array $enSzin = [128, 128, 124];

    /**
     * $oszlopok: [['c' => 'Fejléc', 'en' => 'Header', 'w' => mm, 'a' => 'L|R|C', 'st' => true, 'nt' => true], ...]
     *   'en': angol felirat a magyar alatt; 'st': státusz-oszlop (színes PDF-ben a tételsorok egyetlen színes cellája);
     *   'nt': nem törhető (dátum, összeg) – soronként egyben marad, ha nem fér ki, kisebb betűvel (1.21)
     * $sorok: [['cellak' => ['..', ...], 'en' => [k => 'angol'], 'stilus' => 'normal|fo|al|osszes', 'span' => [k => n],
     *           'szinek' => [k => rgb], 'jelveny' => [k => rgb], 'igazit' => [k => 'L|R|C'], 'vastag' => [k => true]], ...]
     *   ('igazit': a cella saját igazítása; 'vastag': a fő sor félkövér cellái – megadása nélkül a fő sor minden cellája félkövér)
     *   'fo' (1.21): fő sor, pl. az utalás a számlái felett – sötét sáv fehér betűkkel (színesben mélyzöld, fekete-fehérben
     *        fekete). A következő fő / összesítő sorig minden sor hozzá tartozik: a bal szélükön a sáv színű gerinc köti őket
     *        a sávhoz, és ha a csoport átnyúlik a következő lapra, ott a sáv „(folytatás)” jelöléssel megismétlődik.
     *        'jelveny': a fő sor cellája fehér, lekerekített jelvényben (pl. a státusz), a megadott színű betűvel.
     *   'al': alsor (pl. részteljesítés) – beljebb kezdődik; 'osszes': összesítő sor – félkövér, a megadott színekkel.
     * Tételsorok: 9,5 pt tiszta fekete szöveg (#000000), alatta az angol 7 pt ugyanazzal a színnel; színes csak a
     * státusz-oszlop ('st'). Ami nem fér ki, tördelődik – a fejléc is. Fekete-fehér módban ($ff) minden fekete, csak a fő
     * sor sávja fekete alapon fehér. Automatikus oldaltörés, minden oldalon ismétlődő fejléc.
     */
    public function tablazat(array $oszlopok, array $sorok): void
    {
        $pt = $this->sorPt;
        $ept = $this->sorEnPt;
        $pad = 1.1;              // cellák belső margója oldalt (mm)
        $fpad = 1.15;            // ... és fent / lent
        $gerinc = 0.9;           // a fő sorhoz tartozó sorok bal szélén a gerinc szélessége
        $behuz = 2.2;            // ... és az első cellájuk behúzása
        $foKoz = 2.6;            // térköz a csoportok között (a fő sor előtt)
        $huLep = fn(float $p): float => $p * 0.42 + 0.45;                    // magyar sorköz
        $enLep = fn(float $p): float => $p * 0.42 + 0.3;                     // angol sorköz
        $enEltol = fn(float $p): float => $p * 0.0945 + 0.45 + $ept * 0.336; // utolsó magyar alapvonal → első angol alapvonal
        $x0 = $this->margoBal;
        $szel = $this->szelesseg();
        $oszlopok = array_values($oszlopok);
        $skala = $szel / max(array_sum(array_column($oszlopok, 'w')), 0.001);
        foreach ($oszlopok as &$c) {
            $c['w'] *= $skala;
        }
        unset($c);

        // fejléc: oszloponként a tördelt magyar és angol sorok (keskeny oszlopnál két sorba)
        $fejPt = $this->fejPt;
        $fejEnPt = $this->fejEnPt;
        $fejSorok = [];
        $fejH = 0.0;
        foreach ($oszlopok as $k => $c) {
            $hu = $this->tordel((string)$c['c'], 'bold', $fejPt, $c['w'] - 2 * $pad);
            $en = !empty($c['en']) ? $this->tordel((string)$c['en'], 'regular', $fejEnPt, $c['w'] - 2 * $pad) : [];
            $fejSorok[$k] = [$hu, $en];
            $fejH = max($fejH, count($hu) * ($fejPt * 0.42 + 0.35) + count($en) * ($fejEnPt * 0.42 + 0.3));
        }
        $fejlec = function () use ($oszlopok, $x0, $szel, $fejPt, $fejEnPt, $pad, $fejSorok, $fejH): void {
            $h = $fejH + 2 * $pad + 0.3;
            $this->teglalap($x0, $this->y, $szel, $h, [242, 242, 239]);
            if ($this->ff) {
                $this->vonal($x0, $this->y, $x0 + $szel, $this->y, [0, 0, 0], 0.2);   // fekete-fehérben a háttér helyett felül is vonal keretezi
            }
            $x = $x0;
            foreach ($oszlopok as $k => $c) {
                $tx = $c['a'] === 'R' ? $x + $c['w'] - $pad : ($c['a'] === 'C' ? $x + $c['w'] / 2 : $x + $pad);
                $ty = $this->y + $pad + $fejPt * 0.36 + 0.25;
                foreach ($fejSorok[$k][0] as $s) {
                    $this->szoveg($tx, $ty, $s, 'bold', $fejPt, [0, 0, 0], $c['a']);
                    $ty += $fejPt * 0.42 + 0.35;
                }
                foreach ($fejSorok[$k][1] as $s) {
                    $this->szoveg($tx, $ty - 0.15, $s, 'regular', $fejEnPt, $this->enSzin, $c['a']);
                    $ty += $fejEnPt * 0.42 + 0.3;
                }
                $x += $c['w'];
            }
            $this->vonal($x0, $this->y + $h, $x0 + $szel, $this->y + $h, [1, 127, 1], 0.4);
            $this->y += $h;
        };

        // egy sor előkészítése: cellánként a tördelt magyar és angol sorok, és a sor magassága
        $elokeszit = function (array $sor, bool $csoportban, string $folyt = '', string $folytEn = '')
            use ($oszlopok, $pt, $ept, $pad, $fpad, $behuz, $huLep, $enLep, $enEltol): array {
            $stilus = $sor['stilus'] ?? 'normal';
            $fo = $stilus === 'fo';
            $kulcs = $fo || $stilus === 'osszes' ? 'bold' : 'regular';
            // cella-összevonás: 'span' => [oszlop => hány oszlopra terjed ki]
            $span = [];
            $kihagy = [];
            foreach ($oszlopok as $k => $c) {
                if (isset($kihagy[$k])) {
                    continue;
                }
                $w = $c['w'];
                for ($j = 1; $j < (int)($sor['span'][$k] ?? 1) && isset($oszlopok[$k + $j]); $j++) {
                    $w += $oszlopok[$k + $j]['w'];
                    $kihagy[$k + $j] = true;
                }
                $span[$k] = $w;
            }
            $cellak = [];
            $h = $pt * 0.36 + $pt * 0.0945;
            foreach ($span as $k => $w) {
                $bal = $pad + ($k === 0 ? ($csoportban ? $behuz : 0) + ($stilus === 'al' ? 2.5 : 0) : 0);
                $hely = $w - $bal - $pad;
                $t = (string)($sor['cellak'][$k] ?? '');
                $e = (string)($sor['en'][$k] ?? '');
                if ($k === 0 && $folyt !== '') {
                    $t .= $folyt;
                    $e = trim("$e $folytEn");
                }
                $jel = $fo && isset($sor['jelveny'][$k]) && $t !== '';
                $ck = $fo && isset($sor['vastag']) ? (empty($sor['vastag'][$k]) ? 'regular' : 'bold') : $kulcs;
                [$hu, $p] = $jel ? [[$t], $pt] : $this->cellaTordel($t, $ck, $pt, $hely, !empty($oszlopok[$k]['nt']));
                $en = $e === '' ? [] : $this->tordel($e, 'regular', $ept, $hely);
                // az első alapvonalig + a további magyar sorok + az angol sorok + az utolsó sor ereszkedője
                $hC = $pt * 0.36 + (count($hu) - 1) * $huLep($p)
                    + ($en ? $enEltol($p) + (count($en) - 1) * $enLep($ept) + $ept * 0.0945 : $p * 0.0945);
                $h = max($h, $hC);
                $cellak[$k] = ['hu' => $hu, 'en' => $en, 'pt' => $p, 'bal' => $bal, 'w' => $w, 'jel' => $jel, 'kulcs' => $ck];
            }
            return ['sor' => $sor, 'stilus' => $stilus, 'csoportban' => $csoportban, 'cellak' => $cellak, 'h' => $h + 2 * $fpad];
        };

        // egy előkészített sor kirajzolása a $this->y magasságban ($osszesUtan: az előző sor is összesítő volt)
        $rajzol = function (array $r, int $zebra, bool $osszesUtan = false) use ($oszlopok, $x0, $szel, $pt, $ept, $pad, $fpad, $gerinc, $huLep, $enLep, $enEltol): void {
            $y = $this->y;
            $h = $r['h'];
            $fo = $r['stilus'] === 'fo';
            $osszes = $r['stilus'] === 'osszes';
            if ($fo) {
                $this->teglalap($x0, $y, $szel, $h, $this->foSzin);
            } elseif ($osszes) {
                // az összesítő blokk tetején erős vonal, a blokkon belül csak hajszálvonal
                $this->teglalap($x0, $y, $szel, $h, [233, 244, 233]);
                $this->vonal($x0, $y, $x0 + $szel, $y, $osszesUtan ? [200, 222, 200] : [1, 127, 1], $osszesUtan ? ($this->ff ? 0.1 : 0.15) : 0.4);
            } elseif ($r['stilus'] === 'al') {
                $this->teglalap($x0, $y, $szel, $h, [250, 250, 248]);
            } elseif ($zebra % 2 === 1) {
                $this->teglalap($x0, $y, $szel, $h, [247, 247, 244]);
            }
            if ($r['csoportban']) {
                $this->teglalap($x0, $y, $gerinc, $h, $this->foSzin);   // a gerinc a fő sor sávjához köti a sort
            }
            $fekete = [0, 0, 0];
            $feher = [255, 255, 255];
            $x = $x0;
            foreach ($oszlopok as $k => $c) {
                if (isset($r['cellak'][$k])) {
                    $cl = $r['cellak'][$k];
                    $a = $r['sor']['igazit'][$k] ?? $c['a'];
                    $tx = $a === 'R' ? $x + $cl['w'] - $pad : ($a === 'C' ? $x + $cl['w'] / 2 : $x + $cl['bal']);
                    $ty = $y + $fpad + $pt * 0.36;
                    if ($fo) {
                        // fő sor: fehér betűk a sötét sávon (fekete-fehérben is fehér), az angol halványabban
                        [$huSzin, $huFf, $enSzin, $enFf] = [$feher, $feher, $this->foEnSzin, $feher];
                    } else {
                        // tételsor: tiszta fekete, csak a státusz színes; összesítő sor: a megadott színek, az angol halványan
                        $huSzin = $osszes || !empty($c['st']) ? ($r['sor']['szinek'][$k] ?? $fekete) : $fekete;
                        [$huFf, $enSzin, $enFf] = [null, $osszes ? $this->enSzin : $huSzin, null];
                    }
                    if ($cl['jel']) {
                        // jelvény: fehér, lekerekített; a betű a megadott színű (fekete-fehérben fekete)
                        $jp = $this->jelvenyPt;
                        $jw = $this->szovegSzelesseg($cl['hu'][0], 'bold', $jp) + 3.4;
                        $jh = $jp * 0.42 + 1.35;
                        $jx = $a === 'R' ? $tx - $jw : ($a === 'C' ? $tx - $jw / 2 : $tx);
                        $kozep = $ty - $pt * 0.1132;
                        $this->lekerekitett($jx, $kozep - $jh / 2, $jw, $jh, $jh / 2, $feher, $feher);
                        $this->szoveg($jx + $jw / 2, $kozep + $jp * 0.1132, $cl['hu'][0], 'bold', $jp, $r['sor']['jelveny'][$k], 'C');
                    } else {
                        foreach ($cl['hu'] as $i => $s) {
                            $this->szoveg($tx, $ty + $i * $huLep($cl['pt']), $s, $cl['kulcs'], $cl['pt'], $huSzin, $a, $huFf);
                        }
                    }
                    $ty += (count($cl['hu']) - 1) * $huLep($cl['pt']) + $enEltol($cl['pt']);
                    foreach ($cl['en'] as $i => $s) {
                        $this->szoveg($tx, $ty + $i * $enLep($ept), $s, 'regular', $ept, $enSzin, $a, $enFf);
                    }
                }
                $x += $c['w'];
            }
            if (!$fo && !$osszes) {
                $this->vonal($x0 + ($r['csoportban'] ? $gerinc : 0), $y + $h, $x0 + $szel, $y + $h, [226, 226, 222], $this->ff ? 0.1 : 0.15);
            }
        };

        // a fő sorhoz tartozó sorok: a következő fő / összesítő sorig
        $el = [];
        $csoportban = false;
        foreach ($sorok as $sor) {
            $st = $sor['stilus'] ?? 'normal';
            if ($st === 'fo' || $st === 'osszes') {
                $csoportban = false;
            }
            $el[] = $elokeszit($sor, $csoportban);
            if ($st === 'fo') {
                $csoportban = true;
            }
        }
        $this->helyBiztosit($fejH + 30);
        $fejlec();
        $zebra = 0;
        $fo = null;       // az aktuális csoport fő sora – ha a csoport a következő lapon folytatódik, ott megismétlődik
        $elso = true;     // a fejléc alatti első sor
        $elozo = '';      // az előző sor stílusa
        foreach ($el as $i => $r) {
            $isFo = $r['stilus'] === 'fo';
            $koz = $isFo && !$elso ? $foKoz : 0.0;
            // a fő sor ne maradjon árván a lap alján: alatta elférjen a csoport első sora is
            $kell = $r['h'] + ($isFo && isset($el[$i + 1]) && $el[$i + 1]['csoportban'] ? $el[$i + 1]['h'] : 0.0);
            // az összesítő blokk (egymást követő összesítő sorok) egyben marad, és nem kerül egyedül a lapra: az előtte álló
            // utolsó tételsor is vele megy
            if ($r['stilus'] !== 'osszes' || $elozo !== 'osszes') {
                for ($j = $i + 1; isset($el[$j]) && $el[$j]['stilus'] === 'osszes'; $j++) {
                    $kell += $el[$j]['h'];
                }
            }
            if ($this->y + $koz + $kell > $this->alsoHatar()) {
                $this->ujOldal();
                $fejlec();
                $zebra = 0;
                $koz = 0.0;
                $elozo = '';
                if ($r['csoportban'] && $fo !== null) {
                    $folyt = $elokeszit($fo['sor'], false, ' (folytatás)', '(continued)');
                    $rajzol($folyt, 0);
                    $this->y += $folyt['h'];
                }
            }
            $this->y += $koz;
            $rajzol($r, $zebra, $elozo === 'osszes' && $r['stilus'] === 'osszes');
            $this->y += $r['h'];
            $elozo = $r['stilus'];
            $zebra = $isFo ? 0 : $zebra + 1;   // a csoport sorainak csíkozása elölről indul
            $fo = $isFo ? $r : ($r['csoportban'] ? $fo : null);
            $elso = false;
        }
        $this->y += 2.0;
    }

    /** Szakasz-fejléc (színes sáv) – opcionális angol sorral a magyar alatt */
    public function szakasz(string $cim, string $jobb = '', array $szin = [1, 127, 1], string $cimEn = '', string $jobbEn = ''): void
    {
        $ketnyelvu = $cimEn !== '' || $jobbEn !== '';
        $h = $ketnyelvu ? 11.2 : 7.2;
        // a szakasz-fejléc ne maradjon árván a lap alján: alatta elférjen a táblázat fejléce + az első tételsor
        $this->helyBiztosit($h + 46);
        $this->y += 2.5;
        $this->teglalap($this->margoBal, $this->y, $this->szelesseg(), $h, [233, 244, 233]);
        $this->teglalap($this->margoBal, $this->y, 1.6, $h, $szin);
        $this->szoveg($this->margoBal + 4, $this->y + 5.1, $cim, 'bold', 10.5, $szin);
        if ($jobb !== '') {
            $this->szoveg($this->margoBal + $this->szelesseg() - 2, $this->y + 5.0, $jobb, 'regular', 8, [82, 82, 82], 'R');
        }
        if ($ketnyelvu) {
            if ($cimEn !== '') {
                $this->szoveg($this->margoBal + 4, $this->y + 9.3, $cimEn, 'regular', 7.5, $this->enSzin);
            }
            if ($jobbEn !== '') {
                $this->szoveg($this->margoBal + $this->szelesseg() - 2, $this->y + 9.2, $jobbEn, 'regular', 6.8, $this->enSzin, 'R');
            }
        }
        $this->y += $h + 1.8;
    }

    /** Kétnyelvű szabad szöveg: magyar sor + alatta az angol (halványabban) */
    public function szoveg2(float $x, float $y, string $hu, string $en, string $kulcs = 'regular', float $pt = 8, array $rgb = [82, 82, 82], string $igazit = 'L'): float
    {
        $this->szoveg($x, $y, $hu, $kulcs, $pt, $rgb, $igazit);
        if ($en !== '') {
            $y += $pt * 0.42 + 0.8;
            $this->szoveg($x, $y, $en, 'regular', max(5, $pt - 0.8), $this->enSzin, $igazit);
        }
        return $y;
    }

    // ------------------------------------------------------------ kimenet
    public function kimenet(): string
    {
        if ($this->oldalSzam === 0) {
            $this->ujOldal();
        }
        $this->oldalak[] = $this->tartalom;
        $osszes = count($this->oldalak);
        // láblécek utólag (ismerjük az oldalszámot)
        if ($this->lablecRajzolo) {
            foreach ($this->oldalak as $idx => $t) {
                $this->tartalom = '';
                ($this->lablecRajzolo)($this, $idx + 1, $osszes);
                $this->oldalak[$idx] = $t . $this->tartalom;
            }
        }

        $obj = [];
        $ujObj = function (string $tart) use (&$obj): int { $obj[] = $tart; return count($obj); };
        $stream = function (string $adat, string $extra = '') use ($ujObj): int {
            $t = gzcompress($adat, 9);
            return $ujObj("<< /Length " . strlen($t) . " /Filter /FlateDecode $extra >>\nstream\n" . $t . "\nendstream");
        };

        // betűk
        $betuObjs = [];
        foreach ($this->betuk as $kulcs => $b) {
            $ff = $stream($b->adat, '/Length1 ' . strlen($b->adat));
            $sk = 1000 / $b->unitsPerEm;
            $bbox = implode(' ', array_map(fn($v) => (int)round($v * $sk), $b->bbox));
            $fd = $ujObj(sprintf("<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%s] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>",
                $b->nev, $bbox, (int)round($b->ascent * $sk), (int)round($b->descent * $sk), (int)round($b->capHeight * $sk), $ff));
            $w = [];
            for ($g = 0; $g < $b->glyphSzam; $g++) {
                $w[] = (int)round(($b->adv[$g] ?? 0) * $sk);
            }
            $cid = $ujObj(sprintf("<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW 500 /W [0 [%s]] /CIDToGIDMap /Identity >>",
                $b->nev, $fd, implode(' ', $w)));
            // ToUnicode
            $inv = [];
            foreach ($b->cmap as $u => $g) {
                if (!isset($inv[$g])) {
                    $inv[$g] = $u;
                }
            }
            $bf = '';
            $db = 0;
            foreach ($this->hasznaltGlyph[$kulcs] as $g => $_) {
                if (isset($inv[$g]) && $inv[$g] <= 0xFFFF) {
                    $bf .= sprintf("<%04X> <%04X>\n", $g, $inv[$g]);
                    $db++;
                }
            }
            $cmapStr = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n$db beginbfchar\n$bf" . "endbfchar\nendcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
            $tu = $stream($cmapStr);
            $betuObjs[$kulcs] = $ujObj(sprintf("<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>", $b->nev, $cid, $tu));
        }
        $fontDict = '';
        foreach ($this->betuKulcs as $kulcs => $nev) {
            $fontDict .= "$nev {$betuObjs[$kulcs]} 0 R ";
        }
        $pagesObj = count($obj) + 1 + count($this->oldalak) * 2;
        $pageRefs = [];
        foreach ($this->oldalak as $t) {
            $c = $stream($t);
            $p = $ujObj(sprintf("<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R /Resources << /Font << %s>> >> >>", $pagesObj, self::A4_SZ * self::PT, self::A4_MA * self::PT, $c, $fontDict));
            $pageRefs[] = "$p 0 R";
        }
        $pages = $ujObj(sprintf("<< /Type /Pages /Kids [%s] /Count %d >>", implode(' ', $pageRefs), count($pageRefs)));
        if ($pages !== $pagesObj) {
            throw new RuntimeException('PDF objektum-sorrend hiba.');
        }
        $catalog = $ujObj("<< /Type /Catalog /Pages $pages 0 R /PageLayout /SinglePage >>");
        $info = $ujObj("<< /Producer (BaninaPRO) /Creator (BaninaPRO) /Title (BaninaPRO lista) /CreationDate (D:" . date('YmdHis') . ") >>");

        $ki = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        foreach ($obj as $i => $t) {
            $offs[] = strlen($ki);
            $ki .= ($i + 1) . " 0 obj\n" . $t . "\nendobj\n";
        }
        $xref = strlen($ki);
        $ki .= "xref\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
        foreach ($offs as $o) {
            $ki .= sprintf("%010d 00000 n \n", $o);
        }
        $ki .= "trailer\n<< /Size " . (count($obj) + 1) . " /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $ki;
    }
}
