#!/usr/bin/env python3
"""
Teszt: köztes DOS-formátum (dos_koztes.json) VISSZAÉPÍTÉSE a BaninaPRO-ban meglévő adatokból (ismert_dos.json).
Ez az, amit a 10-06-i DOS-mappából a holnapi átalakító adna – a DOS-átvevőnek erre „minden egyezik” kell, hogy mondjon.
--delta: a holnapi helyzet szimulálása (új és megváltozott DOS-rekordok, ütközések) – a várt eredményt kiírja.

    python3 -I ir_ismertbol.py <ismert_dos.json> <kimenet.json> [--delta]
"""
import json
import re
import sys


def osszeg(s):
    """„2 159,00 EUR” → „2159.00”"""
    if s is None:
        return None
    s = re.sub(r'[A-Za-z   ]', '', s).replace(',', '.')
    return s or None


def datum(s):
    """„2026.03.26” → „2026-03-26”, „–” / „még nincs…” → None"""
    if not s:
        return None
    m = re.match(r'(\d{4})\.(\d{2})\.(\d{2})', s)
    return f'{m.group(1)}-{m.group(2)}-{m.group(3)}' if m else None


def toldalek_nelkul(sz):
    return re.sub(r' \[[^\]]+\]$', '', sz or '')


def main():
    j = json.load(open(sys.argv[1], encoding='utf-8'))
    delta = '--delta' in sys.argv
    ir = {'forras': 'visszaépítve: ' + j['adatbazis'], 'partnerek': [], 'kotesek': [], 'tetelek': [], 'forintszamlak': [], 'utalasok': [], 'kimeno': []}
    ceg_ugyf = {}
    ceg_vevo = {}
    for c in j['cegek']:
        for t, kod in c['dos_kodok']:
            ir['partnerek'].append({'tabla': t, 'kod': kod, 'nev': c['nev'], 'cim': c['dos'].get('Cím'), 'bank': c['dos'].get('Bank')})
            (ceg_ugyf if t == 'UGYF' else ceg_vevo).setdefault(c['id'], kod)
    for k in j['kotesek']:
        if k['dos_kotesszam']:
            ir['kotesek'].append({'kotesszam': k['dos_kotesszam'], 'aru': k['megnevezes']})
    for b in sorted(j['bejovo'], key=lambda b: (b['kotes_id'], b['kod'])):
        d = b['dos']
        egyeb = ' · '.join(b['egyeb'])
        hat = b['hatarido']
        m = re.search(r'(\d{4})\.(\d{2})\.(\d{2}) \(nem létező nap\)', egyeb + ' ' + d.get('FIGYELEM', ''))
        if m:
            hat = f'{m.group(1)}.{m.group(2)}.{m.group(3)}'
        if b['dos_kisszam']:
            t = {'kotesszam': b['dos_kotesszam'], 'kisszam': b['dos_kisszam'], 'szallito': d.get('Szállító DOS-kódja'), 'szamlaszam': toldalek_nelkul(b['szamlaszam']),
                 'kelt': b['kelt'], 'teljesites': b['teljesites'], 'hatarido': hat, 'osszeg': b['osszeg'], 'penznem': b['penznem'], 'beszam': b['beszam'],
                 'aru': d.get('Áru'), 'vtsz': d.get('VTSZ'), 'jarmu': d.get('Jármű/fuvarozó'), 'aruertek': osszeg(d.get('Áruérték (DOS)'))}
            if b['dos_utalas']:
                t['utalas'] = b['dos_utalas']
                f = re.search(r'átutaltként ([\d  ,]+) ', d.get('FIGYELEM', ''))
                t['atutalt'] = osszeg(f.group(1)) if f else b['osszeg']
            elif 'A DOS-ban átutalt' in d:
                m = re.match(r'([-\d  ,]+) \w+ \(DOS (deviza|forint)utalás #(\S+), ', d['A DOS-ban átutalt'])
                t['utalas'] = {'tipus': m.group(2), 'szam': m.group(3)}
                t['atutalt'] = osszeg(m.group(1))
            ir['tetelek'].append(t)
        elif 'DOS forintszámla' in d:
            ref = re.match(r'DOS forintutalás #(\S+)', d.get('Utalás', ''))
            ir['forintszamlak'].append({'azon': d['DOS forintszámla'], 'partner': ceg_ugyf.get(b['ceg_id']) or ceg_vevo.get(b['ceg_id']),
                                        'partner_tabla': 'UGYF' if b['ceg_id'] in ceg_ugyf else 'VEVO', 'szamlaszam': toldalek_nelkul(b['szamlaszam']),
                                        'megnevezes': d.get('Megnevezés'), 'netto': osszeg(d.get('Nettó')), 'afa_kulcs': 0, 'afa': osszeg(d.get('ÁFA (0%)')),
                                        'brutto': b['osszeg'], 'kelt': b['kelt'], 'teljesites': b['teljesites'], 'hatarido': hat,
                                        'bankszamla': d.get('Szállító bankszámlája'), 'utalas': {'tipus': 'forint', 'szam': ref.group(1)} if ref else None})
    for u in j['utalasok']:
        if not u['dos_utalas']:
            continue
        d = u['dos']
        x = {'tipus': u['dos_utalas']['tipus'], 'szam': u['dos_utalas']['szam'], 'penznem': u['penznem'], 'osszeg': osszeg(d.get('Összeg (DOS)')),
             'kozlemeny': d.get('Közlemény', '')}
        if x['tipus'] == 'deviza':
            x.update({'megbizas': datum(d.get('Megbízás kelte')), 'terheles': datum(d.get('Terhelés')), 'kedv_bank': d.get('Kedvezményezett bankja (törzsadat)'),
                      'bank_egyeb': d.get('Bank/egyéb (utalás)'), 'kedv_szamla': d.get('Kedvezményezett számlája'),
                      'kedvezmenyezett_megj': d.get('Kedvezményezett a DOS-ban')})
        else:
            x.update({'utalas_datum': datum(d.get('Utalás dátuma')), 'datum2': datum(d.get('2. dátum (terhelés)')), 'terhelt_szamla': d.get('Terhelt saját számla'),
                      'kedv_szamla': d.get('Kedvezményezett számlája')})
        ir['utalasok'].append(x)
    for s in j['kimeno']:
        d = s['dos']
        bef = re.match(r'([-\d  ,]+) \w+ \((\d{4}\.\d{2}\.\d{2})\)', d.get('Befolyt', ''))
        ir['kimeno'].append({'azon': s['dos_azonosito'], 'vevo': d.get('Vevő adószáma (DOS)'), 'kelt': s['kelt'], 'teljesites': s['teljesites'], 'hatarido': s['hatarido'],
                             'netto': osszeg(d.get('Nettó')), 'afa_kulcs': 0, 'afa': osszeg(d.get('ÁFA (0%)')), 'brutto': s['osszeg'], 'penznem': s['penznem'],
                             'fizmod': d.get('Fizetési mód'), 'befolyt': osszeg(bef.group(1)) if bef else None, 'befolyt_datum': datum(bef.group(2)) if bef else None,
                             'egyeb': d.get('Egyéb'), 'megrendeles': d.get('Megrendelés')})

    if delta:
        varhato = []
        vevo_kod = next(kod for c in j['cegek'] for t, kod in c['dos_kodok'] if t == 'VEVO')
        forint_ceg = next(b['ceg_id'] for b in j['bejovo'] if 'DOS forintszámla' in b['dos'] and b['ceg_id'] in ceg_ugyf)
        forint_partner = ceg_ugyf[forint_ceg]
        kimeno_vevo = next(s['dos'].get('Vevő adószáma (DOS)') for s in j['kimeno'] if s['dos'].get('Vevő adószáma (DOS)'))
        # 1. új kötés két tétellel (meglévő szállító), az egyik egy ÚJ devizautalással fizetve
        ir['tetelek'].append({'kotesszam': '0-26-9990', 'kisszam': '99A1', 'szallito': vevo_kod, 'szamlaszam': 'TESZT-001', 'kelt': '26.10.08', 'hatarido': '26.11.31',
                              'osszeg': '1234.56', 'penznem': 'EUR', 'aru': 'vegyes zöldség és gyümölcs', 'utalas': {'tipus': 'deviza', 'szam': '099001'}, 'atutalt': '1234.56'})
        ir['tetelek'].append({'kotesszam': '0-26-9990', 'kisszam': '99A2', 'szallito': vevo_kod, 'szamlaszam': 'TESZT-002', 'kelt': '26.10.09', 'hatarido': '26.11.09',
                              'osszeg': '-50.00', 'penznem': 'EUR', 'aru': 'vegyes zöldség és gyümölcs'})
        ir['utalasok'].append({'tipus': 'deviza', 'szam': '099001', 'penznem': 'EUR', 'osszeg': '1234.56', 'megbizas': '2026-10-09', 'terheles': '2026-10-10',
                               'kozlemeny': 'TESZT-001 számú devizaszámla alapján'})
        varhato.append(f'új kötés 0-26-9990 ({vevo_kod}), 2 tétel: TESZT-001 FIZETVE (új utalás #099001, 2026-10-10), TESZT-002 FIZETENDŐ, határidő 2026-11-30 + megjegyzés')
        # 2. új partner (VEVO XX-00009999) új tétellel → új cég
        ir['partnerek'].append({'tabla': 'VEVO', 'kod': 'XX-00009999', 'nev': 'Teszt Új Partner Kft', 'cim': '1111 Budapest, Teszt u. 1'})
        ir['tetelek'].append({'kotesszam': '0-26-9991', 'kisszam': '99B1', 'szallito': 'XX-00009999', 'szamlaszam': 'UJP-1', 'kelt': '2026-10-10', 'hatarido': '2026-11-10',
                              'osszeg': '500.00', 'penznem': 'EUR', 'aru': 'fuvar'})
        varhato.append('új cég „Teszt Új Partner Kft” (XX-9999) + kötés 0-26-9991 + UJP-1 FIZETENDŐ')
        # 3. egy meglévő FIZETENDŐ (utalás nélküli, BaninaPRO-ban nem módosított) tétel a DOS-ban azóta átutalva (#099002)
        fiz = next(t for t, b in zip(ir['tetelek'], [x for x in sorted(j['bejovo'], key=lambda b: (b['kotes_id'], b['kod'])) if x['dos_kisszam']])
                   if b['statusz'] == 'FIZETENDO' and not b['utalas_id'] and float(b['osszeg']) > 0 and 'utalas' not in t)
        fiz['utalas'] = {'tipus': 'deviza', 'szam': '099002'}
        fiz['atutalt'] = fiz['osszeg']
        ir['utalasok'].append({'tipus': 'deviza', 'szam': '099002', 'penznem': fiz['penznem'], 'osszeg': fiz['osszeg'], 'megbizas': '2026-10-12', 'terheles': None,
                               'kozlemeny': 'teszt'})
        varhato.append(f"{fiz['kotesszam']}/{fiz['kisszam']} FIZETENDŐ → FIZETVE (új utalás #099002, terhelés nélkül)")
        # 4. egy 00.00.00 terhelésű DOS-utalás terhelése azóta rögzítve
        pot = next(u for u in ir['utalasok'] if u['tipus'] == 'deviza' and not u.get('terheles') and u['szam'] not in ('099002',))
        pot['terheles'] = '2026-10-08'
        varhato.append(f"devizautalás #{pot['szam']}: terhelés pótolva 2026-10-08 (+ a számlák fizetési dátuma)")
        # 5. egy (BaninaPRO-ban nem módosított) FIZETENDŐ tétel összegét a DOS-ban javították
        jav = next(t for t, b in zip(ir['tetelek'], [x for x in sorted(j['bejovo'], key=lambda b: (b['kotes_id'], b['kod'])) if x['dos_kisszam']])
                   if b['statusz'] == 'FIZETENDO' and 'utalas' not in t and t is not fiz)
        jav['osszeg'] = f"{float(jav['osszeg']) + 10:.2f}"
        varhato.append(f"{jav['kotesszam']}/{jav['kisszam']}: összeg javítva a DOS szerint (+10)")
        # 6. egy UTALÁSHOZ ADVA (BaninaPRO-utalásban lévő) tétel a DOS-ban is átutalva → ütközés
        bej = [x for x in sorted(j['bejovo'], key=lambda b: (b['kotes_id'], b['kod'])) if x['dos_kisszam']]
        utk = next(t for t, b in zip(ir['tetelek'], bej) if b['statusz'] == 'UTALASHOZ_ADVA')
        utk['utalas'] = {'tipus': 'deviza', 'szam': '099002'}
        varhato.append(f"{utk['kotesszam']}/{utk['kisszam']}: ÜTKÖZÉS (BaninaPRO-ban utalásra vár, DOS-ban átutalva)")
        # 7. egy tétel eltűnt a DOS-ból
        el = ir['tetelek'].pop(5)
        varhato.append(f"{el['kotesszam']}/{el['kisszam']}: a DOS-ból eltűnt (csak jelentés)")
        # 8. új forintszámla meglévő partnernek, új forintutalással
        ir['forintszamlak'].append({'azon': 'F-99001', 'partner': forint_partner, 'partner_tabla': 'UGYF', 'szamlaszam': 'TEL-2026-10', 'megnevezes': 'telefonszámla október',
                                    'netto': '10000', 'afa_kulcs': 27, 'afa': '2700', 'brutto': '12700', 'kelt': '2026-10-05', 'hatarido': '2026-10-20',
                                    'utalas': {'tipus': 'forint', 'szam': '099003'}})
        ir['utalasok'].append({'tipus': 'forint', 'szam': '099003', 'penznem': 'HUF', 'osszeg': '12700', 'utalas_datum': '2026-10-15', 'datum2': '2026-10-15',
                               'terhelt_szamla': '11111111 (Bank)', 'kozlemeny': 'TEL-2026-10 sz. számla alapján:'})
        varhato.append(f'új forintszámla F-99001 (UGYF {forint_partner}) FIZETVE, új forintutalás #099003')
        # 9. új kimenő számlák (egy befolyással, egy anélkül) + egy meglévő NYITOTT azóta befolyt
        ir['kimeno'].append({'azon': 'KE-26 99001', 'vevo': kimeno_vevo, 'kelt': '26.10.09', 'hatarido': '26.11.08', 'netto': '800', 'afa': '0', 'brutto': '800.00', 'penznem': 'EUR',
                             'fizmod': 'ÁTUTALÁS', 'befolyt': '800.00', 'befolyt_datum': '26.10.14'})
        ir['kimeno'].append({'azon': 'KE-26 99002', 'vevo': kimeno_vevo, 'kelt': '26.10.10', 'hatarido': '26.11.09', 'brutto': '300.00', 'penznem': 'EUR', 'fizmod': 'ÁTUTALÁS'})
        ny = next(s for s in ir['kimeno'] if s['befolyt'] is None and next(x for x in j['kimeno'] if x['dos_azonosito'] == s['azon'])['statusz'] == 'NYITOTT')
        ny['befolyt'] = ny['brutto']
        ny['befolyt_datum'] = '2026-10-13'
        varhato.append(f"kimenő KE-99001 FIZETVE (bank „DOS 2026.10.14 EUR”), KE-99002 NYITOTT; {ny['azon']} NYITOTT → FIZETVE (2026-10-13)")
        print('Várható:\n  ' + '\n  '.join(varhato))
    json.dump(ir, open(sys.argv[2], 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f"{sys.argv[2]}: {len(ir['partnerek'])} partner, {len(ir['kotesek'])} kötés, {len(ir['tetelek'])} tétel, {len(ir['forintszamlak'])} forintszámla, "
          f"{len(ir['utalasok'])} utalás, {len(ir['kimeno'])} kimenő")


if __name__ == '__main__':
    main()
