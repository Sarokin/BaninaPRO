#!/usr/bin/env python3
"""
DOS (KULKER / IMPORT.EXE) adatfájlok → köztes JSON (dos_koztes.json) a php/dos_atvetel.php-hez.

Az eredeti, 2026.10.07-i DOS-import (dos/baninapro_export.py) szabályaival számol: ugyanaz a rekordválogatás (időszak
a kelt szerint; STORNO, 0 összegű rekord és kifizetett számla ki nem fizetett másodpéldánya nélkül), ugyanaz a
cégösszevonás (adószám + a kézi lista), ugyanazok a számlaszámok, állapotok és megjegyzések. A köztes rekordok ezért
a 10-07-i import kiszámolt értékeit is viszik (állapot, fizetési dátum, részteljesítés, megjegyzés, a cég adatai):
a DOS-átvétel ezekkel hozza létre az új rekordokat, így azok pontosan olyanok, mintha 10-07-én jöttek volna át.
A 10-07 óta nem változott DOS-rekordokra a DOS-átvétel „egyezik”-et mond.

    python3 -I -B dos_koztes.py --adatok <…/Adatok> --ki <dos_koztes.json> [--tol 2026-01-01] [--ig 2026-10-16]
                                [--osszevonasok <ceg_osszevonasok.txt>] [--elozo <a 10-07-i DOS-állapot köztes fájlja>]
"""
import argparse
import collections
import datetime
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'dos'))

from baninapro_export import (Export, nev_kulcs, nem_letezo_datumok, osszevonasok_beolvas, szamla_bankkal,  # noqa: E402
                              tisztit, d2)


def dt(r, mezo):
    """A DOS-dátum a köztes fájlba: ISO, vagy a nem létező nap DOS-beli alakja (2026.04.31), vagy None."""
    if r.get(mezo + '_dos'):
        return r[mezo + '_dos']
    v = r.get(mezo)
    return v.isoformat() if v else None


def iso(d):
    return d.isoformat() if d else None


def osz(x):
    return None if x is None else str(x)


def reszt(s):
    """A 10-07-i import részteljesítése: (összeg, dátum, banki azonosító) → dict"""
    if not s.get('reszt'):
        return None
    o, d, az = s['reszt']
    return {'osszeg': osz(o), 'datum': iso(d), 'azonosito': az}


def main():
    ap = argparse.ArgumentParser(description='DOS (KULKER) → köztes JSON a DOS-átvételhez')
    ap.add_argument('--adatok', required=True, help='a DOS program ADATOK mappája (F:\\KULKER\\ADATOK másolata)')
    ap.add_argument('--ki', required=True, help='a köztes JSON fájl')
    ap.add_argument('--tol', default='2026-01-01', help='ettől a keltezéstől (ÉÉÉÉ-HH-NN, beleértve)')
    ap.add_argument('--ig', default=datetime.date.today().isoformat(), help='eddig a keltezésig (ÉÉÉÉ-HH-NN, beleértve)')
    ap.add_argument('--osszevonasok', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'ceg_osszevonasok.txt'),
                    help='kézi cégösszevonások (cél partnerkód | összevonandó partnerkód | név)')
    ap.add_argument('--elozo', help='a korábbi DOS-állapot köztes fájlja (a 10-07-i importé): a DOS-ban azóta átnevezett rekordok párosításához')
    a = ap.parse_args()
    tol, ig = datetime.date.fromisoformat(a.tol), datetime.date.fromisoformat(a.ig)

    ex = Export(a.adatok, tol, ig, osszevonasok_beolvas(a.osszevonasok))
    ex.futtat()
    P = ex.P

    # ---- partnerek: minden DOS-entitás, a 10-07-i cégösszevonás csoportjával és a cég kiszámolt adataival
    partnerek = []
    for ek, e in sorted(P.entitasok.items()):
        c = P.ceg(ek)
        csoport = sorted({kod for x in c['entitasok'] for kod in P.entitasok[x]['kodok']})
        partnerek.append({
            'tabla': 'VEVO' if e['tipus'] == 'V' else 'UGYF', 'kod': e['kulcs'], 'nev': e['nev'],
            'cim': e['cim'] or None, 'bank': e['bank'] or None, 'adoszam': (e['adoszamok'] or [None])[0],
            'csoport': csoport,                       # a cég összes DOS-kódja („VEVO NL-00001234”, „UGYF 12345678901”)
            'ceg': {'nev': c['nev'], 'adoszam': c['adoszam'], 'partnerkod': c['partnerkod'], 'megjegyzes': c['megjegyzes']},
        })

    # ---- kötések (a DOS-kötésszám → a 10-07-i import megnevezése)
    kotesek = [{'kotesszam': k['regi_kod'], 'aru': k['megnevezes']} for k in ex.kotesek if k['regi_kod']]

    # ---- bejövő tételek és forintszámlák a 10-07-i import sorrendjében (kötés, azon belül kelt / K / rekord)
    def kotes_rend(s):
        k = s['kotes']
        return (k['ev'], k['penznem'], k['rend'], nev_kulcs(k['ceg']['nev']), k['id'])
    tetelek, forintszamlak = [], []
    for s in sorted(ex.bejovo, key=lambda s: (kotes_rend(s), s['k_sorszam'])):
        r = s['r']
        u = s.get('utalas')
        kozos = {
            'kelt': dt(r, 'kelt'), 'hatarido': dt(r, 'hatarido'), 'penznem': s['penznem'], 'osszeg': osz(s['osszeg']),
            'statusz': s['statusz'], 'fizetve_datum': iso(s['fizetve']), 'reszt': reszt(s), 'megjegyzes': s['megjegyzes'],
            'datum_megj': nem_letezo_datumok(r), 'dos_rekord': r['_rekord'],
            'kotes_ev': s['kotes']['ev'], 'kotes_megnevezes': s['kotes']['megnevezes'],
        }
        if s['forras'] == 'IMSZLA':
            alap = tisztit(r['szamlaszam']) or f"DOS {s['dos_kotes'] or ''}/{s['regi_k'] or ''}".strip()
            b = r['bizonylat'].strip()
            ref = {'tipus': 'deviza', 'szam': b} if b and s['fizetes'] != 'nyitott' else None
            t = {'kotesszam': s['dos_kotes'] or '–', 'kisszam': s['regi_k'] or '–', 'szallito': r['partner'], 'szamlaszam': alap,
                 'teljesites': None, 'beszam': s['beszam'], 'aru': tisztit(r['aru']) or None,
                 'vtsz': tisztit(r['vtsz']) if tisztit(r['vtsz']) not in ('', '1', '1111111') else None,
                 'jarmu': tisztit(r['jarmu']) or None,
                 'aruertek': osz(d2(r['aruertek'])) if d2(r['aruertek']) != s['osszeg'] else None,
                 'utalas': ref, 'atutalt': osz(s['atutalt']) if s['atutalt'] else None,
                 'utalas_kapcsolva': u is not None}
            t.update(kozos)
            tetelek.append(t)
        else:
            sorsz = r['sorszam'].strip()
            az = r['utalas_azon'].strip()
            t = {'azon': f'F-{sorsz}', 'partner': r['adoszam'].strip(), 'partner_tabla': 'UGYF',
                 'szamlaszam': tisztit(r['szamlaszam']) or f'DOS F-{sorsz}', 'teljesites': dt(r, 'teljesites'),
                 'megnevezes': ' '.join(x for x in (tisztit(r['megnevezes']), tisztit(r['megnevezes2'])) if x) or None,
                 'netto': osz(d2(r['netto'])), 'afa_kulcs': r['afa_kulcs'], 'afa': osz(d2(r['afa'])), 'brutto': osz(s['osszeg']),
                 'bankszamla': szamla_bankkal(r['partner_bankszamla']) or None, 'dos_kotes': tisztit(r['kotes']) or None,
                 'kifizetheto': r['kifizetheto'], 'utalas': {'tipus': 'forint', 'szam': az} if az and s['fizetes'] == 'fizetett' else None,
                 'utalas_kapcsolva': u is not None, 'kotes_archiv': True}
            t.update(kozos)
            forintszamlak.append(t)

    # ---- utalások (a 10-07-i import sorrendjében: dátum, pénznem, forrás, azonosító)
    utalasok = []
    for u in ex.utalasok:
        k = u['k']
        x = {'tipus': 'deviza' if u['forras'] == 'KEGY' else 'forint', 'szam': k['azon'].strip(), 'penznem': u['penznem'],
             'osszeg': osz(u['dos_osszeg']), 'utalva': iso(u['utalva']), 'datum': iso(u['datum']), 'hivatkozas': u['hivatkozas'],
             'megjegyzes': u['megjegyzes'], 'sorrend': u['id']}
        if u['forras'] == 'KEGY':
            x.update({'megbizas': dt(k, 'datum'), 'terheles': dt(k, 'terheles'),
                      'kozlemeny': ' '.join(tisztit(k[f'szoveg{j}']) for j in range(1, 9) if tisztit(k[f'szoveg{j}']))})
        else:
            x.update({'utalas_datum': dt(k, 'datum'), 'datum2': dt(k, 'datum2'), 'kozlemeny': tisztit(k['kozlemeny']),
                      'terhelt_szamla': szamla_bankkal(k['sajat_bankszamla']) or None,
                      'kedv_szamla': szamla_bankkal(k['partner_bankszamla']) or None})
        utalasok.append(x)

    # ---- kimenő számlák (a 10-07-i import sorrendjében)
    kimeno = []
    for s in sorted(ex.kimeno_lista, key=lambda s: s['id']):
        r = s['r']
        e = P.entitasok[s['ek']]
        alap = s['szamlaszam']
        if alap.endswith(']') and ' [' in alap:          # a 10-07-i import toldaléka (ismétlődő számlaszám) – az alap számlaszám kell
            alap = alap[:alap.rindex(' [')]
        kimeno.append({
            'azon': s['dos_azon'], 'vevo': r['adoszam'].strip(), 'vevo_tabla': 'VEVO' if e['tipus'] == 'V' else 'UGYF', 'vevo_kod': e['kulcs'],
            'szamlaszam': alap, 'kelt': dt(r, 'kelt'), 'teljesites': dt(r, 'teljesites'), 'hatarido': dt(r, 'hatarido'),
            'netto': osz(d2(r['netto'])), 'afa_kulcs': r['afa_kulcs'], 'afa': osz(d2(r['afa'])), 'brutto': osz(s['osszeg']),
            'penznem': s['penznem'], 'fizmod': tisztit(r['fizmod']) or None,
            'befolyt': osz(s['befolyt']) if s['befolyt'] else None, 'befolyt_datum': dt(r, 'befolyas_datum'),
            'egyeb': tisztit(r['egyeb']) or None, 'megrendeles': tisztit(r['megrendeles']) or None,
            'statusz': s['statusz'], 'fizetve_datum': iso(s['fizetve']), 'reszt': reszt(s), 'megjegyzes': s['megjegyzes'],
            'kivonat': s['kivonat']['azonosito'] if s['kivonat'] else None, 'dos_rekord': r['_rekord'],
        })

    # ---- a DOS-ban azóta átnevezett rekordok (pl. „K1-26 1234” → „KE-26 1234”): a korábbi DOS-állapot (--elozo) ugyanazon
    # rekordja (azonos fájlbeli sorszám, partner, összeg és kelt), amelynek a kulcsa azóta eltűnt – a DOS-átvétel ezen párosítja
    atnevezve = []
    if a.elozo:
        with open(a.elozo, encoding='utf-8') as f:
            el = json.load(f)
        for lista, kulcs, partner, osszeg in (('kimeno', lambda k: ' '.join(k['azon'].split()), 'vevo', 'brutto'),
                                              ('tetelek', lambda k: f"{k['kotesszam']}/{k['kisszam']}", 'szallito', 'osszeg'),
                                              ('forintszamlak', lambda k: k['azon'], 'partner', 'brutto')):
            uj_lista = {'kimeno': kimeno, 'tetelek': tetelek, 'forintszamlak': forintszamlak}[lista]
            regi_rek = {k['dos_rekord']: k for k in el[lista]}
            regi_kulcsok = {kulcs(k) for k in el[lista]}
            uj_kulcsok = {kulcs(k) for k in uj_lista}
            for k in uj_lista:
                r = regi_rek.get(k['dos_rekord'])
                if (r is not None and kulcs(k) not in regi_kulcsok and kulcs(r) not in uj_kulcsok and r[partner] == k[partner]
                        and r[osszeg] == k[osszeg] and r['kelt'] == k['kelt']):
                    k['elozo_kulcs'] = kulcs(r)
                    atnevezve.append(f'{lista}: {kulcs(r)} → {kulcs(k)}')

    ki = {
        'forras': f'DOS ADATOK mappa ({os.path.abspath(a.adatok)}), időszak {tol} – {ig}, készült {datetime.datetime.now():%Y-%m-%d %H:%M}',
        'tol': tol.isoformat(), 'ig': ig.isoformat(),
        'partnerek': partnerek, 'kotesek': kotesek, 'tetelek': tetelek, 'forintszamlak': forintszamlak,
        'utalasok': utalasok, 'kimeno': kimeno,
        'jelentes': {
            'storno': [f'{f}: {le}' for f, le in ex.storno_lista],
            'atnevezve': atnevezve,
            'masodpeldany': ex.masodpeldany_lista,
            'figyelmeztetes': ex.figyelmeztetesek,
            'eszrevetel': {k: n for k, n in sorted(ex.megjegyzesek.items())},
            'hasonlo_nevek': [[{'nev': c['nev'], 'partnerkod': c['partnerkod'], 'adoszam': c['adoszam']} for c in v] for v in P.hasonlo_nevek],
        },
    }
    with open(a.ki, 'w', encoding='utf-8') as f:
        json.dump(ki, f, ensure_ascii=False, indent=1)
    db = collections.Counter(t['statusz'] for t in tetelek + forintszamlak)
    print(f"{a.ki}: {len(partnerek)} partner, {len(kotesek)} kötés, {len(tetelek)} tétel + {len(forintszamlak)} forintszámla "
          f"({dict(db)}), {len(utalasok)} utalás, {len(kimeno)} kimenő" + (f"; a DOS-ban átnevezve: {'; '.join(atnevezve)}" if atnevezve else ''))


if __name__ == '__main__':
    main()
