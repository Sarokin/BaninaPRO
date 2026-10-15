# DOS + IB System migráció – futtatás lépésről lépésre

Minden parancs a `MIGRACIO/eszkozok` mappából fut. Docker Desktopnak futnia kell (`open -a Docker`). Más nem kell a
gépen, a Python is konténerben fut.

A migráció a szerverrel azonos MariaDB 10.11-en, a repó saját PHP-kódjával fut a `bp-mig-db` / `bp-mig-app`
konténerekben. A fejlesztői (`baninapro-*`) és a szerver adatbázisához nem nyúl. Minden futás ÚJ adatbázisnevet kap,
semmit nem kell törölni. A lépések megismételhetők: a végső futás a legfrissebb élő mentésen pár perc.

## 0. Új gépen (MacBook) – még internettel, a helyszín előtt

```sh
git pull                                  # vagy: git clone https://github.com/Sarokin/BaninaPRO.git
cd MIGRACIO/eszkozok
./kornyezet.sh elokeszit                  # képek: mariadb:10.11, python:3.12-slim, az app képe (docker/Dockerfile) + önteszt
```

Az önteszt kitalált adatokon (`teszt/minta_koztes.json`) végigfuttatja a teljes láncot: DOS-átvétel kétszer, ellenőrzés,
felderítés szintetikus DBF-eken, mentés + visszatöltés. Ha a vége „ÖNTESZT: MINDEN RENDBEN”, a gép kész.
Később internet nélkül is megy, mert a képek már a gépen vannak.

```sh
./kornyezet.sh indit                      # a konténerek indítása (ha nem futnak)
```

## 1. Élő adatbázis betöltése

```sh
./kornyezet.sh betolt "../3_BANINAPRO_ELO_ADATBAZIS/<mentés>.sql" elo_0930    # + séma-egyeztetés a szerver telepítőjével
./kornyezet.sh php elo_0930 /migracio/eszkozok/php/ellenorzes.php             # kiinduló állapot (HIBA 0 kell)
./kornyezet.sh php elo_0930 /migracio/eszkozok/php/ismert_dos.php             # → 4_EREDMENY/elo_0930/ismert_dos.json
```

## 2. A DOS-mappa felderítése

```sh
./kornyezet.sh felderit elo_0930          # 1_DOS_PROGRAM → 4_EREDMENY/dos_felderites (felderites.txt, tablak/*.csv)
```

A Python-scriptek mindig így futnak: `./kornyezet.sh py /migracio/eszkozok/<script>.py …`. A `MIGRACIO` mappa a
konténerben a `/migracio` alatt látszik, a DOS-mappa csak olvashatóan.

Olvasd el a `felderites.txt` fájlt:
- melyik program (Clipper / FoxPro / …);
- melyik tábla mit tartalmaz (mező-felismerés, a kötésszám, kisszám, szállítókód stb. találati aránya);
- kódlap;
- a fejlécben a frissítés dátuma: melyik tábla változott 10-06 óta.

Ha nem DBF: a bájtkeresés megadja a rekordhosszt és a mezők eltolását.

## 3. DOS → köztes JSON (a felderítés után megírandó átalakító)

Az átalakító (`dos_koztes.py`) a `4_EREDMENY/dos_felderites/tablak/*.csv` fájlokból (vagy közvetlenül a DOS-fájlokból)
állítja elő a `dos_koztes.json`-t a `DOS_LEKEPEZES.md` „Köztes formátum” szerint.

**Mérce:** a régi (10-06 előtti) rekordokra a `dos_atvetel.php --szaraz` „egyezik”-et kell mondjon, eltérés nélkül.
Amíg ez nem 100%, az átalakító hibás.
- Próba: `./kornyezet.sh py /migracio/eszkozok/teszt/ir_ismertbol.py <ismert_dos.json> <ki.json> [--delta]` visszaépíti a 10-06-i állapotot. A 10-06-i mentésen 15788 egyezik, 0 eltérés (2026-10-16).
- A valódi DOS-ból a 10-06 óta valóban megváltozott rekordok javításként / fizetésként jelennek meg. Ezeket
  szúrópróbával ellenőrizd a DOS-ban.

## 4. DOS-átvétel

```sh
./kornyezet.sh php elo_0930 /migracio/eszkozok/php/dos_atvetel.php /migracio/4_EREDMENY/dos_koztes.json --szaraz   # előbb mindig szárazon
./kornyezet.sh php elo_0930 /migracio/eszkozok/php/dos_atvetel.php /migracio/4_EREDMENY/dos_koztes.json
```

A jelentés: `4_EREDMENY/elo_0930/dos_atvetel/dos_atvetel.txt|json`. A felhasználóval egyeztetendő:
- ütközések;
- a DOS-ból eltűnt rekordok;
- HIBA-sorok (pl. ismeretlen partner).

## 5. IB System import (előbb bejövő, aztán kimenő; mindegyik előbb szárazon)

```sh
./kornyezet.sh php elo_0930 /migracio/eszkozok/php/ib_import.php "/migracio/2_IB_SYSTEM/<bejövő>.xlsx" --szaraz
./kornyezet.sh php elo_0930 /migracio/eszkozok/php/ib_import.php "/migracio/2_IB_SYSTEM/<bejövő>.xlsx" [--csoportok=… --osszevon=… --felulir=…]
```

Átnézendő az `elemzes.json`-ban:
- `dontesre_var`: több összevonási jelölt (`--osszevon` JSON: `{"sorindex": számla_id}`);
- `vedett_kihagyva`: utalásban lévő számlák eltérései (`--felulir=mind|1,2,3`, csak a felhasználó döntése szerint);
- bizonytalan cégcsoportok (`--csoportok` JSON: `{"Excel-név": {"tipus": "db", "ceg_id": 123}}`);
- `felesleg`: a DB-ben van, a fájlban nincs. Ez csak teljes exportnál értelmes.

Az alapbeállítás ugyanaz, mint a weboldal Import oldalán (mérvadó: import, minden mező javítható, utalásban lévő nem).

## 6. Ellenőrzés, összevetés, átadás

```sh
./kornyezet.sh php elo_0930 /migracio/eszkozok/php/ellenorzes.php                  # HIBA 0 kell, különben nincs átadás
./kornyezet.sh betolt "../3_BANINAPRO_ELO_ADATBAZIS/<mentés>.sql" elotte_0930       # változatlan másolat az összevetéshez
./kornyezet.sh php elo_0930 /migracio/eszkozok/php/osszevet.php elotte_0930         # minden változás mezőnként + egyenlegek
./kornyezet.sh mentes elo_0930 "../4_EREDMENY/BaninaPRO_migralt_<dátum>.sql"        # az app saját mentőjével
./kornyezet.sh betolt "../4_EREDMENY/BaninaPRO_migralt_<dátum>.sql" vissza_0930     # visszatöltési próba
./kornyezet.sh php vissza_0930 /migracio/eszkozok/php/osszevet.php elo_0930         # 0 eltérés kell
./kornyezet.sh web vissza_0930                                                      # böngészőben: http://localhost:8090
```

A kész `.sql`-t a szerveren az „SQL IMPORT” ikon tölti be (betöltés előtt mentést készít, hiba esetén visszaállít).
A mentés és a visszatöltés között ne dolgozzon senki a BaninaPRO-ban.

## Kiprobálva (2026-10-15/16, a 10-06-i éles mentésen)

- **Betöltés és séma-egyeztetés:** 3,5 mp, az 1.24-es oszlopok pótolva.
- **IB-import:** a teljes éves kimenőnél 412 új számla, 5755 javítás, 9 összevonás, 5 felesleg. Ugyanezek a számok
  jöttek ki, mint a 10-13-i mérésnél. A bejövő minta 158 új számlát adott.
- **DOS-átvétel:**
  - visszaépített 10-06-i állapot: 15788 egyezik, 0 eltérés, 0 hiba;
  - szimulált delta: minden eset a várt eredményt adta (új cég, kötés, tétel, forintszámla, utalás, kimenő; javítás;
    fizetés; terhelés pótlása; ütközések; eltűnt rekord; IB-archív áthelyezés);
  - második futás: 0 változás;
  - IB után futtatva: 5753 kelt-, 85 határidő-, 51 teljesítés- és 10 összegjavítás érintetlen marad, 0 ütközés.
- **Mentés:** az app formátumában, `USE` nélkül. Visszatöltve 0 eltérés.
- **Ellenőrzés:** minden lépés után HIBA 0.
- **Önteszt** (kitalált adatok, `./kornyezet.sh onteszt`): 12/12 rendben, kb. 11 mp.
- **Új gép szimulálása:** friss GitHub-klónból, üres Dockerrel (`MIG_ELOTAG=bp-migproba MIG_PORT=8091 ./kornyezet.sh
  elokeszit`) az előkészítés és az önteszt 11 mp alatt lefutott, 12/12 rendben. Így indul a MacBookon is.

A valódi adatokon futott próbák (`teszt/probak/`) és az IB-mintafájlok csak az iMacen vannak, a gitben nincsenek
(üzleti adat). A holnapi munkához nem kellenek, mert a friss bemenetekből minden újra előáll.
