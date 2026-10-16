#!/bin/bash
# BaninaPRO – a teljes DOS + IB System migráció egy paranccsal, FRISS adatbázisba (a felhasználó döntése, 2026-10-16: az eddigi
# BaninaPRO-adatbázis tesztüzem volt, semmi nem kell belőle; a felhasználókat kézzel állítja vissza).
#
#   migral.sh <adatbázis név>      pl. migral.sh vegleges_1830
#
# Bemenet (a MIGRACIO mappában, a git nem látja – üzleti adat):
#   DOS_MAPPA     a DOS-program ADATOK mappája             (alap: DOS_ADATOK_20261016/Adatok)
#   IB_FAJL       az IB System kimenő „Számla napló” xlsx  (alap: IB_SYSTEM ADATOK/KE.xlsx)
#   OSSZEVONAS    a jóváhagyott cégösszevonások              (alap: 4_EREDMENY/ceg_osszevonasok_teljes.txt)
# Lépések:
#   1. DOS → a 2026.10.07-i, a DOS saját listáival igazolt exportáló (dos/baninapro_export.py): 2026.01.01-től MINDEN számla,
#      felső határ nélkül (a felhasználó kérése: a jövőbeli keltű is), a jóváhagyott cégösszevonásokkal.
#   2. üres adatbázis a repó sql/schema.sql-jéből (a kezdő admin felhasználóval) + az export 01–08 fájljai + a 09 DOS-ellenőrzés.
#   3. IB System kimenő összefésülés (php/ib_import.php): az IB a pontos a számlaszámban, összegben, dátumokban és a vevőben, a DOS a
#      fizetésben (--vevo=ib, --dupla=torol: a felhasználó döntései).
#   4. ellenőrzés (php/ellenorzes.php: HIBA 0), független DOS-ellenőrzés (saját bájtpozíciós olvasó), mentés az app formátumában,
#      visszatöltési próba (0 eltérés).
# Kimenet: MIGRALT_SQL_EREDMENY_ADATBAZIS/BaninaPRO_migralt_<dátum>.sql (phpMyAdminban importálható) + 4_EREDMENY/<db>/ jelentések.
set -euo pipefail

ESZK="$(cd "$(dirname "$0")" && pwd)"
MIG="$(cd "$ESZK/.." && pwd)"
REPO="$(cd "$MIG/.." && pwd)"
K="$ESZK/kornyezet.sh"
DB="${1:?Használat: migral.sh <adatbázis név>}"
DOS_MAPPA="${DOS_MAPPA:-DOS_ADATOK_20261016/Adatok}"
IB_FAJL="${IB_FAJL:-IB_SYSTEM ADATOK/KE.xlsx}"
OSSZEVONAS="${OSSZEVONAS:-4_EREDMENY/ceg_osszevonasok_teljes.txt}"
TOL=2026-01-01
IG=2079-12-31          # a DOS kétjegyű évet tárol (2000–2079): ez a felső határ nélküli időszak
KI="$MIG/4_EREDMENY/$DB"
PY_KEP=python:3.12-slim

hiba() { printf 'HIBA: %s\n' "$*" >&2; exit 1; }
lepes() { printf '\n== %s\n' "$*"; }
for f in "$DOS_MAPPA/IMSZLA.DAT" "$IB_FAJL" "$OSSZEVONAS"; do
    [[ -e "$MIG/$f" ]] || hiba "Hiányzik: MIGRACIO/$f"
done
mkdir -p "$KI"

lepes "1. DOS → export ($TOL-tól, felső határ nélkül)"
docker run --rm -v "$MIG":/migracio -w /migracio/eszkozok/dos "$PY_KEP" python -B baninapro_export.py \
    --adatok "/migracio/$DOS_MAPPA" --ki "/migracio/4_EREDMENY/$DB/export" --tol "$TOL" --ig "$IG" \
    --osszevonasok "/migracio/$OSSZEVONAS" > "$KI/export.log" 2>&1 || { tail -5 "$KI/export.log"; hiba "a DOS-export nem sikerült"; }
sed -n '/^MIT TARTALMAZ/,/^  Részteljesítések/p' "$KI/export/OLVASSEL.txt"

lepes "2. friss adatbázis: $DB (sql/schema.sql) + export 01–08"
"$K" betolt "$REPO/sql/schema.sql" "$DB" > "$KI/betolt.log" 2>&1 || { tail -5 "$KI/betolt.log"; hiba "az üres adatbázis nem jött létre"; }
for f in 01_cegek 02_kotesek 03_utalasok 04_bejovo_szamlak 05_banki_kivonatok 06_kimeno_szamlak 07_reszteljesitesek 08_valtozasnaplo_es_sorszamok; do
    for g in "$KI/export/$f"*.sql; do
        "$K" sql "$DB" < "$g" > /dev/null || hiba "$(basename "$g") betöltése nem sikerült"
    done
done
"$K" sql "$DB" < "$KI/export/09_ellenorzes.sql" > "$KI/09_ellenorzes.txt" 2>&1
OK="$(awk -F'\t' '$NF == "OK"' "$KI/09_ellenorzes.txt" | wc -l | tr -d ' ')"
ELTER="$( { grep 'ELTÉR' "$KI/09_ellenorzes.txt" || true; } | wc -l | tr -d ' ')"
echo "09 DOS-ellenőrzés: $OK OK, $ELTER ELTÉR"
[[ "$ELTER" -eq 0 && "$OK" -ge 18 ]] || hiba "a 09 DOS-ellenőrzés eltérést talált: $KI/09_ellenorzes.txt"

lepes "   pillanatkép a tiszta DOS-állapotról: ${DB}_dos"
printf 'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "${DB}_dos" | "$K" sql mysql
docker exec bp-mig-db sh -c "mariadb-dump -uroot -pmigracio_root --single-transaction '$DB' | mariadb -uroot -pmigracio_root '${DB}_dos'"

lepes "3. IB System kimenő összefésülés"
"$K" php "$DB" /migracio/eszkozok/php/ib_import.php "/migracio/$IB_FAJL" --vevo=ib --dupla=torol --jelentes="/migracio/4_EREDMENY/$DB/ib" \
    | tee "$KI/ib_import.txt" | grep -v '^vevő az IB szerint:' || hiba "az IB-import nem sikerült"

lepes "   záró ellenőrzés: az IB System újraelemzése a kész adatbázison (szárazon – semmi új, eltérő vagy más cégnél lévő nem lehet)"
"$K" php "$DB" /migracio/eszkozok/php/ib_import.php "/migracio/$IB_FAJL" --szaraz --jelentes="/migracio/4_EREDMENY/$DB/ib_zaro" > "$KI/ib_zaro.txt" 2>&1 || true
grep -E '^KE\.xlsx|adatsor' "$KI/ib_zaro.txt" | head -1

lepes "4. ellenőrzés"
"$K" php "$DB" /migracio/eszkozok/php/ellenorzes.php "/migracio/4_EREDMENY/$DB" > "$KI/ellenorzes.txt" 2>&1 || true
head -1 "$KI/ellenorzes.txt"
grep -q -- '– HIBA 0,' "$KI/ellenorzes.txt" || hiba "az adatbázis-ellenőrzés HIBÁT talált: $KI/ellenorzes.txt"

lepes "   független DOS-ellenőrzés (saját bájtpozíciós olvasó) – a tiszta DOS-állapoton és a végleges adatbázison"
for d in "${DB}_dos" "$DB"; do
    mkdir -p "$KI/tsv/$d"
    M=(docker exec bp-mig-db mariadb -uroot -pmigracio_root --default-character-set=utf8mb4 -B "$d" -e)
    "${M[@]}" "SELECT b.id, k.regi_kod, b.regi_k, k.penznem, k.archiv, c.id ceg_id, c.partnerkod, b.szamlaszam, b.kelt, b.teljesites_datum, b.fizetesi_hatarido, b.osszeg, b.statusz, b.fizetve_datum,
           u.banki_hivatkozas, u.utalva_datum, u.statusz ustat, u.ceg_id uceg, u.penznem upn, COALESCE(r.reszt,0) reszt, b.beszam, b.megjegyzes
      FROM bejovo_szamlak b JOIN kotesek k ON k.id=b.kotes_id JOIN cegek c ON c.id=k.ceg_id LEFT JOIN utalasok u ON u.id=b.utalas_id
      LEFT JOIN (SELECT szamla_id, SUM(osszeg) reszt FROM reszteljesitesek WHERE irany='BEJOVO' GROUP BY szamla_id) r ON r.szamla_id=b.id" > "$KI/tsv/$d/bejovo.tsv"
    "${M[@]}" "SELECT s.id, c.id ceg_id, c.partnerkod, s.penznem, s.szamlaszam, s.kelt, s.fizetesi_hatarido, s.osszeg, s.statusz, s.fizetve_datum, bk.azonosito, COALESCE(r.reszt,0) reszt, s.megjegyzes
      FROM kimeno_szamlak s JOIN cegek c ON c.id=s.ceg_id LEFT JOIN banki_kivonatok bk ON bk.id=s.banki_kivonat_id
      LEFT JOIN (SELECT szamla_id, SUM(osszeg) reszt FROM reszteljesitesek WHERE irany='KIMENO' GROUP BY szamla_id) r ON r.szamla_id=s.id" > "$KI/tsv/$d/kimeno.tsv"
    "${M[@]}" "SELECT id, nev, adoszam, partnerkod, megjegyzes FROM cegek" > "$KI/tsv/$d/cegek.tsv"
    "${M[@]}" "SELECT id, ceg_id, penznem, uid, statusz, utalva_datum, banki_hivatkozas, megjegyzes FROM utalasok" > "$KI/tsv/$d/utalasok.tsv"
    ib=0; [[ "$d" == "$DB" ]] && ib=1
    docker run --rm -e IG="$IG" -e IB="$ib" -v "$MIG":/migracio:ro "$PY_KEP" python -I -B /migracio/eszkozok/dos/fuggetlen_ellenorzes.py \
        "/migracio/$DOS_MAPPA" "/migracio/4_EREDMENY/$DB/tsv/$d" > "$KI/fuggetlen_$d.txt" 2>&1 || true
    echo "-- $d:"; grep -v 'több DOS-kódú' "$KI/fuggetlen_$d.txt" | tail -n +2
done

lepes "5. mentés (az app saját formátumában – phpMyAdminban importálható) + visszatöltési próba"
mkdir -p "$MIG/MIGRALT_SQL_EREDMENY_ADATBAZIS" "$MIG/4_EREDMENY/regi_eredmenyek"
for f in "$MIG/MIGRALT_SQL_EREDMENY_ADATBAZIS"/BaninaPRO_migralt_*.sql; do   # a korábbi eredmény félre, hogy csak a friss legyen ott
    [[ -e "$f" ]] && mv "$f" "$MIG/4_EREDMENY/regi_eredmenyek/"
done
CEL="$MIG/MIGRALT_SQL_EREDMENY_ADATBAZIS/BaninaPRO_migralt_$(date +%Y%m%d_%H%M).sql"
"$K" mentes "$DB" "$CEL" > "$KI/mentes.log" 2>&1 || { cat "$KI/mentes.log"; hiba "a mentés nem sikerült"; }
"$K" betolt "$CEL" "${DB}_vissza" > "$KI/vissza.log" 2>&1 || { tail -5 "$KI/vissza.log"; hiba "a visszatöltés nem sikerült"; }
"$K" php "${DB}_vissza" /migracio/eszkozok/php/osszevet.php "$DB" > "$KI/visszatoltes_osszevetes.txt" 2>&1 || true
cat "$KI/visszatoltes_osszevetes.txt" | head -14

lepes "6. jelentés: MIGRALT_SQL_EREDMENY_ADATBAZIS/JELENTES.txt, OLVASS_EL.txt (betöltési útmutató), DOS_ATVETEL_RESZLETEK.txt"
"$K" php "$DB" /migracio/eszkozok/php/jelentes.php "$(basename "$CEL")" "/migracio/$OSSZEVONAS" > /dev/null || hiba "a jelentés nem készült el"
echo
echo "KÉSZ: $CEL ($(du -h "$CEL" | cut -f1))"
