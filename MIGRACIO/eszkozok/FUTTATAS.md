# DOS + IB System migráció – futtatás

A migráció **friss adatbázist** épít a DOS-ból és az IB System kimenő exportjából (a felhasználó döntése, 2026-10-16: az addigi
BaninaPRO-adatbázis tesztüzem volt). Egy parancs, kb. 3 perc; bármikor megismételhető (pl. ha a DOS-ban még rögzítenek valamit).

Minden a `MIGRACIO/eszkozok` mappából fut. Docker Desktopnak futnia kell (`open -a Docker`); más nem kell a gépen.

## 0. Új gépen (egyszer, internettel)

```sh
./kornyezet.sh elokeszit      # képek (mariadb:10.11, python:3.12-slim, az app képe) + önteszt kitalált adatokon
```

## 1. Bemenetek (a MIGRACIO mappában – üzleti adat, a git nem látja)

| Mappa | Tartalom |
|---|---|
| `DOS_ADATOK_<dátum>/` | a DOS-program mappája; az `Adatok` almappa a hálózati F:\KULKER\ADATOK másolata (`DOS_MAPPA`) |
| `IB_SYSTEM ADATOK/` | az IB System kimenő „Számla napló” xlsx-e, 2026.01.01-től (`IB_FAJL`) |
| `4_EREDMENY/ceg_osszevonasok_teljes.txt` | a jóváhagyott cégösszevonások (`cél partnerkód | összevonandó partnerkód | név`), és a `# KÜLÖN MARAD:` sorok (`OSSZEVONAS`) |

Más mappa vagy fájl: `DOS_MAPPA=… IB_FAJL=… OSSZEVONAS=… ./migral.sh <db>` (a MIGRACIO mappához képest).

## 2. Futtatás

```sh
./migral.sh vegso_1016        # az adatbázis neve új legyen (kisbetű, szám, aláhúzás)
```

Lépések (a részletek a `migral.sh` elején):
1. DOS → a 2026.10.07-i, a DOS saját listáival igazolt exportáló (`dos/baninapro_export.py`): 2026.01.01-től minden számla,
   felső dátumhatár nélkül (a jövőbeli keltű is), a jóváhagyott cégösszevonásokkal.
2. Üres adatbázis a repó `sql/schema.sql`-jéből (a kezdő admin felhasználóval) + az export 01–08 fájljai + a 09 DOS-ellenőrzés
   (darabszámok, cégenkénti és utalásonkénti DOS-egyenlegek: mind OK kell legyen, különben megáll).
3. IB System kimenő összefésülés (`php/ib_import.php --vevo=ib --dupla=torol`), a szabályokat lásd lent.
4. Ellenőrzés: `php/ellenorzes.php` (HIBA 0, különben megáll), független DOS-ellenőrzés a tiszta DOS-állapoton és a végleges
   adatbázison (`dos/fuggetlen_ellenorzes.py`, saját bájtpozíciós DOS-olvasó), az IB újraelemzése a kész adatbázison.
5. Mentés az app formátumában (`MIGRALT_SQL_EREDMENY_ADATBAZIS/BaninaPRO_migralt_<dátum>.sql`, phpMyAdminban importálható) és
   visszatöltési próba (0 eltérés kell). A korábbi eredmény a `4_EREDMENY/regi_eredmenyek/` mappába kerül.
6. Jelentés (`php/jelentes.php`): `JELENTES.txt` (mi került át, a döntések szerint mi változott, DOS-adathibák, ellenőrzések),
   `OLVASS_EL.txt` (betöltés phpMyAdminnal), `DOS_ATVETEL_RESZLETEK.txt` (az exportáló saját jelentése).
   Ha a `4_EREDMENY/<db>/kezi_ellenorzesek.txt` létezik, a sorai a jelentés végére kerülnek (kézi próbák, pl. phpMyAdmin).

## A kimenő összefésülés szabályai (a felhasználó döntései, 2026-10-16)

- **Bejövő:** csak a DOS (IB-ből bejövő nincs).
- **Kimenő:** az IB a pontos a számlaszámban, összegben, dátumokban és a vevőben; a DOS a fizetésben.
  - Ami csak az IB-ben van: NYITOTT. A 0 összegű IB-számla is bekerül (NYITOTT, 0 hátralékkal).
  - A DOS-ban teljesen kifizetett számla kifizetett marad, akkor is, ha az IB-összeg néhány centtel más („ha ki lett fizetve,
    ne szerepeljen nyitottként”). A DOS-ban részben fizetett számla FIZETVE, ha a DOS-befolyás az IB-összeget teljesen fedezi.
  - Minden IB-partner a saját cégéhez: 1) azonos adószám-törzs, 2) ahol a számlái vannak (ha az adószám nem mond ellent, vagy sok
    számla van ott), 3) azonos név, 4) új cég. A DOS-ban más vevőnél rögzített számla az IB szerinti céghez kerül.
  - A DOS-ban elírt számlaszám, amely az IB-ben egy MÁS cég MÁS összegű számlájáé: „ (DOS)” jelölést kap (felszabadul).
  - A DOS-ban kétszer rögzített számla nyitott, IB-ben nem szereplő másodpéldánya törlődik, ha a kifizetett ikre (azonos cég,
    összeg, nap) az IB-ben megvan.
  - A számlaszám írásmódja az IB szerint (a DOS toldaléka / írásjele nélkül), ha a szám szabad.
- **Cégösszevonás:** azonos adószám (automatikus), valamint a felhasználó által jóváhagyott párok; név alapján soha magától.
  Jelöltek: `php/ceg_hasonlo.php`, IB-partner bizonyíték: `php/ib_partner_elemzes.php`.

## Betöltés a szerveren

A felhasználó phpMyAdminnal tölti be (`OLVASS_EL.txt`): a BaninaPRO adatbázisa (`baninapr_DATA`) → Importálás → a .sql fájl.
A fájl minden táblát eldob és újra létrehoz (a felhasználókat is: utána csak a kezdő admin van). A szerver phpMyAdminjának
feltöltési korlátja 64 MB; a fájl kb. 14 MB. Kipróbálva phpMyAdmin + MariaDB 10.11 alatt: kb. 10 mp, rekordra pontos.

## Delta-átvétel (alternatíva, élő adatbázisra)

Ha egyszer egy már használt BaninaPRO-adatbázisra kell a DOS változásait rávezetni (nem friss építés):
`dos_koztes.py` (DOS → köztes JSON a 10-07-i exportáló számításaival; `--elozo` a korábbi DOS-állapot köztes fájlja az átnevezett
rekordokhoz) → `php/dos_atvetel.php --szaraz`, majd élesben. A 10-07-i állapoton igazolva: a régi DOS-ból 15 788 rekord
„egyezik”, 0 eltérés; a mai DOS-ból a valódi változások (új tétel, fizetés, javítás) jönnek. Cégösszevonás élő adatbázison:
`php/ceg_osszevon.php <parok.json>` (DOS-kódok szerint). A formátum: `DOS_LEKEPEZES.md`.
