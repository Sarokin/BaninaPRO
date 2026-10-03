#!/usr/bin/env bash
# =============================================================================
#  BaninaPRO – szerver telepítő és frissítő
#
#  Egy friss (szűz) Ubuntu Serverből egyetlen futtatással kész BaninaPRO szerver lesz:
#  magyar nyelv és billentyűzet, LXQt asztal automatikus belépéssel, AnyDesk, Docker Engine,
#  SSH, és a BaninaPRO a szerveren a http://localhost címen (80-as port), kész adatbázissal.
#
#  ELŐKÉSZÜLET (egyszer, kézzel):
#    1. Ubuntu Server telepítése – felhasználó: baninapro, gépnév: baninapro
#    2. GitHub-hozzáférés a gépen (SSH-kulcs), a kettő közül az egyik:
#       a) a régi szerver kulcsát átmásolod (a GitHubon már fent van, nem kell újra felvenni):
#            install -d -m 700 ~/.ssh && scp baninapro@REGI_GEP:.ssh/id_ed25519* ~/.ssh/
#       b) új kulcs:  ssh-keygen -t ed25519 , majd a ~/.ssh/id_ed25519.pub tartalmát
#          felveszed: GitHub → Settings → SSH and GPG keys → New SSH key
#    3. git clone git@github.com:Sarokin/BaninaPRO.git ~/BaninaPRO
#    (Az adatbázis-séma – sql/schema.sql – a repóval együtt jön, külön nem kell másolni.)
#
#  FUTTATÁS – első telepítés és később minden frissítés is ugyanez:
#    cd ~/BaninaPRO/"SERVER SETUP AND UPDATE"
#    sudo bash szerver_beallitas.sh
#
#  Újrafuttatva frissít: adatbázis-mentés → git pull → rendszerfrissítés → konténerek újraépítése.
#  Bármikor nyugodtan újrafuttatható: ami már kész, azt csak ellenőrzi.
#  Önjavító: ha valami elakad – félbemaradt csomagtelepítés, hiányzó csomag vagy bővítmény,
#  hálózati hiba, rossz rendszeridő, hibás külső csomagtároló, foglalt 80-as port, hiányzó
#  adatbázis-táblák –, a script megpróbálja magától rendbe tenni, és csak akkor áll meg, ha ez sem megy.
#  A képernyőn folyamatjelző mutatja, hol tart; a parancsok teljes kimenete a naplóba kerül:
#  /var/log/baninapro-szerver.log  (hibánál a napló utolsó sorai a képernyőn is megjelennek).
# =============================================================================
set -Eeuo pipefail
umask 022

# ---- Beállítások ------------------------------------------------------------
GEPNEV="baninapro"
IDOZONA="Europe/Budapest"
NYELV="hu_HU.UTF-8"
APP_PORT="127.0.0.1:80"              # csak a szerverről érhető el: http://localhost
MENTES_CRON="0 3 * * *"              # éjszakai adatbázis-mentés, mint az éles cron
# a GitHub-fiókban regisztrált kulcs, amivel a szerver a repót húzza (a privát kulcs NINCS a repóban!)
GITHUB_KULCS="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKFsn3fg7hguHnecoXSTovFR7ZMDm8Frmt0/rbr72EId baninapro@baninapro"
# tartalék, ha az AnyDesk csomagtárolója nem működne (ha ez a változat már nincs fent, a legfrissebbet keresi meg)
ANYDESK_DEB="https://deb.anydesk.com/pool/main/a/anydesk/anydesk_8.1.0_amd64.deb"
NAPLO="/var/log/baninapro-szerver.log"

# a docker-compose.yml-ből: konténer- és kötetnevek (projektnév: baninapro)
PROJEKT="baninapro"
APP_KONTENER="baninapro-app"
DB_KONTENER="baninapro-db"
DB_KOTET="${PROJEKT}_db-adatok"
ADMIN_KEZDO="admin / BaninaPRO-2026!"   # az sql/schema.sql kezdő adminja

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
UTOLSO_PARANCS=""
FIGYELMEZTETESEK=()
LEPESEK=()
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
    torol
    printf '\n%s%s HIBA [%s]: %s%s\n' "$C_PIROS" "$S_HIBA" "$AKT_LEPES" "$*" "$C_N" >&3
    printf '  A hiba javítása után a script nyugodtan újrafuttatható. Napló: %s\n' "$NAPLO" >&3
    printf '\n  XX  HIBA [%s]: %s\n' "$AKT_LEPES" "$*"
    exit 1
}
varatlan_hiba() {   # $1 = kilépési kód, $2 = sor, $3 = parancs
    local parancs="$3"
    if [[ $parancs == return* && -n $UTOLSO_PARANCS ]]; then parancs="$UTOLSO_PARANCS"; fi
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
    s="$(stty size </dev/tty 2>/dev/null || true)"
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
    "$@" &
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
        varj 15
        apt-get clean >/dev/null 2>&1 || true
        if [[ " $* " != *" update "* ]]; then apt_nyers update || true; fi
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
    local d
    AKT_MUVELET="rendszeridő szinkronizálása"
    timedatectl set-ntp true >/dev/null 2>&1 || true
    systemctl restart systemd-timesyncd >/dev/null 2>&1 || true
    varj 10
    if ! timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -qx yes; then
        # ha az NTP nem jut át (pl. tűzfal): az idő egy webszerver válaszfejlécéből (http – a https-hez már jó óra kell)
        d="$(curl -sI --max-time 15 http://archive.ubuntu.com/ubuntu/ 2>/dev/null | tr -d '\r' \
            | awk -F': ' 'tolower($1) == "date" { print $2; exit }' || true)"
        if [[ -n $d ]] && date -s "$d" >/dev/null 2>&1; then
            info "Rendszeridő beállítva: $(date '+%Y-%m-%d %H:%M')"
        fi
    fi
    AKT_MUVELET=""
}

# internetkapcsolat: indulás után a hálózat lassan éledhet, és rossz órával a https sem megy
internet_van() {
    local i
    for i in 1 2 3 4 5 6; do
        if fut curl -fsS --max-time 20 -o /dev/null https://github.com; then return 0; fi
        if (( i == 2 )); then ido_javit; fi
        AKT_MUVELET="internetkapcsolat ellenőrzése ($(( i + 1 )). próba)"
        varj 10
    done
    return 1
}

szabad_gb() { df -Pk / | awk 'NR == 2 { print int($4 / 1024 / 1024) }'; }
# helyfelszabadítás: letöltött csomagok, régi rendszernaplók, használaton kívüli Docker-képek és -gyorsítótár
# (a konténerekhez és a kötetekhez – az adatbázishoz – nem nyúl)
hely_felszabaditas() {
    AKT_MUVELET="hely felszabadítása"
    apt-get clean >/dev/null 2>&1 || true
    journalctl --vacuum-size=200M >/dev/null 2>&1 || true
    if command -v docker >/dev/null; then
        fut docker image prune -f || true
        fut docker builder prune -f || true
    fi
    AKT_MUVELET=""
}

# az adatbázis-séma (sql/schema.sql) a gitben van; ha helyben elveszett, visszaállítja onnan
sema_rendben() { [[ -f $SEMA ]] && grep -q 'CREATE TABLE' "$SEMA"; }
sema_biztosit() {
    sema_rendben && return 0
    # egy korábbi, séma nélküli indításkor a Docker üres mappát hoz létre a fájl helyén
    if [[ -d $SEMA ]]; then rmdir "$SEMA" 2>/dev/null || true; fi
    felh git -C "$REPO" checkout HEAD -- sql/schema.sql >/dev/null 2>&1 || true
    sema_rendben
}

# git pull; ha helyben lévő fájlok állnak az útjában, félrerakja őket (.git/baninapro-felrerakva), és újrapróbálja
git_frissit() {
    local kezdet szoveg fajlok=() f hova
    kezdet="$(naplo_meret)"
    if fut felh git -C "$REPO" pull --ff-only; then return 0; fi
    szoveg="$(naplo_resz "$kezdet")"
    hova="$REPO/.git/baninapro-felrerakva/$(date +%Y%m%d_%H%M%S)"
    if grep -q 'untracked working tree files would be overwritten' <<<"$szoveg"; then
        mapfile -t fajlok < <(awk '/untracked working tree files would be overwritten/ { b = 1; next }
                                   b && /^\t/ { sub(/^\t/, ""); print; next } { b = 0 }' <<<"$szoveg")
        for f in "${fajlok[@]}"; do
            if [[ -e $REPO/$f ]]; then
                install -d -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH")" "$hova/$(dirname "$f")"
                mv -f "$REPO/$f" "$hova/$f"
                info "Félreraktam (a git pull útjában volt): $f → .git/${hova#"$REPO/.git/"}"
            fi
        done
    fi
    if grep -qE 'local changes to the following files would be overwritten|commit your changes or stash them' <<<"$szoveg"; then
        if fut felh git -C "$REPO" stash push -m "szerver_beallitas: automatikusan félretéve $(date '+%Y-%m-%d %H:%M')"; then
            figy "A szerveren módosítva voltak a gitben lévő fájlok – félretettem őket (git stash list), és frissítettem."
        fi
    fi
    fut felh git -C "$REPO" pull --ff-only
}

# ---- Egyéb segédek ------------------------------------------------------------
# parancs futtatása a cél felhasználó nevében (git, ssh – az ő kulcsával, kérdezés nélkül)
felh() {
    sudo -u "$CEL_FELH" -H env GIT_TERMINAL_PROMPT=0 \
        GIT_SSH_COMMAND='ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20' "$@"
}

# docker compose a repó mappájából: a docker-compose.yml mellé a docker-compose.override.yml is betöltődik
dc() { ( cd "$REPO" && docker compose "$@" ); }

kontener_naplok() {   # a konténerek utolsó naplósorai a képernyőre és a naplóba
    local k
    torol
    for k in "$DB_KONTENER" "$APP_KONTENER"; do
        { printf '\n  --- %s (utolsó 25 sor) ---\n' "$k"; docker logs --tail 25 "$k" 2>&1 | sed 's/^/    /'; } \
            | tee /dev/fd/3 || true
    done
}

# kulcs=érték beállítása egy INI-fájl [User] szakaszában (AccountsService)
ini_beallit() {
    if grep -q "^$2=" "$1"; then
        sed -i "s|^$2=.*|$2=$3|" "$1"
    else
        sed -i "/^\[User\]/a $2=$3" "$1"
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

    # akinek a nevében fut (sudo) – ő lép be automatikusan, az ő kulcsával megy a git
    CEL_FELH="${SUDO_USER:-}"
    if [[ -z $CEL_FELH || $CEL_FELH == root ]]; then CEL_FELH="$(stat -c %U "$REPO")"; fi
    [[ $CEL_FELH != root ]] || hiba "A saját felhasználóddal, sudo-val indítsd (ne root-ként): sudo bash $(basename "$SCRIPT")"
    CEL_HOME="$(getent passwd "$CEL_FELH" | cut -d: -f6)"
    [[ -d $CEL_HOME ]] || hiba "Nem található a(z) $CEL_FELH felhasználó saját mappája."
    ok "Felhasználó: $CEL_FELH ($CEL_HOME)"

    if [[ ! -f $REPO/docker-compose.yml || ! -f $REPO/docker/Dockerfile || ! -f $REPO/docker/config.php ]]; then
        # véletlenül törölt fájlok: vissza a gitből
        felh git -C "$REPO" checkout HEAD -- docker-compose.yml docker >/dev/null 2>&1 || true
    fi
    [[ -f $REPO/docker-compose.yml && -f $REPO/docker/Dockerfile && -f $REPO/docker/config.php ]] \
        || hiba "A script nem a BaninaPRO repó „SERVER SETUP AND UPDATE” mappájából fut ($REPO)."
    ok "BaninaPRO mappa: $REPO"

    AKT_MUVELET="internetkapcsolat ellenőrzése"
    internet_van || hiba "Nincs internetkapcsolat (a github.com nem érhető el) – ellenőrizd a hálózatot, majd futtasd újra."
    AKT_MUVELET=""
    ok "Internetkapcsolat rendben"

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

# Frissítés: mentés a mostani adatbázisról, majd a legfrissebb kód. Ha közben maga a script is
# frissült, az új változat fut tovább (egyszer).
frissites_elokeszites() {
    [[ -z ${BANINA_UJRA:-} ]] || return 0
    AKT_LEPES="frissítés előkészítése"
    cim "Mentés és a legfrissebb kód letöltése"
    local elotte utana
    if command -v docker >/dev/null && [[ $(docker inspect -f '{{.State.Running}}' "$APP_KONTENER" 2>/dev/null || true) == true ]]; then
        AKT_MUVELET="adatbázis-mentés"
        if fut docker exec "$APP_KONTENER" php -q cron_mentes.php; then
            ok "Adatbázis-mentés a frissítés előtt: $(tail -n 1 "$NAPLO")"
        else
            figy "A frissítés előtti mentés nem sikerült: $(tail -n 1 "$NAPLO")"
        fi
        AKT_MUVELET=""
    else
        info "A BaninaPRO még nem fut – nincs mit menteni."
    fi

    elotte="$(sha256sum "$SCRIPT" | cut -d' ' -f1)"
    AKT_MUVELET="git pull"
    if git_frissit; then
        ok "A kód naprakész (git pull)"
    else
        figy "A git pull nem sikerült – a meglévő kóddal folytatom. ($(tail -n 1 "$NAPLO"))"
    fi
    AKT_MUVELET=""
    utana="$(sha256sum "$SCRIPT" | cut -d' ' -f1)"
    if [[ $elotte != "$utana" ]]; then
        info "A telepítő script is frissült – az új változattal folytatom."
        export BANINA_UJRA=1
        rm -rf "$TMPD"
        exec bash "$SCRIPT" "$@"
    fi
}

# Minden kérdés az elején, utána már nem kell a géphez nyúlni.
kerdesek() {
    if van_csomag anydesk || [[ ! -t 0 ]]; then return 0; fi
    cim "Egy kérdés az elején (utána már nem kell a géphez nyúlni)"
    local masodszor=""
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
}

# =============================================================================
#  Tevékenységlista – ezen megy végig a script, sorban
#  (függvény | súly ≈ várható másodperc első telepítéskor | megnevezés)
# =============================================================================
lepesek_listaja() {
    LEPESEK=(
        "lepes_rendszer|300|Rendszerfrissítés és alapcsomagok"
        "lepes_gepnev|3|Gépnév ($GEPNEV) és időzóna ($IDOZONA)"
        "lepes_nyelv|45|Magyar nyelv és magyar billentyűzet"
        "lepes_asztal|360|Asztali környezet: LXQt + Xorg + LightDM, magyar feliratokkal"
        "lepes_autologin|2|Automatikus bejelentkezés ($CEL_FELH)"
        "lepes_bongeszo|90|Firefox böngésző (magyar, kezdőlap: http://localhost)"
        "lepes_anydesk|30|AnyDesk – mindig fut, a géppel együtt indul"
        "lepes_docker|90|Docker Engine – mindig fut, a géppel együtt indul"
        "lepes_ssh|15|SSH: távoli belépés és GitHub-kulcs"
        "lepes_energia|2|Alvó mód tiltása (a szerver mindig elérhető)"
        "lepes_baninapro|240|BaninaPRO: konténerek és adatbázis (localhost:80)"
        "lepes_mentes_cron|2|Éjszakai adatbázis-mentés (03:00)"
        "lepes_ellenorzes|45|Végső ellenőrzés: oldal, API, adatbázis"
    )
}

futtat_lepesek() {
    local i=0 db=${#LEPESEK[@]} e fv suly nev
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
        AKT_LEPES="$i/$db $nev" AKT_ROVID="$i/$db" AKT_SULY=$suly LEPES_KEZDET=$SECONDS
        cim "[$i/$db] $nev"
        "$fv"
        KESZ_SULY=$(( KESZ_SULY + suly ))
    done
    AKT_SULY=0
}

# =============================================================================
#  A lépések
# =============================================================================
lepes_rendszer() {
    # egy korábbi, félbeszakadt futás vagy félbemaradt csomagtelepítés után a csomagkezelő rendbetétele
    csomagkezelo_rendbe || true

    apt_ update || figy "Az apt update hibát jelzett: $(apt_hibak) – folytatom."
    if apt_ full-upgrade; then
        ok "Rendszer frissítve"
    else
        figy "A rendszerfrissítés nem sikerült teljesen: $(apt_hibak) – folytatom, a következő futás újra megpróbálja."
    fi
    telepit ca-certificates curl wget gnupg git openssh-server cron psmisc locales \
        || hiba "Az alapcsomagok nem telepíthetők: $(apt_hibak)"
    telepit_opcionalis keyboard-configuration console-setup software-properties-common
    universe_bekapcsol
    ok "Alapcsomagok telepítve (curl, wget, git, openssh-server, cron…)"
}

# az universe csomagtároló (innen jön az LXQt és az Ubuntu saját Docker-csomagja)
universe_bekapcsol() {
    [[ -z $(elerheto lxqt-core) ]] || return 0
    AKT_MUVELET="universe csomagtároló bekapcsolása"
    if ! fut add-apt-repository -y universe && [[ -f /etc/apt/sources.list.d/ubuntu.sources ]]; then
        # tartalék: közvetlenül a forrásfájlba
        sed -i -E '/^Components:/{/universe/!s/$/ universe/}' /etc/apt/sources.list.d/ubuntu.sources
    fi
    AKT_MUVELET=""
    apt_ update || true
    [[ -n $(elerheto lxqt-core) ]] || figy "Az universe csomagtároló nem kapcsolható be – az asztal telepítése elakadhat."
}

lepes_gepnev() {
    if [[ $(hostname) != "$GEPNEV" ]]; then
        hostnamectl set-hostname "$GEPNEV"
        UJRAINDITAS=1
    fi
    if grep -qE '^127\.0\.1\.1[[:space:]]' /etc/hosts; then
        sed -i -E "s/^127\.0\.1\.1[[:space:]].*/127.0.1.1\t$GEPNEV/" /etc/hosts
    else
        printf '127.0.1.1\t%s\n' "$GEPNEV" >> /etc/hosts
    fi
    # a cloud-init újraindításkor ne írja vissza a régi gépnevet
    if [[ -d /etc/cloud/cloud.cfg.d ]]; then
        echo 'preserve_hostname: true' > /etc/cloud/cloud.cfg.d/99-baninapro.cfg
    fi
    ok "Gépnév: $GEPNEV"
    timedatectl set-timezone "$IDOZONA"
    timedatectl set-ntp true || true
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
    if ! van_csomag lightdm; then UJRAINDITAS=1; fi
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
    systemctl enable lightdm
    systemctl set-default graphical.target
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
    "DontCheckDefaultBrowser": true
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
        if echo "$ANYDESK_JELSZO" | anydesk --set-password; then
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
        systemctl daemon-reload || true
    fi
    if systemctl enable --now anydesk \
        || { systemctl reset-failed anydesk || true; systemctl restart anydesk; }; then
        ok "AnyDesk fut, és a géppel együtt indul"
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
    fi
    docker_daemon_json
    if ! docker_inditas; then
        torol
        journalctl -u docker -n 15 --no-pager 2>/dev/null | sed 's/^/    | /' >&3 || true
        hiba "A Docker nem indul el (systemctl status docker)."
    fi
    usermod -aG docker "$CEL_FELH"
    compose_biztosit
    ok "Docker $(docker version -f '{{.Server.Version}}') fut és a géppel együtt indul; Compose $(docker compose version --short)"
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
    local i
    AKT_MUVELET="Docker indítása"
    systemctl daemon-reload || true
    systemctl enable containerd docker || true
    for i in 1 2 3 4; do
        if docker info >/dev/null 2>&1; then AKT_MUVELET=""; return 0; fi
        if [[ -f /etc/docker/daemon.json ]] && command -v dockerd >/dev/null \
            && ! dockerd --validate --config-file=/etc/docker/daemon.json >/dev/null 2>&1; then
            mv -f /etc/docker/daemon.json "/etc/docker/daemon.json.hibas-$(date +%Y%m%d%H%M%S)"
            figy "A /etc/docker/daemon.json hibás volt – félretettem, és újat írtam."
            docker_daemon_json
        fi
        systemctl reset-failed containerd docker || true
        fut systemctl restart containerd docker || true
        varj $(( 3 * i ))
    done
    AKT_MUVELET=""
    docker info >/dev/null 2>&1
}

# docker compose: ha hiányzik, csomagból pótolja, végső esetben a Docker GitHub-oldaláról tölti le
compose_biztosit() {
    local cel=/usr/local/lib/docker/cli-plugins/docker-compose arch
    if docker compose version >/dev/null 2>&1; then return 0; fi
    info "Hiányzik a „docker compose” bővítmény – pótolom…"
    if van_csomag docker-ce; then telepit_opcionalis docker-compose-plugin; else telepit_opcionalis docker-compose-v2; fi
    if docker compose version >/dev/null 2>&1; then return 0; fi
    case $ARCH in amd64) arch=x86_64 ;; arm64) arch=aarch64 ;; armhf) arch=armv7 ;; *) arch=$ARCH ;; esac
    install -d -m 755 "$(dirname "$cel")"
    AKT_MUVELET="docker compose letöltése"
    if ujraprobal 3 curl -fsSL "https://github.com/docker/compose/releases/latest/download/docker-compose-linux-$arch" -o "$cel"; then
        chmod 755 "$cel"
    fi
    AKT_MUVELET=""
    docker compose version >/dev/null 2>&1 || hiba "Hiányzik a „docker compose” bővítmény, és nem sikerült pótolni."
    ok "docker compose pótolva (a Docker GitHub-oldaláról)"
}

lepes_ssh() {
    # SSH szerver – távoli belépés a gépre (Ubuntu 22.10 óta socketről indul)
    if systemctl is-active --quiet ssh.socket; then
        systemctl enable ssh.socket || true
    elif systemctl is-active --quiet ssh; then
        systemctl enable ssh || true
    else
        systemctl enable --now ssh.socket || systemctl enable --now ssh \
            || figy "Az SSH szerver nem indult el (systemctl status ssh)."
    fi
    ok "SSH szerver fut – távoli belépés: ssh $CEL_FELH@$GEPNEV"

    # a gép saját kulcsa, amivel a GitHubról húzza a repót
    local mappa="$CEL_HOME/.ssh" kulcs="$CEL_HOME/.ssh/id_ed25519" url
    install -d -m 700 -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH")" "$mappa"
    if [[ ! -f $kulcs ]]; then
        felh ssh-keygen -q -t ed25519 -N "" -C "$CEL_FELH@$GEPNEV" -f "$kulcs"
        info "Új SSH kulcs készült: $kulcs"
    fi
    if [[ ! -f $kulcs.pub ]]; then
        ssh-keygen -y -f "$kulcs" > "$kulcs.pub"
        chown "$CEL_FELH:" "$kulcs.pub"
    fi
    chmod 600 "$kulcs"
    chmod 644 "$kulcs.pub"
    if [[ $(awk '{print $2}' "$kulcs.pub") == "$(awk '{print $2}' <<<"$GITHUB_KULCS")" ]]; then
        ok "A gépen a GitHubon regisztrált baninapro kulcs van"
    else
        info "A gép kulcsa nem a korábban regisztrált baninapro kulcs: $(cat "$kulcs.pub")"
    fi

    AKT_MUVELET="GitHub elérés ellenőrzése"
    fut felh ssh -T -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20 git@github.com || true
    AKT_MUVELET=""
    if grep -q "successfully authenticated" <<<"$(tail -n 5 "$NAPLO")"; then
        ok "GitHub elérés a kulccsal rendben"
        # ha https-sel lett klónozva, átállítjuk SSH-ra, hogy a frissítés (git pull) jelszó nélkül menjen
        url="$(felh git -C "$REPO" remote get-url origin 2>/dev/null || true)"
        if [[ $url == https://github.com/* ]]; then
            felh git -C "$REPO" remote set-url origin "git@github.com:${url#https://github.com/}"
            ok "A repó címe SSH-ra állítva: git@github.com:${url#https://github.com/}"
        fi
    else
        figy "A GitHub nem fogadja el a gép kulcsát – a frissítéshez (git pull) vedd fel: GitHub → Settings → SSH and GPG keys → New SSH key → $(cat "$kulcs.pub")"
    fi
}

lepes_energia() {
    systemctl mask sleep.target suspend.target hibernate.target hybrid-sleep.target
    mkdir -p /etc/systemd/logind.conf.d
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
    ok "Alvó mód és hibernálás letiltva – a szerver mindig elérhető"
}

lepes_baninapro() {
    # 1) adatbázis-séma (a gitben van): üres adatbázisnál a MySQL ebből hozza létre a táblákat és a kezdő admint
    sema_biztosit || hiba "Hiányzik az adatbázis-séma: $SEMA – a git pull nem hozta le (lásd a figyelmeztetéseket). Ellenőrizd a GitHub-elérést, majd futtasd újra."
    if docker volume inspect "$DB_KOTET" >/dev/null 2>&1; then
        ELSO_INDITAS=0
        ok "Meglévő adatbázis – az adatok megmaradnak"
    else
        ELSO_INDITAS=1
        ok "Adatbázis-séma megvan – első induláskor létrejönnek a táblák és a kezdő admin"
    fi

    # 2) szerver-kiegészítés a docker-compose.yml mellé (a compose magától betölti): 80-as port, fix projektnév
    cat > "$REPO/docker-compose.override.yml" <<EOF
# BaninaPRO szerver – a "SERVER SETUP AND UPDATE/$(basename "$SCRIPT")" írja minden futáskor, kézzel ne módosítsd.
# A docker compose a docker-compose.yml mellé automatikusan betölti: az alkalmazás a szerveren
# a http://localhost (80-as port) címen is elérhető. A rögzített projektnévvel a kötetek neve sem változik.
name: $PROJEKT
services:
  app:
    ports:
      - "$APP_PORT:80"
EOF
    chown "$CEL_FELH:" "$REPO/docker-compose.override.yml"
    # a git ne lássa új fájlnak (helyi kizárás, a .gitignore-hoz nem nyúl)
    if [[ -d $REPO/.git ]] && ! grep -qx 'docker-compose.override.yml' "$REPO/.git/info/exclude" 2>/dev/null; then
        install -d -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH")" "$REPO/.git/info"
        echo 'docker-compose.override.yml' >> "$REPO/.git/info/exclude"
        chown "$CEL_FELH:" "$REPO/.git/info/exclude"
    fi
    ok "Szerver-beállítás: docker-compose.override.yml (BaninaPRO a $APP_PORT címen)"

    # 3) a 80-as port legyen szabad (ha a BaninaPRO már fut rajta, az rendben van)
    port80_felszabadit

    # 4) építés és indítás – átmeneti hálózati hibánál újrapróbálja
    AKT_MUVELET="az alkalmazás építése (PHP 8.3 + Apache)"
    if ! ujraprobal 3 dc build --pull app; then
        # ha az alapkép frissítése nem megy, a meglévővel is felépülhet
        ujraprobal 2 dc build app || hiba "Az alkalmazás képe nem épült fel – a napló végén látszik, miért."
    fi
    ok "Az alkalmazás képe elkészült"
    AKT_MUVELET="konténerek indítása (első induláskor a MySQL 1-2 percig készíti az adatbázist)"
    if ! ujraprobal 2 dc up -d --remove-orphans; then
        # a félig elindult konténerek leállítása (az adatok a kötetekben megmaradnak), majd még egy próba
        fut dc down --remove-orphans || true
        varj 5
        if ! ujraprobal 2 dc up -d --remove-orphans; then
            AKT_MUVELET=""
            kontener_naplok
            hiba "A konténerek nem indultak el."
        fi
    fi
    AKT_MUVELET=""
    dc ps || true
    ok "Konténerek elindítva"

    # 5) adatbázis: a táblák és a kezdő admin – ami hiányzik, azt a sémából pótolja
    if ! adatbazis_rendbe; then
        kontener_naplok
        hiba "Az adatbázis nem készült el (a MySQL nem indult el, vagy a táblák nem hozhatók létre)."
    fi
}

# a 80-as port: ha egy másik webszerver (apache2, nginx…) foglalja, leállítja és kikapcsolja
port80_felszabadit() {
    local foglalo s
    foglalo="$(ss -Hltnp 'sport = :80' 2>/dev/null || true)"
    if [[ -z $foglalo || $foglalo == *docker-proxy* ]]; then return 0; fi
    for s in apache2 nginx lighttpd caddy httpd; do
        if [[ $foglalo == *"\"$s\""* ]] && systemctl disable --now "$s" >/dev/null 2>&1; then
            figy "A 80-as portot a(z) $s foglalta – leállítottam és kikapcsoltam (ez a port a BaninaPRO-é)."
        fi
    done
    varj 2
    foglalo="$(ss -Hltnp 'sport = :80' 2>/dev/null || true)"
    if [[ -n $foglalo && $foglalo != *docker-proxy* ]]; then
        hiba "A 80-as portot már egy másik program használja: $foglalo"
    fi
}

# MySQL-parancs az adatbázis-konténerben, a docker-compose.yml-ben megadott root-jelszóval és adatbázissal
db_sql() {
    docker exec -i "$DB_KONTENER" sh -c 'exec mysql -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" "$@"' _ "$@"
}
# a végleges MySQL fut-e (az első indításkori ideiglenes szerver csak socketen figyel, TCP-n nem)
db_kesz() {
    docker exec "$DB_KONTENER" sh -c 'mysqladmin ping -h 127.0.0.1 --protocol=TCP -uroot -p"$MYSQL_ROOT_PASSWORD" --silent' >/dev/null 2>&1
}
db_szam() { db_sql -e "$1" 2>/dev/null | tr -dc '0-9' || true; }

# Az adatbázis rendbetétele: megvárja a MySQL-t, és ha hiányoznak táblák vagy a kezdő admin, a sémából pótolja.
# A séma csak CREATE TABLE IF NOT EXISTS és ON DUPLICATE KEY beszúrásokból áll, így meglévő adatokhoz nem nyúl.
adatbazis_rendbe() {
    local i kell tablak felhasznalok u p d
    kell="$(grep -c 'CREATE TABLE' "$SEMA" || true)"
    AKT_MUVELET="várakozás az adatbázisra (első induláskor 1-2 perc)"
    for (( i = 0; i < 120; i++ )); do
        if db_kesz; then break; fi
        varj 2
    done
    if ! db_kesz; then
        # beragadt indulás: még egy újraindítás
        fut docker restart "$DB_KONTENER" || true
        for (( i = 0; i < 60; i++ )); do
            if db_kesz; then break; fi
            varj 2
        done
        db_kesz || { AKT_MUVELET=""; return 1; }
    fi
    tablak="$(db_szam 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')"
    felhasznalok="$(db_szam 'SELECT COUNT(*) FROM felhasznalok')"
    if (( ${tablak:-0} < kell )) || (( ${felhasznalok:-0} == 0 )); then
        info "Az adatbázisban ${tablak:-0} tábla és ${felhasznalok:-0} felhasználó van – a sémából pótolom, ami hiányzik…"
        AKT_MUVELET="adatbázis-táblák létrehozása a sémából"
        db_sql < "$SEMA" || true
        tablak="$(db_szam 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')"
        felhasznalok="$(db_szam 'SELECT COUNT(*) FROM felhasznalok')"
        if (( ${tablak:-0} < kell || ${felhasznalok:-0} == 0 )); then AKT_MUVELET=""; return 1; fi
        ok "Adatbázis-táblák pótolva a sémából"
    fi
    # az alkalmazás adatbázis-felhasználója (docker-compose.yml) – egy régebbi kötetből hiányozhat vagy más a jelszava
    u="$(docker exec "$DB_KONTENER" printenv MYSQL_USER 2>/dev/null || true)"
    p="$(docker exec "$DB_KONTENER" printenv MYSQL_PASSWORD 2>/dev/null || true)"
    d="$(docker exec "$DB_KONTENER" printenv MYSQL_DATABASE 2>/dev/null || true)"
    if [[ -n $u && -n $p && -n $d ]]; then
        printf "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';\nALTER USER '%s'@'%%' IDENTIFIED BY '%s';\nGRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'%%';\n" \
            "$u" "$p" "$u" "$p" "$d" "$u" | db_sql || true
    fi
    AKT_MUVELET=""
    ok "Adatbázis rendben: ${tablak:-0} tábla, ${felhasznalok:-0} felhasználó"
}

lepes_mentes_cron() {
    systemctl enable --now cron || true
    cat > /etc/cron.d/baninapro <<EOF
# BaninaPRO – napi teljes adatbázis-mentés, mint az éles cron (a szerver_beallitas.sh írta).
# A mentések a Docker-kötetben vannak; lista: docker exec $APP_KONTENER php -q cron_mentes.php lista
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
$MENTES_CRON root docker exec $APP_KONTENER php -q cron_mentes.php >> /var/log/baninapro-mentes.log 2>&1
EOF
    chmod 644 /etc/cron.d/baninapro
    ok "Éjszakai adatbázis-mentés: minden nap 03:00 (napló: /var/log/baninapro-mentes.log)"
}

# háttérben fut: a főoldal és az adatbázis állapota fájlokba (közben pöröghet a folyamatjelző)
ellenorzo_lekeres() {
    curl -s -o "$TMPD/oldal.html" -w '%{http_code}' --max-time 10 http://127.0.0.1/ > "$TMPD/kod" 2>/dev/null || true
    docker exec "$APP_KONTENER" php -r \
        'require "/var/www/html/includes/db.php"; echo (int)db_val("SELECT COUNT(*) FROM felhasznalok");' \
        > "$TMPD/felh" 2>&1 || true
}

lepes_ellenorzes() {
    local oldal="$TMPD/oldal.html" kod="" felhasznalok="" api="" i k s allapot
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
            fut docker restart "$APP_KONTENER" || true
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
    ok "Főoldal: http://localhost → HTTP 200, BaninaPRO $(grep -o '"verzio":"[^"]*"' "$oldal" | cut -d'"' -f4 || true)"

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

    kod="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1:8081/ || true)"
    if [[ $kod == 200 ]]; then ok "phpMyAdmin: http://localhost:8081"
    else figy "A phpMyAdmin nem válaszol (HTTP $kod)."; fi

    for k in "$APP_KONTENER" "$DB_KONTENER" baninapro-phpmyadmin; do
        allapot="$(docker inspect -f '{{.State.Status}}/{{.HostConfig.RestartPolicy.Name}}' "$k" 2>/dev/null || echo hiányzik)"
        if [[ $allapot == running/unless-stopped || $allapot == running/always ]]; then
            ok "$k fut, a gép újraindítása után magától elindul"
        else
            figy "$k állapota: $allapot"
        fi
    done
    for s in docker containerd anydesk lightdm; do
        if systemctl is-enabled --quiet "$s" 2>/dev/null; then ok "$s: a géppel együtt indul"
        else figy "$s: nem indul automatikusan"; fi
    done
}

# =============================================================================
#  Összegzés
# =============================================================================
osszegzes() {
    AKT_LEPES="összegzés" AKT_ROVID="összegzés"
    local id="" i v="" f
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

    cim "KÉSZ – a BaninaPRO szerver működik"
    ki "  BaninaPRO:    http://localhost  (a szerveren, böngészőben)"
    if (( ELSO_INDITAS )); then
        ki "  Első belépés: $ADMIN_KEZDO  → belépés után azonnal változtasd meg!"
    fi
    ki "  phpMyAdmin:   http://localhost:8081"
    ki "  AnyDesk ID:   ${id:-(újraindítás után: sudo anydesk --get-id)}"
    ki "  SSH:          ssh $CEL_FELH@$GEPNEV"
    ki "  Frissítés:    cd \"$SCRIPT_DIR\" && sudo bash $(basename "$SCRIPT")"
    ki "  Napló:        $NAPLO"

    if (( ${#FIGYELMEZTETESEK[@]} )); then
        ki ""
        torol
        printf '  %sFigyelmeztetések (%d):%s\n' "$C_SARGA" "${#FIGYELMEZTETESEK[@]}" "$C_N" >&3
        printf '  Figyelmeztetések (%d):\n' "${#FIGYELMEZTETESEK[@]}"
        for f in "${FIGYELMEZTETESEK[@]}"; do ki "   - $f"; done
    fi

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

main() {
    if [[ $EUID -ne 0 ]]; then
        echo "Rendszergazdaként kell futtatni:  sudo bash $(basename "$SCRIPT")"
        exit 1
    fi
    naplo_inditas
    kepernyo_beallitas
    TMPD="$(mktemp -d)"
    APT_ALLAPOT="$TMPD/apt_allapot"
    trap 'torol; rm -rf "$TMPD"' EXIT
    LEPES_KEZDET=$SECONDS
    printf '\n\n######## %s – futás indul ########\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    printf '\n%sBaninaPRO szerver – telepítés és frissítés%s  (%s)\n' "$C_F" "$C_N" "$(date '+%Y-%m-%d %H:%M')" >&3

    elofeltetelek
    frissites_elokeszites "$@"
    kerdesek
    lepesek_listaja
    futtat_lepesek
    osszegzes
}

# a teljes script beolvasása után indul – így a futás közbeni git pull sem zavarja meg
main "$@"
