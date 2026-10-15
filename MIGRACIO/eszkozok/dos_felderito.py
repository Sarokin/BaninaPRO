#!/usr/bin/env python3
"""
DOS-os program mappájának felderítése és az adatfájlok kiolvasása – csak olvas, a forrást nem módosítja.

    python3 -I dos_felderito.py <DOS mappa> <kimeneti mappa> [--ismert ismert_dos.json]

Mit csinál:
  * minden fájlt (a ZIP-ek tartalmát is) leltárba vesz: méret, dátum, felismert típus;
  * a dBase / Clipper / FoxPro táblákat (.DBF, memó: .DBT / .FPT) teljesen kiolvassa CSV-be (UTF-8),
    a törölt rekordokat is (_torolt oszlop), a kódlapot felismeri (CP852, CWI-2, CP437, CP1250, Latin-2);
  * a programfájlokból (EXE, OVL) kikeresi, mivel írták (Clipper, FoxPro, dBASE, Turbo Pascal, Btrieve…);
  * --ismert: a BaninaPRO-ban már meglévő DOS-értékek (kötésszám, kisszám, szállítókód, utalásszám, KE-szám,
    cégnév…) alapján megmondja, melyik tábla melyik mezője mit tartalmaz; ismeretlen bináris fájlban a bájtok
    között keres, és a találatok távolságából becsüli a rekordhosszt.

Csak a Python 3.9+ szabványos könyvtára kell. Futtatás -I kapcsolóval (a forrásmappából semmit nem tölt be).
"""
import argparse
import collections
import csv
import datetime
import json
import os
import re
import struct
import sys
import zipfile

# ---------------------------------------------------------------------------------------------------------------
#  Kódlapok
# ---------------------------------------------------------------------------------------------------------------

def _cwi2_tabla():
    """CWI-2 (magyar DOS-kódlap): a CP437 nyolc pozíciója magyar betűre cserélve."""
    t = [bytes([i]).decode('cp437') for i in range(256)]
    for b, ch in {0x8D: 'Í', 0x8F: 'Á', 0x93: 'ő', 0x95: 'Ó', 0x96: 'ű', 0x97: 'Ú', 0x98: 'Ű', 0xA7: 'Ő'}.items():
        t[b] = ch
    return t

_CWI2 = _cwi2_tabla()
KODLAPOK = ['cp852', 'cwi2', 'cp437', 'cp1250', 'iso8859_2']
MAGYAR = set('áéíóöőúüűÁÉÍÓÖŐÚÜŰ')
SEMLEGES = set('€§°²³µ×÷±«»„”–—’')   # előfordulhat, de nem magyar betű: se pont, se büntetés


def dekodol(b, kodlap):
    if kodlap == 'cwi2':
        return ''.join(_CWI2[x] for x in b)
    return b.decode(kodlap, errors='replace')


def pontszam(szoveg):
    """Magyar szövegnek mennyire hihető: a magyar ékezetes betű +2, minden más nem ASCII karakter −3."""
    p = 0
    for ch in szoveg:
        if ch < '\x80':
            if ch < ' ' and ch not in '\r\n\t':
                p -= 3
            continue
        if ch in MAGYAR:
            p += 2
        elif ch not in SEMLEGES:
            p -= 3
    return p


def kodlap_felismer(mintak, nyelv_bajt=None):
    """A minták (bájtsorozatok) alapján a legvalószínűbb kódlap. Ha nincs ékezetes bájt: a DBF nyelvi bájtja vagy cp852."""
    egyutt = b'\n'.join(m for m in mintak if any(x >= 0x80 for x in m))
    nyelv = {0x64: 'cp852', 0x01: 'cp437', 0x02: 'cp850', 0xC8: 'cp1250', 0x57: 'cp1250', 0x03: 'cp1252'}.get(nyelv_bajt or 0)
    if not egyutt:
        return (nyelv if nyelv in KODLAPOK else 'cp852'), {}
    pontok = {k: pontszam(dekodol(egyutt, k)) for k in KODLAPOK}
    legjobb = max(pontok, key=lambda k: (pontok[k], k == nyelv, k == 'cp852'))
    return legjobb, pontok


# ---------------------------------------------------------------------------------------------------------------
#  Fájltípus
# ---------------------------------------------------------------------------------------------------------------

DBF_VERZIOK = {0x02: 'FoxBASE', 0x03: 'dBase III / Clipper (memó nélkül)', 0x04: 'dBase IV (memó nélkül)', 0x05: 'dBase V',
               0x30: 'Visual FoxPro', 0x31: 'Visual FoxPro (autoinc)', 0x32: 'Visual FoxPro (varchar)', 0x43: 'dBase IV SQL',
               0x63: 'dBase IV SQL rendszer', 0x83: 'dBase III / Clipper (memóval, .DBT)', 0x8B: 'dBase IV (memóval, .DBT)',
               0x8E: 'dBase IV SQL (memóval)', 0xCB: 'dBase IV SQL (memóval)', 0xE5: 'HiPer-Six / SMT (memóval)',
               0xF5: 'FoxPro 2.x (memóval, .FPT)', 0xFB: 'FoxBASE (memóval)'}

PROGRAM_JELEK = [b'CA-Clipper', b'Clipper', b'Nantucket', b'Summer \'87', b'FoxPro', b'FOXPRO', b'Fox Software', b'dBASE', b'DBASE',
                 b'Ashton-Tate', b'Turbo Pascal', b'Borland', b'Btrieve', b'BTRIEVE', b'QuickBASIC', b'Microsoft C', b'Clarion',
                 b'Paradox', b'Harbour', b'xBase', b'Blinker', b'RTLink', b'ExoSpace', b'CauseWay', b'DOS/4G', b'Novell']


def dbf_fejlec(fej, meret):
    """DBF fejléc ellenőrzése: (verzió, rekordszám, fejlécméret, rekordhossz) vagy None.
    A rekordszám lehet elavult (félbe írt fájl) – azt a beolvasás a fájlmérethez igazítja."""
    if len(fej) < 33 or fej[0] not in DBF_VERZIOK:
        return None
    if not (1 <= fej[2] <= 12 and 1 <= fej[3] <= 31):      # az utolsó módosítás dátuma (ÉÉ HH NN)
        return None
    n, fejhossz, rekhossz = struct.unpack('<IHH', fej[4:12])
    if fejhossz < 33 or rekhossz < 2 or fejhossz > meret:
        return None
    # a mezőleírók 32 bájtosak, és egy leíró helyén álló 0x0D zárja őket a fejlécen belül
    # (a leírókon belül is lehet 0x0D bájt, pl. egy 13 hosszú mező hossza – ezért leírónként lépünk)
    p = 32
    while p < min(fejhossz, len(fej)):
        if fej[p] == 0x0D:
            return fej[0], n, fejhossz, rekhossz
        p += 32
    if fejhossz > len(fej):
        return fej[0], n, fejhossz, rekhossz   # a fejléc hosszabb a beolvasottnál – elfogadjuk
    return None


def tipus_felismer(ut, meret):
    with open(ut, 'rb') as f:
        fej = f.read(65536)
    nev = os.path.basename(ut).upper()
    ext = os.path.splitext(nev)[1]
    if meret == 0:
        return 'üres'
    if fej[:2] == b'PK':
        return 'zip'
    if dbf_fejlec(fej, meret):
        return 'dbf'
    fej = fej[:4096]
    if ext in ('.DBT', '.FPT'):
        return 'memó'
    if ext in ('.NTX', '.NDX', '.CDX', '.IDX', '.MDX', '.NSX'):
        return 'index'
    if fej[:2] in (b'MZ', b'ZM'):
        return 'program'
    if ext in ('.COM', '.OVL', '.OVR', '.DLL', '.PLL'):
        return 'program'
    nyomtathato = sum(1 for x in fej if 32 <= x < 127 or x in (9, 10, 13) or x >= 128)
    if fej and nyomtathato / len(fej) > 0.97 and b'\0' not in fej:
        return 'szöveg'
    return 'bináris'


# ---------------------------------------------------------------------------------------------------------------
#  DBF olvasás
# ---------------------------------------------------------------------------------------------------------------

class Memo:
    """.DBT (dBase III / Clipper: 512 bájtos blokk; dBase IV: blokkméret a fejlécben) vagy .FPT (FoxPro)."""

    def __init__(self, ut, verzio):
        self.ut = ut
        self.adat = open(ut, 'rb').read() if ut else b''
        self.fpt = ut is not None and ut.upper().endswith('.FPT')
        self.blokk = 512
        if self.fpt and len(self.adat) >= 8:
            self.blokk = struct.unpack('>H', self.adat[6:8])[0] or 64
        elif verzio in (0x8B, 0x8E, 0xCB) and len(self.adat) >= 22:
            b = struct.unpack('<H', self.adat[20:22])[0]
            self.blokk = b if b >= 64 else 512

    def olvas(self, szam):
        if not self.adat or szam <= 0:
            return b''
        p = szam * self.blokk
        if p >= len(self.adat):
            return b''
        if self.fpt:
            if p + 8 > len(self.adat):
                return b''
            hossz = struct.unpack('>I', self.adat[p + 4:p + 8])[0]
            return self.adat[p + 8:p + 8 + hossz]
        if self.adat[p:p + 4] == b'\xff\xff\x08\x00':   # dBase IV blokkfej
            hossz = struct.unpack('<I', self.adat[p + 4:p + 8])[0]
            return self.adat[p + 8:p + hossz]
        v = self.adat.find(b'\x1a', p)
        return self.adat[p:v if v >= 0 else len(self.adat)]


def dbf_mezok(fej_teljes, rekhossz):
    """Mezőleírók. A Clipper a 255-nél hosszabb C mező hosszát a tizedesek bájtjával együtt adja meg."""
    mezok = []
    p = 32
    while p + 32 <= len(fej_teljes) and fej_teljes[p] != 0x0D:
        d = fej_teljes[p:p + 32]
        nev = d[:11].split(b'\0')[0].decode('ascii', errors='replace').strip()
        tipus = chr(d[11])
        hossz, tizedes = d[16], d[17]
        mezok.append({'nev': nev, 'tipus': tipus, 'hossz': hossz, 'tizedes': tizedes, 'flag': d[18]})
        p += 32
    if sum(m['hossz'] for m in mezok) + 1 != rekhossz:
        alt = [dict(m, hossz=m['hossz'] + 256 * m['tizedes'], tizedes=0) if m['tipus'] == 'C' else m for m in mezok]
        if sum(m['hossz'] for m in alt) + 1 == rekhossz:
            mezok = alt
    eltolas = 1
    for m in mezok:
        m['eltolas'] = eltolas
        eltolas += m['hossz']
    return mezok


def dbf_olvas(ut, kodlap=None):
    """Egy DBF tábla teljes beolvasása. Visszaad: leírás (dict) és a rekordok (listák listája, nyers értékekkel)."""
    meret = os.path.getsize(ut)
    with open(ut, 'rb') as f:
        adat = f.read()
    verzio, n_fej, fejhossz, rekhossz = dbf_fejlec(adat[:65536], meret)
    ev, ho, nap = adat[1], adat[2], adat[3]
    frissitve = None
    try:
        frissitve = datetime.date(1900 + ev if ev < 200 else ev, ho, nap).isoformat()
    except ValueError:
        pass
    nyelv = adat[29]
    mezok = dbf_mezok(adat[:fejhossz], rekhossz)
    n_fajl = max(0, (meret - fejhossz) // rekhossz)
    n = min(n_fej, n_fajl) if n_fej else n_fajl
    memo_ut = None
    alap = os.path.splitext(ut)[0]
    for ext in ('.DBT', '.dbt', '.FPT', '.fpt', '.Dbt', '.Fpt'):
        if os.path.exists(alap + ext):
            memo_ut = alap + ext
            break
    memo = Memo(memo_ut, verzio) if any(m['tipus'] in 'MGB' for m in mezok) and memo_ut else None

    nyers = []
    for i in range(n):
        p = fejhossz + i * rekhossz
        rek = adat[p:p + rekhossz]
        if len(rek) < rekhossz:
            break
        nyers.append(rek)

    # kódlap: a szöveges mezők és memók ékezetes bájtjai alapján
    if kodlap is None:
        mintak = []
        for rek in nyers[:20000]:
            for m in mezok:
                if m['tipus'] == 'C':
                    mintak.append(rek[m['eltolas']:m['eltolas'] + m['hossz']])
        kodlap, pontok = kodlap_felismer(mintak, nyelv)
    else:
        pontok = {}

    sorok = []
    torolt_db = 0
    for i, rek in enumerate(nyers):
        torolt = rek[0:1] == b'*'
        torolt_db += torolt
        ertekek = []
        for m in mezok:
            b = rek[m['eltolas']:m['eltolas'] + m['hossz']]
            ertekek.append(mezo_ertek(m, b, kodlap, memo, verzio))
        sorok.append((i + 1, torolt, ertekek))
    leiras = {'fajl': ut, 'verzio': f'0x{verzio:02X} {DBF_VERZIOK.get(verzio, "")}', 'frissitve_fejlec': frissitve,
              'rekord_fejlec': n_fej, 'rekord_fajl': n_fajl, 'rekord': len(sorok), 'torolt': torolt_db, 'fejlec_hossz': fejhossz,
              'rekord_hossz': rekhossz, 'nyelvi_bajt': f'0x{nyelv:02X}', 'kodlap': kodlap, 'kodlap_pontok': pontok,
              'memo': os.path.basename(memo_ut) if memo_ut else None, 'mezok': mezok}
    if n_fej != n_fajl:
        leiras['figyelem'] = f'a fejléc {n_fej} rekordot ír, a fájlban {n_fajl} fér el (sérült vagy éppen írt fájl?)'
    return leiras, sorok


def mezo_ertek(m, b, kodlap, memo, verzio):
    t = m['tipus']
    if t in ('C', 'V', 'Q'):
        return dekodol(b, kodlap).rstrip(' \0')
    if t in ('N', 'F'):
        s = b.decode('ascii', errors='replace').strip(' \0')
        return s
    if t == 'D':
        return b.decode('ascii', errors='replace').strip(' \0')
    if t == 'L':
        s = b.decode('ascii', errors='replace').strip()
        return {'T': 'T', 't': 'T', 'Y': 'T', 'y': 'T', 'F': 'F', 'f': 'F', 'N': 'F', 'n': 'F'}.get(s, '')
    if t in ('M', 'G', 'B') and m['hossz'] == 10:
        s = b.decode('ascii', errors='replace').strip(' \0')
        if t == 'B' and verzio in (0x30, 0x31, 0x32):
            return repr(struct.unpack('<d', b[:8])[0]) if len(b) >= 8 else ''
        if not s.isdigit() or memo is None:
            return '' if not s.strip('0') else f'[memó #{s}]'
        return dekodol(memo.olvas(int(s)), kodlap).rstrip(' \0\x1a')
    if t in ('M', 'G') and m['hossz'] == 4:   # Visual FoxPro: 4 bájtos blokkszám
        szam = struct.unpack('<I', b)[0]
        return dekodol(memo.olvas(szam), kodlap).rstrip(' \0') if memo and szam else ''
    if t == 'I':
        return str(struct.unpack('<i', b[:4])[0])
    if t == 'Y':
        return f'{struct.unpack("<q", b[:8])[0] / 10000:.4f}'
    if t in ('T', '@') and len(b) == 8:
        jd, ms = struct.unpack('<ii', b)
        if jd == 0:
            return ''
        d = datetime.date.fromordinal(jd - 1721425) if jd > 1721425 else None
        return f'{d.isoformat()} {ms // 3600000:02d}:{ms // 60000 % 60:02d}:{ms // 1000 % 60:02d}' if d else ''
    if t == 'O':
        return repr(struct.unpack('<d', b[:8])[0])
    if t == '+':
        return str(struct.unpack('<i', b[:4])[0])
    return b.hex()


# ---------------------------------------------------------------------------------------------------------------
#  Ismert értékek (a BaninaPRO-ban már meglévő DOS-adatok) – mező-felismerés
# ---------------------------------------------------------------------------------------------------------------

def norm(s):
    return re.sub(r'[^0-9A-ZÁÉÍÓÖŐÚÜŰ]', '', str(s).upper())


def ismert_kategoriak(ut):
    """ismert_dos.json → {kategória: set(normalizált érték)} és a nyers minták a bájtkereséshez."""
    j = json.load(open(ut, encoding='utf-8'))
    k = collections.defaultdict(set)
    nyers = collections.defaultdict(list)

    def add(kat, v, minta=True):
        if v is None:
            return
        v = str(v).strip()
        if len(norm(v)) < 2:
            return
        k[kat].add(norm(v))
        if minta and len(v) >= 4:
            nyers[kat].append(v)

    for c in j['cegek']:
        add('cégnév', c['nev'])
        for tipus, kod in c['dos_kodok']:
            add('VEVO-kód (szállító)' if tipus == 'VEVO' else 'UGYF-kód (vevő adószáma)', kod)
    for x in j['kotesek']:
        add('kötésszám', x['dos_kotesszam'])
        add('kötés áru', x['megnevezes'], False)
    for b in j['bejovo']:
        add('kisszám', b['dos_kisszam'])
        add('bejövő számlaszám', b['szamlaszam'])
        add('BESZÁM', b['beszam'])
        add('VEVO-kód (szállító)', b['dos'].get('Szállító DOS-kódja'))
        add('DOS forintszámla', b['dos'].get('DOS forintszámla'))
        add('VTSZ', b['dos'].get('VTSZ'), False)
    for s in j['kimeno']:
        add('kimenő számlaszám (KE)', s['szamlaszam'])
        d = s['dos_azonosito']
        add('kimenő DOS-azonosító', d)
        m = re.match(r'KE-(\d+)\s+(\d+)$', d or '')
        if m:
            k['kimenő sorszám'].add(m.group(2))
        add('UGYF-kód (vevő adószáma)', s['dos'].get('Vevő adószáma (DOS)'), False)
    for u in j['utalasok']:
        if u['dos_utalas']:
            add(f"{u['dos_utalas']['tipus']}utalás száma", u['dos_utalas']['szam'])
            k[f"{u['dos_utalas']['tipus']}utalás száma"].add(norm(u['dos_utalas']['szam'].lstrip('0')))
    return k, nyers


def mezo_felismeres(tablak, kategoriak):
    """Minden tábla minden szöveges/numerikus mezőjére: melyik ismert kategória értékeit tartalmazza (arány)."""
    ki = []
    for leiras, sorok in tablak:
        for idx, m in enumerate(leiras['mezok']):
            if m['tipus'] not in 'CNFV':
                continue
            ertekek = [norm(s[2][idx]) for s in sorok if not s[1] and s[2][idx] not in ('', None)]
            if not ertekek:
                continue
            for kat, halmaz in kategoriak.items():
                talalt = sum(1 for v in ertekek if v in halmaz or v.lstrip('0') in halmaz)
                if talalt >= max(3, 0.2 * len(ertekek)):
                    ki.append({'tabla': leiras['rel'], 'mezo': m['nev'], 'kategoria': kat, 'talalat': talalt,
                               'kitoltott': len(ertekek), 'arany': round(talalt / len(ertekek), 3),
                               'lefedes': round(len({v for v in ertekek if v in halmaz}) / max(1, len(halmaz)), 3)})
    ki.sort(key=lambda x: (-x['arany'], -x['talalat']))
    return ki


def bajt_kereses(ut, nyers, max_minta=150):
    """Ismeretlen (nem DBF) fájlban az ismert értékek bájtjai; a találatok távolságából becsült rekordhossz."""
    adat = open(ut, 'rb').read()
    ki = {}
    for kat, minta in nyers.items():
        for kodlap in ('cp852', 'cwi2', 'ascii'):
            helyek = []
            for v in sorted(set(minta))[:max_minta]:
                try:
                    b = v.encode('ascii') if kodlap == 'ascii' else (bytes(_CWI2.index(ch) for ch in v) if kodlap == 'cwi2' else v.encode(kodlap))
                except (UnicodeEncodeError, ValueError):
                    continue
                p = adat.find(b)
                while p >= 0 and len(helyek) < 5000:
                    helyek.append(p)
                    p = adat.find(b, p + 1)
            if helyek:
                helyek.sort()
                kul = collections.Counter(b - a for a, b in zip(helyek, helyek[1:]) if b - a > 0)
                rek = None
                if kul:
                    # a leggyakoribb távolságok legnagyobb közös osztója közelíti a rekordhosszt
                    from math import gcd
                    g = 0
                    for d, _ in kul.most_common(8):
                        g = gcd(g, d)
                    rek = g if g >= 8 else kul.most_common(1)[0][0]
                ki[f'{kat} ({kodlap})'] = {'talalat': len(helyek), 'elso_helyek': helyek[:5], 'becsult_rekordhossz': rek,
                                          'rekordon_beluli_eltolas': (helyek[0] % rek) if rek else None}
                break
    return ki


# ---------------------------------------------------------------------------------------------------------------
#  Főprogram
# ---------------------------------------------------------------------------------------------------------------

def biztonsagos_kicsomagolas(zip_ut, cel):
    """ZIP kibontása a kimeneti mappába (a fájlnevek nem mutathatnak a célmappán kívülre)."""
    ki = []
    with zipfile.ZipFile(zip_ut) as z:
        for info in z.infolist():
            nev = info.filename
            if not (info.flag_bits & 0x800):
                try:
                    nev = info.filename.encode('cp437').decode('cp852')
                except (UnicodeEncodeError, UnicodeDecodeError):
                    pass
            nev = nev.replace('\\', '/')
            reszek = [r for r in nev.split('/') if r not in ('', '.', '..')]
            if not reszek or info.is_dir():
                continue
            ut = os.path.join(cel, *reszek)
            os.makedirs(os.path.dirname(ut), exist_ok=True)
            with z.open(info) as be, open(ut, 'wb') as kif:
                kif.write(be.read())
            ido = datetime.datetime(*info.date_time).timestamp()
            os.utime(ut, (ido, ido))
            ki.append(ut)
    return ki


def fajlok(gyoker):
    for mappa, almappak, nevek in os.walk(gyoker):
        almappak.sort()
        for nev in sorted(nevek):
            if nev.startswith('.') and nev in ('.DS_Store',):
                continue
            yield os.path.join(mappa, nev)


def program_jelek(ut):
    adat = open(ut, 'rb').read()
    talalt = sorted({j.decode() for j in PROGRAM_JELEK if j in adat})
    verzio = re.findall(rb'(?:Clipper|FoxPro|dBASE|Turbo Pascal|Btrieve)[^\0\r\n]{0,40}', adat)[:5]
    return talalt, [v.decode('cp852', errors='replace') for v in verzio]


def main():
    ap = argparse.ArgumentParser(description='DOS-os program mappájának felderítése (csak olvas).')
    ap.add_argument('forras')
    ap.add_argument('kimenet')
    ap.add_argument('--ismert', help='ismert_dos.json (a BaninaPRO-ban meglévő DOS-értékek)')
    ap.add_argument('--kodlap', help='kódlap kényszerítése (cp852, cwi2, cp437, cp1250, iso8859_2)')
    a = ap.parse_args()

    forras = os.path.abspath(a.forras)
    ki = os.path.abspath(a.kimenet)
    os.makedirs(os.path.join(ki, 'tablak'), exist_ok=True)
    if ki.startswith(forras + os.sep):
        sys.exit('A kimeneti mappa nem lehet a forrásmappán belül.')

    # ZIP-ek kibontása a kimeneti mappába (a forrás érintetlen marad)
    gyokerek = [forras]
    for ut in list(fajlok(forras)):
        if ut.lower().endswith('.zip'):
            cel = os.path.join(ki, 'kicsomagolt', os.path.relpath(ut, forras))
            if not os.path.isdir(cel):
                biztonsagos_kicsomagolas(ut, cel)
            gyokerek.append(cel)

    leltar = []
    tablak = []
    for gy in gyokerek:
        for ut in fajlok(gy):
            meret = os.path.getsize(ut)
            tipus = tipus_felismer(ut, meret)
            sor = {'fajl': os.path.relpath(ut, forras if gy == forras else os.path.dirname(gy)), 'meret': meret,
                   'modositva': datetime.datetime.fromtimestamp(os.path.getmtime(ut)).strftime('%Y-%m-%d %H:%M'), 'tipus': tipus}
            if tipus == 'dbf':
                try:
                    leiras, sorok = dbf_olvas(ut, a.kodlap)
                    leiras['rel'] = sor['fajl']
                    tablak.append((leiras, sorok))
                    sor.update({k: leiras[k] for k in ('verzio', 'rekord', 'torolt', 'kodlap', 'frissitve_fejlec', 'memo')})
                    if 'figyelem' in leiras:
                        sor['figyelem'] = leiras['figyelem']
                    tabla_kiir(ki, ut, forras if gy == forras else os.path.dirname(gy), leiras, sorok)
                except Exception as e:   # egy hibás tábla ne állítsa meg a felderítést
                    sor['hiba'] = f'{type(e).__name__}: {e}'
            elif tipus == 'program':
                sor['jelek'], sor['verzio_szoveg'] = program_jelek(ut)
            leltar.append(sor)

    eredmeny = {'forras': forras, 'keszult': datetime.datetime.now().isoformat(timespec='seconds'), 'fajlok': leltar}
    if a.ismert:
        kategoriak, nyers = ismert_kategoriak(a.ismert)
        eredmeny['mezo_felismeres'] = mezo_felismeres(tablak, kategoriak)
        eredmeny['bajt_kereses'] = {}
        for gy in gyokerek:
            for ut in fajlok(gy):
                if tipus_felismer(ut, os.path.getsize(ut)) in ('bináris', 'szöveg') and os.path.getsize(ut) < 200 * 1024 * 1024:
                    talalat = bajt_kereses(ut, nyers)
                    if talalat:
                        eredmeny['bajt_kereses'][os.path.relpath(ut, forras)] = talalat
    with open(os.path.join(ki, 'felderites.json'), 'w', encoding='utf-8') as f:
        json.dump(eredmeny, f, ensure_ascii=False, indent=1)
    jelentes = osszefoglalo(eredmeny, tablak)
    with open(os.path.join(ki, 'felderites.txt'), 'w', encoding='utf-8') as f:
        f.write(jelentes)
    print(jelentes)


def tabla_kiir(ki, ut, gyoker, leiras, sorok):
    rel = os.path.relpath(ut, gyoker)
    alap = os.path.join(ki, 'tablak', re.sub(r'[\\/]', '__', rel))
    with open(alap + '.csv', 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f)
        w.writerow(['_rekord', '_torolt'] + [m['nev'] for m in leiras['mezok']])
        for rek, torolt, ertekek in sorok:
            w.writerow([rek, '*' if torolt else ''] + ertekek)
    stat = []
    for idx, m in enumerate(leiras['mezok']):
        vals = [s[2][idx] for s in sorok if not s[1] and s[2][idx] not in ('', None)]
        kul = list(dict.fromkeys(vals))
        st = {'nev': m['nev'], 'tipus': m['tipus'], 'hossz': m['hossz'], 'tizedes': m['tizedes'], 'kitoltott': len(vals), 'kulonbozo': len(kul),
              'minta': kul[:6]}
        if vals and m['tipus'] in 'NFDC':
            st['min'], st['max'] = min(vals), max(vals)
        stat.append(st)
    with open(alap + '.json', 'w', encoding='utf-8') as f:
        json.dump(dict(leiras, mezok=stat), f, ensure_ascii=False, indent=1)


def osszefoglalo(e, tablak):
    s = [f"DOS-mappa felderítése: {e['forras']}  ({e['keszult']})", '']
    tip = collections.Counter(f['tipus'] for f in e['fajlok'])
    s.append('Fájlok: ' + ', '.join(f'{t} {n}' for t, n in tip.most_common()) + f"  (összesen {len(e['fajlok'])})")
    progs = [f for f in e['fajlok'] if f['tipus'] == 'program' and f.get('jelek')]
    for p in progs:
        s.append(f"  program: {p['fajl']} – {', '.join(p['jelek'])}" + (f"  [{' | '.join(p['verzio_szoveg'])}]" if p.get('verzio_szoveg') else ''))
    s.append('')
    s.append('Táblák (DBF):')
    for leiras, sorok in sorted(tablak, key=lambda t: -t[0]['rekord']):
        s.append(f"  {leiras['rel']:24} {leiras['rekord']:7} rekord ({leiras['torolt']} törölt)  {leiras['kodlap']:9} "
                 f"frissítve: {leiras['frissitve_fejlec'] or '?'}  {leiras['verzio']}" + (f"  memó: {leiras['memo']}" if leiras['memo'] else '')
                 + (f"  FIGYELEM: {leiras['figyelem']}" if leiras.get('figyelem') else ''))
        s.append('      ' + ', '.join(f"{m['nev']} {m['tipus']}{m['hossz']}" + (f".{m['tizedes']}" if m['tizedes'] else '') for m in leiras['mezok']))
    egyeb = [f for f in e['fajlok'] if f['tipus'] in ('bináris', 'szöveg') and f['meret'] > 0]
    if egyeb:
        s.append('')
        s.append('Nem DBF adatfájlok (szöveg / bináris):')
        for f in sorted(egyeb, key=lambda f: -f['meret'])[:40]:
            s.append(f"  {f['fajl']:40} {f['meret']:>10} bájt  {f['modositva']}  {f['tipus']}")
    hibas = [f for f in e['fajlok'] if 'hiba' in f]
    for f in hibas:
        s.append(f"  HIBA: {f['fajl']}: {f['hiba']}")
    if e.get('mezo_felismeres') is not None:
        s.append('')
        s.append('Mező-felismerés (a BaninaPRO-ban meglévő DOS-értékek alapján):')
        if not e['mezo_felismeres']:
            s.append('  nincs találat a DBF-táblákban')
        for m in e['mezo_felismeres'][:80]:
            s.append(f"  {m['tabla']:24} {m['mezo']:12} → {m['kategoria']:28} {m['arany'] * 100:5.1f}%  ({m['talalat']}/{m['kitoltott']}, "
                     f"az ismert értékek {m['lefedes'] * 100:.0f}%-a)")
        for fajl, t in (e.get('bajt_kereses') or {}).items():
            s.append(f"  bájtkeresés {fajl}:")
            for kat, v in t.items():
                s.append(f"      {kat:40} {v['talalat']:6} találat, becsült rekordhossz: {v['becsult_rekordhossz']}, eltolás: {v['rekordon_beluli_eltolas']}")
    s.append('')
    return '\n'.join(s)


if __name__ == '__main__':
    main()
