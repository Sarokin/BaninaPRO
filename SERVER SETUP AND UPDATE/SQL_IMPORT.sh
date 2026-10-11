#!/bin/bash
# =============================================================================
#  SQL_IMPORT – egy SQL-mentés betöltése a BaninaPRO szerver adatbázisába
# =============================================================================
#  Arra való, hogy egy máshonnan hozott mentést – pl. az éles oldal phpMyAdmin-exportját (SQLDATA_….sql) vagy a
#  BaninaPRO saját mentését – gond nélkül be lehessen tölteni a szerverre, akkor is, ha a táblák már léteznek.
#
#  Futtatás:   az asztali „SQL IMPORT” ikonnal (az ikont a BaninaPro_App_Updater.sh készíti), vagy terminálból:
#                  bash SQL_IMPORT.sh                (a rendszergazdai jelszót kéri)
#              A script megkérdezi a fájlt: húzd bele az ablakba (vagy írd be az elérési útját), és nyomj Entert.
#              (Megadható paraméterként is:  bash SQL_IMPORT.sh /út/a/fájlhoz.sql)
#
#  Amit elvégez:
#   1. ellenőrzi a fájlt: BaninaPRO-mentés-e, megvan-e a vége (nem szakadt-e meg a letöltése);
#   2. az alkalmazás saját visszaállítójával tölti be: az előtte teljes mentést készít a mostani adatbázisról, és ha a
#      betöltés bármin elakad, magától visszaállítja a korábbi állapotot;
#   3. a szerver MOSTANI adatbázisa teljesen lecserélődik a fájl tartalmára (a felhasználók és a jelszavaik is!) – ha
#      a szerveren már van üzleti adat, előbb megerősítést kér;
#   4. a végén ellenőrzi a táblákat, kilépteti a bejelentkezett felhasználókat, és megnézi, hogy az adatbázis
#      szerkezete megfelel-e a szerveren futó kódnak – ha régebbi, a teljes telepítőt indítja, az pótolja a hiányt.
#
#  A betöltött fájl a helyén marad, a szerverre csak egy átmeneti másolata kerül (a végén törlődik).

set -uo pipefail

VALTOZAT=1   # ha a scriptet módosítod, növeld: egy másolat (pl. az asztalon) ebből tudja, hogy a BaninaPRO mappában újabb van
ORSZEM="/usr/local/sbin/baninapro-orszem"       # a telepítő írja: ebből tudja, hol a BaninaPRO mappa és melyik a port
ERTESITO="/usr/local/sbin/baninapro-ertesites"  # push-értesítés (ha a telepítő beállította)
NAPLO="/var/log/baninapro-import.log"
TELEPITO_NEV="SERVER SETUP AND UPDATE/szerver_beallitas.sh"
SAJAT_NEV="SERVER SETUP AND UPDATE/SQL_IMPORT.sh"
MENTES_MAPPA="/var/lib/baninapro/DBBCKP"        # a mentések mappája az alkalmazás konténerében (docker/config.php: BACKUP_DIR)
SEMA_MINTA="baninapro_sema_minta"               # ideiglenes adatbázis a séma összevetéséhez (a telepítő is ezt használja)
ALAP_TABLAK=(felhasznalok cegek kotesek bejovo_szamlak)   # ezek nélkül a fájl nem BaninaPRO-mentés

SCRIPT="$(readlink -f "${BASH_SOURCE[0]}")"
REPO="" APP_PORT=80 APP_KONTENER="baninapro-app" DB_KONTENER="baninapro-db"
IGEN=0 KIMENET="" IMPORT_NEV=""
C_ZOLD="" C_SARGA="" C_PIROS="" C_F="" C_N=""
if [[ -t 1 ]]; then C_ZOLD=$'\e[1;32m' C_SARGA=$'\e[1;33m' C_PIROS=$'\e[1;31m' C_F=$'\e[1m' C_N=$'\e[0m'; fi
S_OK="✔" S_FIGY="⚠" S_HIBA="✘" S_PONT="•"
# (a gép saját szöveges konzolján a betűkészletben nincs meg minden jel)
if [[ ${TERM:-} == linux ]]; then S_OK="+" S_FIGY="!" S_HIBA="X" S_PONT="-"; fi

ok()   { printf '  %s%s%s %s\n' "$C_ZOLD" "$S_OK" "$C_N" "$*"; }
info() { printf '  %s %s\n' "$S_PONT" "$*"; }
figy() { printf '  %s%s %s%s\n' "$C_SARGA" "$S_FIGY" "$*" "$C_N"; }
naploz() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$NAPLO" 2>/dev/null || true; }
takarit() {
    if [[ -n $IMPORT_NEV ]]; then
        timeout 60 docker exec "$APP_KONTENER" rm -f "$MENTES_MAPPA/$IMPORT_NEV" </dev/null >/dev/null 2>&1 || true
        IMPORT_NEV=""
    fi
    if [[ -n $KIMENET ]]; then rm -f "$KIMENET"; fi
}
hiba() {
    printf '\n%s%s HIBA: %s%s\n\n' "$C_PIROS" "$S_HIBA" "$*" "$C_N"
    naploz "HIBA: $*"
    takarit
    exit 1
}

# egy sor értéke az őrszem scriptjéből (a telepítő shell-idézéssel írta ki: NEV=érték)
orszem_ertek() {
    local sor="" ertek=""
    [[ -r $ORSZEM ]] || return 0
    sor="$(grep -m 1 "^$1=" "$ORSZEM" 2>/dev/null || true)"
    [[ -n $sor ]] || return 0
    eval "ertek=${sor#*=}" 2>/dev/null || true
    printf '%s' "$ertek"
}

# a BaninaPRO mappa: ahol ez a fájl van (ha a mappából fut), különben ahová a telepítő tette
repo_keres() {
    local m h
    m="$(dirname "$(dirname "$SCRIPT")")"
    if [[ -d $m/.git && -f $m/docker-compose.yml ]]; then printf '%s' "$m"; return 0; fi
    m="$(orszem_ertek REPO)"
    if [[ -n $m && -d $m/.git ]]; then printf '%s' "$m"; return 0; fi
    if [[ -n ${SUDO_USER:-} ]]; then
        h="$(getent passwd "$SUDO_USER" | cut -d: -f6)"
        if [[ -n $h && -d $h/BaninaPRO/.git ]]; then printf '%s' "$h/BaninaPRO"; return 0; fi
    fi
    for m in /home/*/BaninaPRO; do
        if [[ -d $m/.git ]]; then printf '%s' "$m"; return 0; fi
    done
    return 1
}

# Az ablakba húzott (vagy beírt) elérési út rendbetétele: a terminál idézőjelek közé teheti, a szóközöket
# visszaperrel jelölheti, a fájlkezelő file:// címet adhat – ezekből lesz sima elérési út.
utvonal_tisztit() {
    local u="$1" h
    u="${u#"${u%%[![:space:]]*}"}"
    u="${u%"${u##*[![:space:]]}"}"
    if [[ ${#u} -ge 2 && $u == \'*\' ]]; then
        u="${u:1:${#u}-2}"
        u="${u//\'\\\'\'/\'}"
    elif [[ ${#u} -ge 2 && $u == \"*\" ]]; then
        u="${u:1:${#u}-2}"
    elif [[ $u == *\\* ]]; then
        u="$(printf '%s' "$u" | sed 's/\\\(.\)/\1/g')"
    fi
    if [[ $u == file://* ]]; then
        u="${u#file://}"
        u="$(printf '%b' "${u//%/\\x}")"
    fi
    if [[ $u == \~/* ]]; then
        h="$(getent passwd "${SUDO_USER:-root}" | cut -d: -f6)"
        u="${h:-$HOME}/${u:2}"
    fi
    printf '%s' "$u"
}

# SQL az adatbázis konténerében, rendszergazdaként (a jelszó a konténer környezetében van); a parancsok a bemeneten
# jönnek – a billentyűzetről soha nem olvas (lásd a hívásokat: mindig van átirányított bemenet)
db_sql() { timeout 900 docker exec -i "$DB_KONTENER" sh -c 'exec mysql -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'; }
# egyetlen érték (szám, név); hiba esetén üres
db_ertek() { printf '%s\n' "$1" | db_sql 2>/dev/null | tr -d '\r' || true; }
db_szam() { local v; v="$(db_ertek "$1")"; if [[ $v =~ ^[0-9]+$ ]]; then echo "$v"; else echo 0; fi; }
tabla_sorok() {   # egy tábla sorainak száma (ha nincs ilyen tábla: 0)
    db_szam "SELECT COUNT(*) FROM \`$1\`"
}

# Hosszabb művelet a háttérben, közben pörgő jel és telő idő – hogy látsszon: dolgozik. A kimenete a $KIMENET fájlba
# kerül; a billentyűzetről nem olvashat, és a zárakat (7, 8) nem viszi magával.
var_kozben() {
    local felirat="$1" pid rc=0 kezdet=$SECONDS i=0 jelek='|/-\'
    shift
    "$@" > "$KIMENET" 2>&1 < /dev/null 7>&- 8>&- &
    pid=$!
    if [[ ! -t 1 ]]; then info "$felirat…"; fi
    while kill -0 "$pid" 2>/dev/null; do
        if [[ -t 1 ]]; then
            printf '\r  %s %s… %02d:%02d ' "${jelek:i % 4:1}" "$felirat" $(( (SECONDS - kezdet) / 60 )) $(( (SECONDS - kezdet) % 60 ))
            i=$(( i + 1 ))
        fi
        sleep 0.5
    done
    wait "$pid" || rc=$?
    if [[ -t 1 ]]; then printf '\r\e[K'; fi
    return "$rc"
}

# A betöltendő fájl átmeneti másolata a mentések mappájába (az alkalmazás csak onnan állít vissza).
# Idegen mentésnél (phpMyAdmin, mysqldump) elé kerül a meglévő táblák törlése – ezért megy „gond nélkül”, ha a táblák
# már léteznek –, és kimaradnak belőle az adatbázist váltó sorok (USE, CREATE DATABASE): mindig a BaninaPRO adatbázisába
# tölt. A BaninaPRO saját mentése változatlanul megy át (abban benne van a táblák törlése, és az alkalmazás a záró
# sorából ellenőrzi, hogy teljes-e).
masolat_keszit() {   # $1 = a fájl, $2 = saját mentés-e (1/0), a többi: a meglévő táblák
    local f="$1" sajat="$2" t
    shift 2
    {
        if (( sajat )); then
            cat -- "$f"
        else
            printf -- '-- SQL_IMPORT – idegen mentés betöltése\nSET FOREIGN_KEY_CHECKS = 0;\n'
            for t in "$@"; do printf 'DROP TABLE IF EXISTS `%s`;\n' "$t"; done
            grep -a -v -E '^(USE |CREATE DATABASE )' -- "$f" || true
            printf '\nSET FOREIGN_KEY_CHECKS = 1;\n'
        fi
    } | timeout 900 docker exec -i "$APP_KONTENER" sh -c 'cat > "$1" && chmod 640 "$1"' _ "$MENTES_MAPPA/$IMPORT_NEV"
}
visszaallit() { timeout 3600 docker exec "$APP_KONTENER" php -q cron_mentes.php vissza "$1"; }

# Az adatbázis szerkezete megfelel-e a kódnak (sql/schema.sql)? Kiírja, hány helyen hiányzik vagy más valami (oszlop,
# típus, index); ha nem tudta megnézni: „?”. A sémát egy ideiglenes adatbázisba tölti, és azzal veti össze.
sema_elteres() {
    local sema="$REPO/sql/schema.sql" n
    [[ -f $sema ]] || { echo '?'; return 0; }
    if ! printf 'DROP DATABASE IF EXISTS `%s`;\nCREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$SEMA_MINTA" "$SEMA_MINTA" | db_sql >/dev/null 2>&1 \
        || ! timeout 900 docker exec -i "$DB_KONTENER" sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' _ "$SEMA_MINTA" < "$sema" >/dev/null 2>&1; then
        printf 'DROP DATABASE IF EXISTS `%s`;\n' "$SEMA_MINTA" | db_sql >/dev/null 2>&1 || true
        echo '?'
        return 0
    fi
    n="$(db_ertek "SELECT
        (SELECT COUNT(*) FROM information_schema.COLUMNS m
           LEFT JOIN information_schema.COLUMNS e
             ON e.TABLE_SCHEMA = DATABASE() AND e.TABLE_NAME = m.TABLE_NAME AND e.COLUMN_NAME = m.COLUMN_NAME
          WHERE m.TABLE_SCHEMA = '$SEMA_MINTA'
            AND (e.COLUMN_NAME IS NULL OR e.COLUMN_TYPE <> m.COLUMN_TYPE OR e.IS_NULLABLE <> m.IS_NULLABLE))
      + (SELECT COUNT(*) FROM (SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$SEMA_MINTA') m
           LEFT JOIN (SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()) e
             ON e.TABLE_NAME = m.TABLE_NAME AND e.INDEX_NAME = m.INDEX_NAME
          WHERE e.INDEX_NAME IS NULL)")"
    printf 'DROP DATABASE IF EXISTS `%s`;\n' "$SEMA_MINTA" | db_sql >/dev/null 2>&1 || true
    if [[ $n =~ ^[0-9]+$ ]]; then echo "$n"; else echo '?'; fi
}

# a fájl bekérése: az ablakba húzva a terminál beírja az elérési útját
fajl_bekeres() {
    local nyers="" u=""
    while :; do
        printf '\n  Húzd ide a betöltendő .sql fájlt (vagy írd be az elérési útját), majd nyomj Entert\n'
        printf '  (üresen hagyva kilép):\n  > '
        IFS= read -r nyers || { printf '\n'; exit 1; }
        u="$(utvonal_tisztit "$nyers")"
        if [[ -z $u ]]; then echo "  Nem adtál meg fájlt – nem történt semmi."; exit 0; fi
        if [[ -f $u && -r $u ]]; then FAJL="$u"; return 0; fi
        figy "Nem találom ezt a fájlt: $u"
    done
}

main() {
    local a f="" alapnev meret sajat=0 teljes=0 keszult="" t hianyzik=() meglevo=() fajl_tablak=() db_nev valasz=""
    local elotte_felh elotte_ceg elotte_szamla uzleti ki rc elotte="" n felh ceg kotes bejovo kimeno utalas kod
    local ablak=0 tobbi=()
    FAJL=""
    for a in "$@"; do
        case $a in
            --igen) IGEN=1; tobbi+=("$a") ;;      # a megerősítő kérdés nélkül (pl. scriptből)
            --ablak) ablak=1 ;;                    # az asztali ikonról: a végén megvárja az Entert, hogy az ablak ne tűnjön el
            -*) tobbi+=("$a") ;;
            *) f="$a"; tobbi+=("$a") ;;
        esac
    done
    if (( ablak )); then
        rc=0
        if [[ $EUID -ne 0 ]]; then sudo bash "$SCRIPT" "${tobbi[@]}" || rc=$?; else bash "$SCRIPT" "${tobbi[@]}" || rc=$?; fi
        read -r -p $'\nNyomj Entert az ablak bezárásához… ' _ || true
        exit "$rc"
    fi
    if [[ $EUID -ne 0 ]]; then
        command -v sudo >/dev/null || { echo "Rendszergazdaként kell futtatni (sudo)."; exit 1; }
        exec sudo bash "$SCRIPT" "$@"
    fi
    REPO="$(repo_keres)" || hiba "Nem találom a BaninaPRO mappát – a szervert előbb a teljes telepítővel kell beállítani (szerver_beallitas.sh)."
    # ha ez egy másolat (pl. az asztalon), és a BaninaPRO mappában már újabb változata van: az fut
    if [[ $SCRIPT != "$REPO/$SAJAT_NEV" && -f $REPO/$SAJAT_NEV && -z ${BANINA_IMPORT_ATADVA:-} ]]; then
        t="$(sed -n 's/^VALTOZAT=\([0-9][0-9]*\).*/\1/p' "$REPO/$SAJAT_NEV" 2>/dev/null | head -n 1 || true)"
        if [[ $t =~ ^[0-9]+$ ]] && (( t > VALTOZAT )); then
            export BANINA_IMPORT_ATADVA=1
            exec bash "$REPO/$SAJAT_NEV" "$@"
        fi
    fi
    printf '\n%sBaninaPRO – SQL-mentés betöltése%s  (%s)\n' "$C_F" "$C_N" "$(date '+%Y-%m-%d %H:%M')"
    t="$(orszem_ertek APP_PORT)";     if [[ $t =~ ^[0-9]+$ ]]; then APP_PORT=$t; fi
    t="$(orszem_ertek APP_KONTENER)"; if [[ -n $t ]]; then APP_KONTENER=$t; fi
    t="$(orszem_ertek DB_KONTENER)";  if [[ -n $t ]]; then DB_KONTENER=$t; fi
    command -v docker >/dev/null || hiba "A Docker nincs telepítve – a szervert előbb a teljes telepítővel kell beállítani."
    for t in "$APP_KONTENER" "$DB_KONTENER"; do
        [[ $(timeout 30 docker inspect -f '{{.State.Running}}' "$t" 2>/dev/null </dev/null || true) == true ]] \
            || hiba "A(z) $t konténer nem fut – futtasd előbb a teljes telepítőt: sudo bash \"$REPO/$TELEPITO_NEV\""
    done
    [[ $(db_ertek 'SELECT 1') == 1 ]] || hiba "Az adatbázis nem válaszol – futtasd előbb a teljes telepítőt: sudo bash \"$REPO/$TELEPITO_NEV\""
    db_nev="$(db_ertek 'SELECT DATABASE()')"

    # ---- 1) a fájl ----
    if [[ -n $f ]]; then
        FAJL="$(utvonal_tisztit "$f")"
        [[ -f $FAJL && -r $FAJL ]] || hiba "Nem találom ezt a fájlt: $FAJL"
    else
        [[ -t 0 ]] || hiba "Nincs megadva fájl. Használat: bash $(basename "$SCRIPT") /út/a/fájlhoz.sql"
        fajl_bekeres
    fi
    FAJL="$(readlink -f -- "$FAJL")"
    alapnev="$(basename -- "$FAJL")"
    meret="$(stat -c %s -- "$FAJL" 2>/dev/null || echo 0)"
    (( meret >= 100 )) || hiba "A fájl üres vagy sérült: $alapnev"
    if [[ $(head -c 64 -- "$FAJL" | tr -d '\0') == "-- BaninaPRO adatbázis-mentés"* ]]; then sajat=1; fi
    [[ $(grep -a -c -m 1 -E '^[[:space:]]*CREATE TABLE' -- "$FAJL" || true) == 1 ]] \
        || hiba "Ez nem SQL-mentés (nincs benne CREATE TABLE): $alapnev – az éles oldalról a phpMyAdmin → Exportálás (SQL) fájlja kell."
    if [[ $(grep -a -c -m 1 -E '^DELIMITER ' -- "$FAJL" || true) == 1 ]]; then
        hiba "A fájlban tárolt eljárás vagy trigger van (DELIMITER) – ilyet a BaninaPRO nem használ, ez nem az ő mentése."
    fi
    # megvan-e a vége (egy megszakadt letöltés csonka fájlt ad – abból fél adatbázis lenne)
    if (( sajat )); then
        if [[ $(tail -c 4096 -- "$FAJL" | grep -a -c -E '^-- Mentés vége: [0-9]+ tábla' || true) -ge 1 ]]; then teljes=1; fi
    elif [[ $(tail -c 4096 -- "$FAJL" | grep -a -c -E '^COMMIT;|^-- Dump completed|SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT' || true) -ge 1 ]]; then
        teljes=1
    fi
    (( teljes )) || hiba "A fájl vége hiányzik (a letöltése vagy a másolása megszakadhatott): $alapnev – töltsd le újra. A szerver adatbázisához nem nyúltam."
    mapfile -t fajl_tablak < <(grep -a -o -E '^[[:space:]]*CREATE TABLE (IF NOT EXISTS )?`[^`]+`' -- "$FAJL" | sed -E 's/.*`([^`]+)`$/\1/' || true)
    for t in "${ALAP_TABLAK[@]}"; do
        if [[ " ${fajl_tablak[*]} " != *" $t "* ]]; then hianyzik+=("$t"); fi
    done
    if (( ${#hianyzik[@]} )); then
        hiba "Ez nem BaninaPRO-mentésnek látszik: hiányzik belőle a(z) ${hianyzik[*]} tábla. A szerver adatbázisához nem nyúltam."
    fi
    keszult="$(grep -a -m 1 -E '^-- (Létrehozás ideje|Generation Time|Készült):' -- "$FAJL" | sed -E 's/^-- [^:]+: *//' | tr -d '\r' || true)"
    ok "A fájl rendben: $alapnev – $(( (meret + 524288) / 1048576 )) MB, ${#fajl_tablak[@]} tábla$( (( sajat )) && echo ', a BaninaPRO saját mentése' || echo ', idegen mentés (pl. phpMyAdmin-export)')${keszult:+, készült: $keszult}"

    # ---- 2) zárak: ne fusson közben a teljes telepítő, és az őrszem ne avatkozzon be (újraindítaná a konténereket) ----
    exec 7>/run/baninapro-telepito.lock
    flock -n 7 || hiba "A teljes telepítő (szerver_beallitas.sh) éppen fut – várd meg, amíg befejezi, utána indítsd újra ezt."
    exec 8>/run/baninapro-orszem.lock
    flock -w 90 8 || hiba "Az őrszem éppen helyreállítja a BaninaPRO-t – várj pár percet, utána indítsd újra ezt."

    # ---- 3) mi van most a szerveren ----
    mapfile -t meglevo < <(db_ertek "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")
    elotte_felh="$(tabla_sorok felhasznalok)"
    elotte_ceg="$(tabla_sorok cegek)"
    elotte_szamla=$(( $(tabla_sorok bejovo_szamlak) + $(tabla_sorok kimeno_szamlak) ))
    uzleti=$(( elotte_ceg + elotte_szamla + $(tabla_sorok kotesek) + $(tabla_sorok utalasok) ))
    info "A szerver adatbázisa most (${db_nev:-?}): $elotte_felh felhasználó, $elotte_ceg cég, $elotte_szamla számla"
    if (( uzleti > 0 && ! IGEN )); then
        figy "A szerveren már van üzleti adat. A betöltés a TELJES adatbázist lecseréli a fájl tartalmára"
        figy "(a felhasználókat és a jelszavaikat is). Előtte mentés készül, abból visszaállítható."
        [[ -t 0 ]] || hiba "Megerősítés kell hozzá – futtasd terminálból, vagy add meg a --igen kapcsolót."
        printf '  Biztosan betöltsem? Írd be: igen, majd Enter:  '
        IFS= read -r valasz || valasz=""
        [[ ${valasz,,} == igen ]] || { echo "  Nem töltöttem be – a szerver adatbázisa változatlan."; exit 0; }
    fi

    # ---- 4) betöltés: az alkalmazás visszaállítójával (előtte mentés; ha elakad, magától visszaállítja) ----
    KIMENET="$(mktemp)"
    IMPORT_NEV="import_$(date +%Y%m%d_%H%M%S).sql"
    var_kozben "A fájl átmásolása a szerver mentései közé" masolat_keszit "$FAJL" "$sajat" "${meglevo[@]}" \
        || hiba "A fájl nem másolható a mentések mappájába: $(tail -n 2 "$KIMENET" | tr '\n' ' ')"
    naploz "Betöltés indul: $alapnev ($meret bájt) – előtte: $elotte_felh felhasználó, $elotte_ceg cég, $elotte_szamla számla"
    rc=0
    var_kozben "Betöltés (előtte mentés készül a mostani adatbázisról)" visszaallit "$IMPORT_NEV" || rc=$?
    ki="$(tr -d '\r' < "$KIMENET")"
    if (( rc != 0 )) || [[ $ki != *"KÉSZ:"* ]]; then
        hiba "A betöltés nem sikerült: $(printf '%s' "$ki" | grep -a -E 'HIBA' | tail -n 1 | sed -E 's/^(HIBA:? *)+//; s/^a visszaállításkor \([^)]*\): //' || true)$( [[ $ki == *"visszaállt"* ]] && echo '' || echo ' (Ha a szöveg nem írja, hogy az adatbázis visszaállt, futtasd a teljes telepítőt.)')"
    fi
    elotte="$(printf '%s' "$ki" | sed -n 's/.*visszaállítás előtti állapot mentése: \([A-Za-z0-9._-]*\.sql\).*/\1/p' | tail -n 1)"
    ok "Betöltve: $(printf '%s' "$ki" | sed -n 's/.*KÉSZ: \([0-9]* utasítás, [0-9]* tábla, [0-9.]* mp\).*/\1/p' | tail -n 1)"
    if [[ -n $elotte ]]; then info "A korábbi adatbázis mentése: $elotte (visszaállítható: Admin → Adatbázis-mentések)"; fi

    # ---- 5) ellenőrzés: megvannak-e az alaptáblák, a felhasználók és az elsődleges kulcs (a mentés végén jön létre) ----
    hianyzik=()
    for t in "${ALAP_TABLAK[@]}"; do
        if [[ $(db_szam "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'") != 1 ]]; then hianyzik+=("$t"); fi
    done
    felh="$(tabla_sorok felhasznalok)"
    n="$(db_szam "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'felhasznalok' AND CONSTRAINT_TYPE = 'PRIMARY KEY'")"
    if (( ${#hianyzik[@]} )) || (( felh == 0 || n != 1 )); then
        figy "A betöltött adatbázis hiányos (hiányzó tábla: ${hianyzik[*]:-nincs}, felhasználók: $felh, elsődleges kulcs: $n) – visszaállítom a korábbit…"
        if [[ -n $elotte ]] && var_kozben "A korábbi adatbázis visszaállítása" visszaallit "$elotte"; then
            hiba "A fájl tartalma hiányos volt, ezért a szerver adatbázisát visszaállítottam a betöltés előtti állapotra ($elotte)."
        fi
        hiba "A fájl tartalma hiányos volt, és a korábbi állapot visszaállítása sem sikerült – állítsd vissza kézzel: Admin → Adatbázis-mentések → $elotte"
    fi
    # a bejelentkezett felhasználók kiléptetése: a munkamenetük a régi adatbázis felhasználójához tartozott
    timeout 60 docker exec "$APP_KONTENER" sh -c 'find /tmp -maxdepth 1 -type f -name "sess_*" -delete' </dev/null >/dev/null 2>&1 || true
    takarit
    KIMENET="$(mktemp)"

    ceg="$(tabla_sorok cegek)"; kotes="$(tabla_sorok kotesek)"; bejovo="$(tabla_sorok bejovo_szamlak)"
    kimeno="$(tabla_sorok kimeno_szamlak)"; utalas="$(tabla_sorok utalasok)"
    ok "Az adatbázis most: $felh felhasználó, $ceg cég, $kotes kötés, $bejovo bejövő és $kimeno kimenő számla, $utalas utalás"
    naploz "Betöltve: $alapnev – $felh felhasználó, $ceg cég, $kotes kötés, $bejovo bejövő, $kimeno kimenő számla, $utalas utalás; a korábbi állapot mentése: ${elotte:-?}"
    if [[ -x $ERTESITO ]]; then
        setsid -f "$ERTESITO" -p 4 -t floppy_disk "Adatbázis betöltve" \
            "$alapnev – most: $felh felhasználó, $ceg cég, $(( bejovo + kimeno )) számla. A korábbi adatbázis mentése: ${elotte:-?}" >/dev/null 2>&1 7>&- 8>&- || true
    fi

    # ---- 6) az adatbázis szerkezete megfelel-e a szerveren futó kódnak ----
    var_kozben "Az adatbázis szerkezetének összevetése a kóddal" sema_elteres || true
    n="$(tail -n 1 "$KIMENET" | tr -d '\r')"
    rm -f "$KIMENET"; KIMENET=""
    if [[ $n == 0 ]]; then
        ok "Az adatbázis szerkezete megfelel a kódnak"
    else
        if [[ $n == '?' ]]; then
            figy "Az adatbázis szerkezetét nem tudtam összevetni a kóddal – a teljes telepítő megnézi, és pótolja, ami hiányzik."
        else
            figy "A betöltött adatbázis szerkezete $n helyen eltér a kódétól (régebbi változatból származik)."
        fi
        info "A teljes telepítő pótolja a hiányzó oszlopokat, indexeket – elindítom (néhány perc): $TELEPITO_NEV"
        naploz "A séma eltér ($n) – átadva a teljes telepítőnek"
        [[ -f $REPO/$TELEPITO_NEV ]] || hiba "Nincs meg a teljes telepítő: $REPO/$TELEPITO_NEV"
        exec 7>&- 8>&-
        exec bash "$REPO/$TELEPITO_NEV"
    fi

    # ---- 7) az oldal ----
    kod="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "http://127.0.0.1:$APP_PORT/" 2>/dev/null || true)"
    if [[ $kod == 200 ]]; then
        ok "Az oldal működik – a belépéshez a betöltött adatbázis felhasználói és jelszavai érvényesek"
    else
        figy "Az oldal nem válaszol rendesen (HTTP ${kod:-?}) – futtasd a teljes telepítőt: sudo bash \"$REPO/$TELEPITO_NEV\""
    fi
    printf '\n  %sKész %d másodperc alatt.%s\n\n' "$C_F" "$SECONDS" "$C_N"
}

FAJL=""
main "$@"
exit 0
