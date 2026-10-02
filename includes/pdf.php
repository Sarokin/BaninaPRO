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

    public function vonal(float $x1, float $y1, float $x2, float $y2, array $rgb, float $vastag = 0.2): void
    {
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
     */
    public function szoveg(float $x, float $y, string $szoveg, string $kulcs, float $pt, array $rgb = [23, 23, 23], string $igazit = 'L'): void
    {
        if ($szoveg === '') {
            return;
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

    /** Sortörés szavak mentén (túl hosszú szót karakterenként tör) */
    public function tordel(string $szoveg, string $kulcs, float $pt, float $maxMm): array
    {
        $sorok = [];
        foreach (preg_split('/\r?\n/', $szoveg) as $bek) {
            $szavak = preg_split('/\s+/u', trim($bek)) ?: [];
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
                // a szó önmagában is túl hosszú → karakterenként
                if ($this->szovegSzelesseg($sz, $kulcs, $pt) > $maxMm) {
                    $darab = '';
                    foreach (mb_str_split($sz, 1, 'UTF-8') as $c) {
                        if ($this->szovegSzelesseg($darab . $c, $kulcs, $pt) > $maxMm && $darab !== '') {
                            $sorok[] = $darab;
                            $darab = '';
                        }
                        $darab .= $c;
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

    // ------------------------------------------------------------ táblázat
    /** Az angol (második nyelvű) sorok színe és méretcsökkentése */
    public array $enSzin = [128, 128, 124];

    /**
     * $oszlopok: [['c' => 'Fejléc', 'en' => 'Header', 'w' => mm, 'a' => 'L|R|C'], ...]  – 'en': angol felirat a magyar alatt
     * $sorok:    [ ['cellák' => ['..', ...], 'en' => [k => 'angol'], 'stilus' => 'normal|al|osszes', 'span' => [k => n], 'szinek' => [k => rgb]], ... ]
     * Automatikus oldaltörés, minden oldalon ismétlődő (kétnyelvű) fejléc.
     */
    public function tablazat(array $oszlopok, array $sorok, array $o = []): void
    {
        $pt = $o['pt'] ?? 7.2;
        $sorKoz = 0.75;            // sorok közti extra (mm)
        $pad = 1.0;
        $x0 = $this->margoBal;
        $teljes = array_sum(array_column($oszlopok, 'w'));
        $skala = $this->szelesseg() / max($teljes, 0.001);
        foreach ($oszlopok as &$c) {
            $c['w'] *= $skala;
        }
        unset($c);
        $vanEnFej = count(array_filter($oszlopok, fn($c) => !empty($c['en']))) > 0;
        $enPt = max(4.8, $pt - 1.3);
        $fejlec = function () use ($oszlopok, $x0, $pt, $pad, $vanEnFej, $enPt) {
            $h = $pt * 0.42 + 2 * $pad + 0.6 + ($vanEnFej ? $enPt * 0.42 + 0.5 : 0);
            $this->teglalap($x0, $this->y, $this->szelesseg(), $h, [242, 242, 239]);
            $x = $x0;
            foreach ($oszlopok as $c) {
                $tx = $c['a'] === 'R' ? $x + $c['w'] - $pad : ($c['a'] === 'C' ? $x + $c['w'] / 2 : $x + $pad);
                $y1 = $this->y + $pad + ($pt - 0.6) * 0.36 + 0.5;
                $this->szoveg($tx, $y1, $this->rovidit($c['c'], 'bold', $pt - 0.6, $c['w'] - 2 * $pad), 'bold', $pt - 0.6, [82, 82, 82], $c['a']);
                if ($vanEnFej && !empty($c['en'])) {
                    $this->szoveg($tx, $y1 + $enPt * 0.42 + 0.55, $this->rovidit($c['en'], 'regular', $enPt, $c['w'] - 2 * $pad), 'regular', $enPt, $this->enSzin, $c['a']);
                }
                $x += $c['w'];
            }
            $this->vonal($x0, $this->y + $h, $x0 + $this->szelesseg(), $this->y + $h, [1, 127, 1], 0.4);
            $this->y += $h;
        };
        $this->helyBiztosit($pt * 0.42 * 3 + 12);
        $fejlec();
        $i = 0;
        foreach ($sorok as $sor) {
            $stilus = $sor['stilus'] ?? 'normal';
            $al = $stilus === 'al';
            $osszes = $stilus === 'osszes';
            $spt = $al ? $pt - 0.8 : $pt;
            $ept = max(4.6, $spt - 0.9);   // angol sor
            $kulcs = $osszes ? 'bold' : 'regular';
            // cella-összevonás: 'span' => [oszlop => hány oszlopra terjed ki] (pl. összesítő címkéknek)
            $szel = [];
            $kihagy = [];
            foreach ($oszlopok as $k => $c) {
                if (isset($kihagy[$k])) {
                    $szel[$k] = 0;
                    continue;
                }
                $n = (int)($sor['span'][$k] ?? 1);
                $w = $c['w'];
                for ($j = 1; $j < $n && isset($oszlopok[$k + $j]); $j++) {
                    $w += $oszlopok[$k + $j]['w'];
                    $kihagy[$k + $j] = true;
                }
                $szel[$k] = $w;
            }
            // tördelés cellánként (magyar + angol sorok)
            $cellak = [];
            $enCellak = [];
            $maxH = $spt * 0.42 + $sorKoz;
            foreach ($oszlopok as $k => $c) {
                $t = isset($kihagy[$k]) ? '' : (string)($sor['cellak'][$k] ?? '');
                $sorokC = $t === '' ? [''] : $this->tordel($t, $kulcs, $spt, $szel[$k] - 2 * $pad);
                $cellak[$k] = $sorokC;
                $e = isset($kihagy[$k]) ? '' : (string)($sor['en'][$k] ?? '');
                $enCellak[$k] = $e === '' ? [] : $this->tordel($e, 'regular', $ept, $szel[$k] - 2 * $pad);
                $hC = count($sorokC) * ($spt * 0.42 + $sorKoz) + count($enCellak[$k]) * ($ept * 0.42 + $sorKoz * 0.6);
                $maxH = max($maxH, $hC);
            }
            $h = $maxH + 2 * $pad - 0.2;
            if ($this->y + $h > $this->alsoHatar()) {
                $this->ujOldal();
                $fejlec();
                $i = 0;
            }
            if ($osszes) {
                $this->teglalap($x0, $this->y, $this->szelesseg(), $h, [233, 244, 233]);
                $this->vonal($x0, $this->y, $x0 + $this->szelesseg(), $this->y, [1, 127, 1], 0.4);
            } elseif ($al) {
                $this->teglalap($x0, $this->y, $this->szelesseg(), $h, [250, 250, 248]);
            } elseif ($i % 2 === 1) {
                $this->teglalap($x0, $this->y, $this->szelesseg(), $h, [247, 247, 244]);
            }
            $x = $x0;
            $szin = $al ? [82, 82, 82] : [23, 23, 23];
            foreach ($oszlopok as $k => $c) {
                $ty = $this->y + $pad + $spt * 0.36 + 0.45;
                $cw = $szel[$k] > 0 ? $szel[$k] : $c['w'];
                $tx = $c['a'] === 'R' ? $x + $cw - $pad : ($c['a'] === 'C' ? $x + $cw / 2 : $x + $pad + ($al && $k === 0 ? 2.5 : 0));
                $cSzin = $sor['szinek'][$k] ?? $szin;
                foreach ($cellak[$k] as $sorSz) {
                    $this->szoveg($tx, $ty, $sorSz, $kulcs, $spt, $cSzin, $c['a']);
                    $ty += $spt * 0.42 + $sorKoz;
                }
                foreach ($enCellak[$k] as $sorSz) {
                    $this->szoveg($tx, $ty - 0.25, $sorSz, 'regular', $ept, $this->enSzin, $c['a']);
                    $ty += $ept * 0.42 + $sorKoz * 0.6;
                }
                $x += $c['w'];
            }
            $this->y += $h;
            if (!$osszes) {
                $this->vonal($x0, $this->y, $x0 + $this->szelesseg(), $this->y, [232, 232, 229], 0.15);
            }
            $i++;
        }
        $this->y += 1.5;
    }

    /** Szakasz-fejléc (színes sáv) – opcionális angol sorral a magyar alatt */
    public function szakasz(string $cim, string $jobb = '', array $szin = [1, 127, 1], string $cimEn = '', string $jobbEn = ''): void
    {
        $ketnyelvu = $cimEn !== '' || $jobbEn !== '';
        $h = $ketnyelvu ? 11.2 : 7.2;
        $this->helyBiztosit($h + 16);
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
