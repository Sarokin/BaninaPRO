# DOS → BaninaPRO leképezés (a 2026.10.07-i DOS import visszafejtve)

A DOS-adatok 2026.10.06 21:00-i állapotát egy „DOS import” nevű program hozta át 2026.10.07 12:53–12:55 között,
egy tranzakcióban. A program nincs meg ezen a gépen. A szabályokat az általa írt adatokból és megjegyzésekből fejtettük
vissza (`php/ismert_dos.php` olvassa ki őket). A DOS-fájlok szerkezetét még nem láttuk. A program fajtája, a
táblák és a mezők neve a felderítés után derül ki: `dos_felderito.py`.

## DOS-rekordtípusok és kulcsaik

| DOS | Kulcs (példa) | BaninaPRO | Hol a kulcs |
|---|---|---|---|
| VEVO partner (szállító) | `HU-00000123` (ország + 8 jegy) | `cegek` | `megjegyzes` „DOS-kód: VEVO …”, `partnerkod` `HU-123` |
| UGYF partner (vevő, adószám szerint) | `12345678901`, `B01`, `5` (kézzel gépelt) | `cegek` | `megjegyzes` „DOS-kód: UGYF …”, `partnerkod` `U-<kód>` |
| kötés | `0-26-1234` (0-ÉÉ-sorszám, néha elírt: `0-29-…`, `0-26-,088`) | `kotesek`, cégenként és pénznemenként egy | `regi_kod` |
| kötés tétele (bejövő számla) | kötésszám + kisszám: `0-26-1234/12A3` | `bejovo_szamlak` | `regi_k` = kisszám; `megjegyzes` „DOS azonosító: …” |
| forintszámla (HUF költségszámla, kötés nélkül) | `F-30001` | `bejovo_szamlak` cégenként egy „ARCHÍV – DOS import 2026” kötésben | `regi_k` = F-szám; „DOS forintszámla: …” |
| devizautalás | `#012345` | `utalasok` (EUR) | `banki_hivatkozas` = „DOS devizautalás #012345” |
| forintutalás | `#054321` | `utalasok` (HUF) | `banki_hivatkozas` = „DOS forintutalás #054321” |
| kimenő számla | `KE-26 1126` (elírások: `KE-26 179,`, `KE26 126`, `KE-19 312`, `K-26 1787`) | `kimeno_szamlak`, számlaszám `KE-1126/2026` | „DOS azonosító: …” |
| befolyás (kimenő fizetése) | dátum + pénznem | `banki_kivonatok` „DOS 2026.04.20 EUR” | `azonosito` |

## Mezők és szabályok

- **Cég**: a VEVO és az UGYF rekordjaiból. Ugyanaz a partner több DOS-kóddal egy cég lett (pl. „UGYF 12345678901, VEVO
  HU-00000123, VEVO HU-00000456”). 8 esetben kézi összevonással, ezt a „Kézi összevonás: …” sor jelzi.
  - `adoszam`: a magyar 11 jegyű adószám tagolva (`12345678-1-23`).
  - `megjegyzes`: `DOS import (2026.10.07) · DOS-kód: … · Cím: … · Bank: …`.
- **Kötés**: egy BaninaPRO-kötés jut minden (DOS-kötésszám, cég, pénznem) hármasra. Egy DOS-kötésből (áru + fuvar)
  két-három BaninaPRO-kötés is lehet.
  - `megnevezes`: a tétel árujának neve („vegyes zöldség és gyümölcs”, „fuvar”).
  - `megjegyzes`: „DOS import (2026.10.07)”.
  - Év: a kötésszám ÉÉ-je (`0-25-…` → 2025), ha legfeljebb 1 évvel tér el a számla évétől. Egyébként a számla éve.
  - A kód sorrendje nagyjából a DOS rekordsorrendje.
- **Bejövő tétel**:
  - kelt = teljesítés, mert a DOS-ban egy dátum van; a határidő a DOS-ból jön.
  - Nem létező napnál (`26.04.31`) a hónap utolsó napja kerül be, a megjegyzésbe pedig: „A fizetési határidő a DOS-ban
    2026.04.31 (nem létező nap) – a hónap utolsó napja került be”.
  - A K sorszám a DOS rekordsorrendjét követi.
  - Kötésen belüli dupla számlaszám: „ [kisszám]” toldalék.
  - BESZÁM a DOS-ból (`BE-1000`).
  - `megjegyzes`: `DOS import · DOS azonosító: K/kisszám · Szállító DOS-kódja: … · Áru: … [· VTSZ · Jármű/fuvarozó ·
    Áruérték (DOS)] · Utalás: DOS devizautalás #… · utalás napja (DOS): <megbízás kelte> · terhelés (DOS): <terhelés |
    „még nincs (a DOS-ban 00.00.00)”>`, vagy `· A DOS-ban nincs átutalva`.
- **Fizetés (bejövő)**:
  - Ha a DOS-tételnek van utalása: FIZETVE, negatív összegnél BESZÁMÍTVA. `fizetve_datum` = az utalás terhelése (deviza) /
    utalás dátuma (forint); 00.00.00 terhelésnél NULL (10-06-án 591 ilyen volt).
  - Részben átutalt tételnél FIZETENDŐ marad, és egy `reszteljesitesek` sor jön létre a DOS-utalás nevével.
- **Utalás**:
  - Mindegyik UTALVA, `lezarva = 1`, MANUAL, `utalva_at`, `utalta`, `letrehozta` = NULL.
  - Év = az `utalva_datum` éve (ha nincs, 2026).
  - Cég = a számlák cége.
  - `megjegyzes` deviza: `DOS import · Megbízás kelte · Terhelés · Összeg (DOS) [· Kedvezményezett a DOS-ban ·
    Kedvezményezett bankja (törzsadat) · Bank/egyéb (utalás) · Kedvezményezett számlája] · Közlemény`.
  - `megjegyzes` forint: `Utalás dátuma · 2. dátum (terhelés) · Összeg (DOS) · Terhelt saját számla [· Kedvezményezett
    számlája] · Közlemény`.
- **Kimenő**:
  - Számlaszám: `<előtag>-<szám, ahogy a DOS-ban, a végéről a „,” / „*” nélkül>/<a kelt éve>`.
  - Összeg = bruttó; kelt = teljesítés; határidő a DOS-ból.
  - Teljes befolyásnál FIZETVE, a banki kivonat „DOS <dátum> <pénznem>” (dátumonként egy), `fizetve_datum` = a befolyás napja.
  - Részbefolyásnál NYITOTT, és egy részteljesítés jön létre („DOS import”).
  - Ha a befolyt összeg több a számlánál: FIZETVE, és FIGYELEM kerül a megjegyzésbe.
  - `megjegyzes`: `DOS import · DOS azonosító · Vevő adószáma (DOS) [· Megrendelés] [· Fizetési mód] · Nettó · ÁFA (0%) ·
    Bruttó · Befolyt: X (dátum) | Befolyás a DOS-ban nincs rögzítve [· FIGYELEM] [· Egyéb]`.
- **Napló**: minden rekordnak van egy `valtozasnaplo` sora, `muvelet` = IMPORT, `felhasznalonev` = „DOS import”,
  `leiras` = „DOS importból létrehozva: …”. Minden `letrehozta` mező NULL.

## Köztes formátum (`dos_koztes.json`) – ezt kell a DOS-fájlokból előállítani

A DOS-specifikus átalakító (holnap, a felderítés után) ezt írja. A `php/dos_atvetel.php` ebből dolgozik. A dátum lehet
DOS-szöveg (`26.04.31`, `00.00.00`) vagy ISO. Az összeg lehet `"2 159,00"` vagy `"2159.00"`. Az üres mező lehet null.

```json
{
  "forras": "DOS mappa, 2026.10.16",
  "partnerek": [ {"tabla": "VEVO|UGYF", "kod": "HU-00000123", "nev": "…", "cim": "…", "bank": "…", "adoszam": "…"} ],
  "kotesek":   [ {"kotesszam": "0-26-1234", "aru": "vegyes zöldség és gyümölcs"} ],
  "tetelek":   [ {"kotesszam": "0-26-1234", "kisszam": "12A3", "szallito": "DE-00000789", "szamlaszam": "…",
                  "kelt": "26.03.04", "teljesites": null, "hatarido": "26.04.31", "osszeg": "3564.00", "penznem": "EUR",
                  "beszam": "BE-1001", "aru": "…", "vtsz": null, "jarmu": null, "aruertek": null,
                  "utalas": {"tipus": "deviza|forint", "szam": "012345"} | null, "atutalt": "3564.00" | null} ],
  "forintszamlak": [ {"azon": "F-30001", "partner": "98765432109", "partner_tabla": "UGYF", "szamlaszam": "…",
                  "megnevezes": "…", "netto": "…", "afa_kulcs": 0, "afa": "…", "brutto": "130000", "kelt": "…",
                  "teljesites": null, "hatarido": "…", "bankszamla": null, "utalas": {"tipus": "forint", "szam": "054399"} | null} ],
  "utalasok":  [ {"tipus": "deviza", "szam": "012345", "penznem": "EUR", "osszeg": "…", "megbizas": "…", "terheles": "…" | null,
                  "kedv_bank": null, "bank_egyeb": null, "kedv_szamla": null, "kedvezmenyezett_megj": null, "kozlemeny": "…"},
                 {"tipus": "forint", "szam": "054321", "penznem": "HUF", "osszeg": "…", "utalas_datum": "…", "datum2": "…",
                  "terhelt_szamla": "11111111 (Bank)", "kedv_szamla": null, "kozlemeny": "…"} ],
  "kimeno":    [ {"azon": "KE-26 1126", "vevo": "B01", "kelt": "…", "teljesites": null, "hatarido": "…", "netto": "…",
                  "afa_kulcs": 0, "afa": "…", "brutto": "554.00", "penznem": "EUR", "fizmod": "ÁTUTALÁS",
                  "befolyt": "554.00" | null, "befolyt_datum": "…" | null, "egyeb": null, "megrendeles": null} ]
}
```

- A tételek sorrendje legyen a DOS rekordsorrendje, mert az új K sorszám ezt követi.
- A törölt DOS-rekordok (`_torolt = *`) NEM kerülnek a köztes fájlba.
- `atutalt`: a tételre a DOS-ban átutalt összeg. Ha kisebb a számlánál, részteljesítés lesz; ha nagyobb, FIGYELEM.

## A DOS-átvétel (`php/dos_atvetel.php`) szabályai

- A meglévő rekordot a DOS-kulcsa párosítja (lásd a táblázatot), az újat létrehozza a fenti formában.
- **Mezőnként dönt** a változásnapló alapján, ki nyúlt a mezőhöz október 7. óta:
  - senki: a DOS mostani értéke kerül be;
  - az IB System (Excel) import: az IB-érték marad, és nem ütközés (pl. a kimenő kelt);
  - egy munkatárs: ütközés, döntésre (a BaninaPRO-érték marad).
- **Fizetés**:
  - A FIZETENDŐ, utalásban nem lévő számla a DOS-utalással rendeződik.
  - UTALÁSHOZ ADVA (BaninaPRO-utalásban várakozó) számla + DOS-utalás = ütközés.
  - A 00.00.00 terhelésű utalás dátuma pótlódik, ha a DOS-ban azóta beírták, a számlák fizetési dátumával együtt.
- **IB-archív számla**: ha az új DOS-tétel már bent van az IB importból ARCHÍV kötésben (cég + pénznem + laza
  számlaszám + összeg), nem jön létre újra, hanem a DOS-kötésbe kerül. Az IB-adatai maradnak, a DOS-ból a kisszám és a
  fizetés jön. Ha több jelölt van, vagy a számla már utalásban szerepel: ütközés.
- **Áthelyezett DOS-tétel**: ha ugyanez a számla a DOS-ban másik kötésszám/kisszám alatt szerepel, és a régi kulcs
  eltűnt, nem jön létre másodszor (ütközés).
- **Törlés soha.** Ami a DOS-ból eltűnt, azt csak jelenti.
- `--szaraz`: ugyanaz a futás, a végén visszagörgetve (a jelentésben a pontos kódok látszanak).
- Megismételhető: a második futás 0 változást ad (tesztelve).

## Ismert DOS-adathibák (10-06)

- Lehetetlen dátumok (`2026.04.31`, `2026.02.30`).
- `00.00.00` terhelés (591 fizetett számla dátum nélkül).
- Egy 2005-ös teljesítés (`AB1234 [F-30002]`).
- Egy jövőbeli utalási dátum (2026.10.16).
- Elgépelt kötésszámok (`0-26-,088`, `0-29-…`).
- Elgépelt kimenő azonosítók (76 db).
- Kézzel gépelt UGYF-kulcsok (`5`, `HR`, `B01`).
- Túlfizetés-jelzések (2 kimenő, 1 bejövő).
