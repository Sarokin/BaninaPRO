#!/bin/bash
# BaninaPRO – migrációs környezet (a Macen, Dockerben). A szerverrel azonos adatbázis: MariaDB 10.11.
# Csak Docker Desktop kell (a Python is konténerben fut, a gépen nem kell semmi más).
#
#   kornyezet.sh elokeszit                 új gépen / indulás előtt: képek letöltése, építése, indítás + önteszt
#   kornyezet.sh onteszt                   a teljes lánc próbája kitalált adatokkal (üzleti adat nélkül)
#   kornyezet.sh felderit <db>             a DOS-mappa (1_DOS_PROGRAM) felderítése a <db> ismert DOS-értékeivel
#   kornyezet.sh py <script> [arg…]        Python (3.12) konténerben; a MIGRACIO mappa /migracio alatt látszik
#   kornyezet.sh indit                     a két konténer (bp-mig-db, bp-mig-app) indítása
#   kornyezet.sh betolt <dump.sql> <db>    egy mentés betöltése egy ÚJ, <db> nevű adatbázisba + séma-egyeztetés
#   kornyezet.sh sema <db>                 séma-egyeztetés a repó sql/schema.sql-je szerint (a szerver telepítőjének kódjával)
#   kornyezet.sh php <db> <script> [arg…]  PHP futtatása az app konténerben, a <db> adatbázison
#   kornyezet.sh sql <db>                  SQL a bemenetről (pl. <<'SQL' … SQL)
#   kornyezet.sh szamok <db>               a fő táblák sorszáma
#   kornyezet.sh mentes <db> <cél.sql>     teljes mentés az app saját mentőjével (ezt tölti be a szerver SQL IMPORT ikonja)
#   kornyezet.sh web <db>                  a <db> megnyitása böngészőben: http://localhost:8090
#   kornyezet.sh leallit                   a konténerek leállítása (az adatok megmaradnak)
#
# Minden futás saját adatbázist kap (pl. mig_0930), így semmit nem kell törölni. Csak ebben a két konténerben dolgozik:
# a fejlesztői (baninapro-*) és a szerver adatbázisához nem nyúl.
set -euo pipefail

ESZK="$(cd "$(dirname "$0")" && pwd)"
MIG="$(cd "$ESZK/.." && pwd)"
REPO="$(cd "$MIG/.." && pwd)"
# A konténerek, kötetek és a hálózat neve alapból bp-mig-*. Csak a környezet próbájához: MIG_ELOTAG=bp-migproba MIG_PORT=8091
# egy második, teljesen külön környezetet ad (üres adatbázis-kötettel, mint egy új gépen), a meglévőhöz nem nyúl.
ELOTAG="${MIG_ELOTAG:-bp-mig}"
if [[ "$ELOTAG" == bp-mig ]]; then HALO=bp-migracio; else HALO="$ELOTAG-halo"; fi
DB_K="$ELOTAG-db"
APP_K="$ELOTAG-app"
ROOT_JELSZO=migracio_root
APP_PORT="${MIG_PORT:-8090}"
APP_KEP=baninapro-app:latest      # a repó docker/Dockerfile-jából (ugyanaz, mint a fejlesztői környezeté)
DB_KEP=mariadb:10.11              # mint a szerveren (DB_KEP_SZERVER a szerver_beallitas.sh-ban)
PY_KEP=python:3.12-slim

hiba() { printf 'HIBA: %s\n' "$*" >&2; exit 1; }

docker info >/dev/null 2>&1 || hiba "A Docker nem fut – indítsd el a Docker Desktopot (open -a Docker), és próbáld újra."

kep_biztosit() {   # a hiányzó képek letöltése / építése (internet kell hozzá – a helyszín előtt fusson le: elokeszit)
    docker image inspect "$DB_KEP" >/dev/null 2>&1 || docker pull "$DB_KEP"
    docker image inspect "$PY_KEP" >/dev/null 2>&1 || docker pull "$PY_KEP"
    if ! docker image inspect "$APP_KEP" >/dev/null 2>&1; then
        echo "Az app képe ($APP_KEP) hiányzik – építés a repó docker/Dockerfile-jából…"
        docker build -t "$APP_KEP" "$REPO/docker"
    fi
}

py() {   # py <script> [arg…]: Python konténerben, a DOS-mappa csak olvasható
    docker run --rm -i -v "$MIG":/migracio -v "$MIG/1_DOS_PROGRAM":/migracio/1_DOS_PROGRAM:ro -w /migracio "$PY_KEP" python -I -B "$@"
}
nev_ok() { [[ "$1" =~ ^[a-z][a-z0-9_]{1,40}$ ]] || hiba "Érvénytelen adatbázisnév: $1 (kisbetű, szám, aláhúzás)"; }

db_sql() {   # db_sql [adatbázis] < sql
    docker exec -i "$DB_K" mariadb -uroot -p"$ROOT_JELSZO" --default-character-set=utf8mb4 --max-allowed-packet=1G "$@"
}

db_var() {   # a VÉGLEGES szerverre vár: új köteten a MariaDB előbb egy ideiglenes szerverrel (--skip-networking) állítja be
             # a jelszót és a felhasználót, aztán újraindul – a socket már az ideiglenesnél válaszol, a TCP csak a véglegesnél
    local _
    for _ in $(seq 1 150); do
        if echo 'SELECT 1' | docker exec -i "$DB_K" mariadb -uroot -p"$ROOT_JELSZO" -h127.0.0.1 --protocol=TCP >/dev/null 2>&1 \
            && echo 'SELECT 1' | db_sql >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    hiba "Az adatbázis nem indult el (docker logs $DB_K)."
}

app_inditas() {   # app_inditas <db>
    # Az includes/config.php helyére a migrációs config kerül. A repó csak olvashatóan látszik, ezért a csatolási pontnak
    # léteznie kell: friss klónban nincs meg (a .gitignore kizárja), ilyenkor üres fájl – mint amit a docker compose is létrehoz.
    [[ -e "$REPO/includes/config.php" ]] || : > "$REPO/includes/config.php"
    docker rm -f "$APP_K" >/dev/null 2>&1 || true
    docker run -d --name "$APP_K" --network "$HALO" -p "127.0.0.1:$APP_PORT:80" \
        -e BANINA_DB="$1" \
        -v "$REPO":/var/www/html:ro \
        -v "$ESZK/config.php":/var/www/html/includes/config.php:ro \
        -v "$MIG":/migracio \
        -v "$ELOTAG-adatok":/var/lib/baninapro \
        "$APP_KEP" >/dev/null
}

indit() {
    kep_biztosit
    docker network inspect "$HALO" >/dev/null 2>&1 || docker network create "$HALO" >/dev/null
    if docker container inspect "$DB_K" >/dev/null 2>&1; then
        docker start "$DB_K" >/dev/null
    else
        docker run -d --name "$DB_K" --network "$HALO" --network-alias db \
            -e MARIADB_ROOT_PASSWORD="$ROOT_JELSZO" -e MARIADB_DATABASE=baninapr_DATA \
            -e MARIADB_USER=baninapr_DATA -e MARIADB_PASSWORD=migracio_app \
            -e MARIADB_INITDB_SKIP_TZINFO=1 -e TZ=Europe/Budapest \
            -v "$ELOTAG-db-adat":/var/lib/mysql \
            "$DB_KEP" --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci \
            --max-allowed-packet=1G --innodb-buffer-pool-size=1G --innodb-flush-log-at-trx-commit=2 >/dev/null
    fi
    db_var
    # minden migrációs adatbázishoz hozzáfér (csak ebben a konténerben létezik)
    echo "GRANT ALL PRIVILEGES ON *.* TO 'baninapr_DATA'@'%'; FLUSH PRIVILEGES;" | db_sql
    # az app konténere ennek a mappának a kódját és MIGRACIO-ját lássa (másik klónból / gépről indítva újra kell építeni)
    if docker container inspect "$APP_K" >/dev/null 2>&1 \
        && [[ "$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/migracio"}}{{.Source}}{{end}}{{end}}' "$APP_K")" == "$MIG" ]]; then
        docker start "$APP_K" >/dev/null
    else
        app_inditas baninapr_DATA
    fi
    echo "Kész: $DB_K (MariaDB 10.11) + $APP_K – web: http://localhost:$APP_PORT"
}

sema() {   # sema <db>: a szerver telepítőjének séma-egyeztetése (csak pótol, semmit nem töröl)
    local db="$1" minta="sema_$1" php
    nev_ok "$db"
    printf 'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$minta" | db_sql
    db_sql "$minta" < "$REPO/sql/schema.sql"
    php="$(awk '/^sema_osszevetes\(\) \{/{f=1} f&&/^<\?php/{p=1} p&&/^PHP$/{exit} p{print}' "$REPO/SERVER SETUP AND UPDATE/szerver_beallitas.sh")"
    [[ "$php" == "<?php"* ]] || hiba "A séma-egyeztető kód nem található a szerver_beallitas.sh-ban."
    printf '%s\n' "$php" | docker exec -i -e BANINA_JELSZO="$ROOT_JELSZO" -e BANINA_DB="$db" -e BANINA_MINTA="$minta" "$APP_K" php
}

betolt() {   # betolt <dump.sql> <db>
    local fajl="$1" db="$2" elso
    nev_ok "$db"
    [[ -f "$fajl" ]] || hiba "Nincs ilyen fájl: $fajl"
    if [[ -n "$(echo "SHOW DATABASES LIKE '$db'" | db_sql -N)" ]]; then
        hiba "Már van $db nevű adatbázis – adj új nevet (pl. ${db}_2)."
    fi
    printf 'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$db" | db_sql
    elso="$(head -c 64 "$fajl" | tr -d '\0')"
    if [[ "$elso" == "-- BaninaPRO adatbázis-mentés"* ]]; then
        echo "Betöltés (a BaninaPRO saját mentése): $(basename "$fajl")"
        db_sql "$db" < "$fajl"
    else
        echo "Betöltés (idegen, pl. phpMyAdmin export – a USE / CREATE DATABASE sorok nélkül): $(basename "$fajl")"
        { echo 'SET FOREIGN_KEY_CHECKS = 0;'; grep -a -v -E '^(USE |CREATE DATABASE )' "$fajl"; echo 'SET FOREIGN_KEY_CHECKS = 1;'; } | db_sql "$db"
    fi
    echo "Séma-egyeztetés a repó sql/schema.sql-je szerint:"
    sema "$db"
    szamok "$db"
}

szamok() {
    nev_ok "$1"
    db_sql "$1" -t <<'SQL'
SELECT 'cegek' tabla, COUNT(*) sor FROM cegek UNION ALL SELECT 'kotesek', COUNT(*) FROM kotesek
UNION ALL SELECT 'bejovo_szamlak', COUNT(*) FROM bejovo_szamlak UNION ALL SELECT 'kimeno_szamlak', COUNT(*) FROM kimeno_szamlak
UNION ALL SELECT 'utalasok', COUNT(*) FROM utalasok UNION ALL SELECT 'banki_kivonatok', COUNT(*) FROM banki_kivonatok
UNION ALL SELECT 'reszteljesitesek', COUNT(*) FROM reszteljesitesek UNION ALL SELECT 'valtozasnaplo', COUNT(*) FROM valtozasnaplo
UNION ALL SELECT 'felhasznalok', COUNT(*) FROM felhasznalok;
SQL
}

mentes() {   # mentes <db> <cél.sql>
    local db="$1" cel="$2" ki nev
    nev_ok "$db"
    ki="$(docker exec -u 33 -e BANINA_DB="$db" -w /var/www/html "$APP_K" php -q cron_mentes.php kezi "MIGRACIO")"
    echo "$ki"
    nev="$(printf '%s\n' "$ki" | sed -n 's/^OK: \([^ ]*\.sql\) .*/\1/p')"
    [[ -n "$nev" ]] || hiba "A mentés nem sikerült."
    docker cp "$APP_K:/var/lib/baninapro/DBBCKP/$nev" "$cel"
    echo "Mentés: $cel"
}

php_fut() {   # php_fut <db> <script> [arg…]
    local db="$1"
    shift
    docker exec -i -e BANINA_DB="$db" -w /var/www/html "$APP_K" php -d memory_limit=4G "$@"
}

felderit() {   # felderit <db> [forrás] [kimenet] – a forrás alapból az 1_DOS_PROGRAM, a kimenet a 4_EREDMENY/dos_felderites
    local db="$1" forras="${2:-/migracio/1_DOS_PROGRAM}" ki="${3:-/migracio/4_EREDMENY/dos_felderites}"
    nev_ok "$db"
    [[ -f "$MIG/4_EREDMENY/$db/ismert_dos.json" ]] || php_fut "$db" /migracio/eszkozok/php/ismert_dos.php
    py /migracio/eszkozok/dos_felderito.py "$forras" "$ki" --ismert "/migracio/4_EREDMENY/$db/ismert_dos.json"
}

ONTESZT_HIBA=0
elvart() {   # elvart <mi> <kimenet> <szövegrészlet> – pontos szövegre
    if printf '%s\n' "$2" | grep -q -F -- "$3"; then echo "  rendben: $1"; else echo "  HIBA:    $1 (várt: $3)"; ONTESZT_HIBA=1; fi
}
elvart_re() {   # elvart_re <mi> <kimenet> <regex>
    if printf '%s\n' "$2" | grep -q -E -- "$3"; then echo "  rendben: $1"; else echo "  HIBA:    $1 (várt: $3)"; ONTESZT_HIBA=1; fi
}

onteszt() {   # a teljes lánc kitalált adatokkal (teszt/minta_koztes.json), üzleti adat nélkül
    local db v k
    db="onteszt_$(date +%m%d%H%M%S)"
    v="${db}_v"
    indit >/dev/null
    echo "== ÖNTESZT ($db) – kitalált adatokkal"
    betolt "$REPO/sql/schema.sql" "$db" >/dev/null
    k="$(php_fut "$db" /migracio/eszkozok/php/dos_atvetel.php /migracio/eszkozok/teszt/minta_koztes.json --datum=2026.01.01)"
    elvart "DOS-átvétel: a várt új rekordok" "$k" 'ÚJ: 5 cég, 5 kötés, 6 bejövő számla (+ 0 az IB-archívból áthelyezve), 1 forintszámla, 3 utalás, 3 kimenő számla, 1 banki kivonat, 2 részteljesítés'
    elvart "DOS-átvétel: nincs hiba, nincs ütközés" "$k" '0 ütközés (a BaninaPRO-ban azóta módosítva) · 0 a DOS-ból eltűnt · 0 figyelmeztetés · 0 HIBA'
    k="$(php_fut "$db" /migracio/eszkozok/php/dos_atvetel.php /migracio/eszkozok/teszt/minta_koztes.json --datum=2026.01.01)"
    elvart "második futás: semmi új" "$k" 'ÚJ: 0 cég, 0 kötés, 0 bejövő számla (+ 0 az IB-archívból áthelyezve), 0 forintszámla, 0 utalás, 0 kimenő számla, 0 banki kivonat, 0 részteljesítés'
    elvart "második futás: semmi nem változik" "$k" '0 mező javítva a DOS szerint, 0 számla rendeződött (fizetés), 0 utalás terhelése pótolva'
    k="$(php_fut "$db" /migracio/eszkozok/php/ellenorzes.php || true)"
    elvart "ellenőrzés: 0 hiba" "$k" '– HIBA 0,'
    k="$(php_fut "$db" /migracio/eszkozok/php/ismert_dos.php)"
    elvart "ismert DOS-értékek kinyerése" "$k" 'bejövő:        7  (DOS kötésszám/kisszám: 6'
    py /migracio/eszkozok/teszt/dbf_keszito.py "/migracio/4_EREDMENY/$db/ismert_dos.json" "/migracio/4_EREDMENY/$db/teszt_dos" >/dev/null
    k="$(py /migracio/eszkozok/dos_felderito.py "/migracio/4_EREDMENY/$db/teszt_dos" "/migracio/4_EREDMENY/$db/teszt_felderites" \
        --ismert "/migracio/4_EREDMENY/$db/ismert_dos.json")"
    elvart_re "felderítés: dBase/FoxPro táblák + memók" "$k" 'TETEL\.DBF +6 rekord .*memó: TETEL\.FPT'
    elvart_re "felderítés: a kötésszám mező felismerve" "$k" 'TETEL\.DBF +KSZAM +→ kötésszám +100\.0%'
    elvart_re "felderítés: a kisszám mező felismerve" "$k" 'KISSZAM +→ kisszám +100\.0%'
    elvart_re "felderítés: CWI-2 kódlap felismerve" "$k" 'UGYF\.DBF +[0-9]+ rekord \(0 törölt\) +cwi2'
    elvart_re "felderítés: ismeretlen bináris – rekordhossz" "$k" 'becsült rekordhossz: 64, eltolás: 10'
    mentes "$db" "$MIG/4_EREDMENY/$db/mentes.sql" >/dev/null
    betolt "$MIG/4_EREDMENY/$db/mentes.sql" "$v" >/dev/null
    k="$(php_fut "$v" /migracio/eszkozok/php/osszevet.php "$db")"
    if printf '%s\n' "$k" | awk 'NF >= 6 && $1 ~ /^[a-z_]+$/ && $2 ~ /^[0-9]+$/ { if ($4 + $5 + $6 > 0) rossz = 1; n++ } END { exit (rossz || n < 7) }'; then
        echo "  rendben: mentés + visszatöltés: 0 eltérés"
    else
        echo "  HIBA:    mentés + visszatöltés után eltérés van:"; printf '%s\n' "$k" | sed -n '3,11p'; ONTESZT_HIBA=1
    fi
    if [[ $ONTESZT_HIBA -eq 0 ]]; then
        echo "ÖNTESZT: MINDEN RENDBEN – a gép kész a migrációra."
    else
        echo "ÖNTESZT: HIBA – lásd fent (a részletek: MIGRACIO/4_EREDMENY/$db/)."
        return 1
    fi
}

parancs="${1:-}"
[[ -n "$parancs" ]] && shift
case "$parancs" in
    elokeszit) kep_biztosit; indit; onteszt ;;
    onteszt) onteszt ;;
    felderit) [[ $# -ge 1 ]] || hiba "használat: kornyezet.sh felderit <db> [forrás] [kimenet]"; indit >/dev/null; felderit "$@" ;;
    py)      [[ $# -ge 1 ]] || hiba "használat: kornyezet.sh py <script> [arg…]"; kep_biztosit; py "$@" ;;
    indit)   indit ;;
    betolt)  [[ $# -eq 2 ]] || hiba "használat: kornyezet.sh betolt <dump.sql> <db>"; betolt "$1" "$2" ;;
    sema)    [[ $# -eq 1 ]] || hiba "használat: kornyezet.sh sema <db>"; sema "$1" ;;
    php)     [[ $# -ge 2 ]] || hiba "használat: kornyezet.sh php <db> <script> [arg…]"; db="$1"; shift; nev_ok "$db"
             docker exec -i -e BANINA_DB="$db" -w /var/www/html "$APP_K" php -d memory_limit=4G "$@" ;;
    sql)     [[ $# -ge 1 ]] || hiba "használat: kornyezet.sh sql <db> [mariadb kapcsolók]"; db="$1"; shift; nev_ok "$db"; db_sql "$db" "$@" ;;
    szamok)  [[ $# -eq 1 ]] || hiba "használat: kornyezet.sh szamok <db>"; szamok "$1" ;;
    mentes)  [[ $# -eq 2 ]] || hiba "használat: kornyezet.sh mentes <db> <cél.sql>"; mentes "$1" "$2" ;;
    web)     [[ $# -eq 1 ]] || hiba "használat: kornyezet.sh web <db>"; nev_ok "$1"; app_inditas "$1"; echo "http://localhost:$APP_PORT  (adatbázis: $1)" ;;
    leallit) docker stop "$APP_K" "$DB_K" >/dev/null 2>&1 || true; echo "Leállítva." ;;
    *)       sed -n '2,20p' "$0"; exit 1 ;;
esac
