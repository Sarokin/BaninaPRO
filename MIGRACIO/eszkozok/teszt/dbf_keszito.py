#!/usr/bin/env python3
"""
Teszt: mesterséges DOS-mappa a felderítő kipróbálásához, VALÓDI értékekkel (ismert_dos.json-ból).
dBase III + .DBT memó (CP852), FoxPro 2.x + .FPT memó, CWI-2 kódlapú tábla, Clipper hosszú C mező, törölt rekord,
nem létező dátum (26.04.31), üres dátum (00.00.00), egy almappa ZIP-ben, egy ismeretlen fix rekordhosszú bináris fájl.

    python3 -I dbf_keszito.py <ismert_dos.json> <célmappa>
"""
import json
import os
import random
import re
import struct
import sys
import zipfile

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
from dos_felderito import _CWI2  # noqa: E402

CWI2_KOD = {ch: i for i, ch in enumerate(_CWI2)}


def kodol(s, kodlap):
    if kodlap == 'cwi2':
        return bytes(CWI2_KOD.get(ch, 0x3F) for ch in s)
    return s.encode(kodlap, errors='replace')


def dbf_ir(ut, verzio, mezok, sorok, kodlap='cp852', nyelv=0x64, torolt=(), memo_tipus=None):
    """mezok: [(név, típus, hossz, tizedes)]; sorok: értéklisták (str). memo_tipus: 'dbt' | 'fpt' | None"""
    rekhossz = 1 + sum(m[2] for m in mezok)
    fejhossz = 32 + 32 * len(mezok) + 1
    fej = bytearray(32)
    struct.pack_into('<B3BIHH', fej, 0, verzio, 126, 10, 15, len(sorok), fejhossz, rekhossz)
    fej[29] = nyelv
    for nev, tipus, hossz, tiz in mezok:
        d = bytearray(32)
        d[:len(nev)] = nev.encode('ascii')
        d[11] = ord(tipus)
        if tipus == 'C' and hossz > 255:      # Clipper: a hosszú C mező hossza a tizedesek bájtjával együtt
            d[16], d[17] = hossz & 0xFF, hossz >> 8
        else:
            d[16], d[17] = hossz, tiz
        fej += d
    fej += b'\x0d'
    memo = bytearray(512)
    blokk = 512 if memo_tipus == 'dbt' else 64
    if memo_tipus == 'fpt':
        memo = bytearray(512)
    kov = len(memo) // blokk
    rekordok = bytearray()
    for i, sor in enumerate(sorok):
        r = bytearray(b'*' if i in torolt else b' ')
        for (nev, tipus, hossz, tiz), v in zip(mezok, sor):
            if tipus == 'C':
                r += kodol(v, kodlap)[:hossz].ljust(hossz, b' ')
            elif tipus == 'N':
                r += v.encode('ascii').rjust(hossz, b' ')
            elif tipus == 'D':
                r += (v.encode('ascii') if v else b' ' * 8)
            elif tipus == 'M':
                if not v:
                    r += b' ' * 10
                    continue
                adat = kodol(v, kodlap)
                if memo_tipus == 'dbt':
                    blokkok = adat + b'\x1a\x1a'
                else:
                    blokkok = struct.pack('>II', 1, len(adat)) + adat
                blokkok = blokkok.ljust(-(-len(blokkok) // blokk) * blokk, b'\0')
                r += str(kov).encode('ascii').rjust(10, b' ')
                memo += blokkok
                kov += len(blokkok) // blokk
        rekordok += r
    with open(ut, 'wb') as f:
        f.write(bytes(fej) + bytes(rekordok) + b'\x1a')
    if memo_tipus == 'dbt':
        struct.pack_into('<I', memo, 0, kov)
        open(os.path.splitext(ut)[0] + '.DBT', 'wb').write(bytes(memo))
    elif memo_tipus == 'fpt':
        struct.pack_into('>I', memo, 0, kov)
        struct.pack_into('>H', memo, 6, blokk)
        open(os.path.splitext(ut)[0] + '.FPT', 'wb').write(bytes(memo))


def dos_datum(d):
    return f'{d[2:4]}.{d[5:7]}.{d[8:10]}' if d else '00.00.00'


def main():
    j = json.load(open(sys.argv[1], encoding='utf-8'))
    cel = sys.argv[2]
    os.makedirs(os.path.join(cel, 'ADAT'), exist_ok=True)
    random.seed(7)

    # VEVO: szállítók (CP852, dBase III)
    vevo = []
    for c in j['cegek']:
        for t, kod in c['dos_kodok']:
            if t == 'VEVO':
                vevo.append([kod, c['nev'], c['dos'].get('Cím', ''), c['dos'].get('Bank', '')])
    dbf_ir(os.path.join(cel, 'ADAT', 'VEVO.DBF'), 0x03, [('KOD', 'C', 11, 0), ('NEV', 'C', 40, 0), ('CIM', 'C', 60, 0), ('BANK', 'C', 60, 0)], vevo)

    # UGYF: vevők adószám szerint (CWI-2, nyelvi bájt nélkül)
    ugyf = []
    for c in j['cegek']:
        for t, kod in c['dos_kodok']:
            if t == 'UGYF':
                ugyf.append([kod, c['nev'], c['dos'].get('Cím', '')])
    dbf_ir(os.path.join(cel, 'ADAT', 'UGYF.DBF'), 0x03, [('ADOSZAM', 'C', 13, 0), ('NEV', 'C', 40, 0), ('CIM', 'C', 60, 0)], ugyf,
           kodlap='cwi2', nyelv=0)

    # KOTES (dBase III + DBT memó)
    kot = [[k['dos_kotesszam'], k['megnevezes'] or '', 'DOS megjegyzés: árú átvéve, minőség rendben' if i % 50 == 0 else '']
           for i, k in enumerate(j['kotesek']) if k['dos_kotesszam']]
    dbf_ir(os.path.join(cel, 'ADAT', 'KOTES.DBF'), 0x83, [('KSZAM', 'C', 9, 0), ('ARU', 'C', 30, 0), ('MEGJ', 'M', 10, 0)], kot, memo_tipus='dbt')

    # TETEL (FoxPro 2.x + FPT memó), dátumok YY.MM.DD szövegként, köztük nem létező nap és 00.00.00
    tet = []
    for b in j['bejovo']:
        if not b['dos_kisszam']:
            continue
        hat = dos_datum(b['hatarido'])
        if 'nem létező nap' in ' '.join(b['egyeb']):
            m = re.search(r'(\d{4})\.(\d{2})\.(\d{2}) \(nem létező nap\)', ' '.join(b['egyeb']))
            if m:
                hat = f'{m.group(1)[2:]}.{m.group(2)}.{m.group(3)}'
        tet.append([b['dos_kotesszam'], b['dos_kisszam'], b['dos'].get('Szállító DOS-kódja', ''), b['szamlaszam'], dos_datum(b['kelt']), hat,
                    b['osszeg'], b['penznem'], (b['dos_utalas'] or {}).get('szam', ''), b['beszam'] or '', ('FoxPro memó ő ű Ő Ű – ' + b['kod']) if len(tet) % 100 == 0 else ''])
    dbf_ir(os.path.join(cel, 'ADAT', 'TETEL.DBF'), 0xF5,
           [('KSZAM', 'C', 9, 0), ('KISSZAM', 'C', 4, 0), ('SZALLITO', 'C', 11, 0), ('SZLASZAM', 'C', 20, 0), ('KELT', 'C', 8, 0),
            ('HATARIDO', 'C', 8, 0), ('OSSZEG', 'N', 12, 2), ('DEVIZA', 'C', 3, 0), ('UTALAS', 'C', 6, 0), ('BESZAM', 'C', 10, 0), ('MEGJ', 'M', 10, 0)],
           tet, memo_tipus='fpt', torolt={3, 17})

    # KIMENO (Clipper: 300 hosszú C mező)
    kim = [[s['dos_azonosito'], s['dos'].get('Vevő adószáma (DOS)', ''), dos_datum(s['kelt']), s['osszeg'], 'x' * 10 + ' ő ű Ő Ű'] for s in j['kimeno']]
    dbf_ir(os.path.join(cel, 'ADAT', 'KIMENO.DBF'), 0x03,
           [('AZON', 'C', 12, 0), ('ADOSZAM', 'C', 13, 0), ('KELT', 'C', 8, 0), ('BRUTTO', 'N', 12, 2), ('SZOVEG', 'C', 300, 0)], kim)

    # ismeretlen bináris: 64 bájtos rekordok, az utalásszámmal a 10. bájttól
    bin_ = bytearray()
    for u in j['utalasok']:
        if u['dos_utalas']:
            r = bytearray(64)
            r[10:16] = u['dos_utalas']['szam'].encode('ascii')[:6]
            r[20:28] = struct.pack('<d', float(u['szamla_ossz']))
            bin_ += r
    open(os.path.join(cel, 'ADAT', 'DEVIZA.DAT'), 'wb').write(bytes(bin_))

    # egy almappa ZIP-ben (a felderítő kibontja)
    with zipfile.ZipFile(os.path.join(cel, 'REGI_EV.zip'), 'w') as z:
        z.write(os.path.join(cel, 'ADAT', 'VEVO.DBF'), 'REGI/VEVO.DBF')
    open(os.path.join(cel, 'START.BAT'), 'w').write('@echo off\r\ncd \\BANINA\\ADAT\r\nBANINA.EXE\r\n')
    open(os.path.join(cel, 'BANINA.EXE'), 'wb').write(b'MZ' + b'\0' * 100 + b'CA-Clipper 5.2e (c) Computer Associates' + b'\0' * 50)
    print('kész:', cel)


if __name__ == '__main__':
    main()
