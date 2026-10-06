#!/usr/bin/env bash
# =============================================================================
#  BaninaPRO – szerver telepítő és frissítő – LIGHT változat (kevés hely a gépen + USB-meghajtó)
#
#  A teljes szerver_beallitas.sh könnyített párja egy minimális Ubuntu Serverre, ahol MÁR MEGVAN:
#    - a LightDM, a legszükségesebb Xorg és az AnyDesk (ezekhez a script egyáltalán nem nyúl),
#    - a Docker (telepítve és fut).
#  Csak a BaninaPRO-hoz kellő részt állítja be, ugyanúgy, mint a teljes változat: a weboldal és a phpMyAdmin
#  (Docker-konténerek, adatbázis), az éjszakai adatbázis-mentés, az őrszem, a push-értesítések, a napi jelentés –
#  és hogy a gép soha ne aludjon el:
#    BaninaPRO:   http://<a szerver IP-címe>        vagy  http://baninapro.local
#    phpMyAdmin:  http://<a szerver IP-címe>:8081   (BaninaPRO / BaninaPRO1234 – kezdőjelszó, változtasd meg)
#  Az őrszem 2 percenként ellenőrzi a BaninaPRO-t: ha nem érhető el, emberi beavatkozás nélkül helyreállítja
#  (konténerek indítása, újraindítása, a Docker újraindítása, végső esetben – ritkán – a gép újraindítása).
#  Napló: /var/log/baninapro-orszem.log
#  Push-értesítések a telefonra (ntfy, ingyenes, fiók nélkül): áramszünet / újraindulás / leállás, hibák és
#  helyreállás (BaninaPRO, Docker, USB-meghajtó, konténerek, tárhely), az éjszakai mentés, a napi jelentés (03:30),
#  a be- és kilépések. A feliratkozás leírása az összegzésben és a ~/BaninaPRO-ertesitesek.txt fájlban.
#  A napi jelentés e-mailben is mehet, ha megadsz egy feladó-postafiókot:  sudo bash szerver_beallitas_light.sh --email
#
#  USB-MEGHAJTÓ – a gép saját lemezén kevés a hely, ezért a BaninaPRO adatai a géphez dugott pendrive-on vannak:
#    - BANINAPRO (FAT32 rész): a BaninaPRO-mentesek mappában minden adatbázis-mentés másolata. Bármely Windows,
#      Mac vagy Linux gépen megnyitható – ha baj van, csak kihúzod, és a mentések nálad vannak.
#    - BANINAPRO-ADAT (ext4 rész): a Docker teljes tárhelye – a képek, a konténerek és maga az adatbázis.
#      Így a Docker-gépek nem foglalnak helyet a gép saját lemezén, és az adatbázis is szabadon nőhet.
#    Ha a meghajtó még nincs előkészítve, a script a /dev/sdb-t készíti elő: ha üres (gyárilag formázott vagy
#    formázatlan), létrehozza rajta a két részt – ha bármilyen fájl van rajta, NEM formáz, hanem megáll.
#    Csak USB-eszközt formáz, a rendszerlemezt és a csatolt lemezeket soha.
#    Kapcsolók:  --usb=/dev/sdX     ha a pendrive nem sdb néven jelenik meg
#                --usb-formazas     a nem üres pendrive formázása is (minden adat törlődik róla!)
#    A meghajtót a címkéje alapján találja meg (BANINAPRO-ADAT): ha más néven jelenik meg, vagy egy új szerverbe
#    kerül, ott is működik – az új szerveren ugyanez a script futtatva az összes adattal visszahozza a BaninaPRO-t.
#    A legutóbbi mentések a gép saját lemezén is megvannak (/var/backups/baninapro), ha a pendrive tönkremenne.
#    Biztonságos eltávolítás:  sudo baninapro-usb levalaszt   (utána kihúzható; visszadugva az őrszem magától
#    visszacsatolja, és elindítja a BaninaPRO-t). A Docker a meghajtó nélkül el sem indul – így a gép saját lemezére
#    sem tölt le semmit.
#
#  TÁRHELY – a gép saját lemezén 1 GB-ba belefér (300 MB-tal is elindul):
#    - először takarít: letöltött csomagfájlok, rendszernaplók, Docker-gyorsítótár (a konténerekhez és az
#      adatbázishoz nem nyúl);
#    - az őrszem és a napi jelentés 500 MB alatt jelez kevés helyet (előtte takarít).
#
#  CSOMAGOK – a rendszert NEM frissíti (és automatikus frissítés sincs: kikapcsolja). A félbemaradt csomagtelepítést
#    nem folytatja, hanem lezárja: a csomagot a telepítőjével együtt törli – a Dockert, az asztalt és a rendszer részeit
#    nem (ezekről csak szól). Félbemaradt AnyDesk-csomagot csak akkor töröl, ha a működő AnyDesk nem abból fut (akkor
#    az egy felesleges második példány); ha abból fut, megmondja, hogyan zárható le. Ami félbemaradt marad, amellett
#    az apt-hoz nem nyúl. Csak a hiányzó eszközöket telepíti (curl, git, cron, fdisk, dosfstools…) – ha mind megvan,
#    az apt-ot el sem indítja.
#
#  FUTTATÁS – első futtatás és később minden frissítés is ugyanez:
#    cd ~/BaninaPRO/"SERVER SETUP AND UPDATE"
#    sudo bash szerver_beallitas_light.sh
#  (Ha csak ezt az egy fájlt töltöd le és futtatod, a script magától letölti a repót a ~/BaninaPRO mappába,
#  és onnan folytatja.)
#
#  A script magától nem tölt le újabb kódot (nincs git pull) és futás előtt nem ment – a frissítés kézzel:
#    cd ~/BaninaPRO && git pull      majd újra:  sudo bash szerver_beallitas_light.sh
#  (Az adatbázist minden éjjel 03:00-kor menti, az USB-re és a gép saját lemezére is.)
#  Bármikor nyugodtan újrafuttatható: ami már kész, azt csak ellenőrzi.
#  Önjavító: ha valami elakad – hiányzó eszköz vagy bővítmény, hálózati hiba, rossz rendszeridő, hibás külső
#  csomagtároló, foglalt 80-as port, leállt Docker, leválott USB-meghajtó, hiányzó adatbázis-táblák –, a script
#  megpróbálja magától rendbe tenni, és csak akkor áll meg, ha ez sem megy.
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
# a telefonon az ntfy alkalmazással kell rá feliratkozni (az összegzés és a ~/BaninaPRO-ertesitesek.txt kiírja). Ha épp nincs
# internet, az értesítés sorba áll, és az őrszem később elküldi. (Az ntfy.sh e-mail-továbbítása fiókot kérne – nincs.)
NTFY_SZERVER="https://ntfy.sh"
ERTESITO="/usr/local/sbin/baninapro-ertesites"
BELEPESFIGYELO="/usr/local/sbin/baninapro-belepesfigyelo"
MENTO="/usr/local/sbin/baninapro-mentes"
MENTES_CRON="0 3 * * *"              # éjszakai adatbázis-mentés, mint az éles cron
# a nyilvános GitHub-repó: innen jön a kód és minden frissítés (https – kulcs és jelszó nélkül)
REPO_URL="https://github.com/Sarokin/BaninaPRO.git"
NAPLO="/var/log/baninapro-szerver.log"

# Tárhely (MB): ennyi alatt a script nem indul el; ennyi alatt előbb takarít és figyelmeztet; a PHP-alapképet csak ennyi
# szabad hely fölött frissíti (a Docker tárhelyén, az USB-n); ha a Docker-képek hiányoznak, a letöltésükhöz ennyi kell;
# az őrszem és a napi jelentés ennyi alatt jelez kevés helyet a gép saját lemezén.
HELY_MIN_MB=300
HELY_FIGY_MB=1024
ALAPKEP_FRISSITES_MB=1500
KEP_LETOLTES_MB=2500
KEVES_HELY_MB=500

# USB-meghajtó (lásd fent): két rész, a címkéjük alapján megtalálva. Ha még nincs előkészítve, ezt a lemezt készíti elő
# (a --usb=/dev/sdX kapcsolóval más is megadható). Az USB_TESZT=1 csak a próbagéphez kell (ott nem USB a lemez).
USB_LEMEZ="${BANINA_USB_LEMEZ:-/dev/sdb}"
USB_TESZT="${BANINA_USB_TESZT:-0}"
USB_MIN_GB=8                              # ennél kisebb meghajtót nem használ
USB_ADAT_CIMKE="BANINAPRO-ADAT"           # ext4: a Docker tárhelye (képek, konténerek, adatbázis)
USB_MENTES_CIMKE="BANINAPRO"              # FAT32: a mentések másolatai – bármely gépen olvasható
USB_ADAT="/mnt/baninapro-adat"
USB_MENTES="/mnt/baninapro-mentes"
USB_MENTES_MAPPA="$USB_MENTES/BaninaPRO-mentesek"
USB_SEGED="/usr/local/sbin/baninapro-usb"      # csatolás-ellenőrzés, a mentések másolása, biztonságos leválasztás
BELSO_MENTES="/var/backups/baninapro"          # a legutóbbi mentések a gép saját lemezén is (ha a pendrive tönkremenne)
DOCKER_MAPPAK=(docker containerd)              # /var/lib/docker és /var/lib/containerd → az USB adat-részére

# a docker-compose.yml-ből: konténer- és kötetnevek (projektnév: baninapro)
PROJEKT="baninapro"
APP_KONTENER="baninapro-app"
DB_KONTENER="baninapro-db"
DB_KOTET="${PROJEKT}_db-adatok"
ADATOK_KOTET="${PROJEKT}_adatok"         # az alkalmazás naplója és mentései (LOG, DBBCKP)
ADMIN_KEZDO="admin / BaninaPRO-2026!"   # az sql/schema.sql kezdő adminja
# a BaninaPRO Docker-képei (docker-compose.yml): az alkalmazásé a PHP-alapképből épül
DB_KEP="mysql:latest"
PMA_KEP="phpmyadmin:latest"
APP_KEP="${PROJEKT}-app"
PHP_ALAPKEP="php:8.3-apache"

SCRIPT="$(readlink -f "${BASH_SOURCE[0]}")"
SCRIPT_DIR="$(dirname "$SCRIPT")"
REPO="$(dirname "$SCRIPT_DIR")"
SEMA="$REPO/sql/schema.sql"

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1
export LC_ALL=C.UTF-8 LANG=C.UTF-8     # a script alatt futó programok kimenete egységes legyen
unset LANGUAGE

UJRAINDITAS=0 ELSO_INDITAS=0 ARCH="" CEL_FELH="" CEL_HOME="" TMPD="" APT_ALLAPOT=/dev/null ARGOK=()
USB_FORMAZAS=0 USB_ADAT_DEV="" USB_MENTES_DEV="" USB_ADAT_UUID="" USB_MENTES_UUID=""
UTOLSO_PARANCS=""
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
    # (a 8-as leírón a telepítő zárja van: a gyerekfolyamat ne kapja meg – egy megszakított futás után ne tartsa fogva)
    "$@" 8>&- &
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
        # ha a sikertelen futás után félbemaradt telepítés van, nem próbálja újra (az újabb futás folytatni próbálná)
        if ! dpkg_rendben; then break; fi
        apt_javit "$kezdet" "$@"
    done
    AKT_MUVELET=""
    return "$rc"
}
# A light változat a félbemaradt csomagtelepítéseket nem folytatja, hanem lezárja: a csomagot törli (a felhasználó
# kérése, lásd felbemaradt_lezaras). Ami így is marad (védett, vagy nem törölhető), annál az apt-hoz egyáltalán nem
# nyúl – minden apt-futtatás megpróbálná befejezni. A csomaglistákat csak akkor frissíti, ha valamit telepíteni kell
# (futásonként egyszer) – a rendszert nem frissíti.
APT_LISTA_KESZ=0
apt_hasznalhato() {
    felbemaradt_lezaras
    if ! dpkg_rendben; then
        info "Félbemaradt csomagtelepítés maradt a gépen ($(hibas_csomagok | paste -sd ' ' - || true)) – nem folytatom, ezért az apt-hoz nem nyúlok."
        return 1
    fi
    if (( ! APT_LISTA_KESZ )); then
        apt_ update || true
        APT_LISTA_KESZ=1
    fi
}
telepit()     { apt_hasznalhato && apt_ install "$@"; }
telepit_min() { apt_hasznalhato && apt_ install --no-install-recommends "$@"; }
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
# a megadott csomagok közül azok, amelyek még nincsenek telepítve (a meglévőkhöz az apt-ot sem hívja – helytakarékos)
hianyzo() {
    local p
    for p in "$@"; do van_csomag "$p" || printf '%s\n' "$p"; done
}
# nem kötelező csomagok: ami nem érhető el vagy nem települ, arra csak figyelmeztet, és megy tovább
telepit_opcionalis() {
    local csomagok=() hibas=() p
    mapfile -t csomagok < <(hianyzo "$@")
    (( ${#csomagok[@]} )) || return 0
    if ! apt_hasznalhato; then
        figy "Nem települt (nem kötelező, a működést nem érinti): ${csomagok[*]} – félbemaradt csomagtelepítés van a gépen."
        return 0
    fi
    mapfile -t csomagok < <(elerheto "${csomagok[@]}")
    (( ${#csomagok[@]} )) || return 0
    if telepit_min "${csomagok[@]}"; then return 0; fi
    for p in "${csomagok[@]}"; do
        if ! telepit_min "$p"; then hibas+=("$p"); fi
    done
    if (( ${#hibas[@]} )); then
        figy "Nem települt (nem kötelező, a működést nem érinti): ${hibas[*]} – $(apt_hibak)"
    fi
    return 0
}

# ---- Önjavítás ------------------------------------------------------------------
# a félbemaradt (nem teljesen kicsomagolt vagy be nem állított) csomagok neve
hibas_csomagok() {
    dpkg-query -W -f='${db:Status-Abbrev} ${Package}\n' 2>/dev/null \
        | awk '{ s = substr($1, 2, 1); e = substr($1, 3, 1) } s ~ /[HUF]/ || e == "R" { print $NF }' || true
}

# Félbemaradt csomagtelepítés (a felhasználó kérése): nem folytatja, hanem lezárja – a csomagot a telepítőjével
# (beállításfájljaival) együtt törli. dpkg-val, nem apt-tal: az apt a többi félbemaradt telepítést folytatni próbálná.
# Amitől más csomag függ, azt a dpkg nem engedi törölni – az marad. A védett csomagokat nem törli (ha félbemaradtak,
# csak szól): a Dockert, az asztalt és a rendszer részeit, és a futó kernel csomagjait sem.
# Az AnyDesknél megnézi, honnan fut a működő AnyDesk:
#  - ha éppen a félbemaradt csomagból, akkor az nem egy második példány, hanem maga a működő AnyDesk: a telepítője
#    az xdg-utils nélkül az utolsó lépésénél (az asztali menüpontnál) hibával áll le – a program fut és be van állítva,
#    a csomagkezelő mégis félbemaradtnak látja. Ezt nem törli, csak szól;
#  - ha máshonnan fut, a félbemaradt csomag egy felesleges második példány: azt törli, de a saját eltávolító szkriptjei
#    nélkül (azok a futó AnyDesk szolgáltatását is leállítanák) – a szkriptek félrekerülnek, nem törlődnek;
#  - ha az AnyDesk most nem fut, nem dönthető el, melyik kell – nem törli.
VEDETT_CSOMAGOK=('docker*' 'containerd*' runc 'moby-*' 'lightdm*' 'xserver-*' 'xorg*' 'x11-*' xinit
    'linux-*' 'grub*' 'shim*' 'systemd*' udev 'libc6*' libc-bin dpkg apt 'apt-*' sudo 'openssh-*' 'netplan*'
    'network-manager*' ifupdown e2fsprogs util-linux 'initramfs-tools*' 'ubuntu-*' cloud-init)
FELBEMARADT_JELEZVE=" "   # a már jelzett (nem törölhető) csomagok – egy futásban egyszer szól róluk
# a futó AnyDesk program(ok) csomagja: a csomag neve, „-” ha nem csomagból fut (pl. kicsomagolt változat); üres, ha nem fut
anydesk_futo_csomagjai() {
    local pid exe cs pidek=()
    mapfile -t pidek < <(pgrep -x anydesk 2>/dev/null || true)
    for pid in "${pidek[@]}"; do
        exe="$(readlink -f "/proc/$pid/exe" 2>/dev/null || true)"
        exe="${exe% (deleted)}"
        [[ -n $exe ]] || continue
        cs="$(dpkg-query -S "$exe" 2>/dev/null | awk -F': ' -v p="$exe" '$2 == p { sub(/:.*/, "", $1); print $1 }' | head -n 1 || true)"
        echo "${cs:--} $exe"
    done | sort -u
}
# a csomag törlése a saját telepítő- és eltávolító szkriptjei nélkül (azok félrekerülnek: /var/backups/baninapro-dpkg)
csomag_torles_szkriptek_nelkul() {
    local p=$1 hova f
    hova="/var/backups/baninapro-dpkg/$p-$(date +%Y%m%d_%H%M%S)"
    install -d -m 700 "$hova"
    for f in "/var/lib/dpkg/info/$p".{preinst,postinst,prerm,postrm} "/var/lib/dpkg/info/$p":*.{preinst,postinst,prerm,postrm}; do
        if [[ -e $f ]]; then mv -f "$f" "$hova/"; fi
    done
    fut dpkg --purge --force-remove-reinstreq "$p"
}
vedett_csomag() {
    local m
    if [[ $1 == *"$(uname -r)"* ]]; then return 0; fi
    for m in "${VEDETT_CSOMAGOK[@]}"; do
        # shellcheck disable=SC2053  # szándékos mintaillesztés (csomagnév-minták)
        if [[ $1 == $m ]]; then return 0; fi
    done
    return 1
}
felbemaradt_lezaras() {
    local p futok hibas=() torolt=() maradt=()
    mapfile -t hibas < <(hibas_csomagok)
    (( ${#hibas[@]} )) || return 0
    apt_var
    for p in "${hibas[@]}"; do
        if [[ $p == anydesk || $p == anydesk-* ]]; then
            futok="$(anydesk_futo_csomagjai)"
            if [[ -n $futok ]] && ! grep -q "^$p " <<<"$futok"; then
                # a működő AnyDesk máshonnan fut: a félbemaradt csomag egy felesleges második példány
                AKT_MUVELET="a felesleges, félbemaradt $p csomag törlése"
                if csomag_torles_szkriptek_nelkul "$p"; then
                    ok "A félbemaradt, felesleges $p csomag törölve – a működő AnyDesk ($(awk '{ print $2 }' <<<"$futok" | paste -sd ' ' -)) érintetlen"
                else
                    maradt+=("$p")
                fi
                AKT_MUVELET=""
                continue
            fi
            maradt+=("$p")
            continue
        fi
        if vedett_csomag "$p"; then maradt+=("$p"); continue; fi
        AKT_MUVELET="félbemaradt telepítés lezárása: a(z) $p törlése"
        if fut dpkg --purge --force-remove-reinstreq "$p"; then torolt+=("$p"); else maradt+=("$p"); fi
        UTOLSO_PARANCS=""
    done
    AKT_MUVELET=""
    if (( ${#torolt[@]} )); then
        ok "Félbemaradt telepítés lezárva – törölve, a telepítőjével együtt: ${torolt[*]}"
    fi
    for p in "${maradt[@]}"; do
        [[ $FELBEMARADT_JELEZVE != *" $p "* ]] || continue
        FELBEMARADT_JELEZVE+="$p "
        if [[ $p == anydesk || $p == anydesk-* ]]; then
            if [[ -z $(anydesk_futo_csomagjai) ]]; then
                figy "A(z) $p telepítése félbemaradt, és az AnyDesk most nem fut – nem dönthető el, hogy ez a működő példány-e, ezért nem törlöm."
            else
                figy "A(z) $p telepítése a csomagkezelő szerint félbemaradt, de ez nem egy második példány: a működő AnyDesk éppen ebből a csomagból fut (a telepítője csak az utolsó lépésnél, az asztali menüpontnál állt le – ehhez az xdg-utils kell). Nem törlöm – kézzel lezárható: sudo apt-get install xdg-utils"
            fi
        elif vedett_csomag "$p"; then
            figy "A(z) $p telepítése félbemaradt – a rendszer vagy egy futó szolgáltatás része, ezért nem törlöm (kézzel: sudo dpkg --configure -a)."
        else
            figy "A(z) $p telepítése félbemaradt, és nem törölhető (más csomag függ tőle, vagy a törlése hibát jelzett – a naplóban látszik, miért)."
        fi
    done
}
# a csomagkezelő rendben van-e: nincs félbemaradt (megszakadt vagy be nem fejezett) csomagtelepítés
dpkg_rendben() {
    [[ -z $(hibas_csomagok) ]] && ! compgen -G '/var/lib/dpkg/updates/[0-9]*' >/dev/null
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
    # (félbemaradt telepítésnél nincs folytatás: az apt_ ilyenkor nem próbálja újra, a következő telepítés előtt pedig
    #  a felbemaradt_lezaras törli a félbemaradt csomagot, ha nem védett)
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

szabad_mb() { df -Pk / | awk 'NR == 2 { print int($4 / 1024) }'; }
hely_szoveg() {   # a szabad hely olvashatóan: 850 MB, 1,7 GB
    local m=${1:-$(szabad_mb)} t
    t=$(( (m * 10 + 512) / 1024 ))   # tized GB-ra kerekítve
    if (( m < 1024 )); then echo "$m MB"; else echo "$(( t / 10 )),$(( t % 10 )) GB"; fi
}
# helyfelszabadítás: letöltött csomagok, régi rendszernaplók, használaton kívüli Docker-képek és -gyorsítótár
# (a konténerekhez és a kötetekhez – az adatbázishoz – nem nyúl)
# A Docker válaszol-e – csak ha a szolgáltatása fut, és időkorláttal. Ha a pendrive nincs csatolva, a Docker nem
# indulhat el, a docker.socket viszont fogadja a kérést: egy sima docker-parancs ilyenkor a végtelenségig várna.
docker_valaszol() {
    command -v docker >/dev/null && systemctl is-active --quiet docker.service 2>/dev/null \
        && timeout "${1:-20}" docker info >/dev/null 2>&1
}
hely_felszabaditas() {
    AKT_MUVELET="hely felszabadítása"
    apt-get clean >/dev/null 2>&1 || true
    journalctl --vacuum-size=100M >/dev/null 2>&1 || true
    if docker_valaszol; then
        fut docker image prune -f || true
        fut docker builder prune -f || true
    fi
    AKT_MUVELET=""
}

# A BaninaPRO Docker-képei közül a hiányzók (ha a Docker nem válaszol, nem dönthető el – üres).
# Az alkalmazás képe akkor hiányzik, ha sem a kész kép, sem a PHP-alapkép nincs meg (abból pár MB-tal felépül).
hianyzo_kepek() {
    local k
    docker_valaszol || return 0
    for k in "$DB_KEP" "$PMA_KEP"; do
        docker image inspect "$k" >/dev/null 2>&1 || echo "$k"
    done
    if ! docker image inspect "$APP_KEP" >/dev/null 2>&1 && ! docker image inspect "$PHP_ALAPKEP" >/dev/null 2>&1; then
        echo "$PHP_ALAPKEP"
    fi
}
# a Docker tárhelyén (az USB adat-részén) szabad hely MB-ban
docker_szabad_mb() { df -Pk /var/lib/docker 2>/dev/null | awk 'NR == 2 { print int($4 / 1024) }'; }
# a képek első letöltése kb. 2–2,5 GB: ha hiányoznak, és a Docker tárhelyén nincs hozzájuk elég hely, inkább meg sem kezdi
kepek_helye_rendben() {
    local hiany=() szabad
    mapfile -t hiany < <(hianyzo_kepek)
    (( ${#hiany[@]} )) || return 0
    szabad="$(docker_szabad_mb)"
    if (( ${szabad:-0} < KEP_LETOLTES_MB )); then
        hiba "A BaninaPRO Docker-képei még nincsenek meg (${hiany[*]}). Az első letöltésük kb. 2–2,5 GB helyet kér a Docker tárhelyén (az USB-meghajtón), de csak $(hely_szoveg "${szabad:-0}") szabad – használj nagyobb pendrive-ot, vagy szabadíts fel rajta helyet, majd futtasd újra."
    fi
    info "A BaninaPRO Docker-képei közül még hiányzik: ${hiany[*]} – letöltöm (a Docker tárhelyén $(hely_szoveg "$szabad") szabad)."
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

# A script nem a BaninaPRO repóból fut (pl. csak ezt a fájlt töltötték le): letölti a nyilvános repót a felhasználó
# mappájába (~/BaninaPRO) – ha ott már van, frissíti –, és az ottani példánnyal folytatja.
repo_teljes() { [[ -f $REPO/docker-compose.yml && -f $REPO/docker/Dockerfile && -f $REPO/docker/config.php ]]; }
repo_letoltes() {
    local cel="$CEL_HOME/BaninaPRO" uj
    uj="$cel/SERVER SETUP AND UPDATE/szerver_beallitas_light.sh"
    info "A script nem a BaninaPRO mappájából fut – a repót letöltöm ide: $cel"
    if ! command -v git >/dev/null; then telepit git || hiba "A git nem telepíthető: $(apt_hibak)"; fi
    AKT_MUVELET="a BaninaPRO letöltése (git clone)"
    if [[ -d $cel/.git ]]; then
        fut felh git -C "$cel" pull --ff-only || true
    elif [[ -e $cel ]]; then
        hiba "A(z) $cel már létezik, de nem git-repó – nevezd át vagy töröld, majd futtasd újra."
    else
        ujraprobal 3 felh git -C "$CEL_HOME" clone "$REPO_URL" "$cel" || hiba "A repó nem tölthető le: $REPO_URL"
    fi
    AKT_MUVELET=""
    [[ -f $uj ]] || hiba "A letöltött repóban nincs meg a telepítő: $uj"
    ok "BaninaPRO letöltve: $cel – onnan folytatom."
    rm -rf "$TMPD"
    exec bash "$uj" "${ARGOK[@]}"
}

# ---- Egyéb segédek ------------------------------------------------------------
# parancs futtatása a cél felhasználó nevében (a repó az övé, a git is az ő nevében fut), kérdezés nélkül
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

# =============================================================================
#  Előkészítés
# =============================================================================
elofeltetelek() {
    AKT_LEPES="előfeltételek"
    cim "Előfeltételek ellenőrzése"
    local PRETTY_NAME="" ID="" szabad
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

    AKT_MUVELET="internetkapcsolat ellenőrzése"
    internet_van || hiba "Nincs internetkapcsolat (a github.com nem érhető el) – ellenőrizd a hálózatot, majd futtasd újra."
    AKT_MUVELET=""
    ok "Internetkapcsolat rendben"

    if ! repo_teljes; then
        # véletlenül törölt fájlok: vissza a gitből
        felh git -C "$REPO" checkout HEAD -- docker-compose.yml docker >/dev/null 2>&1 || true
    fi
    # ha a script nem a repóból fut (pl. csak ezt a fájlt töltötték le): letölti a repót, és onnan folytatja
    repo_teljes || repo_letoltes
    ok "BaninaPRO mappa: $REPO"

    # a light változat a már telepített Dockert használja (telepíteni nem telepíti)
    command -v docker >/dev/null \
        || hiba "A Docker nincs telepítve – a light változat a gépen már fent lévő Dockert használja. Docker nélküli géphez a teljes változat kell: sudo bash szerver_beallitas.sh"
    ok "Docker telepítve ($(docker --version 2>/dev/null | head -n 1 || true))"

    # kevés hely: előbb takarít (csomagfájlok, naplók, Docker-gyorsítótár), csak utána dönt
    szabad="$(szabad_mb)"
    if (( szabad < HELY_FIGY_MB )); then
        hely_felszabaditas
        szabad="$(szabad_mb)"
    fi
    if (( szabad < HELY_MIN_MB )); then
        hiba "Túl kevés a szabad hely a gép saját lemezén: $(hely_szoveg "$szabad") (legalább $HELY_MIN_MB MB kell, 1 GB ajánlott)."
    elif (( szabad < HELY_FIGY_MB )); then
        figy "Kevés a szabad hely a gép saját lemezén: $(hely_szoveg "$szabad") – a light változat lefut, de 1 GB ajánlott."
    else
        ok "Szabad hely a gép saját lemezén: $(hely_szoveg "$szabad") (a Docker és az adatbázis az USB-meghajtóra kerül)"
    fi

    if sema_biztosit; then
        ok "Adatbázis-séma megvan (sql/schema.sql)"
    else
        info "Az sql/schema.sql még nincs meg – a git pull hozza le (a BaninaPRO indítása előtt újra ellenőrzöm)."
    fi
}

# Minden kérdés az elején, utána már nem kell a géphez nyúlni. A light változat csak az e-mailről kérdez, és csak
# kérésre (sudo bash szerver_beallitas_light.sh --email) – az értesítések push-ként mennek.
kerdesek() {
    [[ -t 0 ]] || return 0
    (( EMAIL_KERDES )) || return 0
    cim "Kérdések az elején (utána már nem kell a géphez nyúlni)"
    email_kerdesek
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
        "lepes_rendszer|15|A szükséges eszközök (rendszerfrissítés nélkül)"
        "lepes_gepnev|3|Gépnév ($GEPNEV) és időzóna ($IDOZONA)"
        "lepes_usb|90|USB-meghajtó: rajta a Docker és az adatbázis, és a mentések másolatai (ha üres, formázza)"
        "lepes_docker|20|Docker – a meglévő Docker ellenőrzése: mindig fut, a géppel együtt indul"
        "lepes_halozat|5|Hálózat: a gép neve a belső hálózaton ($GEPNEV.local)"
        "lepes_energia|5|Energia: soha nem alszik el (az USB-eszközök sem), áramszünet után bekapcsol"
        "lepes_baninapro|240|BaninaPRO: konténerek és adatbázis – a belső hálózatról is elérhető"
        "lepes_mentes_cron|2|Éjszakai adatbázis-mentés (03:00) – másolat az USB-re és a gép saját lemezére"
        "lepes_ertesitesek|10|Push-értesítések a telefonra (ntfy): leállás, indulás, hibák, mentés, belépések"
        "lepes_orszem|5|Őrszem: ha valami leáll, magától helyreállítja, és értesít"
        "lepes_jelentes|10|Napi állapotjelentés ($JELENTES_IDO)"
        "lepes_ellenorzes|45|Végső ellenőrzés: oldal, API, adatbázis, hálózat, tárhely"
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
# firmware-frissítő, sem a hír- és kiadásfigyelő. A light változat maga sem frissíti a rendszert.
# Az őrszem 2 percenként ellenőrzi, hogy így maradjon.
AUTO_FRISSITO_IDOZITOK=(apt-daily.timer apt-daily-upgrade.timer fwupd-refresh.timer update-notifier-download.timer
    update-notifier-motd.timer motd-news.timer ua-timer.timer)
lepes_auto_frissites_ki() {
    local e
    # az apt saját beállítása: a periodikus munkák (lista-frissítés, letöltés, telepítés, takarítás) ki
    cat > /etc/apt/apt.conf.d/99baninapro-nincs-automatikus-frissites <<'EOF'
// BaninaPRO szerver: nincs automatikus frissítés – nem keres, nem tölt le, nem telepít (a szerver_beallitas.sh írta).
// A szerver_beallitas_light.sh sem frissíti a rendszert.
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
    ok "A rendszer magától nem frissül – és ez a script sem frissíti"
}

# A szükséges eszközök – csak ami hiányzik, az kerül fel. A rendszert NEM frissíti (a felhasználó kérése), a félbemaradt
# csomagtelepítést nem folytatja, és csomagot nem töröl; csak a letöltött csomagfájlokat (a telepített programok maradnak).
lepes_rendszer() {
    local hiany=()
    apt-get clean >/dev/null 2>&1 || true   # a letöltött telepítőfájlok (a telepített programok maradnak)
    felbemaradt_lezaras
    if ! dpkg_rendben; then
        info "Amíg félbemaradt csomagtelepítés van a gépen ($(hibas_csomagok | paste -sd ' ' - || true)), a script csomagot nem telepít."
    fi
    # (az USB-meghajtóhoz: fdisk – partícionálás, dosfstools – FAT32, e2fsprogs – ext4)
    mapfile -t hiany < <(hianyzo ca-certificates curl git cron psmisc fdisk dosfstools e2fsprogs)
    if (( ${#hiany[@]} )); then
        if ! dpkg_rendben; then
            hiba "Hiányzó csomagok: ${hiany[*]} – nem telepíthetők, mert félbemaradt csomagtelepítés van a gépen ($(hibas_csomagok | paste -sd ' ' - || true)), amit a script nem törölhet (védett, vagy más csomag függ tőle). Ha kézzel rendbe teszed (lásd a figyelmeztetést), futtasd újra."
        fi
        telepit_min "${hiany[@]}" || hiba "A hiányzó csomagok nem telepíthetők (${hiany[*]}): $(apt_hibak)"
    fi
    ok "A szükséges eszközök megvannak (curl, git, cron, fdisk, dosfstools…) – rendszerfrissítés nincs; szabad hely: $(hely_szoveg)"
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

# =============================================================================
#  USB-meghajtó: a Docker tárhelye (képek, konténerek, adatbázis) és a mentések másolatai
# =============================================================================
# Két rész, a címkéjük alapján megtalálva (a meghajtó neve – sdb, sdc – változhat, a címke nem):
#   BANINAPRO-ADAT (ext4): a /var/lib/docker és a /var/lib/containerd ide van befűzve (bind mount) – így a Docker
#     minden adata (képek, konténerek, kötetek: az adatbázis, az alkalmazás naplói és mentései) a pendrive-on van;
#   BANINAPRO (FAT32): a BaninaPRO-mentesek mappában minden adatbázis-mentés másolata – bármely gépen olvasható.
lepes_usb() {
    usb_keres
    if [[ -z $USB_ADAT_DEV ]]; then
        usb_elokeszit
        usb_keres
        [[ -n $USB_ADAT_DEV ]] || hiba "Az USB-meghajtó előkészítése után sem található a(z) $USB_ADAT_CIMKE rész."
    else
        ok "BaninaPRO USB-meghajtó: $USB_ADAT_DEV ($(usb_meghajto "$USB_ADAT_DEV"))"
    fi
    USB_ADAT_UUID="$(blkid -s UUID -o value "$USB_ADAT_DEV" 2>/dev/null || true)"
    [[ -n $USB_ADAT_UUID ]] || hiba "Az USB-meghajtó adat-részének ($USB_ADAT_DEV) nincs azonosítója (UUID)."
    USB_MENTES_UUID=""
    if [[ -n $USB_MENTES_DEV ]]; then USB_MENTES_UUID="$(blkid -s UUID -o value "$USB_MENTES_DEV" 2>/dev/null || true)"; fi
    # a segédprogram, az őrszem és a jelentés innen tudja, melyik a BaninaPRO meghajtója
    install -d -m 700 "$TITOK_MAPPA"
    printf '# BaninaPRO USB-meghajtó (a %s írja)\nUSB_ADAT_UUID=%s\nUSB_MENTES_UUID=%s\n' \
        "$(basename "$SCRIPT")" "$USB_ADAT_UUID" "$USB_MENTES_UUID" > "$TITOK_MAPPA/usb"
    chmod 600 "$TITOK_MAPPA/usb"
    usb_csatol
    rm -f /run/baninapro-usb-levalasztva   # (egy korábbi „baninapro-usb levalaszt” jelzője: a meghajtó újra használatban)
    usb_titkok_vissza 0
    docker_athelyezes
    usb_seged_iras
    usb_olvassel
    ok "USB-meghajtó: Docker és adatbázis – $(hely_szoveg "$(df -Pm "$USB_ADAT" | awk 'NR == 2 { print $4 }')") szabad$(
        if mountpoint -q "$USB_MENTES"; then echo "; mentések másolatai – $(hely_szoveg "$(df -Pm "$USB_MENTES" | awk 'NR == 2 { print $4 }')") szabad"; fi)"
}

# A BaninaPRO USB-meghajtó részei a címkéjük alapján. A FAT32 részt csak akkor fogadja el, ha ugyanazon a meghajtón
# van, mint az adat-rész (egy másik, véletlenül BANINAPRO nevű pendrive-hoz nem nyúl).
usb_keres() {
    local m
    udevadm settle >/dev/null 2>&1 || true
    USB_ADAT_DEV="$(blkid -c /dev/null -l -o device -t LABEL="$USB_ADAT_CIMKE" 2>/dev/null || true)"
    USB_MENTES_DEV=""
    [[ -n $USB_ADAT_DEV ]] || return 0
    m="$(blkid -c /dev/null -l -o device -t LABEL="$USB_MENTES_CIMKE" 2>/dev/null || true)"
    if [[ -n $m && $(lsblk -no PKNAME "$m" 2>/dev/null) == "$(lsblk -no PKNAME "$USB_ADAT_DEV" 2>/dev/null)" ]]; then
        USB_MENTES_DEV="$m"
    fi
}
usb_meghajto() {   # a teljes meghajtó gyártója, típusa és mérete (egy részéé is: a szülő lemezé)
    local d=$1 p
    p="$(lsblk -no PKNAME "$d" 2>/dev/null | head -n 1 || true)"
    if [[ -n $p ]]; then d="/dev/$p"; fi
    lsblk -dno VENDOR,MODEL,SIZE "$d" 2>/dev/null | xargs || true
}

# A meghajtó előkészítése – csak ha még nincs BaninaPRO-címkéjű rész a gépben. Csak USB-eszközt, csak teljes lemezt
# formáz, amiről semmi nincs csatolva és semmi nem használja (rendszerlemez, swap, LVM, RAID, titkosítás), és csak
# akkor, ha üres – a nem üres meghajtót csak a --usb-formazas kapcsolóval.
usb_elokeszit() {
    local d=$USB_LEMEZ meret fat_mib tartalom resz=() i regi tipus
    # a BaninaPRO meghajtója már be volt állítva, csak most nincs a gépben: egy másik meghajtóval az adatbázis üresen
    # indulna – ezt csak kifejezett kérésre (--usb-formazas)
    regi="$(sed -n 's/^USB_ADAT_UUID=//p' "$TITOK_MAPPA/usb" 2>/dev/null || true)"
    if [[ -n $regi ]] && (( ! USB_FORMAZAS )); then
        hiba "A BaninaPRO USB-meghajtója (rajta az adatbázissal) nincs a gépben – dugd vissza, majd futtasd újra. (Ha szándékosan egy új, üres meghajtóval kezdenél – az adatbázis üresen indulna! –: sudo bash $(basename "$SCRIPT") --usb-formazas)"
    fi
    [[ -b $d ]] || hiba "Nincs a gépben BaninaPRO USB-meghajtó, és a(z) $d sem található. Dugd be a pendrive-ot (ha más néven jelenik meg – lsblk –, add meg így: sudo bash $(basename "$SCRIPT") --usb=/dev/sdX), majd futtasd újra."
    tipus="$(lsblk -dno TYPE "$d" 2>/dev/null || true)"
    [[ $tipus == disk ]] || { (( USB_TESZT )) && [[ $tipus == loop ]]; } \
        || hiba "A(z) $d nem teljes lemez – a teljes meghajtót add meg (pl. --usb=/dev/sdb, nem /dev/sdb1)."
    if [[ $(lsblk -dno TRAN "$d" 2>/dev/null | xargs || true) != usb ]] && (( ! USB_TESZT )); then
        hiba "A(z) $d nem USB-eszköz ($(lsblk -dno TRAN "$d" 2>/dev/null | xargs || true)) – biztonsági okból nem formázom. Ha a pendrive más néven látszik (lsblk), add meg így: --usb=/dev/sdX"
    fi
    if lsblk -nrpo MOUNTPOINT "$d" 2>/dev/null | grep -q .; then
        hiba "A(z) $d (vagy egy része) csatolva van ($(lsblk -nrpo NAME,MOUNTPOINT "$d" | awk 'NF > 1' | paste -sd ' ' - || true)) – nem formázom. Válaszd le (sudo umount …), majd futtasd újra."
    fi
    if lsblk -nrpo TYPE "$d" 2>/dev/null | grep -qvxE "disk|part$( (( USB_TESZT )) && echo '|loop')"; then
        hiba "A(z) $d használatban van (LVM, RAID vagy titkosított kötet) – nem formázom."
    fi
    meret="$(blockdev --getsize64 "$d" 2>/dev/null || echo 0)"
    (( meret >= USB_MIN_GB * 1000000000 )) \
        || hiba "A(z) $d túl kicsi ($(lsblk -dno SIZE "$d" | xargs || true)) – legalább $USB_MIN_GB GB-os pendrive kell."
    if (( ! USB_FORMAZAS )); then
        tartalom="$(usb_tartalom "$d")"
        if [[ -n $tartalom ]]; then
            hiba "A(z) $d ($(usb_meghajto "$d")) nem üres – $tartalom. Adatot nem törlök: ha a pendrive tartalma törölhető, futtasd így: sudo bash $(basename "$SCRIPT") --usb-formazas"
        fi
    fi
    # a FAT32 rész a meghajtó tizede (1–16 GB) – a mentések kicsik; a többi az ext4 részé
    fat_mib=$(( meret / 1048576 / 10 ))
    if (( fat_mib < 1024 )); then fat_mib=1024; fi
    if (( fat_mib > 16384 )); then fat_mib=16384; fi
    info "Az USB-meghajtó előkészítése: $d ($(usb_meghajto "$d")) – két rész: $USB_MENTES_CIMKE (FAT32, $(hely_szoveg "$fat_mib"), a mentések másolatai) és $USB_ADAT_CIMKE (ext4, a többi: Docker és adatbázis)"
    AKT_MUVELET="az USB-meghajtó formázása"
    printf 'label: gpt\nsize=%sMiB, type=EBD0A0A2-B9E5-4433-87C0-68B6B72699C7, name="%s"\ntype=0FC63DAF-8483-4772-8E79-3D69D8477DE4, name="%s"\n' \
        "$fat_mib" "$USB_MENTES_CIMKE" "$USB_ADAT_CIMKE" > "$TMPD/particiok"
    fut wipefs -a -f "$d" || hiba "A(z) $d régi partíciós táblája nem törölhető."
    # (az sfdisk a bemenetét fájlból kapja: a háttérben futó parancs bemenete egyébként üres)
    fut sh -c 'exec sfdisk --wipe always --wipe-partitions always "$1" < "$2"' _ "$d" "$TMPD/particiok" \
        || hiba "A(z) $d nem partícionálható: $(tail -n 3 "$NAPLO" | paste -sd ' ' - || true)"
    for i in $(seq 1 20); do
        udevadm settle >/dev/null 2>&1 || true
        mapfile -t resz < <(lsblk -nrpo NAME,TYPE "$d" 2>/dev/null | awk '$2 == "part" { print $1 }' || true)
        if (( ${#resz[@]} >= 2 )) && [[ -b ${resz[0]} && -b ${resz[1]} ]]; then break; fi
        partx -u "$d" >/dev/null 2>&1 || true
        varj 1
    done
    (( ${#resz[@]} >= 2 )) || hiba "A(z) $d új részei nem jelentek meg."
    fut mkfs.vfat -F 32 -n "$USB_MENTES_CIMKE" "${resz[0]}" || hiba "A(z) ${resz[0]} nem formázható (FAT32)."
    fut mkfs.ext4 -F -q -m 1 -L "$USB_ADAT_CIMKE" "${resz[1]}" || hiba "A(z) ${resz[1]} nem formázható (ext4)."
    udevadm settle >/dev/null 2>&1 || true
    AKT_MUVELET=""
    ok "USB-meghajtó előkészítve: ${resz[0]} = $USB_MENTES_CIMKE (FAT32: mentések), ${resz[1]} = $USB_ADAT_CIMKE (ext4: Docker és adatbázis)"
}

# Mi van a meghajtón? Üres szöveg, ha semmi (a rendszerek rejtett mappáin kívül). A fájlrendszereit csak olvasásra
# csatolja; amit nem tud megnézni (ismeretlen, titkosított, vagy nincs hozzá meghajtóprogram), azt nem üresnek veszi.
USB_SZEMET='^(System Volume Information|\$RECYCLE\.BIN|RECYCLER|\.Trashes|\.Trash-[0-9]+|\.Spotlight-V100|\.fseventsd|\.TemporaryItems|\.DocumentRevisions-V100|\.DS_Store|\._.*|\.VolumeIcon\.icns|desktop\.ini|IndexerVolumeGuid|lost\+found)$'
usb_tartalom() {
    local d=$1 p e tipus lista db ki="" m="$TMPD/usb-nezo" reszek=()
    mkdir -p "$m"
    mapfile -t reszek < <(lsblk -nrpo NAME,TYPE "$d" 2>/dev/null | awk '$2 == "part" { print $1 }' || true)
    for p in "$d" "${reszek[@]}"; do
        tipus="$(blkid -p -s TYPE -o value "$p" 2>/dev/null || true)"
        [[ -n $tipus ]] || continue   # ezen a részen nincs fájlrendszer
        if mount -o ro "$p" "$m" >/dev/null 2>&1; then
            lista="" db=0
            shopt -s nullglob dotglob
            for e in "$m"/*; do
                e="${e##*/}"
                if [[ $e =~ $USB_SZEMET ]]; then continue; fi   # a rendszerek rejtett mappái, fájljai
                db=$(( db + 1 ))
                if (( db <= 6 )); then lista+="${lista:+, }$e"; fi
            done
            shopt -u nullglob dotglob
            umount "$m" >/dev/null 2>&1 || umount -l "$m" >/dev/null 2>&1 || true
            if (( db > 6 )); then lista+=" … (összesen $db)"; fi
            if (( db )); then ki+="${ki:+; }${p##*/} ($tipus): $lista"; fi
        else
            ki+="${ki:+; }${p##*/}: $tipus fájlrendszer, a tartalma nem nézhető meg"
        fi
    done
    printf '%s' "$ki"
}

# Csatolás: az /etc/fstab-ba a részek UUID-ja kerül (nem a nevük – az változhat); nofail: ha a meghajtó nincs a gépben,
# a gép akkor is elindul. Az üres csatolási pontok írásvédettek (chattr +i): a meghajtó nélkül semmi nem írhat beléjük.
usb_csatol() {
    local m kotes=0
    for m in "$USB_ADAT" "$USB_MENTES"; do
        if ! mountpoint -q "$m"; then
            mkdir -p "$m"
            chattr +i "$m" 2>/dev/null || true
        fi
    done
    # ha a Docker tárhelye már az USB-n van, a befűzés sorai maradnak
    if grep -qE "^$USB_ADAT/docker[[:space:]]+/var/lib/docker[[:space:]]" /etc/fstab; then kotes=1; fi
    usb_fstab_iras "$kotes"
    if ! usb_csatolva "$USB_ADAT" "$USB_ADAT_UUID"; then
        umount -l "$USB_ADAT" >/dev/null 2>&1 || true   # egy korábbi, közben leválott csatolás maradéka
        mount "$USB_ADAT" || hiba "Az USB-meghajtó adat-része ($USB_ADAT_DEV) nem csatolható: $(tail -n 2 "$NAPLO" | paste -sd ' ' - || true)"
    fi
    install -d -m 710 "$USB_ADAT/docker"
    install -d -m 711 "$USB_ADAT/containerd"
    install -d -m 700 "$USB_ADAT/baninapro"
    if [[ -z $USB_MENTES_UUID ]]; then
        figy "Az USB-meghajtón nincs $USB_MENTES_CIMKE (FAT32) rész – a mentések hordozható másolata nem készül (az adatbázis és a mentések az adat-részen így is megvannak)."
        return 0
    fi
    if ! usb_csatolva "$USB_MENTES" "$USB_MENTES_UUID"; then
        umount -l "$USB_MENTES" >/dev/null 2>&1 || true
        mount "$USB_MENTES" || figy "Az USB-meghajtó mentés-része ($USB_MENTES_DEV) nem csatolható – a mentések másolata most nem készül."
    fi
    if mountpoint -q "$USB_MENTES"; then mkdir -p "$USB_MENTES_MAPPA"; fi
}
usb_csatolva() {   # $1 = csatolási pont, $2 = a várt UUID: valóban az a fájlrendszer van-e ott, és él-e az eszköz
    local forras
    forras="$(findmnt -rno SOURCE --mountpoint "$1" 2>/dev/null | tail -n 1 || true)"
    [[ -n $forras && -b $forras && $(blkid -s UUID -o value "$forras" 2>/dev/null || true) == "$2" ]]
}

# Az /etc/fstab BaninaPRO-blokkja (minden futáskor újraírva; az első módosítás előtti állapot: /etc/fstab.baninapro-elott).
# A blokkon kívüli, ugyanerre a meghajtóra vagy csatolási pontra mutató sorokat kikapcsolja (megjegyzéssé teszi).
usb_fstab_iras() {   # $1 = 1: a Docker tárhelyének befűzése (bind mount) is
    local uj="$TMPD/fstab" m
    [[ -f /etc/fstab.baninapro-elott ]] || cp -p /etc/fstab /etc/fstab.baninapro-elott
    sed '/^# BaninaPRO USB-meghajtó – eleje/,/^# BaninaPRO USB-meghajtó – vége/d' /etc/fstab \
        | awk -v a="UUID=$USB_ADAT_UUID" -v b="UUID=${USB_MENTES_UUID:-nincs}" -v m1="$USB_ADAT" -v m2="$USB_MENTES" \
            '$0 !~ /^[[:space:]]*#/ && ($1 == a || $1 == b || $2 == m1 || $2 == m2) { $0 = "# (a BaninaPRO kikapcsolta) " $0 } { print }' > "$uj"
    {
        printf '# BaninaPRO USB-meghajtó – eleje (a %s írja minden futáskor, kézzel ne módosítsd)\n' "$(basename "$SCRIPT")"
        printf 'UUID=%s %s ext4 defaults,noatime,nofail,x-systemd.device-timeout=20s 0 2\n' "$USB_ADAT_UUID" "$USB_ADAT"
        if [[ -n $USB_MENTES_UUID ]]; then
            printf 'UUID=%s %s vfat defaults,noatime,nofail,flush,uid=0,gid=0,fmask=0133,dmask=0022,utf8,x-systemd.device-timeout=20s 0 2\n' \
                "$USB_MENTES_UUID" "$USB_MENTES"
        fi
        if (( $1 )); then
            for m in "${DOCKER_MAPPAK[@]}"; do
                printf '%s/%s /var/lib/%s none bind,nofail,x-systemd.requires-mounts-for=%s 0 0\n' "$USB_ADAT" "$m" "$m" "$USB_ADAT"
            done
        fi
        printf '# BaninaPRO USB-meghajtó – vége\n'
    } >> "$uj"
    if ! cmp -s "$uj" /etc/fstab; then
        cat "$uj" > /etc/fstab
        systemctl daemon-reload || true
    fi
}

# A szerver adatbázis-jelszavai és az értesítési csatorna az USB-n is (csak a root olvashatja; az e-mail-postafiók
# jelszava nem kerül rá): ha a meghajtó egy új szerverre kerül, ott az adatbázis a régi jelszavakkal nyílik, és az
# értesítések ugyanoda mennek.
usb_titkok_vissza() {   # az USB-ről a gépre: $1 = 0 – csak ami a gépen hiányzik; 1 – minden (a meghajtó egy másik szerverről jött)
    local hely="$USB_ADAT/baninapro" f
    mountpoint -q "$USB_ADAT" || return 0
    install -d -m 700 "$TITOK_MAPPA"
    for f in titkok ntfy; do
        [[ -s $hely/$f ]] || continue
        if [[ -s $TITOK_MAPPA/$f ]] && { (( ! $1 )) || cmp -s "$hely/$f" "$TITOK_MAPPA/$f"; }; then continue; fi
        if [[ -s $TITOK_MAPPA/$f ]]; then cp -p "$TITOK_MAPPA/$f" "$TITOK_MAPPA/$f.$(date +%Y%m%d_%H%M%S).regi"; fi
        install -m 600 "$hely/$f" "$TITOK_MAPPA/$f"
        ok "A(z) $TITOK_MAPPA/$f az USB-meghajtóról (az adatbázisa ehhez tartozik)"
    done
}
usb_titkok_ment() {     # a gépről az USB-re (ami változott)
    local hely="$USB_ADAT/baninapro" f
    mountpoint -q "$USB_ADAT" || return 0
    install -d -m 700 "$hely"
    for f in titkok ntfy; do
        if [[ -s $TITOK_MAPPA/$f ]] && ! cmp -s "$TITOK_MAPPA/$f" "$hely/$f"; then install -m 600 "$TITOK_MAPPA/$f" "$hely/$f"; fi
    done
}

# A Docker tárhelye (/var/lib/docker és /var/lib/containerd: képek, konténerek, kötetek – köztük az adatbázis) az USB
# adat-részére kerül, befűzéssel (bind mount): a Docker és a containerd beállításai nem változnak.
#  - A meglévő adatokat átmásolja, a Docker indulása után ellenőrzi (ugyanazok a képek, kötetek és konténerek), és csak
#    ezután törli a gép saját lemezéről – ha bármi nem stimmel, mindent visszaállít az eredetire.
#  - Ha az USB-n már vannak (befejezett áthelyezésből származó) Docker-adatok – a meghajtó egy másik szerverről jött –,
#    azok érvényesek: a gép saját Docker-adatai félrekerülnek, nem törlődnek.
DOCKER_ATHELYEZVE_JEL="baninapro/docker-athelyezve"   # az USB adat-részén: az áthelyezés befejeződött
docker_athelyezes() {
    local m elotte="" utana="" idegen=0 felre=() datum gyoker
    if docker_usb_n_van; then
        docker_usb_vedelem
        ok "A Docker tárhelye az USB-meghajtón van ($USB_ADAT)"
        return 0
    fi
    gyoker=""
    if docker_valaszol 30; then gyoker="$(timeout 30 docker info -f '{{.DockerRootDir}}' 2>/dev/null || true)"; fi
    if [[ -n $gyoker && $gyoker != /var/lib/docker ]]; then
        hiba "A Docker egyedi helyen tárolja az adatait ($gyoker) – a light változat csak az alapértelmezett /var/lib/docker-t helyezi át az USB-re."
    fi
    if grep -qsE '^[[:space:]]*root[[:space:]]*=' /etc/containerd/config.toml \
        && ! grep -qsE '^[[:space:]]*root[[:space:]]*=[[:space:]]*"/var/lib/containerd"' /etc/containerd/config.toml; then
        hiba "A containerd egyedi helyen tárolja az adatait (/etc/containerd/config.toml: root) – nem helyezem át."
    fi
    for m in "${DOCKER_MAPPAK[@]}"; do
        if mountpoint -q "/var/lib/$m" && [[ $(stat -c %d "/var/lib/$m") != "$(stat -c %d "$USB_ADAT")" ]]; then
            hiba "A /var/lib/$m már egy másik meghajtóra van csatolva ($(findmnt -no SOURCE "/var/lib/$m" || true)) – nem helyezem át."
        fi
    done
    if [[ -f $USB_ADAT/$DOCKER_ATHELYEZVE_JEL ]]; then
        idegen=1
        info "Az USB-meghajtón már vannak BaninaPRO Docker-adatok (az adatbázissal együtt) – ezekkel indul a Docker."
    else
        # egy korábbi, félbeszakadt másolás maradéka: az eredeti a gép saját lemezén van, elölről kezdi
        for m in "${DOCKER_MAPPAK[@]}"; do find "$USB_ADAT/$m" -mindepth 1 -delete 2>/dev/null || true; done
        info "A Docker tárhelyének áthelyezése az USB-meghajtóra (a konténerek erre az időre leállnak)…"
    fi
    if docker_valaszol 60; then elotte="$(docker_leltar)"; fi
    docker_leallitas || hiba "A Docker nem állítható le – a tárhelye most nem helyezhető át (a gép újraindítása után futtasd újra)."
    datum="$(date +%Y%m%d_%H%M%S)"
    for m in "${DOCKER_MAPPAK[@]}"; do
        chattr -i "/var/lib/$m" 2>/dev/null || true
        if (( ! idegen )) && [[ -n $(ls -A "/var/lib/$m" 2>/dev/null) ]]; then
            AKT_MUVELET="a Docker adatainak másolása az USB-re (/var/lib/$m, $(du -sh "/var/lib/$m" 2>/dev/null | cut -f1 || true))"
            if ! fut cp -a "/var/lib/$m/." "$USB_ADAT/$m/"; then
                AKT_MUVELET=""
                docker_athelyezes_vissza "$datum" "$idegen"
                hiba "A Docker adatainak másolása az USB-re nem sikerült (betelt a pendrive?) – mindent visszaállítottam."
            fi
            AKT_MUVELET=""
        fi
        if [[ -n $(ls -A "/var/lib/$m" 2>/dev/null) ]]; then
            mv "/var/lib/$m" "/var/lib/$m.athelyezes-$datum"
            felre+=("/var/lib/$m.athelyezes-$datum")
        else
            rmdir "/var/lib/$m" 2>/dev/null || true
        fi
        mkdir -p "/var/lib/$m"
        if [[ $m == docker ]]; then chmod 710 "/var/lib/$m"; else chmod 711 "/var/lib/$m"; fi
        chattr +i "/var/lib/$m" 2>/dev/null || true   # a meghajtó nélkül semmi nem írhat bele
    done
    usb_fstab_iras 1
    for m in "${DOCKER_MAPPAK[@]}"; do
        if ! mount "/var/lib/$m"; then
            docker_athelyezes_vissza "$datum" "$idegen"
            hiba "A(z) /var/lib/$m nem fűzhető be az USB-ről – mindent visszaállítottam."
        fi
    done
    docker_usb_vedelem
    if (( idegen )); then
        # a meghajtó konténerei a Docker indulásakor maguktól elindulnak: az alkalmazás beállítófájlja (és hozzá a
        # meghajtó adatbázisának jelszavai) már előtte legyen meg
        usb_titkok_vissza 1
        titkok_biztosit
        szerver_config
    fi
    if ! docker_inditas; then
        docker_athelyezes_vissza "$datum" "$idegen"
        hiba "A Docker nem indult el az USB-n lévő tárhellyel – mindent visszaállítottam."
    fi
    if (( ! idegen )) && [[ -n $elotte ]]; then
        utana="$(docker_leltar)"
        if [[ $utana != "$elotte" ]]; then
            printf 'Áthelyezés előtt:\n%s\nUtána:\n%s\n' "$elotte" "$utana"
            docker_athelyezes_vissza "$datum" "$idegen"
            hiba "Az áthelyezés után nem ugyanazok a Docker-képek, -kötetek és -konténerek látszanak – mindent visszaállítottam (részletek a naplóban)."
        fi
    fi
    if (( idegen )); then
        for m in "${felre[@]}"; do
            figy "A gép saját, korábbi Docker-adatai félretéve: $m ($(du -sh "$m" 2>/dev/null | cut -f1 || true)) – ha nem kellenek, törölhetők: sudo rm -rf $m"
        done
        ok "A Docker az USB-meghajtón lévő adatokkal fut (a meghajtó egy korábbi szerverről jött)"
    else
        date '+%Y-%m-%d %H:%M:%S' > "$USB_ADAT/$DOCKER_ATHELYEZVE_JEL"
        AKT_MUVELET="a gép saját lemezén maradt példány törlése"
        if (( ${#felre[@]} )); then fut rm -rf "${felre[@]}" || figy "A régi Docker-adatok nem törölhetők: ${felre[*]}"; fi
        AKT_MUVELET=""
        ok "A Docker tárhelye az USB-meghajtóra költözött – a gép saját lemezén $(hely_szoveg) szabad"
    fi
}
docker_usb_n_van() {   # a /var/lib/docker és a /var/lib/containerd valóban az USB adat-részéről van-e befűzve
    local m
    usb_csatolva "$USB_ADAT" "$USB_ADAT_UUID" || return 1
    for m in "${DOCKER_MAPPAK[@]}"; do
        [[ $(stat -c %d:%i "/var/lib/$m" 2>/dev/null || true) == "$(stat -c %d:%i "$USB_ADAT/$m" 2>/dev/null || true)" ]] || return 1
    done
}
docker_leltar() {   # a Docker képei, kötetei és konténerei – az áthelyezés előtti és utáni állapot összevetéséhez
    { timeout 60 docker image ls -aq --no-trunc; timeout 60 docker volume ls -q; timeout 60 docker ps -aq --no-trunc; } 2>/dev/null | sort || true
}
# A Docker és a containerd csak az USB-meghajtóval indulhat: nélküle a gép saját, kicsi lemezére töltené le a képeket
docker_usb_vedelem() {
    local e d valt=0
    for e in containerd docker; do
        d="/etc/systemd/system/$e.service.d"
        mkdir -p "$d"
        printf '# BaninaPRO szerver: a Docker tárhelye az USB-meghajtón van – nélküle nem indul (a %s írta)\n[Unit]\nRequiresMountsFor=/var/lib/docker /var/lib/containerd\n' \
            "$(basename "$SCRIPT")" > "$d/50-baninapro-usb.conf.uj"
        if cmp -s "$d/50-baninapro-usb.conf.uj" "$d/50-baninapro-usb.conf"; then
            rm -f "$d/50-baninapro-usb.conf.uj"
        else
            mv -f "$d/50-baninapro-usb.conf.uj" "$d/50-baninapro-usb.conf"
            valt=1
        fi
    done
    if (( valt )); then systemctl daemon-reload || true; fi
}
docker_leallitas() {   # a Docker és a containerd leállítása (a konténerek is leállnak); 1, ha nem álltak le
    local i
    AKT_MUVELET="a Docker leállítása"
    fut systemctl stop docker.socket docker.service containerd.service || true
    for (( i = 0; i < 30; i++ )); do
        if ! pgrep -x dockerd >/dev/null && ! pgrep -x containerd >/dev/null; then break; fi
        varj 1
    done
    AKT_MUVELET=""
    if pgrep -x dockerd >/dev/null || pgrep -x containerd >/dev/null; then return 1; fi
    # a konténerek esetleg megmaradt csatolásai (rendes leállás után nincs ilyen)
    findmnt -rno TARGET 2>/dev/null | grep -E '^/var/lib/(docker|containerd)/' | sort -r \
        | while read -r m; do umount "$m" 2>/dev/null || umount -l "$m" 2>/dev/null || true; done || true
    return 0
}
docker_athelyezes_vissza() {   # $1 = a félretett mappák dátuma, $2 = 1: az USB-n lévő adatok nem a mieink (maradnak)
    local m
    figy "A Docker tárhelyének áthelyezése nem sikerült – visszaállítom az eredeti állapotot."
    docker_leallitas || true
    for m in "${DOCKER_MAPPAK[@]}"; do
        if mountpoint -q "/var/lib/$m"; then umount "/var/lib/$m" 2>/dev/null || umount -l "/var/lib/$m" 2>/dev/null || true; fi
    done
    usb_fstab_iras 0
    rm -f /etc/systemd/system/containerd.service.d/50-baninapro-usb.conf /etc/systemd/system/docker.service.d/50-baninapro-usb.conf
    systemctl daemon-reload || true
    for m in "${DOCKER_MAPPAK[@]}"; do
        chattr -i "/var/lib/$m" 2>/dev/null || true
        if [[ -e /var/lib/$m.athelyezes-$1 ]]; then
            rmdir "/var/lib/$m" 2>/dev/null || true
            mv "/var/lib/$m.athelyezes-$1" "/var/lib/$m"
        fi
        if (( ! $2 )); then find "$USB_ADAT/$m" -mindepth 1 -delete 2>/dev/null || true; fi
    done
    docker_inditas || true
}

# A segédprogram: az USB-meghajtó csatolásának ellenőrzése (és ha kell, visszacsatolása), a mentések másolása,
# állapot a napi jelentéshez, biztonságos leválasztás
usb_seged_iras() {
    {
        printf '#!/bin/bash\n# BaninaPRO USB-meghajtó – a %s írta, kézzel ne módosítsd (minden futása újraírja).\n' "$(basename "$SCRIPT")"
        printf 'USB_ADAT=%q\nUSB_MENTES=%q\nUSB_MENTES_MAPPA=%q\nBELSO_MENTES=%q\nKOTET=%q\nBEALLITAS=%q\nKEVES_HELY_MB=%q\n' \
            "$USB_ADAT" "$USB_MENTES" "$USB_MENTES_MAPPA" "$BELSO_MENTES" "$ADATOK_KOTET" "$TITOK_MAPPA/usb" "$KEVES_HELY_MB"
        cat <<'EOF'
#   baninapro-usb ellenoriz   csatolva van-e az USB-meghajtó (és rajta a Docker tárhelye); ha leválott, de a gépben van,
#                             visszacsatolja, és újraindítja a Dockert. Kilépési kód: 0 rendben, 3 most csatolta vissza,
#                             4 szándékosan leválasztva (kihúzásra vár), 1 nincs a gépben, 2 nem csatolható
#   baninapro-usb tukor       a mentések másolatai: az USB FAT32 részére (BaninaPRO-mentesek) mind, a gép saját lemezére
#                             (/var/backups/baninapro) a 2 legújabb – 1, ha valamelyik nem sikerült
#   baninapro-usb allapot     állapot a napi jelentéshez (a „!”-lel kezdődő sor probléma)
#   baninapro-usb levalaszt   biztonságos eltávolítás: a BaninaPRO (Docker) leáll, a meghajtó leválik – utána kihúzható.
#                             Visszadugva az őrszem 2 percen belül visszacsatolja, és elindítja a BaninaPRO-t.
#   baninapro-usb csatol      a leválasztott (de ki nem húzott) meghajtó visszacsatolása most
set -u
export LC_ALL=C.UTF-8 PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
ADAT_UUID="$(sed -n 's/^USB_ADAT_UUID=//p' "$BEALLITAS" 2>/dev/null)"
MENTES_UUID="$(sed -n 's/^USB_MENTES_UUID=//p' "$BEALLITAS" 2>/dev/null)"
LEVALASZTVA=/run/baninapro-usb-levalasztva
MAPPAK=(docker containerd)

csatolva() {   # $1 = csatolási pont, $2 = UUID: valóban az a fájlrendszer van-e ott, és él-e az eszköz
    local forras
    [[ -n $2 ]] || return 1
    forras="$(findmnt -rno SOURCE --mountpoint "$1" 2>/dev/null | tail -n 1)"
    [[ -n $forras && -b $forras && $(blkid -s UUID -o value "$forras" 2>/dev/null) == "$2" ]]
}
docker_rajta() {   # a Docker tárhelye valóban az USB-ről van befűzve
    local m
    for m in "${MAPPAK[@]}"; do
        [[ $(stat -c %d:%i "/var/lib/$m" 2>/dev/null) == "$(stat -c %d:%i "$USB_ADAT/$m" 2>/dev/null)" ]] || return 1
    done
}
mentes_csatol() {   # a FAT32 rész (a mentések másolatai): ha leválott, visszacsatolja
    [[ -n $MENTES_UUID ]] || return 1
    csatolva "$USB_MENTES" "$MENTES_UUID" && return 0
    [[ -e /dev/disk/by-uuid/$MENTES_UUID ]] || return 1
    umount -l "$USB_MENTES" >/dev/null 2>&1
    mount "$USB_MENTES" >/dev/null 2>&1 && mkdir -p "$USB_MENTES_MAPPA"
}
ellenoriz() {
    local m
    if [[ -f $LEVALASZTVA ]]; then
        if [[ -e /dev/disk/by-uuid/$ADAT_UUID ]]; then echo "Az USB-meghajtó le van választva, kihúzásra vár."; return 4; fi
        rm -f "$LEVALASZTVA"   # kihúzták – ha visszadugják, újra csatolódik
    fi
    if csatolva "$USB_ADAT" "$ADAT_UUID" && docker_rajta; then mentes_csatol; return 0; fi
    if [[ ! -e /dev/disk/by-uuid/$ADAT_UUID ]]; then echo "Az USB-meghajtó nincs a gépben."; return 1; fi
    # a gépben van, de nincs (jól) csatolva – pl. leválott, vagy kihúzták és visszadugták: a Docker leáll, minden újra csatolódik
    echo "Az USB-meghajtó nincs (jól) csatolva – visszacsatolom."
    systemctl stop docker.socket docker.service containerd.service >/dev/null 2>&1
    for m in "${MAPPAK[@]}"; do umount -l "/var/lib/$m" >/dev/null 2>&1; done
    umount -l "$USB_ADAT" >/dev/null 2>&1
    if ! mount "$USB_ADAT" || ! mount /var/lib/containerd || ! mount /var/lib/docker; then
        echo "Az USB-meghajtó nem csatolható."
        return 2
    fi
    mentes_csatol
    systemctl start containerd.service docker.service >/dev/null 2>&1
    echo "Az USB-meghajtó visszacsatolva, a Docker újraindult."
    return 3
}
tukor() {   # a mentések másolatai; az utolsó kiírt sor az összefoglaló
    local d f nev cel meret regi legujabb uj=0 gond=() n
    if ! systemctl is-active --quiet docker.service; then echo "Másolat: a Docker nem fut – most nem készül."; return 1; fi
    d="$(timeout 30 docker volume inspect -f '{{.Mountpoint}}' "$KOTET" 2>/dev/null)/DBBCKP"
    if [[ ! -d $d ]] || ! compgen -G "$d/*.sql" >/dev/null; then echo "Másolat: még nincs adatbázis-mentés."; return 0; fi
    # 1) az USB FAT32 része: a legújabbaktól visszafelé mindet, ami még nincs ott. Ha betelt, a másolatok közül a
    #    legrégebbiek mennek (egy régebbi mentés kedvéért újabbat sosem töröl). A teljes sor az adat-részen is megvan.
    if mentes_csatol; then
        mkdir -p "$USB_MENTES_MAPPA"
        while IFS= read -r f; do
            nev="${f##*/}" cel="$USB_MENTES_MAPPA/${f##*/}"
            if [[ -f $cel && $(stat -c %s "$cel") == "$(stat -c %s "$f")" ]]; then continue; fi
            meret=$(( $(stat -c %s "$f") / 1048576 + 1 ))
            while (( $(df -Pm "$USB_MENTES" | awk 'NR == 2 { print $4 }') < meret + 20 )); do
                regi="$(ls -1 "$USB_MENTES_MAPPA"/*.sql 2>/dev/null | head -n 1)"
                if [[ -z $regi || ! ${regi##*/} < $nev ]]; then break 2; fi
                rm -f "$regi"
            done
            if cp --preserve=timestamps "$f" "$USB_MENTES_MAPPA/.$nev.tmp" 2>/dev/null && mv -f "$USB_MENTES_MAPPA/.$nev.tmp" "$cel"; then
                uj=$(( uj + 1 ))
            else
                rm -f "$USB_MENTES_MAPPA/.$nev.tmp"
                gond+=("a(z) $nev nem másolható az USB-re")
                break
            fi
        done < <(ls -1r "$d"/*.sql 2>/dev/null)
        sync -f "$USB_MENTES_MAPPA/." 2>/dev/null || sync
        legujabb="$(ls -1 "$d"/*.sql 2>/dev/null | tail -n 1)"
        if [[ ! -f $USB_MENTES_MAPPA/${legujabb##*/} ]] && (( ${#gond[@]} == 0 )); then
            gond+=("a legújabb mentés nem fér el az USB mentés-részén")
        fi
    else
        gond+=("az USB-meghajtó mentés-része nincs csatolva")
    fi
    # 2) a legújabb mentés a gép saját lemezére is (a 2 legújabb marad) – ha a pendrive tönkremenne
    legujabb="$(ls -1 "$d"/*.sql 2>/dev/null | tail -n 1)"
    if [[ -n $legujabb && ! -f $BELSO_MENTES/${legujabb##*/} ]]; then
        install -d -m 700 "$BELSO_MENTES"
        meret=$(( $(stat -c %s "$legujabb") / 1048576 + 1 ))
        if (( $(df -Pm / | awk 'NR == 2 { print $4 }') - meret > KEVES_HELY_MB )) \
            && cp --preserve=timestamps "$legujabb" "$BELSO_MENTES/.masolas.tmp" \
            && mv -f "$BELSO_MENTES/.masolas.tmp" "$BELSO_MENTES/${legujabb##*/}"; then
            chmod 600 "$BELSO_MENTES/${legujabb##*/}"
            ls -1 "$BELSO_MENTES"/*.sql 2>/dev/null | head -n -2 | xargs -r rm -f
        else
            rm -f "$BELSO_MENTES/.masolas.tmp"
            gond+=("a gép saját lemezén nincs hely a legutóbbi mentés másolatának")
        fi
    fi
    n="$(ls -1 "$USB_MENTES_MAPPA"/*.sql 2>/dev/null | wc -l)"
    if (( ${#gond[@]} )); then
        printf 'Másolat – HIBA: %s (az USB-n %s mentés)\n' "$(IFS=';'; echo "${gond[*]}" | sed 's/;/; /g')" "$n"
        return 1
    fi
    printf 'Másolat: az USB-n (BaninaPRO-mentesek: %s mentés%s) és a gép saját lemezén is\n' "$n" "$( (( uj )) && echo ", most $uj új")"
}
allapot() {
    local n uj
    if [[ -f $LEVALASZTVA ]]; then echo "!Az USB-meghajtó le van választva (sudo baninapro-usb levalaszt) – a BaninaPRO nem fut"; fi
    if csatolva "$USB_ADAT" "$ADAT_UUID"; then
        echo "USB adat-rész (Docker, adatbázis): $(df -hP "$USB_ADAT" | awk 'NR == 2 { print $4 " szabad / " $2 }')"
        docker_rajta || echo "!A Docker tárhelye nincs az USB-meghajtóról befűzve"
        if (( $(df -Pm "$USB_ADAT" | awk 'NR == 2 { print $4 }') < 1024 )); then
            echo "!Kevés a szabad hely az USB-meghajtón: $(df -hP "$USB_ADAT" | awk 'NR == 2 { print $4 }')"
        fi
    else
        echo "!Az USB-meghajtó adat-része nincs csatolva – a BaninaPRO nem tud futni"
    fi
    if mentes_csatol; then
        n="$(ls -1 "$USB_MENTES_MAPPA"/*.sql 2>/dev/null | wc -l)"
        uj="$(ls -1 "$USB_MENTES_MAPPA"/*.sql 2>/dev/null | tail -n 1)"
        uj="${uj##*/}"
        echo "USB mentés-rész (BaninaPRO-mentesek): $n mentés, a legújabb: ${uj:-–} – $(df -hP "$USB_MENTES" | awk 'NR == 2 { print $4 }') szabad"
    else
        echo "!Az USB-meghajtó mentés-része nincs csatolva – a mentések hordozható másolata nem készül"
    fi
    uj="$(ls -1 "$BELSO_MENTES"/*.sql 2>/dev/null | tail -n 1)"
    uj="${uj##*/}"
    echo "A gép saját lemezén ($BELSO_MENTES): ${uj:-még nincs mentés-másolat}"
}
levalaszt() {
    local m
    exec 9>/run/baninapro-orszem.lock   # közben az őrszem (és a telepítő) ne avatkozzon be
    if ! flock -n 9; then
        echo "Várakozás, amíg az őrszem vagy a telepítő befejezi (legfeljebb 5 perc)…"
        flock -w 300 9 || true
    fi
    echo "Az utolsó mentések átmásolása, a BaninaPRO leállítása…"
    tukor >/dev/null 2>&1
    touch "$LEVALASZTVA"
    systemctl stop docker.socket docker.service containerd.service
    sync
    for m in "${MAPPAK[@]}"; do umount "/var/lib/$m" 2>/dev/null || umount -l "/var/lib/$m" 2>/dev/null; done
    umount "$USB_MENTES" 2>/dev/null || umount -l "$USB_MENTES" 2>/dev/null
    if umount "$USB_ADAT" 2>/dev/null; then
        echo "Kész – az USB-meghajtó most kihúzható."
    else
        umount -l "$USB_ADAT" 2>/dev/null
        sync
        echo "Leválasztva – várj fél percet, utána húzd ki."
    fi
    echo "Visszadugva az őrszem 2 percen belül visszacsatolja, és elindítja a BaninaPRO-t (azonnal: sudo baninapro-usb csatol)."
}
csatol() {
    local r
    rm -f "$LEVALASZTVA"
    ellenoriz
    r=$?
    if (( r == 0 || r == 3 )); then echo "Az USB-meghajtó csatolva – a BaninaPRO pár percen belül elérhető."; return 0; fi
    return "$r"
}

case "${1:-}" in
    ellenoriz) ellenoriz ;;
    tukor)     tukor ;;
    allapot)   allapot ;;
    levalaszt) levalaszt ;;
    csatol)    csatol ;;
    *) echo "Használat: baninapro-usb ellenoriz | tukor | allapot | levalaszt | csatol"; exit 1 ;;
esac
EOF
    } > "$USB_SEGED.uj"
    chmod 755 "$USB_SEGED.uj"
    mv -f "$USB_SEGED.uj" "$USB_SEGED"
}

# Leírás a FAT32 részen: aki a pendrive-ot egy másik gépbe dugja, ebből tudja, mi van rajta, és mit kezdjen vele
usb_olvassel() {
    mountpoint -q "$USB_MENTES" || return 0
    cat > "$USB_MENTES/OLVASSEL.txt.uj" <<'EOF'
BaninaPRO – adatbázis-mentések

Ez a pendrive a BaninaPRO szerveré. Két része van:

  BANINAPRO (ez a rész)
    A BaninaPRO-mentesek mappában az adatbázis minden mentésének másolata (.sql fájlok).
    Bármely Windows, Mac vagy Linux gépen megnyitható. A fájlnév a mentés ideje: ÉÉÉÉHHNN_ÓÓPP.sql –
    a legfrissebb a legnagyobb dátumú. A szerver minden éjjel 03:00-kor ment, és ide is átmásolja.

  BANINAPRO-ADAT
    A szerveren futó adatbázis és a Docker. Windows és Mac nem tudja olvasni –
    ha a gép felajánlja, hogy „inicializálja” vagy „formázza”, NE engedd!

Visszaállítás egy mentésből: a BaninaPRO-ban Admin → Adatbázis-mentések: a .sql fájl feltöltése, majd visszaállítás.

Ha a szerver tönkrement: dugd ezt a pendrive-ot az új szerverbe, töltsd le a BaninaPRO-t
(git clone https://github.com/Sarokin/BaninaPRO.git ~/BaninaPRO), és futtasd:
    cd ~/BaninaPRO/"SERVER SETUP AND UPDATE"
    sudo bash szerver_beallitas_light.sh
A BaninaPRO az összes adatával visszajön (az adatbázis jelszavai is a pendrive-on vannak).

Biztonságos eltávolítás a szerverből:  sudo baninapro-usb levalaszt  – utána kihúzható.
Visszadugva a szerver 2 percen belül magától visszacsatolja, és elindítja a BaninaPRO-t.
EOF
    if cmp -s "$USB_MENTES/OLVASSEL.txt.uj" "$USB_MENTES/OLVASSEL.txt"; then
        rm -f "$USB_MENTES/OLVASSEL.txt.uj"
    else
        mv -f "$USB_MENTES/OLVASSEL.txt.uj" "$USB_MENTES/OLVASSEL.txt"
    fi
}

# A light változat a gépen már fent lévő Dockert használja: nem telepít és nem cserél Docker-csomagot, csak
# ellenőrzi, hogy fut-e és a géppel együtt indul-e, korlátozza a naplói méretét, és ha kell, pótolja a „docker compose”-t.
lepes_docker() {
    command -v docker >/dev/null \
        || hiba "A Docker nincs telepítve – a light változat a gépen már fent lévő Dockert használja (Docker nélküli géphez: sudo bash szerver_beallitas.sh)."
    docker_daemon_json
    if ! docker_inditas; then
        torol
        journalctl -u docker -n 15 --no-pager 2>/dev/null | sed 's/^/    | /' >&3 || true
        hiba "A Docker nem indul el (systemctl status docker)."
    fi
    if getent group docker >/dev/null && ! id -nG "$CEL_FELH" | grep -qw docker; then
        usermod -aG docker "$CEL_FELH" || figy "A(z) $CEL_FELH felhasználó nem került be a docker csoportba."
    fi
    compose_biztosit
    ok "Docker $(docker version -f '{{.Server.Version}}') fut és a géppel együtt indul; Compose $(docker compose version --short)"
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
        if timeout 30 docker info >/dev/null 2>&1; then AKT_MUVELET=""; return 0; fi
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
    timeout 30 docker info >/dev/null 2>&1
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
    local cel=/usr/local/lib/docker/cli-plugins/docker-compose arch p=docker-compose-v2
    if compose_eleg_uj; then return 0; fi
    info "A „docker compose” bővítmény hiányzik vagy túl régi – pótolom…"
    # a Docker saját csomagjaihoz illő bővítmény (ha már fent van, de régi, az apt frissíti)
    if van_csomag docker-ce; then p=docker-compose-plugin; fi
    if [[ -n $(elerheto "$p") ]]; then telepit_min "$p" || true; fi
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

# Hálózat: a gép neve a belső hálózaton (az SSH-hoz és a távoli eléréshez nem nyúl)
lepes_halozat() {
    # a gép neve a belső hálózaton: baninapro.local – akkor is megtalálható, ha a router más IP-címet ad neki
    telepit_opcionalis avahi-daemon
    if systemctl enable --now avahi-daemon >/dev/null 2>&1; then
        ok "A gép a belső hálózaton $GEPNEV.local néven is elérhető"
    else
        figy "A $GEPNEV.local név nem kapcsolható be (avahi-daemon) – a gép az IP-címével érhető el."
    fi
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
    # az USB-eszközök se aludjanak el (rajtuk van az adatbázis): a most csatlakoztatottak és a később bedugottak sem
    cat > /etc/udev/rules.d/50-baninapro-usb-ebren.rules <<'EOF'
# BaninaPRO szerver: az USB-eszközök ne aludjanak el – az adatbázis USB-meghajtón van (a szerver_beallitas_light.sh írta)
ACTION=="add", SUBSYSTEM=="usb", TEST=="power/control", ATTR{power/control}="on"
EOF
    udevadm control --reload-rules >/dev/null 2>&1 || true
    for f in /sys/bus/usb/devices/*/power/control; do
        if [[ -w $f ]]; then echo on > "$f" 2>/dev/null || true; fi
    done
    ok "Az USB-eszközök energiatakarékos alvása kikapcsolva (az adatbázis USB-meghajtón van)"
    # (a képernyővédőhöz és az asztal energiabeállításaihoz a light változat nem nyúl)

    # 2) áramszünet után magától bekapcsol: ez a BIOS beállítása – ahol a gép engedi, innen állítja be
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
    if timeout 30 docker volume inspect "$DB_KOTET" >/dev/null 2>&1; then
        ELSO_INDITAS=0
        ok "Meglévő adatbázis – az adatok megmaradnak"
    else
        ELSO_INDITAS=1
        ok "Adatbázis-séma megvan – első induláskor létrejönnek a táblák és a kezdő admin"
    fi

    # 2) a szerver saját adatbázis-jelszavai és az alkalmazás szerverre szabott beállítófájlja (nincsenek a gitben)
    titkok_biztosit
    szerver_config

    # 3) szerver-kiegészítés a docker-compose.yml mellé (a compose magától betölti): belső hálózati elérés,
    #    jelszavas phpMyAdmin, a szerver saját jelszavai, rögzített projektnév (így a kötetek neve sem változik),
    #    és gyorsítás: ami csak lehet, a memóriában (lásd gyorsitas_iras)
    gyorsitas_iras
    cat > "$REPO/docker-compose.override.yml" <<EOF
# BaninaPRO szerver – a "SERVER SETUP AND UPDATE/$(basename "$SCRIPT")" írja minden futáskor, kézzel ne módosítsd.
# A docker compose a docker-compose.yml mellé automatikusan betölti. A szerveren:
#  - a BaninaPRO ($APP_PORT) és a phpMyAdmin ($PMA_PORT) a belső hálózatról is elérhető, a MySQL csak a gépen belülről;
#  - a phpMyAdmin jelszót kér (nincs automatikus root-belépés), az adatbázis a szerver saját jelszavait használja;
#  - az alkalmazás beállítófájlja: $TITOK_MAPPA/config.php (a szerver jelszava, hibakijelzés kikapcsolva);
#  - gyorsítás – ami csak lehet, a memóriában: a munkamenetek és az ideiglenes fájlok (tmpfs), a PHP lefordított kódja
#    (opcache, $TITOK_MAPPA/php-gyorsitas.ini), a MySQL gyorsítótára ($DB_PUFFER_MB MB – az egész adatbázis elfér
#    benne); a MySQL a naplóját másodpercenként írja a pendrive-ra (nem minden mentésnél), a binlog ki van kapcsolva.
#    (Hirtelen áramszünetnél az utolsó legfeljebb 1 másodperc mentései elveszhetnek – szabályos leállásnál semmi.)
name: $PROJEKT
services:
  app:
    ports: !override
      - "$APP_PORT:80"
    volumes:
      - $TITOK_MAPPA/config.php:/var/www/html/includes/config.php:ro
      - $TITOK_MAPPA/php-gyorsitas.ini:/usr/local/etc/php/conf.d/zz-baninapro-gyorsitas.ini:ro
      - type: tmpfs
        target: /tmp
        tmpfs:
          size: 268435456
  db:
    # (a docker-compose.yml két beállítása is kell: a command egészében felülíródik)
    command: ["--character-set-server=utf8mb4", "--collation-server=utf8mb4_unicode_ci",
              "--innodb-buffer-pool-size=${DB_PUFFER_MB}M", "--innodb-flush-log-at-trx-commit=2",
              "--innodb-log-buffer-size=32M", "--skip-log-bin"]
    environment:
      MYSQL_ROOT_PASSWORD: "$DB_ROOT_JELSZO"
      MYSQL_PASSWORD: "$DB_APP_JELSZO"
    volumes:
      - type: tmpfs
        target: /tmp
        tmpfs:
          size: 536870912
  phpmyadmin:
    ports: !override
      - "$PMA_PORT:80"
    environment: !override
      PMA_HOST: db
      UPLOAD_LIMIT: 64M
    volumes:
      - type: tmpfs
        target: /tmp
        tmpfs:
          size: 134217728
EOF
    chown "$CEL_FELH:" "$REPO/docker-compose.override.yml"
    chmod 600 "$REPO/docker-compose.override.yml"
    # a git ne lássa új fájlnak (helyi kizárás, a .gitignore-hoz nem nyúl)
    if [[ -d $REPO/.git ]] && ! grep -qx 'docker-compose.override.yml' "$REPO/.git/info/exclude" 2>/dev/null; then
        install -d -o "$CEL_FELH" -g "$(id -gn "$CEL_FELH")" "$REPO/.git/info"
        echo 'docker-compose.override.yml' >> "$REPO/.git/info/exclude"
        chown "$CEL_FELH:" "$REPO/.git/info/exclude"
    fi
    ok "Szerver-beállítás: BaninaPRO a $APP_PORT-as, phpMyAdmin a $PMA_PORT-es porton – a belső hálózatról is (jelszóval)"

    # 4) a 80-as port legyen szabad (ha a BaninaPRO már fut rajta, az rendben van)
    port80_felszabadit

    # 5) építés és indítás – átmeneti hálózati hibánál újrapróbálja. A Docker tárhelye (az USB-meghajtó) helyén
    #    kevés helynél előbb a Docker-gyorsítótár megy (a konténerekhez és az adatbázishoz nem nyúl), a hiányzó képek
    #    letöltése pedig csak akkor indul, ha elfér.
    kepek_helye_rendben
    if (( $(docker_szabad_mb) < ALAPKEP_FRISSITES_MB )); then hely_felszabaditas; fi
    AKT_MUVELET="az alkalmazás építése (PHP 8.3 + Apache)"
    if (( $(docker_szabad_mb) >= ALAPKEP_FRISSITES_MB )) && ujraprobal 3 dc build --pull app; then
        ok "Az alkalmazás képe elkészült (a PHP-alapkép is frissítve)"
    else
        # kevés a hely (vagy az alapkép frissítése nem megy): a meglévő PHP-alapképből épül – ez csak pár MB
        ujraprobal 2 dc build app || hiba "Az alkalmazás képe nem épült fel – a napló végén látszik, miért."
        ok "Az alkalmazás képe elkészült (a meglévő PHP-alapképből – annak frissítése $(hely_szoveg "$ALAPKEP_FRISSITES_MB") szabad hely fölött fut)"
    fi
    AKT_MUVELET="a PHP-gyorsítótár beállítása"
    opcache_betoltes
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
    # a PHP a beállításait induláskor olvassa: ha a gyorsítás beállítása változott, az alkalmazás újraindul
    if (( GYORSITAS_VALTOZOTT )); then
        AKT_MUVELET="az alkalmazás újraindítása (új PHP-beállítás)"
        fut docker restart "$APP_KONTENER" || true
    fi
    AKT_MUVELET=""
    dc ps || true
    ok "Konténerek elindítva"
    fut docker image prune -f || true   # a felülírt régi képek (a használtakhoz és az adatokhoz nem nyúl)

    # 6) adatbázis: a szerver jelszavai, a táblák és a kezdő admin – ami hiányzik, azt pótolja
    if ! adatbazis_rendbe; then
        kontener_naplok
        hiba "Az adatbázis nem készült el (a MySQL nem indult el, vagy a táblák nem hozhatók létre)."
    fi
}

# A szerver saját, erős adatbázis-jelszavai: a repóban lévő alapjelszavak nyilvánosak, a belső hálózatról elérhető
# phpMyAdmin mellett nem maradhatnak. Egyszer készülnek, utána mindig ugyanazok (csak a root olvashatja).
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
    # az alkalmazás (www-data, 33) olvassa a konténerben
    chown root:33 "$f.uj"
    chmod 640 "$f.uj"
    # ha a fájl hiányzott, amikor a Docker a konténert indította, a helyén üres mappát hozott létre – az nem kell
    if [[ -d $f ]]; then rm -rf -- "$f"; fi
    mv -f "$f.uj" "$f"
}

# Gyorsítás – ami csak lehet, a memóriában. A PHP-gyorsítótár (opcache) a lefordított kódot tartja a memóriában; a
# kódváltozást 2 mp-en belül észreveszi (git pull után sem marad régi kód). A munkamenetek a /tmp-ben vannak, ami a
# konténerben memória (tmpfs). A MySQL gyorsítótára a gép memóriájának negyede (256 MB – 4 GB).
DB_PUFFER_MB=256 GYORSITAS_INI="" GYORSITAS_VALTOZOTT=0
gyorsitas_iras() {
    local mem
    mem=$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo 2>/dev/null || echo 1024)
    DB_PUFFER_MB=$(( ${mem:-1024} / 4 ))
    if (( DB_PUFFER_MB < 256 )); then DB_PUFFER_MB=256; fi
    if (( DB_PUFFER_MB > 4096 )); then DB_PUFFER_MB=4096; fi
    install -d -m 700 "$TITOK_MAPPA"
    # (a fájlt az építés után írja ki: akkor derül ki, hogy a PHP-kép magától betölti-e az opcache-t – opcache_betoltes)
    GYORSITAS_INI="$(cat <<'EOF'
; BaninaPRO szerver – gyorsítás (a szerver_beallitas_light.sh írta, minden futása újraírja)
; PHP-gyorsítótár: a lefordított kód a memóriában – a kódváltozást 2 másodpercen belül észreveszi
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
; a munkamenetek és a feltöltések ideiglenes fájljai: a /tmp a konténerben memória (tmpfs)
session.save_path=/tmp
upload_tmp_dir=/tmp
; a fájlútvonalak gyorsítótára
realpath_cache_size=4096k
realpath_cache_ttl=600
EOF
)"
    # a konténer indulásához a fájlnak léteznie kell (az első futáskor az építés előtt még üres)
    if [[ ! -f $TITOK_MAPPA/php-gyorsitas.ini ]]; then
        printf '%s\n' "$GYORSITAS_INI" > "$TITOK_MAPPA/php-gyorsitas.ini"
        chmod 644 "$TITOK_MAPPA/php-gyorsitas.ini"
    fi
}
# A PHP-gyorsítótárat (opcache) a hivatalos PHP-kép újabb változatai maguktól betöltik – kétszer betöltve a PHP minden
# indításkor figyelmeztetést írna ki (és az elrontaná a parancssori ellenőrzéseket). Ezért az elkészült képben megnézi:
# ha a kép magától nem tölti be, a szerver beállítófájlja tölti be. Ha a fájl változott, az alkalmazás újraindul.
opcache_betoltes() {
    local m f="$TITOK_MAPPA/php-gyorsitas.ini" uj
    uj="$GYORSITAS_INI"
    m="$(timeout 120 docker run --rm --entrypoint php "$APP_KEP" -m 2>/dev/null || true)"
    if [[ $m == *"[PHP Modules]"* && $m != *"Zend OPcache"* ]]; then
        uj+=$'\n'"; ez a PHP-kép magától nem tölti be – a szerver tölti be"$'\n'"zend_extension=opcache"
    fi
    if [[ $(cat "$f" 2>/dev/null) != "$uj" ]]; then
        printf '%s\n' "$uj" > "$f"
        chmod 644 "$f"
        GYORSITAS_VALTOZOTT=1
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
# a phpMyAdmin-felhasználó még a kezdőjelszóval lép-e be (ha igen, az összegzés figyelmeztet, hogy változtasd meg)
pma_kezdojelszo_el() {
    docker exec "$DB_KONTENER" mysql -N -B -h 127.0.0.1 -u"$PMA_FELH" -p"$PMA_KEZDO_JELSZO" -e 'SELECT 1' >/dev/null 2>&1
}
# Régebbi, a nyilvános alapjelszóval (docker-compose.yml) létrehozott adatbázis: a root-jelszó átállítása a szerver
# saját jelszavára (a konténer a MYSQL_ROOT_PASSWORD-ben már ezt kapja, de az csak üres adatbázisnál érvényesül)
db_root_atallitas() {
    docker exec -i "$DB_KONTENER" sh -c 'exec mysql -uroot -p"$1"' _ "$ALAP_DB_ROOT" <<EOF || return 1
ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED BY '$DB_ROOT_JELSZO';
ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '$DB_ROOT_JELSZO';
FLUSH PRIVILEGES;
EOF
    db_sql -e 'SELECT 1' >/dev/null 2>&1 || return 1
    ok "Adatbázis: a nyilvános alapjelszó helyett a szerver saját root-jelszava él"
}

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
    # a root-jelszó: egy régebbi, a nyilvános alapjelszóval létrehozott adatbázisnál a szerver saját jelszavára állítja
    if ! db_sql -e 'SELECT 1' >/dev/null 2>&1 && ! db_root_atallitas; then
        AKT_MUVELET=""
        return 1
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
    # a phpMyAdmin-felhasználó (teljes jog a BaninaPRO-adatbázishoz): csak ha még nincs – a jelszavát később nem írja felül
    printf "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';\nGRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'%%';\n" \
        "$PMA_FELH" "$PMA_KEZDO_JELSZO" "${d:-baninapr_DATA}" "$PMA_FELH" | db_sql || true
    AKT_MUVELET=""
    ok "Adatbázis rendben: ${tablak:-0} tábla, ${felhasznalok:-0} felhasználó"
}

lepes_mentes_cron() {
    systemctl enable --now cron || true
    # a mentést egy kis burkoló futtatja: naplóz, és az eredményről push-értesítést küld
    {
        printf '#!/bin/bash\n# BaninaPRO éjszakai adatbázis-mentés – a %s írta, kézzel ne módosítsd.\n' "$(basename "$SCRIPT")"
        printf 'APP_KONTENER=%q\nERTESITO=%q\nUSB_SEGED=%q\n' "$APP_KONTENER" "$ERTESITO" "$USB_SEGED"
        cat <<'EOF'
# A mentés után a másolatai: az USB-meghajtó FAT32 részére (bármely gépen olvasható) és a gép saját lemezére.
NAPLO=/var/log/baninapro-mentes.log
kimenet="$(timeout 1800 docker exec "$APP_KONTENER" php -q cron_mentes.php 2>&1)"
rc=$?
printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "${kimenet//$'\n'/ | }" >> "$NAPLO"
if (( rc == 0 )) && [[ $kimenet == OK* ]]; then
    masolat="$("$USB_SEGED" tukor 2>&1)"
    mrc=$?
    masolat="$(tail -n 1 <<<"$masolat")"
    printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$masolat" >> "$NAPLO"
    if (( mrc == 0 )); then
        "$ERTESITO" -p 2 -t floppy_disk "Éjszakai mentés kész" "$(tail -n 1 <<<"$kimenet") · $masolat" >/dev/null 2>&1 || true
    else
        "$ERTESITO" -p 4 -t warning,floppy_disk "Éjszakai mentés kész – a másolata NEM" "$(tail -n 1 <<<"$kimenet") · $masolat" >/dev/null 2>&1 || true
    fi
else
    "$ERTESITO" -p 4 -t x,floppy_disk "Az éjszakai mentés NEM sikerült" "Kilépési kód: $rc – $(tail -n 3 <<<"$kimenet")" >/dev/null 2>&1 || true
fi
exit "$rc"
EOF
    } > "$MENTO.uj"
    chmod 755 "$MENTO.uj"
    mv -f "$MENTO.uj" "$MENTO"
    cat > /etc/cron.d/baninapro <<EOF
# BaninaPRO – napi teljes adatbázis-mentés, mint az éles cron (a szerver_beallitas.sh írta).
# A mentések a Docker-kötetben vannak; lista: docker exec $APP_KONTENER php -q cron_mentes.php lista
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
$MENTES_CRON root $MENTO
EOF
    chmod 644 /etc/cron.d/baninapro
    ok "Éjszakai adatbázis-mentés: minden nap 03:00 – másolat az USB-re ($USB_MENTES_MAPPA) és a gép saját lemezére ($BELSO_MENTES), az eredményről push-értesítés"
}

# ---- Push-értesítések (ntfy) ----------------------------------------------------
lepes_ertesitesek() {
    local f="$TITOK_MAPPA/ntfy" leiras="$CEL_HOME/BaninaPRO-ertesitesek.txt"
    install -d -m 700 "$TITOK_MAPPA"
    # a titkos csatorna: egyszer készül, utána mindig ugyanaz (aki ismeri, olvashatja az értesítéseket)
    if ! grep -qE '^NTFY_CSATORNA=baninapro-[a-z0-9]{16,}$' "$f" 2>/dev/null; then
        printf '# BaninaPRO szerver – push-értesítések (ntfy); a csatorna neve titkos, mint egy jelszó\nNTFY_SZERVER=%s\nNTFY_CSATORNA=baninapro-%s\n' \
            "$NTFY_SZERVER" "$(veletlen_kod 24)" > "$f"
    fi
    chmod 600 "$f"
    NTFY_CSATORNA="$(sed -n 's/^NTFY_CSATORNA=//p' "$f")"
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
            "Ez a próba-értesítés: a BaninaPRO szerver ($GEPNEV) mostantól ide jelez – leállás, újraindulás, áramszünet, hibák és helyreállás, Docker, éjszakai mentés, napi jelentés ($JELENTES_IDO), belépések."; then
            echo 'NTFY_PROBA_KESZ=1' >> "$f"
            ok "Próba-értesítés elküldve"
        else
            figy "A próba-értesítés most nem ment el (nincs internet?) – sorba állt, az őrszem később elküldi."
        fi
    fi

    # a feliratkozás leírása a felhasználó mappájában is (az asztalhoz a light változat nem nyúl; nem kötelező:
    # ha nem sikerül, csak figyelmeztet)
    if cat > "$leiras" <<EOF
BaninaPRO szerver – értesítések a telefonra (ntfy)

1. Telepítsd az ingyenes „ntfy” alkalmazást (iPhone: App Store, Android: Google Play).
2. Az alkalmazásban:  +  (Subscribe to topic)  →  Topic:  $NTFY_CSATORNA  →  Subscribe
   (a szerver az alapértelmezett ntfy.sh – nem kell átírni; az értesítéseket engedélyezd)
3. Kész: ide érkezik minden értesítés – áramszünet / újraindulás / leállás, hibák és helyreállás (BaninaPRO,
   Docker, cron, konténerek, tárhely), az éjszakai mentés eredménye, a napi jelentés ($JELENTES_IDO),
   a telepítő (frissítés) eredménye, belépések és kilépések.

Böngészőben is olvasható: $NTFY_SZERVER/$NTFY_CSATORNA
A csatorna neve olyan, mint egy jelszó: aki ismeri, olvashatja az értesítéseket – ne add ki.
EOF
    then
        chown "$CEL_FELH:" "$leiras" || true
        chmod 600 "$leiras" || true
        ok "Push-értesítések: ntfy alkalmazás → + → $NTFY_CSATORNA (a leírás: $leiras)"
    else
        figy "A feliratkozás leírása nem készült el ($leiras) – a csatorna: $NTFY_CSATORNA (az összegzésben is benne van)."
    fi
}
veletlen_kod() { tr -dc 'a-z0-9' </dev/urandom 2>/dev/null | head -c "$1" || true; }

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
        printf 'REPO=%q\nAPP_KONTENER=%q\nDB_KONTENER=%q\nAPP_PORT=%q\nJELENTO=%q\nERTESITO=%q\nKEVES_HELY_MB=%q\nUSB_SEGED=%q\n' \
            "$REPO" "$APP_KONTENER" "$DB_KONTENER" "$APP_PORT" "$JELENTO" "$ERTESITO" "$KEVES_HELY_MB" "$USB_SEGED"
        printf 'AUTO_FRISSITO_IDOZITOK=(%s)\n' "${AUTO_FRISSITO_IDOZITOK[*]}"
        cat <<'EOF'
# 2 percenként: életjel és a sorban álló értesítések elküldése; az USB-meghajtó (rajta a Docker és az adatbázis: ha
# leválott, visszacsatolja; ha nincs a gépben, szól, és nem próbálkozik tovább); a részek ellenőrzése (Docker, cron,
# hogy az automatikus frissítés ki maradjon, konténerek, tárhely, frissítés utáni újraindítás) – minden változásról
# push-értesítés –, végül a BaninaPRO elérhetősége.
# Ha nem érhető el, lépcsőzetesen helyreállítja: a hiányzó / leállt konténerek indítása → a konténerek
# újraindítása → a Docker újraindítása (legfeljebb félóránként) → ha 1 órán át sem sikerül, a gép újraindítása
# (legfeljebb 6 óránként). Közben értesít, és szól, ha helyreállt.
NAPLO=/var/log/baninapro-orszem.log
ALLAPOT=/var/lib/baninapro-orszem
mkdir -p "$ALLAPOT"
# egyszerre csak egy fusson; amíg a telepítő dolgozik (ő is ezt a zárat fogja), nem avatkozik be
exec 9>/run/baninapro-orszem.lock
flock -n 9 || exit 0

naplo() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$NAPLO"; }
ert() { "$ERTESITO" "$@" >> "$NAPLO" 2>&1 || true; }   # push-értesítés (ha nincs internet: sorba áll)
dc() { (cd "$REPO" && timeout 600 docker compose "$@") >> "$NAPLO" 2>&1; }
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
szabad_mb() { df -Pk / | awk 'NR == 2 { print int($4 / 1024) }'; }
# a felesleg törlése: letöltött csomagok, rendszernaplók, használaton kívüli Docker-képek és -gyorsítótár
# (a konténerekhez és a kötetekhez – az adatokhoz – nem nyúl)
takarit() {
    timeout 300 docker image prune -f >> "$NAPLO" 2>&1
    timeout 300 docker builder prune -f >> "$NAPLO" 2>&1
    journalctl --vacuum-size=100M >> "$NAPLO" 2>&1
    apt-get clean
}
helyreallt() {
    naplo "Helyreállt: $1"
    ert -p 3 -t white_check_mark "A BaninaPRO újra elérhető" "Helyreállt $1 (kb. $(( $(mp_ota "$ALLAPOT/hiba_ota") / 60 )) perc kiesés után)."
    if [[ -f $ALLAPOT/riasztva ]]; then
        rm -f "$ALLAPOT/riasztva"
        "$JELENTO" riasztas "A BaninaPRO újra elérhető ($1)." >> "$NAPLO" 2>&1 || true
    fi
    rm -f "$ALLAPOT/hiba_ota"
    exit 0
}

# a napló ne nőjön a végtelenségig
if [[ -f $NAPLO ]] && (( $(stat -c %s "$NAPLO") > 5000000 )); then mv -f "$NAPLO" "$NAPLO.1"; fi

# életjel (egy áramszünet hosszát ebből becsüli az induláskori értesítés) és a korábban el nem küldött értesítések
"$ERTESITO" --eletjel >/dev/null 2>&1 || true
"$ERTESITO" --sorbol >/dev/null 2>&1 || true

# --- az USB-meghajtó: rajta van a Docker tárhelye és az adatbázis – nélküle semmi más nem segítene ---
if [[ -x $USB_SEGED ]]; then
    u="$("$USB_SEGED" ellenoriz 2>&1)"
    ur=$?
    if [[ -n $u ]]; then naplo "${u//$'\n'/ – }"; fi   # (rendben esetén nem ír semmit)
    case $ur in
        0)  if valtozott usb ok; then ert -p 3 -t white_check_mark "Az USB-meghajtó rendben" "Az USB-meghajtó csatolva van – a BaninaPRO adatbázisa újra elérhető."; fi ;;
        3)  echo ok > "$ALLAPOT/allapot-usb"
            ert -p 4 -t warning "Az USB-meghajtó újracsatolva" "Leválott (vagy kihúzták és visszadugták) – az őrszem visszacsatolta, és újraindította a Dockert."
            var_rendben 120 || true ;;   # a konténerek induljanak el, mielőtt az elérhetőséget nézi
        4)  if valtozott usb levalasztva; then ert -p 3 -t eject "Az USB-meghajtó leválasztva" "Most kihúzható. Visszadugva az őrszem 2 percen belül visszacsatolja, és elindítja a BaninaPRO-t (azonnal: sudo baninapro-usb csatol)."; fi
            exit 0 ;;
        *)  if valtozott usb hiba; then ert -p 5 -t rotating_light "Az USB-meghajtó nincs a gépben" "Rajta van a BaninaPRO adatbázisa és a Docker – a BaninaPRO most nem fut. Dugd vissza: az őrszem 2 percen belül magától visszacsatolja, és elindítja."; fi
            naplo "Az USB-meghajtó nincs a gépben (vagy nem csatolható) – a többi helyreállítás nélküle nem segítene"
            exit 0 ;;
    esac
fi

# --- a részek: minden változásról értesítés ---
# Docker
docker_fut=1
if timeout 30 docker info >/dev/null 2>&1; then
    if valtozott docker ok; then ert -p 3 -t white_check_mark "A Docker újra fut" "A Docker szolgáltatás ismét működik."; fi
else
    naplo "A Docker nem válaszol – indítás"
    systemctl reset-failed containerd docker >/dev/null 2>&1
    systemctl restart containerd docker >> "$NAPLO" 2>&1
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
    systemctl restart "$1" >> "$NAPLO" 2>&1
    sleep 5
    if systemctl is-active --quiet "$1"; then
        ert -p 4 -t warning "$2 leállt" "$2 nem futott – újraindítottam, most már fut."
        echo ok > "$ALLAPOT/allapot-$1"
    elif valtozott "$1" hiba; then
        ert -p 4 -t warning "$2 nem fut" "$2 leállt, és újraindítás után sem indult el – $3."
    fi
}
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
# konténerek: fut-e, és újraindította-e a Docker (összeomlás után)
if (( docker_fut )); then
    for k in "$APP_KONTENER" "$DB_KONTENER" baninapro-phpmyadmin; do
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
# tárhely (MB): kevés helynél előbb a felesleg törlése, csak ha az sem segít, akkor értesít
szabad=$(szabad_mb)
if (( szabad < KEVES_HELY_MB )); then
    naplo "Kevés a szabad hely ($szabad MB) – a felesleg törlése"
    takarit
    szabad=$(szabad_mb)
fi
if (( szabad < KEVES_HELY_MB )); then
    if valtozott tarhely hiba; then ert -p 4 -t warning "Kevés a szabad hely" "Takarítás után is csak $szabad MB szabad a lemezen – ha elfogy, az adatbázis nem tud írni."; fi
elif valtozott tarhely ok; then
    ert -p 3 -t white_check_mark "Van elég szabad hely" "Szabad hely a lemezen: $szabad MB."
fi
# rendszerfrissítés után újraindítás kellene (a gép magától nem indul újra)
if [[ -f /var/run/reboot-required ]]; then
    if valtozott ujrainditas_kell hiba; then ert -p 2 -t information_source "Újraindítás ajánlott" "Rendszerfrissítés után a gép újraindítása szükséges – alkalmas időben: sudo reboot"; fi
else
    valtozott ujrainditas_kell ok || true
fi

# a mentések másolatai (az USB FAT32 részére és a gép saját lemezére) – a kézzel készült mentéseké is. A naplóba csak
# a hiba kerül (a rendben lefutó másolás nem beavatkozás – a napi jelentés az őrszem beavatkozásait sorolja).
if [[ -x $USB_SEGED ]]; then
    m="$("$USB_SEGED" tukor 2>&1)"
    mr=$?
    m="$(tail -n 1 <<<"$m")"
    if (( mr == 0 )); then
        if valtozott usb-masolat ok; then ert -p 3 -t white_check_mark "A mentések másolata újra rendben" "$m"; fi
    else
        naplo "$m"
        if valtozott usb-masolat hiba; then ert -p 4 -t warning "A mentések másolata nem készül" "$m"; fi
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

# kevés hely: a felesleg törlése
if (( $(szabad_mb) < 2048 )); then
    naplo "Kevés a szabad hely – a felesleg törlése"
    takarit
fi
# 1) a Docker fusson
if ! timeout 30 docker info >/dev/null 2>&1; then
    naplo "A Docker nem válaszol – indítás"
    systemctl reset-failed containerd docker >/dev/null 2>&1
    systemctl restart containerd docker >> "$NAPLO" 2>&1
    sleep 10
fi
# 2) a hiányzó vagy leállt konténerek indítása
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
    systemctl restart containerd docker >> "$NAPLO" 2>&1
    sleep 15
    dc up -d --remove-orphans
    var_rendben 240 && helyreallt "a Docker újraindítása után"
fi
# 5) riasztás e-mailben – ha már 10 perce nem jó (legfeljebb 6 óránként)
if (( $(mp_ota "$ALLAPOT/hiba_ota") > 600 && $(mp_ota "$ALLAPOT/riasztva") > 21600 )); then
    date +%s > "$ALLAPOT/riasztva"
    ert -p 5 -t rotating_light "A BaninaPRO $(( $(mp_ota "$ALLAPOT/hiba_ota") / 60 )) perce NEM érhető el" \
        "Az őrszem még nem tudta helyreállítani (konténerek, Docker újraindítva). Ha 1 órán belül sem sikerül, újraindítja a gépet."
    "$JELENTO" riasztas "A BaninaPRO $(( $(mp_ota "$ALLAPOT/hiba_ota") / 60 )) perce nem érhető el, és az őrszem még nem tudta helyreállítani. Ha 1 órán belül sem sikerül, újraindítja a gépet." >> "$NAPLO" 2>&1 || true
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
# SMTP-küldése viszi, külön program, modul vagy licenc nem kell hozzá. Kézzel: sudo baninapro-jelentes kezi
# (asztali ikont a light változat nem készít).
lepes_jelentes() {
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
    ok "Kézi ellenőrzés és jelentés bármikor: sudo $JELENTO kezi"

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

# A jelentő script (a beállításokkal együtt íródik ki)
jelento_iras() {
    {
        printf '#!/bin/bash\n# BaninaPRO állapotjelentés – a szerver_beallitas.sh írta, kézzel ne módosítsd (minden futása újraírja).\n'
        printf 'APP_KONTENER=%q\nDB_KONTENER=%q\nAPP_PORT=%q\nPMA_PORT=%q\nTITOK_MAPPA=%q\nCIMZETT=%q\nERTESITO=%q\nKEVES_HELY_MB=%q\nUSB_SEGED=%q\n' \
            "$APP_KONTENER" "$DB_KONTENER" "$APP_PORT" "$PMA_PORT" "$TITOK_MAPPA" "$JELENTES_CIMZETT" "$ERTESITO" "$KEVES_HELY_MB" "$USB_SEGED"
        printf 'AUTO_FRISSITO_IDOZITOK=(%s)\n' "${AUTO_FRISSITO_IDOZITOK[*]}"
        cat <<'EOF'
# Ellenőrzi a szervert, és az eredményt elküldi push-értesítésként (rövid összefoglaló) és e-mailben (ha van feladó):
#   baninapro-jelentes napi             minden nap 03:30-kor (systemd-időzítő)
#   baninapro-jelentes kezi             kézzel (sudo baninapro-jelentes kezi) – ugyanez, a képernyőn is
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

# --- az USB-meghajtó (rajta a Docker és az adatbázis) és a mentések másolatai ---
usb=()
if [[ -x $USB_SEGED ]]; then
    "$USB_SEGED" tukor >/dev/null 2>&1 || true
    while IFS= read -r sor; do
        if [[ $sor == '!'* ]]; then pr "${sor#!}"; usb+=("HIBA: ${sor#!}"); else usb+=("$sor"); fi
    done < <("$USB_SEGED" allapot 2>&1)
fi

# --- a gép ---
szabad_mb="$(df -Pk / | awk 'NR == 2 { print int($4 / 1024) }')"
(( szabad_mb >= KEVES_HELY_MB )) || pr "Kevés a szabad hely a lemezen: $szabad_mb MB"
read -r fut _ < /proc/uptime
fut=${fut%.*}
for sz in docker containerd cron baninapro-orszem.timer baninapro-belepesfigyelo; do
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
    auto="ki – a rendszer magától nem frissül (a szerver_beallitas_light.sh sem frissíti)"
fi

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
    if [[ -n $mentes_naplo ]]; then echo "  Mentési napló:  $mentes_naplo"; fi
    if (( ${#usb[@]} )); then
        echo
        echo "USB-meghajtó"
        printf '  %s\n' "${usb[@]}"
    fi
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
    docker exec "$APP_KONTENER" php -r \
        'require "/var/www/html/includes/db.php"; echo (int)db_val("SELECT COUNT(*) FROM felhasznalok");' \
        > "$TMPD/felh" 2>&1 || true
}

lepes_ellenorzes() {
    local oldal="$TMPD/oldal.html" kod="" felhasznalok="" api="" i k s allapot lan pma_db
    # kevés a tárhely: a telepítéshez letöltött csomagfájlok törlése (a telepített programok maradnak)
    apt-get clean >/dev/null 2>&1 || true
    ok "Letöltött csomagfájlok törölve – szabad hely: $(hely_szoveg)"
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

    kod="$(curl -s -o "$TMPD/pma.html" -w '%{http_code}' --max-time 10 "http://127.0.0.1:$PMA_PORT/" || true)"
    if [[ $kod != 200 ]]; then
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

    for k in "$APP_KONTENER" "$DB_KONTENER" baninapro-phpmyadmin; do
        allapot="$(timeout 30 docker inspect -f '{{.State.Status}}/{{.HostConfig.RestartPolicy.Name}}' "$k" 2>/dev/null || echo hiányzik)"
        if [[ $allapot == running/unless-stopped || $allapot == running/always ]]; then
            ok "$k fut, a gép újraindítása után magától elindul"
        else
            figy "$k állapota: $allapot"
        fi
    done
    for s in docker containerd baninapro-orszem.timer baninapro-jelentes.timer \
        baninapro-indulas.service baninapro-leallas.service baninapro-belepesfigyelo.service; do
        if systemctl is-enabled --quiet "$s" 2>/dev/null; then ok "$s: a géppel együtt indul"
        else figy "$s: nem indul automatikusan"; fi
    done
    if systemctl is-active --quiet baninapro-belepesfigyelo.service; then ok "A belépésfigyelő fut (be- és kilépésekről értesít)"
    else figy "A belépésfigyelő (baninapro-belepesfigyelo) nem fut."; fi
    # gyorsítás: a munkamenetek és az ideiglenes fájlok a memóriában, a PHP-gyorsítótár, a MySQL beállításai
    if timeout 30 docker exec "$APP_KONTENER" sh -c 'grep -q " /tmp tmpfs " /proc/mounts && php -m | grep -q "Zend OPcache"' >/dev/null 2>&1; then
        ok "Gyorsítás: a munkamenetek és az ideiglenes fájlok a memóriában, PHP-gyorsítótár (opcache) be"
    else
        figy "A PHP gyorsítása (memóriában lévő /tmp, opcache) nem él – docker exec $APP_KONTENER php -m"
    fi
    s="$(db_szam 'SELECT CONCAT(@@innodb_flush_log_at_trx_commit, @@log_bin)')"
    if [[ $s == 20 ]]; then
        ok "Gyorsítás: MySQL – $(db_sql -e 'SELECT ROUND(@@innodb_buffer_pool_size / 1048576)' 2>/dev/null | tr -dc '0-9' || true) MB gyorsítótár a memóriában, a napló másodpercenként íródik, binlog ki"
    else
        figy "A MySQL gyorsítása nem él (innodb_flush_log_at_trx_commit, log_bin: ${s:-?})"
    fi
    # az USB-meghajtó: csatolva, rajta a Docker tárhelye; a mentések másolatai; a jelszavak az USB-n is (új szerverhez)
    if docker_usb_n_van; then ok "USB-meghajtó csatolva – rajta a Docker tárhelye és az adatbázis ($(timeout 20 docker info -f '{{.DockerRootDir}}' 2>/dev/null || true) → $USB_ADAT)"
    else figy "A Docker tárhelye nincs az USB-meghajtóról befűzve (sudo baninapro-usb ellenoriz)."; fi
    for s in containerd docker; do
        if systemctl show -p RequiresMountsFor --value "$s.service" 2>/dev/null | grep -q /var/lib/docker; then ok "$s: csak az USB-meghajtóval indul"
        else figy "$s: az USB-meghajtó nélkül is elindulhat"; fi
    done
    AKT_MUVELET="a mentések másolása az USB-re és a gép saját lemezére"
    if fut "$USB_SEGED" tukor; then ok "$(tail -n 1 "$NAPLO")"; else figy "$(tail -n 1 "$NAPLO")"; fi
    AKT_MUVELET=""
    usb_titkok_ment
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
    cimek="$(docker port "$1" "$2/tcp" 2>/dev/null | grep -v '^\[' || true)"
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
    local rc=${1:-0} i e fv suly nev jel szin megj v="" f app pma mysql lan mdns
    OSSZEGZES_KESZ=1
    AKT_LEPES="összegzés" AKT_ROVID="összegzés"
    if (( rc == 0 )); then kesz_sav; fi

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
    if (( ELSO_INDITAS )); then
        osz "" "  Első belépés a BaninaPRO-ba: $ADMIN_KEZDO  → belépés után azonnal változtasd meg!"
    fi
    if systemctl is-active --quiet ssh.socket ssh 2>/dev/null; then
        osz "" "  A gép IP-címe: ${lan:-ismeretlen} – SSH: ssh $CEL_FELH@${lan:-$GEPNEV}"
    else
        osz "" "  A gép IP-címe: ${lan:-ismeretlen}"
    fi
    osz "" "  (Tipp: a routerben foglald le ezt az IP-címet a szervernek – DHCP-foglalás –, hogy ne változzon.)"
    if [[ -z $NTFY_CSATORNA ]]; then NTFY_CSATORNA="$(sed -n 's/^NTFY_CSATORNA=//p' "$TITOK_MAPPA/ntfy" 2>/dev/null || true)"; fi
    if [[ -n $NTFY_CSATORNA ]]; then
        osz "" "  Értesítések a telefonra: ntfy alkalmazás → + (Subscribe to topic) → Topic: $NTFY_CSATORNA"
        osz "" "                 (böngészőben: $NTFY_SZERVER/$NTFY_CSATORNA – leírás: $CEL_HOME/BaninaPRO-ertesitesek.txt)"
    fi
    if [[ -n $JELENTES_FELADO ]]; then
        osz "" "  Napi jelentés: minden nap $JELENTES_IDO-kor push-értesítésként és e-mailben → $JELENTES_CIMZETT (feladó: $JELENTES_FELADO)"
    else
        osz "" "  Napi jelentés: minden nap $JELENTES_IDO-kor push-értesítésként (e-mailben is: sudo bash $(basename "$SCRIPT") --email)"
    fi
    osz "" "  Az adatbázis root-jelszava (ha valaha kellene): sudo cat $TITOK_MAPPA/titkok"
    if [[ -n $USB_ADAT_UUID ]]; then
        osz "" "  USB-meghajtó:  rajta a Docker és az adatbázis ($USB_ADAT_CIMKE, $(df -hP "$USB_ADAT" 2>/dev/null | awk 'NR == 2 { print $4 }') szabad)"
        osz "" "                 és minden mentés másolata: $USB_MENTES_CIMKE → BaninaPRO-mentesek – Windows / Mac gépen is olvasható"
        osz "" "                 (a legutóbbi mentések a gép saját lemezén is: $BELSO_MENTES)"
        osz "" "  Biztonságos eltávolítás: sudo baninapro-usb levalaszt  (visszadugva magától visszacsatolódik)"
    fi
    if [[ -f $TITOK_MAPPA/php-gyorsitas.ini ]] && grep -q 'innodb-flush-log-at-trx-commit=2' "$REPO/docker-compose.override.yml" 2>/dev/null; then
        osz "" "  Gyorsítás:     a munkamenetek, az ideiglenes fájlok és a PHP lefordított kódja a memóriában; MySQL: kb. ${DB_PUFFER_MB} MB"
        osz "" "                 gyorsítótár a memóriában, a napló másodpercenként íródik a pendrive-ra"
    fi
    osz "" "  Kézi ellenőrzés: sudo $JELENTO kezi"
    osz "" "  Őrszem:        2 percenként ellenőriz, és ha kell, helyreállít (napló: /var/log/baninapro-orszem.log)"
    osz "" "  Szabad hely:   $(hely_szoveg) a gép saját lemezén (az őrszem $KEVES_HELY_MB MB alatt takarít és értesít)"
    osz "" "  Frissítés:     cd \"$REPO\" && git pull   – majd újra: cd \"$SCRIPT_DIR\" && sudo bash $(basename "$SCRIPT")"
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

    if [[ -f /var/run/reboot-required ]]; then UJRAINDITAS=1; fi
    if (( UJRAINDITAS )); then
        ki ""
        ki "  Újraindítás kell: utána lesz érvényes az új gépnév (vagy egy korábbi, kézi rendszerfrissítés kéri)."
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
            "A $(basename "$SCRIPT") megállt${nev:+ ($AKT_I. lépés: $nev)}: ${HIBA_UZENET%%$'\n'*}. A hiba javítása után újrafuttatható. Napló: $NAPLO" \
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
    ARGOK=("$@")   # egy újraindított (letöltött) példány is ugyanezekkel fut
    for a in "$@"; do
        case $a in
            --email)        EMAIL_KERDES=1 ;;          # a feladó-postafiók (újra)beállítása
            --usb-formazas) USB_FORMAZAS=1 ;;          # a nem üres pendrive formázása is
            --usb=*)        USB_LEMEZ="${a#--usb=}" ;; # ha a pendrive nem /dev/sdb néven jelenik meg
            *) ;;
        esac
    done
    naplo_inditas
    kepernyo_beallitas
    TMPD="$(mktemp -d)"
    APT_ALLAPOT="$TMPD/apt_allapot"
    trap kilepeskor EXIT
    LEPES_KEZDET=$SECONDS
    printf '\n\n######## %s – futás indul ########\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    printf '\n%sBaninaPRO szerver – telepítés (light: asztal és AnyDesk nélkül, kevés helyhez)%s  (%s)\n' \
        "$C_F" "$C_N" "$(date '+%Y-%m-%d %H:%M')" >&3
    # amíg a telepítő dolgozik, az őrszem ne avatkozzon be (ugyanezt a zárat fogja; ha épp helyreállít, megvárja)
    exec 8>/run/baninapro-orszem.lock
    if ! flock -n 8; then
        info "Várakozás, amíg az őrszem befejezi az ellenőrzést (legfeljebb 15 perc)…"
        flock -w 900 8 || true
    fi

    elofeltetelek
    kerdesek
    lepesek_listaja
    futtat_lepesek
    osszegzes 0
}

# a teljes script beolvasása után indul – így a futás közbeni git pull sem zavarja meg
main "$@"
