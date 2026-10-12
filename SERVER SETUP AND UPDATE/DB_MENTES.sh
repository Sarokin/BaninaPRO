#!/bin/bash
# =============================================================================
#  DB_MENTES – a BaninaPRO adatbázis teljes mentése a szerveren, másolattal pendrive-ra
# =============================================================================
#  Kattintásra (az asztali „DB MENTÉS” ikonról, terminálablakban) elkészíti az adatbázis teljes mentését, és ahová csak
#  lehet, ki is másolja:
#   1. mentés az alkalmazás saját mentőjével (cron_mentes.php kezi) a megszokott helyre: a Docker-kötet DBBCKP mappája
#      (ugyanoda, ahová az éjszakai mentés) – kézi mentésként, amit a rendszer soha nem töröl automatikusan;
#   2. másolat a gép saját lemezére (/var/backups/baninapro – a telepítő mentő segédje végzi);
#   3. másolat MINDEN csatlakoztatott, írható külső meghajtóra (pendrive, USB-lemez): a meghajtó BaninaPRO-mentesek
#      mappájába; ha a meghajtó nincs csatolva, a script csatolja, a másolás után kiírja a lemezre (sync), és leválasztja,
#      hogy a pendrive azonnal kihúzható legyen. A legújabb PENDRIVE_MENTES_DB mentés marad meg rajta.
#  A végén kiírja és push-értesítésben is elküldi az eredményt.
#
#  Futtatás:   az asztali „DB MENTÉS” ikonnal (az ikont a BaninaPro_App_Updater.sh készíti), vagy terminálból:
#                  bash DB_MENTES.sh                 (a rendszergazdai jelszót kéri)
#
#  A pendrive-másolatot önmagában is tudja – ezt hívja a telepítő éjszakai mentője (03:00) és az őrszem (2 percenként):
#                  bash DB_MENTES.sh --pendrive          minden írható külső meghajtóra kimásolja a legújabb mentéseket
#                  bash DB_MENTES.sh --pendrive --ha-uj  csak ha új mentés készült, vagy új meghajtót dugtak be (különben
#                                                        hozzá sem nyúl a meghajtóhoz) – egy összefoglaló sort ír ki
#  Környezeti változók (próbához / különleges beállításhoz):
#      BANINA_PENDRIVE_MAPPA=/út   nem keres meghajtót, ebbe a mappába másol (pl. egy mindig csatolt hálózati lemez)
#      BANINA_MENTES_FORRAS=/út    a mentések forrásmappája (alapból a Docker-kötet DBBCKP mappája)

set -uo pipefail

# shellcheck disable=SC2034  # (a többi szerver-scripttel azonos jelölés; a telepítő és a frissítő a verziót nézheti)
VALTOZAT=1   # ha a scriptet módosítod, növeld
ORSZEM="/usr/local/sbin/baninapro-orszem"       # a telepítő írja: ebből tudja a konténer nevét és a www-data uid-jét
ERTESITO="/usr/local/sbin/baninapro-ertesites"  # push-értesítés (ha a telepítő beállította)
MENTO="/usr/local/sbin/baninapro-mentes"        # a telepítő mentő segédje: másolat a gép saját lemezére (baninapro-mentes masol)
NAPLO="/var/log/baninapro-mentes.log"           # közös napló az éjszakai mentéssel
PENDRIVE_MAPPA_NEV="BaninaPRO-mentesek"         # ebbe a mappába kerülnek a mentések a meghajtón
PENDRIVE_MENTES_DB=90                           # ennyi mentés marad meg a meghajtón (a régebbiek törlődnek – csak ebből a mappából)
PENDRIVE_TARTALEK_MB=64                         # legalább ennyi hely maradjon a meghajtón a másolás után
CSATOLAS_GYOKER="/mnt/baninapro-pendrive"       # ide csatolja a még nem csatolt meghajtókat (a másolás után leválasztja)
ALLAPOT_MAPPA="/var/lib/baninapro-orszem"       # --ha-uj: mi volt a legutóbb átmásolt mentés és meghajtó
ZAR="/run/baninapro-pendrive.lock"              # egyszerre csak egy pendrive-másolás (őrszem + éjszakai + kézi)
FS_TIPUSOK=" vfat exfat ntfs ext2 ext3 ext4 xfs btrfs f2fs "   # ezekre tudunk másolni

SCRIPT="$(readlink -f "${BASH_SOURCE[0]}")"
APP_KONTENER="baninapro-app" WWW_UID=33 KOTET="baninapro_adatok"
C_ZOLD="" C_SARGA="" C_PIROS="" C_F="" C_N=""
if [[ -t 1 ]]; then C_ZOLD=$'\e[1;32m' C_SARGA=$'\e[1;33m' C_PIROS=$'\e[1;31m' C_F=$'\e[1m' C_N=$'\e[0m'; fi
S_OK="✔" S_FIGY="⚠" S_HIBA="✘" S_PONT="•"
# (a gép saját szöveges konzolján a betűkészletben nincs meg minden jel)
if [[ ${TERM:-} == linux ]]; then S_OK="+" S_FIGY="!" S_HIBA="X" S_PONT="-"; fi
export LC_ALL=C.UTF-8
shopt -s nullglob

ok()   { printf '  %s%s%s %s\n' "$C_ZOLD" "$S_OK" "$C_N" "$*"; }
info() { printf '  %s %s\n' "$S_PONT" "$*"; }
figy() { printf '  %s%s %s%s\n' "$C_SARGA" "$S_FIGY" "$*" "$C_N"; }
naploz() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$NAPLO" 2>/dev/null || true; }
hiba() {
    printf '\n%s%s HIBA: %s%s\n\n' "$C_PIROS" "$S_HIBA" "$*" "$C_N"
    naploz "HIBA (DB_MENTES): $*"
    exit 1
}

# egy sor értéke a telepítő által írt scriptből (shell-idézéssel kiírva: NEV=érték)
beallitas_ertek() {   # $1 = fájl, $2 = név
    local sor="" ertek=""
    [[ -r $1 ]] || return 0
    sor="$(grep -m 1 "^$2=" "$1" 2>/dev/null || true)"
    [[ -n $sor ]] || return 0
    eval "ertek=${sor#*=}" 2>/dev/null || true
    printf '%s' "$ertek"
}

# ---- a mentések forrása: a Docker-kötet DBBCKP mappája --------------------------------------------------------
forras_mappa() {
    local d
    if [[ -n ${BANINA_MENTES_FORRAS:-} ]]; then printf '%s' "$BANINA_MENTES_FORRAS"; return 0; fi
    d="$(timeout 30 docker volume inspect -f '{{.Mountpoint}}' "$KOTET" 2>/dev/null </dev/null || true)"
    [[ -n $d && -d $d/DBBCKP ]] || return 1
    printf '%s' "$d/DBBCKP"
}

# a teljes (lezárt) mentések, a legújabb elöl – a félbemaradt vagy épp íródó fájl kimarad
teljes_mentesek() {   # $1 = mappa, $2 = legfeljebb ennyi
    local f n=0 lista=()
    mapfile -t lista < <(printf '%s\n' "$1"/*.sql | sort -r)
    for f in "${lista[@]}"; do
        [[ -f $f ]] || continue
        tail -c 4096 "$f" 2>/dev/null | grep -q '^-- Mentés vége:' || continue
        printf '%s\n' "$f"
        n=$(( n + 1 ))
        (( n < $2 )) || break
    done
}

# ---- külső meghajtók --------------------------------------------------------------------------------------------
# Kiírja a szóba jövő köteteket, soronként: NÉV|FSTYPE|UUID|CÍMKE|CSATOLÁS|MÉRET. Külső = kivehető (RM), hotplug vagy
# USB-s eszköz (vagy annak partíciója), rajta ismert fájlrendszerrel; a rendszer saját lemeze (amelyről a /, /boot, /var,
# /home vagy a Docker tárhelye van csatolva) és a swap kimarad.
kulso_kotetek() {
    local -a sorok=() rendszer=()
    local sor NAME TYPE RM HOTPLUG TRAN FSTYPE UUID LABEL MOUNTPOINT SIZE PKNAME
    local -A disk_kulso=()
    command -v lsblk >/dev/null || return 0
    mapfile -t sorok < <(lsblk -P -o NAME,TYPE,RM,HOTPLUG,TRAN,FSTYPE,UUID,LABEL,MOUNTPOINT,SIZE,PKNAME 2>/dev/null || true)
    mezok() {   # az lsblk -P sorának mezői változókba (az értékek idézőjelben, a \x.. jeleket az lsblk már feloldotta volna -P nélkül)
        NAME="" TYPE="" RM="" HOTPLUG="" TRAN="" FSTYPE="" UUID="" LABEL="" MOUNTPOINT="" SIZE="" PKNAME=""
        local m="$1" k v
        while [[ $m =~ ^[[:space:]]*([A-Z]+)=\"([^\"]*)\"(.*)$ ]]; do
            k="${BASH_REMATCH[1]}" v="${BASH_REMATCH[2]}" m="${BASH_REMATCH[3]}"
            case $k in NAME|TYPE|RM|HOTPLUG|TRAN|FSTYPE|UUID|LABEL|MOUNTPOINT|SIZE|PKNAME) printf -v "$k" '%s' "$v" ;; esac
        done
    }
    # 1) melyik lemez külső, és melyik a rendszeré
    for sor in "${sorok[@]}"; do
        mezok "$sor"
        if [[ $TYPE == disk ]]; then
            if [[ $RM == 1 || $HOTPLUG == 1 || $TRAN == usb ]]; then disk_kulso[$NAME]=1; fi
        fi
        case $MOUNTPOINT in
            /|/boot|/boot/efi|/var|/home|/usr|/var/lib/docker|/var/lib/docker/*|\[SWAP\]) rendszer+=("${PKNAME:-$NAME}" "$NAME") ;;
        esac
    done
    # 2) a kötetek: partíció egy külső lemezen, vagy partíció nélküli külső lemez fájlrendszerrel
    for sor in "${sorok[@]}"; do
        mezok "$sor"
        [[ -n $FSTYPE && $FS_TIPUSOK == *" $FSTYPE "* ]] || continue
        local lemez="${PKNAME:-$NAME}" r
        [[ -n ${disk_kulso[$lemez]:-} ]] || continue
        for r in "${rendszer[@]}"; do [[ $r != "$lemez" && $r != "$NAME" ]] || continue 2; done
        [[ $MOUNTPOINT != \[SWAP\] ]] || continue
        printf '%s|%s|%s|%s|%s|%s\n' "$NAME" "$FSTYPE" "$UUID" "$LABEL" "$MOUNTPOINT" "$SIZE"
    done
}

# Egy kötetre másolás. $1..6 = a kulso_kotetek egy sora szétszedve; kiírja az eredmény egy sorát; 0 = rendben, 1 = hiba.
kotetre_masol() {
    local nev="$1" fs="$2" uuid="$3" cimke="$4" mp="$5" meret="$6" forras="$7"
    local csatoltam=0 cel f fnev uj=0 szabad fm hiba_sz="" osszes=() megnev
    megnev="${cimke:-$nev}${meret:+ ($meret)}"
    if [[ -z $mp ]]; then
        mp="$CSATOLAS_GYOKER/$nev"
        install -d -m 700 "$mp" 2>/dev/null || { echo "$megnev – HIBA: a csatolási mappa nem hozható létre ($mp)"; return 1; }
        if ! mount -o rw "/dev/$nev" "$mp" >/dev/null 2>&1; then
            # NTFS: a kernel újabb meghajtója (ntfs3) kell hozzá
            if [[ $fs != ntfs ]] || ! mount -t ntfs3 -o rw "/dev/$nev" "$mp" >/dev/null 2>&1; then
                rmdir "$mp" 2>/dev/null || true
                echo "$megnev – HIBA: nem csatolható ($fs)"
                return 1
            fi
        fi
        csatoltam=1
    fi
    cel="$mp/$PENDRIVE_MAPPA_NEV"
    if ! mkdir -p "$cel" 2>/dev/null || ! { : > "$cel/.irasproba"; } 2>/dev/null; then
        hiba_sz="nem írható (írásvédett, vagy csak olvashatóra csatolt meghajtó)"
    else
        rm -f "$cel/.irasproba"
        if [[ ! -f $cel/OLVASS_EL.txt ]]; then
            printf 'BaninaPRO adatbázis-mentések (teljes SQL-mentés, a legújabb %s marad meg).\nVisszaállítás a szerveren: az asztali „SQL IMPORT” ikonnal, vagy Admin → Adatbázis-mentések → feltöltés.\n' "$PENDRIVE_MENTES_DB" > "$cel/OLVASS_EL.txt" 2>/dev/null || true
        fi
        local lista=()
        mapfile -t lista < <(teljes_mentesek "$forras" "$PENDRIVE_MENTES_DB")
        for f in "${lista[@]}"; do
            fnev="${f##*/}"
            if [[ -f $cel/$fnev ]] && [[ $(stat -c %s "$f") == "$(stat -c %s "$cel/$fnev")" ]]; then continue; fi
            fm=$(( $(stat -c %s "$f") / 1048576 + 1 ))
            szabad="$(df -Pm "$cel" 2>/dev/null | awk 'NR == 2 { print $4 }')"
            if [[ -z $szabad ]] || (( szabad - fm < PENDRIVE_TARTALEK_MB )); then hiba_sz="kevés a hely (${szabad:-?} MB szabad, a mentés $fm MB)"; break; fi
            if cp "$f" "$cel/.$fnev.tmp" 2>/dev/null && mv -f "$cel/.$fnev.tmp" "$cel/$fnev" 2>/dev/null; then
                uj=$(( uj + 1 ))
            else
                rm -f "$cel/.$fnev.tmp" 2>/dev/null
                hiba_sz="a(z) $fnev nem másolható át"
                break
            fi
        done
        # csak a legújabb PENDRIVE_MENTES_DB marad – kizárólag a saját mappánkból
        printf '%s\n' "$cel"/*.sql | sort -r | tail -n +$(( PENDRIVE_MENTES_DB + 1 )) | xargs -r rm -f -- 2>/dev/null
        osszes=("$cel"/*.sql)
        date '+%Y-%m-%d %H:%M' > "$cel/.utolso_masolas" 2>/dev/null || true
    fi
    sync -f "$mp" 2>/dev/null || sync
    if (( csatoltam )); then
        umount "$mp" >/dev/null 2>&1 || { sleep 2; umount "$mp" >/dev/null 2>&1 || true; }
        rmdir "$mp" 2>/dev/null || true
    fi
    if [[ -n $hiba_sz ]]; then
        echo "$megnev – HIBA: $hiba_sz$( (( uj > 0 )) && echo " ($uj mentés azért átkerült)")"
        return 1
    fi
    echo "$megnev – $uj új mentés átmásolva, összesen ${#osszes[@]} a $PENDRIVE_MAPPA_NEV mappában$([[ $csatoltam == 1 ]] && echo ', leválasztva – kihúzható')"
    return 0
}

# ugyanaz egy már csatolt (vagy hálózati) mappába – BANINA_PENDRIVE_MAPPA
kotetre_masol_mappaba() {   # $1 = mappa, $2 = forrás
    kotetre_masol "mappa" "" "" "$1" "$1" "" "$2"
}

# A pendrive-másolás: minden írható külső meghajtóra (vagy a BANINA_PENDRIVE_MAPPA mappába). $1 = 1: csak ha van új
# (új mentés vagy új meghajtó az előző futás óta). Egy összefoglaló sort ír ki; 0 = rendben / nincs mit, 1 = valahol hiba.
pendrive_masolas() {
    local csak_uj="${1:-0}" forras ujabb kotetek=() k nev fs uuid cimke mp meret eredm=() rc=0 kulcs allapot=""
    forras="$(forras_mappa)" || { echo "Pendrive: a mentések mappája (a Docker-kötet) nem található – most nem készül másolat."; return 1; }
    ujabb="$(teljes_mentesek "$forras" 1)"
    ujabb="${ujabb##*/}"
    if [[ -n ${BANINA_PENDRIVE_MAPPA:-} ]]; then
        kotetek=("MAPPA||mappa|$BANINA_PENDRIVE_MAPPA|$BANINA_PENDRIVE_MAPPA|")
    else
        mapfile -t kotetek < <(kulso_kotetek)
    fi
    if (( ${#kotetek[@]} == 0 )); then
        if (( csak_uj )); then printf '' > "$ALLAPOT_MAPPA/pendrive-masolat" 2>/dev/null || true; fi
        echo "Pendrive: nincs csatlakoztatva külső meghajtó"
        return 0
    fi
    kulcs="$(printf '%s;' "${kotetek[@]}" | sed 's/|[^|]*|[^|]*;/;/g')|$ujabb"   # eszközök (név|fs|uuid|címke) + a legújabb mentés
    if (( csak_uj )); then
        allapot="$(cat "$ALLAPOT_MAPPA/pendrive-masolat" 2>/dev/null || true)"
        if [[ $allapot == "$kulcs" ]]; then echo "Pendrive: nincs új mentés a legutóbbi másolás óta (${ujabb:-még nincs mentés})"; return 0; fi
    fi
    exec 8>"$ZAR"
    if ! flock -w 120 8; then echo "Pendrive: éppen fut egy másik másolás – most kimarad."; return 1; fi
    for k in "${kotetek[@]}"; do
        IFS='|' read -r nev fs uuid cimke mp meret <<<"$k"
        if [[ $nev == MAPPA ]]; then
            local e
            if [[ -d $mp ]]; then
                e="$(kotetre_masol_mappaba "$mp" "$forras")" || rc=1
            else
                e="$mp – HIBA: a mappa nem létezik"; rc=1
            fi
            eredm+=("$e")
        else
            local e2
            e2="$(kotetre_masol "$nev" "$fs" "$uuid" "$cimke" "$mp" "$meret" "$forras")" || rc=1
            eredm+=("$e2")
        fi
    done
    flock -u 8
    if (( rc == 0 )); then
        install -d -m 700 "$ALLAPOT_MAPPA" 2>/dev/null || true
        printf '%s' "$kulcs" > "$ALLAPOT_MAPPA/pendrive-masolat" 2>/dev/null || true
    fi
    local ossz
    ossz="$(printf ' · %s' "${eredm[@]}")"
    echo "Pendrive: ${ossz# · }"
    return "$rc"
}

# ---- kézi mentés (az asztali ikon) ------------------------------------------------------------------------------
kezi_mentes() {
    local kimenet rc masolat="" pend="" prc=0 kerte sorok osszefoglalo
    printf '\n%sBaninaPRO – adatbázis-mentés%s  (%s)\n' "$C_F" "$C_N" "$(date '+%Y-%m-%d %H:%M')"
    command -v docker >/dev/null || hiba "A Docker nincs telepítve – futtasd a teljes telepítőt (szerver_beallitas.sh)."
    if ! timeout 20 docker inspect -f '{{.State.Running}}' "$APP_KONTENER" 2>/dev/null </dev/null | grep -q true; then
        hiba "A BaninaPRO konténere ($APP_KONTENER) nem fut – indítsd el (vagy futtasd a teljes telepítőt), és próbáld újra."
    fi
    kerte="DB_MENTES${SUDO_USER:+ ($SUDO_USER)}"
    info "Teljes mentés készül az adatbázisról…"
    # www-data-ként, mint a weboldal: így a fájlok a weboldalról is kezelhetők (törlés, letöltés, jelző)
    kimenet="$(timeout 1800 docker exec -u "$WWW_UID" "$APP_KONTENER" php -q cron_mentes.php kezi "$kerte" 2>&1 </dev/null)"
    rc=$?
    if (( rc != 0 )) || [[ $kimenet != OK* ]]; then
        naploz "Kézi mentés (DB_MENTES) NEM sikerült: ${kimenet//$'\n'/ | }"
        if [[ -x $ERTESITO ]]; then setsid -f "$ERTESITO" -p 4 -t x,floppy_disk "A kézi adatbázis-mentés NEM sikerült" "$(tail -n 3 <<<"$kimenet")" >/dev/null 2>&1 8>&- || true; fi
        hiba "A mentés nem készült el: $(tail -n 3 <<<"$kimenet")"
    fi
    ok "Mentés kész: ${kimenet#OK: }"
    if [[ -x $MENTO ]]; then
        masolat="$("$MENTO" masol 2>&1 </dev/null | tail -n 1)" && ok "$masolat" || figy "${masolat:-A másolat a gép saját lemezére nem készült el.}"
    else
        masolat="Másolat a gép saját lemezére: nincs beállítva (futtasd a teljes telepítőt)"
        figy "$masolat"
    fi
    info "Külső meghajtó (pendrive) keresése…"
    pend="$(pendrive_masolas 0)" || prc=1
    if (( prc )); then figy "$pend"; elif [[ $pend == *"nincs csatlakoztatva"* ]]; then info "$pend – ha dugsz be egyet, 2 percen belül arra is kimásolódnak a mentések."; else ok "$pend"; fi
    osszefoglalo="Kézi mentés: ${kimenet#OK: } | $masolat | $pend"
    naploz "$osszefoglalo"
    if [[ -x $ERTESITO ]]; then
        setsid -f "$ERTESITO" -p 2 -t floppy_disk "Kézi adatbázis-mentés kész" "$osszefoglalo" >/dev/null 2>&1 8>&- || true
    fi
    printf '\n'
    return "$prc"
}

main() {
    local a ablak=0 pendrive=0 ha_uj=0 tobbi=() rc=0 f
    for a in "$@"; do
        case $a in
            --ablak) ablak=1 ;;          # az asztali ikonról: a végén megvárja az Entert, hogy az ablak ne tűnjön el
            --pendrive) pendrive=1; tobbi+=("$a") ;;   # csak a pendrive-másolat (az éjszakai mentő és az őrszem hívja)
            --ha-uj) ha_uj=1; tobbi+=("$a") ;;         # …és csak akkor, ha van új mentés vagy új meghajtó
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
    f="$(beallitas_ertek "$ORSZEM" APP_KONTENER)"; [[ -n $f ]] && APP_KONTENER="$f"
    f="$(beallitas_ertek "$ORSZEM" WWW_UID)"; [[ $f =~ ^[0-9]+$ ]] && WWW_UID="$f"
    f="$(beallitas_ertek "$MENTO" KOTET)"; [[ -n $f ]] && KOTET="$f"
    if (( pendrive )); then
        pendrive_masolas "$ha_uj"
        exit $?
    fi
    kezi_mentes
}

main "$@"
