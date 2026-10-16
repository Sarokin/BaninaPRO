#!/usr/bin/env python3
"""
DOS (KULKER / IMPORT.EXE) számlaadatok → BaninaPRO import SQL (phpMyAdminhoz).

Használat:
  python3 baninapro_export.py --adatok "<…/kulker/Adatok>" --ki "<kimeneti mappa>" [--tol 2026-01-01] [--ig 2026-10-06]

Forrás (a hálózati meghajtó F:\\KULKER\\ADATOK mappája):
  IMSZLA  bejövő devizás számlák     → bejovo_szamlak (+ kotesek)
  FORINT  bejövő forintos számlák    → bejovo_szamlak (archív kötésekben)
  KEGY    devizás utalások           → utalasok (a hivatkozott IMSZLA számlákkal)
  IMUTAL  forintos utalások          → utalasok (a hivatkozott FORINT számlákkal)
  IMELSZ  kimenő számlák             → kimeno_szamlak (+ banki_kivonatok a befolyásokhoz)
  VEVO    külföldi partnerek (kulcs: országkód + kód)  → cegek
  UGYF    ügyfelek (kulcs: adószám)                    → cegek

A BaninaPRO sémáját (sql/schema.sql) és a program saját Excel-importjának
szabályait követi: kódformátumok, archív kötések, BESZÁMÍTVA a negatív
összegekre, részteljesítések, változásnapló, sorszám-generátor.

A DOS a mérvadó: pontosan azok a tételek kerülnek át, amelyeket a DOS a listáiban mutat
(a STORNO-jelzésűek nem), a DOS összegével és fizetési állapotával. A DOS listáiban látható
„Összesen” = a BaninaPRO „teljes”, az „Átutalva” = „fizetett”, a különbségük = „nyitott”.
"""
import argparse
import collections
import datetime
import os
import re
import unicodedata
from decimal import Decimal

from dos_olvaso import tabla, kerekit

SOR_PER_INSERT = 500


# ---------------------------------------------------------------------------
#  Segédfüggvények
# ---------------------------------------------------------------------------
def norm_azonosito(s: str) -> str:
    """A BaninaPRO norm_azonosito() megfelelője: nagybetű, csak betű és szám."""
    s = s.strip().upper()
    return ''.join(ch for ch in s if ch.isalpha() or ch.isnumeric())


def nev_kulcs(s: str) -> str:
    """Cégösszevonási kulcs: ékezet, kis-/nagybetű, írásjel és szóköz nélkül."""
    s = unicodedata.normalize('NFKD', s)
    s = ''.join(c for c in s if not unicodedata.combining(c)).casefold()
    return re.sub(r'[^0-9a-z]+', '', s)


def ci_kulcs(s: str) -> str:
    """Az adatbázis (utf8mb4_unicode_ci) egyezésvizsgálatának megfelelő kulcs: kis/nagybetű és ékezet nélkül."""
    s = unicodedata.normalize('NFKD', s or '')
    return ''.join(c for c in s if not unicodedata.combining(c)).casefold().rstrip()


def tisztit(s: str) -> str:
    return re.sub(r'\s+', ' ', s or '').strip()


def vag(s, n):
    if s is None:
        return None
    s = tisztit(s)
    return s if len(s) <= n else s[:n - 1].rstrip() + '…'


def d2(x: float) -> Decimal:
    return kerekit(x, 2)


def osszeg_hu(d: Decimal) -> str:
    """12248.88 → '12 248,88'"""
    neg = d < 0
    s = f'{abs(d):,.2f}'.replace(',', ' ').replace('.', ',')
    return ('-' if neg else '') + s


def datum_hu(d) -> str:
    return d.strftime('%Y.%m.%d') if d else '–'


DATUM_MEZONEV = {'hatarido': 'fizetési határidő', 'befolyas_datum': 'befolyás dátuma', 'kelt': 'kelt', 'teljesites': 'teljesítés',
                 'utalas_datum': 'utalás dátuma', 'terheles_datum': 'terhelés dátuma'}


def nem_letezo_datumok(r):
    """Megjegyzés a DOS-ban begépelt, nem létező napokról (pl. 2026.02.30 → a hónap utolsó napja)."""
    return [f"A {DATUM_MEZONEV.get(m[:-4], m[:-4])} a DOS-ban {v} (nem létező nap) – a hónap utolsó napja került be"
            for m, v in r.items() if m.endswith('_dos') and m[:-4] in DATUM_MEZONEV]


def sql(v) -> str:
    if v is None:
        return 'NULL'
    if isinstance(v, bool):
        return '1' if v else '0'
    if isinstance(v, (int, Decimal)):
        return str(v)
    if isinstance(v, datetime.date):
        return "'" + v.isoformat() + "'"
    s = str(v).replace('\\', '\\\\').replace("'", "''")
    return "'" + s + "'"


EU_ORSZAGKODOK = {'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT',
                  'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI', 'GB', 'CH', 'NO', 'RS', 'TR'}


HU_TERULETI_KODOK = {f'{i:02d}' for i in list(range(2, 21)) + list(range(22, 45)) + [51]}


def hu_ellenorzo_ok(t: str) -> bool:
    """11 jegy, a törzsszám 8. jegye ellenőrző szám (súlyok 9,7,3,1,9,7,3), az ÁFA-kód 1–5."""
    if not re.fullmatch(r'\d{11}', t):
        return False
    osszeg = sum(int(d) * w for d, w in zip(t[:7], (9, 7, 3, 1, 9, 7, 3)))
    return (10 - osszeg % 10) % 10 == int(t[7]) and t[8] in '12345'


def hu_adoszam_ervenyes(t: str) -> bool:
    """Érvényes magyar adószám: helyes ellenőrző szám és létező területi kód (02–20, 22–44, 51)."""
    return hu_ellenorzo_ok(t) and t[9:] in HU_TERULETI_KODOK


def adoszam_fmt(s: str, orszag: str = 'HU'):
    """Adószám egységes alakra: magyar → 12345678-1-23; külföldi → országkód + szám; más → None."""
    t = re.sub(r'[\s.\-/]', '', (s or '').upper())
    t = re.sub(r'^(VAT|ADOSZ|ADÓSZ)\W*', '', t)
    if t.startswith('HU') and hu_adoszam_ervenyes(t[2:]):
        t = t[2:]
    if hu_adoszam_ervenyes(t) and orszag in ('', 'HU'):
        return f'{t[:8]}-{t[8]}-{t[9:]}'
    if hu_ellenorzo_ok(t) and orszag in ('', 'HU'):
        return t          # adószámnak tűnik, de a területi kód nem létezik: a DOS-beli érték, formázás nélkül
    if re.fullmatch(r'\d{8,12}', t) and orszag in EU_ORSZAGKODOK and orszag != 'HU':
        return orszag + t
    if t.startswith('DEDE'):
        t = t[2:]
    if re.fullmatch(r'HU\d{8}', t):
        return t
    if t[:2] in EU_ORSZAGKODOK and t[:2] != 'HU' and re.fullmatch(r'[A-Z]{2}[0-9A-Z]{8,12}', t) and re.search(r'\d{6}', t):
        return t
    return None


def ado_kulcs(a: str):
    """Adószám összevetési kulcsa: magyar → 'HU' + törzsszám (8 jegy); uniós → országkód + szám (NL: a B01 nélkül)."""
    t = re.sub(r'[^0-9A-Z]', '', (a or '').upper())
    if hu_adoszam_ervenyes(t):
        return 'HU' + t[:8]
    if re.fullmatch(r'HU\d{8}', t):
        return t
    m = re.fullmatch(r'NL(\d{9})B\d{2}', t)
    if m:
        return 'NL' + m.group(1)
    if t[:2] in EU_ORSZAGKODOK and re.fullmatch(r'[A-Z]{2}[0-9A-Z]{6,14}', t) and re.search(r'\d{6}', t):
        return t
    return None


def ado_szamtorzs(a: str):
    """Adószám számjegy-törzse (a leghosszabb, legalább 8 jegyű számsor első 8 jegye):
    'NL123456789B01' és a csonkolt '123456789B0' ugyanazt adja."""
    sorok = re.findall(r'\d+', a or '')
    hosszu = max(sorok, key=len) if sorok else ''
    return hosszu[:8] if len(hosszu) >= 8 else None


def hu_adoszam_mag(s: str):
    """Magyar adószám törzsszáma (első 8 jegy) bármilyen szövegből, pl. 'asz,12345676-2-41' → '12345676'."""
    m = re.search(r'(?<!\d)(\d{8})-?\d-?\d{2}(?!\d)', s or '')
    return m.group(1) if m else None


def nev_alap(nev: str) -> str:
    """A névvégi pénznem-jelölés nélkül (a DOS-ban külön „… EUR” partnert vettek fel az EUR-os számlákhoz)."""
    return re.sub(r'[\s,./-]+(EUR|HUF|EURO|DEVIZA|FT)\.?\s*$', '', tisztit(nev), flags=re.I)


_CEGFORMA = (r'(b\.?\s?v\.?(\s?i\.?\s?o\.?)?|n\.?\s?v\.?|gmbh(\s?(&|und)?\s?co\.?\s?kg)?|co\.?\s?kg|kg|ohg|ag|kft\.?|zrt\.?|nyrt\.?|rt\.?|bt\.?|e\.?\s?v\.?|'
             r's\.?\s?r\.?\s?l\.?|s\.?\s?a\.?|s\.?\s?p\.?\s?a\.?|s\.?\s?l\.?\s?u\.?|s\.?\s?l\.?|scs|d\.?\s?o\.?\s?o\.?|s\.?\s?r\.?\s?o\.?|ltd\.?|limited|'
             r'sp\.?\s?z\.?\s?o\.?\s?o\.?|eood|s\.?\s?c\.?\s?a\.?|coop|inc\.?|llc)')


def nev_cegforma_nelkul(nev: str) -> str:
    """A név a végén álló cégforma(ák) és országkód nélkül: 'Minta Holland BV' → 'Minta Holland'."""
    n = nev_alap(nev)
    while True:
        uj = re.sub(r'[\s,.]+(' + _CEGFORMA + r'|nl|hu|de|it|es|ro|at)[\s,.]*$', '', n, flags=re.I)
        if uj == n or not uj.strip():
            return n
        n = uj


BANKOK = {'100': 'Magyar Államkincstár', '101': 'Budapest Bank', '103': 'MKB Bank', '104': 'K&H Bank',
          '107': 'CIB Bank', '109': 'UniCredit Bank', '116': 'Erste Bank', '117': 'OTP Bank',
          '120': 'Raiffeisen Bank', '137': 'ING Bank'}


def bank_nev(szamla: str) -> str:
    t = re.sub(r'\D', '', szamla or '')
    if len(t) >= 8 and t[:3] in BANKOK:
        return BANKOK[t[:3]]
    return ''


def szamla_bankkal(szamla: str) -> str:
    szamla = tisztit(szamla)
    if not szamla or szamla in ('0', '.'):
        return ''
    b = bank_nev(szamla)
    return f'{szamla} ({b})' if b else szamla


# ---------------------------------------------------------------------------
#  Cégek (partnerek)
# ---------------------------------------------------------------------------
class Partnerek:
    """A DOS-partnerek (VEVO / UGYF) → BaninaPRO cégek (névegyezés szerint összevonva)."""

    def __init__(self, vevo, ugyf, export_datum):
        self.vevo = {f"{r['orszag'].strip()}-{r['kod'].strip()}": r for r in vevo}
        self.ugyf = collections.defaultdict(list)
        for r in ugyf:
            self.ugyf[r['adoszam'].strip()].append(r)
        self.export_datum = export_datum
        self.kezi = []                 # kézi összevonások: (cél partnerkód, összevonandó partnerkód, név)
        self.kezi_eredmeny = []        # jelentéshez
        self.entitasok = {}            # ('V'|'U', kulcs) → entitás dict
        self.cegek = None              # összevonás után: lista
        self.ent_ceg = {}              # entitás-kulcs → cég dict

    @staticmethod
    def sajat_pk(e):
        """Az entitás saját partnerkódja: szállító (VEVO) → NL-1238; ügyfél (UGYF) → U-<kulcs>."""
        if e['tipus'] == 'V' and '-' in e['kulcs']:
            orsz, kod = e['kulcs'].split('-', 1)
            return f"{orsz}-{int(kod)}" if kod.isdigit() else e['kulcs']
        return 'U-' + (tisztit(e['kulcs']) or '?')

    @staticmethod
    def _ervenyes(kulcs):
        return len(re.sub(r'[^0-9A-Za-z]', '', kulcs)) >= 6

    def vevo_partner(self, kulcs):
        ek = ('V', kulcs)
        if ek not in self.entitasok:
            r = self.vevo.get(kulcs)
            if r is None:
                e = {'nev': f'Törölt DOS-partner ({kulcs})', 'helyorzo': 'torolt', 'kodok': [f'VEVO {kulcs}'],
                     'adoszamok': [], 'cim': '', 'bank': '', 'egyeb': 'A partnert a DOS programban törölték, a nevét nem lehetett visszaállítani – nevezd át, ha tudod, ki volt.'}
            else:
                cim = ', '.join(x for x in (tisztit(r['cim1']), tisztit(r['cim2']), tisztit(r['cim3'])) if x and x not in ('0', '.'))
                bank = ', '.join(x for x in (tisztit(r['bank']), tisztit(r['bankszamla'])) if x and x not in ('0', '.'))
                orszag = tisztit(r['orszag']).upper()
                ado = [a for a in (adoszam_fmt(r['bankszamla'], orszag), adoszam_fmt(r['bank'], orszag), adoszam_fmt(r['egyeb1'], orszag)) if a]
                m = re.search(r'(?<![A-Z0-9])([A-Z]{2})\s*(\d{8,12}(?:B\d{2})?)(?![0-9])', (r['bankszamla'] + ' ' + r['bank']).upper())
                if m and not ado and 'IBAN' not in (r['bankszamla'] + r['bank']).upper()[max(0, m.start() - 6):m.start()]:
                    ado.append(m.group(1) + m.group(2))
                mag = hu_adoszam_mag(r['bankszamla'] + ' ' + r['bank'] + ' ' + r['egyeb1']) if orszag == 'HU' else None
                e = {'nev': tisztit(r['nev']) or f'DOS-partner {kulcs}', 'helyorzo': None, 'kodok': [f'VEVO {kulcs}'],
                     'adoszamok': ado, 'cim': cim, 'bank': bank, 'egyeb': '', 'hu_mag': mag}
            e['tipus'] = 'V'
            e['kulcs'] = kulcs
            e['hivatkozas'] = 0
            self.entitasok[ek] = e
        self.entitasok[ek]['hivatkozas'] += 1
        return ek

    def ugyf_partner(self, kulcs):
        kulcs = kulcs.strip()
        ek = ('U', kulcs)
        if ek not in self.entitasok:
            recs = self.ugyf.get(kulcs, [])
            if not recs:
                e = {'nev': f'Ismeretlen DOS-ügyfél (adószám: {kulcs or "üres"})', 'helyorzo': 'ismeretlen', 'kodok': [f'UGYF {kulcs}'],
                     'adoszamok': [], 'cim': '', 'bank': '', 'egyeb': 'Az ügyfél nem szerepel a DOS ügyféltörzsben.'}
            elif len(recs) > 1 and not self._ervenyes(kulcs) and len({nev_kulcs(r['nev']) for r in recs}) > 1:
                nevek = ' / '.join(tisztit(r['nev']) for r in recs)
                e = {'nev': f'Nem azonosítható DOS-ügyfél (adószám: {kulcs or "üres"})', 'helyorzo': 'nem_egyertelmu',
                     'kodok': [f'UGYF {kulcs}'], 'adoszamok': [], 'cim': '', 'bank': '',
                     'egyeb': f'A DOS-ban több ügyfél is ezt az adószám-mezőt használja: {nevek}. Helyezd át a számlákat a megfelelő céghez.'}
            else:
                nevek = collections.Counter(tisztit(r['nev']) for r in recs if tisztit(r['nev']))
                # több rekord ugyanazzal az adószámmal = ugyanaz a cég; a leghosszabb (legteljesebb) név marad
                nev = max(nevek, key=lambda n: (len(n), n)) if nevek else f'DOS-ügyfél {kulcs}'
                r = recs[-1]
                cim = ', '.join(x for x in (tisztit(r['irsz']), tisztit(r['varos']), tisztit(r['cim'])) if x and x not in ('0', '.'))
                orszag = tisztit(r['irsz']).upper() if re.fullmatch(r'[A-Za-z]{2}', tisztit(r['irsz'])) else 'HU'
                ado = [a for a in (adoszam_fmt(r['eu_adoszam'], orszag), adoszam_fmt(kulcs, orszag)) if a]
                mag = hu_adoszam_mag(kulcs) if orszag == 'HU' and re.fullmatch(r'\d{11}', kulcs) else None
                e = {'nev': nev, 'helyorzo': None, 'kodok': [f'UGYF {kulcs}'], 'adoszamok': ado, 'cim': cim, 'hu_mag': mag,
                     'ado_szoveg': [kulcs] + [tisztit(x['eu_adoszam']) for x in recs if tisztit(x['eu_adoszam'])],
                     'bank': tisztit(r['bankszamla']), 'egyeb': ('Névváltozatok a DOS-ban: ' + ' / '.join(sorted(nevek))) if len(nevek) > 1 else ''}
            e['tipus'] = 'U'
            e['kulcs'] = kulcs
            e['hivatkozas'] = 0
            self.entitasok[ek] = e
        self.entitasok[ek]['hivatkozas'] += 1
        return ek

    def _kezi_entitas(self, pk):
        """Kézi összevonás tagja a DOS-törzsből akkor is, ha az időszakban nincs tétele (adószám, cím, bank miatt)."""
        if pk.startswith('U-'):
            kulcs = pk[2:]
            if kulcs in self.ugyf:
                self.entitasok[self.ugyf_partner(kulcs)]['hivatkozas'] -= 1
        elif '-' in pk:
            orsz, kod = pk.split('-', 1)
            kulcs = f'{orsz}-{int(kod):08d}' if kod.isdigit() else pk
            if kulcs in self.vevo:
                self.entitasok[self.vevo_partner(kulcs)]['hivatkozas'] -= 1

    def osszevon(self):
        """Entitások → cégek (azonos nevűek összevonva), azonosító a név szerinti sorrendben."""
        jelen = {ci_kulcs(self.sajat_pk(e)) for e in self.entitasok.values()}
        for cel_pk, forras_pk, _ in self.kezi:
            if ci_kulcs(cel_pk) in jelen or ci_kulcs(forras_pk) in jelen:
                for pk in (cel_pk, forras_pk):
                    if ci_kulcs(pk) not in jelen:
                        self._kezi_entitas(pk)
        # Összevonás (a felhasználó szabálya): azonos adószám → egy cég; eltérő adószám → külön cég.
        # Azonos nevű partnerek (kis/nagybetű, ékezet, szóköz nem számít) csak akkor kerülnek össze,
        # ha nincs köztük eltérő adószám. A csak hasonló nevek külön cégek maradnak (a jelentés felsorolja).
        szulo = {}

        def gyoker(x):
            while szulo[x] != x:
                szulo[x] = szulo[szulo[x]]
                x = szulo[x]
            return x

        def egyesit(a, b):
            szulo[gyoker(a)] = gyoker(b)
        adok = {}
        for ek, e in sorted(self.entitasok.items()):
            szulo[ek] = ek
            szovegek = list(e['adoszamok']) + list(e.get('ado_szoveg', []))
            k = {ado_szamtorzs(a) for a in szovegek} | ({e['hu_mag']} if e.get('hu_mag') else set())
            adok[ek] = {x for x in k if x}
        elso = {}
        for ek in sorted(self.entitasok):
            for a in adok[ek]:
                if a in elso:
                    egyesit(ek, elso[a])
                else:
                    elso[a] = ek
        nevcsop = collections.defaultdict(set)
        for ek, e in self.entitasok.items():
            nevcsop[ci_kulcs(tisztit(e['nev'])) or f'#{ek}'].add(gyoker(ek))
        for gyokerek in nevcsop.values():
            komp_ado = collections.defaultdict(set)
            for ek in self.entitasok:
                if gyoker(ek) in gyokerek:
                    komp_ado[gyoker(ek)] |= adok[ek]
            kulonbozo = set().union(*komp_ado.values()) if komp_ado else set()
            if len(gyokerek) > 1 and len(kulonbozo) <= 1:
                lista = sorted(gyokerek)
                for g in lista[1:]:
                    egyesit(g, lista[0])
        # kézi összevonások (ceg_osszevonasok.txt) – a partnerkód alapján
        pk_ent = {ci_kulcs(self.sajat_pk(e)): ek for ek, e in self.entitasok.items()}
        kezi_csop = []
        for cel_pk, forras_pk, nev in self.kezi:
            a, b = pk_ent.get(ci_kulcs(cel_pk)), pk_ent.get(ci_kulcs(forras_pk))
            if a is not None and b is not None:
                egyesit(b, a)
            if a is not None or b is not None:
                kezi_csop.append((a if a is not None else b, nev, cel_pk))
            self.kezi_eredmeny.append((cel_pk, forras_pk, nev, a is not None, b is not None))
        csoport = collections.OrderedDict()
        for ek, e in sorted(self.entitasok.items()):
            csoport.setdefault(gyoker(ek), []).append((ek, e))
        felulir = {gyoker(x): (nev, cel_pk) for x, nev, cel_pk in kezi_csop}
        cegek = []
        for k, tagok in csoport.items():
            # megjelenő név: a legtöbbet hivatkozott entitásé
            fo = max(tagok, key=lambda t: (nev_alap(t[1]['nev']) == tisztit(t[1]['nev']), t[1]['hivatkozas'], len(t[1]['nev'])))[1]
            ado = None
            for _, e in tagok:
                for a in e['adoszamok']:
                    ado = ado or a
            reszek = [f'DOS import ({datum_hu(self.export_datum)})']
            reszek.append('DOS-kód: ' + ', '.join(sorted({c for _, e in tagok for c in e['kodok']})))
            for _, e in tagok:
                if e['cim']:
                    reszek.append('Cím: ' + e['cim'])
                    break
            bankok = sorted({e['bank'] for _, e in tagok if e['bank']})
            if bankok:
                reszek.append('Bank: ' + ' / '.join(bankok))
            for _, e in tagok:
                if e['egyeb']:
                    reszek.append(e['egyeb'])
            # Partnerkód („régi rendszer”): a DOS-kód, előtaggal, hogy ne ütközzön a könyvelési kódokkal.
            # Szállítói (VEVO) kód: NL-1238; ügyfél (UGYF): U- + a DOS ügyfélkulcsa (többnyire az adószám).
            elso_tag = sorted(tagok, key=lambda t: (t[1]['tipus'] != 'V', -t[1]['hivatkozas']))[0][1]
            pk = self.sajat_pk(elso_tag)
            nev = fo['nev']
            if k in felulir:
                nev, cel_pk = felulir[k]
                pk = cel_pk          # a kézi összevonás célkódja akkor is, ha a cél partnernek nincs tétele az időszakban
                reszek.append('Kézi összevonás: ' + ', '.join(f"„{tisztit(e['nev'])}” (P{self.sajat_pk(e)})" for _, e in tagok))
            c = {'nev': vag(nev, 191), 'adoszam': ado, 'megjegyzes': ' · '.join(reszek), 'partnerkod': vag(pk, 32) if pk else None,
                 'helyorzo': fo['helyorzo'], 'entitasok': [ek for ek, _ in tagok], 'kezi': k in felulir}
            cegek.append(c)
        pk_lattam = set()
        for c in cegek:
            if c['partnerkod'] and ci_kulcs(c['partnerkod']) in pk_lattam:
                c['partnerkod'] = None
            elif c['partnerkod']:
                pk_lattam.add(ci_kulcs(c['partnerkod']))
        nevek = collections.defaultdict(list)
        for c in cegek:
            nevek[ci_kulcs(c['nev'])].append(c)
        for v in nevek.values():
            if len(v) > 1:
                for c in v:
                    jel = c['adoszam'] or ', '.join(sorted({k for ek in c['entitasok'] for k in self.entitasok[ek]['kodok']}))
                    c['nev'] = vag(f"{c['nev']} ({jel})", 191)
        cegek.sort(key=lambda c: (nev_kulcs(c['nev']), c['nev']))
        hasonlo = collections.defaultdict(list)
        for c in cegek:
            hasonlo[nev_kulcs(nev_cegforma_nelkul(c['nev']))].append(c)
        self.hasonlo_nevek = [v for k, v in hasonlo.items() if len(v) > 1 and len(k) >= 3]
        for i, c in enumerate(cegek, 1):
            c['id'] = i
            for ek in c['entitasok']:
                self.ent_ceg[ek] = c
        self.cegek = cegek

    def ceg(self, ek):
        return self.ent_ceg[ek]


# ---------------------------------------------------------------------------
#  Fő feldolgozás
# ---------------------------------------------------------------------------
class Export:
    def __init__(self, adatok, tol, ig, osszevonasok=()):
        self.tol, self.ig = tol, ig
        self.megjegyzesek = collections.Counter()      # jelentéshez: kihagyások, rendellenességek
        self.peldak = collections.defaultdict(list)
        self.storno_lista = []                          # (forrás, leírás) – a kihagyott STORNO-tételek
        self.figyelmeztetesek = []                      # a felhasználónak külön jelzendő DOS-adathibák
        self.masodpeldany_lista = []                    # kifizetett számla ki nem fizetett DOS-másodpéldánya – nem kerül át
        print('DOS táblák olvasása…')
        self.T = {n: tabla(adatok, n) for n in ('VEVO', 'UGYF', 'IMSZLA', 'FORINT', 'IMUTAL', 'KEGY', 'IMELSZ')}
        for n, v in self.T.items():
            print(f'  {n}: {len(v)} rekord')
        self.P = Partnerek(self.T['VEVO'], self.T['UGYF'], ig)
        self.P.kezi = list(osszevonasok)

    def esemeny(self, kulcs, pelda=None, max_pelda=15):
        self.megjegyzesek[kulcs] += 1
        if pelda is not None and len(self.peldak[kulcs]) < max_pelda:
            self.peldak[kulcs].append(pelda)

    def storno_esemeny(self, forras, leiras):
        """A DOS-ban STORNO-jelzésű tétel: nem kerül át."""
        self.esemeny(f'{forras}: a DOS-ban STORNO-jelzésű tétel, kihagyva')
        self.storno_lista.append((forras, leiras))

    def idoszakban(self, d):
        return d is not None and self.tol <= d <= self.ig

    @staticmethod
    def fizetes_tipus(osszeg, atutalt):
        """A DOS „Összesen” és „Átutalva” értéke alapján: nyitott / fizetett / reszben / tulfizetett."""
        if atutalt == 0:
            return 'nyitott'
        if atutalt == osszeg:
            return 'fizetett'
        if (osszeg > 0 and 0 < atutalt < osszeg) or (osszeg < 0 and osszeg < atutalt < 0):
            return 'reszben'
        return 'tulfizetett'

    # ---------------- bejövő devizás számlák (IMSZLA) ----------------
    def bejovo_deviza(self):
        """Összeg = a DOS „Összesen” oszlopa, kifizetés = az „Átutalva” oszlop (lásd dos_olvaso.TABLAK['IMSZLA'])."""
        ki = []
        for r in self.T['IMSZLA']:
            if not self.idoszakban(r['kelt']):
                if r['kelt'] is None:
                    self.esemeny('IMSZLA: kelt (számladátum) nélküli rekord a teljes DOS-adatbázisban – időszakba nem sorolható, kihagyva (zömmel üres sablonrekord)')
                continue
            dos_kotes = self.dos_kotes(r['azon'].ljust(11)[:7])
            if r['storno'] == 1:
                self.storno_esemeny('IMSZLA', f"{dos_kotes or '–'} / {r['azon'][7:].strip()} „{tisztit(r['szamlaszam'])}” {r['partner']} "
                                              f"{r['kelt']} {d2(r['osszesen'])} {tisztit(r['deviza'])}")
                continue
            if any(abs(r[m]) >= 0.005 for m in ('fuvarktg', 'r188', 'r224')):
                raise RuntimeError(f"IMSZLA {r['azon']}: fuvarköltséges számla – ennek a DOS-szabálya nincs ellenőrizve, a konverter megáll")
            osszeg, atutalt = d2(r['osszesen']), d2(r['atutalt'])
            if osszeg == 0 and atutalt == 0:
                self.esemeny('IMSZLA: 0 összegű rekord, kihagyva (nincs pénzügyi hatása)', f"{r['azon']} „{tisztit(r['szamlaszam'])}” {r['partner']} {r['kelt']}", 10 ** 6)
                continue
            if osszeg == 0:
                raise RuntimeError(f"IMSZLA {r['azon']}: 0 összegű, de átutalt ({atutalt}) számla – ellenőrizendő")
            deviza = r['deviza'].strip().upper()
            if deviza not in ('EUR', 'HUF'):
                raise RuntimeError(f"IMSZLA {r['azon']}: a BaninaPRO-ban nem kezelt deviza „{deviza}” – ellenőrizendő")
            s = {'forras': 'IMSZLA', 'r': r, 'ek': self.P.vevo_partner(r['partner']), 'penznem': deviza, 'osszeg': osszeg,
                 'atutalt': atutalt, 'fizetes': self.fizetes_tipus(osszeg, atutalt), 'dos_kotes': dos_kotes,
                 'regi_k': r['azon'][7:].strip() or None, 'dos_azon': r['azon'].strip()}
            if s['fizetes'] in ('reszben', 'tulfizetett'):
                self.figyelmeztetesek.append(
                    f"Bejövő {dos_kotes} / {s['regi_k']} „{tisztit(r['szamlaszam'])}” ({r['partner']}): a számla {osszeg_hu(osszeg)} {deviza}, "
                    f"a DOS-ban átutaltként {osszeg_hu(atutalt)} {deviza} szerepel (DOS-utalás #{tisztit(r['bizonylat']) or '–'})"
                    + (' – a BaninaPRO-ban is részben fizetett, a különbözet nyitott, ahogy a DOS mutatja' if s['fizetes'] == 'reszben'
                       else ' – a BaninaPRO-ban FIZETVE (túlfizetést nem tud tárolni)'))
            ki.append(s)
        return ki

    @staticmethod
    def dos_kotes(kot7):
        s = kot7.rstrip()
        if not s.strip() or set(s.strip()) <= {'0'}:
            return None
        a, bb, cccc = kot7[0].strip(), kot7[1:3].strip(), kot7[3:7].strip()
        return '-'.join([a, bb, cccc]).strip('-')

    # ---------------- bejövő forintos számlák (FORINT) ----------------
    def bejovo_forint(self):
        """Fizetve: van forintutalás-azonosító (a DOS ezt írja be az utaláskor). STORNO: a 198. bájt (a DOS listáján
        „STORNO”). A „Kifizethető: nem” számla a DOS listáján normál, át nem utalt sor – ezért átkerül, jelölve."""
        ki = []
        for r in self.T['FORINT']:
            if not self.idoszakban(r['kelt']):
                if r['kelt'] is None:
                    self.esemeny('FORINT: kelt (számladátum) nélküli rekord a teljes DOS-adatbázisban – időszakba nem sorolható, kihagyva (zömmel üres sablonrekord)')
                continue
            sorsz = r['sorszam'].strip()
            if r['storno'] == 1:
                self.storno_esemeny('FORINT', f"F-{sorsz} „{tisztit(r['szamlaszam'])}” adószám {tisztit(r['adoszam'])} {r['kelt']} {d2(r['brutto'])} HUF"
                                              + (f" (DOS-forintutalás #{r['utalas_azon'].strip()})" if r['utalas_azon'].strip() else ''))
                continue
            osszeg = d2(r['brutto'])
            if osszeg == 0:
                self.esemeny('FORINT: 0 összegű rekord, kihagyva (nincs pénzügyi hatása)', f"F-{sorsz} „{tisztit(r['szamlaszam'])}” adószám {tisztit(r['adoszam'])} {r['kelt']}", 10 ** 6)
                continue
            atutalt = osszeg if r['utalas_azon'].strip() else Decimal(0)
            ki.append({'forras': 'FORINT', 'r': r, 'ek': self.P.ugyf_partner(r['adoszam']), 'penznem': 'HUF', 'osszeg': osszeg,
                       'atutalt': atutalt, 'fizetes': self.fizetes_tipus(osszeg, atutalt), 'dos_kotes': None,
                       'regi_k': f'F-{sorsz}' if sorsz else None, 'dos_azon': f"F-{sorsz} (#{r['_rekord']})"})
        # Ugyanaz a bizonylat többször (azonos partner + F-sorszám): ha az egyik példányt kifizették, a számla ki van
        # fizetve – a ki nem fizetett „Kifizethető: NEM” példány, illetve az azonos számú és összegű másodpéldány nem
        # kerül át nyitottként (a felhasználó döntése, 2026-10-07)
        csop = collections.defaultdict(list)
        for s in ki:
            if s['regi_k']:
                csop[(s['r']['adoszam'].strip(), s['regi_k'])].append(s)
        kihagy = set()
        for (ado, regi), v in csop.items():
            fizetett = [s for s in v if s['atutalt']]
            for s in v:
                if s['atutalt'] or not fizetett:
                    continue
                if s['r']['kifizetheto'] == 0 or any(f['osszeg'] == s['osszeg'] and tisztit(f['r']['szamlaszam']) == tisztit(s['r']['szamlaszam'])
                                                     for f in fizetett):
                    kihagy.add(id(s))
                    f = fizetett[0]
                    self.masodpeldany_lista.append(
                        f"Forintszámla {regi} „{tisztit(s['r']['szamlaszam'])}” {osszeg_hu(s['osszeg'])} Ft (adószám {ado})"
                        + (', „Kifizethető: NEM”' if s['r']['kifizetheto'] == 0 else '')
                        + f" – kifizetett példánya: {osszeg_hu(f['osszeg'])} Ft, DOS-forintutalás #{f['r']['utalas_azon'].strip()}")
        for s in ki:
            if s['r']['kifizetheto'] == 0 and id(s) not in kihagy:
                self.figyelmeztetesek.append(
                    f"Forintszámla {s['regi_k']} „{tisztit(s['r']['szamlaszam'])}” {osszeg_hu(s['osszeg'])} Ft: a DOS-ban „Kifizethető: NEM”, "
                    f"kifizetett párja nincs – FIZETENDŐként került át (megjegyzéssel)")
        return [s for s in ki if id(s) not in kihagy]

    # ---------------- utalások összekapcsolása ----------------
    def kegy_kapcsolas(self, szamlak):
        """DOS-devizautalás (KEGY) ↔ számla: a számla „Bizonylat” mezőjébe a DOS a fizető utalás azonosítóját írja."""
        kegy = {k['azon'].strip(): k for k in self.T['KEGY']}
        csop = collections.OrderedDict()
        for s in szamlak:
            if s['forras'] != 'IMSZLA' or s['fizetes'] not in ('fizetett', 'tulfizetett'):
                continue
            b = s['r']['bizonylat'].strip()
            if not b:
                self.esemeny('IMSZLA: átutaltként jelölt számla DOS-utalás (bizonylat) nélkül', s['dos_azon'])
                continue
            if b not in kegy:
                raise RuntimeError(f"IMSZLA {s['dos_azon']}: a hivatkozott DOS-devizautalás (#{b}) nem található")
            csop.setdefault(b, []).append(s)
        # a 2026 előtti (nem átkerülő) számlák az utalásban – a megjegyzéshez és az ellenőrzéshez
        korabbi = collections.defaultdict(lambda: [0, Decimal(0)])
        for r in self.T['IMSZLA']:
            b = r['bizonylat'].strip()
            if b in csop and r['storno'] != 1 and r['kelt'] and r['kelt'] < self.tol and d2(r['atutalt']) != 0:
                korabbi[b][0] += 1
                korabbi[b][1] += d2(r['atutalt'])
        return [{'forras': 'KEGY', 'k': kegy[b], 'szamlak': v, 'korabbi': tuple(korabbi[b])} for b, v in csop.items()]

    def imutal_kapcsolas(self, szamlak):
        """DOS-forintutalás (IMUTAL) ↔ forintszámla: a számla utalás-azonosító mezője."""
        im = {r['azon'].strip(): r for r in self.T['IMUTAL']}
        csop = collections.OrderedDict()
        for s in szamlak:
            if s['forras'] != 'FORINT' or s['fizetes'] != 'fizetett':
                continue
            az = s['r']['utalas_azon'].strip()
            if az not in im:
                raise RuntimeError(f"FORINT {s['dos_azon']}: a hivatkozott DOS-forintutalás (#{az}) nem található")
            csop.setdefault(az, []).append(s)
        korabbi = collections.defaultdict(lambda: [0, Decimal(0)])
        for r in self.T['FORINT']:
            az = r['utalas_azon'].strip()
            if az in csop and r['storno'] != 1 and r['kelt'] and r['kelt'] < self.tol:
                korabbi[az][0] += 1
                korabbi[az][1] += d2(r['brutto'])
        return [{'forras': 'IMUTAL', 'k': im[az], 'szamlak': v, 'korabbi': tuple(korabbi[az])} for az, v in csop.items()]

    # ---------------- kimenő számlák (IMELSZ) ----------------
    def kimeno(self):
        ki = []
        for r in self.T['IMELSZ']:
            if not self.idoszakban(r['kelt']):
                if r['kelt'] is None:
                    self.esemeny('IMELSZ: kelt (számladátum) nélküli rekord a teljes DOS-adatbázisban – időszakba nem sorolható, kihagyva (zömmel üres sablonrekord)')
                continue
            azon = r['azon'].ljust(12)
            if r['storno'] == 1:
                self.storno_esemeny('IMELSZ', f"„{tisztit(azon)}” adószám {tisztit(r['adoszam'])} {r['kelt']} {d2(r['brutto'])} {tisztit(r['deviza'])}")
                continue
            elotag, szam = azon[:7], azon[7:].strip().strip('.,;:*/ ')
            m = re.fullmatch(r'\s*([A-Za-z]{1,2})\W*(\d{2})?\W*', elotag)
            if m:
                betuk = m.group(1).upper()
                # a számlaszám éve a kelt éve: a DOS-azonosító két jegye gyakran elírás (pl. a nap: „KE-23  123,” kelte
                # 2026.01.23; „KE-59  4567”) – a DOS-beli azonosító a megjegyzésben változatlanul látszik
                ev = r['kelt'].year
                if m.group(2) and 2000 + int(m.group(2)) != ev:
                    self.esemeny('IMELSZ: a DOS-azonosító évszáma eltér a kelt évétől (a számlaszám a kelt évével készült)',
                                 f"„{tisztit(azon)}” kelte {r['kelt']}", 100)
                szamlaszam = f'{betuk}-{szam}/{ev}'
                sorozat = betuk
            else:
                szamlaszam = f"{szam}/{r['kelt'].year}"
                sorozat = ''
                self.esemeny('IMELSZ: sorozatjel nélküli számlaszám', f"{azon.strip()} {r['kelt']}")
            deviza = r['deviza'].strip().upper()
            eredeti = None
            if deviza in ('EUR', 'HUF'):
                penznem = deviza
            else:
                penznem = 'HUF' if sorozat == 'KH' else 'EUR'
                eredeti = f"a DOS-ban a deviza mező „{deviza}” volt – a {sorozat or '?'} sorozat alapján {penznem}"
                self.esemeny(f'IMELSZ: deviza „{deviza}” → {penznem}', f"{azon.strip()} {r['brutto']}")
            adoszam = r['adoszam'].strip()
            if adoszam in self.P.ugyf or not r['partner']:
                ek = self.P.ugyf_partner(adoszam)
            else:
                ek = self.P.vevo_partner(r['partner'])
            if d2(r['brutto']) == 0:
                # a DOS-ban javításkor a régi rekord 0 összeggel megmarad, a valódi számla új (pl. vesszős) azonosítót kap
                self.esemeny('IMELSZ: 0 összegű (felülírt/érvénytelen) rekord, kihagyva (nincs pénzügyi hatása)', f"„{tisztit(azon)}” adószám {adoszam} {r['kelt']}", 10 ** 6)
                continue
            ki.append({'r': r, 'ek': ek, 'penznem': penznem, 'osszeg': d2(r['brutto']), 'befolyt': d2(r['befolyt']),
                       'szamlaszam': szamlaszam, 'eredeti': eredeti, 'dos_azon': tisztit(azon)})
        # ugyanaz a számla kétszer a DOS-ban (azonos szám, vevő, összeg, kelt): ha az egyik befolyt, a számla ki van
        # fizetve – a befolyás nélküli másodpéldány nem kerül át nyitottként (a felhasználó döntése, 2026-10-07)
        csop = collections.defaultdict(list)
        for s in ki:
            csop[(norm_azonosito(s['szamlaszam']), s['ek'], s['osszeg'], s['r']['kelt'])].append(s)
        kihagy = set()
        for v in csop.values():
            if len(v) > 1:
                befolyt = [s for s in v if s['befolyt']]
                for s in v:
                    if befolyt and not s['befolyt']:
                        kihagy.add(id(s))
                        self.masodpeldany_lista.append(f"Kimenő „{s['dos_azon']}” {osszeg_hu(s['osszeg'])} {s['penznem']} (adószám "
                                                       f"{tisztit(s['r']['adoszam'])}) – befolyt példánya: „{befolyt[0]['dos_azon']}”")
                if not befolyt:
                    self.figyelmeztetesek.append('Kimenő számla többször rögzítve a DOS-ban, egyik sem befolyt: '
                                                 + '; '.join(f"„{s['dos_azon']}”" for s in v) + ' – mind átkerült')
        return [s for s in ki if id(s) not in kihagy]

    # ---------------------------------------------------------------------------
    def futtat(self):
        bejovo = self.bejovo_deviza() + self.bejovo_forint()
        kimeno = self.kimeno()
        utal = self.kegy_kapcsolas(bejovo) + self.imutal_kapcsolas(bejovo)
        # az utalás kedvezményezettje is a partner hivatkozása (a cég megjelenő nevének kiválasztásához, mint eddig)
        for u in utal:
            if u['forras'] == 'KEGY':
                self.P.vevo_partner(u['k']['partner'])
            else:
                self.P.ugyf_partner(u['k']['adoszam'])
        self.P.osszevon()
        self.cegek = self.P.cegek
        self.kotesek_epit(bejovo)
        self.utalasok_epit(utal)
        self.bejovo_statusz(bejovo)
        self.bejovo_sorrend(bejovo)
        self.kimeno_epit(kimeno)
        self.bejovo, self.kimeno_lista = bejovo, kimeno

    # ---------------- kötések ----------------
    def kotesek_epit(self, bejovo):
        csop = collections.OrderedDict()
        for s in bejovo:
            c = self.P.ceg(s['ek'])
            s['ceg'] = c
            r = s['r']
            if s['dos_kotes']:
                kulcs = ('K', s['dos_kotes'], c['id'], s['penznem'])
            else:
                kulcs = ('A', c['id'], s['penznem'], r['kelt'].year)
            csop.setdefault(kulcs, []).append(s)
        kotesek = []
        for kulcs, sz in csop.items():
            keltek = [s['r']['kelt'] for s in sz]
            if kulcs[0] == 'K':
                regi = kulcs[1]
                ev = min(keltek).year
                m = re.fullmatch(r'[^-]*-(\d{2})-.*', regi)
                if m:
                    e2 = 2000 + int(m.group(1)) if int(m.group(1)) < 80 else 1900 + int(m.group(1))
                    if min(keltek).year - 1 <= e2 <= max(keltek).year + 1:
                        ev = e2
                arukk = collections.Counter(tisztit(s['r']['aru']) for s in sz if s['forras'] == 'IMSZLA' and tisztit(s['r']['aru']))
                megnev = arukk.most_common(1)[0][0] if arukk else None
                num = re.sub(r'\D', '', regi.split('-')[-1])
                rend = (0, int(num) if num else 10 ** 9, regi)
                kt = {'archiv': 0, 'regi_kod': vag(regi, 100), 'megnevezes': vag(megnev, 191), 'ev': ev}
            else:
                ev = kulcs[3]
                rend = (1, 0, '')
                kt = {'archiv': 1, 'regi_kod': None, 'megnevezes': f'ARCHÍV – DOS import {ev}', 'ev': ev}
            kt.update({'ceg': sz[0]['ceg'], 'penznem': sz[0]['penznem'], 'szamlak': sz, 'rend': rend})
            kotesek.append(kt)
        kotesek.sort(key=lambda k: (k['ev'], k['penznem'], k['rend'], nev_kulcs(k['ceg']['nev'])))
        sorsz = collections.Counter()
        for i, k in enumerate(kotesek, 1):
            sorsz[(k['ev'], k['penznem'])] += 1
            k['id'] = i
            k['sorszam'] = sorsz[(k['ev'], k['penznem'])]
            k['kod'] = f"{k['ev']:04d}-{k['penznem']}-{k['sorszam']:06d}"
            for s in k['szamlak']:
                s['kotes'] = k
        self.kotesek = kotesek

    # ---------------- utalások ----------------
    def utalasok_epit(self, utal):
        """Egy DOS-utalás = egy BaninaPRO-utalás (UTALVA). A DOS-ban az utalással a számla átutaltnak számít."""
        lista = []
        for u in utal:
            k = u['k']
            cegek = {id(self.P.ceg(s['ek'])): self.P.ceg(s['ek']) for s in u['szamlak']}
            penznemek = {s['penznem'] for s in u['szamlak']}
            if len(cegek) != 1 or len(penznemek) != 1:
                raise RuntimeError(f"DOS-utalás #{k['azon'].strip()}: több cég vagy pénznem számlái egy utalásban – ellenőrizendő")
            c, pn = next(iter(cegek.values())), next(iter(penznemek))
            # datum: a sorrendhez / sorszámhoz (a DOS-utalás kelte); utalva: a BaninaPRO utalás dátuma = amit a DOS a
            # számla „Dátuma” oszlopában mutat – devizánál a terhelés napja (ha még nincs, üres), forintnál az utalás napja
            if u['forras'] == 'KEGY':
                datum, utalva, szamla_mezo = k['datum'] or k['terheles'], k['terheles'], 'terheles_datum'
                if not k['datum']:
                    self.esemeny('DOS-devizautalás megbízási dátum nélkül – a terhelés dátuma a sorrend alapja', f"#{k['azon'].strip()} {k['terheles']}")
                if tisztit(k['deviza']).upper() != pn:
                    raise RuntimeError(f"DOS-utalás #{k['azon'].strip()}: az utalás pénzneme ({k['deviza']}) eltér a számlákétól ({pn})")
            else:
                datum, utalva, szamla_mezo = k['datum'], k['datum'], 'utalas_datum'
            if datum is None:
                raise RuntimeError(f"DOS-utalás #{k['azon'].strip()}: nincs dátuma")
            u.update({'ceg': c, 'penznem': pn, 'datum': datum, 'utalva': utalva})
            for s in u['szamlak']:
                s['utalas'] = u
                if s['r'][szamla_mezo] != utalva:
                    self.figyelmeztetesek.append(
                        f"Bejövő {s['dos_azon']} „{tisztit(s['r']['szamlaszam'])}”: a DOS-ban a számla dátuma {datum_hu(s['r'][szamla_mezo])}, "
                        f"a DOS-utalás (#{k['azon'].strip()}) dátuma {datum_hu(utalva)} – mindkettő a DOS szerint került át")
            lista.append(u)
        lista.sort(key=lambda u: (u['datum'], u['penznem'], u['forras'], u['k']['azon']))
        sorsz = collections.Counter()
        for i, u in enumerate(lista, 1):
            ev = u['datum'].year
            sorsz[(u['penznem'], ev)] += 1
            u['id'] = i
            u['ev'] = ev
            u['sorszam'] = sorsz[(u['penznem'], ev)]
            u['uid'] = f"U-{u['penznem']}-{ev:04d}-{u['sorszam']:06d}"
            k = u['k']
            utalt = d2(k['osszeg'])
            if u['forras'] == 'KEGY':
                u['hivatkozas'] = f"DOS devizautalás #{k['azon'].strip()}"
                vp = self.P.vevo.get(k['partner'])
                kozl = ' '.join(tisztit(k[f'szoveg{j}']) for j in range(1, 9) if tisztit(k[f'szoveg{j}']))
                reszek = ['DOS import', f"Megbízás kelte: {datum_hu(k['datum'])}", f"Terhelés: {datum_hu(k['terheles'])}",
                          f"Összeg (DOS): {osszeg_hu(utalt)} {tisztit(k['deviza'])}"]
                if not k['terheles']:
                    reszek.append('A DOS-ban a terhelés még nincs rögzítve (00.00.00) – a számlák a DOS-ban már átutaltak, ezért '
                                  'UTALVA, dátum nélkül; a terhelés napját a BaninaPRO-ban lehet beírni')
                if k['partner'] not in {s['r']['partner'] for s in u['szamlak']}:
                    nev = tisztit(vp['nev']) if vp is not None else 'törölt DOS-partner'
                    reszek.append(f"Kedvezményezett a DOS-ban: {nev} ({k['partner']}) – a számlák szállítójánál szerepel")
                if vp is not None and (tisztit(vp['bank']) or tisztit(vp['bankszamla'])):
                    reszek.append('Kedvezményezett bankja (törzsadat): ' + ', '.join(x for x in (tisztit(vp['bank']), tisztit(vp['bankszamla'])) if x))
                if tisztit(k['bank']) and tisztit(k['bank']) not in ('.', '0'):
                    reszek.append(f"Bank/egyéb (utalás): {tisztit(k['bank'])}")
                if k['partner2']:
                    reszek.append(f"Kedvezményezett DOS-kódja: {k['partner2']}")
                reszek.append(f'Közlemény: {kozl}')
            else:
                u['hivatkozas'] = f"DOS forintutalás #{k['azon'].strip()}"
                reszek = ['DOS import', f"Utalás dátuma: {datum_hu(k['datum'])}", f"2. dátum (terhelés): {datum_hu(k['datum2'])}",
                          f"Összeg (DOS): {osszeg_hu(utalt)} HUF"]
                if szamla_bankkal(k['sajat_bankszamla']):
                    reszek.append(f"Terhelt saját számla: {szamla_bankkal(k['sajat_bankszamla'])}")
                if szamla_bankkal(k['partner_bankszamla']):
                    reszek.append(f"Kedvezményezett számlája: {szamla_bankkal(k['partner_bankszamla'])}")
                reszek.append(f"Közlemény: {tisztit(k['kozlemeny'])}")
            szum = sum(s['osszeg'] for s in u['szamlak'])
            kn, ksz = u['korabbi']
            u['dos_osszeg'] = utalt
            if kn:
                reszek.append(f"A DOS-utalás {kn} db {datum_hu(self.tol)} előtt kelt számlát is fizetett ({osszeg_hu(ksz)}) – "
                              f"azok nem kerültek át, ezért itt {osszeg_hu(szum)} látszik")
            if szum + ksz != utalt:
                reszek.append(f"Megjegyzés: a DOS-ban a számlák összege {osszeg_hu(szum + ksz)}, az utalt összeg {osszeg_hu(utalt)}")
                self.esemeny('Utalás: a DOS-ban a számlák összege eltér az utalt összegtől', f"{u['hivatkozas']} {utalt} ≠ {szum + ksz}")
            u['megjegyzes'] = ' · '.join(reszek)
        self.utalasok = lista

    # ---------------- bejövő státuszok ----------------
    def bejovo_statusz(self, bejovo):
        for s in bejovo:
            r = s['r']
            u = s.get('utalas')
            s['reszt'] = None
            s['utalas_id'] = u['id'] if u is not None else None
            if s['fizetes'] in ('fizetett', 'tulfizetett'):
                s['statusz'] = 'BESZAMITVA' if s['osszeg'] < 0 else 'FIZETVE'
                # amit a DOS a számla mellett fizetési dátumként mutat: devizás számlánál a „Dátuma” oszlop = a terhelés
                # napja (ha még nincs, a DOS 00.00.00-t mutat → üres); forintosnál az „Átutalva” oszlop = az utalás napja
                s['fizetve'] = r['terheles_datum'] if s['forras'] == 'IMSZLA' else r['utalas_datum']
            elif s['fizetes'] == 'reszben':
                s['statusz'], s['fizetve'] = 'FIZETENDO', None
                b = r['bizonylat'].strip() if s['forras'] == 'IMSZLA' else r['utalas_azon'].strip()
                datum = (r['terheles_datum'] if s['forras'] == 'IMSZLA' else None) or r['utalas_datum'] or r['kelt']
                s['reszt'] = (s['atutalt'], datum, vag(f"DOS {'devizautalás' if s['forras'] == 'IMSZLA' else 'forintutalás'} #{b}", 100) if b else 'DOS import')
            else:
                s['statusz'], s['fizetve'] = 'FIZETENDO', None

    # ---------------- bejövő sorrend, számlaszámok, megjegyzés ----------------
    def bejovo_sorrend(self, bejovo):
        bid = 0
        for k in self.kotesek:
            k['szamlak'].sort(key=lambda s: (s['r']['kelt'], s['regi_k'] or '', s['r']['_rekord']))
            foglalt = set()
            for i, s in enumerate(k['szamlak'], 1):
                bid += 1
                r = s['r']
                s['id'] = bid
                s['k_sorszam'] = i
                s['kod'] = f"{k['kod']}-K{i:04d}"
                if s['forras'] == 'IMSZLA':
                    sz = tisztit(r['szamlaszam']) or f"DOS {s['dos_kotes'] or ''}/{s['regi_k'] or ''}".strip()
                else:
                    sz = tisztit(r['szamlaszam']) or f"DOS F-{r['sorszam'].strip()}"
                n = norm_azonosito(sz)
                if not n or ci_kulcs(n) in foglalt:
                    alap = sz
                    sz = f"{alap} [{s['regi_k'] or r['_rekord']}]"
                    j = 2
                    while ci_kulcs(norm_azonosito(sz)) in foglalt:
                        sz = f"{alap} [{s['regi_k'] or r['_rekord']}] {j}"
                        j += 1
                    n = norm_azonosito(sz)
                    self.esemeny('Bejövő: kötésen belül ismétlődő számlaszám → toldalék', sz)
                foglalt.add(ci_kulcs(n))
                s['szamlaszam'], s['norm'] = vag(sz, 100), n
                s['kelt'] = r['kelt']
                if s['forras'] == 'IMSZLA':
                    s['teljesites'] = r['kelt']
                    # BESZÁM: a könyvelési számlanapló sorszáma (BE-… = bejövő EUR, BH-… = bejövő HUF), változatlanul
                    s['beszam'] = vag(tisztit(r['beszam']), 191) or None
                else:
                    s['teljesites'] = r['teljesites'] or r['kelt']
                    s['beszam'] = None
                hatarido_hiany = r['hatarido'] is None
                # a BaninaPRO-ban kötelező: ha a DOS-ban nincs, a kelt (a DOS az üres határidőt is lejártnak veszi)
                s['hatarido'] = r['hatarido'] or r['kelt']
                if hatarido_hiany:
                    self.esemeny('Bejövő: a DOS-ban nincs fizetési határidő (a kelt dátumát kapta)')
                s['megjegyzes'] = self.bejovo_megjegyzes(s, hatarido_hiany)

    def bejovo_megjegyzes(self, s, hatarido_hiany):
        r = s['r']
        cur = s['penznem']
        if s['forras'] == 'IMSZLA':
            p = ['DOS import', f"DOS azonosító: {s['dos_kotes'] or '–'}/{s['regi_k'] or '–'}", f"Szállító DOS-kódja: {r['partner']}"]
            if tisztit(r['aru']):
                p.append(f"Áru: {tisztit(r['aru'])}")
            if tisztit(r['vtsz']) and tisztit(r['vtsz']) not in ('1', '1111111'):
                p.append(f"VTSZ: {tisztit(r['vtsz'])}")
            if tisztit(r['jarmu']):
                p.append(f"Jármű/fuvarozó: {tisztit(r['jarmu'])}")
            if d2(r['aruertek']) != s['osszeg']:
                p.append(f"Áruérték (DOS): {osszeg_hu(d2(r['aruertek']))} {cur}")
        else:
            p = ['DOS import', f"DOS forintszámla: F-{r['sorszam'].strip()}"]
            megn = ' '.join(x for x in (tisztit(r['megnevezes']), tisztit(r['megnevezes2'])) if x)
            if megn:
                p.append(f'Megnevezés: {megn}')
            p.append(f"Nettó: {osszeg_hu(d2(r['netto']))} · ÁFA ({r['afa_kulcs']}%): {osszeg_hu(d2(r['afa']))} · Bruttó: {osszeg_hu(d2(r['brutto']))} HUF")
            if tisztit(r['kotes']) not in ('', '0'):
                p.append(f"DOS kötés: {tisztit(r['kotes'])}")
            if szamla_bankkal(r['partner_bankszamla']):
                p.append(f"Szállító bankszámlája: {szamla_bankkal(r['partner_bankszamla'])}")
        if s['forras'] == 'FORINT' and r['kifizetheto'] == 0:
            p.append('A DOS-ban „Kifizethető: NEM” jelölésű (nincs elutalva)')
        u = s.get('utalas')
        if u is not None:
            if s['forras'] == 'IMSZLA':
                p.append(f"Utalás: {u['hivatkozas']} · utalás napja (DOS): {datum_hu(r['utalas_datum'])} · terhelés (DOS): "
                         + (datum_hu(r['terheles_datum']) if r['terheles_datum'] else 'még nincs (a DOS-ban 00.00.00)'))
            else:
                p.append(f"Utalás: {u['hivatkozas']} ({datum_hu(r['utalas_datum'])})")
        elif s['fizetes'] == 'reszben':
            p.append(f"A DOS-ban átutalt: {osszeg_hu(s['atutalt'])} {cur} ({s['reszt'][2]}, {datum_hu(s['reszt'][1])}) – a különbözet a DOS-ban is nyitott")
        elif s['statusz'] in ('FIZETVE', 'BESZAMITVA'):
            p.append(f"Átutalás dátuma (DOS): {datum_hu(r['utalas_datum'])}")
        else:
            p.append('A DOS-ban nincs átutalva')
        if s['fizetes'] == 'tulfizetett':
            p.append(f"FIGYELEM: a DOS-ban átutaltként {osszeg_hu(s['atutalt'])} {cur} szerepel (több, mint a számla) – valószínű elírás a DOS-ban")
        if hatarido_hiany:
            p.append('A fizetési határidő a DOS-ban nincs megadva (a kelt dátumát kapta)')
        nl = nem_letezo_datumok(r)
        if nl:
            self.esemeny('A DOS-ban nem létező nap (pl. 02.30) → a hónap utolsó napja, megjegyzéssel', f"{s['dos_azon']}: {'; '.join(nl)}", 100)
        p.extend(nl)
        return ' · '.join(p)

    # ---------------- kimenő számlák ----------------
    def kimeno_epit(self, kimeno):
        for s in kimeno:
            s['ceg'] = self.P.ceg(s['ek'])
        kimeno.sort(key=lambda s: (s['r']['kelt'].year, s['penznem'], s['r']['kelt'], s['dos_azon'], s['r']['_rekord']))
        foglalt = set()
        sorsz = collections.Counter()
        kivonatok = {}
        for i, s in enumerate(kimeno, 1):
            r = s['r']
            ev = r['kelt'].year
            sorsz[(ev, s['penznem'])] += 1
            s['id'], s['ev'], s['sorszam'] = i, ev, sorsz[(ev, s['penznem'])]
            s['kod'] = f"{ev:04d}-{s['penznem']}-{s['sorszam']:06d}"
            sz = s['szamlaszam']
            n = norm_azonosito(sz)
            if ci_kulcs(n) in foglalt:
                tag = re.sub(r'[^\w ]+', '', s['ceg']['nev'])[:14].strip()
                sz2 = f'{sz} [{tag}]'
                j = 2
                while ci_kulcs(norm_azonosito(sz2)) in foglalt:
                    sz2 = f'{sz} [{tag}] {j}'
                    j += 1
                self.esemeny('Kimenő: ismétlődő számlaszám → toldalék', f'{sz} → {sz2}')
                sz = sz2
                n = norm_azonosito(sz)
            foglalt.add(ci_kulcs(n))
            s['szamlaszam'], s['norm'] = vag(sz, 100), n
            s['kelt'] = r['kelt']
            s['teljesites'] = r['teljesites'] or r['kelt']
            hatarido_hiany = r['hatarido'] is None
            s['hatarido'] = r['hatarido'] or r['kelt']
            # fizetés: a DOS „Befolyás” oszlopa
            o, b = s['osszeg'], s['befolyt']
            s['reszt'] = None
            fdatum = r['befolyas_datum']
            tipus = self.fizetes_tipus(o, b)
            s['fizetes'] = tipus
            if tipus == 'nyitott':
                s['statusz'], s['fizetve'] = 'NYITOTT', None
            elif tipus == 'reszben':
                s['statusz'], s['fizetve'] = 'NYITOTT', None
                s['reszt'] = (b, fdatum or r['kelt'], 'DOS import')
                self.esemeny('Kimenő: részben befolyt (részteljesítés)', f"{sz} {o} / {b}")
            else:
                s['statusz'], s['fizetve'] = 'FIZETVE', fdatum
                if not fdatum:
                    self.esemeny('Kimenő: befolyt, de a DOS-ban nincs befolyási dátum (dátum nélkül FIZETVE)', sz)
                if tipus == 'tulfizetett':
                    self.figyelmeztetesek.append(f"Kimenő „{s['dos_azon']}” ({s['ceg']['nev']}): a számla {osszeg_hu(o)} {s['penznem']}, "
                                                 f"a DOS-ban befolytként {osszeg_hu(b)} {s['penznem']} szerepel – a BaninaPRO-ban FIZETVE")
            if s['statusz'] == 'FIZETVE' and s['fizetve']:
                kk = (s['fizetve'], s['penznem'])
                if kk not in kivonatok:
                    kivonatok[kk] = {'datum': s['fizetve'], 'penznem': s['penznem'],
                                     'azonosito': f"DOS {datum_hu(s['fizetve'])} {s['penznem']}"}
                s['kivonat'] = kivonatok[kk]
            else:
                s['kivonat'] = None
            s['megjegyzes'] = self.kimeno_megjegyzes(s, hatarido_hiany)
        kiv = sorted(kivonatok.values(), key=lambda k: (k['datum'], k['penznem']))
        for i, k in enumerate(kiv, 1):
            k['id'] = i
            k['norm'] = norm_azonosito(k['azonosito'])
        self.kivonatok = kiv

    def kimeno_megjegyzes(self, s, hatarido_hiany):
        r = s['r']
        cur = s['penznem']
        p = ['DOS import', f"DOS azonosító: {s['dos_azon']}", f"Vevő adószáma (DOS): {tisztit(r['adoszam']) or '–'}"]
        if tisztit(r['megrendeles']):
            p.append(f"Megrendelés: {tisztit(r['megrendeles'])}")
        if tisztit(r['fizmod']):
            p.append(f"Fizetési mód: {tisztit(r['fizmod'])}")
        kulcs = r['afa_kulcs'] / 100 if r['afa_kulcs'] else 0
        p.append(f"Nettó: {osszeg_hu(d2(r['netto']))} {cur} · ÁFA ({kulcs:g}%): {osszeg_hu(d2(r['afa']))} · Bruttó: {osszeg_hu(s['osszeg'])} {cur}")
        if s['befolyt']:
            p.append(f"Befolyt: {osszeg_hu(s['befolyt'])} {cur} ({datum_hu(r['befolyas_datum'])})")
        else:
            p.append('Befolyás a DOS-ban nincs rögzítve')
        if tisztit(r['egyeb']):
            p.append(f"Egyéb: {tisztit(r['egyeb'])}")
        if s['eredeti']:
            p.append('Deviza: ' + s['eredeti'])
        if s['fizetes'] == 'tulfizetett':
            p.append(f"FIGYELEM: a DOS-ban a befolyt összeg ({osszeg_hu(s['befolyt'])}) több, mint a számla – valószínű elírás a DOS-ban")
        if hatarido_hiany:
            p.append('A fizetési határidő a DOS-ban nincs megadva (a kelt dátumát kapta)')
        nl = nem_letezo_datumok(r)
        if nl:
            self.esemeny('A DOS-ban nem létező nap (pl. 02.30) → a hónap utolsó napja, megjegyzéssel', f"{s['dos_azon']}: {'; '.join(nl)}", 100)
        p.extend(nl)
        return ' · '.join(p)


# ---------------------------------------------------------------------------
#  SQL fájlok írása
# ---------------------------------------------------------------------------
FEJLEC = """-- =====================================================================
--  BaninaPRO – DOS (KULKER / IMPORT.EXE) adatimport
--  {cim}
--  Készült: {kelt} · időszak: {tol} – {ig} (a számla kelte szerint)
--  Futtatás: phpMyAdmin → a BaninaPRO adatbázis kiválasztása → Importálás,
--  a fájlokat a SORSZÁMUK SZERINTI SORRENDBEN, egyenként.
--  A fájl egy tranzakció: ha bármi hibát jelez, semmi nem kerül be belőle.
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
"""


class SqlIro:
    def __init__(self, mappa, max_mb, ex):
        self.mappa = mappa
        self.max_bajt = int(max_mb * 1024 * 1024)
        self.ex = ex
        self.fajlok = []
        os.makedirs(mappa, exist_ok=True)
        for f in os.listdir(mappa):
            if f.endswith('.sql'):
                os.remove(os.path.join(mappa, f))

    def fej(self, cim):
        return FEJLEC.format(cim=cim, kelt=datetime.datetime.now().strftime('%Y.%m.%d %H:%M'),
                             tol=datum_hu(self.ex.tol), ig=datum_hu(self.ex.ig))

    @staticmethod
    def or_hiba(feltetel_sql, uzenet):
        """Ha a feltétel igaz, a lekérdezés szándékosan hibát ad (a phpMyAdmin leáll, a tranzakció visszagörgetődik)."""
        uz = uzenet.replace("'", "''")
        return (f"SET @hiba := IF({feltetel_sql}, 1, 0);\n"
                f"SELECT 'HA ITT HIBA VAN: {uz}' AS ellenorzes,\n"
                f"       (SELECT 1 FROM DUAL WHERE @hiba = 1 UNION ALL SELECT 2 FROM DUAL WHERE @hiba = 1) AS allapot;\n")

    def ir(self, nev, tartalom):
        ut = os.path.join(self.mappa, nev)
        with open(ut, 'w', encoding='utf-8', newline='\n') as f:
            f.write(tartalom)
        self.fajlok.append((nev, os.path.getsize(ut)))

    def tabla(self, sorszam, nev, cim, tabla, oszlopok, sorok, elofeltetelek, id_oszlop='id'):
        """sorok: list of tuples (az oszlopok sorrendjében). Méret szerint több fájlra bont."""
        reszek, akt, akt_meret = [], [], 0
        for s in sorok:
            sor_sql = '(' + ', '.join(sql(v) for v in s) + ')'
            if akt and akt_meret + len(sor_sql) > self.max_bajt:
                reszek.append(akt)
                akt, akt_meret = [], 0
            akt.append((s, sor_sql))
            akt_meret += len(sor_sql.encode('utf-8')) + 2
        if akt or not reszek:
            reszek.append(akt)
        idx = oszlopok.index(id_oszlop) if id_oszlop in oszlopok else None
        for ri, resz in enumerate(reszek, 1):
            fnev = f'{sorszam:02d}_{nev}' + (f'_{ri}' if len(reszek) > 1 else '') + '.sql'
            t = [self.fej(f'{cim}' + (f' – {ri}/{len(reszek)}. rész' if len(reszek) > 1 else '') + f' ({len(resz)} sor)')]
            t.append('START TRANSACTION;\n\n-- Előfeltételek ellenőrzése\n')
            for felt, uz in elofeltetelek:
                t.append(self.or_hiba(felt, uz))
            if idx is not None and resz:
                a, b = resz[0][0][idx], resz[-1][0][idx]
                if ri > 1:
                    t.append(self.or_hiba(f'(SELECT COUNT(*) FROM `{tabla}` WHERE `{id_oszlop}` < {a}) <> {a - 1}',
                                          f'előbb a(z) {nev} korábbi része(i)t kell lefuttatni'))
                t.append(self.or_hiba(f'(SELECT COUNT(*) FROM `{tabla}` WHERE `{id_oszlop}` BETWEEN {a} AND {b}) > 0',
                                      'ez a fájl már le lett futtatva (vagy a tábla nem üres)'))
            t.append('\n')
            oszl = ', '.join(f'`{o}`' for o in oszlopok)
            for i in range(0, len(resz), SOR_PER_INSERT):
                csomag = resz[i:i + SOR_PER_INSERT]
                t.append(f'INSERT INTO `{tabla}` ({oszl}) VALUES\n' + ',\n'.join(x[1] for x in csomag) + ';\n')
            t.append('\nCOMMIT;\n')
            self.ir(fnev, ''.join(t))


def sql_fajlok(ex, mappa, max_mb):
    w = SqlIro(mappa, max_mb, ex)
    N = {'cegek': len(ex.cegek), 'kotesek': len(ex.kotesek), 'utalasok': len(ex.utalasok),
         'bejovo_szamlak': len(ex.bejovo), 'banki_kivonatok': len(ex.kivonatok), 'kimeno_szamlak': len(ex.kimeno_lista)}
    resztek = [('BEJOVO', s) for s in ex.bejovo if s.get('reszt')] + [('KIMENO', s) for s in ex.kimeno_lista if s.get('reszt')]

    def kesz(*tablak):
        return [(f'(SELECT COUNT(*) FROM `{t}`) <> {N[t]}', f'a(z) {t} tábla nem teljes – előbb a korábbi sorszámú fájlokat kell lefuttatni') for t in tablak]

    # 00 – a korábbi (teszt- vagy DOS-import) adatok törlése (külön, tudatosan futtatandó)
    van_import = "(SELECT COUNT(*) FROM `valtozasnaplo` WHERE `felhasznalonev` = 'DOS import') > 0"
    kezi = ' + '.join([
        "(SELECT COUNT(*) FROM `valtozasnaplo` WHERE `felhasznalonev` <> 'DOS import')",
        *(f'(SELECT COUNT(*) FROM `{tb}` WHERE `letrehozta` IS NOT NULL)'
          for tb in ('cegek', 'kotesek', 'utalasok', 'bejovo_szamlak', 'kimeno_szamlak', 'banki_kivonatok', 'reszteljesitesek')),
        '(SELECT COUNT(*) FROM `utalasok` WHERE `utalta` IS NOT NULL OR `lezarta` IS NOT NULL)',
        *(f'(SELECT COUNT(*) FROM `{tb}` WHERE `modositotta` IS NOT NULL)' for tb in ('kotesek', 'bejovo_szamlak', 'kimeno_szamlak'))])
    w.ir('00_korabbi_adatok_torlese.sql', w.fej('A BaninaPRO jelenlegi számlaadatainak TÖRLÉSE (a korábbi DOS-import vagy tesztadat)') + """
-- FIGYELEM! Ez a fájl TÖRLI a BaninaPRO összes cégét, kötését, számláját, utalását,
-- bankkivonat-azonosítóját, részteljesítését, változásnaplóját és sorszámát – a korábbi DOS-importot is.
-- A felhasználók, a belépési eszközök (passkey) és a beállítások MEGMARADNAK.
-- Futtatás előtt készíts mentést: BaninaPRO → Admin → Adatbázis-mentések → Mentés most.
--
-- VÉDELEM: ha a BaninaPRO-ban már van DOS-import, és azóta valaki a programban rögzített vagy módosított
-- valamit (új tétel, módosítás, utalás jelölése), a fájl NEM töröl semmit, hanem hibával megáll –
-- ezek a kézi változások a törléssel elvesznének. Ilyenkor szólj, mielőtt továbblépsz.
-- A kézi változások listája (ha van):
SELECT `idopont`, `felhasznalonev`, `tipus`, `muvelet`, `leiras` FROM `valtozasnaplo` WHERE `felhasznalonev` <> 'DOS import' ORDER BY `idopont`;
""" + w.or_hiba(f'{van_import} AND ({kezi}) > 0',
                'a DOS-import óta a BaninaPRO-ban kézi rögzítés/módosítás történt – a törlés ezeket is eltüntetné, ezért NEM töröltem semmit') + """
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM `reszteljesitesek`;
DELETE FROM `valtozasnaplo`;
DELETE FROM `bejovo_szamlak`;
DELETE FROM `kimeno_szamlak`;
DELETE FROM `banki_kivonatok`;
DELETE FROM `utalasok`;
DELETE FROM `kotesek`;
DELETE FROM `cegek`;
DELETE FROM `sorszamok`;
SET FOREIGN_KEY_CHECKS = 1;
ALTER TABLE `reszteljesitesek` AUTO_INCREMENT = 1;
ALTER TABLE `valtozasnaplo` AUTO_INCREMENT = 1;
ALTER TABLE `bejovo_szamlak` AUTO_INCREMENT = 1;
ALTER TABLE `kimeno_szamlak` AUTO_INCREMENT = 1;
ALTER TABLE `banki_kivonatok` AUTO_INCREMENT = 1;
ALTER TABLE `utalasok` AUTO_INCREMENT = 1;
ALTER TABLE `kotesek` AUTO_INCREMENT = 1;
ALTER TABLE `cegek` AUTO_INCREMENT = 1;
""")

    ures = ' + '.join(f'(SELECT COUNT(*) FROM `{t}`)' for t in
                      ('cegek', 'kotesek', 'utalasok', 'bejovo_szamlak', 'banki_kivonatok', 'kimeno_szamlak', 'reszteljesitesek', 'sorszamok'))
    w.tabla(1, 'cegek', 'Cégek (a DOS partnertörzsből)', 'cegek', ['id', 'nev', 'adoszam', 'partnerkod', 'megjegyzes'],
            [(c['id'], c['nev'], c['adoszam'], c['partnerkod'], c['megjegyzes']) for c in ex.cegek],
            [(f'({ures}) > 0', 'a BaninaPRO számlatáblái nem üresek – előbb futtasd a 00_korabbi_adatok_torlese.sql fájlt')])
    w.tabla(2, 'kotesek', 'Kötések', 'kotesek',
            ['id', 'ceg_id', 'ev', 'penznem', 'sorszam', 'kod', 'megnevezes', 'megjegyzes', 'archiv', 'regi_kod', 'utolso_k'],
            [(k['id'], k['ceg']['id'], k['ev'], k['penznem'], k['sorszam'], k['kod'], k['megnevezes'],
              f"DOS import ({datum_hu(ex.ig)})", k['archiv'], k['regi_kod'], len(k['szamlak'])) for k in ex.kotesek],
            kesz('cegek'))
    w.tabla(3, 'utalasok', 'Utalások (DOS deviza- és forintutalások)', 'utalasok',
            ['id', 'ceg_id', 'ev', 'penznem', 'sorszam', 'uid', 'utalas_mod', 'statusz', 'utalva_datum', 'banki_hivatkozas', 'megjegyzes', 'lezarva'],
            [(u['id'], u['ceg']['id'], u['ev'], u['penznem'], u['sorszam'], u['uid'], 'MANUAL', 'UTALVA', u['utalva'],
              vag(u['hivatkozas'], 100), u['megjegyzes'], 1) for u in ex.utalasok],
            kesz('cegek'))
    w.tabla(4, 'bejovo_szamlak', 'Bejövő számlák', 'bejovo_szamlak',
            ['id', 'kotes_id', 'k_sorszam', 'kod', 'szamlaszam', 'szamlaszam_norm', 'teljesites_datum', 'kelt', 'osszeg', 'beszam',
             'regi_k', 'fizetesi_hatarido', 'statusz', 'fizetve_datum', 'utalas_id', 'megjegyzes'],
            sorted(((s['id'], s['kotes']['id'], s['k_sorszam'], s['kod'], s['szamlaszam'], s['norm'], s['teljesites'], s['kelt'],
                     s['osszeg'], s['beszam'], vag(s['regi_k'], 50), s['hatarido'], s['statusz'], s['fizetve'], s['utalas_id'],
                     s['megjegyzes']) for s in ex.bejovo)),
            kesz('cegek', 'kotesek', 'utalasok'))
    w.tabla(5, 'banki_kivonatok', 'Banki kivonat-azonosítók (a kimenő számlák befolyásaihoz, naponta és pénznemenként)',
            'banki_kivonatok', ['id', 'azonosito', 'azonosito_norm', 'datum'],
            [(k['id'], k['azonosito'], k['norm'], k['datum']) for k in ex.kivonatok],
            kesz('cegek', 'kotesek', 'utalasok', 'bejovo_szamlak'))
    w.tabla(6, 'kimeno_szamlak', 'Kimenő számlák', 'kimeno_szamlak',
            ['id', 'ceg_id', 'ev', 'penznem', 'sorszam', 'kod', 'szamlaszam', 'szamlaszam_norm', 'teljesites_datum', 'kelt',
             'fizetesi_hatarido', 'osszeg', 'statusz', 'banki_kivonat_id', 'fizetve_datum', 'megjegyzes'],
            [(s['id'], s['ceg']['id'], s['ev'], s['penznem'], s['sorszam'], s['kod'], s['szamlaszam'], s['norm'], s['teljesites'],
              s['kelt'], s['hatarido'], s['osszeg'], s['statusz'], s['kivonat']['id'] if s['kivonat'] else None, s['fizetve'],
              s['megjegyzes']) for s in ex.kimeno_lista],
            kesz('cegek', 'banki_kivonatok'))
    w.tabla(7, 'reszteljesitesek', 'Részteljesítések (részben fizetett számlák)', 'reszteljesitesek',
            ['irany', 'szamla_id', 'osszeg', 'datum', 'banki_azonosito'],
            [(irany, s['id'], s['reszt'][0], s['reszt'][1], s['reszt'][2]) for irany, s in resztek],
            kesz('bejovo_szamlak', 'kimeno_szamlak') + [('(SELECT COUNT(*) FROM `reszteljesitesek`) > 0', 'a részteljesítések már be vannak töltve')],
            id_oszlop=None)

    # 08 – változásnapló + sorszám-generátor
    t = [w.fej('Változásnapló-bejegyzések (IMPORT) és a sorszám-generátor beállítása'), 'START TRANSACTION;\n\n']
    for felt, uz in kesz('cegek', 'kotesek', 'utalasok', 'bejovo_szamlak', 'banki_kivonatok', 'kimeno_szamlak'):
        t.append(w.or_hiba(felt, uz))
    t.append(w.or_hiba("(SELECT COUNT(*) FROM `valtozasnaplo` WHERE `felhasznalonev` = 'DOS import') > 0", 'ez a fájl már le lett futtatva'))
    t.append(f"""
INSERT INTO `valtozasnaplo` (`tipus`, `rekord_id`, `szulo_id`, `muvelet`, `leiras`, `felhasznalonev`)
SELECT 'KOTES', k.`id`, NULL, 'IMPORT',
       LEFT(CONCAT('DOS importból létrehozva: ', k.`kod`, IF(k.`regi_kod` IS NULL, ' (archív kötés)', CONCAT(' – régi (DOS) kötésszám: ', k.`regi_kod`))), 500),
       'DOS import'
  FROM `kotesek` k WHERE k.`id` BETWEEN 1 AND {N['kotesek']};

INSERT INTO `valtozasnaplo` (`tipus`, `rekord_id`, `szulo_id`, `muvelet`, `leiras`, `felhasznalonev`)
SELECT 'BEJOVO', b.`id`, b.`kotes_id`, 'IMPORT',
       LEFT(CONCAT('DOS importból létrehozva: ', b.`kod`, ' „', b.`szamlaszam`, '” (', CAST(b.`osszeg` AS CHAR), ' ', k.`penznem`, ')'), 500),
       'DOS import'
  FROM `bejovo_szamlak` b JOIN `kotesek` k ON k.`id` = b.`kotes_id` WHERE b.`id` BETWEEN 1 AND {N['bejovo_szamlak']};

INSERT INTO `valtozasnaplo` (`tipus`, `rekord_id`, `szulo_id`, `muvelet`, `leiras`, `felhasznalonev`)
SELECT 'KIMENO', s.`id`, NULL, 'IMPORT',
       LEFT(CONCAT('DOS importból létrehozva: ', s.`kod`, ' „', s.`szamlaszam`, '” (', CAST(s.`osszeg` AS CHAR), ' ', s.`penznem`, ')'), 500),
       'DOS import'
  FROM `kimeno_szamlak` s WHERE s.`id` BETWEEN 1 AND {N['kimeno_szamlak']};

-- Sorszám-generátor: a BaninaPRO-ban ezután rögzített tételek a DOS-ból jöttek UTÁN folytatják a számozást
""")
    maxok = collections.defaultdict(int)
    for k in ex.kotesek:
        maxok[('KOTES', k['ev'], k['penznem'])] = max(maxok[('KOTES', k['ev'], k['penznem'])], k['sorszam'])
    for u in ex.utalasok:
        maxok[('UTALAS', u['ev'], u['penznem'])] = max(maxok[('UTALAS', u['ev'], u['penznem'])], u['sorszam'])
    for s in ex.kimeno_lista:
        maxok[('KIMENO', s['ev'], s['penznem'])] = max(maxok[('KIMENO', s['ev'], s['penznem'])], s['sorszam'])
    for (tip, ev, pn), m in sorted(maxok.items()):
        t.append(f"INSERT IGNORE INTO `sorszamok` (`tipus`, `ev`, `penznem`, `utolso`) VALUES ('{tip}', {ev}, '{pn}', {m});\n"
                 f"UPDATE `sorszamok` SET `utolso` = GREATEST(`utolso`, {m}) WHERE `tipus` = '{tip}' AND `ev` = {ev} AND `penznem` = '{pn}';\n")
    t.append('\nCOMMIT;\n')
    t.append('\n-- Indexstatisztikák frissítése a tömeges betöltés után. Enélkül az adatbázis rossz lekérdezési tervet\n'
             '-- választhat, és egyes listák (pl. a céglista) percekig is tölthetnek. Adatot nem módosít.\n'
             'ANALYZE TABLE `cegek`, `kotesek`, `utalasok`, `bejovo_szamlak`, `banki_kivonatok`, `kimeno_szamlak`, `reszteljesitesek`, `valtozasnaplo`;\n')
    w.ir('08_valtozasnaplo_es_sorszamok.sql', ''.join(t))

    # 09 – ellenőrzés (csak lekérdezés)
    w.ir('09_ellenorzes.sql', w.fej('ELLENŐRZÉS – csak lekérdezés, nem módosít semmit') + ellenorzo_sql(ex, N))
    return w.fajlok


def ellenorzo_sql(ex, N):
    sorok = []
    for t, n in N.items():
        sorok.append(f"SELECT '{t}' AS tabla, '' AS reszlet, COUNT(*) AS tenyleges, {n} AS vart FROM `{t}`")
    sorok.append(f"SELECT 'reszteljesitesek', '', COUNT(*), {sum(1 for s in ex.bejovo + ex.kimeno_lista if s.get('reszt'))} FROM `reszteljesitesek`")
    eredm = 'SELECT tabla, reszlet, tenyleges, vart, IF(tenyleges = vart, \'OK\', \'ELTÉR!\') AS eredmeny FROM (\n  ' + '\n  UNION ALL '.join(sorok) + '\n) x;\n\n'
    # összegek pénznem és státusz szerint
    bej = collections.defaultdict(lambda: [0, Decimal(0)])
    for s in ex.bejovo:
        g = bej[(s['penznem'], s['statusz'])]
        g[0] += 1
        g[1] += s['osszeg']
    kim = collections.defaultdict(lambda: [0, Decimal(0)])
    for s in ex.kimeno_lista:
        g = kim[(s['penznem'], s['statusz'])]
        g[0] += 1
        g[1] += s['osszeg']
    v = []
    for (pn, st), (db, osz) in sorted(bej.items()):
        v.append(f"SELECT 'bejövő {pn} {st}' AS csoport, (SELECT COUNT(*) FROM `bejovo_szamlak` b JOIN `kotesek` k ON k.`id` = b.`kotes_id` WHERE k.`penznem` = '{pn}' AND b.`statusz` = '{st}') AS db, {db} AS vart_db, "
                 f"(SELECT COALESCE(SUM(b.`osszeg`), 0) FROM `bejovo_szamlak` b JOIN `kotesek` k ON k.`id` = b.`kotes_id` WHERE k.`penznem` = '{pn}' AND b.`statusz` = '{st}') AS osszeg, {osz} AS vart_osszeg")
    for (pn, st), (db, osz) in sorted(kim.items()):
        v.append(f"SELECT 'kimenő {pn} {st}', (SELECT COUNT(*) FROM `kimeno_szamlak` WHERE `penznem` = '{pn}' AND `statusz` = '{st}'), {db}, "
                 f"(SELECT COALESCE(SUM(`osszeg`), 0) FROM `kimeno_szamlak` WHERE `penznem` = '{pn}' AND `statusz` = '{st}'), {osz}")
    eredm += ('SELECT csoport, db, vart_db, osszeg, vart_osszeg, IF(db = vart_db AND osszeg = vart_osszeg, \'OK\', \'ELTÉR!\') AS eredmeny FROM (\n  '
              + '\n  UNION ALL '.join(v) + '\n) y;\n\n')
    eredm += dos_osszevetes_sql(ex)
    return eredm


def dos_osszevetes_sql(ex):
    """Cégenkénti és utalásonkénti összevetés a DOS-szal, a BaninaPRO saját képleteivel (api_bejovo.php
    BEJOVO_OSSZESITO_MEZOK): teljes = minden számla összege, fizetett = rendezett számlák + a nyitottak
    részteljesítései, nyitott = a nyitott számlák hátraléka. A várt érték a DOS „Összesen” / „Átutalva” oszlopa
    (kimenőnél „Számlázva” / „Befolyás”). Csak az eltérő sorokat listázza, a végén egy összesítő sorral."""
    def vart(lista, fizetett_mezo):
        g = collections.defaultdict(lambda: [0, Decimal(0), Decimal(0)])
        for s in lista:
            x = g[(s['ceg']['id'], s['penznem'])]
            x[0] += 1
            x[1] += s['osszeg']
            # túlfizetés (a DOS-ban több az átutalt/befolyt, mint a számla) a BaninaPRO-ban FIZETVE = a számla összege
            x[2] += s['osszeg'] if s['fizetes'] == 'tulfizetett' else s[fizetett_mezo]
        return g
    t = []
    for cim, lista, mezo, from_sql, nyitott_st, fizetett_st, reszt_join, reszt_irany in (
            ('bejövő', ex.bejovo, 'atutalt', '`bejovo_szamlak` b JOIN `kotesek` k ON k.`id` = b.`kotes_id`', ('FIZETENDO', 'UTALASHOZ_ADVA'),
             ('FIZETVE', 'BESZAMITVA'), 'b', 'BEJOVO'),
            ('kimenő', ex.kimeno_lista, 'befolyt', '`kimeno_szamlak` b JOIN `cegek` k ON k.`id` = b.`ceg_id`', ('NYITOTT',), ('FIZETVE',), 'b', 'KIMENO')):
        g = vart(lista, mezo)
        ceg_oszlop = 'k.`ceg_id`' if cim == 'bejövő' else 'b.`ceg_id`'
        pn_oszlop = 'k.`penznem`' if cim == 'bejövő' else 'b.`penznem`'
        sorok = [f"SELECT {cid} AS ceg_id, '{pn}' AS penznem, {db} AS vart_db, {osz} AS vart_teljes, {fiz} AS vart_fizetett, {osz - fiz} AS vart_nyitott"
                 for (cid, pn), (db, osz, fiz) in sorted(g.items())]
        nyit = ', '.join(f"'{x}'" for x in nyitott_st)
        fizs = ', '.join(f"'{x}'" for x in fizetett_st)
        baninapro = (f"SELECT {ceg_oszlop} AS ceg_id, {pn_oszlop} AS penznem, COUNT(*) AS db, SUM(b.`osszeg`) AS teljes,\n"
                     f"         SUM(CASE WHEN b.`statusz` IN ({fizs}) THEN b.`osszeg` ELSE COALESCE(r.`reszt`, 0) END) AS fizetett,\n"
                     f"         SUM(CASE WHEN b.`statusz` IN ({nyit}) THEN b.`osszeg` - COALESCE(r.`reszt`, 0) ELSE 0 END) AS nyitott\n"
                     f"    FROM {from_sql}\n"
                     f"    LEFT JOIN (SELECT `szamla_id`, SUM(`osszeg`) AS reszt FROM `reszteljesitesek` WHERE `irany` = '{reszt_irany}' GROUP BY `szamla_id`) r ON r.`szamla_id` = b.`id`\n"
                     f"   GROUP BY {ceg_oszlop}, {pn_oszlop}")
        t.append(f"-- {cim.upper()} SZÁMLÁK cégenként és pénznemenként: BaninaPRO ↔ DOS ({len(sorok)} cég/pénznem)\n"
                 f"SELECT v.ceg_id, c.`nev`, v.penznem, v.vart_db, x.db, v.vart_teljes, x.teljes, v.vart_fizetett, x.fizetett, v.vart_nyitott, x.nyitott,\n"
                 f"       IF(x.db = v.vart_db AND x.teljes = v.vart_teljes AND x.fizetett = v.vart_fizetett AND x.nyitott = v.vart_nyitott, 'OK', 'ELTÉR!') AS eredmeny\n"
                 f"  FROM (\n  " + '\n  UNION ALL '.join(sorok) + "\n) v\n"
                 f"  LEFT JOIN `cegek` c ON c.`id` = v.ceg_id\n"
                 f"  LEFT JOIN ({baninapro}) x ON x.ceg_id = v.ceg_id AND x.penznem = v.penznem\n"
                 f" WHERE NOT (x.db <=> v.vart_db AND x.teljes <=> v.vart_teljes AND x.fizetett <=> v.vart_fizetett AND x.nyitott <=> v.vart_nyitott)\n"
                 f"UNION ALL\n"
                 f"SELECT NULL, '{cim} – összesítő: ennyi cég/pénznem eltér (0 kell legyen)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,\n"
                 f"       IF((SELECT COUNT(*) FROM (SELECT {ceg_oszlop} AS ceg_id, {pn_oszlop} AS penznem FROM {from_sql} GROUP BY {ceg_oszlop}, {pn_oszlop}) z) = {len(sorok)}, 'OK', 'ELTÉR! (a cég/pénznem párok száma)');\n\n")
    # utalások: a BaninaPRO-utalás számláinak összege = a DOS-utalás összege − a 2026 előtti (át nem került) számlák
    sorok = [f"SELECT {u['id']} AS id, {u['dos_osszeg'] - u['korabbi'][1]} AS vart, {len(u['szamlak'])} AS vart_db" for u in ex.utalasok]
    t.append(f"-- UTALÁSOK: a hozzárendelt számlák összege = a DOS-utalás összege (a {datum_hu(ex.tol)} előtti számlák nélkül), {len(sorok)} utalás\n"
             "SELECT v.id, u.`uid`, u.`banki_hivatkozas`, v.vart_db, x.db, v.vart, x.osszeg, 'ELTÉR!' AS eredmeny\n"
             "  FROM (\n  " + '\n  UNION ALL '.join(sorok) + "\n) v\n"
             "  LEFT JOIN `utalasok` u ON u.`id` = v.id\n"
             "  LEFT JOIN (SELECT `utalas_id`, COUNT(*) AS db, SUM(`osszeg`) AS osszeg FROM `bejovo_szamlak` WHERE `utalas_id` IS NOT NULL GROUP BY `utalas_id`) x ON x.`utalas_id` = v.id\n"
             " WHERE NOT (x.osszeg <=> v.vart AND x.db <=> v.vart_db)\n"
             "UNION ALL\n"
             f"SELECT NULL, 'utalások – összesítő (fent csak az eltérők; 0 sor = minden OK)', NULL, NULL, NULL, NULL, NULL, "
             f"IF((SELECT COUNT(*) FROM `utalasok`) = {len(sorok)}, 'OK', 'ELTÉR! (az utalások száma)');\n")
    return ''.join(t)


# ---------------------------------------------------------------------------
#  Jelentés (OLVASSEL.txt)
# ---------------------------------------------------------------------------
def jelentes(ex, fajlok, mappa):
    L = []
    a = L.append
    a('BaninaPRO – DOS ADATIMPORT')
    a('=' * 72)
    a(f'Forrás: a hálózati meghajtó F:\\KULKER\\ADATOK mappája (KULKER / IMPORT.EXE)')
    a(f"Időszak: {datum_hu(ex.tol)} – {datum_hu(ex.ig)} (a számla kelte szerint, a kezdőnapot is beleértve; a korábbi számlák NEM kerülnek át)")
    a(f'Készült: {datetime.datetime.now():%Y.%m.%d %H:%M}')
    a('')
    a('ALAPELV: A DOS A MÉRVADÓ')
    a('-' * 72)
    a('  Pontosan azok a tételek kerülnek át, amelyeket a DOS a listáiban mutat, a DOS összegével és')
    a('  fizetési állapotával. A DOS lista „Összesen” oszlopa = a BaninaPRO „teljes” összege, az')
    a('  „Átutalva” (kimenőnél „Befolyás”) = „fizetett”, a kettő különbsége = „nyitott”.')
    a('  STORNO: a DOS a STORNO-jelzésű rekordot a devizás és kimenő listáin nem mutatja, a forintos listán')
    a('  „STORNO” címkével jelöli – ez nem kerül át. A párja (a javított, újra rögzített számla) a DOS-ban')
    a('  érvényes, a legtöbbször ki is fizetett tétel (benne van a DOS-utalás összegében), ezért az átkerül.')
    a('')
    a('MIT TARTALMAZ')
    a('-' * 72)
    a(f'  Cégek:                 {len(ex.cegek):>7}')
    hely = collections.Counter(c['helyorzo'] for c in ex.cegek if c['helyorzo'])
    if hely:
        a(f"    ebből helyőrző név:   {sum(hely.values()):>5}  (törölt / ismeretlen / nem egyértelmű DOS-partner – lásd lent)")
    a(f"  Kötések:               {len(ex.kotesek):>7}  (ebből archív: {sum(1 for k in ex.kotesek if k['archiv'])})")
    ut = collections.Counter(u['forras'] for u in ex.utalasok)
    a(f'  Utalások (mind UTALVA): {len(ex.utalasok):>6}')
    for f, n in sorted(ut.items()):
        a(f"    {'devizautalás (KEGY)' if f == 'KEGY' else 'forintutalás (IMUTAL)':<24} {n:>6}")
    a(f'  Bejövő számlák:        {len(ex.bejovo):>7}')
    bc = collections.defaultdict(lambda: [0, Decimal(0)])
    for s in ex.bejovo:
        g = bc[(s['forras'], s['penznem'], s['statusz'])]
        g[0] += 1
        g[1] += s['osszeg']
    for (f, pn, st), (n, o) in sorted(bc.items()):
        a(f"    {'devizás (IMSZLA)' if f == 'IMSZLA' else 'forintos (FORINT)':<18} {pn} {st:<16} {n:>6} db  {osszeg_hu(o):>20} {pn}")
    a(f'  Kimenő számlák:        {len(ex.kimeno_lista):>7}')
    kc = collections.defaultdict(lambda: [0, Decimal(0)])
    for s in ex.kimeno_lista:
        g = kc[(s['penznem'], s['statusz'])]
        g[0] += 1
        g[1] += s['osszeg']
    for (pn, st), (n, o) in sorted(kc.items()):
        a(f"    {pn} {st:<16} {n:>6} db  {osszeg_hu(o):>20} {pn}")
    a(f'  Banki kivonat-azonosítók (befolyási napok): {len(ex.kivonatok)}')
    a(f"  Részteljesítések: {sum(1 for s in ex.bejovo + ex.kimeno_lista if s.get('reszt'))}")
    a('')
    a('MÉG FIZETENDŐ / KINTLÉVŐ (a DOS szerint, pénznemenként)')
    a('-' * 72)
    for cim, lista, mezo in (('Bejövő (tartozásunk)', ex.bejovo, 'atutalt'), ('Kimenő (kintlévőség)', ex.kimeno_lista, 'befolyt')):
        g = collections.defaultdict(lambda: [Decimal(0), Decimal(0)])
        for s in lista:
            g[s['penznem']][0] += s['osszeg']
            g[s['penznem']][1] += s['osszeg'] if s['fizetes'] == 'tulfizetett' else s[mezo]
        for pn, (o, f) in sorted(g.items()):
            a(f"  {cim:<22} {pn}:  összesen {osszeg_hu(o):>18}   fizetett {osszeg_hu(f):>18}   NYITOTT {osszeg_hu(o - f):>16}")
    a('')
    a('IMPORTÁLÁS phpMyAdminban')
    a('-' * 72)
    a('  1. Készíts mentést: BaninaPRO → Admin → Adatbázis-mentések → Mentés most.')
    a('  2. phpMyAdmin → bal oldalt a BaninaPRO adatbázisa → felül „Importálás”.')
    a('  3. A fájlokat EGYENKÉNT, a sorszámuk szerinti sorrendben töltsd be:')
    for f, m in fajlok:
        a(f'       {f:<40} {m / 1024 / 1024:6.2f} MB')
    a('     A 00_korabbi_adatok_torlese.sql TÖRLI a jelenlegi cégeket, kötéseket, számlákat, utalásokat –')
    a('     a korábbi DOS-importot is. A felhasználók, passkey-ek és beállítások megmaradnak.')
    a('     Védelem: ha a korábbi DOS-import óta valaki a BaninaPRO-ban rögzített vagy módosított valamit,')
    a('     a 00-s fájl nem töröl, hanem hibával megáll (ezek a változások elvesznének) – ilyenkor szólj.')
    a('  4. Minden fájl egy tranzakció, és az elején ellenőrzi az előfeltételeket. Ha egy fájl')
    a('     „HA ITT HIBA VAN: …” szövegű lekérdezésnél hibát ad („Subquery returns more than 1 row”),')
    a('     akkor a szövegben leírt ok miatt állt meg, és belőle SEMMI nem került be.')
    a('  5. Végül futtasd a 09_ellenorzes.sql-t: minden sor eredménye „OK” kell legyen. A cégenkénti és')
    a('     utalásonkénti DOS-összevetésnél csak az eltérések jelennek meg – ott az összesítő soron kívül')
    a('     egyetlen sor sem lehet.')
    a('  A korábbi 10_cegek_osszevonasa.sql és 11_storno_torlese.sql már NEM kell: ez az import eleve')
    a('  összevont cégekkel, STORNO-tételek nélkül készül.')
    a('  Ha a phpMyAdmin feltöltési korlátja kisebb a fájlméretnél, a konverter kisebb')
    a('  fájlokat is tud készíteni:  python3 baninapro_export.py … --max-mb 2')
    a('')
    a('HOGYAN KÉPEZŐDNEK LE AZ ADATOK')
    a('-' * 72)
    a('  Bejövő devizás számla (IMSZLA) → kötés: a DOS kötésszám (pl. 0-26-1234 = régi kötés ID),')
    a('    cégenként és pénznemenként; számlaszám = a szállító számlaszáma; régi K azonosító =')
    a('    a DOS azonosító utolsó 4 jegye (pl. 81N6); BESZÁM = a DOS BE/BH-szám, változatlanul.')
    a('    Összeg = a DOS „Összesen”. Fizetve, ha a DOS-ban van „Átutalva” összeg; a fizetés dátuma az, amit a DOS')
    a('    „Dátuma” oszlopa mutat: a TERHELÉS napja (ha a DOS-ban még nincs terhelés – 00.00.00 –, üres). Az utalás a')
    a('    számla „Bizonylat” mezőjében álló DOS-devizautalás.')
    a('  Bejövő forintos számla (FORINT) → cégenkénti, évenkénti ARCHÍV kötésbe (a DOS-ban nincs')
    a('    kötéshez rendelve); régi K azonosító = a DOS forintszámla-sorszáma (F-…). Kelt = a DOS lista „Kelte”')
    a('    oszlopa (erre szűr az időszak is). Fizetve, ha a DOS-ban forintutalás tartozik hozzá; a fizetés dátuma')
    a('    a DOS „Átutalva” oszlopa. STORNO: amit a DOS listája „STORNO”-ként jelöl. A „Kifizethető: NEM”')
    a('    jelölésű számla a DOS listáján normál, át nem utalt sor, ezért fizetendőként, megjegyzéssel került át.')
    a('  Utalások: egy DOS-utalás = egy BaninaPRO-utalás, UTALVA státusszal, a DOS-utalás terhelési napjával')
    a('    (forintnál az utalás napjával; ha a DOS-ban még nincs terhelés, dátum nélkül) és')
    a('    „DOS devizautalás #…” / „DOS forintutalás #…” banki hivatkozással. A DOS-ban az utalással a számla')
    a('    átutaltnak számít, ezért a BaninaPRO-ban sincs „utaláshoz adva” (folyamatban lévő) állapot.')
    a('    Ha egy DOS-utalás 2026 előtt kelt számlát is fizetett, azok nem kerültek át – az utalás megjegyzése')
    a('    ezt összeggel együtt jelzi (a BaninaPRO-utalás összege ezért kisebb a DOS-beli utalt összegnél).')
    a('    Negatív összegű (jóváíró) számla fizetve → BESZÁMÍTVA.')
    a('  Kimenő számla (IMELSZ) → számlaszám a könyvelési formátumban: KE-1044/2026, KH-12/2025;')
    a('    befolyt → FIZETVE a befolyás dátumával, „DOS ÉÉÉÉ.HH.NN PÉNZNEM” bankkivonat-azonosítóval;')
    a('    részben befolyt → NYITOTT + részteljesítés.')
    a('  Fizetési határidő: a DOS-beli határidő; ha a DOS-ban nincs megadva, a számla kelte (a BaninaPRO-ban')
    a('    kötelező mező) – a megjegyzésben jelölve. Ha a DOS-ban nem létező nap van begépelve (pl. 2026.02.30),')
    a('    a hónap utolsó napja kerül be, és a megjegyzés a DOS-beli értéket mutatja. Más dátum vagy összeg nem')
    a('    kitalált: minden érték a DOS-ból származik.')
    a('  0 összegű DOS-rekordok (felülírt/érvénytelen sorok) nem kerülnek át – pénzügyi hatásuk nincs.')
    a('  Cégek: a DOS-beli név marad. Azonos adószámú partnerek → egy cég; eltérő adószám → külön cég')
    a('    (ha a nevük is azonos, a név mögé zárójelben az adószám kerül). Partnerkód = a DOS-kód')
    a('    (szállítónál pl. NL-1238, ügyfélnél U- + az adószám-kulcs) – a céglistában „P…” jelöléssel látszik.')
    a('  Minden tétel megjegyzésében ott vannak a BaninaPRO-ban oszlop nélküli DOS-adatok.')
    a('')
    if ex.figyelmeztetesek:
        a(f'A DOS-BAN TALÁLT ADATHIBÁK / FIGYELMEZTETÉSEK ({len(ex.figyelmeztetesek)} db) – a BaninaPRO a DOS-t tükrözi,')
        a('a DOS-ban érdemes ellenőrizni (és ha ott javítjátok, egy újrafuttatás a BaninaPRO-t is javítja)')
        a('-' * 72)
        for x in ex.figyelmeztetesek:
            a('  • ' + x)
        a('')
    if ex.masodpeldany_lista:
        a(f'KIFIZETETT SZÁMLA KI NEM FIZETETT MÁSODPÉLDÁNYA – NEM KERÜLT ÁT ({len(ex.masodpeldany_lista)} db)')
        a('A DOS-ban ugyanaz a számla kétszer szerepel; az egyik példányt kifizették, ezért a számla ki van fizetve, és')
        a('nem szerepelhet nyitottként (a felhasználó döntése, 2026.10.07). A kifizetett példány átkerült.')
        a('-' * 72)
        for x in ex.masodpeldany_lista:
            a('  • ' + x)
        a('')
    if ex.storno_lista:
        a(f'STORNO-TÉTELEK – NEM KERÜLTEK ÁT ({len(ex.storno_lista)} db; a DOS a devizás és kimenő listáin nem mutatja őket,')
        a('a forintos listán „STORNO” címkével jelöli)')
        a('-' * 72)
        for forras, leiras in ex.storno_lista:
            a(f'  {forras:<7} {leiras}')
        a('')
    hely_c = [c for c in ex.cegek if c['helyorzo']]
    if hely_c:
        a('ÁTNEVEZENDŐ / ELLENŐRIZENDŐ CÉGEK (a BaninaPRO-ban: Cégek → a cég → szerkesztés)')
        a('-' * 72)
        szamlak = collections.Counter()
        idoszak = {}
        for s in ex.bejovo + ex.kimeno_lista:
            c = s['ceg']
            szamlak[c['id']] += 1
            d = s['kelt']
            lo, hi = idoszak.get(c['id'], (d, d))
            idoszak[c['id']] = (min(lo, d), max(hi, d))
        for c in hely_c:
            lo, hi = idoszak.get(c['id'], (None, None))
            a(f"  {c['nev']}: {szamlak[c['id']]} számla, {datum_hu(lo)} – {datum_hu(hi)}")
        a('')
    if ex.P.kezi_eredmeny:
        a('KÉZI CÉGÖSSZEVONÁSOK (DOS_KINYERES/ceg_osszevonasok.txt, a felhasználó döntése) – ebben az importban már összevonva')
        a('-' * 72)
        for cel, forras, nev, van_a, van_b in ex.P.kezi_eredmeny:
            allapot = 'összevonva' if van_a and van_b else ('csak az egyik szerepel az időszakban' if van_a or van_b else 'egyik sem szerepel az időszakban')
            a(f'  {nev}:  P{forras} → P{cel}  ({allapot})')
        a('')
    if ex.P.hasonlo_nevek:
        a('HASONLÓ NEVŰ CÉGEK – külön cégként kerültek át, a DOS-beli nevükkel (eltér az adószám, vagy legalább')
        a('az egyiknél nincs adószám a DOS-ban). A Partnerkód (P…, a DOS-kód) különbözteti meg őket:')
        a('-' * 72)
        for v in ex.P.hasonlo_nevek:
            a('  ' + '  |  '.join(f"{c['nev']} [P{c['partnerkod'] or '–'}" + (f", adószám {c['adoszam']}" if c['adoszam'] else '') + ']' for c in v))
        a('')
    a('ÉSZREVÉTELEK AZ ADATOKBAN')
    a('-' * 72)
    for k, n in sorted(ex.megjegyzesek.items()):
        if 'STORNO' in k:
            continue
        a(f'  {k}: {n}')
        teljes = '0 összegű' in k        # ezek a DOS-listákban 0,00 soként látszanak – mind felsorolva
        for p in ex.peldak.get(k, [])[:None if teljes else 5]:
            a(f"      {'' if teljes else 'pl. '}{p}")
    a('')
    a('NEM KERÜLT ÁT')
    a('-' * 72)
    a(f'  A {datum_hu(ex.tol)} előtt kelt számlák (kérésre), a STORNO-tételek, a 0 összegű DOS-rekordok, a számlák')
    a('  tételsorai (IMSZTET, IMELTET – a BaninaPRO-ban nincs számlatétel), a vám- és engedélyadatok (IMVAM,')
    a('  IMENG, VPOP), valamint a hálózati meghajtó EXPORT mappájának különálló, 1995–96-os adatai.')
    a('')
    a('ÚJRAFUTTATÁS (pl. a DOS rendszer végleges leállításakor, friss adatokkal)')
    a('-' * 72)
    a('  Másold le újra a hálózati meghajtó KULKER\\ADATOK mappáját, majd:')
    a('    cd DOS_KINYERES')
    a('    python3 baninapro_export.py --adatok "<a friss ADATOK mappa>" --ki ../BANINAPRO_IMPORT')
    a('  Ha a konverter olyan DOS-adatot talál, amelynek a szabálya még nincs ellenőrizve (pl. fuvarköltséges')
    a('  bejövő számla, ismeretlen deviza), nem készít félig jó fájlokat, hanem megáll és megírja, mit talált.')
    with open(os.path.join(mappa, 'OLVASSEL.txt'), 'w', encoding='utf-8', newline='\n') as f:
        f.write('\n'.join(L) + '\n')
    return '\n'.join(L)


def osszevonasok_beolvas(utvonal):
    """Kézi cégösszevonások: soronként 'cél partnerkód | összevonandó partnerkód | név' (# = megjegyzés)."""
    ki = []
    if not utvonal or not os.path.exists(utvonal):
        return ki
    with open(utvonal, encoding='utf-8') as f:
        for i, sor in enumerate(f, 1):
            sor = sor.split('#', 1)[0].strip()
            if not sor:
                continue
            r = [x.strip() for x in sor.split('|')]
            if len(r) != 3 or not all(r):
                raise ValueError(f'{utvonal} {i}. sor: „cél partnerkód | összevonandó partnerkód | név” formátum kell')
            ki.append(tuple(r))
    return ki


def main():
    ap = argparse.ArgumentParser(description='DOS (KULKER) → BaninaPRO import SQL')
    ap.add_argument('--adatok', required=True, help='a DOS program ADATOK mappája (F:\\KULKER\\ADATOK másolata)')
    ap.add_argument('--ki', required=True, help='kimeneti mappa az SQL fájloknak')
    ap.add_argument('--tol', default='2026-01-01', help='ettől a keltezéstől (ÉÉÉÉ-HH-NN, beleértve)')
    ap.add_argument('--ig', default=datetime.date.today().isoformat(), help='eddig a keltezésig (ÉÉÉÉ-HH-NN, beleértve)')
    ap.add_argument('--max-mb', type=float, default=8.0, help='egy SQL fájl legnagyobb mérete (MB)')
    ap.add_argument('--osszevonasok', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'ceg_osszevonasok.txt'),
                    help='kézi cégösszevonások listája (alapértelmezés: a konverter melletti ceg_osszevonasok.txt)')
    a = ap.parse_args()
    tol = datetime.date.fromisoformat(a.tol)
    ig = datetime.date.fromisoformat(a.ig)
    ex = Export(a.adatok, tol, ig, osszevonasok_beolvas(a.osszevonasok))
    ex.futtat()
    fajlok = sql_fajlok(ex, a.ki, a.max_mb)
    print(jelentes(ex, fajlok, a.ki))


if __name__ == '__main__':
    main()
