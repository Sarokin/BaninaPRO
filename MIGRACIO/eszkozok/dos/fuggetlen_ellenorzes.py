"""
Független ellenőrzés: a DOS nyers adatfájljaiból (saját bájtpozíciókkal, a konverter kódja nélkül)
kiszámolja, mi a helyes BaninaPRO-állapot, és soronként összeveti az adatbázisból kimentett tartalommal.
Használat: python3 fuggetlen_ellenorzes.py <ADATOK mappa> <db_ki mappa>
"""
import collections
import csv
import datetime
import re
import struct
import sys
from decimal import Decimal, ROUND_HALF_UP

ADATOK, DBKI = sys.argv[1], sys.argv[2]
TOL = datetime.date(2026, 1, 1)
import os
IG = datetime.date.fromisoformat(os.environ.get('IG', '2026-10-16'))   # a mai napig bezárólag
IB_MOD = os.environ.get('IB') == '1'   # végleges (IB-vel összefésült) adatbázis: a kimenőnél csak a DOS-mezők (fizetés)
IB_MEZO = collections.Counter()
HIBAK = collections.Counter()
PELDA = collections.defaultdict(list)


def hiba(kat, pelda):
    HIBAK[kat] += 1
    if len(PELDA[kat]) < 8:
        PELDA[kat].append(pelda)


# ---------------- nyers olvasás (saját) ----------------
def recs(nev):
    d = open(f'{ADATOK}/{nev}.DAT', 'rb').read()
    L = struct.unpack('<i', d[12:16])[0]
    for i in range(1, len(d) // L):
        r = d[i * L:(i + 1) * L]
        if r[:4] == b'\0\0\0\0':
            yield i, r


def real(b):
    if b[0] == 0:
        return Decimal(0)
    m = int.from_bytes(b[1:6], 'little')
    sgn = -1 if m >> 39 else 1
    m &= (1 << 39) - 1
    v = sgn * (1 + m / 2 ** 39) * 2.0 ** (b[0] - 129)
    return Decimal(repr(v)).quantize(Decimal('0.01'), rounding=ROUND_HALF_UP)


def dat(b):
    """3 bájtos DOS-dátum; nem létező nap (pl. 02.30) → a hónap utolsó napja (a konverter dokumentált szabálya)."""
    if b == b'\0\0\0':
        return None
    ev = 2000 + b[0] if b[0] < 80 else 1900 + b[0]
    try:
        return datetime.date(ev, b[1], b[2])
    except ValueError:
        if 1 <= b[1] <= 12 and b[2] > 28:
            return datetime.date(ev + (b[1] == 12), b[1] % 12 + 1, 1) - datetime.timedelta(days=1)
        return None


def s(r, o, n):
    return r[o + 1:o + 1 + min(r[o], n)].decode('cp437').strip()


def pkey(r, o):
    if r[o] == 0:
        return ''
    return r[o + 1:o + 3].decode('cp437').strip() + '-' + s(r, o + 3, 8)


# ---------------- DOS: mi a helyes ----------------
KEGY = {}
for i, r in recs('KEGY'):
    KEGY[s(r, 4, 6)] = {'datum': dat(r[92:95]), 'terh': dat(r[95:98]), 'osszeg': real(r[128:134]), 'partner': pkey(r, 20)}
IMUTAL = {}
for i, r in recs('IMUTAL'):
    IMUTAL[s(r, 4, 6)] = {'datum': dat(r[75:78]), 'osszeg': real(r[87:93]), 'ado': s(r, 20, 11)}


def statusz(osszeg, fiz):
    if fiz == 0:
        return 'FIZETENDO', Decimal(0)
    if fiz == osszeg:
        return ('BESZAMITVA' if osszeg < 0 else 'FIZETVE'), Decimal(0)
    if (osszeg > 0 and 0 < fiz < osszeg) or (osszeg < 0 and osszeg < fiz < 0):
        return 'FIZETENDO', fiz
    return ('BESZAMITVA' if osszeg < 0 else 'FIZETVE'), Decimal(0)


VART_B = {}          # (forrás, kulcs) -> elvárt sor
VART_F = collections.defaultdict(list)
storno_db = collections.Counter()
for i, r in recs('IMSZLA'):
    kelt = dat(r[149:152])
    if not kelt or kelt < TOL or kelt > IG:
        continue
    if r[237] == 1:
        storno_db['IMSZLA'] += 1
        continue
    osz, fiz = real(r[218:224]), real(r[176:182])
    if osz == 0 and fiz == 0:
        continue
    az = s(r, 4, 11).ljust(11)
    biz = s(r, 27, 15)
    st, reszt = statusz(osz, fiz)
    fdat = None
    utal = None
    if st in ('FIZETVE', 'BESZAMITVA'):
        fdat = dat(r[161:164])            # a DOS „Dátuma” oszlopa: a terhelés napja (üres, ha még nincs)
        utal = f'DOS devizautalás #{biz}'
    VART_B[(f'{az[0]}-{az[1:3]}-{az[3:7]}', az[7:11].strip())] = {
        'partner': 'VEVO ' + pkey(r, 43), 'penznem': s(r, 137, 3), 'kelt': kelt, 'hatarido': dat(r[152:155]) or kelt,
        'osszeg': osz, 'statusz': st, 'reszt': reszt, 'fizetve': fdat, 'utal': utal, 'beszam': s(r, 55, 10) or None,
        'szamlaszam': s(r, 16, 10), 'azon': az}
for i, r in recs('FORINT'):
    kelt = dat(r[160:163])                # a DOS forint-lista „Kelte” oszlopa
    if not kelt or kelt < TOL or kelt > IG:
        continue
    if r[198] == 1:                       # a DOS listáján „STORNO”
        storno_db['FORINT'] += 1
        continue
    osz = real(r[192:198])
    if osz == 0:
        continue
    ua = s(r, 76, 6)
    fiz = osz if ua else Decimal(0)
    st, reszt = statusz(osz, fiz)
    VART_F[f'F-{s(r, 51, 5)}'].append({
        'partner': 'UGYF ' + s(r, 20, 11), 'penznem': 'HUF', 'kelt': kelt, 'hatarido': dat(r[163:166]) or kelt, 'osszeg': osz,
        'statusz': st, 'reszt': reszt, 'fizetve': dat(r[166:169]) if ua else None, 'utal': f'DOS forintutalás #{ua}' if ua else None,
        'rek': i, 'kifiz': r[75], 'sz': s(r, 4, 15)})
# a felhasználó döntése: kifizetett számla ki nem fizetett DOS-másodpéldánya (azonos F-sorszám) nem kerül át
for fk, v in VART_F.items():
    fiz = [x for x in v if x['utal']]
    if fiz:
        VART_F[fk] = [x for x in v if x['utal'] or not (x['kifiz'] == 0 or any(f['osszeg'] == x['osszeg'] and f['sz'] == x['sz'] for f in fiz))]
UGYF_KULCSOK = set()
for i, r in recs('UGYF'):
    UGYF_KULCSOK.add(s(r, 248, 11))
VART_K = {}
for i, r in recs('IMELSZ'):
    kelt = dat(r[184:187])
    if not kelt or kelt < TOL or kelt > IG:
        continue
    if r[493] == 1:
        storno_db['IMELSZ'] += 1
        continue
    osz, bef = real(r[283:289]), real(r[289:295])
    if osz == 0:
        continue
    az = re.sub(r'\s+', ' ', s(r, 4, 12)).strip()
    ado = s(r, 29, 11)
    st, reszt = statusz(osz, bef)
    st = {'FIZETENDO': 'NYITOTT', 'BESZAMITVA': 'FIZETVE'}.get(st, st)
    fd = dat(r[199:202]) if st == 'FIZETVE' else None
    pk = pkey(r, 17)
    assert az not in VART_K
    VART_K[az] = {'partner': {'UGYF ' + ado} | ({'VEVO ' + pk} if pk else set()), 'penznem': s(r, 160, 3), 'kelt': kelt,
                  'hatarido': dat(r[196:199]) or kelt, 'osszeg': osz, 'statusz': st, 'reszt': reszt, 'fizetve': fd,
                  'befolyt': bef, 'bef_datum': dat(r[199:202]),
                  'kivonat': f"DOS {fd:%Y.%m.%d} {s(r, 160, 3)}" if fd else None}
# kimenő: azonos vevő + szám + összeg + kelt, az egyik befolyt → a befolyás nélküli másodpéldány nem kerül át
csk = collections.defaultdict(list)
for az, v in VART_K.items():
    csk[(min(v['partner']), re.sub(r'\D', '', az.split(' ', 1)[-1]) if ' ' in az else az, v['osszeg'], v['kelt'])].append(az)
for kk, azs in csk.items():
    if len(azs) > 1 and any(VART_K[a]['statusz'] == 'FIZETVE' for a in azs):
        for a in azs:
            if VART_K[a]['statusz'] != 'FIZETVE':
                del VART_K[a]
print('DOS (független) – átkerülendő: IMSZLA', len(VART_B), '| FORINT', sum(len(v) for v in VART_F.values()), '| IMELSZ', len(VART_K),
      '| STORNO kihagyva:', dict(storno_db))


# ---------------- adatbázis ----------------
def tsv(nev):
    with open(f'{DBKI}/{nev}.tsv', encoding='utf-8') as f:
        return list(csv.DictReader(f, delimiter='\t', quoting=csv.QUOTE_NONE))


def D(x):
    return None if x in ('NULL', '', None) else datetime.date.fromisoformat(x)


def N(x):
    return None if x in ('NULL', '', None) else x


CEG = {}
for c in tsv('cegek'):
    m = re.search(r'DOS-kód: ([^·]*)', c['megjegyzes'])
    kodok = {x.strip() for x in m.group(1).split(',')} if m else set()
    CEG[c['id']] = {'nev': c['nev'], 'kodok': kodok, 'ado': N(c['adoszam'])}
BEJ = tsv('bejovo')
KIM = tsv('kimeno')
print('Adatbázis: bejövő', len(BEJ), '| kimenő', len(KIM), '| cég', len(CEG))


def osszevet(kat, vart, sor, utal_mezo='banki_hivatkozas'):
    if IB_MOD and kat == 'IMELSZ':
        # az IB-re tartozó mezők (összeg, kelt, határidő, vevő) eltérése csak számlálva; a DOS-ra tartozók (fizetés) ellenőrizve
        if Decimal(sor['osszeg']) != vart['osszeg']: IB_MEZO['összeg'] += 1
        if D(sor['kelt']) != vart['kelt']: IB_MEZO['kelt'] += 1
        if D(sor['fizetesi_hatarido']) != vart['hatarido']: IB_MEZO['határidő'] += 1
        p = vart['partner'] if isinstance(vart['partner'], set) else {vart['partner']}
        if not (p & CEG[sor['ceg_id']]['kodok']): IB_MEZO['vevő (az IB szerinti cégnél)'] += 1
        # a fizetés a DOS szerint: amit a DOS teljesen kifizetettnek mutat, az kifizetett (a felhasználó szabálya: „ha ki lett fizetve,
        # ne szerepeljen nyitottként”, akkor is, ha az IB-összeg néhány centtel más); a DOS-ban részben fizetett számla akkor FIZETVE,
        # ha a DOS-befolyás az IB szerinti összeget teljesen fedezi
        if vart['statusz'] == 'FIZETVE':
            st, reszt = 'FIZETVE', Decimal(0)
        else:
            st, reszt = statusz(Decimal(sor['osszeg']), vart['befolyt'])
            st = {'FIZETENDO': 'NYITOTT', 'BESZAMITVA': 'FIZETVE'}.get(st, st)
        fd = vart['bef_datum'] if st == 'FIZETVE' else None
        vart['kivonat'] = f"DOS {fd:%Y.%m.%d} {vart['penznem']}" if fd else None
        if st != vart['statusz']:
            IB_MEZO['fizetési állapot az IB-összeg miatt más, mint a DOS-ban (a DOS-befolyás az IB-összeget teljesen fedezi)'] += 1
        if sor['statusz'] != st:
            hiba(f'{kat}: státusz', (sor['szamlaszam'], sor['statusz'], st))
        if D(sor['fizetve_datum']) != fd:
            hiba(f'{kat}: fizetés dátuma', (sor['szamlaszam'], sor['fizetve_datum'], fd))
        if Decimal(sor['reszt']) != reszt:
            hiba(f'{kat}: részteljesítés', (sor['szamlaszam'], sor['reszt'], reszt))
        if sor['penznem'] != vart['penznem']:
            hiba(f'{kat}: pénznem', (sor['szamlaszam'], sor['penznem'], vart['penznem']))
        return
    if Decimal(sor['osszeg']) != vart['osszeg']:
        hiba(f'{kat}: összeg', (sor['szamlaszam'], sor['osszeg'], vart['osszeg']))
    if sor['statusz'] != vart['statusz']:
        hiba(f'{kat}: státusz', (sor['szamlaszam'], sor['statusz'], vart['statusz']))
    if D(sor['kelt']) != vart['kelt']:
        hiba(f'{kat}: kelt', (sor['szamlaszam'], sor['kelt'], vart['kelt']))
    if D(sor['fizetesi_hatarido']) != vart['hatarido']:
        hiba(f'{kat}: határidő', (sor['szamlaszam'], sor['fizetesi_hatarido'], vart['hatarido']))
    if D(sor['fizetve_datum']) != vart['fizetve']:
        hiba(f'{kat}: fizetés dátuma', (sor['szamlaszam'], sor['fizetve_datum'], vart['fizetve']))
    if Decimal(sor['reszt']) != vart['reszt']:
        hiba(f'{kat}: részteljesítés', (sor['szamlaszam'], sor['reszt'], vart['reszt']))
    if sor['penznem'] != vart['penznem']:
        hiba(f'{kat}: pénznem', (sor['szamlaszam'], sor['penznem'], vart['penznem']))
    kodok = CEG[sor['ceg_id']]['kodok']
    p = vart['partner'] if isinstance(vart['partner'], set) else {vart['partner']}
    if not (p & kodok):
        hiba(f'{kat}: cég (a DOS-partner nincs a cég DOS-kódjai között)', (sor['szamlaszam'], p, CEG[sor['ceg_id']]['nev']))


# bejövő devizás
latott = set()
for sor in BEJ:
    m = re.search(r'DOS azonosító: ([^/·]+)/([^·\s]+)', sor['megjegyzes'])
    if not m:
        continue
    kulcs = (m.group(1).strip(), m.group(2).strip())
    if kulcs not in VART_B:
        hiba('IMSZLA: az adatbázisban van, a DOS szerint nem kellene', kulcs)
        continue
    if kulcs in latott:
        hiba('IMSZLA: kétszer az adatbázisban', kulcs)
    latott.add(kulcs)
    v = VART_B[kulcs]
    if sor['regi_kod'] != kulcs[0] or sor['regi_k'] != kulcs[1]:
        hiba('IMSZLA: régi kötésszám / régi K', (kulcs, sor['regi_kod'], sor['regi_k']))
    osszevet('IMSZLA', v, sor)
    if N(sor['banki_hivatkozas']) != v['utal']:
        hiba('IMSZLA: utalás', (kulcs, sor['banki_hivatkozas'], v['utal']))
    if N(sor['beszam']) != v['beszam']:
        hiba('IMSZLA: BESZÁM', (kulcs, sor['beszam'], v['beszam']))
    if N(sor['banki_hivatkozas']) and (sor['ustat'] != 'UTALVA' or sor['uceg'] != sor['ceg_id'] or sor['upn'] != sor['penznem']):
        hiba('IMSZLA: utalás állapota/cége/pénzneme', (kulcs, sor['ustat'], sor['uceg'], sor['ceg_id']))
for k in set(VART_B) - latott:
    hiba('IMSZLA: a DOS szerint kellene, az adatbázisban nincs', k)
# bejövő forintos (ugyanazon F-sorszámon belül multihalmaz-egyeztetés)
db_f = collections.defaultdict(list)
for sor in BEJ:
    m = re.search(r'DOS forintszámla: (F-\S+)', sor['megjegyzes'])
    if m:
        db_f[m.group(1)].append(sor)
for fk in set(VART_F) | set(db_f):
    vl, dl = list(VART_F.get(fk, [])), list(db_f.get(fk, []))
    if len(vl) != len(dl):
        hiba('FORINT: darabszám eltér', (fk, len(dl), len(vl)))
        continue
    for v in sorted(vl, key=lambda x: (x['statusz'], x['osszeg'], x['kelt'])):
        jo = next((d for d in dl if Decimal(d['osszeg']) == v['osszeg'] and d['statusz'] == v['statusz'] and D(d['kelt']) == v['kelt']), None)
        if jo is None:
            hiba('FORINT: nincs megfelelő sor', (fk, v['osszeg'], v['statusz']))
            continue
        dl.remove(jo)
        osszevet('FORINT', v, jo)
        if N(jo['banki_hivatkozas']) != v['utal']:
            hiba('FORINT: utalás', (fk, jo['banki_hivatkozas'], v['utal']))
        if jo['archiv'] != '1':
            hiba('FORINT: nem archív kötésben', fk)
# kimenő
latott = set()
for sor in KIM:
    m = re.search(r'DOS azonosító: ([^·]+?) ·', sor['megjegyzes'])
    if m is None:
        IB_MEZO['csak az IB-ben (új számla, DOS-azonosító nélkül)'] += 1
        continue
    az = m.group(1).strip()
    if az not in VART_K:
        hiba('IMELSZ: az adatbázisban van, a DOS szerint nem kellene', az)
        continue
    latott.add(az)
    v = VART_K[az]
    osszevet('IMELSZ', v, sor)
    if N(sor['azonosito']) != v['kivonat']:
        hiba('IMELSZ: bankkivonat-azonosító', (az, sor['azonosito'], v['kivonat']))
for k in set(VART_K) - latott:
    hiba('IMELSZ: a DOS szerint kellene, az adatbázisban nincs', k)

# utalások: a DOS-utalás összege = a hozzárendelt (2026-os) számlák + a 2026 előtti, át nem került számlák
ut_db = collections.defaultdict(Decimal)
for sor in BEJ:
    if N(sor['banki_hivatkozas']):
        ut_db[sor['banki_hivatkozas']] += Decimal(sor['osszeg'])
korabbi = collections.defaultdict(Decimal)
for i, r in recs('IMSZLA'):
    kelt = dat(r[149:152])
    if kelt and kelt < TOL and r[237] != 1 and real(r[176:182]) != 0:
        korabbi['DOS devizautalás #' + s(r, 27, 15)] += real(r[176:182])
for i, r in recs('FORINT'):
    kelt = dat(r[160:163])
    if kelt and kelt < TOL and r[198] != 1 and s(r, 76, 6):
        korabbi['DOS forintutalás #' + s(r, 76, 6)] += real(r[192:198])
ok_ut = 0
for h, osz in ut_db.items():
    az = h.split('#')[1]
    dos = KEGY[az]['osszeg'] if 'deviza' in h else IMUTAL[az]['osszeg']
    if osz + korabbi[h] == dos:
        ok_ut += 1
    else:
        hiba('Utalás: a számlák összege (+ 2026 előtti) ≠ DOS-utalás összege', (h, osz, korabbi[h], dos))
UT = tsv('utalasok')
if len(UT) != len(ut_db):
    hiba('Utalás: számla nélküli utalás az adatbázisban', (len(UT), len(ut_db)))
for u in UT:
    if u['statusz'] != 'UTALVA':
        hiba('Utalás: nem UTALVA', u['uid'])
    az = u['banki_hivatkozas'].split('#')[1]
    d = KEGY[az]['terh'] if 'deviza' in u['banki_hivatkozas'] else IMUTAL[az]['datum']
    if D(u['utalva_datum']) != d:
        hiba('Utalás: dátum', (u['uid'], u['utalva_datum'], d))
print(f'Utalások: {ok_ut}/{len(ut_db)} egyezik a DOS-utalással (a 2026 előtti számlákat is beszámítva)')

# cégösszevonások: több DOS-kódú cégek – csak azonos adószám vagy a jóváhagyott 8 pár
for cid, c in CEG.items():
    if len(c['kodok']) > 1:
        print('  több DOS-kódú cég:', c['nev'], '|', sorted(c['kodok']))

if IB_MEZO:
    print('IB-re tartozó kimenő mezők (az IB szerint, nem DOS-hiba):', dict(IB_MEZO))
print()
if HIBAK:
    print('ELTÉRÉSEK:')
    for k, n in HIBAK.most_common():
        print(f'  {k}: {n}', PELDA[k][:4])
else:
    print('NINCS ELTÉRÉS: minden DOS-tétel pontosan egyszer, helyes összeggel, státusszal, dátumokkal, céggel és utalással szerepel,')
    print('és az adatbázisban nincs olyan tétel, amely a DOS szerint nem kellene.')
