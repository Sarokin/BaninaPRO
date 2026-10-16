"""
A DOS-os KULKER / IMPORT.EXE program adatfájljainak olvasója.

Az adatfájlok TurboPower B-Tree Filer formátumúak (Turbo Pascal):
  * <TÁBLA>.DAT  – fix hosszú rekordok; a 0. rekord a fejléc
                   (FirstFree, NumberFree, rekordszám, rekordhossz, kulcsok száma),
                   minden rekord elején 4 bájtos törlésjelző (0 = élő rekord)
  * <TÁBLA>.IDX  – indexek (nem kellenek, minden adat a .DAT-ban van)
  * <TÁBLA>.DIA  – hálózati zárolófájl (nincs benne adat)

Mezőtípusok a rekordokban:
  * string[N]  – 1 hosszbájt + N karakter (a hosszbájt utáni rész szemét lehet)
  * Real       – 6 bájtos Turbo Pascal lebegőpontos szám
  * dátum      – 3 bájt: év (2 jegy), hó, nap
  * partnerkulcs – 11 karakteres string: országkód (2) + string[8] (partnerkód)

Kódlap: a program CP437 alapú, magyar betűkkel (Á=0x8F, ő=0x93, ű=0x96 …),
a begépelt adatokban viszont CP852 kódok is előfordulnak (ő=0x8B, Á=0xB5 …).
A kettő nem ütközik, ezért egy egyesített táblával minden ékezet helyreáll.
"""
import datetime
import os
import struct
from decimal import Decimal, ROUND_HALF_UP

# ---------------------------------------------------------------------------
#  Kódlap
# ---------------------------------------------------------------------------
_KODLAP = {b: bytes([b]).decode('cp437') for b in range(256)}
_KODLAP.update({
    # CWI-2 jellegű magyar kiosztás (a program saját képernyőfontja)
    0x8F: 'Á', 0x8D: 'Í', 0x93: 'ő', 0x95: 'Ó', 0x96: 'ű', 0x97: 'Ú', 0x98: 'Ű', 0xA7: 'Ő',
    # CP852 (a felhasználók által más billentyűzetmeghajtóval begépelt adatok)
    0x8B: 'ő', 0x8A: 'Ő', 0xFB: 'ű', 0xEB: 'Ű', 0xB5: 'Á', 0xD6: 'Í', 0xE0: 'Ó', 0xE9: 'Ú',
    # 0xFF (CP437 nem törő szóköz) a nevekben aposztróf helyett szerepel (pl. „Farmer's Best”)
    0xFF: "'",
})
for _b in range(0x20):
    _KODLAP[_b] = ' '          # vezérlőkarakterek a szövegben → szóköz


def szoveg(b: bytes) -> str:
    return ''.join(_KODLAP[x] for x in b)


# ---------------------------------------------------------------------------
#  Alaptípusok
# ---------------------------------------------------------------------------
def real48(b: bytes) -> float:
    """Turbo Pascal 6 bájtos Real → float."""
    if b[0] == 0:
        return 0.0
    m = int.from_bytes(b[1:6], 'little')
    elojel = -1 if m & (1 << 39) else 1
    m &= (1 << 39) - 1
    return elojel * (1 + m / 2 ** 39) * 2.0 ** (b[0] - 129)


def kerekit(x: float, jegy: int = 2) -> Decimal:
    q = Decimal(1).scaleb(-jegy)
    return Decimal(repr(x)).quantize(q, rounding=ROUND_HALF_UP)


def ev4(ev2: int) -> int:
    """Kétjegyű év → négyjegyű (az adatok 1991–2026 közöttiek)."""
    return 1900 + ev2 if ev2 >= 80 else 2000 + ev2


def datum3(b: bytes):
    """(év, hó, nap) bájtok → datetime.date; üres vagy érvénytelen → None."""
    e, h, n = b[0], b[1], b[2]
    if e == 0 and h == 0 and n == 0:
        return None
    try:
        return datetime.date(ev4(e), h, n)
    except ValueError:
        return None


def datum3_nem_letezo(b: bytes):
    """A DOS-ban begépelt, de nem létező nap (pl. 2026.02.30): (a hónap utolsó napja, 'ÉÉÉÉ.HH.NN'); egyébként None."""
    e, h, n = b[0], b[1], b[2]
    if not 1 <= h <= 12 or n < 1 or datum3(b) is not None:
        return None
    ev = ev4(e)
    utolso = (datetime.date(ev + (h == 12), h % 12 + 1, 1) - datetime.timedelta(days=1))
    return (utolso, f'{ev}.{h:02d}.{n:02d}') if n > utolso.day else None


def pstring(r: bytes, o: int, n: int) -> str:
    """string[n] az o offszeten (hosszbájt + karakterek), jobbról levágva."""
    L = min(r[o], n)
    return szoveg(r[o + 1:o + 1 + L]).rstrip()


def partnerkulcs(r: bytes, o: int) -> str:
    """11 bájtos partnerkulcs: 0x0B, országkód (2), 0x08, kód (8) → 'NL-00001238'."""
    if r[o] == 0:
        return ''
    orszag = szoveg(r[o + 1:o + 3]).strip()
    kod = pstring(r, o + 3, 8).strip()
    if not orszag and not kod:
        return ''
    return f'{orszag}-{kod}'


# ---------------------------------------------------------------------------
#  Táblaleírások: (mezőnév, offszet, típus, méret)
#    s = string[méret], r = Real, d = dátum, w = Word, b = Byte, k = partnerkulcs
# ---------------------------------------------------------------------------
TABLAK = {
    # Külföldi partnerek (szállítók) – kulcs: országkód + 8 jegyű kód
    'VEVO': [
        ('nev', 30, 's', 40), ('cim1', 71, 's', 20), ('cim2', 92, 's', 30),
        ('telefon', 123, 's', 10), ('fax', 134, 's', 10), ('egyeb1', 145, 's', 10),
        ('bank', 156, 's', 40), ('bankszamla', 201, 's', 27), ('cim3', 229, 's', 18),
        ('orszag', 248, 's', 2), ('kod', 251, 's', 8),
    ],
    # Ügyfelek (belföldi és külföldi vevők, forintos szállítók) – kulcs: adószám
    'UGYF': [
        ('nev', 30, 's', 40), ('varos', 71, 's', 20), ('cim', 92, 's', 30),
        ('telefon', 123, 's', 10), ('fax', 134, 's', 10), ('egyeb1', 145, 's', 10),
        ('eu_adoszam', 156, 's', 40), ('irsz', 201, 's', 4), ('bankszamla', 206, 's', 22),
        ('egyeb2', 229, 's', 18), ('adoszam', 248, 's', 11),
    ],
    # Bejövő devizás (import) számlák. A DOS listáinak oszlopai (… Áruérték, Fuvarköltség, Összesen, Átutalva, Dátuma):
    #   osszesen (218) = a számla összege („Összesen”), atutalt (176) = a ténylegesen átutalt összeg („Átutalva”,
    #   0 = még nincs átutalva), utalas_datum = „Dátuma”, bizonylat = a fizető DOS-devizautalás (KEGY) azonosítója.
    'IMSZLA': [
        ('azon', 4, 's', 11), ('szamlaszam', 16, 's', 10), ('bizonylat', 27, 's', 15),
        ('partner', 43, 'k', 11), ('beszam', 55, 's', 10), ('aru', 66, 's', 35),
        ('vtsz', 102, 's', 7), ('f110', 110, 's', 2), ('f113', 113, 's', 2),
        ('jarmu', 116, 's', 20), ('deviza', 137, 's', 3), ('w141', 141, 'w', 2),
        ('arfolyam', 143, 'r', 6),
        ('kelt', 149, 'd', 3), ('hatarido', 152, 'd', 3), ('d155', 155, 'd', 3),
        ('utalas_datum', 158, 'd', 3), ('terheles_datum', 161, 'd', 3),
        ('d164', 164, 'd', 3), ('d167', 167, 'd', 3),
        ('aruertek', 170, 'r', 6), ('atutalt', 176, 'r', 6),
        ('fuvarktg', 182, 'r', 6), ('r188', 188, 'r', 6),
        ('forintertek', 194, 'r', 6), ('forintertek_e', 200, 'r', 6),
        ('fuvar_forint', 206, 'r', 6), ('fuvar_forint_e', 212, 'r', 6),
        ('osszesen', 218, 'r', 6), ('r224', 224, 'r', 6), ('r230', 230, 'r', 6),
        ('b236', 236, 'b', 1), ('storno', 237, 'b', 1),      # 237. bájt = 1: a DOS-ban STORNO (sztornózott) tétel
    ],
    # Bejövő forintos számlák (belföldi szállítók, szolgáltatók). A DOS „Bejövő forint számlák” listája szerint:
    #   kelt (160) = „Kelte” (erre szűr az időszak), teljesites (71), utalas_datum = „Átutalva”,
    #   storno (198) = 1: a listán „STORNO”; kifizetheto (75) = az űrlap „Kifizethető ?” mezője (0 = nem).
    'FORINT': [
        ('szamlaszam', 4, 's', 15), ('adoszam', 20, 's', 11), ('partner_bankszamla', 32, 's', 18),
        ('sorszam', 51, 's', 5), ('kotes', 57, 's', 7), ('f65', 65, 's', 5),
        ('teljesites', 71, 'd', 3), ('koltsegnem', 74, 'b', 1), ('kifizetheto', 75, 'b', 1),
        ('utalas_azon', 76, 's', 6), ('f83', 83, 's', 10),
        ('megnevezes', 94, 's', 30), ('megnevezes2', 125, 's', 30), ('me', 156, 's', 3),
        ('kelt', 160, 'd', 3), ('hatarido', 163, 'd', 3), ('utalas_datum', 166, 'd', 3),
        ('d169', 169, 'd', 3), ('afa_kulcs', 172, 'b', 1),
        ('r174', 174, 'r', 6), ('netto', 180, 'r', 6), ('afa', 186, 'r', 6), ('brutto', 192, 'r', 6),
        ('storno', 198, 'b', 1),
    ],
    # Forintos utalások (a FORINT számlák kifizetése)
    'IMUTAL': [
        ('azon', 4, 's', 6), ('adoszam', 20, 's', 11), ('partner_bankszamla', 32, 's', 18),
        ('sajat_bankszamla', 51, 's', 18), ('f70', 70, 's', 4),
        ('datum', 75, 'd', 3), ('datum2', 78, 'd', 3),
        ('r81', 81, 'r', 6), ('osszeg', 87, 'r', 6), ('r93', 93, 'r', 6),
        ('kozlemeny', 99, 's', 35),
    ],
    # Devizás utalások (az IMSZLA számlák kifizetése)
    'KEGY': [
        ('azon', 4, 's', 6), ('partner', 20, 'k', 11), ('partner2', 32, 'k', 11),
        ('f44', 44, 's', 11), ('bank', 56, 's', 15), ('kotes', 72, 's', 7),
        ('deviza', 80, 's', 3), ('w84', 84, 'w', 2), ('arfolyam', 86, 'r', 6),
        ('datum', 92, 'd', 3), ('terheles', 95, 'd', 3),
        ('osszeg', 128, 'r', 6), ('forintertek', 134, 'r', 6), ('r152', 152, 'r', 6),
        ('szoveg1', 165, 's', 35), ('szoveg2', 201, 's', 35), ('szoveg3', 237, 's', 35),
        ('szoveg4', 273, 's', 35), ('szoveg5', 309, 's', 35), ('szoveg6', 345, 's', 35),
        ('szoveg7', 381, 's', 35), ('szoveg8', 417, 's', 35),
    ],
    # Kimenő számlák (belföldi és külföldi vevőknek)
    'IMELSZ': [
        ('azon', 4, 's', 12), ('partner', 17, 'k', 11), ('adoszam', 29, 's', 11),
        ('f41', 41, 's', 18), ('fizmod', 60, 's', 10), ('megrendeles', 71, 's', 10),
        ('f82', 82, 's', 15), ('egyeb', 98, 's', 30), ('sajat_adoszam', 129, 's', 11),
        ('sajat_bankszamla', 141, 's', 18), ('deviza', 160, 's', 3), ('w164', 164, 'w', 2),
        ('arfolyam', 166, 'r', 6),
        ('kelt', 184, 'd', 3), ('d187', 187, 'd', 3), ('d190', 190, 'd', 3), ('d193', 193, 'd', 3),
        ('hatarido', 196, 'd', 3), ('befolyas_datum', 199, 'd', 3), ('teljesites', 202, 'd', 3),
        ('r205', 205, 'r', 6), ('r211', 211, 'r', 6), ('r217', 217, 'r', 6),
        ('netto', 223, 'r', 6), ('afa', 247, 'r', 6), ('r253', 253, 'r', 6),
        ('r271', 271, 'r', 6), ('r277', 277, 'r', 6),
        ('brutto', 283, 'r', 6), ('befolyt', 289, 'r', 6),
        ('w482', 482, 'w', 2), ('afa_kulcs', 484, 'w', 2), ('b492', 492, 'b', 1),
        ('storno', 493, 'b', 1),                            # 493. bájt = 1: a DOS-ban STORNO (sztornózott) számla
        ('f499', 499, 's', 6), ('f506', 506, 's', 1), ('f511', 511, 's', 1),
    ],
}


def fejlec(utvonal: str):
    d = open(utvonal, 'rb').read(20)
    first_free, n_free, n_rec, reclen, n_keys = struct.unpack('<iiiii', d)
    return {'first_free': first_free, 'n_free': n_free, 'n_rec': n_rec, 'reclen': reclen, 'n_keys': n_keys}


def rekordok(utvonal: str):
    """Az élő (nem törölt) rekordok nyers bájtjai, sorszámmal."""
    adat = open(utvonal, 'rb').read()
    reclen = struct.unpack('<i', adat[12:16])[0]
    if reclen <= 0 or len(adat) % reclen:
        raise ValueError(f'{utvonal}: nem B-Tree Filer adatfájl (rekordhossz {reclen}, méret {len(adat)})')
    for i in range(1, len(adat) // reclen):
        r = adat[i * reclen:(i + 1) * reclen]
        if r[:4] == b'\0\0\0\0':
            yield i, r


def dekodol(r: bytes, leiras):
    sor = {}
    for nev, o, t, n in leiras:
        if t == 's':
            sor[nev] = pstring(r, o, n)
        elif t == 'r':
            sor[nev] = real48(r[o:o + 6])
        elif t == 'd':
            sor[nev] = datum3(r[o:o + 3])
            nl = datum3_nem_letezo(r[o:o + 3])
            if nl:                       # nem létező nap: a hónap utolsó napja, a DOS-beli érték a <mező>_dos alatt
                sor[nev], sor[nev + '_dos'] = nl
        elif t == 'w':
            sor[nev] = int.from_bytes(r[o:o + 2], 'little')
        elif t == 'b':
            sor[nev] = r[o]
        elif t == 'k':
            sor[nev] = partnerkulcs(r, o)
        else:
            raise ValueError(t)
    return sor


def tabla(adatok_mappa: str, nev: str):
    """Egy tábla összes élő rekordja dict-ként (a '_rekord' a fájlbeli sorszám)."""
    utvonal = None
    for f in os.listdir(adatok_mappa):
        if f.upper() == nev.upper() + '.DAT':
            utvonal = os.path.join(adatok_mappa, f)
    if utvonal is None:
        raise FileNotFoundError(f'{nev}.DAT nincs a(z) {adatok_mappa} mappában')
    leiras = TABLAK[nev]
    ki = []
    for i, r in rekordok(utvonal):
        sor = dekodol(r, leiras)
        sor['_rekord'] = i
        ki.append(sor)
    return ki
