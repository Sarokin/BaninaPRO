#!/bin/bash
# =============================================================================
#  BaninaPro_App_Updater – a BaninaPRO gyors frissítése a szerveren
# =============================================================================
#  Csak ennyit csinál: lehúzza a GitHubról a legfrissebb (pusholt) kódot, és felülírja vele a BaninaPRO fájljait.
#  A konténerek a BaninaPRO mappát közvetlenül olvassák, ezért a változás azonnal él: nincs újraépítés, nincs
#  újraindítás, nincs rendszerbeállítás – néhány másodperc.
#
#  Futtatás:   az asztali „BaninaPro App Updater” ikonnal (kattintásra terminálablakban indul), vagy terminálból:
#                  bash BaninaPro_App_Updater.sh      (a rendszergazdai jelszót kéri)
#  Az asztali ikonokat – a sajátját, az SQL_IMPORT.sh-ét és a DB_MENTES.sh-ét – ez a script készíti el minden futásakor
#  (és a teljes telepítő is ezt hívja: --ikonok).
#
#  Amit elvégez:
#   - a BaninaPRO mappa frissítése a GitHub main ágára (a szerveren kézzel módosított fájlokat előbb félreteszi:
#     git stash – visszahozhatók);
#   - a szerver beállítófájljának frissítése ($TITOK_MAPPA/config.php): a verziószám és az esetleges új beállítások a
#     docker/config.php-ból, a szerver saját adatbázis-jelszavával;
#   - ellenőrzés: az oldal válaszol-e, és az új verziót mutatja-e.
#
#  Amihez a teljes telepítő kell (szerver_beallitas.sh) – ilyenkor ez a script semmit nem ír felül, hanem azt indítja el:
#   - változott az adatbázis szerkezete (sql/schema.sql): a hiányzó táblákat, oszlopokat a telepítő pótolja;
#   - változott a konténerek leírása (docker-compose.yml, docker/Dockerfile): újra kell építeni őket;
#   - a szerver kódja eltér a GitHubétól (másik ág, vagy helyi commitok): a telepítő teszi rendbe.
#  Ha csak a telepítő változott, a weboldal ettől még frissül – a script szól, hogy a szerver beállításaihoz (őrszem,
#  jelentés, mentés…) alkalmas időben a teljes telepítőt is futtasd le.

set -uo pipefail

VALTOZAT=3   # ha a scriptet módosítod, növeld: egy másolat (pl. az asztalon) ebből tudja, hogy a BaninaPRO mappában újabb van
REPO_AG="main"
TITOK_MAPPA="/etc/baninapro"
ORSZEM="/usr/local/sbin/baninapro-orszem"       # a telepítő írja: ebből tudja, hol a BaninaPRO mappa és melyik a port
ERTESITO="/usr/local/sbin/baninapro-ertesites"  # push-értesítés (ha a telepítő beállította)
NAPLO="/var/log/baninapro-frissites.log"
TELEPITO_NEV="SERVER SETUP AND UPDATE/szerver_beallitas.sh"
SAJAT_NEV="SERVER SETUP AND UPDATE/BaninaPro_App_Updater.sh"
IMPORT_NEV="SERVER SETUP AND UPDATE/SQL_IMPORT.sh"
MENTES_NEV="SERVER SETUP AND UPDATE/DB_MENTES.sh"
# ezek változásához a teljes telepítő kell
NEHEZ_FAJLOK=("sql/schema.sql" "docker-compose.yml" "docker/Dockerfile")

SCRIPT="$(readlink -f "${BASH_SOURCE[0]}")"
REPO="" TULAJ="" TULAJ_HOME="" APP_PORT=80
C_ZOLD="" C_SARGA="" C_PIROS="" C_F="" C_N=""
if [[ -t 1 ]]; then C_ZOLD=$'\e[1;32m' C_SARGA=$'\e[1;33m' C_PIROS=$'\e[1;31m' C_F=$'\e[1m' C_N=$'\e[0m'; fi
S_OK="✔" S_FIGY="⚠" S_HIBA="✘" S_PONT="•"
# (a gép saját szöveges konzolján a betűkészletben nincs meg minden jel)
if [[ ${TERM:-} == linux ]]; then S_OK="+" S_FIGY="!" S_HIBA="X" S_PONT="-"; fi

ok()   { printf '  %s%s%s %s\n' "$C_ZOLD" "$S_OK" "$C_N" "$*"; }
info() { printf '  %s %s\n' "$S_PONT" "$*"; }
figy() { printf '  %s%s %s%s\n' "$C_SARGA" "$S_FIGY" "$*" "$C_N"; }
hiba() {
    printf '\n%s%s HIBA: %s%s\n' "$C_PIROS" "$S_HIBA" "$*" "$C_N"
    naploz "HIBA: $*"
    exit 1
}
naploz() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$NAPLO" 2>/dev/null || true; }

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

# git a BaninaPRO mappa tulajdonosa nevében (a mappa az övé – rendszergazdaként a git nem nyúlna hozzá); időkorláttal,
# és a billentyűzetről semmit nem olvashat (a timeout alatt futó parancsot a terminál megállítaná, ha olvasni próbálna)
g() {
    if [[ $TULAJ == root ]]; then
        GIT_TERMINAL_PROMPT=0 timeout 120 git -C "$REPO" "$@" </dev/null
    else
        timeout 120 runuser -u "$TULAJ" -- env HOME="$TULAJ_HOME" GIT_TERMINAL_PROMPT=0 \
            GIT_AUTHOR_NAME="BaninaPRO szerver" GIT_AUTHOR_EMAIL="$TULAJ@localhost" \
            GIT_COMMITTER_NAME="BaninaPRO szerver" GIT_COMMITTER_EMAIL="$TULAJ@localhost" git -C "$REPO" "$@" </dev/null
    fi
}

# a kód verziószáma (a docker/config.php-ból – a szerver beállítófájlja ebből készül)
kod_verzio() { sed -n "s/^define('APP_VERSION', *'\([^']*\)');.*/\1/p" "$REPO/docker/config.php" 2>/dev/null | head -n 1 || true; }

# A teljes telepítő indítása (ami ennek a scriptnek sok): maga húzza le a kódot, és mindent rendbe tesz.
telepito_indit() {
    local t="$REPO/$TELEPITO_NEV"
    [[ -f $t ]] || hiba "$1 – ehhez a teljes telepítő kell, de nincs meg: $t"
    figy "$1"
    info "Ehhez a teljes telepítő kell – elindítom (néhány perc): $TELEPITO_NEV"
    naploz "Átadva a teljes telepítőnek: $1"
    exec 7>&-   # (a zárat a telepítő maga veszi fel)
    exec bash "$t"
}

# A szerver beállítófájlja a docker/config.php-ból: a szerver jelszavával, hibakijelzés nélkül – ugyanúgy, ahogy a
# telepítő készíti. Helyben írja felül (nem cseréli a fájlt): a futó konténer a fájlt az indulásakor csatolta, egy új
# fájlt csak újraindítás után látna. Kilépési kód: 0 = változatlan, 10 = frissítve, 1 = nem sikerült.
config_frissit() {
    local f="$TITOK_MAPPA/config.php" minta="$REPO/docker/config.php" jelszo="" sor uj="" volt_jelszo=0
    [[ -f $f && -f $minta ]] || return 1
    while IFS= read -r sor; do
        if [[ $sor =~ ^define\(\'DB_PASS\',\ *\'([^\']*)\'\)\; ]]; then jelszo="${BASH_REMATCH[1]}"; break; fi
    done < "$f"
    [[ -n $jelszo ]] || return 1
    while IFS= read -r sor || [[ -n $sor ]]; do
        if [[ $sor =~ ^define\(\'DB_PASS\',\ *\'[^\']*\'\)\;(.*)$ ]]; then
            sor="define('DB_PASS', '$jelszo');${BASH_REMATCH[1]}"
            volt_jelszo=1
        elif [[ $sor =~ ^define\(\'APP_DEBUG\',\ *true\)\;(.*)$ ]]; then
            sor="define('APP_DEBUG', false);${BASH_REMATCH[1]}"
        fi
        uj+="$sor"$'\n'
    done < "$minta"
    (( volt_jelszo )) || return 1
    printf '%s' "$uj" > "$f.uj" || return 1
    if cmp -s "$f.uj" "$f"; then rm -f "$f.uj"; return 0; fi
    cat "$f.uj" > "$f" || { rm -f "$f.uj"; return 1; }
    rm -f "$f.uj"
    return 10
}

# az oldal válaszol-e, és a várt verziót mutatja-e ($1) – a PHP a módosult fájlokat 2 másodpercen belül veszi észre
oldal_ellenorzes() {
    local kod="" oldal v=""
    oldal="$(mktemp)"
    for _ in 1 2 3 4 5 6 7 8; do
        kod="$(curl -s -o "$oldal" -w '%{http_code}' --max-time 20 "http://127.0.0.1:$APP_PORT/" 2>/dev/null || true)"
        v="$(grep -o '"verzio":"[^"]*"' "$oldal" 2>/dev/null | head -n 1 | cut -d'"' -f4 || true)"
        if [[ $kod == 200 && ( -z $1 || $v == "$1" ) ]] && grep -q '"hiba":null' "$oldal" 2>/dev/null; then
            rm -f "$oldal"
            OLDAL_VERZIO="$v"
            return 0
        fi
        sleep 1
    done
    OLDAL_VERZIO="$v" OLDAL_KOD="$kod"
    rm -f "$oldal"
    return 1
}

# parancs a BaninaPRO mappa tulajdonosa nevében (ő a szerver asztali felhasználója)
felh() {
    if [[ $TULAJ == root ]]; then "$@"; else runuser -u "$TULAJ" -- env HOME="$TULAJ_HOME" "$@"; fi
}

# a felhasználó asztalának mappája (magyarul: ~/Asztal)
asztal_mappa() {
    local m=""
    m="$(felh env LANG=hu_HU.UTF-8 LC_ALL=hu_HU.UTF-8 xdg-user-dir DESKTOP 2>/dev/null </dev/null || true)"
    if [[ -z $m || $m == "$TULAJ_HOME" || $m != "$TULAJ_HOME"/* || $m == *$'\n'* ]]; then
        if [[ -d $TULAJ_HOME/Asztal || ! -d $TULAJ_HOME/Desktop ]]; then m="$TULAJ_HOME/Asztal"; else m="$TULAJ_HOME/Desktop"; fi
    fi
    printf '%s' "$m"
}

# Egy asztali indító: kattintásra terminálablakban futtatja a scriptet (--ablak: a végén megvárja az Entert).
# Csak akkor írja újra, ha változott. Az Xfce akkor futtatja kérdés nélkül, ha futtatható, és az ellenőrzőösszege
# megbízhatónak van jelölve – ez a felhasználó futó munkamenetében állítható be; ha most nem megy, az Xfce az első
# kattintáskor egyszer rákérdez („Indítás mindenképp”).
ikon_keszit() {   # $1 = az indító fájlja, $2 = név, $3 = leírás, $4 = a script
    local f="$1" uj busz
    uj="$(printf '[Desktop Entry]\nType=Application\nVersion=1.0\nName=%s\nComment=%s\nExec=bash "%s" --ablak\nIcon=org.xfce.terminal\nTerminal=true\nCategories=System;\n' "$2" "$3" "$4")"
    if [[ ! -f $f || $(cat "$f" 2>/dev/null) != "$uj" ]]; then printf '%s\n' "$uj" > "$f" || return 1; fi
    chown "$TULAJ:" "$f" 2>/dev/null || true
    chmod 755 "$f" || return 1
    busz="/run/user/$(id -u "$TULAJ" 2>/dev/null || echo 0)/bus"
    if command -v gio >/dev/null && [[ -S $busz ]]; then
        felh env DBUS_SESSION_BUS_ADDRESS="unix:path=$busz" timeout 20 gio set -t string "$f" metadata::xfce-exe-checksum \
            "$(sha256sum < "$f" | cut -d' ' -f1)" >/dev/null 2>&1 </dev/null || true
    fi
}

# A három asztali ikon: „BaninaPro App Updater” (ez a script), „SQL IMPORT” (SQL_IMPORT.sh) és „DB MENTÉS” (DB_MENTES.sh)
# – mind a BaninaPRO mappában lévő példányt indítja, így a frissítésekkel együtt maguk is frissülnek.
IKONOK_SZAMA=3
asztali_ikonok() {
    local asztal db=0
    asztal="$(asztal_mappa)"
    felh mkdir -p "$asztal" 2>/dev/null </dev/null || return 1
    if [[ -f $REPO/$SAJAT_NEV ]] && ikon_keszit "$asztal/BaninaPro_App_Updater.desktop" "BaninaPro App Updater" \
        "A weboldal gyors frissítése a GitHubról (néhány másodperc)" "$REPO/$SAJAT_NEV"; then db=$(( db + 1 )); fi
    if [[ -f $REPO/$IMPORT_NEV ]] && ikon_keszit "$asztal/SQL_IMPORT.desktop" "SQL IMPORT" \
        "Egy SQL-mentés (pl. az éles oldal phpMyAdmin-exportja) betöltése az adatbázisba" "$REPO/$IMPORT_NEV"; then db=$(( db + 1 )); fi
    if [[ -f $REPO/$MENTES_NEV ]] && ikon_keszit "$asztal/DB_MENTES.desktop" "DB MENTÉS" \
        "Az adatbázis teljes mentése most – a gép lemezére és a bedugott pendrive-ra is" "$REPO/$MENTES_NEV"; then db=$(( db + 1 )); fi
    # az asztalra letöltött .sh másolat helyett mostantól az ikon indítja (a BaninaPRO mappában lévő példányt)
    if (( db == IKONOK_SZAMA )) && [[ $(dirname "$SCRIPT") == "$asztal" && $SCRIPT == *.sh && -f $REPO/$SAJAT_NEV ]]; then
        if rm -f -- "$SCRIPT"; then info "Az asztalra letöltött $(basename "$SCRIPT") fájlt töröltem: helyette az ikonra kattints."; fi
    fi
    IKONOK_DB=$db
    IKONOK_HELYE="$asztal"
    (( db == IKONOK_SZAMA ))
}

main() {
    local regi uj ag f n verzio_elotte verzio rc nehez=() valtozott=() ertesites a ablak=0 csak_ikonok=0 tobbi=()
    for a in "$@"; do
        case $a in
            --ablak) ablak=1 ;;          # az asztali ikonról: a végén megvárja az Entert, hogy az ablak ne tűnjön el
            --ikonok) csak_ikonok=1; tobbi+=("$a") ;;   # csak az asztali ikonok elkészítése (a teljes telepítő hívja)
            *) tobbi+=("$a") ;;
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
    # ha ez egy másolat (pl. az asztalon), és a BaninaPRO mappában már újabb változata van: az fut – a másolatot így
    # soha nem kell újra letölteni
    if [[ $SCRIPT != "$REPO/$SAJAT_NEV" && -f $REPO/$SAJAT_NEV && -z ${BANINA_UPDATER_ATADVA:-} ]]; then
        f="$(sed -n 's/^VALTOZAT=\([0-9][0-9]*\).*/\1/p' "$REPO/$SAJAT_NEV" 2>/dev/null | head -n 1 || true)"
        if [[ $f =~ ^[0-9]+$ ]] && (( f > VALTOZAT )); then
            export BANINA_UPDATER_ATADVA=1
            exec bash "$REPO/$SAJAT_NEV" "$@"
        fi
    fi
    printf '\n%sBaninaPRO – gyors frissítés%s  (%s)\n' "$C_F" "$C_N" "$(date '+%Y-%m-%d %H:%M')"
    TULAJ="$(stat -c %U "$REPO" 2>/dev/null || echo root)"
    if [[ $TULAJ == UNKNOWN || -z $TULAJ ]]; then TULAJ=root; fi
    TULAJ_HOME="$(getent passwd "$TULAJ" | cut -d: -f6)"
    [[ -n $TULAJ_HOME ]] || TULAJ_HOME=/root
    if (( csak_ikonok )); then
        asztali_ikonok || { echo "Az asztali ikonok nem készültek el ($IKONOK_DB/$IKONOK_SZAMA, $IKONOK_HELYE)."; exit 1; }
        echo "Asztali ikonok: BaninaPro App Updater, SQL IMPORT, DB MENTÉS ($IKONOK_HELYE)"
        exit 0
    fi
    f="$(orszem_ertek APP_PORT)"
    if [[ $f =~ ^[0-9]+$ ]]; then APP_PORT=$f; fi
    command -v git >/dev/null || hiba "A git nincs telepítve – futtasd a teljes telepítőt (szerver_beallitas.sh)."
    [[ -f $TITOK_MAPPA/config.php ]] || telepito_indit "A szerver beállítófájlja hiányzik ($TITOK_MAPPA/config.php)"

    # egyszerre ne fusson a teljes telepítővel (ugyanazt a zárat fogja)
    exec 7>/run/baninapro-telepito.lock
    if ! flock -n 7; then
        hiba "A teljes telepítő (szerver_beallitas.sh) éppen fut – az úgyis frissíti a BaninaPRO-t; várd meg, amíg befejezi."
    fi

    # 1) mi van a GitHubon
    if ! g fetch -q origin "+refs/heads/$REPO_AG:refs/remotes/origin/$REPO_AG" 2>/dev/null; then
        hiba "A GitHub nem érhető el (nincs internet, vagy a repó címe hibás) – a BaninaPRO változatlan maradt."
    fi
    regi="$(g rev-parse HEAD 2>/dev/null || true)"
    uj="$(g rev-parse "refs/remotes/origin/$REPO_AG" 2>/dev/null || true)"
    ag="$(g rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
    [[ -n $regi && -n $uj ]] || telepito_indit "A BaninaPRO mappa git-tárolója nincs rendben"
    [[ $ag == "$REPO_AG" ]] || telepito_indit "A szerver kódja nem a(z) $REPO_AG ágon áll (hanem: ${ag:-?})"
    verzio_elotte="$(kod_verzio)"

    if [[ $regi == "$uj" ]] || g merge-base --is-ancestor "$uj" "$regi" 2>/dev/null; then
        # nincs új kód – a beállítófájl és az oldal azért legyen rendben
        config_frissit
        rc=$?
        if (( rc == 10 )); then ok "A szerver beállítófájlja frissítve"; fi
        if (( rc == 1 )); then figy "A szerver beállítófájlja nem frissíthető – futtasd a teljes telepítőt."; fi
        ok "Nincs új változás: a szerveren már a legfrissebb kód van (BaninaPRO ${verzio_elotte:-?}, ${regi:0:7})"
        if asztali_ikonok; then ok "Asztali ikonok rendben: BaninaPro App Updater, SQL IMPORT, DB MENTÉS"; fi
        if oldal_ellenorzes "$verzio_elotte"; then
            ok "Az oldal működik"
        else
            figy "Az oldal nem válaszol rendesen (HTTP ${OLDAL_KOD:-?}) – futtasd a teljes telepítőt: sudo bash \"$REPO/$TELEPITO_NEV\""
        fi
        exit 0
    fi
    g merge-base --is-ancestor "$regi" "$uj" 2>/dev/null \
        || telepito_indit "A szerver kódja eltér a GitHubétól (helyi commitok vannak rajta)"

    # 2) mi változott – ami ennek a scriptnek sok, azt a teljes telepítő viszi
    mapfile -t valtozott < <(g diff --name-only "$regi" "$uj" 2>/dev/null || true)
    for f in "${valtozott[@]}"; do
        for n in "${NEHEZ_FAJLOK[@]}"; do
            if [[ $f == "$n" ]]; then nehez+=("$f"); fi
        done
    done
    if (( ${#nehez[@]} )); then
        telepito_indit "Ez a frissítés a weboldalon túl mást is érint (${nehez[*]}): adatbázis-szerkezet vagy a konténerek"
    fi

    # 3) a kód felülírása (a szerveren kézzel módosított fájlok előbb félre – visszahozhatók: git stash list)
    if [[ -n $(g status --porcelain --untracked-files=no 2>/dev/null || true) ]]; then
        if g stash push -q -m "BaninaPro_App_Updater $(date '+%Y-%m-%d %H:%M')" >/dev/null 2>&1; then
            info "A szerveren kézzel módosított fájlokat félretettem (visszahozhatók: git -C \"$REPO\" stash list)"
        fi
    fi
    if ! g merge -q --ff-only "$uj" >/dev/null 2>&1; then
        telepito_indit "A kód nem írható felül egyszerűen (pl. egy helyi fájl útban van)"
    fi
    ok "Új kód letöltve: ${#valtozott[@]} fájl (${regi:0:7} → ${uj:0:7})"
    n=0
    for f in "${valtozott[@]}"; do
        n=$(( n + 1 ))
        if (( n > 12 )); then printf '      … és még %d fájl\n' $(( ${#valtozott[@]} - 12 )); break; fi
        printf '      %s\n' "$f"
    done

    # 4) a szerver beállítófájlja (verziószám, új beállítások)
    verzio="$(kod_verzio)"
    config_frissit
    rc=$?
    if (( rc == 10 )); then ok "A szerver beállítófájlja frissítve (verzió: ${verzio:-?})"; fi
    if (( rc == 1 )); then telepito_indit "A szerver beállítófájlja nem frissíthető"; fi

    # 5) ellenőrzés
    if oldal_ellenorzes "$verzio"; then
        ok "Az oldal működik: BaninaPRO ${OLDAL_VERZIO:-?}"
    else
        telepito_indit "A frissítés után az oldal nem válaszol rendesen (HTTP ${OLDAL_KOD:-?}, verzió: ${OLDAL_VERZIO:-?}, várt: ${verzio:-?})"
    fi
    for f in "${valtozott[@]}"; do
        if [[ $f == "$TELEPITO_NEV" ]]; then
            info "A szerver telepítője is frissült. A weboldal kész; a szerver beállításaihoz (őrszem, jelentés, mentés…)"
            info "alkalmas időben futtasd le:  sudo bash \"$REPO/$TELEPITO_NEV\""
        fi
    done

    if asztali_ikonok; then ok "Asztali ikonok rendben: BaninaPro App Updater, SQL IMPORT, DB MENTÉS (kattintásra indulnak)"; fi

    if [[ $verzio_elotte != "$verzio" ]]; then ertesites="BaninaPRO ${verzio_elotte:-?} → ${verzio:-?}"; else ertesites="BaninaPRO ${verzio:-?}"; fi
    naploz "Frissítve: $ertesites, ${#valtozott[@]} fájl (${regi:0:7} → ${uj:0:7})"
    if [[ -x $ERTESITO ]]; then
        # (a háttérben: a küldés ne tartsa fel – ha nincs internet, sorba áll)
        setsid -f "$ERTESITO" -p 3 -t arrow_up "BaninaPRO frissítve" \
            "$ertesites – ${#valtozott[@]} fájl (gyors frissítés, ${regi:0:7} → ${uj:0:7})." >/dev/null 2>&1 7>&- || true
    fi
    printf '\n  %sKész %d másodperc alatt.%s\n\n' "$C_F" "$SECONDS" "$C_N"
}

OLDAL_VERZIO="" OLDAL_KOD="" IKONOK_DB=0 IKONOK_HELYE=""
main "$@"
exit 0
