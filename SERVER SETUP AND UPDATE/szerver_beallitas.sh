#!/usr/bin/env bash
# =============================================================================
#  BaninaPRO – szerver telepítő és frissítő
#
#  Egy friss (szűz) Ubuntu Serverből egyetlen futtatással kész BaninaPRO szerver lesz:
#  magyar nyelv és billentyűzet, LXQt asztal automatikus belépéssel, AnyDesk, Docker Engine, SSH,
#  és a BaninaPRO kész adatbázissal, a belső hálózat bármely gépéről elérhetően:
#    BaninaPRO:   http://<a szerver IP-címe>        vagy  http://baninapro.local
#    phpMyAdmin:  http://<a szerver IP-címe>:8081   (BaninaPRO / BaninaPRO1234 – kezdőjelszó, változtasd meg)
#  Szerverként üzemel: soha nem alszik el, nincs képernyővédő, áramszünet után magától bekapcsol (ahol a
#  BIOS engedi), az AnyDesk mindig fut, és egy őrszem 2 percenként ellenőrzi a BaninaPRO-t: ha nem érhető el,
#  emberi beavatkozás nélkül helyreállítja (konténerek indítása, újraindítása, a Docker újraindítása,
#  végső esetben – ritkán – a gép újraindítása). Napló: /var/log/baninapro-orszem.log
#  Push-értesítések a telefonra (ntfy, ingyenes, fiók nélkül): áramszünet / újraindulás / leállás, hibák és
#  helyreállás (BaninaPRO, Docker, AnyDesk, konténerek, tárhely), az éjszakai mentés, a napi jelentés (03:30),
#  a be- és kilépések. A feliratkozás leírása az összegzésben és az asztalon (BaninaPRO-ertesitesek.txt).
#  A napi jelentés e-mailben is mehet, ha megadsz egy feladó-postafiókot:  sudo bash szerver_beallitas.sh --email
#
#  ELŐKÉSZÜLET (egyszer, kézzel) – a repó nyilvános, a letöltéshez nem kell GitHub-fiók vagy -kulcs:
#    1. Ubuntu Server telepítése (a minimális változat is jó) – felhasználó: baninapro, gépnév: baninapro
#    2. a BaninaPRO letöltése:  git clone https://github.com/Sarokin/BaninaPRO.git ~/BaninaPRO
#       vagy csak ez az egy fájl (ha a git még nincs fent):
#       wget -O szerver_beallitas.sh "https://raw.githubusercontent.com/Sarokin/BaninaPRO/main/SERVER%20SETUP%20AND%20UPDATE/szerver_beallitas.sh"
#    Minden mást a script maga tölt le és telepít (curl, git, Docker Engine, a Docker-képek, az asztal…). Ha csak ezt
#    az egy fájlt futtatod, letölti a repót a ~/BaninaPRO mappába, és onnan folytatja.
#
#  FUTTATÁS – első telepítés, később minden frissítés, és BÁRMILYEN HIBA UTÁN is ugyanez:
#    cd ~/BaninaPRO/"SERVER SETUP AND UPDATE"
#    sudo bash szerver_beallitas.sh
#  Kapcsolók:  --email                 a napi jelentés e-mail-feladójának (újra)beállítása
#              --nincs-visszaallitas   üres adatbázisnál se állítsa vissza a legutóbbi mentést (lásd lent)
#
#  Újrafuttatva frissít: adatbázis-mentés → git pull → rendszerfrissítés → konténerek újraépítése → takarítás.
#  Automatikus rendszerfrissítés NINCS (kevés a tárhely): a gép magától nem keres, nem tölt le és nem telepít
#  frissítést – csak ennek a scriptnek a kézi futtatásakor frissül, utána törli a régi kerneleket és a letöltött csomagokat.
#  Bármikor nyugodtan újrafuttatható: ami már kész, azt csak ellenőrzi.
#  Önjavító – bármi romlott el, a futtatása után a BaninaPRO újra fut. Amit magától rendbe tesz:
#    - csomagkezelés: félbemaradt csomagtelepítés, hiányzó alapeszközök (curl, git…), hibás külső csomagtároló;
#    - hálózat: rossz rendszeridő, nem működő névfeloldás (tartalék DNS), leállt hálózati beállítás;
#    - Docker: hiányzó vagy sérült telepítés, letiltott szolgáltatás, hibás daemon.json, hiányzó compose / buildx,
#      elérhetetlen Docker Hub (a képeket a nyilvános tükrökről tölti le), foglalt port, idegen konténer;
#    - a BaninaPRO kódja: törölt vagy módosított programfájlok (a GitHubon lévő változat áll vissza – a módosítás
#      a git stash-ben megmarad), sérült git-tároló, átmásolt (nem git) mappa;
#    - adatbázis: hiányzó táblák, oszlopok, indexek (a kódhoz illő séma: a hiányzót pótolja, adatot nem töröl),
#      elveszett jelszavak, és ha az adatbázis elveszett vagy nem indul el: a legutóbbi mentésből visszaállítja.
#  Csak akkor áll meg, ha valami így sem javítható – akkor megmondja, mi a baj.
#
#  ADATBÁZIS-MENTÉSEK: minden éjjel 03:00-kor, az alkalmazás Docker-kötetébe, és a legutóbbi 14 a gép saját lemezére
#  is: /var/backups/baninapro (így egy elveszett Docker-tárhely után is megvan). Ha az adatbázis üres (új
#  telepítés, elveszett vagy újra létrehozott adatbázis), és van korábbi mentés, a script a legfrissebbet
#  automatikusan visszaállítja. Tiszta lappal indulni:  sudo bash szerver_beallitas.sh --nincs-visszaallitas
#  A képernyőn folyamatjelző mutatja, hol tart; a parancsok teljes kimenete a naplóba kerül:
#  /var/log/baninapro-szerver.log  (hibánál a napló utolsó sorai a képernyőn is megjelennek).
#  A végén – hiba esetén is – összegzés: minden lépés eredménye, és hogy a szerveren milyen címen érhető el
#  az oldal és a phpMyAdmin. Az összegzés a ~/BaninaPRO-osszegzes.txt fájlba is elmentődik.
# =============================================================================
set -Eeuo pipefail
umask 022

# ---- Beállítások ------------------------------------------------------------
GEPNEV="baninapro"
IDOZONA="Europe/Budapest"
NYELV="hu_HU.UTF-8"
# elérés: a BaninaPRO és a phpMyAdmin a belső hálózat bármely gépéről (a MySQL csak a szerveren belülről)
APP_PORT="80"                         # BaninaPRO:  http://<a szerver IP-címe>  vagy  http://baninapro.local
PMA_PORT="8081"                       # phpMyAdmin: http://<a szerver IP-címe>:8081 – jelszóval (root)
TITOK_MAPPA="/etc/baninapro"          # a szerver saját adatbázis-jelszavai és beállítófájlja – nincsenek a gitben
ORSZEM="/usr/local/sbin/baninapro-orszem"   # az őrszem: 2 percenként ellenőriz, és ha kell, helyreállít
ALAP_DB_ROOT="baninapro_root"         # a docker-compose.yml nyilvános root-jelszava – csak a lecseréléséhez kell
# a phpMyAdmin belépése: külön adatbázis-felhasználó, teljes joggal a BaninaPRO-adatbázishoz (a root jelszava véletlen
# és titkos marad). Csak akkor jön létre ezzel a kezdőjelszóval, ha még nincs – ha megváltoztatod, a script nem írja vissza.
PMA_FELH="BaninaPRO"
PMA_KEZDO_JELSZO="BaninaPRO1234"
# Napi állapotjelentés (a mentés 03:00-kor fut, utána): push-értesítésként mindig, e-mailben akkor, ha van feladó-
# postafiók. A címzett mindig ez – ehhez a fiókhoz nem kell hozzáférés. A feladó-postafiókot (pl. a céges tárhely egy
# postafiókja, vagy egy külön Gmail-fiók) csak a --email kapcsolóval kérdezi meg, és a szerveren tárolja
# (/etc/baninapro – a repóba nem kerül).
JELENTES_CIMZETT="sarokintamas@gmail.com"
JELENTES_IDO="03:30"
JELENTO="/usr/local/sbin/baninapro-jelentes"
# Push-értesítések a telefonra (ntfy – nyílt forrású, ingyenes, fiók nélkül): a script egy titkos csatornát generál,
# a telefonon az ntfy alkalmazással kell rá feliratkozni (az összegzés és az asztali jegyzet kiírja). Ha épp nincs
# internet, az értesítés sorba áll, és az őrszem később elküldi. (Az ntfy.sh e-mail-továbbítása fiókot kérne – nincs.)
NTFY_SZERVER="https://ntfy.sh"
ERTESITO="/usr/local/sbin/baninapro-ertesites"
BELEPESFIGYELO="/usr/local/sbin/baninapro-belepesfigyelo"
MENTO="/usr/local/sbin/baninapro-mentes"
MENTES_CRON="0 3 * * *"              # éjszakai adatbázis-mentés, mint az éles cron
# a nyilvános GitHub-repó: innen jön a kód és minden frissítés (https – kulcs és jelszó nélkül)
REPO_URL="https://github.com/Sarokin/BaninaPRO.git"
REPO_AG="main"
# ha a gépen nincs rendes felhasználó (pl. root-ként, egy felhőben indított gépen fut), ezt hozza létre
ALAP_FELH="baninapro"
# a legutóbbi adatbázis-mentések másolata a gép saját lemezén, a Dockeren kívül (ennyi marad meg)
GEP_MENTES="/var/backups/baninapro"
GEP_MENTES_DB=14
# a hivatalos Docker-képek nyilvános tükrei – ha a Docker Hub nem érhető el, vagy korlátozza a letöltést
KEP_TUKROK=(public.ecr.aws/docker/library mirror.gcr.io/library)
# tartalék, ha az AnyDesk csomagtárolója nem működne (ha ez a változat már nincs fent, a legfrissebbet keresi meg)
ANYDESK_DEB="https://deb.anydesk.com/pool/main/a/anydesk/anydesk_8.1.0_amd64.deb"
NAPLO="/var/log/baninapro-szerver.log"

# a docker-compose.yml-ből: konténer- és kötetnevek (projektnév: baninapro)
PROJEKT="baninapro"
APP_KONTENER="baninapro-app"
DB_KONTENER="baninapro-db"
PMA_KONTENER="baninapro-phpmyadmin"
APP_KEP="${PROJEKT}-app"                 # az alkalmazás képe (a compose így nevezi: projekt-szolgáltatás)
DB_KOTET="${PROJEKT}_db-adatok"
ADATOK_KOTET="${PROJEKT}_adatok"         # az alkalmazás naplója és mentései (LOG, DBBCKP)
ADMIN_KEZDO="admin / BaninaPRO-2026!"   # az sql/schema.sql kezdő adminja
WWW_UID=33                               # a www-data felhasználó a PHP-képben (ő írja a mentéseket)

# Nem kötelező csomagok: ha a telepítésük félbemarad és nem javítható, a script eltávolítja őket, hogy ne
# akasszák meg a többi telepítést (a következő futás újra megpróbálja). A Docker-lépés a saját csomagjait adja hozzá.
NEM_KOTELEZO_CSOMAGOK=(anydesk firefox firefox-l10n-hu)
DOCKER_CE_CSOMAGOK=(docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin docker-ce-rootless-extras)
DOCKER_UBUNTU_CSOMAGOK=(docker.io docker-compose-v2 docker-buildx containerd runc)

SCRIPT="$(readlink -f "${BASH_SOURCE[0]}")"
SCRIPT_DIR="$(dirname "$SCRIPT")"
REPO="$(dirname "$SCRIPT_DIR")"
SEMA="$REPO/sql/schema.sql"

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1
export LC_ALL=C.UTF-8 LANG=C.UTF-8     # a script alatt futó programok kimenete egységes legyen
unset LANGUAGE

UJRAINDITAS=0 ELSO_INDITAS=0 ARCH="" CEL_FELH="" CEL_HOME="" ANYDESK_JELSZO="" TMPD="" APT_ALLAPOT=/dev/null
UTOLSO_PARANCS="" ARGOK=() APT_LISTA_FRISS=0
# --nincs-visszaallitas: üres adatbázisnál se állítsa vissza a legutóbbi mentést (tiszta lappal indulás)
NINCS_VISSZAALLITAS=0
# a phpMyAdmin és a MySQL portja kifelé (ha egy idegen program foglalja, és nem szabadítható fel, kimarad – a BaninaPRO
# enélkül is működik); az adatbázis rendbetételének eredménye az összegzéshez
PMA_PORT_KI=1 DB_PORT_KI=1 VISSZAALLITVA="" SERULT_DB_MASOLAT=""
FIGYELMEZTETESEK=()
LEPESEK=()
# az összegzéshez: az épp futó lépés sorszáma, a lépések eredménye (ok / figy:N), az esetleges hibaüzenet
AKT_I=0 OSSZEGZES_KESZ=0 HIBA_UZENET=""
DB_ROOT_JELSZO="" DB_APP_JELSZO="" EMAIL_FELADO="" EMAIL_JELSZO="" EMAIL_SMTP_HOST="" EMAIL_SMTP_PORT="" JELENTES_FELADO="" EMAIL_UJ=0
EMAIL_KERDES=0 NTFY_CSATORNA=""   # --email: a feladó-postafiók megkérdezése (alapból nem kérdez)
LEPES_ALLAPOT=()
OSSZ_SOROK=()
AKT_LEPES="előkészítés" AKT_ROVID="előkészítés" AKT_MUVELET=""
# folyamatjelző: a lépések súlya ≈ a várható időtartamuk másodpercben
OSSZ_SULY=0 KESZ_SULY=0 AKT_SULY=0 LEPES_KEZDET=0
KEPERNYO_TTY=0 SZELESSEG=80 SAV_SZ=20 SAV_AKTIV=0 SPIN_I=0
C_KEK="" C_ZOLD="" C_SARGA="" C_PIROS="" C_F="" C_HALV="" C_N=""
S_OK="+" S_FIGY="!" S_HIBA="X" S_VONAL="==" S_PONT="-" S_SEP="-" S_TELE="#" S_URES="." S_SPIN='|/-\' S_ELL="~"

# ---- Képernyő és napló --------------------------------------------------------
# A 3-as leíró a képernyő; a parancsok kimenete (stdout, stderr) csak a naplóba kerül.
naplo_inditas() {
    # képernyő: a vezérlő terminál, ha van – így egy régebbi változatból újraindítva is ide ír
    if (exec 3>/dev/tty) 2>/dev/null; then
        exec 3>/dev/tty
    elif ! { true >&3; } 2>/dev/null; then
        exec 3>&1
    fi
    if [[ -z ${BANINA_NAPLO:-} ]]; then
        export BANINA_NAPLO=1
        if [[ -f $NAPLO ]] && (( $(stat -c %s "$NAPLO") > 20000000 )); then mv -f "$NAPLO" "$NAPLO.1"; fi
        touch "$NAPLO"
        chmod 600 "$NAPLO"
    fi
    exec >>"$NAPLO" 2>&1
}

kepernyo_beallitas() {
    if [[ -t 3 ]]; then
        KEPERNYO_TTY=1
        C_KEK=$'\e[1;36m' C_ZOLD=$'\e[1;32m' C_SARGA=$'\e[1;33m' C_PIROS=$'\e[1;31m' C_F=$'\e[1m' C_HALV=$'\e[2m' C_N=$'\e[0m'
    fi
    # a gép saját szöveges konzolján (TERM=linux) a betűkészletben nincs meg minden jel – ott egyszerűbbek
    if [[ ${TERM:-} != linux ]]; then
        S_OK="✔" S_FIGY="⚠" S_HIBA="✘" S_VONAL="━━" S_PONT="•" S_SEP="·" S_TELE="█" S_URES="░" S_SPIN="⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏" S_ELL="…"
    fi
}

torol() {   # a folyamatjelző sorának törlése, mielőtt más kerül a képernyőre
    if (( SAV_AKTIV )); then printf '\r\e[K' >&3 || true; SAV_AKTIV=0; fi
}
ki() { torol; printf '%s\n' "$*" >&3; printf '%s\n' "$*"; }
cim() {
    torol
    printf '\n%s%s %s %s%s\n' "$C_KEK" "$S_VONAL" "$*" "$S_VONAL" "$C_N" >&3
    printf '\n== %s ==  [%s]\n' "$*" "$(date '+%Y-%m-%d %H:%M:%S')"
}
ok()   { torol; printf '  %s%s%s %s\n' "$C_ZOLD" "$S_OK" "$C_N" "$*" >&3; printf '  OK  %s\n' "$*"; }
info() { torol; printf '  %s %s\n' "$S_PONT" "$*" >&3; printf '  ..  %s\n' "$*"; }
figy() {
    torol
    printf '  %s%s %s%s\n' "$C_SARGA" "$S_FIGY" "$*" "$C_N" >&3
    printf '  !!  %s\n' "$*"
    FIGYELMEZTETESEK+=("[$AKT_LEPES] $*")
}
naplo_vege() {   # a napló utolsó sorai a képernyőre – így látszik, mit írt ki a hibázó parancs
    torol
    printf '  %sA napló utolsó sorai (%s):%s\n' "$C_SARGA" "$NAPLO" "$C_N" >&3
    tail -n "${1:-15}" "$NAPLO" 2>/dev/null | sed 's/^/    | /' >&3 || true
}
hiba() {
    HIBA_UZENET="$*"
    torol
    printf '\n%s%s HIBA [%s]: %s%s\n' "$C_PIROS" "$S_HIBA" "$AKT_LEPES" "$*" "$C_N" >&3
    printf '  A hiba javítása után a script nyugodtan újrafuttatható. Napló: %s\n' "$NAPLO" >&3
    printf '\n  XX  HIBA [%s]: %s\n' "$AKT_LEPES" "$*"
    exit 1
}
varatlan_hiba() {   # $1 = kilépési kód, $2 = sor, $3 = parancs
    local parancs="$3"
    if [[ $parancs == return* && -n $UTOLSO_PARANCS ]]; then parancs="$UTOLSO_PARANCS"; fi
    HIBA_UZENET="váratlan hiba ($2. sor, kilépési kód: $1): $parancs"
    naplo_vege 15
    printf '\n%s%s Váratlan hiba [%s] – %s. sor, kilépési kód: %s%s\n    Parancs: %s\n' \
        "$C_PIROS" "$S_HIBA" "$AKT_LEPES" "$2" "$1" "$C_N" "$parancs" >&3
    printf '  A hiba javítása után a script nyugodtan újrafuttatható. Napló: %s\n' "$NAPLO" >&3
    printf '\n  XX  Váratlan hiba [%s] – %s. sor, kilépési kód: %s: %s\n' "$AKT_LEPES" "$2" "$1" "$parancs"
}
# váratlan hiba: csak a fő folyamatban jelez és áll le (a $(…), a csővezetékek és a háttérparancsok belsejében nem)
trap 'rc=$?; if [[ $BASHPID == "$$" ]]; then varatlan_hiba "$rc" "$LINENO" "$BASH_COMMAND" || true; exit "$rc"; fi' ERR

# a napló mérete bájtban – egy parancs előtt feljegyezve utána csak az ő kimenete vizsgálható (naplo_resz)
naplo_meret() { stat -c %s "$NAPLO" 2>/dev/null || echo 0; }
naplo_resz() { tail -c +"$(( $1 + 1 ))" "$NAPLO" 2>/dev/null | tail -n 300 || true; }

# ---- Folyamatjelző ------------------------------------------------------------
# A teljes futás %-a becslés: a kész lépések súlya + a mostani lépésben eltelt idő a várhatóhoz képest.
# Az apt saját, valódi %-a zárójelben látszik.
szelesseg_frissit() {
    local s
    s="$(stty size 2>/dev/null </dev/tty || true)"   # (előbb a 2>: terminál nélkül a /dev/tty hibája se kerüljön a naplóba)
    s="${s##* }"
    if [[ $s =~ ^[0-9]+$ ]] && (( s >= 40 )); then SZELESSEG=$s; else SZELESSEG=80; fi
    if (( SZELESSEG < 70 )); then SAV_SZ=10; else SAV_SZ=20; fi
}

rajzol() {
    (( KEPERNYO_TTY )) || return 0
    local elt=$(( SECONDS - LEPES_KEZDET )) ezr=0 pct=0 teli sav ures szoveg ido sor k resz="" max
    local mezok=()
    if (( AKT_SULY > 0 )); then
        # a várható időig egyenletesen 80%-ig, utána lassulva közelít a 95%-hoz – sosem áll meg
        if (( elt < AKT_SULY )); then ezr=$(( 800 * elt / AKT_SULY ))
        else ezr=$(( 800 + 150 * (elt - AKT_SULY) / elt )); fi
    fi
    if (( OSSZ_SULY > 0 )); then pct=$(( (KESZ_SULY * 1000 + AKT_SULY * ezr) / (OSSZ_SULY * 10) )); fi
    if (( pct > 99 )); then pct=99; fi
    teli=$(( pct * SAV_SZ / 100 ))
    printf -v sav '%*s' "$teli" ''
    printf -v ures '%*s' $(( SAV_SZ - teli )) ''
    sav="${sav// /$S_TELE}"
    ures="${ures// /$S_URES}"

    szoveg="$AKT_ROVID"
    if [[ -n $AKT_MUVELET ]]; then
        szoveg+=" $S_SEP $AKT_MUVELET"
        if [[ -s $APT_ALLAPOT ]]; then
            # apt állapotsor: dlstatus:N:SZÁZALÉK:… vagy pmstatus:CSOMAG[:ARCH]:SZÁZALÉK:…
            sor="$(tail -n 1 "$APT_ALLAPOT" 2>/dev/null || true)"
            IFS=: read -r -a mezok <<<"$sor" || true
            for (( k = 2; k < ${#mezok[@]}; k++ )); do
                if [[ ${mezok[k]} =~ ^[0-9]+(\.[0-9]+)?$ ]]; then resz="${mezok[k]%%.*}%"; break; fi
            done
            if [[ -n $resz && ${mezok[0]:-} == dlstatus ]]; then resz="letöltés $resz"; fi
            if [[ -n $resz ]]; then szoveg+=" ($resz)"; fi
        fi
    fi
    printf -v ido '%02d:%02d' $(( elt / 60 )) $(( elt % 60 ))
    szoveg+=" $S_SEP $ido"
    max=$(( SZELESSEG - SAV_SZ - 15 ))
    if (( ${#szoveg} > max )); then szoveg="${szoveg:0:max-1}$S_ELL"; fi
    SPIN_I=$(( (SPIN_I + 1) % ${#S_SPIN} ))
    printf '\r  [%s%s%s%s%s%s] %3d%%  %s %s\e[K' "$C_ZOLD" "$sav" "$C_N" "$C_HALV" "$ures" "$C_N" \
        "$pct" "${S_SPIN:SPIN_I:1}" "$szoveg" >&3 || true
    SAV_AKTIV=1
}

kesz_sav() {   # a végén: teli sáv, 100%, teljes idő
    (( KEPERNYO_TTY )) || return 0
    local sav
    printf -v sav '%*s' "$SAV_SZ" ''
    sav="${sav// /$S_TELE}"
    torol
    printf '  [%s%s%s] 100%%  %s kész, összesen %02d:%02d\n' "$C_ZOLD" "$sav" "$C_N" "$S_OK" \
        $(( SECONDS / 60 )) $(( SECONDS % 60 )) >&3
}

# hosszabb parancs: a háttérben fut (kimenete a naplóba), közben a folyamatjelző folyamatosan pörög
fut() {
    local pid rc=0
    UTOLSO_PARANCS="$*"
    szelesseg_frissit
    # (a 7-es és a 8-as leírón a telepítő zárjai vannak: a gyerekfolyamat ne kapja meg – egy megszakított futás után
    #  ne tartsa fogva)
    "$@" 7>&- 8>&- &
    pid=$!
    while kill -0 "$pid" 2>/dev/null; do
        rajzol
        sleep 0.2
    done
    wait "$pid" || rc=$?
    return "$rc"
}
varj() {   # várakozás $1 másodpercig, közben a folyamatjelző pörög
    local i
    for (( i = 0; i < $1 * 5; i++ )); do rajzol; sleep 0.2; done
}
# parancs újrapróbálása (pl. átmeneti hálózati hiba): legfeljebb $1 alkalommal, növekvő szünetekkel
ujraprobal() {
    local n=$1 i
    shift
    for (( i = 1; i <= n; i++ )); do
        if fut "$@"; then return 0; fi
        if (( i < n )); then varj $(( 10 * i )); fi
    done
    return 1
}

# ---- Csomagkezelés ------------------------------------------------------------
# megvárja, amíg a háttérben futó automatikus frissítés elengedi az apt-ot (friss telepítés után gyakori)
apt_var() {
    local i
    for i in $(seq 1 120); do
        if ! fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock >/dev/null 2>&1; then
            return 0
        fi
        AKT_MUVELET="várakozás: a háttérben automatikus frissítés fut ($i)"
        varj 5
    done
}
# egyetlen apt-get futtatás, javítás és újrapróbálás nélkül
apt_nyers() {
    local rc=0
    apt_var
    : >"$APT_ALLAPOT"
    # a 4-es leírón az apt a saját, valódi előrehaladását jelzi (ezt mutatja a folyamatjelző zárójelben)
    fut apt-get -y -q -o DPkg::Lock::Timeout=600 -o APT::Status-Fd=4 -o APT::Keep-Downloaded-Packages=false \
        -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$@" 4>>"$APT_ALLAPOT" || rc=$?
    UTOLSO_PARANCS="apt-get $*"
    : >"$APT_ALLAPOT"
    return "$rc"
}
# apt-get önjavítással: hibánál megkeresi az okát a naplóban, kijavítja, és legfeljebb kétszer újrapróbálja
apt_() {
    local rc=0 m="csomagkezelés" proba kezdet
    case " $* " in
        *" update "*)       m="csomaglisták frissítése" ;;
        *" full-upgrade "*) m="rendszerfrissítés" ;;
        *" install "*)      m="csomagok telepítése" ;;
        *" remove "*)       m="csomagok eltávolítása" ;;
        *" autoremove "*)   m="felesleges csomagok törlése" ;;
    esac
    for proba in 1 2 3; do
        AKT_MUVELET="$m"
        if (( proba > 1 )); then AKT_MUVELET+=" ($proba. próba)"; fi
        kezdet="$(naplo_meret)"
        rc=0
        apt_nyers "$@" || rc=$?
        if (( rc == 0 || proba == 3 )); then break; fi
        apt_javit "$kezdet" "$@"
    done
    AKT_MUVELET=""
    return "$rc"
}
telepit()     { apt_ install "$@"; }
telepit_min() { apt_ install --no-install-recommends "$@"; }
van_csomag()  { [[ $(dpkg-query -W -f='${db:Status-Abbrev}' "$1" 2>/dev/null) == ii* ]]; }

# a megadott nevek közül azok, amelyeknek van telepíthető változata (a csak „virtuális” nevek kimaradnak)
elerheto() {
    (( $# )) || return 0
    apt-cache policy "$@" 2>/dev/null \
        | awk '/^[^ ].*:$/ { p = substr($0, 1, length($0) - 1) } /^  Candidate:/ && $2 != "(none)" { print p }' || true
}
apt_hibak() {   # az apt utolsó hibaüzenetei a naplóból, egy sorban
    tail -n 80 "$NAPLO" 2>/dev/null | grep -E '^E: ' | awk '!volt[$0]++' | tail -n 2 | paste -sd ' ' - || true
}
# nem kötelező csomagok: ami nem érhető el vagy nem települ, arra csak figyelmeztet, és megy tovább
telepit_opcionalis() {
    local csomagok=() hibas=() p
    mapfile -t csomagok < <(elerheto "$@")
    (( ${#csomagok[@]} )) || return 0
    if telepit_min "${csomagok[@]}"; then return 0; fi
    fut dpkg --configure -a || true
    for p in "${csomagok[@]}"; do
        if ! telepit_min "$p"; then hibas+=("$p"); fi
    done
    if (( ${#hibas[@]} )); then
        figy "Nem települt (nem kötelező, a működést nem érinti): ${hibas[*]} – $(apt_hibak)"
    fi
    return 0
}

# ---- Önjavítás ------------------------------------------------------------------
# a félbemaradt (nem teljesen kicsomagolt vagy beállított) csomagok neve
hibas_csomagok() {
    dpkg-query -W -f='${db:Status-Abbrev} ${Package}\n' 2>/dev/null \
        | awk '{ s = substr($1, 2, 1); e = substr($1, 3, 1) } s ~ /[HUFWt]/ || e == "R" { print $NF }' || true
}

# A csomagkezelő rendbetétele: a félbemaradt telepítések befejezése, a hiányzó függőségek pótlása.
# Ami így sem javul, és nem kötelező (vagy a hívó megengedi: $@), azt eltávolítja – a következő futás újra
# megpróbálja telepíteni –, hogy ne akassza meg a többi telepítést. Ha így is maradt hiba, 1-gyel tér vissza.
csomagkezelo_rendbe() {
    local hibas=() maradt=() p eltavolithato=" ${NEM_KOTELEZO_CSOMAGOK[*]} $* "
    AKT_MUVELET="csomagkezelő ellenőrzése"
    apt_var
    fut dpkg --configure -a || true
    mapfile -t hibas < <(hibas_csomagok)
    if (( ${#hibas[@]} == 0 )); then AKT_MUVELET=""; return 0; fi

    info "Félbemaradt csomagtelepítés: ${hibas[*]} – javítom…"
    # Az AnyDesk telepítő szkriptje (postinst) az xdg-utils nélkül hibával áll le, és félbemaradt csomagot
    # hagy maga után, ami minden további apt-ot megakaszt – ez a leggyakoribb ok, ezért ezzel kezdem.
    AKT_MUVELET="hiányzó segédcsomagok pótlása"
    if ! apt_nyers install --no-install-recommends xdg-utils desktop-file-utils; then
        apt_nyers update || true
        apt_nyers install --no-install-recommends xdg-utils desktop-file-utils || true
    fi
    AKT_MUVELET="félbemaradt telepítések befejezése"
    apt_nyers -f install || true
    fut dpkg --configure -a || true
    mapfile -t hibas < <(hibas_csomagok)

    for p in "${hibas[@]}"; do
        if [[ $eltavolithato == *" $p "* || $p == *-l10n ]]; then
            AKT_MUVELET="$p eltávolítása"
            if apt_nyers remove "$p" || fut dpkg --remove --force-remove-reinstreq "$p"; then
                figy "A(z) $p telepítése félbemaradt, és nem volt javítható – eltávolítottam, hogy ne akassza meg a többit (a következő futás újra megpróbálja)."
            fi
        fi
    done
    fut dpkg --configure -a || true
    mapfile -t maradt < <(hibas_csomagok)
    AKT_MUVELET=""
    if (( ${#maradt[@]} )); then
        figy "Félbemaradt csomagok maradtak: ${maradt[*]} – $(apt_hibak)"
        return 1
    fi
    ok "Csomagkezelő rendbe téve"
}

# egy csomagtároló hibája a sajátja (aláírás, kulcs, hiányzó Release fájl) – nem hálózati és nem óra-probléma
TAROLO_HIBA='is not signed|NO_PUBKEY|EXPKEYSIG|KEYEXPIRED|sqv returned|Signing key on|OpenPGP signature verification failed|GPG error|does not have a Release file|no longer has a Release file|Clearsigned file'

# Az apt hibájának oka a naplóból ($1 = a parancs indulásakori naplóméret) és a javítása;
# utána a hívó (apt_) újrapróbálja. A többi argumentum az apt-get parancssora.
apt_javit() {
    local kezdet=$1 szoveg
    shift
    szoveg="$(naplo_resz "$kezdet")"
    info "Az apt-get hibát jelzett ($(apt_hibak)) – javítom, és újrapróbálom."
    if grep -qiE 'not valid yet|invalid for another' <<<"$szoveg"; then
        # rossz rendszeridő: ilyenkor minden csomagtároló aláírása „még nem érvényes” – csak az órát kell javítani
        ido_javit
    elif grep -E '^(E|W|Err:[0-9]+)' <<<"$szoveg" | grep -qE "$TAROLO_HIBA"; then
        # hibás külső csomagtároló: kikapcsolom, hogy a többi működjön (a lépése a tartalék megoldással megy tovább)
        tarolo_kikapcsol "$szoveg"
    fi
    # félbemaradt telepítés, törött függőségek
    if grep -qE 'dpkg was interrupted|error processing|returned an error code|[Uu]nmet dependencies|fix-broken|held broken packages|not fully installed|Unable to correct problems' <<<"$szoveg" \
        || [[ -n $(hibas_csomagok) ]]; then
        csomagkezelo_rendbe || true
    fi
    # hálózati vagy letöltési hiba: rövid szünet, a gyorsítótár ürítése, friss csomaglisták
    if grep -qE 'Failed to fetch|Temporary failure|Could not resolve|Could not connect|Connection (failed|timed out|refused|reset)|Hash Sum mismatch|unexpected size|Unable to fetch|Service Unavailable|Bad Gateway|Gateway Time' <<<"$szoveg"; then
        AKT_MUVELET="hálózati hiba – rövid várakozás, majd újra"
        if grep -qE 'Temporary failure resolving|Could not resolve' <<<"$szoveg"; then halozat_javit; fi
        varj 15
        apt-get clean >/dev/null 2>&1 || true
        if [[ " $* " != *" update "* ]]; then apt_nyers update || true; fi
    elif grep -qE 'Unable to locate package|has no installation candidate|is not available, but is referred to' <<<"$szoveg" \
        && [[ " $* " != *" update "* ]]; then
        # régi vagy hiányzó csomaglisták (pl. a telepítés óta nem frissültek): frissítés, majd újra
        apt_nyers update || true
    fi
    return 0
}

# a hibát okozó külső csomagtároló kikapcsolása – az Ubuntu saját tárolóihoz nem nyúl; a fájl félrekerül
tarolo_kikapcsol() {
    local hosztok=() h f hova=/var/backups/baninapro-apt
    mapfile -t hosztok < <(grep -E '^(E|W|Err:[0-9]+)' <<<"$1" | grep -E "$TAROLO_HIBA" \
        | grep -oE 'https?://[^/ ]+' | sed -E 's|^https?://||' | sort -u || true)
    for h in "${hosztok[@]}"; do
        case $h in *ubuntu.com | *canonical.com) continue ;; esac
        for f in /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; do
            if [[ -f $f ]] && grep -qF "$h" "$f"; then
                install -d -m 755 "$hova"
                mv -f "$f" "$hova/"
                figy "Hibás csomagtároló kikapcsolva: $h ($(basename "$f") → $hova)"
            fi
        done
    done
}

# rendszeridő: rossz órával a https és a csomagtárolók aláírása is „érvénytelen”
ido_javit() {
    local d=""
    AKT_MUVELET="rendszeridő szinkronizálása"
    timedatectl set-ntp true >/dev/null 2>&1 || true
    # az időszinkron szolgáltatása: Ubuntu 25.10 előtt a systemd-timesyncd, azóta a chrony
    systemctl restart systemd-timesyncd >/dev/null 2>&1 || true
    systemctl restart chrony >/dev/null 2>&1 || true
    varj 10
    if ! timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -qx yes; then
        # ha az NTP nem jut át (pl. tűzfal): az idő egy webszerver válaszfejlécéből (http – a https-hez már jó óra kell)
        if command -v curl >/dev/null; then
            d="$(curl -sI --max-time 15 http://archive.ubuntu.com/ubuntu/ 2>/dev/null | tr -d '\r' \
                | awk -F': ' 'tolower($1) == "date" { print $2; exit }' || true)"
        fi
        if [[ -n $d ]] && date -s "$d" >/dev/null 2>&1; then
            info "Rendszeridő beállítva: $(date '+%Y-%m-%d %H:%M')"
        fi
    fi
    AKT_MUVELET=""
}

# TCP-kapcsolat egy géphez (curl nélkül is – a bash saját /dev/tcp-je): $1 gép, $2 port
tcp_el() { timeout "${3:-10}" bash -c 'exec 9<>"/dev/tcp/$1/$2"' _ "$1" "$2" >/dev/null 2>&1; }
nevfeloldas_megy() { getent ahosts github.com >/dev/null 2>&1; }

# internetkapcsolat: indulás után a hálózat lassan éledhet, és rossz órával a https sem megy. Ha még nincs curl
# (minimális rendszer), a névfeloldást és a TCP-kapcsolatot nézi.
internet_van() {
    local i
    for i in 1 2 3 4 5 6; do
        if command -v curl >/dev/null; then
            if fut curl -fsS --max-time 20 -o /dev/null https://github.com; then return 0; fi
        elif nevfeloldas_megy && tcp_el github.com 443; then
            return 0
        fi
        case $i in
            1|4) halozat_javit ;;
            2)   ido_javit ;;
        esac
        AKT_MUVELET="internetkapcsolat ellenőrzése ($(( i + 1 )). próba)"
        varj 10
    done
    return 1
}

# A hálózat rendbetétele: ha nincs alapértelmezett útvonal, a hálózati beállítás újraalkalmazása (netplan, networkd,
# NetworkManager); ha a névfeloldás nem működik, tartalék DNS-szerverek.
halozat_javit() {
    AKT_MUVELET="a hálózat ellenőrzése"
    if [[ -z $(ip -4 route show default 2>/dev/null) && -z $(ip -6 route show default 2>/dev/null) ]]; then
        info "Nincs hálózati útvonal (alapértelmezett átjáró) – a hálózati beállítást újra alkalmazom…"
        if command -v netplan >/dev/null; then fut timeout 120 netplan apply || true; fi
        systemctl restart systemd-networkd >/dev/null 2>&1 || true
        systemctl restart NetworkManager >/dev/null 2>&1 || true
        varj 15
    fi
    if ! nevfeloldas_megy; then dns_javit; fi
    AKT_MUVELET=""
}

# Tartalék DNS-szerverek a router (DHCP) DNS-e mellé – a systemd-resolved mindkettőt kérdezi, az első jó válasz nyer.
# Csak akkor írja be, ha a névfeloldás nem működött (utána megmarad: ha a router DNS-e újra elromlana, ne álljon le).
dns_javit() {
    local f=/etc/systemd/resolved.conf.d/90-baninapro-dns.conf
    AKT_MUVELET="névfeloldás (DNS) javítása"
    if systemctl is-active --quiet systemd-resolved 2>/dev/null; then
        if [[ ! -f $f ]]; then
            mkdir -p /etc/systemd/resolved.conf.d
            printf '# BaninaPRO szerver: tartalék DNS-szerverek a router mellé (a szerver_beallitas.sh írta, mert a névfeloldás nem működött)\n[Resolve]\nDNS=1.1.1.1 9.9.9.9 8.8.8.8\nFallbackDNS=1.0.0.1 149.112.112.112 8.8.4.4\n' > "$f"
            figy "A névfeloldás (DNS) nem működött – tartalék DNS-szervereket állítottam be ($f)."
        fi
        # a /etc/resolv.conf a systemd-resolved-re mutasson (ha valami felülírta, és nincs benne névszerver)
        if ! grep -qE '^nameserver' /etc/resolv.conf 2>/dev/null && [[ -e /run/systemd/resolve/stub-resolv.conf ]]; then
            ln -sf ../run/systemd/resolve/stub-resolv.conf /etc/resolv.conf 2>/dev/null || true
        fi
        systemctl restart systemd-resolved >/dev/null 2>&1 || true
    elif ! grep -qE '^nameserver' /etc/resolv.conf 2>/dev/null; then
        if printf '# a szerver_beallitas.sh írta: a névfeloldás nem működött\nnameserver 1.1.1.1\nnameserver 9.9.9.9\n' >> /etc/resolv.conf 2>/dev/null; then
            figy "A névfeloldás (DNS) nem működött – tartalék DNS-szervereket írtam a /etc/resolv.conf-ba."
        fi
    fi
    varj 3
    AKT_MUVELET=""
}

# ---- Alapeszközök ---------------------------------------------------------------
# A script saját működéséhez kellő programok (parancs:csomag). Egy minimális (minimized) Ubuntu Serverről hiányozhat a
# curl, a git, a tanúsítványcsomag is: ezeket minden más előtt pótolja (az apt-hoz nem kell curl).
ALAP_PARANCSOK=(curl:curl git:git fuser:psmisc ss:iproute2 ip:iproute2 pgrep:procps ps:procps free:procps
    flock:util-linux runuser:util-linux findmnt:util-linux timeout:coreutils sha256sum:coreutils crontab:cron
    update-locale:locales)
alapeszkozok_biztosit() {
    local e hianyzo=()
    for e in "${ALAP_PARANCSOK[@]}"; do
        command -v "${e%%:*}" >/dev/null || hianyzo+=("${e#*:}")
    done
    [[ -s /etc/ssl/certs/ca-certificates.crt ]] || hianyzo+=(ca-certificates)
    [[ -d /usr/share/zoneinfo/Europe ]] || hianyzo+=(tzdata)
    (( ${#hianyzo[@]} )) || return 0
    mapfile -t hianyzo < <(printf '%s\n' "${hianyzo[@]}" | sort -u)
    info "Hiányzó alapeszközök: ${hianyzo[*]} – telepítem…"
    csomagkezelo_rendbe || true
    lista_frissites
    telepit_min "${hianyzo[@]}" || hiba "Az alapeszközök nem telepíthetők (${hianyzo[*]}): $(apt_hibak)"
    ok "Alapeszközök pótolva: ${hianyzo[*]}"
}
# a csomaglisták frissítése (futásonként egyszer elég)
lista_frissites() {
    if (( APT_LISTA_FRISS )); then return 0; fi
    if apt_ update; then APT_LISTA_FRISS=1; else figy "Az apt update hibát jelzett: $(apt_hibak) – folytatom."; fi
}

szabad_gb() { df -Pk / | awk 'NR == 2 { print int($4 / 1024 / 1024) }'; }
# helyfelszabadítás: letöltött csomagok, régi rendszernaplók, használaton kívüli Docker-képek és -gyorsítótár
# (a konténerekhez és a kötetekhez – az adatbázishoz – nem nyúl)
hely_felszabaditas() {
    AKT_MUVELET="hely felszabadítása"
    apt-get clean >/dev/null 2>&1 || true
    journalctl --vacuum-size=200M >/dev/null 2>&1 || true
    if docker_valaszol; then
        fut timeout 600 docker image prune -f || true
        fut timeout 600 docker builder prune -f || true
    fi
    AKT_MUVELET=""
}
# A Docker válaszol-e – időkorláttal: egy beragadt Docker-szolgáltatásnál egy sima docker-parancs a végtelenségig várna
docker_valaszol() { command -v docker >/dev/null && timeout "${1:-30}" docker info >/dev/null 2>&1; }

# az adatbázis-séma (sql/schema.sql) a gitben van; ha helyben elveszett, visszaállítja onnan
sema_rendben() { [[ -f $SEMA ]] && grep -q 'CREATE TABLE' "$SEMA"; }
sema_biztosit() {
    sema_rendben && return 0
    # egy korábbi, séma nélküli indításkor a Docker üres mappát hoz létre a fájl helyén
    if [[ -d $SEMA ]]; then rmdir "$SEMA" 2>/dev/null || true; fi
    felh git -C "$REPO" checkout HEAD -- sql/schema.sql >/dev/null 2>&1 || true
    sema_rendben
}

# A repó címe a nyilvános https-cím legyen – egy régebbi, SSH-val klónozott példánynál is –, így a frissítéshez
# nem kell GitHub-kulcs. Más repóra mutató címhez nem nyúl.
repo_cim_beallit() {
    local url
    [[ -d $REPO/.git ]] || return 0
    url="$(felh git -C "$REPO" remote get-url origin 2>/dev/null || true)"
    if [[ $url == "$REPO_URL" ]]; then return 0; fi
    if [[ -z $url ]]; then
        felh git -C "$REPO" remote add origin "$REPO_URL" || return 0
    elif [[ ${url,,} =~ github\.com[:/]sarokin/baninapro(\.git)?/?$ ]]; then
        felh git -C "$REPO" remote set-url origin "$REPO_URL" || return 0
    else
        info "A repó címe nem a nyilvános BaninaPRO-repó ($url) – nem módosítom."
        return 0
    fi
    ok "A repó címe: $REPO_URL (a frissítéshez nem kell GitHub-kulcs)"
}

# A script nem a BaninaPRO repóból fut (pl. csak ezt a fájlt töltötték le): letölti a nyilvános repót a felhasználó
# mappájába (~/BaninaPRO) – ha ott már van, frissíti –, és az ottani példánnyal folytatja.
repo_teljes() { [[ -f $REPO/docker-compose.yml && -f $REPO/docker/Dockerfile && -f $REPO/docker/config.php ]]; }
repo_letoltes() {
    local cel="$CEL_HOME/BaninaPRO" uj hova
    uj="$cel/SERVER SETUP AND UPDATE/szerver_beallitas.sh"
    info "A script nem a BaninaPRO mappájából fut – a repót letöltöm ide: $cel"
    AKT_MUVELET="a BaninaPRO letöltése (git clone)"
    if [[ -d $cel/.git || -f $cel/docker-compose.yml ]]; then
        # már megvan (git-tároló, vagy egy átmásolt BaninaPRO mappa): az ottani futás rendbe teszi és frissíti
        info "A(z) $cel már megvan – onnan folytatom (a frissítést az ottani futás végzi)."
    else
        if [[ -e $cel ]]; then
            hova="$cel.regi-$(date +%Y%m%d_%H%M%S)"
            mv "$cel" "$hova"
            figy "A(z) $cel már létezett, de nem a BaninaPRO volt benne – átneveztem ($hova)."
        fi
        ujraprobal 3 felh git -C "$CEL_HOME" clone -q -b "$REPO_AG" "$REPO_URL" "$cel" || hiba "A repó nem tölthető le: $REPO_URL"
    fi
    if [[ ! -f $uj && -d $cel/.git ]]; then
        felh git -C "$cel" checkout -q HEAD -- "SERVER SETUP AND UPDATE" >/dev/null 2>&1 || true
    fi
    AKT_MUVELET=""
    [[ -f $uj ]] || hiba "A BaninaPRO mappában ($cel) nincs meg a telepítő: $uj"
    ok "BaninaPRO: $cel – onnan folytatom."
    rm -rf "$TMPD"
    exec bash "$uj" "${ARGOK[@]}"
}

# A BaninaPRO mappájának rendbetétele a frissítés előtt: a fájlok a felhasználóéi, a git-tároló ép (beragadt zárfájl és
# félbehagyott git-művelet nélkül). Ha a mappa nem git-tároló (pl. átmásolták), vagy a tároló sérült, újra a GitHubhoz köti.
repo_rendbe() {
    local hova
    # tulajdonos: egy root-ként végzett git-művelet (sudo git …) után a fájlok egy része a rooté lehet – akkor a
    # felhasználó nevében futó git nem tud írni
    if [[ -n $(find "$REPO" -xdev ! -user "$CEL_FELH" -print -quit 2>/dev/null || true) ]]; then
        chown -R "$CEL_FELH:$(id -gn "$CEL_FELH")" "$REPO" || true
        info "A BaninaPRO mappa fájljai újra a(z) $CEL_FELH felhasználóéi."
    fi
    if [[ -d $REPO/.git ]]; then
        # egy megszakadt git-művelet (pl. áramszünet a frissítés közben) zárfájlja: ha most nem fut git, törölhető
        if [[ -e $REPO/.git/index.lock ]] && ! pgrep -x git >/dev/null; then
            rm -f "$REPO/.git/index.lock"
            info "Egy megszakadt git-művelet zárfájlja törölve (.git/index.lock)."
        fi
        # félbehagyott összefésülés, átrendezés
        if [[ -e $REPO/.git/MERGE_HEAD ]]; then felh git -C "$REPO" merge --abort >/dev/null 2>&1 || true; fi
        if [[ -d $REPO/.git/rebase-merge || -d $REPO/.git/rebase-apply ]]; then felh git -C "$REPO" rebase --abort >/dev/null 2>&1 || true; fi
        if [[ -e $REPO/.git/CHERRY_PICK_HEAD ]]; then felh git -C "$REPO" cherry-pick --abort >/dev/null 2>&1 || true; fi
        if felh git -C "$REPO" rev-parse -q --verify HEAD >/dev/null 2>&1 \
            && felh git -C "$REPO" status --porcelain --untracked-files=no >/dev/null 2>&1; then
            return 0
        fi
        hova="/var/backups/baninapro-repo/git-$(date +%Y%m%d_%H%M%S)"
        install -d -m 700 "$(dirname "$hova")"
        mv "$REPO/.git" "$hova"
        figy "A BaninaPRO git-tárolója sérült volt – félretettem ($hova), és újat hoztam létre a GitHubról."
    else
        info "A BaninaPRO mappa nem git-tároló (átmásolták?) – a GitHubhoz kötöm, így frissíthető."
    fi
    AKT_MUVELET="a BaninaPRO git-tárolójának létrehozása"
    if felh git -C "$REPO" init -q \
        && felh git -C "$REPO" remote add origin "$REPO_URL" \
        && ujraprobal 3 felh git -C "$REPO" fetch -q origin "$REPO_AG" \
        && fut felh git -C "$REPO" checkout -q -f -B "$REPO_AG" "origin/$REPO_AG"; then
        felh git -C "$REPO" branch -q -u "origin/$REPO_AG" "$REPO_AG" >/dev/null 2>&1 || true
        ok "A BaninaPRO mappa a GitHubhoz kötve – a GitHubon lévő változat áll benne"
    else
        figy "A BaninaPRO mappa nem köthető a GitHubhoz – a meglévő fájlokkal folytatom."
    fi
    AKT_MUVELET=""
}

# A kód frissítése a GitHubról: letöltés (fetch), majd előrelépés (fast-forward). A helyben módosított vagy törölt
# programfájlok félrekerülnek (git stash), így mindig a GitHubon lévő változat fut. Ha a helyi ág elvált a GitHubétól
# (pl. átírt előzmények), a helyi állapot egy mentő ágon marad, és a GitHub-változatra áll.
git_frissit() {
    local kezdet szoveg fajlok=() f hova ag mento
    repo_cim_beallit
    ujraprobal 3 felh git -C "$REPO" fetch --prune origin || return 1
    felh git -C "$REPO" rev-parse -q --verify "origin/$REPO_AG" >/dev/null 2>&1 || return 1
    # helyben módosított vagy törölt fájlok (csak a gitben lévők – a .gitignore-ban lévőkhöz és a szerver saját fájljaihoz,
    # pl. a docker-compose.override.yml-hez nem nyúl): a módosítás a git stash-be kerül, a fájl a gitből visszaáll
    if [[ -n $(felh git -C "$REPO" status --porcelain --untracked-files=no 2>/dev/null || true) ]]; then
        if fut felh git -C "$REPO" stash push -q -m "szerver_beallitas: automatikusan félretéve $(date '+%Y-%m-%d %H:%M')"; then
            figy "A szerveren módosítva vagy törölve voltak a BaninaPRO fájljai – a GitHubon lévő változatot állítottam vissza (a módosítás megmaradt: git stash list)."
        elif fut felh git -C "$REPO" reset -q --hard HEAD; then
            figy "A szerveren módosítva vagy törölve voltak a BaninaPRO fájljai – a GitHubon lévő változatot állítottam vissza."
        fi
    fi
    # a fő ágon álljon (ha egy másik ágon vagy leválasztott állapotban maradt)
    ag="$(felh git -C "$REPO" symbolic-ref --short -q HEAD 2>/dev/null || true)"
    if [[ $ag != "$REPO_AG" ]]; then
        if fut felh git -C "$REPO" checkout -q -B "$REPO_AG" "origin/$REPO_AG"; then
            felh git -C "$REPO" branch -q -u "origin/$REPO_AG" "$REPO_AG" >/dev/null 2>&1 || true
            figy "A BaninaPRO mappa nem a(z) $REPO_AG ágon állt (${ag:-leválasztott állapot}) – visszaállítottam a(z) $REPO_AG ágra."
        fi
    fi
    # naprakész, vagy előrébb jár (helyi, még fel nem töltött változtatás): nincs teendő
    if felh git -C "$REPO" merge-base --is-ancestor "origin/$REPO_AG" HEAD 2>/dev/null; then return 0; fi
    if felh git -C "$REPO" merge-base --is-ancestor HEAD "origin/$REPO_AG" 2>/dev/null; then
        kezdet="$(naplo_meret)"
        if fut felh git -C "$REPO" merge -q --ff-only "origin/$REPO_AG"; then return 0; fi
        # nyomon nem követett fájlok állnak az útjában: félre (.git/baninapro-felrerakva), és újra
        szoveg="$(naplo_resz "$kezdet")"
        hova="$REPO/.git/baninapro-felrerakva/$(date +%Y%m%d_%H%M%S)"
        if grep -q 'untracked working tree files would be overwritten' <<<"$szoveg"; then
            mapfile -t fajlok < <(awk '/untracked working tree files would be overwritten/ { b = 1; next }
                                       b && /^\t/ { sub(/^\t/, ""); print; next } { b = 0 }' <<<"$szoveg")
            for f in "${fajlok[@]}"; do
                if [[ -e $REPO/$f ]]; then
                    install -d -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH")" "$hova/$(dirname "$f")"
                    mv -f "$REPO/$f" "$hova/$f"
                    info "Félreraktam (a frissítés útjában volt): $f → .git/${hova#"$REPO/.git/"}"
                fi
            done
        fi
        if fut felh git -C "$REPO" merge -q --ff-only "origin/$REPO_AG"; then return 0; fi
        return 1
    fi
    # elvált: mentő ág a helyi állapotról, majd a GitHub-változat
    mento="baninapro-helyi-$(date +%Y%m%d-%H%M%S)"
    felh git -C "$REPO" branch "$mento" HEAD >/dev/null 2>&1 || true
    if fut felh git -C "$REPO" reset -q --hard "origin/$REPO_AG"; then
        figy "A helyi kód elvált a GitHubon lévőtől – a GitHub-változatra álltam (a helyi állapot a(z) $mento ágon megmaradt)."
        return 0
    fi
    return 1
}

# ---- Egyéb segédek ------------------------------------------------------------
# parancs futtatása a cél felhasználó nevében (a repó az övé, a git is az ő nevében fut), kérdezés nélkül. A runuser
# (util-linux) mindig megvan, és nem függ a sudo beállításaitól (az Ubuntu 25.10 óta a sudo-rs az alapértelmezett).
# A git-azonosító a git stash-hez kell (egy friss gépen nincs beállítva).
felh() {
    runuser -u "$CEL_FELH" -- env HOME="$CEL_HOME" GIT_TERMINAL_PROMPT=0 \
        GIT_AUTHOR_NAME="BaninaPRO szerver" GIT_AUTHOR_EMAIL="$CEL_FELH@$GEPNEV.local" \
        GIT_COMMITTER_NAME="BaninaPRO szerver" GIT_COMMITTER_EMAIL="$CEL_FELH@$GEPNEV.local" \
        GIT_SSH_COMMAND='ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20' "$@"
}

# docker compose a repó mappájából: a docker-compose.yml mellé a docker-compose.override.yml is betöltődik
# (időkorláttal: egy beragadt Docker ne akassza meg a telepítőt)
dc() { ( cd "$REPO" && timeout 3600 docker compose "$@" ); }

kontener_naplok() {   # a konténerek utolsó naplósorai a képernyőre és a naplóba
    local k
    torol
    for k in "$DB_KONTENER" "$APP_KONTENER"; do
        { printf '\n  --- %s (utolsó 25 sor) ---\n' "$k"; timeout 30 docker logs --tail 25 "$k" 2>&1 | sed 's/^/    /'; } \
            | tee /dev/fd/3 || true
    done
}

# kulcs=érték beállítása egy INI-fájl szakaszában ($4, alapból [User] – AccountsService); ha kell, létrehozza
ini_beallit() {
    local f=$1 k=$2 v=$3 sz=${4:-User}
    [[ -f $f ]] || printf '[%s]\n' "$sz" > "$f"
    grep -q "^\[$sz\]" "$f" || printf '\n[%s]\n' "$sz" >> "$f"
    if grep -q "^$k=" "$f"; then
        sed -i "s|^$k=.*|$k=$v|" "$f"
    else
        sed -i "/^\[$sz\]/a $k=$v" "$f"
    fi
}

# =============================================================================
#  Előkészítés
# =============================================================================
elofeltetelek() {
    AKT_LEPES="előfeltételek"
    cim "Előfeltételek ellenőrzése"
    local PRETTY_NAME="" ID="" szabad minimum=5
    # shellcheck disable=SC1091
    . /etc/os-release
    [[ $ID == ubuntu ]] || hiba "Ez a script Ubuntu Serverre készült (ez a rendszer: ${PRETTY_NAME:-ismeretlen})."
    ARCH="$(dpkg --print-architecture)"
    ok "Rendszer: $PRETTY_NAME ($ARCH)"

    cel_felhasznalo
    ok "Felhasználó: $CEL_FELH ($CEL_HOME)"

    AKT_MUVELET="internetkapcsolat ellenőrzése"
    internet_van || hiba "Nincs internetkapcsolat (a github.com nem érhető el, és a hálózat újraindítása sem segített) – ellenőrizd a hálózati kábelt / a routert, majd futtasd újra."
    AKT_MUVELET=""
    ok "Internetkapcsolat rendben"
    # a script saját eszközei – egy minimális rendszeren a curl, a git is hiányozhat
    alapeszkozok_biztosit

    if ! repo_teljes && [[ -d $REPO/.git ]]; then
        # véletlenül törölt fájlok: vissza a gitből
        felh git -C "$REPO" checkout HEAD -- docker-compose.yml docker >/dev/null 2>&1 || true
    fi
    # ha a script nem a repóból fut (pl. csak ezt a fájlt töltötték le): letölti a repót, és onnan folytatja
    repo_teljes || repo_letoltes
    ok "BaninaPRO mappa: $REPO"

    # első telepítéshez (asztal + Docker + képek) jóval több hely kell, mint egy frissítéshez
    if van_csomag lightdm && { van_csomag docker-ce || van_csomag docker.io; }; then minimum=2; fi
    szabad="$(szabad_gb)"
    if (( szabad < 8 )); then
        hely_felszabaditas
        szabad="$(szabad_gb)"
    fi
    if (( szabad < minimum )); then
        hiba "Túl kevés a szabad hely a lemezen: $szabad GB (legalább $minimum GB kell, első telepítéshez 8 GB ajánlott)."
    elif (( szabad < 8 )); then
        figy "Kevés a szabad hely: $szabad GB – első telepítéshez legalább 8 GB ajánlott."
    else
        ok "Szabad hely: $szabad GB"
    fi

    if sema_biztosit; then
        ok "Adatbázis-séma megvan (sql/schema.sql)"
    else
        info "Az sql/schema.sql még nincs meg – a git pull hozza le (a BaninaPRO indítása előtt újra ellenőrzöm)."
    fi
}

# A felhasználó, akinek a nevében a BaninaPRO fut (ő lép be automatikusan az asztalra, övé a BaninaPRO mappa): aki a
# scriptet sudo-val indította; root-ként indítva a BaninaPRO mappa tulajdonosa, vagy a gép első rendes felhasználója.
# Ha egy sincs (pl. egy csak root-tal telepített gépen), létrehozza.
cel_felhasznalo() {
    local u
    u="${SUDO_USER:-}"
    if [[ -z $u || $u == root ]]; then u="$(stat -c %U "$REPO" 2>/dev/null || true)"; fi
    if [[ -z $u || $u == root || $u == UNKNOWN ]] || ! getent passwd "$u" >/dev/null; then
        u="$(awk -F: '$3 >= 1000 && $3 < 60000 && $6 ~ /^\/home\// && $7 !~ /(nologin|false)$/ { print $1; exit }' /etc/passwd || true)"
    fi
    if [[ -z $u ]]; then
        u="$ALAP_FELH"
        if ! getent passwd "$u" >/dev/null; then
            useradd -m -s /bin/bash -c "BaninaPRO szerver" "$u" || hiba "Nem volt rendes felhasználó a gépen, és a(z) $u nem hozható létre."
            usermod -aG sudo "$u" >/dev/null 2>&1 || true
            figy "A gépen nem volt rendes felhasználó (csak root) – létrehoztam: $u. Jelszót így kaphat: sudo passwd $u"
        fi
    fi
    CEL_FELH="$u"
    CEL_HOME="$(getent passwd "$CEL_FELH" | cut -d: -f6 || true)"
    [[ -n $CEL_HOME && $CEL_HOME != / ]] || hiba "A(z) $CEL_FELH felhasználónak nincs saját mappája."
    if [[ ! -d $CEL_HOME ]]; then
        install -d -m 750 -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH")" "$CEL_HOME"
        cp -rT /etc/skel "$CEL_HOME" >/dev/null 2>&1 || true
        chown -R "$CEL_FELH:$(id -gn "$CEL_FELH")" "$CEL_HOME" || true
        info "A(z) $CEL_FELH saját mappája hiányzott – létrehoztam: $CEL_HOME"
    fi
}

# Frissítés: mentés a mostani adatbázisról, majd a legfrissebb kód. Ha közben maga a script is
# frissült, az új változat fut tovább (egyszer).
frissites_elokeszites() {
    [[ -z ${BANINA_UJRA:-} ]] || return 0
    AKT_LEPES="frissítés előkészítése"
    cim "Mentés és a legfrissebb kód letöltése"
    local elotte utana
    if docker_valaszol && [[ $(timeout 30 docker inspect -f '{{.State.Running}}' "$APP_KONTENER" 2>/dev/null || true) == true ]]; then
        AKT_MUVELET="adatbázis-mentés"
        if fut timeout 1800 docker exec "$APP_KONTENER" php -q cron_mentes.php; then
            ok "Adatbázis-mentés a frissítés előtt: $(tail -n 1 "$NAPLO")"
            # (egy korábbi telepítés mentő-segédje: a mentés a gép saját lemezére is)
            if [[ -x $MENTO ]]; then fut "$MENTO" masol || true; fi
        else
            figy "A frissítés előtti mentés nem sikerült: $(tail -n 1 "$NAPLO")"
        fi
        AKT_MUVELET=""
    else
        info "A BaninaPRO most nem fut – nincs mit menteni."
    fi

    elotte="$(sha256sum "$SCRIPT" | cut -d' ' -f1)"
    repo_rendbe
    AKT_MUVELET="a legfrissebb kód letöltése (git)"
    if git_frissit; then
        ok "A kód naprakész – a GitHubon lévő változat ($(felh git -C "$REPO" log -1 --format='%h, %cd' --date=format:'%Y-%m-%d %H:%M' 2>/dev/null || true))"
    else
        figy "A kód frissítése (git) nem sikerült – a meglévő kóddal folytatom. ($(tail -n 1 "$NAPLO"))"
    fi
    AKT_MUVELET=""
    utana="$(sha256sum "$SCRIPT" 2>/dev/null | cut -d' ' -f1 || true)"
    if [[ $elotte != "$utana" ]]; then
        info "A telepítő script is frissült – az új változattal folytatom."
        export BANINA_UJRA=1
        rm -rf "$TMPD"
        exec bash "$SCRIPT" "$@"
    fi
}

# Minden kérdés az elején, utána már nem kell a géphez nyúlni. Csak azt kérdezi, ami még nincs beállítva.
kerdesek() {
    local masodszor="" kell_anydesk=0 kell_email=0
    [[ -t 0 ]] || return 0
    van_csomag anydesk || kell_anydesk=1
    # az e-mailről csak kérésre kérdez (sudo bash szerver_beallitas.sh --email) – az értesítések push-ként mennek
    kell_email=$EMAIL_KERDES
    (( kell_anydesk || kell_email )) || return 0
    cim "Kérdések az elején (utána már nem kell a géphez nyúlni)"
    if (( kell_anydesk )); then
        torol
        printf '  AnyDesk jelszó a felügyelet nélküli eléréshez (Enter = kihagyás): ' >&3
        read -r -s ANYDESK_JELSZO || true
        printf '\n' >&3
        if [[ -n $ANYDESK_JELSZO ]]; then
            printf '  Még egyszer: ' >&3
            read -r -s masodszor || true
            printf '\n' >&3
            if [[ $ANYDESK_JELSZO != "$masodszor" ]]; then
                figy "A két jelszó nem egyezik – az AnyDesk jelszót most nem állítom be."
                ANYDESK_JELSZO=""
            fi
        fi
    fi
    if (( kell_email )); then
        email_kerdesek
    fi
}

# a feladó-postafiók a napi jelentéshez (a címzett mindig a JELENTES_CIMZETT – ahhoz nem kell hozzáférés)
email_kerdesek() {
    local domain alap
    torol
    printf '  A napi jelentés és a riasztások címzettje: %s (ehhez a fiókhoz nem kell hozzáférés).\n' "$JELENTES_CIMZETT" >&3
    printf '  A küldéshez egy feladó-postafiók kell: pl. a céges tárhely (cPanel) egy postafiókja, vagy egy erre a\n' >&3
    printf '  célra létrehozott Gmail-fiók. Feladó e-mail-címe (Enter = most kihagyom): ' >&3
    read -r EMAIL_FELADO || true
    EMAIL_FELADO="${EMAIL_FELADO// /}"
    if [[ -n $EMAIL_FELADO && $EMAIL_FELADO != *@*.* ]]; then
        figy "Ez nem e-mail-cím ($EMAIL_FELADO) – az e-mail-küldést most nem állítom be."
        EMAIL_FELADO=""
    fi
    [[ -n $EMAIL_FELADO ]] || return 0
    domain="${EMAIL_FELADO##*@}"
    if [[ ${domain,,} == gmail.com || ${domain,,} == googlemail.com ]]; then alap="smtp.gmail.com"; else alap="mail.$domain"; fi
    printf '  SMTP-szerver [%s]: ' "$alap" >&3
    read -r EMAIL_SMTP_HOST || true
    EMAIL_SMTP_HOST="${EMAIL_SMTP_HOST// /}"
    EMAIL_SMTP_HOST="${EMAIL_SMTP_HOST:-$alap}"
    printf '  Port [587]: ' >&3
    read -r EMAIL_SMTP_PORT || true
    [[ $EMAIL_SMTP_PORT =~ ^[0-9]+$ ]] || EMAIL_SMTP_PORT=587
    if [[ $alap == smtp.gmail.com ]]; then
        printf '  A Gmail-fiók alkalmazásjelszava (Google-fiók → Biztonság → Kétlépcsős azonosítás → Alkalmazásjelszavak): ' >&3
    else
        printf '  A postafiók jelszava: ' >&3
    fi
    read -r -s EMAIL_JELSZO || true
    printf '\n' >&3
    # a Google az alkalmazásjelszót szóközökkel tagolva mutatja – azok nem részei a jelszónak
    if [[ $alap == smtp.gmail.com ]]; then EMAIL_JELSZO="${EMAIL_JELSZO// /}"; fi
    if [[ -z $EMAIL_JELSZO ]]; then
        figy "Jelszó nélkül az e-mail-küldést most nem állítom be."
        EMAIL_FELADO=""
    fi
}

# =============================================================================
#  Tevékenységlista – ezen megy végig a script, sorban
#  (függvény | súly ≈ várható másodperc első telepítéskor | megnevezés)
# =============================================================================
lepesek_listaja() {
    LEPESEK=(
        "lepes_auto_frissites_ki|5|Automatikus rendszerfrissítés kikapcsolva: nem keres, nem tölt le (kevés a tárhely)"
        "lepes_rendszer|300|Rendszerfrissítés, alapcsomagok, tárhely és memória (napló-korlát, swap)"
        "lepes_gepnev|3|Gépnév ($GEPNEV) és időzóna ($IDOZONA)"
        "lepes_nyelv|45|Magyar nyelv és magyar billentyűzet"
        "lepes_asztal|360|Asztali környezet: LXQt + Xorg + LightDM, magyar feliratokkal"
        "lepes_autologin|2|Automatikus bejelentkezés ($CEL_FELH)"
        "lepes_bongeszo|90|Firefox böngésző (magyar, kezdőlap: http://localhost)"
        "lepes_anydesk|30|AnyDesk – mindig fut, a géppel együtt indul"
        "lepes_docker|90|Docker Engine – mindig fut, a géppel együtt indul"
        "lepes_ssh|15|Hálózat: SSH, gépnév ($GEPNEV.local), tűzfal, GitHub-elérés"
        "lepes_energia|5|Energia: soha nem alszik el, nincs képernyővédő, áramszünet után bekapcsol"
        "lepes_baninapro|240|BaninaPRO: konténerek és adatbázis (javítás, séma, visszaállítás mentésből) – a belső hálózatról is"
        "lepes_mentes_cron|2|Éjszakai adatbázis-mentés (03:00) – másolat a gép saját lemezére is"
        "lepes_ertesitesek|10|Push-értesítések a telefonra (ntfy): leállás, indulás, hibák, mentés, belépések"
        "lepes_orszem|5|Őrszem: ha valami leáll, magától helyreállítja, és értesít"
        "lepes_jelentes|20|Napi állapotjelentés ($JELENTES_IDO) és asztali ikon"
        "lepes_ellenorzes|45|Végső ellenőrzés: oldal, API, adatbázis, hálózat"
    )
}

futtat_lepesek() {
    local i=0 db=${#LEPESEK[@]} e fv suly nev elotte n
    OSSZ_SULY=0
    for e in "${LEPESEK[@]}"; do
        IFS='|' read -r fv suly nev <<<"$e"
        OSSZ_SULY=$(( OSSZ_SULY + suly ))
    done
    cim "TEVÉKENYSÉGLISTA"
    for e in "${LEPESEK[@]}"; do
        i=$(( i + 1 ))
        IFS='|' read -r fv suly nev <<<"$e"
        ki "$(printf '  %2d. %s' "$i" "$nev")"
    done
    i=0
    for e in "${LEPESEK[@]}"; do
        i=$(( i + 1 ))
        IFS='|' read -r fv suly nev <<<"$e"
        AKT_LEPES="$i/$db $nev" AKT_ROVID="$i/$db" AKT_SULY=$suly LEPES_KEZDET=$SECONDS AKT_I=$i
        cim "[$i/$db] $nev"
        elotte=${#FIGYELMEZTETESEK[@]}
        "$fv"
        n=$(( ${#FIGYELMEZTETESEK[@]} - elotte ))
        if (( n )); then LEPES_ALLAPOT[i]="figy:$n"; else LEPES_ALLAPOT[i]="ok"; fi
        KESZ_SULY=$(( KESZ_SULY + suly ))
    done
    AKT_SULY=0
}

# =============================================================================
#  A lépések
# =============================================================================
# Az automatikus rendszerfrissítés teljesen ki (a felhasználó kérése: kevés a tárhely, és ne fogyassza a gépet):
# nem keres (apt update), nem tölt le és nem telepít – sem az apt / unattended-upgrades, sem a snap, sem a
# firmware-frissítő, sem a hír- és kiadásfigyelő. A rendszer csak ennek a scriptnek a kézi futtatásakor frissül.
# Az őrszem 2 percenként ellenőrzi, hogy így maradjon.
AUTO_FRISSITO_IDOZITOK=(apt-daily.timer apt-daily-upgrade.timer fwupd-refresh.timer update-notifier-download.timer
    update-notifier-motd.timer motd-news.timer ua-timer.timer)
lepes_auto_frissites_ki() {
    local e
    # az apt saját beállítása: a periodikus munkák (lista-frissítés, letöltés, telepítés, takarítás) ki
    cat > /etc/apt/apt.conf.d/99baninapro-nincs-automatikus-frissites <<'EOF'
// BaninaPRO szerver: nincs automatikus frissítés – nem keres, nem tölt le, nem telepít (a szerver_beallitas.sh írta).
// A rendszer csak a szerver_beallitas.sh kézi futtatásakor frissül.
APT::Periodic::Enable "0";
APT::Periodic::Update-Package-Lists "0";
APT::Periodic::Download-Upgradeable-Packages "0";
APT::Periodic::Unattended-Upgrade "0";
APT::Periodic::AutocleanInterval "0";
Unattended-Upgrade::InstallOnShutdown "false";
EOF
    # az időzítők leállítva és letiltva (mask: semmi nem indíthatja el őket, egy csomagfrissítés sem kapcsolja vissza)
    for e in "${AUTO_FRISSITO_IDOZITOK[@]}" unattended-upgrades.service; do
        systemctl disable --now "$e" >/dev/null 2>&1 || true
        systemctl mask "$e" >/dev/null 2>&1 || true
    done
    # a szolgáltatásokat csak letiltja: ha egy épp fut, végigér (a megszakított csomagtelepítés többet ártana)
    for e in apt-daily.service apt-daily-upgrade.service fwupd-refresh.service; do
        systemctl mask "$e" >/dev/null 2>&1 || true
    done
    # új Ubuntu-kiadás figyelése ki
    if [[ -f /etc/update-manager/release-upgrades ]]; then
        sed -i 's/^Prompt=.*/Prompt=never/' /etc/update-manager/release-upgrades || true
    fi
    ok "Az apt nem keres, nem tölt le és nem telepít magától (unattended-upgrades, firmware-, hír- és kiadásfigyelő is ki)"
    # snap: ha van, az automatikus frissítése is ki (a snapok így sosem töltenek le maguktól)
    if command -v snap >/dev/null && systemctl is-active --quiet snapd 2>/dev/null; then
        if fut timeout 120 snap refresh --hold; then ok "Snap: az automatikus frissítés ki"
        else figy "A snap automatikus frissítését nem sikerült kikapcsolni."; fi
    fi
    ok "A rendszer csak akkor frissül, amikor ezt a scriptet kézzel futtatod (utána takarít: régi kernelek, letöltött csomagok)"
}

lepes_rendszer() {
    # egy korábbi, félbeszakadt futás vagy félbemaradt csomagtelepítés után a csomagkezelő rendbetétele
    csomagkezelo_rendbe || true
    forrasok_rendbe

    apt_ update || figy "Az apt update hibát jelzett: $(apt_hibak) – folytatom."
    APT_LISTA_FRISS=1
    if apt_ full-upgrade; then
        ok "Rendszer frissítve"
    else
        figy "A rendszerfrissítés nem sikerült teljesen: $(apt_hibak) – folytatom, a következő futás újra megpróbálja."
    fi
    # kevés a tárhely: a már nem kellő csomagok (pl. a régi kernelek) törlése – az automatikus frissítés ezt nem végzi
    if apt_ autoremove --purge; then ok "Felesleges csomagok (pl. régi kernelek) törölve"
    else figy "A felesleges csomagok törlése nem sikerült: $(apt_hibak)"; fi
    telepit ca-certificates curl wget gnupg git openssh-server cron psmisc locales \
        || hiba "Az alapcsomagok nem telepíthetők: $(apt_hibak)"
    telepit_opcionalis keyboard-configuration console-setup software-properties-common
    universe_bekapcsol
    ok "Alapcsomagok telepítve (curl, wget, git, openssh-server, cron…)"
    tarhely_memoria
}

# Az Ubuntu csomagforrásai: ha egy sincs bekapcsolva (törölt vagy kikommentezett forrásfájl), a gyári beállítás vissza
forrasok_rendbe() {
    local kod uri f=/etc/apt/sources.list.d/ubuntu.sources
    if grep -qsE '^[[:space:]]*deb[[:space:]].*/ubuntu(-ports)?/?[[:space:]]' /etc/apt/sources.list /etc/apt/sources.list.d/*.list \
        || grep -qsE '^[[:space:]]*URIs:.*/ubuntu(-ports)?/?([[:space:]]|$)' /etc/apt/sources.list.d/*.sources; then
        return 0
    fi
    kod="$(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")"
    case $ARCH in amd64|i386) uri="http://archive.ubuntu.com/ubuntu/" ;; *) uri="http://ports.ubuntu.com/ubuntu-ports/" ;; esac
    if [[ -f $f ]]; then cp -p "$f" "$f.$(date +%Y%m%d_%H%M%S).regi"; fi
    printf 'Types: deb\nURIs: %s\nSuites: %s %s-updates %s-backports\nComponents: main restricted universe multiverse\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n\nTypes: deb\nURIs: %s\nSuites: %s-security\nComponents: main restricted universe multiverse\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n' \
        "$uri" "$kod" "$kod" "$kod" "${uri/archive.ubuntu.com/security.ubuntu.com}" "$kod" > "$f"
    figy "Az Ubuntu csomagforrásai hiányoztak vagy ki voltak kapcsolva – visszaállítottam a gyári beállítást ($f)."
}

# az universe csomagtároló (innen jön az LXQt és az Ubuntu saját Docker-csomagja)
universe_bekapcsol() {
    [[ -z $(elerheto lxqt-core) ]] || return 0
    AKT_MUVELET="universe csomagtároló bekapcsolása"
    if ! { command -v add-apt-repository >/dev/null && fut add-apt-repository -y universe; }; then
        # tartalék: közvetlenül a forrásfájlba (új formátum: ubuntu.sources; régi, egysoros: sources.list)
        if [[ -f /etc/apt/sources.list.d/ubuntu.sources ]]; then
            sed -i -E '/^Components:/{/universe/!s/$/ universe/}' /etc/apt/sources.list.d/ubuntu.sources
        fi
        if [[ -f /etc/apt/sources.list ]]; then
            sed -i -E '/^[[:space:]]*deb(-src)?[[:space:]].*\/ubuntu(-ports)?\/?[[:space:]]/{/universe/!s/[[:space:]]*$/ universe/}' /etc/apt/sources.list
        fi
    fi
    AKT_MUVELET=""
    apt_ update || true
    [[ -n $(elerheto lxqt-core) ]] || figy "Az universe csomagtároló nem kapcsolható be – az asztal telepítése elakadhat."
}

# Tárhely és memória: a rendszernapló legfeljebb 200 MB. Ha nincs swap, és kevés a memória (4 GB alatt), egy 2 GB-os
# swap-fájl – így a MySQL, a böngésző és a Docker-építés egyszerre sem fogyasztja el a memóriát (csak ha bőven van hely).
tarhely_memoria() {
    local f=/etc/systemd/journald.conf.d/50-baninapro.conf mem_mb
    if ! grep -qs '^SystemMaxUse=200M' "$f"; then
        mkdir -p "$(dirname "$f")"
        printf '# BaninaPRO szerver: a rendszernapló legfeljebb 200 MB (a szerver_beallitas.sh írta)\n[Journal]\nSystemMaxUse=200M\nRuntimeMaxUse=100M\n' > "$f"
        systemctl restart systemd-journald >/dev/null 2>&1 || true
    fi
    ok "A rendszernapló legfeljebb 200 MB helyet foglal"
    mem_mb="$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo 2>/dev/null || echo 0)"
    if [[ -n $(swapon --noheadings --show 2>/dev/null || true) ]] || (( ${mem_mb:-0} >= 4096 )); then return 0; fi
    if [[ ! -e /swapfile ]] && (( $(szabad_gb) >= 12 )); then
        AKT_MUVELET="swap-fájl létrehozása"
        if { fallocate -l 2G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none 2>/dev/null; } \
            && chmod 600 /swapfile && mkswap /swapfile >/dev/null 2>&1 && swapon /swapfile >/dev/null 2>&1; then
            grep -qE '^/swapfile[[:space:]]' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
            ok "2 GB-os swap-fájl (/swapfile): a gépben csak $mem_mb MB memória van"
        else
            rm -f /swapfile
            figy "Kevés a memória ($mem_mb MB), és a swap-fájl nem hozható létre – a BaninaPRO így is fut, de szűkösen."
        fi
        AKT_MUVELET=""
    elif [[ -e /swapfile ]]; then
        if swapon /swapfile >/dev/null 2>&1; then ok "Swap bekapcsolva (/swapfile)"; fi
    else
        figy "Kevés a memória ($mem_mb MB), nincs swap, és a swap-fájlhoz kevés a szabad hely – a BaninaPRO így is fut, de szűkösen."
    fi
}

lepes_gepnev() {
    if [[ $(hostname) != "$GEPNEV" ]]; then
        if ! hostnamectl set-hostname "$GEPNEV" >/dev/null 2>&1; then
            # (ha a systemd-hostnamed nem válaszol: a fájl és a futó név közvetlenül)
            echo "$GEPNEV" > /etc/hostname 2>/dev/null || true
            hostname "$GEPNEV" 2>/dev/null || true
        fi
        UJRAINDITAS=1
    fi
    # a gép saját neve a hosts fájlban (helyben írja felül, nem cseréli a fájlt – így egy csatolt hosts fájlnál is megy)
    if ! grep -qE "^127\.0\.1\.1[[:space:]]+$GEPNEV([[:space:]]|$)" /etc/hosts 2>/dev/null; then
        {
            grep -vE '^127\.0\.1\.1[[:space:]]' /etc/hosts 2>/dev/null || true
            printf '127.0.1.1\t%s\n' "$GEPNEV"
        } > "$TMPD/hosts"
        cat "$TMPD/hosts" > /etc/hosts || figy "A /etc/hosts nem írható – a gépnév ($GEPNEV) feloldása lassú lehet."
    fi
    # a cloud-init újraindításkor ne írja vissza a régi gépnevet
    if [[ -d /etc/cloud/cloud.cfg.d ]]; then
        echo 'preserve_hostname: true' > /etc/cloud/cloud.cfg.d/99-baninapro.cfg
    fi
    ok "Gépnév: $GEPNEV"
    if ! timedatectl set-timezone "$IDOZONA" >/dev/null 2>&1; then
        # (ha a systemd-timedated nem válaszol: közvetlenül)
        ln -sf "/usr/share/zoneinfo/$IDOZONA" /etc/localtime
    fi
    echo "$IDOZONA" > /etc/timezone   # (a timedatectl ezt nem írja át, egyes programok innen olvassák)
    timedatectl set-ntp true >/dev/null 2>&1 || true
    ok "Időzóna: $IDOZONA (most: $(date '+%Y-%m-%d %H:%M'))"
}

lepes_nyelv() {
    telepit language-pack-hu language-pack-hu-base \
        || figy "A magyar nyelvi csomagok nem települtek: $(apt_hibak) – a nyelvi beállítás enélkül is elkészül."
    if [[ -f /etc/locale.gen ]] && ! grep -q "^$NYELV UTF-8" /etc/locale.gen; then
        if grep -q "^# *$NYELV UTF-8" /etc/locale.gen; then
            sed -i "s/^# *$NYELV UTF-8/$NYELV UTF-8/" /etc/locale.gen
        else
            echo "$NYELV UTF-8" >> /etc/locale.gen
        fi
    fi
    AKT_MUVELET="magyar nyelvi beállítás létrehozása"
    fut locale-gen "$NYELV" || true
    if ! grep -qix 'hu_HU.utf8' <<<"$(locale -a)"; then
        # a locales csomag sérült vagy hiányos: újratelepítés, majd még egy próba
        apt_ install --reinstall locales || true
        fut locale-gen "$NYELV" || true
    fi
    AKT_MUVELET=""
    if ! grep -qix 'hu_HU.utf8' <<<"$(locale -a)"; then
        figy "A magyar nyelvi beállítás ($NYELV) nem jött létre – a rendszer angolul marad (a BaninaPRO működését nem érinti)."
    else
        if ! grep -qE "^LANG=\"?$NYELV\"?$" /etc/default/locale 2>/dev/null; then UJRAINDITAS=1; fi
        update-locale LANG="$NYELV" LANGUAGE=hu_HU:hu
        localectl set-locale LANG="$NYELV" LANGUAGE=hu_HU:hu || true
        ok "Rendszernyelv: magyar ($NYELV)"
    fi

    # billentyűzet: konzol + grafikus felület (a localectl Ubuntun nem mindig ismeri a konzolos „hu”-t,
    # ezért a végén az /etc/default/keyboard fájlt is beírjuk – az a mérvadó)
    localectl set-keymap hu || true
    localectl set-x11-keymap hu pc105 || true
    printf '%s\n' \
        'keyboard-configuration keyboard-configuration/layoutcode string hu' \
        'keyboard-configuration keyboard-configuration/modelcode string pc105' \
        'keyboard-configuration keyboard-configuration/variantcode string ' \
        'keyboard-configuration keyboard-configuration/optionscode string ' | debconf-set-selections
    AKT_MUVELET="billentyűzet beállítása"
    fut dpkg-reconfigure -f noninteractive keyboard-configuration || true
    AKT_MUVELET=""
    cat > /etc/default/keyboard <<'EOF'
# BaninaPRO szerver: magyar billentyűzet (a szerver_beallitas.sh írta)
XKBMODEL="pc105"
XKBLAYOUT="hu"
XKBVARIANT=""
XKBOPTIONS=""
BACKSPACE="guess"
EOF
    fut setupcon --save-only || true
    mkdir -p /etc/X11/xorg.conf.d
    cat > /etc/X11/xorg.conf.d/00-keyboard.conf <<'EOF'
# BaninaPRO szerver: magyar billentyűzet a grafikus felületen (a szerver_beallitas.sh írta)
Section "InputClass"
        Identifier "system-keyboard"
        MatchIsKeyboard "on"
        Option "XkbLayout" "hu"
        Option "XkbModel" "pc105"
EndSection
EOF
    ok "Billentyűzet: magyar (konzol és grafikus felület)"
}

lepes_asztal() {
    local dm
    if ! van_csomag lightdm; then UJRAINDITAS=1; fi
    # ha egy másik bejelentkező-kezelő (pl. gdm3) is fent van, a telepítő ne kérdezze, melyik legyen
    echo 'lightdm shared/default-x-display-manager select lightdm' | debconf-set-selections >/dev/null 2>&1 || true
    # a jegyzet szerinti minimális asztal – ez kötelező
    telepit_min lxqt-core xorg lightdm || hiba "Az asztali környezet nem telepíthető: $(apt_hibak)"
    ok "LXQt + Xorg + LightDM telepítve"
    # kiegészítők: üdvözlőképernyő, ablakkezelő, terminál, asztali segédprogramok (xdg-utils: az AnyDesk
    # telepítője is igényli) – ha valamelyik nem érhető el, attól még megy tovább
    telepit_opcionalis lightdm-gtk-greeter openbox qterminal xdg-utils desktop-file-utils

    # magyar feliratok: a --no-install-recommends miatt a fordításcsomagok (…-l10n) maguktól nem jönnek
    local jeloltek=(qttranslations5-l10n qt6-translations-l10n) l10n=() p
    for p in $(dpkg-query -W -f='${db:Status-Abbrev}|${Package}\n' | awk -F'|' '$1 ~ /^ii/ {print $2}'); do
        case $p in
            *-l10n) continue ;;
            lxqt*|liblxqt*|pcmanfm-qt*|libfm-qt*|qterminal*|lximage-qt*) ;;
            *) continue ;;
        esac
        # pl. lxqt-panel → lxqt-panel-l10n, libfm-qt14 → libfm-qt-l10n
        jeloltek+=("$p-l10n" "$(sed -E 's/[0-9.-]+(t64)?$//' <<<"$p")-l10n")
    done
    # shellcheck disable=SC2046  # szándékos szófelbontás: csomagnevek listája
    mapfile -t l10n < <(elerheto $(printf '%s\n' "${jeloltek[@]}" | sort -u))
    telepit_opcionalis "${l10n[@]}"
    ok "Magyar feliratok: ${#l10n[@]} fordításcsomag"

    echo /usr/sbin/lightdm > /etc/X11/default-display-manager
    # a LightDM legyen a bejelentkező-kezelő akkor is, ha egy másik (gdm3, sddm…) is fent van: azok ki, a --force
    # felülírja a display-manager.service régi hivatkozását
    for dm in gdm3 gdm sddm lxdm xdm slim; do
        systemctl disable "$dm" >/dev/null 2>&1 || true
    done
    systemctl unmask lightdm >/dev/null 2>&1 || true
    systemctl enable --force lightdm >/dev/null 2>&1 || hiba "A LightDM nem kapcsolható be (systemctl enable lightdm)."
    systemctl set-default graphical.target >/dev/null 2>&1 || figy "A grafikus indulás nem állítható be (systemctl set-default graphical.target)."
    ok "A gép grafikus felülettel indul (LightDM)"
}

lepes_autologin() {
    local sesszio="lxqt" greeter="" ses=()
    if [[ ! -f /usr/share/xsessions/lxqt.desktop ]]; then
        ses=(/usr/share/xsessions/*.desktop)
        [[ -e ${ses[0]} ]] || hiba "Nem található grafikus munkamenet (/usr/share/xsessions)."
        sesszio="$(basename "${ses[0]}" .desktop)"
    fi
    if [[ -f /usr/share/xgreeters/lightdm-gtk-greeter.desktop ]]; then
        greeter="greeter-session=lightdm-gtk-greeter"
    fi
    mkdir -p /etc/lightdm/lightdm.conf.d
    cat > /etc/lightdm/lightdm.conf.d/50-autologin.conf <<EOF
# BaninaPRO szerver: automatikus bejelentkezés (a szerver_beallitas.sh írta)
[Seat:*]
autologin-user=$CEL_FELH
autologin-user-timeout=0
autologin-session=$sesszio
user-session=$sesszio
$greeter
EOF
    groupadd -f -r autologin
    usermod -aG autologin "$CEL_FELH"

    # a felhasználó munkamenete is magyarul induljon (a LightDM innen veszi a nyelvet, ha van)
    local f="/var/lib/AccountsService/users/$CEL_FELH"
    if [[ -d /var/lib/AccountsService/users ]]; then
        [[ -f $f ]] || printf '[User]\n' > "$f"
        ini_beallit "$f" Language "$NYELV"
        ini_beallit "$f" Session "$sesszio"
        ini_beallit "$f" XSession "$sesszio"
    fi
    printf '[Desktop]\nSession=%s\nLanguage=%s\n' "$sesszio" "$NYELV" > "$CEL_HOME/.dmrc"
    chown "$CEL_FELH:" "$CEL_HOME/.dmrc"
    chmod 644 "$CEL_HOME/.dmrc"
    ok "Automatikus bejelentkezés: $CEL_FELH → $sesszio munkamenet, magyarul"
}

lepes_bongeszo() {
    local lista=/etc/apt/sources.list.d/mozilla.list jelolt
    if ! van_csomag firefox; then
        # a Mozilla saját csomagtárolójából (nem snap): apt-tal frissül, van magyar nyelvi csomagja
        if [[ ! -f $lista ]]; then
            install -m 0755 -d /etc/apt/keyrings
            if ! fut curl -fsSL https://packages.mozilla.org/apt/repo-signing-key.gpg -o /etc/apt/keyrings/packages.mozilla.org.asc; then
                figy "A Mozilla csomagtároló kulcsa nem tölthető le – a böngésző kimarad."
                return 0
            fi
            echo "deb [signed-by=/etc/apt/keyrings/packages.mozilla.org.asc] https://packages.mozilla.org/apt mozilla main" > "$lista"
            printf 'Package: *\nPin: origin packages.mozilla.org\nPin-Priority: 1000\n' > /etc/apt/preferences.d/mozilla
            apt_ update || true
        fi
        # ha nem a Mozilla-féle (hanem a snap-es átmeneti) változat a jelölt, a csomagtároló nem működik
        jelolt="$(apt-cache policy firefox 2>/dev/null | awk '/^  Candidate:/ {print $2}' || true)"
        if [[ -z $jelolt || $jelolt == "(none)" || $jelolt == *snap* ]]; then
            rm -f "$lista" /etc/apt/preferences.d/mozilla
            figy "A Mozilla csomagtároló nem működik – a böngésző kimarad."
            return 0
        fi
        if ! telepit firefox; then
            csomagkezelo_rendbe || true
            figy "A Firefox telepítése nem sikerült: $(apt_hibak)"
            return 0
        fi
    fi
    telepit_opcionalis firefox-l10n-hu
    mkdir -p /etc/firefox/policies
    cat > /etc/firefox/policies/policies.json <<'EOF'
{
  "policies": {
    "Homepage": { "URL": "http://localhost/", "StartPage": "homepage" },
    "RequestedLocales": ["hu"],
    "OverrideFirstRunPage": "",
    "OverridePostUpdatePage": "",
    "DontCheckDefaultBrowser": true,
    "DisableAppUpdate": true
  }
}
EOF
    ok "Firefox – magyarul, a kezdőlapja a BaninaPRO (http://localhost)"
}

lepes_anydesk() {
    local lista=/etc/apt/sources.list.d/anydesk-stable.list
    # Az AnyDesk telepítő szkriptje (postinst) az xdg-utils nélkül hibával áll le, és félbemaradt csomagot hagy
    # maga után, ami minden további apt-telepítést (pl. a Dockerét) megakaszt – ezért ennek előbb fent kell lennie.
    telepit_opcionalis xdg-utils desktop-file-utils
    if ! van_csomag anydesk; then
        # elsősorban a hivatalos csomagtárolóból – így a rendszerfrissítéssel együtt frissül
        install -m 0755 -d /etc/apt/keyrings
        AKT_MUVELET="AnyDesk csomagtároló beállítása"
        if fut curl -fsSL https://keys.anydesk.com/repos/DEB-GPG-KEY -o /etc/apt/keyrings/keys.anydesk.com.asc; then
            chmod a+r /etc/apt/keyrings/keys.anydesk.com.asc
            echo "deb [signed-by=/etc/apt/keyrings/keys.anydesk.com.asc] https://deb.anydesk.com all main" > "$lista"
            apt_ update || true
        fi
        AKT_MUVELET=""
        if [[ -n $(elerheto anydesk) ]] && telepit anydesk; then
            ok "AnyDesk telepítve (hivatalos csomagtárolóból)"
        else
            # egy félbemaradt próbálkozás ne akassza meg a többi telepítést
            csomagkezelo_rendbe || true
            if [[ -z $(elerheto anydesk) ]]; then rm -f "$lista"; fi
        fi
        if ! van_csomag anydesk; then
            info "A csomagtárolóból nem sikerült – a .deb csomagot telepítem."
            if ! anydesk_deb_telepit; then
                csomagkezelo_rendbe || true
                figy "Az AnyDesk most nem települt ($(apt_hibak)) – a következő futás újra megpróbálja."
                return 0
            fi
            ok "AnyDesk telepítve (.deb csomagból)"
        fi
    fi
    anydesk_szolgaltatas
    if [[ -n $ANYDESK_JELSZO ]]; then
        if printf '%s\n' "$ANYDESK_JELSZO" | timeout 60 anydesk --set-password; then
            ok "AnyDesk jelszó beállítva (felügyelet nélküli elérés)"
        else
            figy "Az AnyDesk jelszót nem sikerült beállítani – később: echo 'JELSZÓ' | sudo anydesk --set-password"
        fi
        ANYDESK_JELSZO=""
    fi
}

# AnyDesk .deb csomagból: előbb a jegyzetben szereplő változat, ha az már nincs fent (vagy nem ehhez
# a géphez való), akkor a legfrissebb a csomagtároló listájából
anydesk_deb_telepit() {
    local deb="$TMPD/anydesk.deb" url
    for url in "$ANYDESK_DEB" "$(anydesk_deb_legfrissebb)"; do
        [[ -n $url && $url == *"_$ARCH.deb" ]] || continue
        AKT_MUVELET="AnyDesk letöltése"
        if fut curl -fsSL "$url" -o "$deb"; then
            AKT_MUVELET=""
            chmod 644 "$deb"
            if telepit "$deb"; then return 0; fi
            csomagkezelo_rendbe || true
            if van_csomag anydesk; then return 0; fi
        fi
    done
    AKT_MUVELET=""
    return 1
}
anydesk_deb_legfrissebb() {
    curl -fsSL --max-time 30 "https://deb.anydesk.com/dists/all/main/binary-$ARCH/Packages" 2>/dev/null \
        | awk '/^Filename:/ { f = $2 } END { if (f != "") print "https://deb.anydesk.com/" f }' || true
}

anydesk_szolgaltatas() {
    local egyseg=/etc/systemd/system/anydesk.service
    # a csomag telepítője másolja a helyére – ha egy félbemaradt telepítés miatt hiányzik, pótolom
    if [[ ! -f $egyseg && -f /usr/share/anydesk/files/systemd/anydesk.service ]]; then
        cp /usr/share/anydesk/files/systemd/anydesk.service "$egyseg"
    fi
    # mindig fusson: a géppel indul, és ha bármiért leállna, a systemd 5 mp múlva újraindítja
    mkdir -p /etc/systemd/system/anydesk.service.d
    cat > /etc/systemd/system/anydesk.service.d/50-baninapro.conf <<'EOF'
# BaninaPRO szerver: az AnyDesk mindig fusson – ha leáll, magától újraindul (a szerver_beallitas.sh írta)
[Unit]
StartLimitIntervalSec=0

[Service]
Restart=always
RestartSec=5
EOF
    systemctl daemon-reload || true
    if systemctl enable --now anydesk \
        || { systemctl reset-failed anydesk || true; systemctl restart anydesk; }; then
        ok "AnyDesk fut, a géppel együtt indul, és ha leállna, magától újraindul"
    else
        figy "Az AnyDesk szolgáltatás nem indult el (systemctl status anydesk) – a gép újraindítása után általában rendben van."
    fi
}

lepes_docker() {
    # egy korábbi félbemaradt telepítés (pl. az AnyDeské) ne akassza meg; a félig felkerült Docker-csomagokat is
    # eltávolíthatja – ezeket alább újratelepítem (a konténerek adatai a /var/lib/docker alatt megmaradnak)
    csomagkezelo_rendbe "${DOCKER_CE_CSOMAGOK[@]}" "${DOCKER_UBUNTU_CSOMAGOK[@]}" || true
    if ! van_csomag docker-ce && ! van_csomag docker.io; then
        docker_telepites
    elif ! command -v docker >/dev/null || ! command -v dockerd >/dev/null; then
        # a csomag fent van, de a programfájlja hiányzik (sérült telepítés): újratelepítés
        docker_ujratelepites
    fi
    docker_daemon_json
    if ! docker_inditas; then
        # végső próba: a Docker újratelepítése (a konténerek és az adatok a /var/lib/docker alatt megmaradnak)
        figy "A Docker nem indult el – újratelepítem."
        docker_ujratelepites
        if ! docker_inditas; then
            torol
            journalctl -u docker -n 15 --no-pager 2>/dev/null | sed 's/^/    | /' >&3 || true
            hiba "A Docker nem indul el (systemctl status docker)."
        fi
    fi
    if getent group docker >/dev/null && ! id -nG "$CEL_FELH" | grep -qw docker; then
        usermod -aG docker "$CEL_FELH" || figy "A(z) $CEL_FELH felhasználó nem került be a docker csoportba."
    fi
    compose_biztosit
    buildx_biztosit
    ok "Docker $(timeout 30 docker version -f '{{.Server.Version}}' 2>/dev/null || echo '?') fut és a géppel együtt indul; Compose $(timeout 30 docker compose version --short 2>/dev/null || echo '?')"
}

# a Docker csomagjainak újratelepítése (a hiányzó vagy sérült programfájlok, szolgáltatás-leírások pótlása)
docker_ujratelepites() {
    if van_csomag docker-ce; then
        apt_ install --reinstall docker-ce docker-ce-cli containerd.io || figy "A Docker újratelepítése nem sikerült: $(apt_hibak)"
    elif van_csomag docker.io; then
        apt_ install --reinstall docker.io containerd runc || figy "A Docker újratelepítése nem sikerült: $(apt_hibak)"
    else
        docker_telepites
    fi
}

# docker buildx: az alkalmazás képének építéséhez a compose újabb változatai ezt használják. Ha nem pótolható, a kép a
# Docker egyszerű építőjével készül (alkalmazas_epites).
buildx_biztosit() {
    if timeout 30 docker buildx version >/dev/null 2>&1; then return 0; fi
    if van_csomag docker-ce; then telepit_opcionalis docker-buildx-plugin; else telepit_opcionalis docker-buildx; fi
    if timeout 30 docker buildx version >/dev/null 2>&1; then
        ok "docker buildx pótolva"
    else
        info "A docker buildx nem érhető el – az alkalmazás képe a Docker egyszerű építőjével készül."
    fi
}

# Docker Engine: elsősorban a Docker hivatalos csomagtárolójából, ha az nem megy, az Ubuntu saját csomagjaiból
docker_telepites() {
    local p kod
    # a Docker leírása szerint az ütköző csomagok eltávolítása (friss gépen általában nincs ilyen)
    for p in docker.io docker-doc docker-compose docker-compose-v2 podman-docker containerd runc; do
        if van_csomag "$p"; then apt_ remove "$p" || true; fi
    done
    if command -v snap >/dev/null && snap list docker >/dev/null 2>&1; then
        info "A snap-es Docker eltávolítása (ütközne a Docker Engine-nel)…"
        AKT_MUVELET="snap-es Docker eltávolítása"
        fut snap remove --purge docker || true
        AKT_MUVELET=""
    fi
    install -m 0755 -d /etc/apt/keyrings
    kod="$(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")"
    AKT_MUVELET="Docker csomagtároló beállítása"
    if fut curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc; then
        chmod a+r /etc/apt/keyrings/docker.asc
        printf 'Types: deb\nURIs: https://download.docker.com/linux/ubuntu\nSuites: %s\nComponents: stable\nArchitectures: %s\nSigned-By: /etc/apt/keyrings/docker.asc\n' \
            "$kod" "$ARCH" > /etc/apt/sources.list.d/docker.sources
        apt_ update || true
    fi
    AKT_MUVELET=""
    if [[ -n $(elerheto docker-ce) ]] \
        && telepit docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin; then
        ok "Docker Engine telepítve (hivatalos Docker csomagtárolóból)"
        return 0
    fi
    figy "A Docker hivatalos csomagtárolójából nem sikerült ($kod: $(apt_hibak)) – az Ubuntu saját Docker csomagjait telepítem."
    # a félig felkerült hivatalos csomagok le, hogy ne ütközzenek
    docker_csomagok_le "${DOCKER_CE_CSOMAGOK[@]}"
    rm -f /etc/apt/sources.list.d/docker.sources
    apt_ update || true
    telepit docker.io docker-compose-v2 \
        || hiba "A Docker sem a hivatalos, sem az Ubuntu csomagtárolójából nem telepíthető: $(apt_hibak)"
    telepit_opcionalis docker-buildx
    ok "Docker Engine telepítve (az Ubuntu csomagtárolójából)"
}
docker_csomagok_le() {   # eltávolítás (nem purge – a /var/lib/docker adatai megmaradnak)
    local p le=()
    for p in "$@"; do
        if [[ $(dpkg-query -W -f='${db:Status-Abbrev}' "$p" 2>/dev/null) =~ ^[ih][iUFHWt] ]]; then le+=("$p"); fi
    done
    (( ${#le[@]} )) || return 0
    apt_ remove "${le[@]}" || fut dpkg --remove --force-remove-reinstreq "${le[@]}" || true
}

# a konténerek naplói ne nőjenek a végtelenségig (konténerenként legfeljebb 3 × 10 MB)
docker_daemon_json() {
    [[ ! -f /etc/docker/daemon.json ]] || return 0
    mkdir -p /etc/docker
    printf '{\n  "log-driver": "json-file",\n  "log-opts": { "max-size": "10m", "max-file": "3" }\n}\n' > /etc/docker/daemon.json
    AKT_MUVELET="Docker újraindítása"
    fut systemctl restart docker || true
    AKT_MUVELET=""
    ok "Docker naplók mérete korlátozva"
}

# a Docker szolgáltatás indítása; ha nem indul, a gyakori okokat (beragadt indítás, hibás daemon.json) javítja
docker_inditas() {
    local i sugo=""
    AKT_MUVELET="Docker indítása"
    # (ha valaki letiltotta – mask – a szolgáltatásokat, vissza)
    systemctl unmask containerd.service docker.service docker.socket >/dev/null 2>&1 || true
    systemctl daemon-reload || true
    systemctl enable containerd docker >/dev/null 2>&1 || true
    if command -v dockerd >/dev/null; then sugo="$(timeout 30 dockerd --help 2>&1 || true)"; fi
    for i in 1 2 3 4; do
        if docker_valaszol; then AKT_MUVELET=""; return 0; fi
        # (a --validate a Docker 23-as változata óta van – a régebbiek nem ismerik, ott nem ezen múlik)
        if [[ -f /etc/docker/daemon.json && $sugo == *--validate* ]] \
            && ! timeout 60 dockerd --validate --config-file=/etc/docker/daemon.json >/dev/null 2>&1; then
            mv -f /etc/docker/daemon.json "/etc/docker/daemon.json.hibas-$(date +%Y%m%d%H%M%S)"
            figy "A /etc/docker/daemon.json hibás volt – félretettem, és újat írtam."
            docker_daemon_json
        fi
        systemctl reset-failed containerd docker >/dev/null 2>&1 || true
        fut timeout 300 systemctl restart containerd docker || true
        varj $(( 3 * i ))
    done
    AKT_MUVELET=""
    docker_valaszol
}

# docker compose: ha hiányzik vagy túl régi, csomagból pótolja, végső esetben a Docker GitHub-oldaláról tölti le
# (a szerver docker-compose.override.yml-je a 2.24.4-es változattól ismert „!override” jelölést használja)
compose_eleg_uj() {
    local v
    v="$(docker compose version --short 2>/dev/null || true)"
    v="${v#v}"
    [[ -n $v && $(printf '%s\n' 2.24.4 "$v" | sort -V | head -n 1) == 2.24.4 ]]
}
compose_biztosit() {
    local cel=/usr/local/lib/docker/cli-plugins/docker-compose arch
    if compose_eleg_uj; then return 0; fi
    info "A „docker compose” bővítmény hiányzik vagy túl régi – pótolom…"
    if van_csomag docker-ce; then telepit_opcionalis docker-compose-plugin; else telepit_opcionalis docker-compose-v2; fi
    if compose_eleg_uj; then return 0; fi
    case $ARCH in amd64) arch=x86_64 ;; arm64) arch=aarch64 ;; armhf) arch=armv7 ;; *) arch=$ARCH ;; esac
    install -d -m 755 "$(dirname "$cel")"
    AKT_MUVELET="docker compose letöltése"
    if ujraprobal 3 curl -fsSL "https://github.com/docker/compose/releases/latest/download/docker-compose-linux-$arch" -o "$cel"; then
        chmod 755 "$cel"
    fi
    AKT_MUVELET=""
    compose_eleg_uj || hiba "A „docker compose” bővítmény hiányzik vagy túl régi, és nem sikerült pótolni."
    ok "docker compose pótolva (a Docker GitHub-oldaláról)"
}

lepes_ssh() {
    # SSH szerver – távoli belépés a gépre (Ubuntu 22.10 óta socketről indul)
    if ! van_csomag openssh-server; then telepit_opcionalis openssh-server; fi
    systemctl unmask ssh.socket ssh.service >/dev/null 2>&1 || true
    if systemctl is-active --quiet ssh.socket; then
        systemctl enable ssh.socket || true
    elif systemctl is-active --quiet ssh; then
        systemctl enable ssh || true
    else
        systemctl enable --now ssh.socket || systemctl enable --now ssh \
            || figy "Az SSH szerver nem indult el (systemctl status ssh)."
    fi
    ok "SSH szerver fut – távoli belépés: ssh $CEL_FELH@$GEPNEV"

    # a gép neve a belső hálózaton: baninapro.local – akkor is megtalálható, ha a router más IP-címet ad neki
    telepit_opcionalis avahi-daemon
    if systemctl enable --now avahi-daemon >/dev/null 2>&1; then
        ok "A gép a belső hálózaton $GEPNEV.local néven is elérhető"
    else
        figy "A $GEPNEV.local név nem kapcsolható be (avahi-daemon) – a gép az IP-címével érhető el."
    fi

    # tűzfal: alapból ki van kapcsolva; ha valaki bekapcsolta, az SSH, a BaninaPRO, a phpMyAdmin és a gépnév (mDNS)
    # legyen nyitva (a Docker a saját portjait a tűzfal mellett is megnyitja, a többit nem)
    if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q '^Status: active'; then
        ufw allow 22/tcp >/dev/null 2>&1 || true
        ufw allow "$APP_PORT/tcp" >/dev/null 2>&1 || true
        ufw allow "$PMA_PORT/tcp" >/dev/null 2>&1 || true
        ufw allow 5353/udp >/dev/null 2>&1 || true
        ok "Tűzfal (ufw): az SSH, a BaninaPRO ($APP_PORT), a phpMyAdmin ($PMA_PORT) és a gépnév (mDNS) nyitva"
    fi

    # a frissítés (git pull) a nyilvános GitHub-repóból megy, https-en – GitHub-kulcs nem kell
    repo_cim_beallit
    AKT_MUVELET="GitHub elérés ellenőrzése"
    if ujraprobal 2 felh git -C "$REPO" ls-remote --exit-code origin HEAD; then
        ok "GitHub elérés rendben – a frissítések innen jönnek: $REPO_URL"
    else
        figy "A GitHub-repó most nem érhető el ($REPO_URL) – a következő futás újra megpróbálja a frissítést."
    fi
    AKT_MUVELET=""
}

lepes_energia() {
    local f
    # 1) soha ne aludjon el: alvó és hibernált állapot letiltva – rendszerszinten és a bejelentkezés-kezelőben is
    systemctl mask sleep.target suspend.target hibernate.target hybrid-sleep.target suspend-then-hibernate.target
    mkdir -p /etc/systemd/logind.conf.d /etc/systemd/sleep.conf.d
    cat > /etc/systemd/logind.conf.d/50-baninapro.conf <<'EOF'
# BaninaPRO szerver: soha ne aludjon el (a szerver_beallitas.sh írta)
[Login]
HandleLidSwitch=ignore
HandleLidSwitchExternalPower=ignore
HandleLidSwitchDocked=ignore
HandleSuspendKey=ignore
HandleHibernateKey=ignore
IdleAction=ignore
EOF
    cat > /etc/systemd/sleep.conf.d/50-baninapro.conf <<'EOF'
# BaninaPRO szerver: soha ne aludjon el (a szerver_beallitas.sh írta)
[Sleep]
AllowSuspend=no
AllowHibernation=no
AllowSuspendThenHibernate=no
AllowHybridSleep=no
EOF
    ok "Alvó mód és hibernálás letiltva – a gép soha nem alszik el"

    # 2) soha ne legyen képernyővédő, és a kijelző se kapcsoljon ki: az X szerverben, a LightDM-ben és a munkamenetben
    mkdir -p /etc/X11/xorg.conf.d /etc/lightdm/lightdm.conf.d
    cat > /etc/X11/xorg.conf.d/10-baninapro-kepernyo.conf <<'EOF'
# BaninaPRO szerver: nincs képernyővédő és kijelző-kikapcsolás (a szerver_beallitas.sh írta)
Section "ServerFlags"
        Option "BlankTime"   "0"
        Option "StandbyTime" "0"
        Option "SuspendTime" "0"
        Option "OffTime"     "0"
EndSection
EOF
    cat > /etc/lightdm/lightdm.conf.d/60-baninapro-kepernyo.conf <<'EOF'
# BaninaPRO szerver: az X szerver képernyővédő és energiatakarékos kijelző nélkül indul (a szerver_beallitas.sh írta)
[Seat:*]
xserver-command=X -s 0 -dpms
EOF
    # a bejelentkezett munkamenetben is (ha egy program mégis bekapcsolná): xset induláskor, az LXQt energiakezelője
    # tétlenségi műveletek nélkül, az xscreensaver (ha fent van) kikapcsolva
    felh mkdir -p "$CEL_HOME/.config/autostart" "$CEL_HOME/.config/lxqt"
    cat > "$CEL_HOME/.config/autostart/baninapro-kepernyo.desktop" <<'EOF'
[Desktop Entry]
Type=Application
Name=BaninaPRO: nincs képernyővédő
Exec=sh -c "xset s off; xset s noblank; xset -dpms"
NoDisplay=true
EOF
    f="$CEL_HOME/.config/lxqt/lxqt-powermanagement.conf"
    ini_beallit "$f" enableIdlenessWatcher false General
    ini_beallit "$f" enableIdlenessBacklightWatcher false General
    ini_beallit "$f" enableLidWatcher false General
    if command -v xscreensaver >/dev/null; then
        f="$CEL_HOME/.xscreensaver"
        if [[ -f $f ]] && grep -q '^mode:' "$f"; then sed -i 's/^mode:.*/mode:\t\toff/' "$f"; else printf 'mode:\t\toff\n' >> "$f"; fi
        chown "$CEL_FELH:" "$f"
    fi
    chown "$CEL_FELH:" "$CEL_HOME/.config/autostart/baninapro-kepernyo.desktop" "$CEL_HOME/.config/lxqt/lxqt-powermanagement.conf"
    ok "Képernyővédő és kijelző-kikapcsolás letiltva"

    # 3) áramszünet után magától bekapcsol: ez a BIOS beállítása – ahol a gép engedi, innen állítja be
    aram_utan_bekapcsol
}

# Áramszünet után magától bekapcsol: ez a gép BIOS/UEFI-beállítása („Restore on AC Power Loss”). Ahol a firmware
# engedi (Dell, HP, Lenovo: /sys/class/firmware-attributes), innen állítja be; máshol megmondja, mit kell a BIOS-ban.
aram_utan_bekapcsol() {
    local a nev ertek talalt="" minta='acpwrrcvry|afterpower(loss|failure)|after.power.(loss|failure)|restoreon.*ac|ac.*power.*(loss|recovery)|power.*(loss|failure).*(action|recovery)'
    for a in /sys/class/firmware-attributes/*/attributes/*; do
        [[ -f $a/current_value && -f $a/possible_values ]] || continue
        nev="$(basename "$a")"
        [[ ${nev,,} =~ $minta ]] || continue
        ertek="$(tr ';,' '\n\n' < "$a/possible_values" | grep -ixE 'on|power on|always on|alwayson|poweron|turn on' | head -n 1 || true)"
        [[ -n $ertek ]] || continue
        if [[ $(cat "$a/current_value" 2>/dev/null) == "$ertek" ]] || { printf '%s' "$ertek" > "$a/current_value"; } 2>/dev/null; then
            talalt="$nev = $ertek"
            break
        fi
    done
    if [[ -n $talalt ]]; then
        ok "Áramszünet után a gép magától bekapcsol (BIOS: $talalt)"
    else
        figy "Az áramszünet utáni automatikus bekapcsolást ennél a gépnél egyszer a BIOS-ban kell beállítani: bekapcsoláskor F2 / Del / F10 → Power vagy Advanced → „Restore on AC Power Loss” / „After Power Failure” / „AC Power Recovery” → Power On, majd mentés (F10)."
    fi
}

lepes_baninapro() {
    # 1) adatbázis-séma (a gitben van): üres adatbázisnál a MySQL ebből hozza létre a táblákat és a kezdő admint
    sema_biztosit || hiba "Hiányzik az adatbázis-séma: $SEMA – a git pull nem hozta le (lásd a figyelmeztetéseket). Ellenőrizd a GitHub-elérést, majd futtasd újra."
    if timeout 60 docker volume inspect "$DB_KOTET" >/dev/null 2>&1; then
        ELSO_INDITAS=0
        ok "Meglévő adatbázis – az adatok megmaradnak"
    else
        ELSO_INDITAS=1
        ok "Új adatbázis – első induláskor létrejönnek a táblák (ha van korábbi mentés, abból visszaállítom)"
    fi

    # 2) a szerver saját adatbázis-jelszavai és az alkalmazás szerverre szabott beállítófájlja (nincsenek a gitben)
    titkok_biztosit
    szerver_config

    # 3) a portok: a BaninaPRO-é kötelező; a phpMyAdminé és a MySQL-é (csak a gépen belül) nem – ha egy idegen program
    #    foglalja, és nem állítható le, az kimarad (a BaninaPRO enélkül is működik)
    port_felszabadit "$APP_PORT" \
        || hiba "A(z) $(port_nev "$APP_PORT") portot egy másik program használja, és nem állítható le: $(port_foglalo "$APP_PORT")"
    PMA_PORT_KI=1 DB_PORT_KI=1
    if ! port_felszabadit "$PMA_PORT"; then
        PMA_PORT_KI=0
        figy "A(z) $(port_nev "$PMA_PORT") portot egy másik program foglalja ($(port_foglalo "$PMA_PORT")) – a phpMyAdmin most nem érhető el kívülről."
    fi
    if ! port_felszabadit 3307; then
        DB_PORT_KI=0
        figy "A 3307-es portot egy másik program foglalja – a MySQL most csak a konténerek között érhető el (a BaninaPRO-t nem érinti)."
    fi

    # 4) szerver-kiegészítés a docker-compose.yml mellé
    override_iras
    if (( PMA_PORT_KI )); then
        ok "Szerver-beállítás: BaninaPRO a $APP_PORT-as, phpMyAdmin a $PMA_PORT-es porton – a belső hálózatról is (jelszóval)"
    else
        ok "Szerver-beállítás: BaninaPRO a $APP_PORT-as porton – a belső hálózatról is"
    fi

    # 5) a Docker-képek (ha a Docker Hub nem érhető el: a hivatalos képek tükreiről), az alkalmazás képe, indítás
    idegen_kontenerek_le
    kepek_biztosit
    alkalmazas_epites || hiba "Az alkalmazás képe nem épült fel – a napló végén látszik, miért."
    if ! kontenerek_inditasa; then
        # a MySQL nem indul el (sérült vagy egy másik MySQL-változattal írt adatfájlok): ha van mentés, abból újraépíti
        if timeout 30 docker inspect "$DB_KONTENER" >/dev/null 2>&1 && ! db_var_inditasra; then
            adatbazis_ujraepites || true
        fi
        if ! kontenerek_inditasa; then
            kontener_naplok
            hiba "A konténerek nem indultak el."
        fi
    fi
    dc ps || true
    ok "Konténerek elindítva"
    fut timeout 600 docker image prune -f || true   # a felülírt régi képek (a használtakhoz és az adatokhoz nem nyúl)

    # 6) adatbázis: indulás, jelszavak, táblák, felhasználók, visszaállítás mentésből, a séma egyeztetése a kóddal
    if ! adatbazis_rendbe; then
        kontener_naplok
        hiba "Az adatbázis nem készült el (a MySQL nem indult el, vagy a táblák nem hozhatók létre)."
    fi
}

# A szerver saját, erős adatbázis-jelszavai: a repóban lévő alapjelszavak nyilvánosak, a belső hálózatról elérhető
# phpMyAdmin mellett nem maradhatnak. Egyszer készülnek, utána mindig ugyanazok (csak a root olvashatja). Ha a fájl
# elveszne, újak készülnek, és az adatbázis jelszavai is ezekre állnak át (db_jelszo_helyreallitas).
titkok_biztosit() {
    local f="$TITOK_MAPPA/titkok"
    install -d -m 700 "$TITOK_MAPPA"
    if ! grep -qE '^DB_ROOT_JELSZO=[A-Za-z0-9]{16,}$' "$f" 2>/dev/null || ! grep -qE '^DB_APP_JELSZO=[A-Za-z0-9]{16,}$' "$f"; then
        printf '# BaninaPRO szerver – adatbázis-jelszavak (a szerver_beallitas.sh készítette, ne add ki)\nDB_ROOT_JELSZO=%s\nDB_APP_JELSZO=%s\n' \
            "$(veletlen_jelszo)" "$(veletlen_jelszo)" > "$f"
    fi
    chmod 600 "$f"
    DB_ROOT_JELSZO="$(sed -n 's/^DB_ROOT_JELSZO=//p' "$f")"
    DB_APP_JELSZO="$(sed -n 's/^DB_APP_JELSZO=//p' "$f")"
}
veletlen_jelszo() { tr -dc 'A-Za-z0-9' </dev/urandom 2>/dev/null | head -c 24 || true; }

# az alkalmazás szerverre szabott beállítófájlja: a docker/config.php a szerver adatbázis-jelszavával, és
# hibakijelzés nélkül (a belső hálózatról elérhető szerveren a részletes hibák nem látszhatnak)
szerver_config() {
    local f="$TITOK_MAPPA/config.php"
    sed -e "s/^define('DB_PASS', *'[^']*');/define('DB_PASS', '$DB_APP_JELSZO');/" \
        -e "s/^define('APP_DEBUG', *true);/define('APP_DEBUG', false);/" "$REPO/docker/config.php" > "$f.uj"
    grep -q "^define('DB_PASS', '$DB_APP_JELSZO');" "$f.uj" \
        || hiba "A szerver beállítófájlja nem készült el (a docker/config.php DB_PASS sora nem a várt formájú)."
    # ha a fájl hiányzott, amikor a Docker a konténert indította, a helyén üres mappát hozott létre – az nem kell
    if [[ -d $f ]]; then rm -rf -- "$f"; fi
    # Helyben írja felül (nem cseréli a fájlt): a futó konténer a fájlt az indulásakor csatolta – egy új fájlt (új
    # inode-ot) csak újraindítás után látna, ugyanabba írva viszont azonnal az új tartalmat olvassa.
    if ! cmp -s "$f.uj" "$f"; then cat "$f.uj" > "$f"; fi
    rm -f "$f.uj"
    # az alkalmazás (www-data, 33) olvassa a konténerben
    chown "root:$WWW_UID" "$f"
    chmod 640 "$f"
}

# A szerver-kiegészítés a docker-compose.yml mellé (a compose magától betölti): belső hálózati elérés, jelszavas
# phpMyAdmin, a szerver saját jelszavai, rögzített projektnév (így a kötetek neve sem változik). Egy példánya a
# $TITOK_MAPPA-ban is megvan: ha a BaninaPRO mappából eltűnne (pl. egy git clean után), az őrszem onnan visszateszi.
override_iras() {
    local f="$REPO/docker-compose.override.yml"
    {
        cat <<EOF
# BaninaPRO szerver – a "SERVER SETUP AND UPDATE/$(basename "$SCRIPT")" írja minden futáskor, kézzel ne módosítsd.
# A docker compose a docker-compose.yml mellé automatikusan betölti. A szerveren:
#  - a BaninaPRO ($APP_PORT) és a phpMyAdmin ($PMA_PORT) a belső hálózatról is elérhető, a MySQL csak a gépen belülről;
#  - a phpMyAdmin jelszót kér (nincs automatikus root-belépés), az adatbázis a szerver saját jelszavait használja;
#  - az alkalmazás beállítófájlja: $TITOK_MAPPA/config.php (a szerver jelszava, hibakijelzés kikapcsolva).
name: $PROJEKT
services:
  app:
    ports: !override
      - "$APP_PORT:80"
    volumes:
      - $TITOK_MAPPA/config.php:/var/www/html/includes/config.php:ro
  db:
    environment:
      MYSQL_ROOT_PASSWORD: "$DB_ROOT_JELSZO"
      MYSQL_PASSWORD: "$DB_APP_JELSZO"
EOF
        if (( ! DB_PORT_KI )); then printf '    ports: !reset []\n'; fi
        printf '  phpmyadmin:\n'
        if (( PMA_PORT_KI )); then printf '    ports: !override\n      - "%s:80"\n' "$PMA_PORT"; else printf '    ports: !reset []\n'; fi
        printf '    environment: !override\n      PMA_HOST: db\n      UPLOAD_LIMIT: 64M\n'
    } > "$TITOK_MAPPA/docker-compose.override.yml"
    chmod 600 "$TITOK_MAPPA/docker-compose.override.yml"
    install -m 600 -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH")" "$TITOK_MAPPA/docker-compose.override.yml" "$f"
    # a git ne lássa új fájlnak (helyi kizárás, a .gitignore-hoz nem nyúl)
    if [[ -d $REPO/.git ]] && ! grep -qx 'docker-compose.override.yml' "$REPO/.git/info/exclude" 2>/dev/null; then
        install -d -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH")" "$REPO/.git/info"
        echo 'docker-compose.override.yml' >> "$REPO/.git/info/exclude"
        chown "$CEL_FELH:" "$REPO/.git/info/exclude"
    fi
}

# ---- Portok -------------------------------------------------------------------------
port_foglalo() { ss -Hltnp "sport = :$1" 2>/dev/null || true; }   # ki figyel a porton (üres: senki)
# a port száma a kiejtéséhez illő raggal: 80-as, 8081-es, 3307-es, 443-as, 5355-ös
port_nev() {
    local p=$1 r=es
    case $p in
        *000) r=es ;;
        *00) r=as ;;
        *0) case ${p: -2:1} in 2|3|6|8) r=as ;; *) r=es ;; esac ;;
        *3|*8) r=as ;;
        *5) r=ös ;;
        *6) r=os ;;
    esac
    printf '%s-%s' "$p" "$r"
}
# A port felszabadítása a BaninaPRO-nak: egy idegen konténer leáll (és nem indul újra magától); egy rendszerszolgáltatás
# (pl. apache2, nginx) leáll, és kikapcsolódik; egy kézzel indított program leáll. 0, ha a port szabad, vagy a BaninaPRO-é.
port_felszabadit() {
    local port=$1 foglalo k pid nev egyseg kesz=" " kontenerek=() pidek=()
    if docker_valaszol; then
        mapfile -t kontenerek < <(timeout 30 docker ps --filter "publish=$port" --format '{{.Names}}' 2>/dev/null || true)
        for k in "${kontenerek[@]}"; do
            case $k in ""|"$APP_KONTENER"|"$DB_KONTENER"|"$PMA_KONTENER") continue ;; esac
            timeout 60 docker update --restart=no "$k" >/dev/null 2>&1 || true
            fut timeout 120 docker stop "$k" || true
            figy "A(z) $(port_nev "$port") portot a(z) $k konténer foglalta – leállítottam (ez a port a BaninaPRO-é)."
        done
    fi
    foglalo="$(port_foglalo "$port")"
    mapfile -t pidek < <(grep -oE 'pid=[0-9]+' <<<"$foglalo" | cut -d= -f2 | sort -un || true)
    for pid in "${pidek[@]}"; do
        # (egy szolgáltatás leállításával a gyerekfolyamatai is leálltak – azokkal már nincs teendő)
        kill -0 "$pid" 2>/dev/null || continue
        nev="$(ps -o comm= -p "$pid" 2>/dev/null || true)"
        case $nev in docker-proxy|dockerd|containerd*) continue ;; esac   # (a konténereké – fent már rendezve)
        if [[ $pid == 1 || $nev == systemd ]]; then
            # a systemd figyel rajta (socket-aktiválás): a socket-egység leáll és kikapcsolódik
            egyseg="$(systemctl list-sockets --all --no-legend 2>/dev/null | awk -v p=":$port" '$1 ~ p"$" { print $2; exit }' || true)"
            if [[ -n $egyseg && $kesz != *" $egyseg "* ]]; then
                kesz+="$egyseg "
                systemctl disable --now "$egyseg" >/dev/null 2>&1 || true
                figy "A(z) $(port_nev "$port") porton a(z) $egyseg figyelt – leállítottam és kikapcsoltam (ez a port a BaninaPRO-é)."
            fi
            continue
        fi
        egyseg="$(ps -o unit= -p "$pid" 2>/dev/null | tr -d ' ' || true)"
        if [[ $egyseg == *.service && $egyseg != user@*.service ]]; then
            if [[ $kesz != *" $egyseg "* ]]; then
                kesz+="$egyseg "
                systemctl disable --now "$egyseg" >/dev/null 2>&1 || systemctl stop "$egyseg" >/dev/null 2>&1 || true
                figy "A(z) $(port_nev "$port") portot a(z) $egyseg ($nev) foglalta – leállítottam és kikapcsoltam (ez a port a BaninaPRO-é)."
            fi
        else
            kill "$pid" 2>/dev/null || true
            figy "A(z) $(port_nev "$port") portot egy kézzel indított program (${nev:-?}, $pid) foglalta – leállítottam."
        fi
    done
    if (( ${#pidek[@]} )); then varj 3; fi
    foglalo="$(port_foglalo "$port")"
    [[ -z $foglalo || $foglalo == *docker-proxy* ]]
}

# Egy korábbi, más mappából vagy más néven indított telepítés azonos nevű konténere: eltávolítja (a kötetekhez – az
# adatokhoz – nem nyúl), különben a compose nem tudná létrehozni a sajátját
idegen_kontenerek_le() {
    local k p
    for k in "$APP_KONTENER" "$DB_KONTENER" "$PMA_KONTENER"; do
        timeout 30 docker inspect "$k" >/dev/null 2>&1 || continue
        p="$(timeout 30 docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$k" 2>/dev/null || true)"
        if [[ $p != "$PROJEKT" ]]; then
            fut timeout 120 docker rm -f "$k" || true
            figy "A(z) $k konténer nem ehhez a telepítéshez tartozott (projekt: ${p:-–}) – eltávolítottam (a kötetei megmaradtak)."
        fi
    done
}

# ---- Docker-képek -------------------------------------------------------------------
# A BaninaPRO képei: a docker-compose.yml image-sorai és az alkalmazás Dockerfile-jának alapképe (pl. mysql:latest,
# phpmyadmin:latest, php:8.3-apache)
kepek_listaja() {
    awk '$1 == "image:" { gsub(/["'"'"']/, "", $2); print $2 }' "$REPO/docker-compose.yml" 2>/dev/null || true
    awk 'toupper($1) == "FROM" { print $2; exit }' "$REPO/docker/Dockerfile" 2>/dev/null || true
}
# Egy kép letöltése: a Docker Hubról; ha az nem megy (elérhetetlen, vagy korlátozza a letöltések számát), a hivatalos
# képek nyilvános tükreiről – a kép az eredeti nevén kerül a gépre, így a compose és az építés is megtalálja
kep_letolt() {
    local kep=$1 t
    if ujraprobal 2 timeout 1800 docker pull -q "$kep"; then return 0; fi
    [[ $kep != */* ]] || return 1   # tükör csak a hivatalos (library) képekhez van
    for t in "${KEP_TUKROK[@]}"; do
        if ujraprobal 2 timeout 1800 docker pull -q "$t/$kep" && timeout 60 docker tag "$t/$kep" "$kep"; then
            timeout 60 docker rmi "$t/$kep" >/dev/null 2>&1 || true   # (csak a tükrös név törlődik, a kép marad)
            figy "A(z) $kep képet a Docker Hub helyett a tükréről töltöttem le ($t) – a Docker Hub most nem volt elérhető."
            return 0
        fi
    done
    return 1
}
HIANYZO_KEPEK=()
kepek_biztosit() {   # a hiányzó képek letöltése – a meglévőkhöz nem nyúl (a MySQL-kép így sosem cserélődik magától)
    local k kepek=()
    HIANYZO_KEPEK=()
    mapfile -t kepek < <(kepek_listaja)
    for k in "${kepek[@]}"; do
        [[ -n $k ]] || continue
        if timeout 60 docker image inspect "$k" >/dev/null 2>&1; then continue; fi
        AKT_MUVELET="a(z) $k kép letöltése"
        if kep_letolt "$k"; then ok "Docker-kép letöltve: $k"; else HIANYZO_KEPEK+=("$k"); fi
    done
    AKT_MUVELET=""
    if (( ${#HIANYZO_KEPEK[@]} )); then
        figy "Nem tölthető le (sem a Docker Hubról, sem a tükrökről): ${HIANYZO_KEPEK[*]} – ellenőrizd az internetet."
    fi
}
# Az alkalmazás képének építése (PHP 8.3 + Apache). Ha a PHP-alapkép frissítése nem megy (pl. a Docker Hub nem érhető
# el), a meglévő alapképből; ha a compose építője (buildx / bake) nem működik, a Docker egyszerű építőjével.
alkalmazas_epites() {
    AKT_MUVELET="az alkalmazás építése (PHP 8.3 + Apache)"
    if ujraprobal 2 dc build --pull app; then
        ok "Az alkalmazás képe elkészült (a PHP-alapkép is frissítve)"
    elif ujraprobal 2 dc build app; then
        ok "Az alkalmazás képe elkészült (a meglévő PHP-alapképből)"
    elif fut sh -c 'cd "$1" && COMPOSE_BAKE=false timeout 3600 docker compose build app' _ "$REPO"; then
        ok "Az alkalmazás képe elkészült (a compose egyszerű építőjével)"
    elif fut timeout 3600 docker build -t "$APP_KEP" "$REPO/docker"; then
        ok "Az alkalmazás képe elkészült (a Docker egyszerű építőjével)"
    else
        AKT_MUVELET=""
        return 1
    fi
    AKT_MUVELET=""
}
# A konténerek indítása. Ha a phpMyAdmin képe nem tölthető le, nélküle (a BaninaPRO-hoz nem kell). Ha nem indulnak el:
# egy idegen konténer foglalta portok felszabadítása, a félig elindultak leállítása (az adatok a kötetekben maradnak),
# és még egy próba.
kontenerek_inditasa() {
    local szolg=() k
    for k in "${HIANYZO_KEPEK[@]}"; do
        if [[ $k == *phpmyadmin* ]]; then szolg=(app db); fi
    done
    AKT_MUVELET="konténerek indítása (első induláskor a MySQL 1-2 percig készíti az adatbázist)"
    if fut dc up -d --remove-orphans "${szolg[@]}"; then AKT_MUVELET=""; return 0; fi
    # ha a MySQL újra és újra összeomlik, a további próbák csak az időt viszik – a hívó az adatbázist javítja
    if db_osszeomlik; then AKT_MUVELET=""; return 1; fi
    varj 10
    if fut dc up -d --remove-orphans "${szolg[@]}"; then AKT_MUVELET=""; return 0; fi
    port_felszabadit "$APP_PORT" || true
    if (( PMA_PORT_KI )); then port_felszabadit "$PMA_PORT" || true; fi
    fut dc down --remove-orphans || true
    varj 5
    if ujraprobal 2 dc up -d --remove-orphans "${szolg[@]}"; then AKT_MUVELET=""; return 0; fi
    AKT_MUVELET=""
    return 1
}

# ---- Adatbázis ----------------------------------------------------------------------
# MySQL-parancs az adatbázis-konténerben, a docker-compose.yml-ben megadott root-jelszóval és adatbázissal
db_sql() {
    timeout 3600 docker exec -i "$DB_KONTENER" sh -c 'exec mysql -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" "$@"' _ "$@"
}
# a végleges MySQL fut-e (az első indításkori ideiglenes szerver csak socketen figyel, TCP-n nem). A mysqladmin ping
# rossz jelszóval is sikeres, ha a szerver fut (Access denied = a szerver válaszolt).
db_kesz() {
    timeout 30 docker exec "$DB_KONTENER" sh -c 'mysqladmin ping -h 127.0.0.1 --protocol=TCP -uroot -p"$MYSQL_ROOT_PASSWORD" --silent' >/dev/null 2>&1
}
db_szam() { db_sql -e "$1" 2>/dev/null | tr -dc '0-9' || true; }
# a MySQL-konténer összeomlik: újraindul és újra leáll, vagy hibával kilépett
db_osszeomlik() {
    [[ $(timeout 30 docker inspect -f '{{.State.Status}}' "$DB_KONTENER" 2>/dev/null || true) =~ ^(restarting|exited|dead)$ ]]
}
db_nev() { timeout 30 docker exec "$DB_KONTENER" printenv MYSQL_DATABASE 2>/dev/null || echo baninapr_DATA; }
# a phpMyAdmin-felhasználó még a kezdőjelszóval lép-e be (ha igen, az összegzés figyelmeztet, hogy változtasd meg)
pma_kezdojelszo_el() {
    timeout 30 docker exec "$DB_KONTENER" mysql -N -B -h 127.0.0.1 -u"$PMA_FELH" -p"$PMA_KEZDO_JELSZO" -e 'SELECT 1' >/dev/null 2>&1
}
# Megvárja, amíg a MySQL elindul (első induláskor 1-2 perc); ha beragad, újraindítja. 1, ha így sem indul el.
db_var_inditasra() {
    local i
    AKT_MUVELET="várakozás az adatbázisra (első induláskor 1-2 perc)"
    for (( i = 0; i < 120; i++ )); do
        if db_kesz; then AKT_MUVELET=""; return 0; fi
        # ha a konténer hibával kilépett vagy újra és újra összeomlik, nincs mire várni
        if (( i > 15 )) && [[ $(timeout 30 docker inspect -f '{{.State.Status}}' "$DB_KONTENER" 2>/dev/null || true) =~ ^(exited|dead|restarting|)$ ]]; then
            break
        fi
        varj 2
    done
    fut timeout 300 docker restart "$DB_KONTENER" || true
    for (( i = 0; i < 60; i++ )); do
        if db_kesz; then AKT_MUVELET=""; return 0; fi
        varj 2
    done
    AKT_MUVELET=""
    return 1
}
# Régebbi, a nyilvános alapjelszóval (docker-compose.yml) létrehozott adatbázis: a root-jelszó átállítása a szerver
# saját jelszavára (a konténer a MYSQL_ROOT_PASSWORD-ben már ezt kapja, de az csak üres adatbázisnál érvényesül)
db_root_atallitas() {
    timeout 120 docker exec -i "$DB_KONTENER" sh -c 'exec mysql -uroot -p"$1"' _ "$ALAP_DB_ROOT" >/dev/null 2>&1 <<EOF || return 1
ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED BY '$DB_ROOT_JELSZO';
ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '$DB_ROOT_JELSZO';
FLUSH PRIVILEGES;
EOF
    db_sql -e 'SELECT 1' >/dev/null 2>&1 || return 1
    ok "Adatbázis: a nyilvános alapjelszó helyett a szerver saját root-jelszava él"
}
# Elveszett vagy megváltozott root-jelszó (pl. elveszett a $TITOK_MAPPA/titkok, vagy valaki átírta): a MySQL egyszeri
# indítása egy indítófájllal (--init-file), amely a root jelszavát a szerver jelszavára állítja – az adatokhoz nem nyúl.
# Utána a szokásos módon indul újra.
db_jelszo_helyreallitas() {
    local nev="baninapro-db-jelszo" sql="$TMPD/jelszo.sql" kep i jo=0
    info "Az adatbázis nem fogadja el a szerver jelszavát – helyreállítom (az adatokhoz nem nyúlok)…"
    AKT_MUVELET="az adatbázis jelszavának helyreállítása"
    kep="$(timeout 30 docker inspect -f '{{.Config.Image}}' "$DB_KONTENER" 2>/dev/null || true)"
    [[ -n $kep ]] || kep="$(kepek_listaja | grep -m1 -i mysql || echo mysql:latest)"
    {
        printf "CREATE USER IF NOT EXISTS 'root'@'localhost' IDENTIFIED BY '%s';\n" "$DB_ROOT_JELSZO"
        printf "ALTER USER 'root'@'localhost' IDENTIFIED BY '%s';\n" "$DB_ROOT_JELSZO"
        printf "GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION;\n"
        printf "CREATE USER IF NOT EXISTS 'root'@'%%' IDENTIFIED BY '%s';\n" "$DB_ROOT_JELSZO"
        printf "ALTER USER 'root'@'%%' IDENTIFIED BY '%s';\n" "$DB_ROOT_JELSZO"
        printf "GRANT ALL PRIVILEGES ON *.* TO 'root'@'%%' WITH GRANT OPTION;\n"
        printf 'FLUSH PRIVILEGES;\n'
    } > "$sql"
    chmod 644 "$sql"
    fut timeout 300 docker stop "$DB_KONTENER" || true
    timeout 60 docker rm -f "$nev" >/dev/null 2>&1 || true
    if fut timeout 300 docker run -d --name "$nev" -v "$DB_KOTET:/var/lib/mysql" -v "$sql:/baninapro-jelszo.sql:ro" "$kep" \
        --init-file=/baninapro-jelszo.sql --skip-networking; then
        for (( i = 0; i < 90; i++ )); do
            if timeout 30 docker exec "$nev" mysql -uroot -p"$DB_ROOT_JELSZO" -N -B -e 'SELECT 1' >/dev/null 2>&1; then jo=1; break; fi
            [[ $(timeout 30 docker inspect -f '{{.State.Running}}' "$nev" 2>/dev/null || true) == true ]] || break
            varj 2
        done
        if (( ! jo )); then timeout 30 docker logs --tail 20 "$nev" 2>&1 | sed 's/^/    | /' || true; fi
        fut timeout 300 docker stop -t 120 "$nev" || true
    fi
    timeout 60 docker rm -f "$nev" >/dev/null 2>&1 || true
    rm -f "$sql"
    kontenerek_inditasa || true
    db_var_inditasra || true
    AKT_MUVELET=""
    if (( jo )) && db_sql -e 'SELECT 1' >/dev/null 2>&1; then
        figy "Az adatbázis nem fogadta el a szerver jelszavát (elveszett vagy megváltozott) – a root-jelszót visszaállítottam a szerver jelszavára ($TITOK_MAPPA/titkok). Az adatok érintetlenek."
        return 0
    fi
    return 1
}
# Az alkalmazás adatbázis-felhasználója (a jelszava mindig a szerveré), és a phpMyAdmin-felhasználó (teljes jog a
# BaninaPRO-adatbázishoz) – ez csak akkor jön létre a kezdőjelszóval, ha még nincs; a jelszavát később nem írja felül
db_felhasznalok() {
    local u p d
    u="$(timeout 30 docker exec "$DB_KONTENER" printenv MYSQL_USER 2>/dev/null || true)"
    p="$(timeout 30 docker exec "$DB_KONTENER" printenv MYSQL_PASSWORD 2>/dev/null || true)"
    d="$(db_nev)"
    if [[ -n $u && -n $p && -n $d ]]; then
        printf "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';\nALTER USER '%s'@'%%' IDENTIFIED BY '%s';\nGRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'%%';\n" \
            "$u" "$p" "$u" "$p" "$d" "$u" | db_sql || figy "Az alkalmazás adatbázis-felhasználója ($u) nem állítható be."
    fi
    printf "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';\nGRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'%%';\n" \
        "$PMA_FELH" "$PMA_KEZDO_JELSZO" "$d" "$PMA_FELH" | db_sql || figy "A phpMyAdmin-felhasználó ($PMA_FELH) nem állítható be."
}

# Az adatbázis rendbetétele: megvárja a MySQL-t; a jelszavak (ha kell, helyreállítja őket); üres adatbázisnál a táblák
# és a kezdő admin a sémából; az alkalmazás és a phpMyAdmin felhasználója; ha az adatbázis üres, de van korábbi mentés,
# a legfrissebbet visszaállítja; végül a séma egyeztetése a kóddal (a hiányzó táblák, oszlopok, indexek pótlása).
adatbazis_rendbe() {
    local tablak felhasznalok
    db_var_inditasra || return 1
    # a root-jelszó: a szerver jelszava; egy régebbi adatbázisnál a nyilvános alapjelszó; ha egyik sem, helyreállítás
    if ! db_sql -e 'SELECT 1' >/dev/null 2>&1 && ! db_root_atallitas && ! db_jelszo_helyreallitas; then
        return 1
    fi
    # Üres adatbázis (nincs felhasználó): a teljes séma, a kezdő adminnal. Egy meglévő adatbázisba kezdő admin soha nem
    # kerül (egy átnevezett admin mellé a nyilvános kezdőjelszóval) – oda csak a hiányzó táblák stb. (sema_egyeztetes).
    felhasznalok="$(db_szam 'SELECT COUNT(*) FROM felhasznalok')"
    if [[ -z $felhasznalok || $felhasznalok == 0 ]]; then
        info "Az adatbázisban nincs felhasználó – a táblák és a kezdő admin a sémából…"
        AKT_MUVELET="adatbázis-táblák létrehozása a sémából"
        db_sql < "$SEMA" || true
        felhasznalok="$(db_szam 'SELECT COUNT(*) FROM felhasznalok')"
        if [[ -z $felhasznalok || $felhasznalok == 0 ]]; then AKT_MUVELET=""; return 1; fi
        ok "Adatbázis-táblák és a kezdő admin létrehozva a sémából"
    fi
    db_felhasznalok
    # üres adatbázis, de van korábbi mentés: a legfrissebb visszaállítása (--nincs-visszaallitas: kihagyja)
    if adatbazis_ures; then
        if (( NINCS_VISSZAALLITAS )); then
            info "Az adatbázis üres – a mentésből visszaállítást kihagyom (--nincs-visszaallitas)."
        else
            mentesbol_visszaallitas || true
        fi
    fi
    sema_egyeztetes || true
    AKT_MUVELET=""
    tablak="$(db_szam 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')"
    felhasznalok="$(db_szam 'SELECT COUNT(*) FROM felhasznalok')"
    ok "Adatbázis rendben: ${tablak:-?} tábla, ${felhasznalok:-?} felhasználó"
}

# Az adatbázisban nincs üzleti adat: legfeljebb egy felhasználó (a kezdő admin), és egyetlen cég, kötés, számla, utalás sem
adatbazis_ures() {
    local n
    n="$(db_szam 'SELECT (SELECT COUNT(*) FROM felhasznalok) + (SELECT COUNT(*) FROM cegek) + (SELECT COUNT(*) FROM kotesek) + (SELECT COUNT(*) FROM bejovo_szamlak) + (SELECT COUNT(*) FROM kimeno_szamlak) + (SELECT COUNT(*) FROM utalasok)')"
    [[ -n $n ]] && (( n <= 1 ))
}
# A teljes (lezárt) adatbázis-mentések, amelyekben üzleti adat van, az alkalmazás kötetében (DBBCKP) és a gép saját
# lemezén ($GEP_MENTES), a legfrissebb elöl, soronként: „időpont<TAB>útvonal<TAB>üzleti sorok<TAB>összes sor”.
# Üzleti adat: cégek, kötések, számlák, utalások, és a kezdő adminon túli felhasználók – a mentés táblánkénti
# fejlécéből („-- Tábla: `cegek` (2 sor)”). Az üres adatbázisról készült mentés (pl. egy visszaállítás előtti, vagy egy
# új telepítés éjszakai mentése) így sosem kerül egy adatokkal teli elé. A hívó $(...)-ben vagy <(...)-ben olvassa.
mentesek_keresese() {
    local m d f ido vege sorok uzleti re='-- Mentés vége: ([0-9]+) tábla, ([0-9]+) sor'
    m="$(timeout 30 docker volume inspect -f '{{.Mountpoint}}' "$ADATOK_KOTET" 2>/dev/null || true)"
    for d in "${m:+$m/DBBCKP}" "$GEP_MENTES"; do
        [[ -n $d && -d $d ]] || continue
        for f in "$d"/*.sql; do
            [[ -f $f ]] || continue
            vege="$(tail -c 4096 "$f" 2>/dev/null || true)"
            [[ $vege =~ $re ]] || continue   # csonka (félbemaradt) mentés: nem jó
            sorok=${BASH_REMATCH[2]}
            uzleti="$(awk -F'[`()]' '/^-- Tábla: `/ { n = $4 + 0; if ($2 == "felhasznalok") n -= 1
                if ($2 ~ /^(cegek|kotesek|bejovo_szamlak|kimeno_szamlak|utalasok|felhasznalok)$/ && n > 0) s += n }
                END { print s + 0 }' "$f" 2>/dev/null || echo 0)"
            [[ $uzleti =~ ^[0-9]+$ ]] && (( uzleti > 0 )) || continue
            ido="$(basename "$f")"
            if [[ $ido =~ ^([0-9]{8}_[0-9]{4}) ]]; then ido=${BASH_REMATCH[1]}; else ido="$(date -r "$f" +%Y%m%d_%H%M 2>/dev/null || echo 00000000_0000)"; fi
            printf '%s\t%s\t%s\t%s\n' "$ido" "$f" "$uzleti" "$sorok"
        done
    done | sort -r
}

# Üres adatbázis, de van korábbi mentés (pl. az adatbázis elveszett, sérült volt, vagy a Docker tárhelye újra
# létrejött): a legfrissebb, üzleti adatot tartalmazó teljes mentés visszaállítása az alkalmazás saját visszaállítójával
# (előtte az üres állapotról is mentés készül).
mentesbol_visszaallitas() {
    local mentesek=() ido="" uzleti="" sorok="" jelolt="" m nev cel kezdet
    mapfile -t mentesek < <(mentesek_keresese)
    (( ${#mentesek[@]} )) || return 0
    IFS=$'\t' read -r ido jelolt uzleti sorok <<<"${mentesek[0]}"
    [[ -n $jelolt ]] || return 0
    m="$(timeout 30 docker volume inspect -f '{{.Mountpoint}}' "$ADATOK_KOTET" 2>/dev/null || true)"
    if [[ -z $m ]]; then figy "A mentés ($jelolt) nem állítható vissza: az alkalmazás kötete nem található."; return 1; fi
    nev="$(basename "$jelolt")"
    cel="$m/DBBCKP/$nev"
    if [[ $jelolt != "$cel" ]]; then
        # a gép saját lemezén lévő másolat: az alkalmazás csak a saját mentés-mappájából állít vissza
        if [[ -e $cel ]] && ! cmp -s "$jelolt" "$cel"; then nev="${nev%.sql}_gepi.sql"; cel="$m/DBBCKP/$nev"; fi
        install -d -o "$WWW_UID" -g "$WWW_UID" -m 750 "$m/DBBCKP"
        if ! install -o "$WWW_UID" -g "$WWW_UID" -m 640 "$jelolt" "$cel"; then
            figy "A mentés ($jelolt) nem másolható az alkalmazás mentés-mappájába – nem állítottam vissza."
            return 1
        fi
    fi
    info "Az adatbázis üres, de van korábbi mentés: $nev ($sorok sor) – visszaállítom…"
    AKT_MUVELET="az adatbázis visszaállítása a mentésből ($nev)"
    kezdet="$(naplo_meret)"
    if fut timeout 3600 docker exec "$APP_KONTENER" php -q cron_mentes.php vissza "$nev"; then
        VISSZAALLITVA="$nev"
        figy "Az adatbázis üres volt – visszaállítottam a legfrissebb mentésből: $nev (${ido:0:4}-${ido:4:2}-${ido:6:2} ${ido:9:2}:${ido:11:2}, $sorok sor). Ha üres adatbázissal akartál indulni: sudo bash $(basename "$SCRIPT") --nincs-visszaallitas"
    else
        figy "A mentés ($nev) visszaállítása nem sikerült – az adatbázis üres maradt. $(naplo_resz "$kezdet" | grep -m1 -E 'HIBA|Error|error' || true)"
    fi
    AKT_MUVELET=""
}

# A MySQL nem indul el (sérült vagy egy másik MySQL-változattal írt adatfájlok, pl. egy áramszünet után): ha van teljes
# mentés, a régi adatfájlok félrekerülnek a gép saját lemezére (semmi nem vész el), az adatbázis újra létrejön, és a
# telepítő a legfrissebb mentésből visszaállítja. Mentés (vagy elég hely) nélkül az adatfájlokhoz nem nyúl.
adatbazis_ujraepites() {
    local mentesek=() m meret szabad hova
    mapfile -t mentesek < <(mentesek_keresese)
    if (( ${#mentesek[@]} == 0 )); then
        figy "A MySQL nem indul el, és nincs adatokat tartalmazó adatbázis-mentés, amiből újra lehetne építeni – az adatfájlokhoz nem nyúlok."
        return 1
    fi
    m="$(timeout 30 docker volume inspect -f '{{.Mountpoint}}' "$DB_KOTET" 2>/dev/null || true)"
    [[ -n $m && -d $m ]] || return 1
    # kevés hely? (a MySQL ettől is leállhat) – előbb takarítás, és ha az segített, nem kell újraépíteni
    if (( $(szabad_gb) < 2 )); then
        hely_felszabaditas
        if db_var_inditasra; then return 0; fi
    fi
    meret="$(du -sm "$m" 2>/dev/null | cut -f1 || true)"
    szabad="$(df -Pm / 2>/dev/null | awk 'NR == 2 { print $4 }' || true)"
    if (( ${szabad:-0} < ${meret:-0} + 1024 )); then
        figy "A MySQL nem indul el, és a régi adatfájlok félretételéhez nincs elég hely (${meret:-?} MB kellene) – az adatfájlokhoz nem nyúlok."
        return 1
    fi
    hova="$GEP_MENTES/serult-adatbazis-$(date +%Y%m%d_%H%M%S)"
    AKT_MUVELET="a régi adatfájlok félretétele ($hova)"
    fut timeout 300 docker stop "$DB_KONTENER" || true
    install -d -m 700 "$GEP_MENTES"
    if ! fut cp -a "$m" "$hova"; then
        rm -rf "$hova"
        figy "A MySQL nem indul el, és a régi adatfájlok nem másolhatók félre – az adatfájlokhoz nem nyúlok."
        AKT_MUVELET=""
        return 1
    fi
    AKT_MUVELET="az adatbázis újra létrehozása"
    fut timeout 120 docker rm -f "$DB_KONTENER" || true
    if ! fut timeout 300 docker volume rm "$DB_KOTET"; then
        figy "A sérült adatbázis-kötet nem törölhető ($DB_KOTET) – a régi adatfájlok másolata: $hova"
        AKT_MUVELET=""
        return 1
    fi
    SERULT_DB_MASOLAT="$hova"
    ELSO_INDITAS=1
    figy "A MySQL nem indult el (sérült adatfájlok?) – a régi adatfájlokat félretettem ($hova), az adatbázist újra létrehozom, és a legfrissebb mentésből visszaállítom."
    AKT_MUVELET=""
}

# A séma egyeztetése a kóddal: egy ideiglenes adatbázisba betölti a sql/schema.sql-t, és összeveti a valódival. Ami a
# valódiból hiányzik – tábla, oszlop, index, idegen kulcs –, azt pótolja; egy oszlopot csak akkor módosít, ha az adatot
# nem érinti (az ENUM/SET új értékei, hosszabb szöveg, nagyobb szám, NULL engedése, új alapérték). Semmit nem töröl.
# (Ugyanazt végzi, mint a kézi sql/frissites_X.Y.sql, ha egy frissítés új táblát vagy oszlopot hozott – és egy régebbi
# mentésből visszaállított adatbázist is a kódhoz igazít.)
SEMA_MINTA="baninapro_sema_minta"
sema_egyeztetes() {
    local kimenet sor n=0 kesz=0
    AKT_MUVELET="az adatbázis-séma egyeztetése a kóddal"
    if ! printf 'DROP DATABASE IF EXISTS `%s`;\nCREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$SEMA_MINTA" "$SEMA_MINTA" | db_sql \
        || ! timeout 600 docker exec -i "$DB_KONTENER" sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$1"' _ "$SEMA_MINTA" < "$SEMA"; then
        figy "A séma egyeztetése nem sikerült (a sql/schema.sql nem tölthető be egy ideiglenes adatbázisba)."
        printf 'DROP DATABASE IF EXISTS `%s`;\n' "$SEMA_MINTA" | db_sql >/dev/null 2>&1 || true
        AKT_MUVELET=""
        return 1
    fi
    kimenet="$(sema_osszevetes 2>&1 || true)"
    printf '%s\n' "$kimenet"   # (a naplóba)
    printf 'DROP DATABASE IF EXISTS `%s`;\n' "$SEMA_MINTA" | db_sql >/dev/null 2>&1 || true
    while IFS= read -r sor; do
        case $sor in
            "UJ "*)   n=$(( n + 1 )); info "Adatbázis-séma pótolva: ${sor#UJ }" ;;
            "FIGY "*) figy "Adatbázis-séma: ${sor#FIGY }" ;;
            "HIBA "*) figy "A séma egyeztetése nem sikerült: ${sor#HIBA }" ;;
            VEGE)     kesz=1 ;;
        esac
    done <<<"$kimenet"
    AKT_MUVELET=""
    if (( ! kesz )); then
        figy "A séma egyeztetése nem futott le végig – a napló végén látszik, miért."
        return 1
    fi
    if (( n )); then ok "Adatbázis-séma a kódhoz igazítva: $n változás (a hiányzó táblák, oszlopok, indexek pótolva)"
    else ok "Adatbázis-séma: megfelel a kódnak (sql/schema.sql)"; fi
}
# Az összevetés az alkalmazás konténerében fut (PHP + PDO): a root-jelszóval csatlakozik az adatbázishoz. Kimenete
# soronként: UJ|FIGY|HIBA <szöveg>, a végén VEGE.
sema_osszevetes() {
    timeout 900 docker exec -i -e BANINA_JELSZO="$DB_ROOT_JELSZO" -e BANINA_DB="$(db_nev)" -e BANINA_MINTA="$SEMA_MINTA" \
        "$APP_KONTENER" php <<'PHP'
<?php
// BaninaPRO szerver – az adatbázis-séma egyeztetése (a szerver_beallitas.sh futtatja). Csak hozzáad, semmit nem töröl.
error_reporting(E_ALL);
ini_set('display_errors', '1');
$jelszo = (string)getenv('BANINA_JELSZO');
$db = (string)getenv('BANINA_DB');
$minta = (string)getenv('BANINA_MINTA');
try {
    $pdo = new PDO('mysql:host=db;port=3306;charset=utf8mb4', 'root', $jelszo, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('USE ' . nv($db));
} catch (Throwable $e) {
    echo 'HIBA nem lehet csatlakozni az adatbázishoz: ', egysor($e->getMessage()), "\n";
    exit(1);
}

function nv(string $s): string { return '`' . str_replace('`', '``', $s) . '`'; }
function egysor(string $s): string { return trim((string)preg_replace('/\s+/', ' ', $s)); }

function tablak(PDO $pdo, string $db): array
{
    $st = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    $st->execute([$db]);
    return array_column($st->fetchAll(), 'TABLE_NAME');
}

/** oszlopnév (kisbetűvel) → information_schema-sor */
function oszlopok(PDO $pdo, string $db, string $t): array
{
    $st = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $st->execute([$db, $t]);
    $ki = [];
    foreach ($st->fetchAll() as $r) {
        $ki[strtolower($r['COLUMN_NAME'])] = $r;
    }
    return $ki;
}

/** a SHOW CREATE TABLE sorai: [oszlopok, kulcsok, idegen kulcsok, a teljes utasítás] – név (kisbetűvel) → definíció */
function letrehozo(PDO $pdo, string $db, string $t): array
{
    $sql = (string)$pdo->query('SHOW CREATE TABLE ' . nv($db) . '.' . nv($t))->fetch(PDO::FETCH_NUM)[1];
    $osz = $kulcs = $fk = [];
    foreach (preg_split('/\R/', $sql) as $s) {
        $s = rtrim(trim($s), ',');
        if (preg_match('/^`((?:[^`]|``)+)`\s/', $s, $m)) {
            $osz[strtolower(str_replace('``', '`', $m[1]))] = $s;
        } elseif (preg_match('/^CONSTRAINT `((?:[^`]|``)+)` FOREIGN KEY/', $s, $m)) {
            $fk[strtolower($m[1])] = $s;
        } elseif (preg_match('/^(?:UNIQUE |FULLTEXT |SPATIAL )?KEY `((?:[^`]|``)+)`/', $s, $m)) {
            $kulcs[strtolower($m[1])] = $s;
        } elseif (str_starts_with($s, 'PRIMARY KEY')) {
            $kulcs['primary'] = $s;
        }
    }
    return [$osz, $kulcs, $fk, $sql];
}

/** egy index / idegen kulcs lényege (név, megjegyzés, indextípus nélkül) – az azonos, csak máshogy nevezett kulcsokhoz */
function lenyeg(string $def): string
{
    $s = (string)preg_replace(["/\\s+COMMENT\\s+'(?:[^']|'')*'/i", '/\s+USING\s+\w+/i'], '', $def);
    $s = (string)preg_replace('/^(CONSTRAINT `(?:[^`]|``)+` |((?:UNIQUE |FULLTEXT |SPATIAL )?KEY) `(?:[^`]|``)+` )/', '$2 ', $s);
    return strtolower(egysor($s));
}

/** típus részei: 'varchar(100)' → ['varchar', '100', ''], 'int unsigned' → ['int', '', 'unsigned'] */
function tipus(string $t): array
{
    if (preg_match('/^([a-z]+)(?:\((.*)\))?\s*(.*)$/s', strtolower(trim($t)), $m)) {
        return [$m[1], $m[2] ?? '', trim($m[3] ?? '')];
    }
    return [strtolower($t), '', ''];
}

function enum_ertekek(string $lista): array
{
    preg_match_all("/'((?:[^']|'')*)'/", $lista, $m);
    return array_map(fn($x) => str_replace("''", "'", $x), $m[1]);
}

/** null: azonos típus; true: a régi típus minden értéke elfér az újban (bővítés); false: más / szűkebb típus */
function bovul(string $regi, string $uj): ?bool
{
    if (strtolower($regi) === strtolower($uj)) {
        return null;
    }
    [$rt, $rp, $rm] = tipus($regi);
    [$ut, $up, $um] = tipus($uj);
    $egesz = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4, 'bigint' => 5];
    $szoveg = ['tinytext' => 1, 'text' => 2, 'mediumtext' => 3, 'longtext' => 4];
    $blob = ['tinyblob' => 1, 'blob' => 2, 'mediumblob' => 3, 'longblob' => 4];
    if (in_array($rt, ['enum', 'set'], true) && $rt === $ut) {
        return array_diff(enum_ertekek($rp), enum_ertekek($up)) === [];
    }
    if (in_array($rt, ['char', 'varchar'], true) && in_array($ut, ['char', 'varchar'], true) && !($rt === 'varchar' && $ut === 'char')) {
        return (int)$up >= (int)$rp;
    }
    if (in_array($rt, ['binary', 'varbinary'], true) && $ut === 'varbinary') {
        return (int)$up >= (int)$rp;
    }
    if (isset($egesz[$rt], $egesz[$ut])) {
        return $egesz[$ut] >= $egesz[$rt] && str_contains($um, 'unsigned') === str_contains($rm, 'unsigned');
    }
    if (isset($szoveg[$rt], $szoveg[$ut])) {
        return $szoveg[$ut] >= $szoveg[$rt];
    }
    if (isset($blob[$rt], $blob[$ut])) {
        return $blob[$ut] >= $blob[$rt];
    }
    if ($rt === 'decimal' && $ut === 'decimal') {
        [$rpp, $rs] = array_map('intval', explode(',', $rp . ',0'));
        [$upp, $us] = array_map('intval', explode(',', $up . ',0'));
        return $us >= $rs && $upp - $us >= $rpp - $rs && str_contains($um, 'unsigned') === str_contains($rm, 'unsigned');
    }
    return false;
}

$vegrehajt = function (string $sql, string $leiras) use ($pdo): void {
    try {
        $pdo->exec($sql);
        echo "UJ $leiras\n";
    } catch (Throwable $e) {
        echo "FIGY $leiras – nem sikerült: ", egysor($e->getMessage()), "\n";
    }
};
$valodi = [];
foreach (tablak($pdo, $db) as $t) {
    $valodi[strtolower($t)] = $t;
}
foreach (tablak($pdo, $minta) as $t) {
    [$mOsz, $mKulcs, $mFk, $mSql] = letrehozo($pdo, $minta, $t);
    if (!isset($valodi[strtolower($t)])) {
        $vegrehajt($mSql, "új tábla: $t");
        continue;
    }
    $vt = $valodi[strtolower($t)];
    [$vOsz, $vKulcs, $vFk] = letrehozo($pdo, $db, $vt);
    $mInfo = oszlopok($pdo, $minta, $t);
    $vInfo = oszlopok($pdo, $db, $vt);
    // oszlopok – a minta sorrendjében, így az új oszlop a helyére kerül (AFTER az előtte lévő)
    $elozo = null;
    foreach ($mOsz as $nev => $def) {
        $m = $mInfo[$nev] ?? null;
        $v = $vInfo[$nev] ?? null;
        if ($v === null) {
            $vegrehajt('ALTER TABLE ' . nv($vt) . ' ADD COLUMN ' . $def . ($elozo === null ? ' FIRST' : ' AFTER ' . nv($elozo)), "új oszlop: $vt." . ($m['COLUMN_NAME'] ?? $nev));
        } elseif ($m !== null) {
            $b = bovul($v['COLUMN_TYPE'], $m['COLUMN_TYPE']);
            $nullSzukul = $v['IS_NULLABLE'] === 'YES' && $m['IS_NULLABLE'] === 'NO';
            $mas = $b === true || $v['IS_NULLABLE'] !== $m['IS_NULLABLE'] || $v['COLUMN_DEFAULT'] !== $m['COLUMN_DEFAULT']
                || strtolower((string)$v['EXTRA']) !== strtolower((string)$m['EXTRA']);
            if ($b === false || $nullSzukul) {
                echo "FIGY eltérő oszlop: $vt.{$v['COLUMN_NAME']} ({$v['COLUMN_TYPE']}" . ($v['IS_NULLABLE'] === 'YES' ? ' NULL' : '') . " → {$m['COLUMN_TYPE']}"
                    . ($m['IS_NULLABLE'] === 'YES' ? ' NULL' : '') . ") – nem módosítom, mert adatot érinthet (kézzel: sql/frissites_*.sql)\n";
            } elseif ($mas) {
                $vegrehajt('ALTER TABLE ' . nv($vt) . ' MODIFY COLUMN ' . $def, "oszlop igazítva: $vt.{$v['COLUMN_NAME']} ({$v['COLUMN_TYPE']} → {$m['COLUMN_TYPE']})");
            }
        }
        $elozo = $v['COLUMN_NAME'] ?? ($m['COLUMN_NAME'] ?? $nev);
    }
    // indexek: ami hiányzik (és más néven sincs meg ugyanaz)
    $vLenyeg = array_map('lenyeg', $vKulcs);
    foreach ($mKulcs as $nev => $def) {
        if (isset($vKulcs[$nev])) {
            if (lenyeg($vKulcs[$nev]) !== lenyeg($def)) {
                echo "FIGY eltérő index: $vt.$nev – nem módosítom (kézzel: sql/frissites_*.sql)\n";
            }
        } elseif (!in_array(lenyeg($def), $vLenyeg, true)) {
            $vegrehajt('ALTER TABLE ' . nv($vt) . ' ADD ' . $def, "új index: $vt.$nev");
        }
    }
    // idegen kulcsok
    $vFkLenyeg = array_map('lenyeg', $vFk);
    foreach ($mFk as $nev => $def) {
        if (!isset($vFk[$nev]) && !in_array(lenyeg($def), $vFkLenyeg, true)) {
            $vegrehajt('ALTER TABLE ' . nv($vt) . ' ADD ' . $def, "új idegen kulcs: $vt.$nev");
        }
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "VEGE\n";
PHP
}

lepes_mentes_cron() {
    if ! van_csomag cron; then telepit_min cron || figy "A cron nem telepíthető: $(apt_hibak)"; fi
    systemctl unmask cron >/dev/null 2>&1 || true
    systemctl enable --now cron >/dev/null 2>&1 || figy "A cron (ütemező) nem indul el – az éjszakai mentés nem fut le (systemctl status cron)."
    # a mentést egy kis burkoló futtatja: naplóz, a mentést a gép saját lemezére is átmásolja, és értesít
    mento_iras
    cat > /etc/cron.d/baninapro <<EOF
# BaninaPRO – napi teljes adatbázis-mentés, mint az éles cron (a szerver_beallitas.sh írta).
# A mentések a Docker-kötetben vannak (lista: docker exec $APP_KONTENER php -q cron_mentes.php lista),
# a legutóbbi $GEP_MENTES_DB a gép saját lemezén is: $GEP_MENTES
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
$MENTES_CRON root $MENTO
EOF
    chmod 644 /etc/cron.d/baninapro
    ok "Éjszakai adatbázis-mentés: minden nap 03:00, az eredményről push-értesítés (napló: /var/log/baninapro-mentes.log)"
    # a meglévő mentések másolata a gép saját lemezére (a Dockeren kívül)
    AKT_MUVELET="a mentések másolása a gép saját lemezére"
    if fut "$MENTO" masol; then
        ok "Mentések a gép saját lemezén is: $GEP_MENTES ($(find "$GEP_MENTES" -maxdepth 1 -name '*.sql' 2>/dev/null | wc -l) db, a legutóbbi $GEP_MENTES_DB marad meg)"
    else
        figy "A mentések nem másolhatók a gép saját lemezére ($GEP_MENTES): $(tail -n 1 "$NAPLO")"
    fi
    AKT_MUVELET=""
}

# A mentő segéd: az éjszakai mentés (cron), és a mentések másolata a gép saját lemezére
mento_iras() {
    {
        printf '#!/bin/bash\n# BaninaPRO adatbázis-mentés – a szerver_beallitas.sh írta, kézzel ne módosítsd (minden futása újraírja).\n'
        printf 'APP_KONTENER=%q\nERTESITO=%q\nKOTET=%q\nGEP_MENTES=%q\nGEP_MENTES_DB=%q\n' \
            "$APP_KONTENER" "$ERTESITO" "$ADATOK_KOTET" "$GEP_MENTES" "$GEP_MENTES_DB"
        cat <<'EOF'
#   baninapro-mentes          éjszakai mentés (a cron hívja 03:00-kor): mentés, másolat a gép saját lemezére, értesítés
#   baninapro-mentes masol    csak a másolat: a kötet legújabb mentései a gép saját lemezére (az őrszem 2 percenként hívja)
set -u
export LC_ALL=C.UTF-8
shopt -s nullglob
NAPLO=/var/log/baninapro-mentes.log
# A legújabb mentések másolata a gép saját lemezén, a Dockeren kívül: ha a Docker tárhelye (és vele az adatbázis meg a
# mentései) elveszne, a telepítő innen állítja vissza. Csak a teljes (lezárt) mentéseket másolja, csak ha van elég
# hely, és a legújabb GEP_MENTES_DB marad meg. Az utolsó kiírt sora az összefoglaló.
masol() {
    local d f nev meret szabad uj=0 fajlok=()
    d="$(timeout 30 docker volume inspect -f '{{.Mountpoint}}' "$KOTET" 2>/dev/null)"
    if [[ -z $d || ! -d $d/DBBCKP ]]; then echo "Másolat: az alkalmazás kötete nem található – most nem készül."; return 0; fi
    install -d -m 700 "$GEP_MENTES"
    mapfile -t fajlok < <(printf '%s\n' "$d"/DBBCKP/*.sql | sort -r | head -n "$GEP_MENTES_DB")
    for f in "${fajlok[@]}"; do
        nev="${f##*/}"
        [[ -n $nev && ! -f $GEP_MENTES/$nev ]] || continue
        tail -c 4096 "$f" 2>/dev/null | grep -q '^-- Mentés vége:' || continue   # félbemaradt vagy épp íródik
        meret=$(( $(stat -c %s "$f") / 1048576 + 1 ))
        szabad="$(df -Pm "$GEP_MENTES" | awk 'NR == 2 { print $4 }')"
        if (( szabad - meret < 1024 )); then echo "Másolat: kevés a hely a gép saját lemezén ($szabad MB) – nem készült."; return 1; fi
        if cp --preserve=timestamps "$f" "$GEP_MENTES/.$nev.tmp" && mv -f "$GEP_MENTES/.$nev.tmp" "$GEP_MENTES/$nev"; then
            chmod 600 "$GEP_MENTES/$nev"
            uj=$(( uj + 1 ))
        else
            rm -f "$GEP_MENTES/.$nev.tmp"
            echo "Másolat: a(z) $nev nem másolható."
            return 1
        fi
    done
    printf '%s\n' "$GEP_MENTES"/*.sql | sort -r | tail -n +$(( GEP_MENTES_DB + 1 )) | xargs -r rm -f --
    fajlok=("$GEP_MENTES"/*.sql)
    echo "Másolat a gép saját lemezén: $uj új, összesen ${#fajlok[@]} mentés ($GEP_MENTES)"
}
if [[ ${1:-} == masol ]]; then
    masol
    exit $?
fi
kimenet="$(timeout 1800 docker exec "$APP_KONTENER" php -q cron_mentes.php 2>&1)"
rc=$?
printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "${kimenet//$'\n'/ | }" >> "$NAPLO"
if (( rc == 0 )) && [[ $kimenet == OK* ]]; then
    masolat="$(masol 2>&1 | tail -n 1)"
    printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$masolat" >> "$NAPLO"
    "$ERTESITO" -p 2 -t floppy_disk "Éjszakai mentés kész" "$(tail -n 1 <<<"$kimenet") · $masolat" >/dev/null 2>&1 || true
else
    "$ERTESITO" -p 4 -t x,floppy_disk "Az éjszakai mentés NEM sikerült" "Kilépési kód: $rc – $(tail -n 3 <<<"$kimenet")" >/dev/null 2>&1 || true
fi
exit "$rc"
EOF
    } > "$MENTO.uj"
    chmod 755 "$MENTO.uj"
    mv -f "$MENTO.uj" "$MENTO"
}

# ---- Push-értesítések (ntfy) ----------------------------------------------------
lepes_ertesitesek() {
    local f="$TITOK_MAPPA/ntfy" asztal
    install -d -m 700 "$TITOK_MAPPA"
    # a titkos csatorna: egyszer készül, utána mindig ugyanaz (aki ismeri, olvashatja az értesítéseket)
    if ! grep -qE '^NTFY_CSATORNA=baninapro-[a-z0-9]{16,}$' "$f" 2>/dev/null; then
        printf '# BaninaPRO szerver – push-értesítések (ntfy); a csatorna neve titkos, mint egy jelszó\nNTFY_SZERVER=%s\nNTFY_CSATORNA=baninapro-%s\n' \
            "$NTFY_SZERVER" "$(veletlen_kod 24)" > "$f"
    fi
    chmod 600 "$f"
    NTFY_CSATORNA="$(sed -n 's/^NTFY_CSATORNA=//p' "$f")"
    ntfy_szerver_beolvas
    ertesito_iras
    belepesfigyelo_iras

    # indulás (áramszünet / újraindulás felismerése) és szabályos leállás – a gép életciklusa
    cat > /etc/systemd/system/baninapro-indulas.service <<EOF
[Unit]
Description=BaninaPRO értesítés: a szerver elindult (szabályos újraindulás vagy áramszünet után)
After=network-online.target docker.service
Wants=network-online.target

[Service]
# simple: a háttérben próbálkozik (internet nélkül akár 5 percig) – a rendszer indulását nem tartja fel
Type=simple
ExecStart=$ERTESITO --indulas

[Install]
WantedBy=multi-user.target
EOF
    cat > /etc/systemd/system/baninapro-leallas.service <<EOF
[Unit]
Description=BaninaPRO értesítés: a szerver szabályosan leáll vagy újraindul
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/true
ExecStop=$ERTESITO --leallas
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
EOF
    cat > /etc/systemd/system/baninapro-belepesfigyelo.service <<EOF
[Unit]
Description=BaninaPRO értesítés: be- és kilépések (az alkalmazás naplójából)
After=docker.service network-online.target
Wants=network-online.target

[Service]
ExecStart=$BELEPESFIGYELO
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable baninapro-indulas.service
    systemctl enable --now baninapro-leallas.service
    systemctl enable baninapro-belepesfigyelo.service
    systemctl restart baninapro-belepesfigyelo.service || true
    ok "Értesít: indulás (áramszünet után is), leállás, belépés / kilépés"

    # első alkalommal próba-értesítés (a feliratkozás után ez már látszik a telefonon)
    if ! grep -q '^NTFY_PROBA_KESZ=1' "$f"; then
        if "$ERTESITO" -p 3 -t bell "Értesítések bekapcsolva" \
            "Ez a próba-értesítés: a BaninaPRO szerver ($GEPNEV) mostantól ide jelez – leállás, újraindulás, áramszünet, hibák és helyreállás, AnyDesk / Docker, éjszakai mentés, napi jelentés ($JELENTES_IDO), belépések."; then
            echo 'NTFY_PROBA_KESZ=1' >> "$f"
            ok "Próba-értesítés elküldve"
        else
            figy "A próba-értesítés most nem ment el (nincs internet?) – sorba állt, az őrszem később elküldi."
        fi
    fi

    # a feliratkozás leírása az asztalon is (nem kötelező: ha nem sikerül, csak figyelmeztet)
    asztal="$(asztal_mappa)"
    if cat > "$asztal/BaninaPRO-ertesitesek.txt" <<EOF
BaninaPRO szerver – értesítések a telefonra (ntfy)

1. Telepítsd az ingyenes „ntfy” alkalmazást (iPhone: App Store, Android: Google Play).
2. Az alkalmazásban:  +  (Subscribe to topic)  →  Topic:  $NTFY_CSATORNA  →  Subscribe
   (a szerver az alapértelmezett ntfy.sh – nem kell átírni; az értesítéseket engedélyezd)
3. Kész: ide érkezik minden értesítés – áramszünet / újraindulás / leállás, hibák és helyreállás (BaninaPRO,
   AnyDesk, Docker, cron, konténerek, tárhely), az éjszakai mentés eredménye, a napi jelentés ($JELENTES_IDO),
   a telepítő (frissítés) eredménye, belépések és kilépések.

Böngészőben is olvasható: $NTFY_SZERVER/$NTFY_CSATORNA
A csatorna neve olyan, mint egy jelszó: aki ismeri, olvashatja az értesítéseket – ne add ki.
EOF
    then
        chown "$CEL_FELH:" "$asztal/BaninaPRO-ertesitesek.txt" || true
        chmod 600 "$asztal/BaninaPRO-ertesitesek.txt" || true
        ok "Push-értesítések: ntfy alkalmazás → + → $NTFY_CSATORNA (a leírás az asztalon: BaninaPRO-ertesitesek.txt)"
    else
        figy "A feliratkozás leírása nem került az asztalra ($asztal) – a csatorna: $NTFY_CSATORNA (az összegzésben is benne van)."
    fi
}
veletlen_kod() { tr -dc 'a-z0-9' </dev/urandom 2>/dev/null | head -c "$1" || true; }
# a ténylegesen beállított ntfy-szerver (a $TITOK_MAPPA/ntfy-ból – a feliratkozás leírásához és az összegzéshez)
ntfy_szerver_beolvas() {
    local s
    s="$(sed -n 's/^NTFY_SZERVER=//p' "$TITOK_MAPPA/ntfy" 2>/dev/null | head -n 1 || true)"
    if [[ -n $s ]]; then NTFY_SZERVER="$s"; fi
}

# Az értesítő: push-értesítés az ntfy-csatornára (JSON, így az ékezet is jó); ha nincs internet, sorba áll
ertesito_iras() {
    {
        printf '#!/bin/bash\n# BaninaPRO push-értesítés (ntfy) – a szerver_beallitas.sh írta, kézzel ne módosítsd (minden futása újraírja).\n'
        printf 'BEALLITAS=%q\n' "$TITOK_MAPPA/ntfy"
        cat <<'EOF'
#   baninapro-ertesites [-p 1-5] [-t címke,címke] "Cím" "Üzenet"   értesítés (ha nincs internet: sorba áll)
#   baninapro-ertesites --sorbol      a sorban álló értesítések elküldése (az őrszem hívja 2 percenként)
#   baninapro-ertesites --eletjel     „még élek” időbélyeg (az őrszem írja – ebből becsülhető egy áramszünet hossza)
#   baninapro-ertesites --indulas     a gép elindult: szabályos újraindulás vagy áramszünet / váratlan leállás után
#   baninapro-ertesites --leallas     a gép szabályosan leáll / újraindul (a systemd hívja leálláskor)
set -u
export LC_ALL=C.UTF-8
SOR=/var/spool/baninapro-ertesites
ALLAPOT=/var/lib/baninapro-ertesites
mkdir -p "$SOR" "$ALLAPOT"
SZERVER="$(sed -n 's/^NTFY_SZERVER=//p' "$BEALLITAS" 2>/dev/null)"
SZERVER="${SZERVER:-https://ntfy.sh}"
CSATORNA="$(sed -n 's/^NTFY_CSATORNA=//p' "$BEALLITAS" 2>/dev/null)"

json() {   # JSON-karakterlánc idézőjelekkel (\ " újsor tab, a többi vezérlőkarakter kimarad)
    local s=$1
    s=${s//\\/\\\\}
    s=${s//\"/\\\"}
    s=${s//$'\n'/\\n}
    s=${s//$'\t'/\\t}
    s=${s//$'\r'/}
    printf '"%s"' "$(printf '%s' "$s" | tr -d '\000-\010\013\014\016-\037')"
}
kuld_fajl() {   # 0 = elküldve, 1 = most nem megy (újra kell próbálni), 2 = a szerver elutasította (eldobható)
    local kod
    kod="$(curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 8 -m 20 -H 'Content-Type: application/json' \
        --data-binary @"$1" "$SZERVER/" 2>/dev/null || true)"
    if [[ $kod == 2* ]]; then return 0; fi
    if [[ $kod == 4* && $kod != 429 ]]; then return 2; fi
    return 1
}
sorbol() {   # a sorban állók a beérkezés sorrendjében; az első sikertelennél abbahagyja
    local f r zar
    # egyszerre csak egy folyamat küldjön (különben ugyanaz az értesítés kétszer is elmehetne)
    exec {zar}>"$ALLAPOT/sorbol.lock"
    flock -w 25 "$zar" || { exec {zar}>&-; return 1; }
    for f in "$SOR"/*.json; do
        [[ -f $f ]] || continue
        kuld_fajl "$f"
        r=$?
        if (( r == 1 )); then exec {zar}>&-; return 1; fi
        rm -f "$f"
    done
    exec {zar}>&-
    return 0
}
ertesit() {   # $1 prioritás (1–5), $2 címkék (vesszővel), $3 cím, $4 üzenet
    local f tags="" t tl=()
    [[ -n $CSATORNA ]] || return 1
    IFS=',' read -r -a tl <<<"$2"
    for t in "${tl[@]}"; do
        if [[ -n $t ]]; then tags+="${tags:+,}$(json "$t")"; fi
    done
    f="$SOR/$(date +%s%N)-$$.json"
    printf '{"topic":%s,"title":%s,"message":%s,"priority":%d,"tags":[%s]}\n' \
        "$(json "$CSATORNA")" "$(json "BaninaPRO · $3")" "$(json "${4:0:1800}")" "$1" "$tags" > "$f"
    if sorbol; then return 0; fi
    # nincs internet: sorban marad (legfeljebb 300 értesítés – a legrégebbiek törlődnek)
    ls -1t "$SOR"/*.json 2>/dev/null | tail -n +301 | xargs -r rm -f
    return 1
}
indulas() {
    local boot most eletjel kieses="" perc i
    boot="$(uptime -s)"
    if [[ -f $ALLAPOT/tiszta-leallas ]]; then
        ertesit 3 arrows_counterclockwise "Újraindult a szerver" \
            "Szabályos leállás: $(cat "$ALLAPOT/tiszta-leallas") → elindult: $boot. A BaninaPRO-t az őrszem 3 percen belül ellenőrzi."
        rm -f "$ALLAPOT/tiszta-leallas"
    else
        read -r eletjel most < "$ALLAPOT/eletjel" 2>/dev/null || true
        if [[ ${eletjel:-} =~ ^[0-9]+$ ]]; then
            perc=$(( ($(date -d "$boot" +%s) - eletjel) / 60 ))
            if (( perc >= 60 )); then kieses=" Utolsó életjel: ${most:-?} – a kiesés kb. $(( perc / 60 )) óra $(( perc % 60 )) perc."
            elif (( perc >= 0 )); then kieses=" Utolsó életjel: ${most:-?} – a kiesés kb. $perc perc."
            fi   # negatív: az óra indulás után még nem állt be – ilyenkor nem becsül
        fi
        ertesit 4 zap,warning "ÁRAMSZÜNET vagy váratlan leállás után újraindult" \
            "A gép nem szabályosan állt le (áramszünet, lefagyás vagy kihúzott kábel), most elindult: $boot.$kieses A BaninaPRO-t az őrszem 3 percen belül ellenőrzi, és ha kell, helyreállítja."
    fi
    # a hálózat indulás után lassan éledhet: legfeljebb 5 percig próbálkozik, utána a sorban marad (az őrszem elküldi)
    for (( i = 0; i < 30; i++ )); do
        sorbol && return 0
        sleep 10
    done
    return 0
}
leallas() {
    local mod="leáll"
    if systemctl list-jobs 2>/dev/null | grep -q 'reboot.target'; then mod="újraindul"; fi
    date '+%Y-%m-%d %H:%M:%S' > "$ALLAPOT/tiszta-leallas"
    ertesit 3 stop_sign "A szerver $mod" "Szabályos leállás: $(date '+%Y-%m-%d %H:%M') – a szerver most $mod." || true
}

case "${1:-}" in
    --sorbol)  sorbol ;;
    --eletjel) printf '%s %s\n' "$(date +%s)" "$(date '+%Y-%m-%d %H:%M')" > "$ALLAPOT/eletjel" ;;
    --indulas) indulas ;;
    --leallas) leallas ;;
    *)
        p=3 t=""
        while getopts 'p:t:' o; do
            case $o in p) p=$OPTARG ;; t) t=$OPTARG ;; *) ;; esac
        done
        shift $(( OPTIND - 1 ))
        [[ $p =~ ^[1-5]$ ]] || p=3
        ertesit "$p" "$t" "${1:-Értesítés}" "${2:-}"
        ;;
esac
EOF
    } > "$ERTESITO.uj"
    chmod 755 "$ERTESITO.uj"
    mv -f "$ERTESITO.uj" "$ERTESITO"
}

# A belépésfigyelő: az alkalmazás napi naplójából (a Docker-kötetben) a be- és kilépések (a lejárt munkamenet miatti
# kiléptetés is), a sikertelen és a blokkolt belépések push-értesítésként – a felhasználók egyéb tevékenysége nem
belepesfigyelo_iras() {
    {
        printf '#!/bin/bash\n# BaninaPRO belépésfigyelő – a szerver_beallitas.sh írta, kézzel ne módosítsd (minden futása újraírja).\n'
        printf 'KOTET=%q\nERTESITO=%q\n' "$ADATOK_KOTET" "$ERTESITO"
        cat <<'EOF'
set -u
export LC_ALL=C   # bájtpontos olvasás (a napló UTF-8 – a szöveg változatlanul megy tovább)
ALLAPOT=/var/lib/baninapro-ertesites/belepesfigyelo
mkdir -p "$(dirname "$ALLAPOT")"
naplo_mappa() { local m; m="$(timeout 30 docker volume inspect -f '{{.Mountpoint}}' "$KOTET" 2>/dev/null)"; [[ -n $m ]] && printf '%s/LOG' "$m"; }
mai_fajl() { printf '%s/%s.txt' "$1" "$(date -d '-3 hours' +%Y%m%d)"; }   # a napló napja 03:00-kor vált
feldolgoz() {   # [ÉÉÉÉ-HH-NN óó:pp:mm] felhasználó | IP | KÓD | EREDMÉNY | részletek
    local re='^\[([^]]+)\] ([^|]*) \| ([^|]*) \| (BELEPES|KILEPES|KILEPTETES) \| ([^|]*) \| (.*)$' ido felh ip kod er r
    [[ $1 =~ $re ]] || return 0
    ido=${BASH_REMATCH[1]} felh=${BASH_REMATCH[2]% } ip=${BASH_REMATCH[3]% } kod=${BASH_REMATCH[4]} er=${BASH_REMATCH[5]% } r=${BASH_REMATCH[6]}
    case "$kod:$er" in
        BELEPES:OK)        "$ERTESITO" -p 2 -t bust_in_silhouette "Belépett: $felh" "$ido · $r · IP: $ip" ;;
        KILEPES:OK)        "$ERTESITO" -p 2 -t wave "Kilépett: $felh" "$ido · IP: $ip" ;;
        KILEPTETES:*)      "$ERTESITO" -p 2 -t hourglass "Kiléptetve: $felh" "$ido · $r · IP: $ip" ;;   # lejárt munkamenet
        BELEPES:BLOKKOLVA) "$ERTESITO" -p 4 -t no_entry "Belépés blokkolva: $felh" "$ido · $r · IP: $ip" ;;
        BELEPES:*)         "$ERTESITO" -p 3 -t warning "Sikertelen belépés: $felh" "$ido · $r · IP: $ip" ;;
    esac
}
olvas() {   # a $1 fájl új, teljes sorai a $poz bájttól
    local meret darab teljes sor
    meret=$(stat -c %s "$1" 2>/dev/null || echo 0)
    if (( meret < poz )); then poz=0; fi   # a fájl rövidebb lett (pl. visszaállítás után) – elölről
    (( meret > poz )) || return 0
    darab="$(tail -c +$(( poz + 1 )) "$1" | head -c $(( meret - poz )); printf x)"
    darab="${darab%x}"
    [[ $darab == *$'\n'* ]] || return 0   # még nincs teljes sor
    teljes="${darab%$'\n'*}"
    poz=$(( poz + ${#teljes} + 1 ))
    while IFS= read -r sor; do feldolgoz "$sor"; done <<<"$teljes"
}
fajl="" poz=0 mappa=""
if [[ -f $ALLAPOT ]]; then read -r fajl poz < "$ALLAPOT" || true; fi
[[ $poz =~ ^[0-9]+$ ]] || poz=0
while true; do
    if [[ -z $mappa || ! -d $mappa ]]; then   # a kötet helye (csak akkor kérdezi a Dockert, ha még nem tudja)
        mappa="$(naplo_mappa)"
        if [[ -z $mappa || ! -d $mappa ]]; then mappa=""; sleep 30; continue; fi
    fi
    uj="$(mai_fajl "$mappa")"
    if [[ -z $fajl ]]; then   # első indulás: a mostani végéről (a régi sorokról nem küld értesítést)
        fajl=$uj
        poz=$(stat -c %s "$fajl" 2>/dev/null || echo 0)
    fi
    if [[ $uj != "$fajl" ]]; then   # napváltás (03:00): előbb a régi fájl maradéka, aztán az új az elejéről
        olvas "$fajl"
        fajl=$uj
        poz=0
    fi
    olvas "$fajl"
    printf '%s %s\n' "$fajl" "$poz" > "$ALLAPOT"
    sleep 5
done
EOF
    } > "$BELEPESFIGYELO.uj"
    chmod 755 "$BELEPESFIGYELO.uj"
    mv -f "$BELEPESFIGYELO.uj" "$BELEPESFIGYELO"
}

# Az őrszem: 2 percenként ellenőrzi, hogy a BaninaPRO elérhető-e (oldal + adatbázis), és ha nem, emberi beavatkozás
# nélkül helyreállítja. A script a beállításokkal együtt íródik ki; a systemd-időzítő futtatja.
orszem_iras() {
    {
        printf '#!/bin/bash\n# BaninaPRO őrszem – a szerver_beallitas.sh írta, kézzel ne módosítsd (minden futása újraírja).\n'
        printf 'REPO=%q\nAPP_KONTENER=%q\nDB_KONTENER=%q\nPMA_KONTENER=%q\nAPP_PORT=%q\nJELENTO=%q\nERTESITO=%q\nMENTO=%q\n' \
            "$REPO" "$APP_KONTENER" "$DB_KONTENER" "$PMA_KONTENER" "$APP_PORT" "$JELENTO" "$ERTESITO" "$MENTO"
        printf 'TITOK_MAPPA=%q\nCEL_FELH=%q\nREPO_URL=%q\nREPO_AG=%q\nWWW_UID=%q\n' \
            "$TITOK_MAPPA" "$CEL_FELH" "$REPO_URL" "$REPO_AG" "$WWW_UID"
        printf 'AUTO_FRISSITO_IDOZITOK=(%s)\n' "${AUTO_FRISSITO_IDOZITOK[*]}"
        cat <<'EOF'
# 2 percenként: életjel és a sorban álló értesítések elküldése; a részek ellenőrzése (Docker, AnyDesk, cron, hogy az
# automatikus frissítés ki maradjon, a szerver beállítófájljai, konténerek, tárhely, frissítés utáni újraindítás) –
# minden változásról push-értesítés –, a mentések másolata a gép saját lemezére, végül a BaninaPRO elérhetősége.
# Ha nem érhető el, lépcsőzetesen helyreállítja: a hiányzó vagy módosult programfájlok visszaállítása a gitből → a
# hiányzó / leállt konténerek indítása → a konténerek újraindítása → a Docker újraindítása (legfeljebb félóránként) →
# ha 1 órán át sem sikerül, a gép újraindítása (legfeljebb 6 óránként). Közben értesít, és szól, ha helyreállt.
NAPLO=/var/log/baninapro-orszem.log
ALLAPOT=/var/lib/baninapro-orszem
mkdir -p "$ALLAPOT"
# egyszerre csak egy fusson; amíg a telepítő dolgozik (ő is ezt a zárat fogja), nem avatkozik be
exec 9>/run/baninapro-orszem.lock
flock -n 9 || exit 0

naplo() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$NAPLO"; }
# egy parancs kimenete a naplóba, minden sora dátummal (a napi jelentés a dátum szerint válogatja ki az elmúlt 24 órát);
# a parancs kilépési kódjával tér vissza
naplora() {
    local rc s
    "$@" > "$ALLAPOT/kimenet" 2>&1
    rc=$?
    while IFS= read -r s; do naplo "  $s"; done < "$ALLAPOT/kimenet"
    return "$rc"
}
ert() { naplora "$ERTESITO" "$@" || true; }   # push-értesítés (ha nincs internet: sorba áll)
dc() { ( cd "$REPO" && naplora timeout 600 docker compose "$@" ); }
# egy rész állapotának változása ($1 = név, $2 = ok | hiba): 0, ha változott – az első futás „ok”-nak veszi a korábbit
valtozott() {
    local f="$ALLAPOT/allapot-$1" regi
    regi="$(cat "$f" 2>/dev/null || echo ok)"
    echo "$2" > "$f"
    [[ $regi != "$2" ]]
}
rendben() {
    [[ $(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:$APP_PORT/" || true) == 200 ]] || return 1
    timeout 30 docker exec "$APP_KONTENER" php -r 'require "/var/www/html/includes/db.php"; db_val("SELECT 1");' >/dev/null 2>&1
}
var_rendben() {   # legfeljebb $1 másodpercig vár, hogy helyreálljon
    local i
    for (( i = 0; i < $1; i += 10 )); do rendben && return 0; sleep 10; done
    rendben
}
mp_ota() { echo $(( $(date +%s) - $(cat "$1" 2>/dev/null || echo 0) )); }
# git a BaninaPRO mappa tulajdonosa nevében (a mappa az övé)
felh_git() {
    runuser -u "$CEL_FELH" -- env HOME="$(getent passwd "$CEL_FELH" | cut -d: -f6)" GIT_TERMINAL_PROMPT=0 \
        GIT_AUTHOR_NAME="BaninaPRO szerver" GIT_AUTHOR_EMAIL="$CEL_FELH@localhost" \
        GIT_COMMITTER_NAME="BaninaPRO szerver" GIT_COMMITTER_EMAIL="$CEL_FELH@localhost" git "$@"
}
# A szerver beállítófájljai: a docker-compose.override.yml (a BaninaPRO mappában – egy git clean vagy egy újra letöltött
# mappa után hiányozhat) és az alkalmazás beállítófájlja. Ha hiányzik vagy eltér, a telepítő által mentett példányból
# visszaállítja – különben a konténerek a nyilvános alapbeállítással, rossz jelszóval indulnának.
beallitasok_rendben() {
    local valt=0 jelszo f="$TITOK_MAPPA/config.php"
    # (ha maga a BaninaPRO mappa hiányos, azt a kod_rendben hozza rendbe – addig ide nincs mit visszaírni)
    [[ -f $REPO/docker-compose.yml ]] || return 0
    if [[ -f $TITOK_MAPPA/docker-compose.override.yml ]] \
        && ! cmp -s "$TITOK_MAPPA/docker-compose.override.yml" "$REPO/docker-compose.override.yml"; then
        if install -m 600 -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH" 2>/dev/null || echo root)" \
            "$TITOK_MAPPA/docker-compose.override.yml" "$REPO/docker-compose.override.yml"; then
            valt=1
            naplo "A docker-compose.override.yml hiányzott vagy eltért – visszaállítva"
        fi
    fi
    if [[ -d $f ]]; then rm -rf -- "$f"; fi   # (a Docker mappát hoz létre a helyén, ha a fájl hiányzott)
    if [[ ! -s $f && -f $REPO/docker/config.php && -s $TITOK_MAPPA/titkok ]]; then
        jelszo="$(sed -n 's/^DB_APP_JELSZO=//p' "$TITOK_MAPPA/titkok")"
        if [[ -n $jelszo ]] && sed -e "s/^define('DB_PASS', *'[^']*');/define('DB_PASS', '$jelszo');/" \
            -e "s/^define('APP_DEBUG', *true);/define('APP_DEBUG', false);/" "$REPO/docker/config.php" > "$f.uj"; then
            cat "$f.uj" > "$f"
            rm -f "$f.uj"
            chown "root:$WWW_UID" "$f"
            chmod 640 "$f"
            valt=2
            naplo "Az alkalmazás beállítófájlja ($f) hiányzott – visszaállítva"
        fi
    fi
    if (( valt )); then
        ert -p 4 -t wrench "A szerver beállítófájlja hiányzott" "A BaninaPRO szerver-beállítása (docker-compose.override.yml / config.php) hiányzott vagy eltért – visszaállítottam, a konténerek újraindulnak."
        dc up -d --remove-orphans
        if (( valt == 2 )); then naplora timeout 180 docker restart "$APP_KONTENER"; fi
    fi
}
# A BaninaPRO programfájljai (csak ha a BaninaPRO nem érhető el): ha a mappa hiányzik, a GitHubról újra letölti; ha
# programfájlok hiányoznak vagy módosultak, a gitben lévő változatot állítja vissza (a módosítás a git stash-be kerül).
kod_rendben() {
    local hova
    if [[ ! -f $REPO/docker-compose.yml || ! -f $REPO/index.php ]]; then
        if [[ -d $REPO/.git ]]; then
            naplo "Hiányoznak a BaninaPRO programfájljai – visszaállítás a gitből"
            naplora felh_git -C "$REPO" checkout -f HEAD -- .
        else
            # A mappa hiányzik, vagy csak egy váz van a helyén (egy újraindított konténerhez a Docker üresen hozza létre,
            # benne az includes/config.php csatolási pontjával): a váz félre (nem törlődik), és újra letöltés a GitHubról
            if [[ -e $REPO ]]; then
                hova="$REPO.hianyos-$(date +%Y%m%d_%H%M%S)"
                if mv "$REPO" "$hova"; then naplo "A BaninaPRO mappa hiányos volt – félretéve: $hova"; fi
            fi
            naplo "A BaninaPRO mappa hiányzik – letöltés a GitHubról"
            naplora timeout 600 runuser -u "$CEL_FELH" -- env GIT_TERMINAL_PROMPT=0 git clone -q -b "$REPO_AG" "$REPO_URL" "$REPO"
        fi
        if [[ -f $REPO/docker-compose.yml ]]; then
            ert -p 4 -t wrench "A BaninaPRO programfájljai hiányoztak" "Visszaállítottam őket (a gitből / a GitHubról)."
            beallitasok_rendben
            # (a futó konténer a régi mappát csatolta – újraindítva már az újat látja)
            naplora timeout 180 docker restart "$APP_KONTENER"
        fi
    elif [[ -d $REPO/.git && -n $(felh_git -C "$REPO" status --porcelain --untracked-files=no 2>/dev/null) ]]; then
        naplo "A BaninaPRO programfájljai módosultak – a gitben lévő változat vissza (a módosítás: git stash)"
        naplora felh_git -C "$REPO" stash push -q -m "őrszem: automatikusan félretéve $(date '+%Y-%m-%d %H:%M')" \
            || naplora felh_git -C "$REPO" checkout -f HEAD -- .
        ert -p 4 -t wrench "A BaninaPRO programfájljai módosultak" "A BaninaPRO nem volt elérhető, és a programfájljai módosultak – a gitben lévő változatot állítottam vissza (a módosítás megmaradt: git stash list)."
    fi
}
helyreallt() {
    naplo "Helyreállt: $1"
    ert -p 3 -t white_check_mark "A BaninaPRO újra elérhető" "Helyreállt $1 (kb. $(( $(mp_ota "$ALLAPOT/hiba_ota") / 60 )) perc kiesés után)."
    if [[ -f $ALLAPOT/riasztva ]]; then
        rm -f "$ALLAPOT/riasztva"
        naplora "$JELENTO" riasztas "A BaninaPRO újra elérhető ($1)." || true
    fi
    rm -f "$ALLAPOT/hiba_ota"
    exit 0
}

# a napló ne nőjön a végtelenségig
if [[ -f $NAPLO ]] && (( $(stat -c %s "$NAPLO") > 5000000 )); then mv -f "$NAPLO" "$NAPLO.1"; fi

# életjel (egy áramszünet hosszát ebből becsüli az induláskori értesítés) és a korábban el nem küldött értesítések
"$ERTESITO" --eletjel >/dev/null 2>&1 || true
"$ERTESITO" --sorbol >/dev/null 2>&1 || true

# --- a részek: minden változásról értesítés ---
# Docker
docker_fut=1
if timeout 30 docker info >/dev/null 2>&1; then
    if valtozott docker ok; then ert -p 3 -t white_check_mark "A Docker újra fut" "A Docker szolgáltatás ismét működik."; fi
else
    naplo "A Docker nem válaszol – indítás"
    systemctl reset-failed containerd docker >/dev/null 2>&1
    naplora systemctl restart containerd docker
    sleep 10
    if timeout 30 docker info >/dev/null 2>&1; then
        ert -p 4 -t warning "A Docker leállt" "A Docker szolgáltatás nem válaszolt – újraindítottam, most már fut."
        echo ok > "$ALLAPOT/allapot-docker"
    else
        docker_fut=0
        if valtozott docker hiba; then ert -p 5 -t rotating_light "A Docker nem fut" "A Docker szolgáltatás leállt, és újraindítás után sem indult el – a BaninaPRO nem elérhető."; fi
    fi
fi
# szolgáltatások (ha telepítve vannak): mindig fussanak – ha leálltak, újraindítja
figyel() {   # $1 = systemd-egység, $2 = megnevezés, $3 = mi nem működik nélküle
    systemctl cat "$1" >/dev/null 2>&1 || return 0
    if systemctl is-active --quiet "$1"; then
        if valtozott "$1" ok; then ert -p 3 -t white_check_mark "$2 újra fut" "$2 ismét működik."; fi
        return 0
    fi
    naplo "$2 nem fut – újraindítás"
    systemctl reset-failed "$1" >/dev/null 2>&1
    naplora systemctl restart "$1"
    sleep 5
    if systemctl is-active --quiet "$1"; then
        ert -p 4 -t warning "$2 leállt" "$2 nem futott – újraindítottam, most már fut."
        echo ok > "$ALLAPOT/allapot-$1"
    elif valtozott "$1" hiba; then
        ert -p 4 -t warning "$2 nem fut" "$2 leállt, és újraindítás után sem indult el – $3."
    fi
}
figyel anydesk "Az AnyDesk" "a távoli elérés most nem működik"
figyel cron "A cron (ütemező)" "az éjszakai adatbázis-mentés nem fut le"
# az automatikus rendszerfrissítés maradjon kikapcsolva (kevés a tárhely) – ha valami visszakapcsolta, újra ki
for e in "${AUTO_FRISSITO_IDOZITOK[@]}"; do
    a="$(systemctl is-enabled "$e" 2>/dev/null)"
    if [[ -n $a && $a != masked && $a != masked-runtime && $a != not-found ]]; then
        naplo "$e: $a – az automatikus frissítés újra kikapcsolva"
        systemctl disable --now "$e" >/dev/null 2>&1
        systemctl mask "$e" >/dev/null 2>&1
        ert -p 3 -t warning "Az automatikus frissítés visszakapcsolódott" "$e ($a) – újra kikapcsoltam: a szerver ne keressen és ne töltsön le frissítést magától (kevés a tárhely)."
    fi
done
# a szerver beállítófájljai (override, config.php): ha hiányoznak, vissza
if (( docker_fut )); then beallitasok_rendben; fi
# konténerek: fut-e, és újraindította-e a Docker (összeomlás után)
if (( docker_fut )); then
    for k in "$APP_KONTENER" "$DB_KONTENER" "$PMA_KONTENER"; do
        a="$(timeout 30 docker inspect -f '{{.State.Status}} {{.RestartCount}}' "$k" 2>/dev/null || echo 'hiányzik 0')"
        allapot="${a% *}" db="${a##* }"
        regi_db="$(cat "$ALLAPOT/ujraindulas-$k" 2>/dev/null || echo "$db")"
        echo "$db" > "$ALLAPOT/ujraindulas-$k"
        if [[ $db =~ ^[0-9]+$ && $regi_db =~ ^[0-9]+$ ]] && (( db > regi_db )); then
            ert -p 3 -t arrows_counterclockwise "A(z) $k konténer újraindult" "A Docker újraindította (összesen ${db}×) – a konténer naplója: docker logs $k"
        fi
        if [[ $allapot == running ]]; then
            if valtozott "kontener-$k" ok; then ert -p 3 -t white_check_mark "A(z) $k konténer újra fut" "A konténer ismét működik."; fi
            continue
        fi
        naplo "A(z) $k konténer nem fut ($allapot) – indítás"
        dc up -d --remove-orphans
        sleep 5
        if [[ $(timeout 30 docker inspect -f '{{.State.Status}}' "$k" 2>/dev/null) == running ]]; then
            ert -p 4 -t warning "A(z) $k konténer leállt" "Állapota „$allapot” volt – elindítottam, most már fut."
            echo ok > "$ALLAPOT/allapot-kontener-$k"
        elif valtozott "kontener-$k" hiba; then
            ert -p 4 -t warning "A(z) $k konténer nem fut" "Állapota: $allapot – elindítás után sem fut."
        fi
    done
fi
# tárhely
szabad=$(df -Pk / | awk 'NR == 2 { print int($4 / 1024 / 1024) }')
if (( szabad < 5 )); then
    if valtozott tarhely hiba; then ert -p 4 -t warning "Kevés a szabad hely" "Már csak $szabad GB szabad a lemezen."; fi
elif valtozott tarhely ok; then
    ert -p 3 -t white_check_mark "Van elég szabad hely" "Szabad hely a lemezen: $szabad GB."
fi
# rendszerfrissítés után újraindítás kellene (a gép magától nem indul újra)
if [[ -f /var/run/reboot-required ]]; then
    if valtozott ujrainditas_kell hiba; then ert -p 2 -t information_source "Újraindítás ajánlott" "Rendszerfrissítés után a gép újraindítása szükséges – alkalmas időben: sudo reboot"; fi
else
    valtozott ujrainditas_kell ok || true
fi
# a legújabb adatbázis-mentések másolata a gép saját lemezére (ha nincs új mentés, nem csinál semmit)
if (( docker_fut )) && [[ -x $MENTO ]]; then
    m="$("$MENTO" masol 2>&1)"
    mr=$?
    m="$(tail -n 1 <<<"$m")"
    if (( mr )); then
        naplo "$m"
        if valtozott mentes_masolat hiba; then ert -p 3 -t warning "A mentések másolata nem készül" "$m"; fi
    elif valtozott mentes_masolat ok; then
        ert -p 3 -t white_check_mark "A mentések másolata újra készül" "$m"
    fi
fi

# --- a BaninaPRO elérhetősége ---
if rendben; then
    if [[ -f $ALLAPOT/hiba_ota ]]; then helyreallt "magától"; fi
    exit 0
fi
if [[ ! -f $ALLAPOT/hiba_ota ]]; then
    date +%s > "$ALLAPOT/hiba_ota"
    ert -p 4 -t rotating_light "A BaninaPRO nem érhető el" "Az oldal vagy az adatbázis nem válaszol – az őrszem most helyreállítja."
fi
naplo "A BaninaPRO nem érhető el – helyreállítás…"

# kevés hely: a felesleg törlése (a konténerekhez és a kötetekhez – az adatokhoz – nem nyúl)
if (( $(df -Pk / | awk 'NR == 2 { print int($4 / 1024) }') < 2048 )); then
    naplo "Kevés a szabad hely – a felesleg törlése"
    naplora timeout 600 docker image prune -f
    naplora timeout 600 docker builder prune -f
    naplora journalctl --vacuum-size=200M
    apt-get clean
fi
# 1) a Docker fusson
if ! timeout 30 docker info >/dev/null 2>&1; then
    naplo "A Docker nem válaszol – indítás"
    systemctl reset-failed containerd docker >/dev/null 2>&1
    naplora timeout 300 systemctl restart containerd docker
    sleep 10
fi
# 2) a BaninaPRO programfájljai és beállítófájljai (ha hiányoznak vagy módosultak), a hiányzó vagy leállt konténerek
kod_rendben
beallitasok_rendben
dc up -d --remove-orphans
var_rendben 120 && helyreallt "a konténerek indítása után"
# 3) a konténerek újraindítása
naplo "Még mindig nem érhető el – a konténerek újraindítása"
dc restart
var_rendben 180 && helyreallt "a konténerek újraindítása után"
# 4) a Docker újraindítása – legfeljebb félóránként
if (( $(mp_ota "$ALLAPOT/docker_ujrainditas") > 1800 )); then
    naplo "Még mindig nem érhető el – a Docker újraindítása"
    date +%s > "$ALLAPOT/docker_ujrainditas"
    naplora timeout 300 systemctl restart containerd docker
    sleep 15
    dc up -d --remove-orphans
    var_rendben 240 && helyreallt "a Docker újraindítása után"
fi
# 5) riasztás e-mailben – ha már 10 perce nem jó (legfeljebb 6 óránként)
if (( $(mp_ota "$ALLAPOT/hiba_ota") > 600 && $(mp_ota "$ALLAPOT/riasztva") > 21600 )); then
    date +%s > "$ALLAPOT/riasztva"
    ert -p 5 -t rotating_light "A BaninaPRO $(( $(mp_ota "$ALLAPOT/hiba_ota") / 60 )) perce NEM érhető el" \
        "Az őrszem még nem tudta helyreállítani (konténerek, Docker újraindítva). Ha 1 órán belül sem sikerül, újraindítja a gépet."
    naplora "$JELENTO" riasztas "A BaninaPRO $(( $(mp_ota "$ALLAPOT/hiba_ota") / 60 )) perce nem érhető el, és az őrszem még nem tudta helyreállítani. Ha 1 órán belül sem sikerül, újraindítja a gépet." || true
fi
# 6) végső eset: ha már 1 órája nem jó, a gép újraindítása (legfeljebb 6 óránként)
if (( $(mp_ota "$ALLAPOT/hiba_ota") > 3600 && $(mp_ota "$ALLAPOT/gep_ujrainditas") > 21600 )); then
    naplo "1 órája nem érhető el – a gép újraindítása"
    ert -p 5 -t rotating_light "Újraindítom a szervert" "A BaninaPRO 1 órája nem érhető el, a többi lépés nem segített – végső lépésként a gép most újraindul."
    date +%s > "$ALLAPOT/gep_ujrainditas"
    sync
    systemctl reboot
    exit 0
fi
naplo "Most nem sikerült helyreállítani – a következő ellenőrzés újra megpróbálja."
exit 1
EOF
    } > "$ORSZEM.uj"
    chmod 755 "$ORSZEM.uj"
    mv -f "$ORSZEM.uj" "$ORSZEM"
}

lepes_orszem() {
    orszem_iras
    cat > /etc/systemd/system/baninapro-orszem.service <<EOF
[Unit]
Description=BaninaPRO őrszem – a szolgáltatás ellenőrzése és helyreállítása
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=$ORSZEM
TimeoutStartSec=20min
EOF
    cat > /etc/systemd/system/baninapro-orszem.timer <<'EOF'
[Unit]
Description=BaninaPRO őrszem – 2 percenként

[Timer]
OnBootSec=3min
OnUnitInactiveSec=2min
AccuracySec=15s

[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload
    systemctl enable --now baninapro-orszem.timer
    ok "Őrszem: 2 percenként ellenőrzi a BaninaPRO-t, és ha nem érhető el, magától helyreállítja (napló: /var/log/baninapro-orszem.log)"
}

# Napi állapotjelentés: push-értesítés (ntfy), és ha van feladó-postafiók, e-mail is – a levelet a curl beépített
# SMTP-küldése viszi, külön program, modul vagy licenc nem kell hozzá. Asztali ikon is készül a kézi futtatáshoz.
lepes_jelentes() {
    local asztal
    install -d -m 700 "$TITOK_MAPPA"
    if [[ -n $EMAIL_FELADO && -n $EMAIL_JELSZO ]]; then email_mentes; fi
    jelento_iras

    cat > /etc/systemd/system/baninapro-jelentes.service <<EOF
[Unit]
Description=BaninaPRO napi állapotjelentés (push-értesítés, e-mail)
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=$JELENTO napi
TimeoutStartSec=15min
EOF
    cat > /etc/systemd/system/baninapro-jelentes.timer <<EOF
[Unit]
Description=BaninaPRO napi állapotjelentés – minden nap $JELENTES_IDO

[Timer]
OnCalendar=*-*-* $JELENTES_IDO:00
Persistent=true

[Install]
WantedBy=timers.target
EOF
    systemctl daemon-reload
    systemctl enable --now baninapro-jelentes.timer

    # asztali ikon: az ellenőrzés és a jelentés kézzel, terminálablakban (a sudo-szabály csak erre az egy parancsra szól)
    printf '%s ALL=(root) NOPASSWD: %s kezi\n' "$CEL_FELH" "$JELENTO" > "$TMPD/sudoers"
    if visudo -cf "$TMPD/sudoers" >/dev/null 2>&1; then
        install -m 440 -o root -g root "$TMPD/sudoers" /etc/sudoers.d/baninapro-jelentes
    else
        figy "A kézi ellenőrzés sudo-szabálya nem állítható be – az asztali ikon jelszót fog kérni."
    fi
    asztal="$(asztal_mappa)"
    ikon_iras "$asztal/baninapro-ellenorzes.desktop"
    felh mkdir -p "$CEL_HOME/.local/share/applications"
    ikon_iras "$CEL_HOME/.local/share/applications/baninapro-ellenorzes.desktop"
    ok "Asztali ikon: „BaninaPRO ellenőrzés” ($asztal) – kézzel is lefuttatja az ellenőrzést, és elküldi a jelentést"

    JELENTES_FELADO="$(sed -n 's/^EMAIL_FELADO=//p; s/^EMAIL_CIM=//p' "$TITOK_MAPPA/email" 2>/dev/null | head -n 1 || true)"
    if [[ -n $JELENTES_FELADO ]]; then
        ok "Napi állapotjelentés: minden nap $JELENTES_IDO-kor push-értesítésként és e-mailben → $JELENTES_CIMZETT (feladó: $JELENTES_FELADO)"
    else
        ok "Napi állapotjelentés: minden nap $JELENTES_IDO-kor push-értesítésként (e-mailben is, ha beállítod: sudo bash $(basename "$SCRIPT") --email)"
    fi
}

# A most megadott feladó-postafiók mentése: a cím és a szerver az email fájlba, a belépési adat a curl beállítófájljába
# (idézőjelben, így bármilyen jelszó jó, és nem látszik a folyamatlistában) – csak a root olvashatja
email_mentes() {
    local j="${EMAIL_JELSZO//\\/\\\\}"
    j="${j//\"/\\\"}"
    (
        umask 077
        printf 'EMAIL_FELADO=%s\nSMTP_HOST=%s\nSMTP_PORT=%s\n' "$EMAIL_FELADO" "$EMAIL_SMTP_HOST" "$EMAIL_SMTP_PORT" > "$TITOK_MAPPA/email"
        printf '# a feladó-postafiók belépési adatai (a szerver_beallitas.sh írta)\nuser = "%s:%s"\n' "$EMAIL_FELADO" "$j" \
            > "$TITOK_MAPPA/smtp.curl"
    )
    rm -f "$TITOK_MAPPA/smtp.netrc"
    EMAIL_JELSZO=""
    EMAIL_UJ=1
}

# a felhasználó asztal-mappája (magyarul általában ~/Asztal) az xdg-user-dirs szerint – ha kell, létrehozza.
# A hívó $(...)-ben olvassa: a kimenetén csak az útvonal lehet, minden más (pl. a csomagtelepítésé) a naplóba megy.
asztal_mappa() {
    local m=""
    command -v xdg-user-dirs-update >/dev/null || telepit_opcionalis xdg-user-dirs >&2
    felh env LANG="$NYELV" LC_ALL="$NYELV" xdg-user-dirs-update >/dev/null 2>&1 || true
    m="$(felh env LANG="$NYELV" LC_ALL="$NYELV" xdg-user-dir DESKTOP 2>/dev/null || true)"
    if [[ -z $m || $m == "$CEL_HOME" || $m != "$CEL_HOME"/* || $m == *$'\n'* ]]; then m="$CEL_HOME/Asztal"; fi
    felh mkdir -p "$m" >&2
    echo "$m"
}
ikon_iras() {
    cat > "$1" <<EOF
[Desktop Entry]
Type=Application
Version=1.0
Name=BaninaPRO ellenőrzés
Comment=A szerver ellenőrzése, és az állapotjelentés elküldése (push-értesítés, e-mail)
Exec=sudo $JELENTO kezi
Icon=utilities-system-monitor
Terminal=true
Categories=System;Monitor;
EOF
    chown "$CEL_FELH:" "$1"
    chmod 755 "$1"
}

# A jelentő script (a beállításokkal együtt íródik ki)
jelento_iras() {
    {
        printf '#!/bin/bash\n# BaninaPRO állapotjelentés – a szerver_beallitas.sh írta, kézzel ne módosítsd (minden futása újraírja).\n'
        printf 'APP_KONTENER=%q\nDB_KONTENER=%q\nAPP_PORT=%q\nPMA_PORT=%q\nTITOK_MAPPA=%q\nCIMZETT=%q\nERTESITO=%q\nGEP_MENTES=%q\n' \
            "$APP_KONTENER" "$DB_KONTENER" "$APP_PORT" "$PMA_PORT" "$TITOK_MAPPA" "$JELENTES_CIMZETT" "$ERTESITO" "$GEP_MENTES"
        printf 'AUTO_FRISSITO_IDOZITOK=(%s)\n' "${AUTO_FRISSITO_IDOZITOK[*]}"
        cat <<'EOF'
# Ellenőrzi a szervert, és az eredményt elküldi push-értesítésként (rövid összefoglaló) és e-mailben (ha van feladó):
#   baninapro-jelentes napi             minden nap 03:30-kor (systemd-időzítő)
#   baninapro-jelentes kezi             az asztali ikonról – ugyanez, a képernyőn is
#   baninapro-jelentes proba            próba-jelentés (a telepítő küldi, amikor az e-mailt beállítja)
#   (a riasztásról az őrszem maga küld push-értesítést – a riasztas mód csak e-mailt küld)
#   baninapro-jelentes riasztas SZÖVEG  az őrszem értesítése (nem tudta helyreállítani / helyreállt)
# A levél mindig a CIMZETT-hez megy; a curl beépített SMTP-küldése viszi a beállított feladó-postafiókon keresztül
# (pl. a céges tárhely egy postafiókja) – külön program nem kell hozzá.
# A jelentés e-mail nélkül is elkészül: /var/log/baninapro-jelentes/ (60 napig marad meg).
set -u
export LC_ALL=C.UTF-8
mod="${1:-napi}" uzenet="${2:-}"
MAPPA=/var/log/baninapro-jelentes
EMAIL_FAJL="$TITOK_MAPPA/email"
mkdir -p "$MAPPA"
find "$MAPPA" -name '*.txt' -mtime +60 -delete 2>/dev/null
if [[ $mod == kezi ]]; then printf '\nBaninaPRO szerver – ellenőrzés folyamatban…\n\n'; fi

problemak=() allapotok=() kont=()
pr() { problemak+=("$*"); }
lan="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{ for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit } }')"
web() { if [[ $1 == 80 ]]; then echo "http://${lan:-$(hostname)}"; else echo "http://${lan:-$(hostname)}:$1"; fi; }

# --- a szolgáltatás ---
kod="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$APP_PORT/" || true)"
[[ $kod == 200 ]] || pr "A BaninaPRO oldal nem válaszol (HTTP ${kod:-–})"
api="$(curl -s --max-time 15 -H 'Content-Type: application/json' -d '{"action":"ping"}' "http://127.0.0.1:$APP_PORT/api.php" || true)"
if [[ $api == '{"ok":true'* ]]; then api="rendben"; else api="HIBÁS"; pr "Az API (api.php) nem válaszol rendesen"; fi
felh="$(timeout 30 docker exec "$APP_KONTENER" php -r 'require "/var/www/html/includes/db.php"; echo (int)db_val("SELECT COUNT(*) FROM felhasznalok");' 2>/dev/null || true)"
if ! [[ $felh =~ ^[0-9]+$ ]] || (( felh == 0 )); then pr "Az alkalmazás nem éri el az adatbázist"; felh="?"; fi
tablak="$(timeout 30 docker exec "$DB_KONTENER" sh -c 'mysql -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"' 2>/dev/null | tr -dc '0-9' || true)"
pkod="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://127.0.0.1:$PMA_PORT/" || true)"
[[ $pkod == 200 ]] || pr "A phpMyAdmin nem válaszol (HTTP ${pkod:-–})"

# --- a 03:00-s mentés ---
lista="$(timeout 120 docker exec "$APP_KONTENER" php -q cron_mentes.php lista 2>/dev/null || true)"
mai="$(grep -E "$(date -d '-3 hours' +%Y%m%d)_0[0-9]{3}" <<<"$lista" | tail -n 1 || true)"   # 03:00 előtt a tegnapi
db_mentes="$(grep -c '\.sql' <<<"$lista" || true)"
if [[ -n $mai ]]; then
    mentes="ELKÉSZÜLT – $(awk '{ print $NF " (" $3 " " $4 ")" }' <<<"$mai")"
elif [[ $mod == proba ]]; then
    mentes="ma még nem készült (a próba-jelentésnél ez nem hiba – a mentés minden nap 03:00-kor fut)"
else
    mentes="NEM KÉSZÜLT"
    pr "Ma éjjel nem készült adatbázis-mentés (03:00)"
fi
mentes_naplo="$(tail -n 1 /var/log/baninapro-mentes.log 2>/dev/null || true)"
# a mentések másolata a gép saját lemezén (a Dockeren kívül)
gep_mentesek=("$GEP_MENTES"/*.sql)
if [[ -e ${gep_mentesek[0]} ]]; then
    gep_mentes="${#gep_mentesek[@]} db, a legújabb: $(basename "${gep_mentesek[-1]}")"
else
    gep_mentes="még nincs"
    if [[ -n $mai ]]; then pr "A mentések másolata nincs meg a gép saját lemezén ($GEP_MENTES)"; fi
fi

# --- a gép ---
szabad_gb="$(df -Pk / | awk 'NR == 2 { print int($4 / 1024 / 1024) }')"
(( szabad_gb >= 5 )) || pr "Kevés a szabad hely a lemezen: $szabad_gb GB"
read -r fut _ < /proc/uptime
fut=${fut%.*}
for sz in docker containerd cron baninapro-orszem.timer baninapro-belepesfigyelo anydesk; do
    if systemctl is-active --quiet "$sz"; then allapotok+=("$sz: fut"); else allapotok+=("$sz: NEM FUT"); pr "Nem fut: $sz"; fi
done
for k in "$APP_KONTENER" "$DB_KONTENER" baninapro-phpmyadmin; do
    a="$(timeout 30 docker inspect -f '{{.State.Status}} – indult: {{.State.StartedAt}}, újraindítva: {{.RestartCount}}×' "$k" 2>/dev/null \
        | sed -E 's/T([0-9:]+)\.[0-9]+Z/ \1 UTC/')"
    [[ -n $a ]] || a="hiányzik"
    [[ $a == running* ]] || pr "A(z) $k konténer: $a"
    kont+=("$k: $a")
done
orszem="$(awk -v t="$(date -d '24 hours ago' '+%Y-%m-%d %H:%M:%S')" '($1 " " $2) >= t' /var/log/baninapro-orszem.log 2>/dev/null | tail -n 15 || true)"
auto_be=()
for e in "${AUTO_FRISSITO_IDOZITOK[@]}"; do
    a="$(systemctl is-enabled "$e" 2>/dev/null)"
    if [[ -n $a && $a != masked && $a != masked-runtime && $a != not-found ]]; then auto_be+=("$e"); fi
done
if (( ${#auto_be[@]} )); then
    auto="BE: ${auto_be[*]}"
    pr "Az automatikus frissítés be van kapcsolva (${auto_be[*]}) – az őrszem kikapcsolja"
else
    auto="ki – a rendszer csak a szerver_beallitas.sh kézi futtatásakor frissül"
fi
anydesk_id="$(timeout 15 anydesk --get-id 2>/dev/null | tr -dc '0-9' || true)"

if (( ${#problemak[@]} )); then allapot="FIGYELEM – ${#problemak[@]} probléma"; else allapot="MINDEN RENDBEN"; fi
fajl="$MAPPA/$(date +%Y-%m-%d)$([[ $mod == napi ]] || echo "-$(date +%H%M)-$mod").txt"
{
    echo "BaninaPRO szerver – állapotjelentés – $(date '+%Y-%m-%d %H:%M')"
    echo "ÁLLAPOT: $allapot"
    if [[ -n $uzenet ]]; then echo; echo "$uzenet"; fi
    if (( ${#problemak[@]} )); then echo; echo "Problémák:"; printf '  - %s\n' "${problemak[@]}"; fi
    echo
    echo "Szolgáltatás (a belső hálózatról)"
    echo "  BaninaPRO:      $(web "$APP_PORT")  – HTTP ${kod:-–}, API: $api"
    echo "  phpMyAdmin:     $(web "$PMA_PORT")  – HTTP ${pkod:-–}"
    echo "  Adatbázis:      ${tablak:-?} tábla, $felh felhasználó"
    echo
    echo "Adatbázis-mentés (minden nap 03:00)"
    echo "  Ma éjjel:       $mentes"
    echo "  Mentések:       ${db_mentes:-0} db"
    echo "  A gép lemezén:  $gep_mentes ($GEP_MENTES)"
    if [[ -n $mentes_naplo ]]; then echo "  Mentési napló:  $mentes_naplo"; fi
    echo
    echo "Gép"
    echo "  Név / IP:       $(hostname) / ${lan:-ismeretlen}"
    echo "  Rendszer:       $(. /etc/os-release && echo "$PRETTY_NAME"), kernel $(uname -r)"
    echo "  Utolsó indítás: $(uptime -s)  (azóta: $(( fut / 86400 )) nap $(( fut % 86400 / 3600 )) óra $(( fut % 3600 / 60 )) perc)"
    echo "  Tárhely (/):    $(df -hP / | awk 'NR == 2 { print $4 " szabad / " $2 " (" $5 " foglalt)" }')"
    echo "  Memória:        $(free -h | awk '/^Mem:/ { print $3 " használt / " $2 }')"
    echo "  Terhelés:       $(cut -d' ' -f1-3 /proc/loadavg)"
    echo "  Automatikus frissítés: $auto"
    echo "  Újraindítás kell (frissítés miatt): $([[ -f /var/run/reboot-required ]] && echo igen || echo nem)"
    echo
    echo "Szolgáltatások"
    printf '  %s\n' "${allapotok[@]}"
    echo "  AnyDesk ID:     ${anydesk_id:-–}"
    echo
    echo "Konténerek"
    printf '  %s\n' "${kont[@]}"
    echo
    echo "Őrszem – az elmúlt 24 óra"
    if [[ -n $orszem ]]; then sed 's/^/  /' <<<"$orszem"; else echo "  Nem kellett beavatkoznia."; fi
} > "$fajl"
chmod 640 "$fajl"

# a levél: a curl beépített SMTP-küldése (587: STARTTLS, 465: SSL), a belépési adat a curl beállítófájljából
# (a jelszó nem látszik a folyamatlistában)
kuld() {   # $1 = tárgy, $2 = a jelentés fájlja; 2 = nincs feladó-postafiók beállítva
    local felado host port url level rc hiteles=()
    felado="$(sed -n 's/^EMAIL_FELADO=//p; s/^EMAIL_CIM=//p' "$EMAIL_FAJL" 2>/dev/null | head -n 1)"
    host="$(sed -n 's/^SMTP_HOST=//p' "$EMAIL_FAJL" 2>/dev/null)"
    port="$(sed -n 's/^SMTP_PORT=//p' "$EMAIL_FAJL" 2>/dev/null)"
    host="${host:-smtp.gmail.com}" port="${port:-587}"
    if [[ -s $TITOK_MAPPA/smtp.curl ]]; then hiteles=(-K "$TITOK_MAPPA/smtp.curl")
    elif [[ -s $TITOK_MAPPA/smtp.netrc ]]; then hiteles=(--netrc-file "$TITOK_MAPPA/smtp.netrc")   # régebbi beállítás
    fi
    [[ -n $felado && ${#hiteles[@]} -gt 0 ]] || return 2
    if [[ $port == 465 ]]; then url="smtps://$host:$port"; else url="smtp://$host:$port"; fi
    level="$(mktemp)"
    {
        printf 'From: BaninaPRO szerver <%s>\r\n' "$felado"
        printf 'To: <%s>\r\n' "$CIMZETT"
        printf 'Subject: =?UTF-8?B?%s?=\r\n' "$(printf '%s' "$1" | base64 -w0)"
        printf 'Date: %s\r\n' "$(LC_ALL=C date -R)"
        printf 'Message-ID: <%s.%s@%s>\r\n' "$(date +%s)" "$$" "$(hostname)"
        printf 'MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n'
        sed 's/$/\r/' "$2"
    } > "$level"
    curl -sS --max-time 60 --url "$url" --ssl-reqd "${hiteles[@]}" \
        --mail-from "$felado" --mail-rcpt "$CIMZETT" --upload-file "$level"
    rc=$?
    rm -f "$level"
    return "$rc"
}
case $mod in
    riasztas) targy="BaninaPRO szerver – ÉRTESÍTÉS: $uzenet" ;;
    proba)    targy="BaninaPRO szerver – próba-jelentés – $allapot" ;;
    *)        targy="BaninaPRO szerver – $(date +%Y-%m-%d) – $allapot" ;;
esac
targy="${targy:0:150}"
if [[ $mod == kezi ]]; then cat "$fajl"; fi
if kuld "$targy" "$fajl"; then
    eredmeny="Az e-mail elküldve: $CIMZETT"
    rc=0
elif (( $? == 2 )); then
    eredmeny="Nincs feladó-postafiók beállítva, e-mail nem ment – a jelentés itt van: $fajl"
    rc=0
else
    eredmeny="Az e-mail küldése NEM sikerült (a feladó-postafiók címe, jelszava, SMTP-szervere? internet?) – a jelentés itt van: $fajl"
    rc=1
fi
# push-értesítés (ntfy): rövid összefoglaló – a teljes jelentés a fájlban (és e-mailben, ha be van állítva).
# A riasztásokról az őrszem maga értesít, ezért annál csak e-mail megy.
if [[ $mod != riasztas && -x $ERTESITO ]]; then
    case $mod in
        napi) pcim="Napi jelentés – $allapot" ;;
        kezi) pcim="Kézi ellenőrzés – $allapot" ;;
        *)    pcim="Próba-jelentés – $allapot" ;;
    esac
    if (( ${#problemak[@]} )); then pp=4 pt=warning; else pp=3 pt=clipboard; fi
    rovid="$(
        if (( ${#problemak[@]} )); then printf '• %s\n' "${problemak[@]}"; fi
        printf 'BaninaPRO: HTTP %s, API %s · %s tábla, %s felhasználó\n' "${kod:-–}" "$api" "${tablak:-?}" "$felh"
        printf 'Mentés (03:00): %s\n' "$mentes"
        printf 'Tárhely: %s szabad · utolsó indítás: %s\n' "$(df -hP / | awk 'NR == 2 { print $4 }')" "$(uptime -s)"
        if (( rc )); then printf 'Az e-mail küldése NEM sikerült (feladó-postafiók / internet?)\n'; fi
    )"
    if "$ERTESITO" -p "$pp" -t "$pt" "$pcim" "$rovid" >/dev/null 2>&1; then
        eredmeny+=" · push-értesítés elküldve"
    else
        eredmeny+=" · push-értesítés sorba állt (nincs internet?)"
    fi
fi
echo "$(date '+%Y-%m-%d %H:%M:%S')  $mod: $allapot – $eredmeny" >> "$MAPPA/kuldesek.log"
if [[ $mod == kezi ]]; then
    printf '\n%s\n' "$eredmeny"
    if [[ -t 0 ]]; then read -r -p $'\nNyomj Entert az ablak bezárásához… ' _ || true; fi
fi
exit "$rc"
EOF
    } > "$JELENTO.uj"
    chmod 750 "$JELENTO.uj"
    mv -f "$JELENTO.uj" "$JELENTO"
}

# háttérben fut: a főoldal és az adatbázis állapota fájlokba (közben pöröghet a folyamatjelző)
ellenorzo_lekeres() {
    curl -s -o "$TMPD/oldal.html" -w '%{http_code}' --max-time 10 http://127.0.0.1/ > "$TMPD/kod" 2>/dev/null || true
    timeout 60 docker exec "$APP_KONTENER" php -r \
        'require "/var/www/html/includes/db.php"; echo (int)db_val("SELECT COUNT(*) FROM felhasznalok");' \
        > "$TMPD/felh" 2>&1 || true
}

lepes_ellenorzes() {
    local oldal="$TMPD/oldal.html" kod="" felhasznalok="" api="" i k s allapot lan pma_db v_oldal v_kod
    # kevés a tárhely: a telepítéshez letöltött csomagfájlok törlése (a telepített programok maradnak)
    apt-get clean >/dev/null 2>&1 || true
    ok "Letöltött csomagfájlok törölve – szabad hely: $(szabad_gb) GB"
    AKT_MUVELET="várakozás, amíg a BaninaPRO teljesen elindul"
    for i in $(seq 1 60); do
        fut ellenorzo_lekeres || true
        kod="$(cat "$TMPD/kod" 2>/dev/null || true)"
        felhasznalok="$(cat "$TMPD/felh" 2>/dev/null || true)"
        if [[ $kod == 200 && $felhasznalok =~ ^[0-9]+$ ]] && (( felhasznalok > 0 )); then break; fi
        # ha sokáig nem jó: javítási kör – konténerek (újra)indítása, adatbázis rendbetétele
        if (( i == 20 || i == 40 )); then
            info "A BaninaPRO még nem válaszol rendesen (HTTP ${kod:-?}) – újraindítom, és ellenőrzöm az adatbázist…"
            fut dc up -d --remove-orphans || true
            fut timeout 180 docker restart "$APP_KONTENER" || true
            adatbazis_rendbe || true
        fi
        AKT_MUVELET="várakozás, amíg a BaninaPRO teljesen elindul ($i. próba)"
        varj 3
    done
    AKT_MUVELET=""

    if [[ $kod != 200 ]]; then
        kontener_naplok
        hiba "A http://localhost nem válaszol (HTTP $kod)."
    fi
    grep -q '<title>BaninaPRO</title>' "$oldal" || hiba "A http://localhost nem a BaninaPRO oldalát adja."
    if ! grep -q '"hiba":null' "$oldal"; then
        kontener_naplok
        hiba "Az oldal betölt, de hibát jelez: $(grep -o '"hiba":"[^"]*"' "$oldal" || true)"
    fi
    if grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' "$oldal"; then
        figy "PHP-figyelmeztetés az oldalon: $(grep -oE '<b>(Warning|Notice|Deprecated|Fatal error)</b>:[^<]*' "$oldal" | head -n 1 || true)"
    fi
    v_oldal="$(grep -o '"verzio":"[^"]*"' "$oldal" | cut -d'"' -f4 || true)"
    v_kod="$(sed -n "s/^define('APP_VERSION', *'\([^']*\)');.*/\1/p" "$REPO/docker/config.php" 2>/dev/null | head -n 1 || true)"
    if [[ -n $v_kod && $v_oldal != "$v_kod" ]]; then
        # a futó alkalmazás még a régi beállítófájlt látja: újraindítás
        info "Az oldal még a(z) ${v_oldal:-?} változatot mutatja (a kód: $v_kod) – az alkalmazást újraindítom…"
        fut timeout 180 docker restart "$APP_KONTENER" || true
        varj 5
        fut ellenorzo_lekeres || true
        v_oldal="$(grep -o '"verzio":"[^"]*"' "$oldal" | cut -d'"' -f4 || true)"
        if [[ $v_oldal != "$v_kod" ]]; then figy "Az oldal a(z) ${v_oldal:-?} változatot mutatja, a kód viszont $v_kod."; fi
    fi
    ok "Főoldal: http://localhost → HTTP 200, BaninaPRO $v_oldal"

    # a szerver beállítófájlja (benne az adatbázis jelszavai) a webről ne legyen olvasható
    kod="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1/docker-compose.override.yml || true)"
    if [[ $kod == 200 ]]; then
        chmod 600 "$REPO/docker-compose.override.yml" || true
        figy "A docker-compose.override.yml a webről olvasható volt (HTTP 200) – a jogosultságát visszaállítottam (600)."
    else
        ok "A szerver beállítófájljai a webről nem olvashatók (HTTP $kod)"
    fi

    if [[ $felhasznalok =~ ^[0-9]+$ ]] && (( felhasznalok > 0 )); then
        ok "Adatbázis: az alkalmazás eléri, a táblák megvannak ($felhasznalok felhasználó)"
    else
        kontener_naplok
        hiba "Az alkalmazás nem éri el az adatbázist, vagy hiányoznak a táblák: ${felhasznalok:0:400}"
    fi

    api="$(curl -s --max-time 10 -H 'Content-Type: application/json' -d '{"action":"ping"}' http://127.0.0.1/api.php || true)"
    if [[ $api == '{"ok":true'* ]]; then ok "API (api.php) rendben válaszol"
    else hiba "Az API hibás választ ad: ${api:0:300}"; fi

    kod="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1/assets/app.js || true)"
    if [[ $kod == 200 ]]; then ok "Statikus fájlok (assets/app.js) rendben"
    else hiba "Az assets/app.js nem tölthető be (HTTP $kod)."; fi

    kod="$(curl -s -o "$TMPD/pma.html" -w '%{http_code}' --max-time 10 "http://127.0.0.1:$PMA_PORT/" || true)"
    if (( ! PMA_PORT_KI )); then
        info "A phpMyAdmin most nem érhető el kívülről (a $PMA_PORT-es portot egy másik program foglalja)."
    elif [[ $kod != 200 ]]; then
        figy "A phpMyAdmin nem válaszol (HTTP $kod)."
    elif grep -q 'pma_username' "$TMPD/pma.html"; then
        ok "phpMyAdmin: jelszót kér (nincs automatikus belépés)"
    else
        figy "A phpMyAdmin jelszó nélkül beenged – ellenőrizd a docker-compose.override.yml-t!"
    fi
    pma_db="$(db_szam "SELECT COUNT(*) FROM mysql.user WHERE user = '$PMA_FELH'")"
    if pma_kezdojelszo_el; then
        ok "phpMyAdmin-belépés: $PMA_FELH / $PMA_KEZDO_JELSZO (kezdőjelszó – változtasd meg!)"
    elif (( ${pma_db:-0} > 0 )); then
        ok "phpMyAdmin-belépés: $PMA_FELH (a saját, már megváltoztatott jelszavaddal)"
    else
        figy "A phpMyAdmin-felhasználó ($PMA_FELH) nem jött létre."
    fi

    # a belső hálózatról is: a gép hálózati címén (a portok minden hálózati csatolón figyelnek)
    lan="$(lan_ip)"
    if [[ -n $lan ]]; then
        kod="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://$lan:$APP_PORT/" || true)"
        if [[ $kod == 200 ]]; then ok "A belső hálózatról is elérhető: http://$lan"
        else figy "A gép hálózati címén (http://$lan) a BaninaPRO nem válaszol (HTTP $kod)."; fi
    else
        figy "A gépnek nincs hálózati IP-címe – a belső hálózatról most nem érhető el."
    fi

    for k in "$APP_KONTENER" "$DB_KONTENER" "$PMA_KONTENER"; do
        allapot="$(timeout 30 docker inspect -f '{{.State.Status}}/{{.HostConfig.RestartPolicy.Name}}' "$k" 2>/dev/null || echo hiányzik)"
        if [[ $allapot == running/unless-stopped || $allapot == running/always ]]; then
            ok "$k fut, a gép újraindítása után magától elindul"
        else
            figy "$k állapota: $allapot"
        fi
    done
    for s in docker containerd cron anydesk lightdm baninapro-orszem.timer baninapro-jelentes.timer \
        baninapro-indulas.service baninapro-leallas.service baninapro-belepesfigyelo.service; do
        if systemctl is-enabled --quiet "$s" 2>/dev/null; then ok "$s: a géppel együtt indul"
        else figy "$s: nem indul automatikusan"; fi
    done
    if [[ -f /etc/cron.d/baninapro ]] && systemctl is-active --quiet cron; then
        ok "Az éjszakai mentés ütemezve (03:00, /etc/cron.d/baninapro)"
    else
        figy "Az éjszakai mentés ütemezése nem él (/etc/cron.d/baninapro, cron)."
    fi
    if systemctl is-active --quiet baninapro-belepesfigyelo.service; then ok "A belépésfigyelő fut (be- és kilépésekről értesít)"
    else figy "A belépésfigyelő (baninapro-belepesfigyelo) nem fut."; fi
    # a push-értesítések: a sorban álló (még el nem küldött) értesítések elküldése – ha nem megy, nincs internet
    fut "$ERTESITO" --sorbol || true
    s="$(find /var/spool/baninapro-ertesites -name '*.json' 2>/dev/null | wc -l)"
    if (( s == 0 )); then ok "Push-értesítések: minden értesítés elment ($NTFY_SZERVER)"
    else figy "$s push-értesítés még sorban áll ($NTFY_SZERVER nem érhető el?) – az őrszem 2 percenként újrapróbálja."; fi

    # az e-mail most lett beállítva: próba-jelentés – ha nem megy el, a beállítást törli, és a következő futás újra kérdez
    if (( EMAIL_UJ )); then
        AKT_MUVELET="próba-jelentés küldése e-mailben"
        if fut "$JELENTO" proba; then
            ok "Próba-jelentés elküldve: $JELENTES_CIMZETT (feladó: $JELENTES_FELADO)"
        else
            rm -f "$TITOK_MAPPA/email" "$TITOK_MAPPA/smtp.curl" "$TITOK_MAPPA/smtp.netrc"
            JELENTES_FELADO=""
            figy "A próba-e-mail nem ment el (a feladó-postafiók címe, jelszava vagy SMTP-szervere hibás?) – a beállítást töröltem, a script következő futása újra megkérdezi."
        fi
        AKT_MUVELET=""
    fi
}

# =============================================================================
#  Összegzés – a végén, és akkor is, ha a script egy lépésnél hibával megállt
# =============================================================================
# összegzés-sor: a képernyőre ($1 = szín, lehet üres), a naplóba és az összegzés-fájlba
osz() {
    local szin=$1
    shift
    torol
    printf '%s%s%s\n' "$szin" "$*" "${szin:+$C_N}" >&3
    printf '%s\n' "$*"
    OSSZ_SOROK+=("$*")
}

# egy konténerport címe a gépen a Docker szerint (pl. 127.0.0.1:8081); ha több is van, a $3 porton lévőt adja
port_cim() {
    local cimek
    cimek="$(timeout 30 docker port "$1" "$2/tcp" 2>/dev/null | grep -v '^\[' || true)"
    if [[ -n ${3:-} ]] && grep -q ":$3\$" <<<"$cimek"; then cimek="$(grep ":$3\$" <<<"$cimek")"; fi
    head -n 1 <<<"$cimek"
}
# a gép IP-címe a helyi hálózaton (amelyiken a kifelé menő forgalom megy)
lan_ip() {
    ip -4 route get 1.1.1.1 2>/dev/null | awk '{ for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit } }' || true
}
# böngészőcím a Docker szerinti kötésből ($1, pl. 0.0.0.0:8081); a gép címe ($2) kerül a 0.0.0.0 / 127.0.0.1 helyére
web_cim() {
    local hoszt=${1%:*} port=${1##*:}
    if [[ $hoszt == 0.0.0.0 || $hoszt == 127.0.0.1 ]]; then hoszt=$2; fi
    if [[ $port == 80 ]]; then echo "http://$hoszt"; else echo "http://$hoszt:$port"; fi
}

osszegzes() {   # $1 = 0: minden lépés lefutott; különben a kilépési kód (a script hibával állt le)
    local rc=${1:-0} i e fv suly nev jel szin megj id="" v="" f app pma mysql lan mdns
    OSSZEGZES_KESZ=1
    AKT_LEPES="összegzés" AKT_ROVID="összegzés"
    if (( rc == 0 )); then
        if command -v anydesk >/dev/null; then
            AKT_MUVELET="AnyDesk azonosító lekérése"
            for i in 1 2 3; do
                fut sh -c 'timeout 15 anydesk --get-id > "$1" 2>/dev/null' _ "$TMPD/anydesk_id" || true
                id="$(tr -dc '0-9' < "$TMPD/anydesk_id" 2>/dev/null || true)"
                if [[ -n $id && $id != 0 ]]; then break; fi
                varj 5
            done
            AKT_MUVELET=""
        fi
        kesz_sav
    fi

    cim "ÖSSZEGZÉS"
    i=0
    for e in "${LEPESEK[@]}"; do
        i=$(( i + 1 ))
        IFS='|' read -r fv suly nev <<<"$e"
        case ${LEPES_ALLAPOT[i]:-} in
            ok)     jel=$S_OK   szin=$C_ZOLD  megj="" ;;
            figy:*) jel=$S_FIGY szin=$C_SARGA megj=" – ${LEPES_ALLAPOT[i]#figy:} figyelmeztetés" ;;
            *)  if (( i == AKT_I )); then jel=$S_HIBA szin=$C_PIROS megj=" – HIBA"
                else jel=$S_PONT szin=$C_HALV megj=" – nem futott le"; fi ;;
        esac
        osz "$szin" "$(printf '  %s %2d. %s%s' "$jel" "$i" "$nev" "$megj")"
    done
    osz "" ""
    if (( rc )); then
        osz "$C_PIROS" "  EREDMÉNY: $S_HIBA NEM SIKERÜLT – ${HIBA_UZENET:-hiba történt}"
        osz "" "  A hiba javítása után a script nyugodtan újrafuttatható – ami már kész, azt csak ellenőrzi."
    elif (( ${#FIGYELMEZTETESEK[@]} )); then
        osz "$C_ZOLD" "  EREDMÉNY: $S_OK SIKERES – a BaninaPRO szerver működik (${#FIGYELMEZTETESEK[@]} figyelmeztetéssel, lásd lent)"
    else
        osz "$C_ZOLD" "  EREDMÉNY: $S_OK MINDEN SIKERES – a BaninaPRO szerver működik"
    fi

    # a címek a Docker szerint – így mindig azt mutatja, ahol a konténerek valóban elérhetők
    app="$(port_cim "$APP_KONTENER" 80 "$APP_PORT")"
    pma="$(port_cim baninapro-phpmyadmin 80 "$PMA_PORT")"
    mysql="$(port_cim "$DB_KONTENER" 3306)"
    lan="$(lan_ip)"
    mdns=""
    if systemctl is-active --quiet avahi-daemon 2>/dev/null; then mdns="$GEPNEV.local"; fi
    osz "" ""
    if [[ -z $app ]]; then
        osz "" "  Elérés: a BaninaPRO még nem fut."
    elif [[ $app == 127.0.0.1:* ]]; then
        osz "" "  Elérés (csak magáról a szerverről):  BaninaPRO: $(web_cim "$app" localhost)${pma:+   phpMyAdmin: $(web_cim "$pma" localhost)}"
    else
        osz "" "  Elérés a belső hálózat bármely gépéről (böngészőben):"
        osz "" "    BaninaPRO (az oldal):  $(web_cim "$app" "${lan:-<a szerver IP-címe>}")${mdns:+   vagy  $(web_cim "$app" "$mdns")}"
        if [[ -n $pma ]]; then
            osz "" "    phpMyAdmin:            $(web_cim "$pma" "${lan:-<a szerver IP-címe>}")${mdns:+   vagy  $(web_cim "$pma" "$mdns")}"
            if pma_kezdojelszo_el; then
                osz "" "                           belépés: $PMA_FELH / $PMA_KEZDO_JELSZO  → változtasd meg (phpMyAdmin: Jelszó módosítása)!"
            else
                osz "" "                           belépés: $PMA_FELH / a saját jelszavad"
            fi
        fi
        osz "" "  Magán a szerveren:       http://localhost   és   http://localhost:$PMA_PORT"
    fi
    osz "" "  MySQL (csak a szerveren, programokból): ${mysql:-még nem fut} – adatbázis: baninapr_DATA"
    if (( ELSO_INDITAS )) && [[ -z $VISSZAALLITVA ]]; then
        osz "" "  Első belépés a BaninaPRO-ba: $ADMIN_KEZDO  → belépés után azonnal változtasd meg!"
    fi
    if [[ -n $VISSZAALLITVA ]]; then
        osz "$C_SARGA" "  Az adatbázis üres volt – visszaállítva ebből a mentésből: $VISSZAALLITVA (a régi felhasználókkal lehet belépni)"
    fi
    if [[ -n $SERULT_DB_MASOLAT ]]; then
        osz "$C_SARGA" "  A nem induló adatbázis régi adatfájljai félretéve: $SERULT_DB_MASOLAT"
    fi
    osz "" "  Adatbázis-mentés: minden nap 03:00 – a Docker-kötetben, és a legutóbbi $GEP_MENTES_DB a gép lemezén is: $GEP_MENTES"
    osz "" "  A gép IP-címe: ${lan:-ismeretlen} – SSH: ssh $CEL_FELH@${lan:-$GEPNEV}"
    osz "" "  (Tipp: a routerben foglald le ezt az IP-címet a szervernek – DHCP-foglalás –, hogy ne változzon.)"
    if [[ -z $NTFY_CSATORNA ]]; then NTFY_CSATORNA="$(sed -n 's/^NTFY_CSATORNA=//p' "$TITOK_MAPPA/ntfy" 2>/dev/null || true)"; fi
    ntfy_szerver_beolvas
    if [[ -n $NTFY_CSATORNA ]]; then
        osz "" "  Értesítések a telefonra: ntfy alkalmazás → + (Subscribe to topic) → Topic: $NTFY_CSATORNA"
        osz "" "                 (böngészőben: $NTFY_SZERVER/$NTFY_CSATORNA – leírás az asztalon: BaninaPRO-ertesitesek.txt)"
    fi
    if [[ -n $JELENTES_FELADO ]]; then
        osz "" "  Napi jelentés: minden nap $JELENTES_IDO-kor push-értesítésként és e-mailben → $JELENTES_CIMZETT (feladó: $JELENTES_FELADO)"
    else
        osz "" "  Napi jelentés: minden nap $JELENTES_IDO-kor push-értesítésként (e-mailben is: sudo bash $(basename "$SCRIPT") --email)"
    fi
    osz "" "  Az adatbázis root-jelszava (ha valaha kellene): sudo cat $TITOK_MAPPA/titkok"
    osz "" "  Kézi ellenőrzés: az asztalon a „BaninaPRO ellenőrzés” ikon (vagy: sudo $JELENTO kezi)"
    osz "" "  Őrszem:        2 percenként ellenőriz, és ha kell, helyreállít (napló: /var/log/baninapro-orszem.log)"
    osz "" "  AnyDesk ID:    ${id:-(újraindítás után: sudo anydesk --get-id)}"
    osz "" "  Frissítés, és bármilyen hiba után javítás:  cd \"$SCRIPT_DIR\" && sudo bash $(basename "$SCRIPT")"
    osz "" "  Napló:         $NAPLO"

    if (( ${#FIGYELMEZTETESEK[@]} )); then
        osz "" ""
        osz "$C_SARGA" "  Figyelmeztetések (${#FIGYELMEZTETESEK[@]}):"
        for f in "${FIGYELMEZTETESEK[@]}"; do osz "" "   - $f"; done
    fi

    # az összegzés fájlba is: a felhasználó mappájában, sudo nélkül is olvasható
    if [[ -n $CEL_HOME && -d $CEL_HOME ]]; then
        # (a phpMyAdmin jelszava is benne van, ezért csak a felhasználó olvashatja)
        { printf 'BaninaPRO szerver – összegzés (%s)\n\n' "$(date '+%Y-%m-%d %H:%M')"; printf '%s\n' "${OSSZ_SOROK[@]}"; } \
            > "$CEL_HOME/BaninaPRO-osszegzes.txt" 2>/dev/null \
            && chown "$CEL_FELH:" "$CEL_HOME/BaninaPRO-osszegzes.txt" 2>/dev/null \
            && chmod 600 "$CEL_HOME/BaninaPRO-osszegzes.txt" \
            && ki "" && ki "  Az összegzés elmentve: $CEL_HOME/BaninaPRO-osszegzes.txt"
    fi
    vegeredmeny_ertesites "$rc"
    if (( rc )); then return 0; fi

    if [[ -f /var/run/reboot-required ]] || ! systemctl is-active --quiet lightdm; then UJRAINDITAS=1; fi
    if (( UJRAINDITAS )); then
        ki ""
        ki "  Újraindítás kell: utána indul az asztal, a magyar nyelv és az automatikus belépés."
        if [[ -t 0 ]]; then
            printf '  Újraindítsam most? [I/n] (60 mp múlva magától igen): ' >&3
            read -r -t 60 v || true
            printf '\n' >&3
            if [[ ! $v =~ ^[nN] ]]; then
                info "Újraindítás…"
                sync
                systemctl reboot
                exit 0
            fi
        fi
        info "Indítsd újra, amikor alkalmas: sudo reboot"
    fi
}

# a telepítés / frissítés végeredménye push-értesítésként is (ha az értesítő már be van állítva)
vegeredmeny_ertesites() {   # $1 = 0: sikeres; különben a kilépési kód
    local lan verzio nev="" f uzenet
    [[ -x $ERTESITO && -s $TITOK_MAPPA/ntfy ]] || return 0
    if (( $1 )); then
        if (( AKT_I > 0 )); then IFS='|' read -r _ _ nev <<<"${LEPESEK[AKT_I - 1]}"; fi
        "$ERTESITO" -p 4 -t x "Telepítés / frissítés: HIBA" \
            "A szerver_beallitas.sh megállt${nev:+ ($AKT_I. lépés: $nev)}: ${HIBA_UZENET%%$'\n'*}. A hiba javítása után újrafuttatható. Napló: $NAPLO" \
            >/dev/null 2>&1 || true
        return 0
    fi
    lan="$(lan_ip)"
    verzio="$(grep -o '"verzio":"[^"]*"' "$TMPD/oldal.html" 2>/dev/null | cut -d'"' -f4 || true)"
    uzenet="BaninaPRO ${verzio:+$verzio }fut: http://${lan:-$GEPNEV.local} · phpMyAdmin: http://${lan:-$GEPNEV.local}:$PMA_PORT"
    if (( ${#FIGYELMEZTETESEK[@]} )); then
        uzenet+=$'\n'"Figyelmeztetések (${#FIGYELMEZTETESEK[@]}):"
        for f in "${FIGYELMEZTETESEK[@]}"; do uzenet+=$'\n'"• $f"; done
        "$ERTESITO" -p 3 -t warning "Telepítés / frissítés kész – ${#FIGYELMEZTETESEK[@]} figyelmeztetéssel" "$uzenet" >/dev/null 2>&1 || true
    else
        "$ERTESITO" -p 3 -t white_check_mark "Telepítés / frissítés kész – minden rendben" "$uzenet" >/dev/null 2>&1 || true
    fi
}

# kilépéskor: ha a script egy lépés közben hibával állt le, akkor is legyen összegzés
kilepeskor() {
    local rc=$?
    set +eu
    trap - ERR
    torol
    if (( rc != 0 && ! OSSZEGZES_KESZ && AKT_I > 0 )); then osszegzes "$rc"; fi
    rm -rf "$TMPD"
}

main() {
    if [[ $EUID -ne 0 ]]; then
        echo "Rendszergazdaként kell futtatni:  sudo bash $(basename "$SCRIPT")"
        exit 1
    fi
    local a
    ARGOK=("$@")
    for a in "$@"; do
        case $a in
            --email) EMAIL_KERDES=1 ;;                     # a feladó-postafiók (újra)beállítása
            --nincs-visszaallitas) NINCS_VISSZAALLITAS=1 ;; # üres adatbázisnál se állítson vissza mentést
            *) ;;
        esac
    done
    # egyszerre csak egy telepítő fusson (egy második példány összeakadna az elsővel)
    exec 7>/run/baninapro-telepito.lock
    if ! flock -n 7; then
        echo "A BaninaPRO telepítője már fut (egy másik ablakban vagy háttérben) – várd meg, amíg befejezi."
        exit 1
    fi
    naplo_inditas
    kepernyo_beallitas
    TMPD="$(mktemp -d)"
    APT_ALLAPOT="$TMPD/apt_allapot"
    trap kilepeskor EXIT
    LEPES_KEZDET=$SECONDS
    printf '\n\n######## %s – futás indul ########\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    printf '\n%sBaninaPRO szerver – telepítés és frissítés%s  (%s)\n' "$C_F" "$C_N" "$(date '+%Y-%m-%d %H:%M')" >&3
    # Amíg a telepítő dolgozik, az őrszem ne avatkozzon be (ugyanezt a zárat fogja). Ha épp most dolgozik – egy
    # helyreállítási köre akár negyedóráig is tarthat –, nem várja meg, hanem leállítja: a telepítő úgyis mindent rendbe tesz.
    exec 8>/run/baninapro-orszem.lock
    if ! flock -n 8; then
        info "Az őrszem épp helyreállít – leállítom, a telepítő maga tesz rendbe mindent…"
        systemctl stop baninapro-orszem.service >/dev/null 2>&1 || true
        systemctl reset-failed baninapro-orszem.service >/dev/null 2>&1 || true
        flock -w 120 8 || true
    fi

    elofeltetelek
    frissites_elokeszites "$@"
    kerdesek
    lepesek_listaja
    futtat_lepesek
    osszegzes 0
}

# a teljes script beolvasása után indul – így a futás közbeni git pull sem zavarja meg
main "$@"
