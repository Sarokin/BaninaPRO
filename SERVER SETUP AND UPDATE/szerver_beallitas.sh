#!/usr/bin/env bash
# =============================================================================
#  BaninaPRO – szerver telepítő és frissítő
#
#  Egy friss (szűz) Ubuntu Serverből egyetlen futtatással kész BaninaPRO szerver lesz:
#  magyar nyelv és billentyűzet, LXQt asztal automatikus belépéssel, AnyDesk, Docker Engine,
#  SSH, és a BaninaPRO a szerveren a http://localhost címen (80-as port).
#
#  ELŐKÉSZÜLET (egyszer, kézzel – a repó privát, a klónozáshoz GitHub-kulcs kell):
#    1. Ubuntu Server telepítése – felhasználó: baninapro, gépnév: baninapro
#    2. GitHub-hozzáférés a gépen, a kettő közül az egyik:
#       a) a régi szerver kulcsát átmásolod (a GitHubon már fent van, nem kell újra felvenni):
#            install -d -m 700 ~/.ssh && scp baninapro@REGI_GEP:.ssh/id_ed25519* ~/.ssh/
#       b) új kulcs:  ssh-keygen -t ed25519 , majd a ~/.ssh/id_ed25519.pub tartalmát
#          felveszed: GitHub → Settings → SSH and GPG keys → New SSH key
#    3. git clone git@github.com:Sarokin/BaninaPRO.git ~/BaninaPRO
#    4. Az adatbázis-sémát bemásolod (szándékosan nincs a gitben):  ~/BaninaPRO/sql/schema.sql
#
#  FUTTATÁS – első telepítés és később minden frissítés is ugyanez:
#    cd ~/BaninaPRO/"SERVER SETUP AND UPDATE"
#    sudo bash szerver_beallitas.sh
#
#  Újrafuttatva frissít: adatbázis-mentés → git pull → rendszerfrissítés → konténerek újraépítése.
#  Bármikor nyugodtan újrafuttatható: ami már kész, azt csak ellenőrzi. Napló: /var/log/baninapro-szerver.log
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
# tartalék, ha az AnyDesk csomagtárolója nem működne
ANYDESK_DEB="https://deb.anydesk.com/pool/main/a/anydesk/anydesk_8.1.0_amd64.deb"
NAPLO="/var/log/baninapro-szerver.log"

# a docker-compose.yml-ből: konténer- és kötetnevek (projektnév: baninapro)
PROJEKT="baninapro"
APP_KONTENER="baninapro-app"
DB_KOTET="${PROJEKT}_db-adatok"
ADMIN_KEZDO="admin / BaninaPRO-2026!"   # az sql/schema.sql kezdő adminja

SCRIPT="$(readlink -f "${BASH_SOURCE[0]}")"
SCRIPT_DIR="$(dirname "$SCRIPT")"
REPO="$(dirname "$SCRIPT_DIR")"
SEMA="$REPO/sql/schema.sql"

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1
export LC_ALL=C.UTF-8 LANG=C.UTF-8     # a script alatt futó programok kimenete egységes legyen
unset LANGUAGE

UJRAINDITAS=0 ELSO_INDITAS=0 ARCH="" CEL_FELH="" CEL_HOME="" ANYDESK_JELSZO="" TMPD=""
FIGYELMEZTETESEK=()
AKT_LEPES="előkészítés"

# ---- Kiírás -----------------------------------------------------------------
if [[ -t 1 || -n ${BANINA_SZIN:-} ]]; then
    export BANINA_SZIN=1
    C_KEK=$'\e[1;36m' C_ZOLD=$'\e[1;32m' C_SARGA=$'\e[1;33m' C_PIROS=$'\e[1;31m' C_F=$'\e[1m' C_N=$'\e[0m'
else
    C_KEK="" C_ZOLD="" C_SARGA="" C_PIROS="" C_F="" C_N=""
fi

cim()  { printf '\n%s━━ %s ━━%s\n' "$C_KEK" "$*" "$C_N"; }
ok()   { printf '  %s✔%s %s\n' "$C_ZOLD" "$C_N" "$*"; }
info() { printf '  • %s\n' "$*"; }
figy() { printf '  %s⚠ %s%s\n' "$C_SARGA" "$*" "$C_N"; FIGYELMEZTETESEK+=("[$AKT_LEPES] $*"); }
hiba() {
    printf '\n%s✘ HIBA [%s]: %s%s\n' "$C_PIROS" "$AKT_LEPES" "$*" "$C_N"
    printf '  Részletek: %s – a hiba javítása után a script nyugodtan újrafuttatható.\n' "$NAPLO"
    exit 1
}
# váratlan hiba: csak a fő folyamatban jelez és áll le (a $(…) és a csővezetékek belsejében nem)
trap 'rc=$?; if [[ $BASHPID == "$$" ]]; then printf "\n%s✘ Váratlan hiba [%s] – %s. sor, kilépési kód: %s%s\n    %s\n  Részletek: %s – a hiba javítása után a script nyugodtan újrafuttatható.\n" "$C_PIROS" "$AKT_LEPES" "$LINENO" "$rc" "$C_N" "$BASH_COMMAND" "$NAPLO"; exit "$rc"; fi' ERR

# Minden kiírás a naplóba is (színkódok nélkül). Újraindított scriptnél már be van kötve.
naplo_inditas() {
    [[ -z ${BANINA_NAPLO:-} ]] || return 0
    export BANINA_NAPLO=1
    touch "$NAPLO" && chmod 600 "$NAPLO"
    exec > >(tee >(sed -u 's/\x1b\[[0-9;]*m//g' >> "$NAPLO")) 2>&1
}

# ---- Segédek ----------------------------------------------------------------
# megvárja, amíg a háttérben futó automatikus frissítés elengedi az apt-ot (friss telepítés után gyakori)
apt_var() {
    local i
    for i in $(seq 1 120); do
        if ! fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock >/dev/null 2>&1; then
            return 0
        fi
        if (( i == 1 )); then info "Az apt foglalt (automatikus frissítés fut a háttérben) – várok…"; fi
        sleep 5
    done
}
apt_() {
    apt_var
    apt-get -y -q -o DPkg::Lock::Timeout=600 \
        -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$@"
}
telepit()     { apt_ install "$@"; }
telepit_min() { apt_ install --no-install-recommends "$@"; }
van_csomag()  { [[ $(dpkg-query -W -f='${db:Status-Abbrev}' "$1" 2>/dev/null) == ii* ]]; }
van_apt()     { apt-cache show "$1" >/dev/null 2>&1; }

# parancs futtatása a cél felhasználó nevében (git, ssh – az ő kulcsával, kérdezés nélkül)
felh() {
    sudo -u "$CEL_FELH" -H env GIT_TERMINAL_PROMPT=0 \
        GIT_SSH_COMMAND='ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20' "$@"
}

# docker compose a repó mappájából: a docker-compose.yml mellé a docker-compose.override.yml is betöltődik
dc() { ( cd "$REPO" && docker compose "$@" ); }

kontener_naplok() {
    local k
    for k in baninapro-db baninapro-app; do
        printf '\n  --- %s (utolsó 25 sor) ---\n' "$k"
        docker logs --tail 25 "$k" 2>&1 | sed 's/^/    /' || true
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
    local PRETTY_NAME="" ID=""
    # shellcheck disable=SC1091
    . /etc/os-release
    [[ $ID == ubuntu ]] || hiba "Ez a script Ubuntu Serverre készült (ez a rendszer: ${PRETTY_NAME:-ismeretlen})."
    ARCH="$(dpkg --print-architecture)"
    ok "Rendszer: $PRETTY_NAME ($ARCH)"
    if [[ $ARCH != amd64 ]]; then figy "Nem amd64 gép ($ARCH) – az AnyDesk telepítése elmaradhat."; fi

    # akinek a nevében fut (sudo) – ő lép be automatikusan, az ő kulcsával megy a git
    CEL_FELH="${SUDO_USER:-}"
    if [[ -z $CEL_FELH || $CEL_FELH == root ]]; then CEL_FELH="$(stat -c %U "$REPO")"; fi
    [[ $CEL_FELH != root ]] || hiba "A saját felhasználóddal, sudo-val indítsd (ne root-ként): sudo bash $(basename "$SCRIPT")"
    CEL_HOME="$(getent passwd "$CEL_FELH" | cut -d: -f6)"
    [[ -d $CEL_HOME ]] || hiba "Nem található a(z) $CEL_FELH felhasználó saját mappája."
    ok "Felhasználó: $CEL_FELH ($CEL_HOME)"

    [[ -f $REPO/docker-compose.yml && -f $REPO/docker/Dockerfile && -f $REPO/docker/config.php ]] \
        || hiba "A script nem a BaninaPRO repó „SERVER SETUP AND UPDATE” mappájából fut ($REPO)."
    ok "BaninaPRO mappa: $REPO"

    curl -fsS --max-time 20 -o /dev/null https://github.com || hiba "Nincs internetkapcsolat (a github.com nem érhető el)."
    ok "Internetkapcsolat rendben"

    local szabad
    szabad="$(df -Pk / | awk 'NR==2 {print int($4/1024/1024)}')"
    if (( szabad >= 8 )); then ok "Szabad hely: $szabad GB"
    else figy "Kevés a szabad hely ($szabad GB) – legalább 8 GB ajánlott."; fi

    if [[ -f $SEMA ]] && grep -q 'CREATE TABLE' "$SEMA"; then
        ok "Adatbázis-séma megvan (sql/schema.sql)"
    elif docker volume inspect "$DB_KOTET" >/dev/null 2>&1; then
        ok "Az adatbázis már létezik – séma nem kell"
    else
        figy "Hiányzik az sql/schema.sql (nincs a gitben). A gép beállítása lefut, de a BaninaPRO indításához be kell másolni ide: $SEMA"
    fi
}

# Frissítés: mentés a mostani adatbázisról, majd a legfrissebb kód. Ha közben maga a script is
# frissült, az új változat fut tovább (egyszer).
frissites_elokeszites() {
    [[ -z ${BANINA_UJRA:-} ]] || return 0
    AKT_LEPES="frissítés előkészítése"
    cim "Mentés és a legfrissebb kód letöltése"
    local kimenet elotte utana
    if command -v docker >/dev/null && [[ $(docker inspect -f '{{.State.Running}}' "$APP_KONTENER" 2>/dev/null || true) == true ]]; then
        if kimenet="$(docker exec "$APP_KONTENER" php -q cron_mentes.php 2>&1)"; then
            ok "Adatbázis-mentés a frissítés előtt: $kimenet"
        else
            figy "A frissítés előtti mentés nem sikerült: $kimenet"
        fi
    else
        info "A BaninaPRO még nem fut – nincs mit menteni."
    fi

    elotte="$(sha256sum "$SCRIPT" | cut -d' ' -f1)"
    if felh git -C "$REPO" pull --ff-only; then
        ok "A kód naprakész (git pull)"
    else
        figy "A git pull nem sikerült – a meglévő kóddal folytatom."
    fi
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
    if command -v anydesk >/dev/null || [[ ! -t 0 ]]; then return 0; fi
    cim "Egy kérdés az elején (utána már nem kell a géphez nyúlni)"
    local masodszor=""
    read -r -s -p "  AnyDesk jelszó a felügyelet nélküli eléréshez (Enter = kihagyás): " ANYDESK_JELSZO || true
    echo
    if [[ -n $ANYDESK_JELSZO ]]; then
        read -r -s -p "  Még egyszer: " masodszor || true
        echo
        if [[ $ANYDESK_JELSZO != "$masodszor" ]]; then
            figy "A két jelszó nem egyezik – az AnyDesk jelszót most nem állítom be."
            ANYDESK_JELSZO=""
        fi
    fi
}

# =============================================================================
#  Tevékenységlista – ezen megy végig a script, sorban
# =============================================================================
lepesek_listaja() {
    LEPESEK=(
        "lepes_rendszer|Rendszerfrissítés és alapcsomagok"
        "lepes_gepnev|Gépnév ($GEPNEV) és időzóna ($IDOZONA)"
        "lepes_nyelv|Magyar nyelv és magyar billentyűzet"
        "lepes_asztal|Asztali környezet: LXQt + Xorg + LightDM, magyar feliratokkal"
        "lepes_autologin|Automatikus bejelentkezés ($CEL_FELH)"
        "lepes_bongeszo|Firefox böngésző (magyar, kezdőlap: http://localhost)"
        "lepes_anydesk|AnyDesk – mindig fut, a géppel együtt indul"
        "lepes_docker|Docker Engine – mindig fut, a géppel együtt indul"
        "lepes_ssh|SSH: távoli belépés és GitHub-kulcs"
        "lepes_energia|Alvó mód tiltása (a szerver mindig elérhető)"
        "lepes_baninapro|BaninaPRO konténerek építése és indítása (localhost:80)"
        "lepes_mentes_cron|Éjszakai adatbázis-mentés (03:00)"
        "lepes_ellenorzes|Végső ellenőrzés: oldal, API, adatbázis"
    )
}

futtat_lepesek() {
    local i=0 db=${#LEPESEK[@]} e
    cim "TEVÉKENYSÉGLISTA"
    for e in "${LEPESEK[@]}"; do i=$((i + 1)); printf '  %2d. %s\n' "$i" "${e#*|}"; done
    i=0
    for e in "${LEPESEK[@]}"; do
        i=$((i + 1))
        AKT_LEPES="$i/$db ${e#*|}"
        cim "[$i/$db] ${e#*|}"
        "${e%%|*}"
    done
}

# =============================================================================
#  A lépések
# =============================================================================
lepes_rendszer() {
    apt_ update || figy "Az apt update hibát jelzett (lásd fent) – folytatom."
    apt_ full-upgrade
    ok "Rendszer frissítve"
    telepit ca-certificates curl wget gnupg git openssh-server cron psmisc locales \
        keyboard-configuration console-setup software-properties-common
    if ! van_apt lxqt-core; then
        add-apt-repository -y universe
        apt_ update
    fi
    ok "Alapcsomagok telepítve (curl, wget, git, openssh-server, cron…)"
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
    timedatectl set-ntp true 2>/dev/null || true
    ok "Időzóna: $IDOZONA (most: $(date '+%Y-%m-%d %H:%M'))"
}

lepes_nyelv() {
    telepit language-pack-hu language-pack-hu-base
    if [[ -f /etc/locale.gen ]] && ! grep -q "^$NYELV UTF-8" /etc/locale.gen; then
        if grep -q "^# *$NYELV UTF-8" /etc/locale.gen; then
            sed -i "s/^# *$NYELV UTF-8/$NYELV UTF-8/" /etc/locale.gen
        else
            echo "$NYELV UTF-8" >> /etc/locale.gen
        fi
    fi
    locale-gen "$NYELV" >/dev/null
    grep -qix 'hu_HU.utf8' <<<"$(locale -a)" || hiba "A magyar nyelvi beállítás ($NYELV) nem jött létre."

    if ! grep -qE "^LANG=\"?$NYELV\"?$" /etc/default/locale 2>/dev/null; then UJRAINDITAS=1; fi
    update-locale LANG="$NYELV" LANGUAGE=hu_HU:hu
    localectl set-locale LANG="$NYELV" LANGUAGE=hu_HU:hu 2>/dev/null || true
    ok "Rendszernyelv: magyar ($NYELV)"

    # billentyűzet: konzol + grafikus felület (a localectl Ubuntun nem mindig ismeri a konzolos „hu”-t,
    # ezért a végén az /etc/default/keyboard fájlt is beírjuk – az a mérvadó)
    localectl set-keymap hu 2>/dev/null || true
    localectl set-x11-keymap hu pc105 2>/dev/null || true
    printf '%s\n' \
        'keyboard-configuration keyboard-configuration/layoutcode string hu' \
        'keyboard-configuration keyboard-configuration/modelcode string pc105' \
        'keyboard-configuration keyboard-configuration/variantcode string ' \
        'keyboard-configuration keyboard-configuration/optionscode string ' | debconf-set-selections
    dpkg-reconfigure -f noninteractive keyboard-configuration >/dev/null 2>&1 || true
    cat > /etc/default/keyboard <<'EOF'
# BaninaPRO szerver: magyar billentyűzet (a szerver_beallitas.sh írta)
XKBMODEL="pc105"
XKBLAYOUT="hu"
XKBVARIANT=""
XKBOPTIONS=""
BACKSPACE="guess"
EOF
    setupcon --save-only >/dev/null 2>&1 || true
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
    # a jegyzet szerinti minimális asztal + üdvözlőképernyő, ablakkezelő és terminál, hogy biztosan teljes legyen
    telepit_min lxqt-core xorg lightdm lightdm-gtk-greeter openbox qterminal

    # magyar feliratok: a --no-install-recommends miatt a fordítás-csomagok (…-l10n) maguktól nem jönnek
    local l10n=() p alap j
    for p in $(dpkg-query -W -f='${db:Status-Abbrev}|${Package}\n' | awk -F'|' '$1 ~ /^ii/ {print $2}'); do
        case $p in
            *-l10n) continue ;;
            lxqt*|liblxqt*|pcmanfm-qt*|libfm-qt*|qterminal*|lximage-qt*) ;;
            *) continue ;;
        esac
        alap="$(sed -E 's/[0-9.-]+(t64)?$//' <<<"$p")"   # pl. libfm-qt14 → libfm-qt
        for j in "$p-l10n" "$alap-l10n"; do
            if van_apt "$j"; then l10n+=("$j"); break; fi
        done
    done
    for j in qttranslations5-l10n qt6-translations-l10n; do
        if van_apt "$j"; then l10n+=("$j"); fi
    done
    if (( ${#l10n[@]} )); then
        # shellcheck disable=SC2046
        telepit_min $(printf '%s\n' "${l10n[@]}" | sort -u)
    fi

    echo /usr/sbin/lightdm > /etc/X11/default-display-manager
    systemctl enable lightdm
    systemctl set-default graphical.target
    ok "LXQt asztal + LightDM telepítve (${#l10n[@]} magyar fordításcsomag), a gép grafikus felülettel indul"
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
    # a Mozilla saját csomagtárolójából (nem snap): magyar nyelvi csomaggal, apt-tal frissül
    if [[ ! -f /etc/apt/sources.list.d/mozilla.list ]]; then
        install -m 0755 -d /etc/apt/keyrings
        if ! curl -fsSL https://packages.mozilla.org/apt/repo-signing-key.gpg -o /etc/apt/keyrings/packages.mozilla.org.asc; then
            figy "A Mozilla csomagtároló kulcsa nem tölthető le – a böngésző kimarad."
            return 0
        fi
        echo "deb [signed-by=/etc/apt/keyrings/packages.mozilla.org.asc] https://packages.mozilla.org/apt mozilla main" \
            > /etc/apt/sources.list.d/mozilla.list
        printf 'Package: *\nPin: origin packages.mozilla.org\nPin-Priority: 1000\n' > /etc/apt/preferences.d/mozilla
        if ! apt_ update; then
            rm -f /etc/apt/sources.list.d/mozilla.list /etc/apt/preferences.d/mozilla
            figy "A Mozilla csomagtároló nem működik – a böngésző kimarad."
            return 0
        fi
    fi
    if ! telepit firefox firefox-l10n-hu; then
        figy "A Firefox telepítése nem sikerült."
        return 0
    fi
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
    ok "Firefox telepítve – magyarul, a kezdőlapja a BaninaPRO (http://localhost)"
}

lepes_anydesk() {
    local lista=/etc/apt/sources.list.d/anydesk-stable.list deb
    if ! command -v anydesk >/dev/null; then
        install -m 0755 -d /etc/apt/keyrings
        # elsősorban a hivatalos csomagtárolóból – így a rendszerfrissítéssel együtt frissül
        if curl -fsSL https://keys.anydesk.com/repos/DEB-GPG-KEY -o /etc/apt/keyrings/keys.anydesk.com.asc \
            && chmod a+r /etc/apt/keyrings/keys.anydesk.com.asc \
            && echo "deb [signed-by=/etc/apt/keyrings/keys.anydesk.com.asc] https://deb.anydesk.com all main" > "$lista" \
            && apt_ update && telepit anydesk; then
            ok "AnyDesk telepítve (hivatalos csomagtárolóból)"
        else
            rm -f "$lista"
            apt_ update >/dev/null 2>&1 || true
            if [[ $ARCH != amd64 ]]; then
                figy "AnyDesk: ehhez a géphez ($ARCH) nincs tartalék csomag – kimarad."
                return 0
            fi
            info "A csomagtároló nem működött – a jegyzetben szereplő .deb csomagot telepítem."
            deb="$TMPD/anydesk.deb"
            if ! curl -fsSL "$ANYDESK_DEB" -o "$deb"; then
                figy "Az AnyDesk nem tölthető le ($ANYDESK_DEB)."
                return 0
            fi
            chmod 644 "$deb"
            telepit "$deb"
            ok "AnyDesk telepítve (.deb csomagból)"
        fi
    fi
    if systemctl enable --now anydesk >/dev/null 2>&1; then
        ok "AnyDesk fut, és a géppel együtt indul"
    else
        figy "Az AnyDesk szolgáltatás nem indult el (systemctl status anydesk)."
    fi
    if [[ -n $ANYDESK_JELSZO ]]; then
        if echo "$ANYDESK_JELSZO" | anydesk --set-password >/dev/null 2>&1; then
            ok "AnyDesk jelszó beállítva (felügyelet nélküli elérés)"
        else
            figy "Az AnyDesk jelszót nem sikerült beállítani – később: echo 'JELSZÓ' | sudo anydesk --set-password"
        fi
        ANYDESK_JELSZO=""
    fi
}

lepes_docker() {
    local p kod
    if ! van_csomag docker-ce && ! van_csomag docker.io; then
        # a Docker leírása szerint az ütköző csomagok eltávolítása (friss gépen általában nincs ilyen)
        for p in docker.io docker-doc docker-compose docker-compose-v2 podman-docker containerd runc; do
            if van_csomag "$p"; then apt_ remove "$p"; fi
        done
        if command -v snap >/dev/null && snap list docker >/dev/null 2>&1; then
            info "A snap-es Docker eltávolítása (ütközne a Docker Engine-nel)…"
            snap remove --purge docker
        fi
        install -m 0755 -d /etc/apt/keyrings
        kod="$(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")"
        if curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc \
            && chmod a+r /etc/apt/keyrings/docker.asc \
            && printf 'Types: deb\nURIs: https://download.docker.com/linux/ubuntu\nSuites: %s\nComponents: stable\nArchitectures: %s\nSigned-By: /etc/apt/keyrings/docker.asc\n' \
                "$kod" "$ARCH" > /etc/apt/sources.list.d/docker.sources \
            && apt_ update \
            && telepit docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin; then
            ok "Docker Engine telepítve (hivatalos Docker csomagtárolóból)"
        else
            figy "A Docker csomagtároló nem működött ($kod) – az Ubuntu saját Docker csomagjait telepítem."
            rm -f /etc/apt/sources.list.d/docker.sources
            apt_ update || true
            telepit docker.io docker-compose-v2
            telepit docker-buildx >/dev/null 2>&1 || true
        fi
    fi
    # a konténerek naplói ne nőjenek a végtelenségig (konténerenként legfeljebb 3 × 10 MB)
    if [[ ! -f /etc/docker/daemon.json ]]; then
        mkdir -p /etc/docker
        printf '{\n  "log-driver": "json-file",\n  "log-opts": { "max-size": "10m", "max-file": "3" }\n}\n' > /etc/docker/daemon.json
        systemctl restart docker 2>/dev/null || true
        ok "Docker naplók mérete korlátozva"
    fi
    systemctl enable --now containerd docker
    usermod -aG docker "$CEL_FELH"
    docker info >/dev/null 2>&1 || hiba "A Docker nem fut (systemctl status docker)."
    docker compose version >/dev/null 2>&1 || hiba "Hiányzik a „docker compose” bővítmény."
    ok "Docker $(docker version -f '{{.Server.Version}}') fut és a géppel együtt indul; Compose $(docker compose version --short)"
}

lepes_ssh() {
    # SSH szerver – távoli belépés a gépre (Ubuntu 22.10 óta socketről indul)
    if systemctl is-active --quiet ssh.socket; then
        systemctl enable ssh.socket >/dev/null 2>&1 || true
    elif systemctl is-active --quiet ssh; then
        systemctl enable ssh >/dev/null 2>&1 || true
    else
        systemctl enable --now ssh.socket >/dev/null 2>&1 || systemctl enable --now ssh >/dev/null 2>&1 \
            || figy "Az SSH szerver nem indult el (systemctl status ssh)."
    fi
    ok "SSH szerver fut – távoli belépés: ssh $CEL_FELH@$GEPNEV"

    # a gép saját kulcsa, amivel a GitHubról húzza a repót
    local mappa="$CEL_HOME/.ssh" kulcs="$CEL_HOME/.ssh/id_ed25519" gh url
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

    gh="$(felh ssh -T -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20 git@github.com 2>&1 || true)"
    if [[ $gh == *"successfully authenticated"* ]]; then
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
    systemctl mask sleep.target suspend.target hibernate.target hybrid-sleep.target >/dev/null 2>&1
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
    # 1) adatbázis-séma: üres adatbázisnál a MySQL ebből hozza létre a táblákat és a kezdő admint
    if [[ -d $SEMA ]]; then
        # egy korábbi, séma nélküli indításkor a Docker üres mappát hoz létre a fájl helyén
        rmdir "$SEMA" 2>/dev/null || hiba "Az sql/schema.sql egy mappa – töröld, és másold a helyére a schema.sql fájlt."
    fi
    if docker volume inspect "$DB_KOTET" >/dev/null 2>&1; then
        ELSO_INDITAS=0
        if [[ ! -f $SEMA ]]; then
            install -d -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH")" "$REPO/sql"
            printf -- '-- Helyőrző: az adatbázis már létezik, a séma csak üres adatbázisnál futna le.\n' > "$SEMA"
            chown "$CEL_FELH:" "$SEMA"
        fi
        ok "Meglévő adatbázis – az adatok megmaradnak"
    else
        ELSO_INDITAS=1
        if [[ ! -f $SEMA ]] || ! grep -q 'CREATE TABLE' "$SEMA"; then
            hiba "Hiányzik az adatbázis-séma: $SEMA
    Ez a fájl szándékosan nincs a gitben. Másold be (pl. a fejlesztő gépről:
      scp sql/schema.sql $CEL_FELH@$GEPNEV:$SEMA ), majd futtasd újra a scriptet."
        fi
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

    # 3) szabad-e a 80-as port (ha a BaninaPRO már fut rajta, az rendben van)
    local foglalo
    foglalo="$(ss -Hltnp 'sport = :80' 2>/dev/null || true)"
    if [[ -n $foglalo && $foglalo != *docker-proxy* ]]; then
        hiba "A 80-as portot már egy másik program használja: $foglalo"
    fi

    # 4) építés és indítás
    info "Az alkalmazás képének építése (PHP 8.3 + Apache)…"
    dc build --pull app
    info "Konténerek indítása (első induláskor a MySQL 1-2 percig készíti az adatbázist)…"
    if ! dc up -d --remove-orphans; then
        kontener_naplok
        hiba "A konténerek nem indultak el."
    fi
    dc ps
    ok "Konténerek elindítva"
}

lepes_mentes_cron() {
    systemctl enable --now cron >/dev/null 2>&1 || true
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

lepes_ellenorzes() {
    local oldal="$TMPD/oldal.html" kod="" felhasznalok="" api="" i k s allapot
    info "Várakozás, amíg a BaninaPRO teljesen elindul (első induláskor 1-2 perc)…"
    for i in $(seq 1 60); do
        kod="$(curl -s -o "$oldal" -w '%{http_code}' --max-time 10 http://127.0.0.1/ 2>/dev/null || true)"
        felhasznalok="$(docker exec "$APP_KONTENER" php -r \
            'require "/var/www/html/includes/db.php"; echo (int)db_val("SELECT COUNT(*) FROM felhasznalok");' 2>&1 || true)"
        if [[ $kod == 200 && $felhasznalok =~ ^[0-9]+$ ]]; then break; fi
        sleep 3
    done

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

    for k in baninapro-app baninapro-db baninapro-phpmyadmin; do
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
    AKT_LEPES="összegzés"
    local id="" i v="" f
    if command -v anydesk >/dev/null; then
        for i in 1 2 3; do
            id="$(timeout 15 anydesk --get-id 2>/dev/null | tr -dc '0-9' || true)"
            if [[ -n $id && $id != 0 ]]; then break; fi
            sleep 5
        done
    fi

    cim "KÉSZ – a BaninaPRO szerver működik"
    printf '  BaninaPRO:    %shttp://localhost%s  (a szerveren, böngészőben)\n' "$C_ZOLD" "$C_N"
    if (( ELSO_INDITAS )); then
        printf '  Első belépés: %s  → belépés után azonnal változtasd meg!\n' "$ADMIN_KEZDO"
    fi
    printf '  phpMyAdmin:   http://localhost:8081\n'
    printf '  AnyDesk ID:   %s\n' "${id:-(újraindítás után: anydesk --get-id)}"
    printf '  SSH:          ssh %s@%s\n' "$CEL_FELH" "$GEPNEV"
    printf '  Frissítés:    cd "%s" && sudo bash %s\n' "$SCRIPT_DIR" "$(basename "$SCRIPT")"
    printf '  Napló:        %s\n' "$NAPLO"

    if (( ${#FIGYELMEZTETESEK[@]} )); then
        printf '\n  %sFigyelmeztetések (%d):%s\n' "$C_SARGA" "${#FIGYELMEZTETESEK[@]}" "$C_N"
        for f in "${FIGYELMEZTETESEK[@]}"; do printf '   - %s\n' "$f"; done
    fi

    if [[ -f /var/run/reboot-required ]] || ! systemctl is-active --quiet lightdm; then UJRAINDITAS=1; fi
    if (( UJRAINDITAS )); then
        printf '\n  %sÚjraindítás kell%s: utána indul az asztal, a magyar nyelv és az automatikus belépés.\n' "$C_SARGA" "$C_N"
        if [[ -t 0 ]]; then
            read -r -t 60 -p "  Újraindítsam most? [I/n] (60 mp múlva magától igen): " v || true
            echo
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
    TMPD="$(mktemp -d)"
    trap 'rm -rf "$TMPD"' EXIT
    printf '\n%sBaninaPRO szerver – telepítés és frissítés%s  (%s)\n' "$C_F" "$C_N" "$(date '+%Y-%m-%d %H:%M')"

    elofeltetelek
    frissites_elokeszites "$@"
    kerdesek
    lepesek_listaja
    futtat_lepesek
    osszegzes
}

# a teljes script beolvasása után indul – így a futás közbeni git pull sem zavarja meg
main "$@"
