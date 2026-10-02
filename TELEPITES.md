# BaninaPRO – telepítési és használati útmutató

Számla-nyilvántartó webalkalmazás: **PHP 8 + MySQL/MariaDB**, keretrendszer nélkül,
cPanel-es (osztott) tárhelyen is futtatható. Mobilra (iPhone XS, 375 px) optimalizált,
a keresők és AI-robotok elől teljesen elzárt, bejelentkezéshez kötött rendszer.

---

## 1. Mit kapsz (fájlok:)

```
baninapro/
├── index.php              – az alkalmazás (egyoldalas app, hash-alapú útvonalak: #/bejovo, #/kimeno …)
├── api.php                – JSON API (minden adatművelet ezen megy, csak bejelentkezve, CSRF-védelemmel)
├── pdf.php                – nyomtatási lista PDF-ben (a kosárba gyűjtött sorokból, A4)
├── cron_mentes.php        – NAPI TELJES ADATBÁZIS-MENTÉS (cron indítja 03:00-kor; parancssorból: lista / vissza)
├── mentes_letoltes.php    – mentésfájl letöltése (csak admin)
├── mentes_feltoltes.php   – mentésfájl feltöltése a DBBCKP mappába (csak admin)
├── import.php             – Excel (.xlsx) feltöltése és elemzése: számlanapló vagy kötéskönyv (régi adatok importja)
├── setup.php              – EGYSZERI telepítő böngészőből (telepítés után TÖRÖLD!)
├── manifest.webmanifest   – „Kezdőképernyőhöz adás” iPhone-on / Androidon
├── robots.txt             – minden robot kitiltva
├── .htaccess              – X-Robots-Tag, biztonsági fejlécek, robotok tiltása, mappák védelme
├── assets/
│   ├── app.js, app.css    – a felület
│   ├── qr.js              – saját QR-kód generátor (nincs külső függőség)
│   ├── favicon.svg        – a félig hámozott banán (SVG, a fejléc, a belépő oldal és a favicon is ezt használja)
│   ├── favicon.ico, favicon-32.png, apple-touch-icon.png, icon-192.png, icon-512.png – PNG/ICO tartalékok
│   └── fonts/             – a PDF-be ágyazott betűk (Carlito, Poppins – nyílt licenc, magyar ékezetekkel)
├── includes/              – PHP háttér (webről NEM elérhető)
│   ├── config.php         – ADATBÁZIS-BEÁLLÍTÁSOK (itt kell átírni, ha kell) + munkamenet-időkorlátok (ULES_*, 1.14)
│   ├── db.php, auth.php, helpers.php, ids.php, logger.php, rates.php
│   ├── jogok.php          – szerepkörök és jogosultságok (Admin / Irodavezető / Rögzítő / Üzletkötő), megjegyzés- és BESZÁM-szabály
│   ├── webauthn.php       – passkey (WebAuthn) ellenőrzés: CBOR, COSE→PEM, aláírás
│   ├── api_passkey.php    – QR-kódos belépés, jóváhagyás, eszköz-regisztráció
│   ├── pdf.php            – saját PDF-író (A4, beágyazott TrueType betűk, táblázatok oldaltöréssel) – nincs külső könyvtár
│   ├── backup.php         – adatbázis-mentés / visszaállítás (mysqldump nélkül, tiszta PHP)
│   ├── xlsx.php           – saját .xlsx olvasó (zip-bővítmény sem kell)
│   ├── import.php         – Excel import: formátum-felismerés, elemzés, felügyelt cégegyeztetés (≥ 55 %), ütközések, rögzítés
│   ├── api_import.php     – import API (cégcsoportok, adagolt rögzítés) + archív számlák kötésbe helyezése + régi kötés ID ellenőrzés
│   ├── api_kereses.php    – a főoldali kereső (azonosító, számlaszám, cég, régi K, összeg, dátum – töredékre is)
│   ├── api_reszteljesites.php – részteljesítések (nyitott bejövő/kimenő számlára érkezett részfizetések, hátralék)
│   ├── audit.php          – változásnapló: ki, mikor, mit módosított egy kötésen / számlán (a kártyák „könyv” gombja)
│   ├── nyomtatas.php      – a nyomtatási lista összeállítása (cégek, kötések, számlák, utalások, kivonatok)
│   └── api_*.php          – az API műveletei (auth, admin, cégek, bejövő, utalás, kimenő, riport)
├── sql/schema.sql         – ADATBÁZIS TÁBLÁK (UTF-8 / utf8mb4) + kezdő admin
├── sql/frissites_1.2.sql  – frissítés meglévő 1.1-es telepítéshez (passkey táblák)
├── sql/frissites_1.5.sql  – frissítés 1.4-ről 1.5-re (partnerkód, archív kötés, régi kötés ID oszlopok)
├── sql/frissites_1.6.sql  – frissítés 1.5-ről 1.6-ra (régi K azonosító oszlop a bejövő számlákon)
├── sql/frissites_1.8.sql  – frissítés 1.7-ről 1.8-ra (részteljesítések táblája + a bejövő számlák utalási dátuma)
├── sql/frissites_1.9.sql  – frissítés 1.8-ról 1.9-re (számlaszám kötésenként egyedi, változásnapló tábla, kötés módosítás ideje)
├── sql/frissites_1.10.sql – frissítés 1.9-ről 1.10-re (szerepkörök, megjegyzés szerzője, bejövő részteljesítés kapcsoló)
├── sql/frissites_1.13.sql – frissítés 1.12-ről 1.13-ra (utalás lakat oszlopai)
├── LOG/                   – tevékenységnapló: naponta új fájl ÉÉÉÉHHNN.txt (03:00-kor vált)
├── DBBCKP/                – ADATBÁZIS-MENTÉSEK: ÉÉÉÉHHNN_ÓÓPP.sql (webről nem elérhető; magától létrejön)
└── TELEPITES.md           – ez a leírás
```

## 2. Követelmények

* PHP **8.0 vagy újabb** (8.1–8.3 ajánlott), bővítmények: `pdo_mysql`, `mbstring`, `json`, `zlib` (PDF, Excel import), `xml` (Excel import), `curl` (árfolyamhoz), `openssl`
* cPanel **Cron Jobs** a napi adatbázis-mentéshez (8. fejezet)
* MySQL 8.0+ vagy MariaDB 10.4+
* Apache (cPanel/LiteSpeed) `.htaccess` támogatással
* HTTPS (Let's Encrypt a cPanel-ben ingyenes) – **erősen ajánlott**, mert jelszóval belépős rendszer

## 3. Telepítés cPanel-es tárhelyre

1. **Adatbázis létrehozása** (cPanel → *MySQL® Databases*):
   * Adatbázis neve: `baninapr_DATA`
   * Felhasználó: `baninapr_DATA`, jelszó: `Qwerty.20`
   * Add hozzá a felhasználót az adatbázishoz **ALL PRIVILEGES** joggal.
   * (Ha a cPanel más előtagot ad, írd át az `includes/config.php`-ban a `DB_NAME` / `DB_USER` / `DB_PASS` értékeket.)
2. **Fájlok feltöltése**: a `baninapro` mappa tartalmát töltsd fel a `public_html`-be
   (vagy egy aldomain/almappa gyökerébe). A `.htaccess` fájlokat is (rejtett fájlok!).
3. **Táblák létrehozása** – kétféleképpen:
   * **a) Böngészőből:** nyisd meg `https://a-te-domained.hu/setup.php`, add meg az admin jelszót → *Telepítés indítása*.
     A telepítő ellenőrzi a PHP-t, létrehozza a táblákat és az admin felhasználót.
   * **b) phpMyAdmin-ból:** válaszd ki a `baninapr_DATA` adatbázist → *SQL* fül → másold be az `sql/schema.sql` tartalmát → *Go*.
     Ekkor a kezdő admin: **felhasználónév `admin`, jelszó `BaninaPRO-2026!`** – első belépés után változtasd meg!
4. **`setup.php` törlése** a szerverről (biztonság).
5. **LOG mappa**: legyen írható a PHP számára (cPanel-en általában alapból az; ha nem, 0755 vagy 0775).
6. Nyisd meg az oldalt, lépj be a jelszóval (*Jelszóval* link), majd a menü → *Profil · eszközeim* pontban
   **regisztráld a telefonodat** (Face ID / ujjlenyomat) – lásd a 6. fejezetet. Innentől jelszó nélkül lépsz be.
7. **HTTPS**: ha van tanúsítvány, az `.htaccess`-ben vedd ki a `#` jelet a HTTPS-kényszerítés és a
   `Strict-Transport-Security` sor elől.
8. **Napi adatbázis-mentés**: cPanel → *Cron Jobs* → minden nap 03:00 → `php -q …/cron_mentes.php`
   (a kész parancsot az Admin → Adatbázis-mentések oldal mutatja) – részletesen a 8. fejezetben.

Saját szerveren (root joggal) a `sql/schema.sql` elején lévő kommentelt `CREATE DATABASE` / `CREATE USER` sorok
kikommentezésével egyben is futtatható: `mysql -u root -p < sql/schema.sql`.

## 4. iPhone-on kényelmesen

Safari → *Megosztás* → **Kezdőképernyőhöz adás**. Ekkor teljes képernyős appként indul, a banán ikonnal.
A jobb alsó kerek gomb mindig az adott szint „új” gombja (gyár = új cég, masni = új kötés, + = új számla).

## 5. Hogyan működik (röviden)

### Bejövő számlák
* **Cég → Kötések → Számlák.** Kötés azonosító: `ÉÉÉÉ-PÉNZNEM-000001` (pl. `2026-HUF-000001`), a számla ehhez
  kap `-K0001`, `-K0002`… sorszámot (`2026-HUF-000001-K0002`). A sorszámok soha nem ismétlődnek, törlés után sem.
* A **számlaszám** szabad formátum, a rendszer **gépelés közben azonnal ellenőrzi**. Az összevetés normalizált:
  `SZ-2026/17`, `sz 2026 17` és `SZ202617` ugyanannak számít. A szabály (1.9-től):
  * **ugyanabban a kötésben** ugyanaz a számlaszám **csak egyszer** szerepelhet – a mező pirosra vált, a mentés gomb
    **inaktív** lesz (⛔ „Ebben a kötésben már szerepel…”); az adatbázisban UNIQUE index (kötés + számlaszám) is védi;
  * **másik kötésben** (vagy a kimenő oldalon) ugyanaz a számlaszám **rögzíthető** – a mező narancssárga
    **figyelmeztetést** kap (⚠ „ez a számlaszám már szerepel: 2026-EUR-000001-K0001 – cég (bejövő, … kötés)”), de a
    mentés engedélyezett. Példa: az `sz-2026/1010` a `2026-EUR-000001-K0001`-nél már megvan → a `2026-EUR-000001`
    kötésbe nem vihető fel újra, a `2026-EUR-000002`-be a figyelmeztetéssel igen.
  * A **kimenő** számlák számlaszáma a kimenők között egyedi (tiltás), bejövővel egyezés csak figyelmeztetés.
  * Az Excel importnál a máshol (más cégnél / kimenő oldalon) már szereplő számlaszám alapból változatlanul
    importálódik („Importálás változatlanul”), a régi kihagyás / toldalék opciók megmaradtak.
* Számla adatai: számlaszám, számla kelte, teljesítési dátum, fizetési határidő, összeg (**lehet negatív**),
  BESZÁM (szabad szöveg, pl. a raktári ellenőrző irat száma), megjegyzés.
* Státuszok: **FIZETENDŐ → UTALÁSHOZ ADVA → FIZETVE** (negatív összegnél **BESZÁMÍTVA**, ami a FIZETVE-vel egyenértékű).
  UTALÁSHOZ ADVA jelvény fölé húzva az egeret (vagy rákoppintva) az utalás UID-ja látszik; FIZETVE/BESZÁMÍTVA
  sorában az UID mindig látszik és kattintható.
* Kötés státusz: **NYITOTT / FIZETETT** (ha minden számlája FIZETVE vagy BESZÁMÍTVA). A kötés sorában:
  fizetendő, utalás alatt, fizetett és teljes érték.
* **Státusz-fülek (1.11)** – ugyanaz a megoldás, mint a kimenő számláknál, kötés- és számlaszinten is:
  * a cég oldalán a kötések felett **NYITOTT · FIZETETT · MIND** (a fülön a darabszám is látszik) – egy gombnyomásra
    csak a nyitott, csak a fizetett vagy az összes kötés;
  * a kötés oldalán a számlák felett **NYITOTT · FIZETVE (+ beszámítva) · MIND** – nyitott = FIZETENDŐ és UTALÁSHOZ
    ADVA, fizetve = FIZETVE és BESZÁMÍTVA. A „Számlák” fejlécben `2 / 5 db` mutatja, hogy szűrt lista látszik.
  * Alapból mindkét helyen a **NYITOTT** fül aktív (mint a kimenő oldalon); a választás a munkameneten belül
    megmarad. Ha egy linkről vagy a keresőből egy konkrét számlához ugrasz, a fül magától átvált arra, ahol a számla
    látszik. Az időszak-szűrő és a „Mind a kosárba” a fül szerint látható számlákon dolgozik.
* **UTALÁSHOZ gomb** a kötés sorában (minden FIZETENDŐ számláját hozzáadja) és a számla sorában.
  Hozzáadáskor felugró ablak: *UTALÁSHOZ ADVA! UTALÁS ÖSSZEGE EDDIG: …*
* **Utalás dátuma:** FIZETVE / BESZÁMÍTVA számlánál a kártyán zölddel látszik *Utalva: 2026.09.25.* (az utalás
  teljesítésének dátuma; az utalás visszanyitásakor törlődik). A régi, Excelből FIZETVE-ként importált számláknál
  nincs ilyen dátum, ott `–` áll.
* **Részteljesítés** (lásd lent, a kimenő számláknál részletesen): FIZETENDŐ számlára a ceruza ikonnal nyíló
  szerkesztő ablak *Részteljesítések* részében, a **+** gombbal vezethető fel. A számla FIZETENDŐ marad, a kártyán
  a **főérték a hátralék** (eredeti összeg − részteljesítések), alatta az eredeti összeg és a részfizetések.
  Utaláshoz adva a számla **hátraléka** kerül az utalásba (a határértékes utalás is ezzel számol); a kötés
  *Fizetett* értéke a részteljesítéseket is tartalmazza. UTALÁSHOZ ADVA számlára nem vezethető fel részteljesítés
  (előbb ki kell venni az utalásból), és negatív (jóváíró) számlára sem.
* **Részteljesítés a bejövő oldalon – kapcsoló (1.10):** alapból **kikapcsolva**, a bejövő számlák szerkesztő
  ablakában nincs részteljesítés-szakasz (a kimenő oldalon változatlanul van). Admin → *Részteljesítés* kártya →
  kapcsoló: bekapcsolva a bejövő számlákra is felvezethető részteljesítés, pontosan úgy, mint a kimenőknél. A korábban
  felvezetett bejövő részteljesítések kikapcsolt állapotban is látszanak, csak újat nem lehet felvinni.
* **Alapértelmezett pénznem:** kötés és kimenő számla létrehozásakor a választó **EUR**-n áll (1.10-től), HUF egy
  érintéssel választható.
* A kötés- és számlakártyák adatsorai (kelt, teljesítés, határidő, BESZÁM…) 14 px-es fekete betűvel jelennek meg; a kártya
  alján a „Létrehozta / Utoljára módosította” sor szándékosan maradt kisebb, halvány.

### Utalások
* Cégenként, az **UTALÁSOK** gomb alatt. UID: `U-PÉNZNEM-ÉÉÉÉ-000001` (pl. `U-EUR-2026-000001`).
* **Egy utalás vagy HUF, vagy EUR** – más pénznemű számla hozzáadását a rendszer elutasítja
  (a szerver oldalon is ellenőrzi, nem csak a felületen).
* **Manuális utalás**: üres utalás, a kötések/számlák UTALÁSHOZ gombjával töltöd fel.
  Ha egy cégnél több nyitott utalás is van azonos pénznemben, a rendszer megkérdezi, melyikhez adja
  (a választást a böngésző-munkamenet végéig megjegyzi).
* **Határértékes utalás**: megadsz egy felső limitet (pl. 100 000 EUR); a rendszer a legrégebb óta várakozó
  FIZETENDŐ számlákat gyűjti (fizetési határidő, majd kelte szerint), az összeg soha nem lépi túl a limitet.
  Ami nem fér bele, azt kihagyja és a következővel folytatja. Előnézet → pipák → *Utalás létrehozása*.
* **Utalás teljesítve** (dátum + banki hivatkozás) → a számlák FIZETVE/BESZÁMÍTVA státuszba kerülnek.
  Irodavezető vagy admin vissza tudja nyitni. Nyitott utalás törölhető (a számlák visszakerülnek FIZETENDŐ-be) – szintén
  irodavezető / admin.
* **Aktuális utalás – bankkártya gomb (1.10):** amint egy számlát utaláshoz adsz (UTALÁSHOZ gomb, „Számla hozzáadása”,
  határértékes utalás), a jobb alsó sarokban a nyomtató és a + gomb **felett** megjelenik egy narancs, bankkártya-ikonos
  gomb, rajta a számlák darabszámával. Bárhol, bármelyik oldalon rákattintva egy ablakban látod az utalás tartalmát
  (azonosító, cég, végösszeg, kötésenként a számlák a hátralékkal) – **az oldal nem navigál el**. Az ablakból számlát ki is
  vehetsz az utalásból, vagy az *Utalás megnyitása* gombbal átugorhatsz rá. A gomb addig marad, amíg az utalás nyitott;
  teljesítés vagy törlés után eltűnik (felhasználónként, a böngésző jegyzi meg).
* **Melyik utaláshoz gyűjtsön a bankkártyás gomb? (1.13)** A *Minden utalás* nézetben (és a cég utalásainál) minden
  nyitott, nem lakatolt utalás kártyáján van egy **„Ehhez gyűjtök”** gomb: rákattintva az lesz az aktuális gyűjtő
  (a kártyán *AKTUÁLIS GYŰJTŐ* jelvény), és az **UTALÁSHOZ** gombok kérdés nélkül ebbe teszik a számlát, ha a számla
  cége és pénzneme egyezik vele (más cég / pénznem esetén a megszokott módon kérdez vagy új utalást ajánl). Ugyanez a
  gyűjtő ablakban is: *Másik nyitott utaláshoz gyűjtök…* lista. Az utalás oldalán is ott az „Ehhez gyűjtök” gomb.
* **Lakat (1.13):** ha egy nyitott utaláshoz befejezted a gyűjtést, az utalás oldalán (vagy a gyűjtő ablakban) a
  **Lelakatolás** gombbal lelakatolhatod – a státusza **NYITOTT marad**, de nem lehet bele számlát tenni, kivenni belőle,
  és nem törölhető (az UTALÁSHOZ gombok átugorják, és ha minden nyitott utalás lakatolt, új utalást ajánlanak). A
  lelakatolt utalás **teljesíthető** (Utalás teljesítve). A lakat bármikor kinyitható a **Lakat kinyitása** gombbal.
  A lakat a listákban is látszik (fekete-sárga LELAKATOLVA jelvény). Rögzítő vagy magasabb szerep lakatolhat és nyithat;
  a naplóba UTALAS_LEZAR / UTALAS_KINYIT kerül, az utalás oldalán látszik, ki és mikor lakatolta le.
* **A lakat elengedi a gyűjtőt (1.13.1):** ha az aktuális gyűjtő-utalást lakatolod le (az utalás oldalán vagy a gyűjtő
  ablakból), a bankkártyás gomb **eltűnik**, és egyik utalás sem marad „aktuális gyűjtő” – a gyűjtés véget ért, a gomb
  nem zavar más munka közben. Ha közben más gépen lakatolták le (vagy teljesítették), a gomb a következő megnyitáskor
  jelzi és elengedi. A lakat kinyitása **nem** választja vissza automatikusan: ha újra gyűjtenél bele, nyomd meg az
  „Ehhez gyűjtök” gombot (vagy adj hozzá számlát az UTALÁSHOZ gombbal).
* Az utalás összesítő nézete: UID, végösszeg, kötésenként a hozzáadott számlák és részösszegek.
* Főoldal → **Minden utalás**: az összes utalás cégtől függetlenül, szűrhetően.

### Kimenő számlák
* Cég → számlák. Azonosító: `ÉÉÉÉ-PÉNZNEM-000001`. Adatok: számlaszám (szabad; a kimenők között egyedi – tiltás,
  bejövővel egyezés csak figyelmeztetés),
  teljesítés, kelte, fizetési határidő, összeg, pénznem. Státusz: **NYITOTT / FIZETVE**.
* A cégnél látszik, mennyivel tartozik (pénznemenként).
* **Banki megfeleltetés gyűjtő (1.12):** a számla elején lévő **kerek gombbal** (vagy a *Bankazonosítóhoz* gombbal) a
  NYITOTT számla a **banki gyűjtőbe** kerül – a jobb alsó sarokban megjelenik a sárga, **oszlopos bank-épület** ikonos
  gomb (a nyomtató és az utalási gyűjtő felett), rajta a gyűjtött számlák számával. Ugyanúgy működik, mint az utalási
  gyűjtő: bármelyik oldalon látszik, több cég számlája is gyűjthető, a böngésző megjegyzi. Rákattintva egy ablak mutatja
  a gyűjtött számlákat cégenként (kivétel ×, Ürítés), és a **Bankazonosítóhoz adás (N)** gombbal jön a megszokott ablak:
  dátum + banki azonosító → a számlák FIZETVE státuszt kapnak, a gyűjtő kiürül. Ha egy gyűjtött számla közben máshol
  fizetve lett, az ablak kiveszi és jelzi. (A korábbi nagy alsó gomb megszűnt.)
* A banki azonosítót gépelés közben ellenőrzi. Ha már használtad: *„Itt valami nem stimmel! Ezt a banki azonosítót
  már használtad ezekhez a számlákhoz: … Biztos, hogy a mostani számlákat is ez az azonosító fedezi?”*
  → *Igen* → **„Biztos?” 5 másodperces visszaszámlálóval**, az IGEN csak utána aktív.
* Irodavezető vagy admin a fizetést vissza tudja vonni (számla vissza NYITOTT-ba).
* **Fizetés dátuma:** FIZETVE számlánál a kártyán zölddel *Fizetve: 2026.03.28.* + a banki azonosító.
* **Részteljesítés** – ha egy NYITOTT számlára csak részösszeg érkezik: a számla sorában a **ceruza** ikon →
  a szerkesztő ablak alján **Részteljesítések** rész, alapból csak egy zöld **+** gomb. A + megnyomására három mező
  jelenik meg: **összeg** (kötelező), **utalás dátuma** (kötelező, alapból a mai nap), **banki azonosító** (nem
  kötelező) → *Részteljesítés felvezetése*. Azonnal mentődik (naplózva `RESZTELJESITES`), akárhány részteljesítés
  felvihető. A számla **NYITOTT marad**, de a **főértéke a hátralék** lesz: 1 000 EUR-os számla + 100 EUR
  részteljesítés → a kártyán **900 EUR (HÁTRALÉK)**, alatta *Eredeti összeg: 1 000 EUR · részteljesítés: −100 EUR*
  és a részfizetések felsorolása (összeg · dátum · banki azonosító). A részteljesítés nem lehet nagyobb vagy egyenlő
  a hátraléknál – ha a teljes hátralék megjött, a számlát a rendes úton (bankkivonati azonosítóhoz rendelés) kell
  FIZETVE-nek jelölni; ekkor a banki azonosítóhoz a hátralék tartozik. A részteljesítés a szerkesztő ablakban
  a kuka ikonnal törölhető, amíg a számla nyitott. A számla összege nem módosítható a részteljesítések összege alá,
  és részteljesítéssel rendelkező számla nem törölhető (előbb a részteljesítéseket kell törölni).
  A tartozás-összesítő („Ennyivel tartozik nekem”), a cégek listája, a fizetési határidők, az összevetés és a
  főoldali kereső is a hátralékkal számol.
* **MIND (egyenleg készítés) fül:** az összes számla (nyitott és fizetett) egy listában. Ha itt az időszak-szűrővel
  leszűröd a számlákat, és a **„Mind a kosárba – egyenleg”** gombbal teszed őket a nyomtatási kosárba, a PDF-ben a
  kimenő számlák alatt egy külön **EGYENLEG** táblázat készül a partnernek (lásd a Nyomtatás résznél).

### Fizetési határidők (főoldal)
* Minden FIZETENDŐ bejövő és NYITOTT kimenő számla **lejárat szerint növekvő** sorrendben (a legsürgősebb elöl);
  lejárt = piros csík, 7 napon belül = sárga csík.
* Oszlopok: fizetési határidő; cég; számlaszám; összeg; pénznem; kötés+számla ID; irány.
* Kattintás: **cégnév** → csak az adott cég; **pénznem** → csak az adott pénznem; a kettő **egymásra épül**
  (bármilyen sorrendben). Felugró üzenet mondja, mire van szűrve. *Alaphelyzet* gomb törli.
  **Kötés ID** → ugrik a kötéshez; **számlaszám** → ugrik a számlához (kiemelve).
* Az „utaláshoz adott” (még ki nem fizetett) számlák alapból rejtve vannak, egy gombbal bekapcsolhatók.

### Kereső (főoldal) – bármit beírva megtalálja a számlát
A főoldalon a *Minden utalás* / *Fizetési határidők* gombok **alatt** egy nagy **„Kereső”** mező van. Ez azoknak is
megoldás, akik nem igazodnak el a Bejövő / Kimenő irányokon: bármit beírnak, a rendszer megmutatja, mi az, és egy
kattintással odaugrik.
* **3 beírt karaktertől** gépelés közben magától keres (kb. negyed másodperc szünet után), a találatokat listázza –
  **soha nem ugrik magától**, csak ha rákattintasz egy találatra (billentyűzettel: ↑ ↓ + Enter). Enter a telefonon
  csak a billentyűzetet tünteti el, hogy a lista látszódjon.
* **Mire keres** (töredékre is, kis/nagybetű és ékezet nem számít):
  * teljes rendszer-azonosító: `2026-EUR-000001-K0001` (bejövő számla), `2026-EUR-000001` (kötés / kimenő számla),
    `U-EUR-2026-000001` (utalás) – vagy ezek egy darabja (`K0001`, `000043`);
  * szabadkezes számlaszám: `2626335`, `2611240 [ANTON DÜRBECK]`, `926301189365/2026` – elválasztók nélkül is
    (`bank-2026-0002` megtalálja a `BAN-K-2026/0002`-t);
  * **cégnév**, partnerkód, adószám → a **céghez** ugrik: külön sor a *bejövő számláihoz* (kötések) és – ha van –
    a *kimenő számláihoz*;
  * **régi K** azonosító (`85V7`), **régi kötés ID** (a kötéshez ugrik), BESZÁM, kötés megnevezése, banki azonosító,
    banki hivatkozás;
  * **pontos összeg**: `55 EUR`, `4003,20 EUR`, `4 003,20`, `120 000 Ft`, `-11,74` (pénznem nélkül mindkét pénznemben
    keres; előjel nélkül a negatív számlát is megtalálja);
  * **dátum**: `2026.03.15`, `2026-03-15`, `2026. 03. 15.`, egy egész hónap (`2026.03`) vagy egy nap bármely évből
    (`03.15`) – kelt, teljesítés, határidő és fizetés dátumára.
* A találatok **típusonként** csoportosítva jönnek (Bejövő számlák, Kimenő számlák, Cégek, Kötések, Utalások),
  minden sorban látszik a cég, a számlaszám, az összeg, a státusz és a kelt; a **talált részlet sárgával kiemelve**,
  és zöld címke mondja meg, ha nem a számlaszám/azonosító, hanem pl. a *határidő 2026.03.15.* vagy az *összeg*
  egyezett. Típusonként az első 8 találat látszik (a legpontosabb egyezés elöl) – ha több van, a fejléc jelzi, és
  érdemes többet beírni. Ha nincs találat, a rendszer tanácsot ad (rövidebb részlet, cégnév eleje, pontos összeg).
* Kattintás után a **számla kiemelve** jelenik meg a saját kötésében / a cég kimenő listájában (a kimenő lista a
  megfelelő fülre – NYITOTT / FIZETVE – vált át). A *Vissza* gombbal a főoldalra lépve a kereső **emlékszik** az
  utolsó keresésre és a találatokra, a × gombbal törölhető.
* Napló: `KERESES` (mit kerestek, hány találat), `KERESES_UGRAS` (melyik találatra kattintottak).

### Összevetés / Összesítő
* Cég kiválasztása → azonnal: egyenleg EUR-ban és HUF-ban (bejövő nyitott − kimenő nyitott;
  **pozitív = én tartozom, negatív = ő tartozik**), alatta kisbetűvel az MNB-árfolyamos átszámítás
  („összesen ≈ … HUF ≈ … EUR”).
* Pénznem + **kötelező** teljesítési dátum tól–ig → részletes lista két oszlopban: bejövő számlák kötésenként
  és kimenő számlák, NYITOTT és FIZETVE egyenleggel az időszakra.
* *Banki kivonatok* fül: keresés banki azonosítóra, egy kattintásra látszik, mely kimenő számlákat fedi le.
* Árfolyam: **MNB hivatalos** (SOAP webszolgáltatás) → ha nem elérhető: ECB (frankfurter.app) → open.er-api.com →
  admin által beállított kézi árfolyam. 6 óránként frissül, az admin kézzel is frissítheti.
  (Ha a tárhely nem enged kimenő HTTP-hívást, állítsd be a kézi árfolyamot az Admin oldalon.)

### Nyomtatás – PDF a listákból
* **Kétnyelvű PDF (1.9-től):** minden magyar felirat alatt halványabb, kisebb betűvel az angol megfelelője –
  oszlopfejlécek (Számla ID / Invoice ID, Határidő / Due date, Utalva / Transferred…), szakaszcímek (Bejövő
  számlák / Incoming invoices), státuszok (FIZETVE / PAID, FIZETENDŐ / PAYABLE, UTALÁSHOZ ADVA / IN TRANSFER,
  BESZÁMÍTVA / OFFSET, NYITOTT / OPEN), összesítő sorok (Összesen – teljes pénzforgalom / Total – total turnover),
  a részteljesítés- és hátralék-alsorok, az EGYENLEG blokk (BALANCE), a fej- és lábléc (Nyomtatási lista / Printed
  list, bizalmas / confidential). A magyar szöveg, az oszlopok és a formátum változatlan, csak a fordítás került alá.
* **Minden lista minden sorának végén** van egy kis nyomtató gomb (cégek, kötések, bejövő és kimenő számlák, utalások,
  az utalás számlái, fizetési határidők, összevetés sorai, banki kivonatok). Megnyomva a sor a **nyomtatási kosárba**
  kerül (a gomb sárga lesz, zöld pipával), újra megnyomva kikerül. Bármelyik listából gyűjthetsz, vegyesen is.
* A főoldalon kívül **minden oldalon** a jobb alsó sarokban, a fő gomb (gyár / kötél / plusz) **felett** ül egy ugyanolyan
  kerek **nyomtató gomb**. Üres kosárnál szürke (inaktív), gyűjtött sorokkal sötét, és a sarkán **piros körben fehér
  szám** mutatja, hány sor van a kosárban (mint a Facebook értesítés-jelzője).
* A nyomtató gombra nyomva a rendszer **A4-es, helytakarékos PDF-et** készít a kosár tartalmából, típusonként csoportosítva
  (Cégek, Kötések, Bejövő számlák, Utalások – a számláikkal, Kimenő számlák, Banki kivonatok – a fedezett számlákkal),
  pénznemenkénti összesítőkkel, fejléccel (dátum, ki készítette, hány tétel) és oldalszámmal. Új lapon nyílik meg,
  onnan nyomtatható vagy menthető (iPhone-on a Megosztás gombbal).
* A kosár a **böngészőben** marad meg (felhasználónként külön), tehát oldalváltás vagy újratöltés után is megvan;
  a PDF viszont mindig az **adatbázis friss adataiból** készül. Kezelés: menü → *Nyomtatási kosár* (tételek kivétele,
  ürítés, PDF), vagy a PDF utáni üzenet *Kosár ürítése* linkje. Egy kosárba legfeljebb 500 sor kerülhet.
* A PDF-készítés is naplózódik (`PDF_NYOMTATAS`). A PDF-hez nem kell külső könyvtár vagy szerverbeállítás
  (saját PDF-író, beágyazott betűk – az ő/ű is jó).
* **Fizetés dátuma a PDF-ben:** a bejövő táblázatban a Határidő mellett **Utalva** oszlop (FIZETVE / BESZÁMÍTVA
  számlánál az utalás dátuma), a kimenő táblázatban **Fizetve** oszlop – bárhonnan kerül a számla a kosárba
  (kötés, cég, határidők, kereső, utalás, kivonat…).
* **Részteljesítések a PDF-ben:** ha egy számlához részteljesítés tartozik, a sora alatt kis betűs alsorok mutatják
  őket (*» részteljesítés – bank: …*, dátum, −összeg), nyitott számlánál egy *» hátralék (még fizetendő)* sorral.
  Az utalás és a banki kivonat számláinál a hátralék (az utalt összeg) szerepel, mellette az eredeti összeg.
* **Bontott összesítő:** a bejövő és a kimenő táblázat végén pénznemenként három sor: *Összesen – teljes
  pénzforgalom*, *Fizetett / beszámított* (a rendezett számlák + a részteljesítések) és *Nyitott (még fizetendő)*.
* **EGYENLEG a partnernek (kimenő, MIND fül):** a kimenő számlák oldalán a **MIND (egyenleg készítés)** fülön
  szűrd le az időszakot, majd **„Mind a kosárba – egyenleg (N)”**. A kosárban ezek a sorok *egyenleg* jelölést
  kapnak, és a PDF-ben a kimenő táblázat után cégenként egy narancssárga **EGYENLEG – cég neve** blokk jelenik meg
  (időszak, számlák száma), pénznemenként: **Összes pénzforgalom · Fizetett / beszámított · ebből részteljesítés ·
  NYITOTT – még fizetendő**. Ez nyomtatható a partnernek: látja, mennyit fizetett, mennyi a teljes összeg, és mennyit
  kell még fizetnie. (A nyomtató gombbal egyesével kosárba tett kimenő számlákhoz nem készül egyenleg – csak az
  „egyenleg” gombbal hozzáadottakhoz.)
* **Időszak szerint, egy gombbal a kosárba:** a számlalisták tetején egy **„Számlák időszak szerint → nyomtatási
  kosárba”** sáv van: választasz dátummezőt (**Kelt / Teljesítés / Határidő**), megadod a **-tól / -ig** dátumot
  (alapból az idei év eleje → ma), majd **Szűrés**. A lista csak a találatokat mutatja, a sáv kiírja a darabszámot és
  pénznemenként az összeget, és a sárga **„Mind a kosárba (N)”** gomb egyszerre teszi az összes találatot a
  nyomtatási kosárba (ami már benne volt, nem duplázódik). *Szűrés törlése* visszaadja a teljes listát. Három helyen:
  * **kötés oldala** (egy kötés számlái),
  * **cég oldala** (a kötések listája felett): **„A cég számlái időszak szerint (minden kötésből)”** – a cég
    *összes* kötésének számláit gyűjti ki az adatbázisból az időszakra (pl. 2026.03.15 – 2026.09.20), listázza
    őket (ugrás a számlához, egyenkénti nyomtató gomb is), és egy gombbal a kosárba teszi,
  * **kimenő számlák** listája.
  Utána a jobb alsó nyomtató gombbal jön a PDF, mint eddig.

### Változásnapló – ki, mikor, mit módosított (kötés és számla)
* Minden **kötés- és számlakártya alján, középen** látszik: *Létrehozta X · 2026.09.30. 14:05* és – ha volt
  módosítás – *Utoljára módosította Y · dátum óra:perc* (percre pontosan).
* Minden kötés- és számlakártyán (bejövő és kimenő) a ceruza / nyomtató gomb **bal oldalán** egy **könyv ikon** van:
  megnyitja a rekord teljes történetét a létrehozástól kezdve, időrendben: mikor, ki, mit csinált – **Létrehozás**
  (a rögzített adatokkal), **Módosítás** (mezőnként: régi érték → új érték, pl. Összeg 415 000 HUF → 416 000 HUF),
  **Státuszváltás** (utaláshoz adva / kivéve / utalás teljesítve / visszanyitva; kimenőnél bankkivonati azonosítóhoz
  rendelés és fizetés visszavonása, a dátummal és azonosítóval), **Részteljesítés** (felvezetés / törlés, hátralék
  előtte → utána), **Áthelyezés** (archív kötésből kötésbe: régi → új kötés és azonosító), **Törlés**, **Excel import**.
* A **kötés naplójában** a kötés saját változásai mellett a hozzá tartozó (és a már törölt) számlák eseményei is
  látszanak, behúzva – így egy helyen ellenőrizhető, ki mikor mit csinált a kötéssel és a számláival.
* A frissítéskor a meglévő rekordok létrehozása visszamenőleg bekerül (aki és amikor rögzítette); a korábbi
  módosításokról egy „Korábbi módosítás (részletek nem ismertek)” bejegyzés készül az utolsó módosítás idejével.
  Tábla: `valtozasnaplo` (a napi DB-mentés ezt is menti). Napló: `VALTOZASNAPLO` (megtekintés).

### Szerepkörök és jogosultságok (1.10)
Minden felhasználónak egy szerepköre van (Admin → Felhasználók → ceruza → Szerepkör). A jogokat a **szerver** ellenőrzi
minden művelet előtt (`includes/jogok.php`), a felület csak azt mutatja, amit az adott szerep megtehet.

| Szerepkör | Mit tehet |
|---|---|
| **Admin** | Mindent láthat és módosíthat, minden adminjoggal rendelkezik: felhasználók, eszközök, beállítások, napló, DB-mentés / visszaállítás, cron-kulcs. |
| **Irodavezető** | Minden adatművelet: felvitel, szerkesztés, **törlés** is, utalás visszanyitása, fizetés visszavonása, Excel import. Adminjoga nincs: nem adhat hozzá felhasználót, nem végezhet biztonsági műveletet (más eszközeinek törlése, regisztrációs kód), DB-visszaállítást, beállítás-módosítást. |
| **Rögzítő** | Új adatot felvihet (cég, kötés, számla, utalás, részteljesítés, import), a meglévőket szerkesztheti, utaláshoz adhat, utalást teljesíthet, kimenő számlát fizetve-re állíthat. **Semmit nem törölhet** (számla, kötés, utalás, részteljesítés, visszavonások). A más által írt megjegyzést nem írhatja át és nem törölheti, csak hozzáfűzhet. Csak a saját eszközeit és jelszavát kezelheti. |
| **Üzletkötő** | Mindent lát és megnézhet, szűrhet, keres, PDF-et készít bármiről. **Nem vihet fel, nem módosít, nem töröl** semmit. Két kivétel: a bejövő számla **BESZÁM** mezőjét beírhatja, ha üres, és módosíthatja, de üresre nem állíthatja; a **megjegyzés** rovatokhoz (cég, kötés, számla, utalás) hozzáfűzhet – a más által írt szöveg érintetlen marad. A ceruza gomb nála csak ezt a két mezőt nyitja meg. |

* A régebbi telepítések „felhasználó” szerepű fiókjai a frissítéskor **Irodavezető** jogot kapnak (ez felel meg az addigi
  működésüknek) – az Admin oldalon állítsd át őket Rögzítőre / Üzletkötőre, ha kell. Új felhasználó alapból Rögzítő.
* **Megjegyzés-szabály:** a rendszer megjegyzi, ki írta utoljára a megjegyzést. Rögzítő és Üzletkötő a saját szövegét
  szabadon átírhatja; ha a szöveget más írta (vagy többen írták), a szerkesztő ablakban a régi szöveg csak olvasható,
  alatta egy új mezőbe lehet hozzáfűzni (új sorként kerül a régi alá). Irodavezető és Admin bármit átírhat.
* A menü fejlécében látszik a szereped; az Admin oldalon a felhasználók listája szerepkör-jelvénnyel, az űrlapon a
  szerepek rövid leírásával.

### Felhasználók, napló, biztonság
* **ADMIN**: felhasználókat hoz létre/tilt le/jelszót állít, szerepkört ad, kézi árfolyam, beállítások (import előtti
  mentés, bejövő részteljesítés), napló megtekintése, DB-mentések. A többi szerepkör jogait lásd fent.
* **Napló**: minden művelet (belépés, létrehozás, módosítás, törlés, megtekintés is) időbélyeggel:
  `LOG/ÉÉÉÉHHNN.txt`, sor: `[2026-09-29 14:03:22] felhasználó | IP | MŰVELET | EREDMÉNY | részletek`.
  **Minden nap 03:00-kor nyílik új fájl** (02:59-ig még az előző napiba ír) – cron nem kell hozzá.
* Titkosság: `robots.txt` (minden tiltva), `<meta name="robots" content="noindex…">`, `X-Robots-Tag` fejléc,
  ismert kereső- és AI-robotok tiltása `.htaccess`-ben, és minden adat csak bejelentkezés után érhető el.
  A `includes/`, `sql/`, `LOG/`, `DBBCKP/` mappák webről nem olvashatók.
* Belépés: QR-kód + telefonos biometria (passkey), lásd 6. fejezet. Jelszó csak tartalék, és csak eszköz nélküli fiókhoz.
* Brute-force védelem: 8 hibás belépés után 15 perc tiltás (felhasználónévre és IP-re is), QR-kérések IP-nként korlátozva.
  Jelszavak bcrypt-tel tárolva. Munkamenet-süti HttpOnly + SameSite, HTTPS-en Secure.
  CSRF-token minden API-hívásnál, szigorú Content-Security-Policy.

### A belépés a böngésző bezárásáig él (1.14)
* **A böngésző bezárásával a belépés megszűnik.** A munkamenet-sütinek nincs lejárati dátuma (a böngésző a
  bezáráskor törli), és a szerver is figyeli, hogy a lap nyitva van-e:
  * a nyitott lap **30 másodpercenként jelez** a szervernek („szívverés”);
  * a lap vagy a böngésző bezárásakor a lap egy **„bezárva” jelzést** küld – utána a belépés már csak
    **3 percig** nyitható vissza (ez a véletlenül bezárt lap visszahozására való: ugyanabban a böngészőben
    nyisd meg újra az oldalt, és belépve maradsz);
  * ha **10 percig semmilyen jelzés** nem jön (a böngészőt kilőtték, a gép elaludt, a telefonon másik app van
    elöl), a belépés lejár;
  * ha **8 órán át nincs tevékenység** (kattintás, gépelés), a nyitva felejtett lapot is kilépteti.
* A kiléptetés után a belépő képernyő megmondja az okát („A böngésző vagy a lap be lett zárva…”, „…nem volt
  nyitva…”, „…nem volt tevékenység…”), a felhasználónév előre ki van töltve – csak újra be kell lépni
  (telefonos jóváhagyás / Face ID, vagy tartalékként jelszó).
* Az időkorlátok az `includes/config.php`-ban állíthatók: `ULES_JELENLET_MP` (30), `ULES_LAPZARAS_PERC` (3),
  `ULES_CSEND_PERC` (10), `ULES_TETLENSEG_ORA` (8).
* **Böngésző-beállítás (ajánlott):** Chrome-ban az *Indításkor → Folytatás ott, ahol abbahagytam*, Edge-ben az
  *Előző munkamenet lapjainak megnyitása*, Firefoxban az *Előző ablakok és lapok megnyitása* legyen **kikapcsolva**
  (ez az alapértelmezés). Ezek a beállítások ugyanis a bezárt böngésző munkamenet-sütijeit is visszaállítják – ilyenkor
  csak a 3 perces türelmi idő és a 10 perces csend-korlát véd, azonnali kilépés nincs.
* Telefonon (iPhone Safari) a lap „bezárása” a másik appra váltás: ilyenkor a 10 perces csend-korlát után kell újra
  belépni (Face ID – egy érintés).

### A böngésző nem menti el a jelszót (1.14)
* A jelszómezők (belépés, saját jelszó módosítása, admin felhasználó-űrlap) **nem** a böngésző „jelszó” típusú
  mezői, hanem maszkolt szövegmezők (a karakterek pöttyként látszanak, a szem gombbal megmutathatók), és
  `autocomplete="off"`-osak – így a Chrome / Safari / Edge / Firefox **nem ajánlja fel a jelszó mentését** és nem is
  tölti ki. (Nagyon régi Firefoxban, ahol a maszkolás nem támogatott, klasszikus jelszómező jelenik meg.)
* A korábban már elmentett jelszót töröld a böngésző jelszókezelőjéből: Chrome → `chrome://password-manager`,
  Safari → Beállítások → Jelszavak, Edge → `edge://wallet/passwords`, Firefox → `about:logins`.
* Ez a jelszavas (tartalék) belépésre vonatkozik – a passkey (Face ID / ujjlenyomat) nem jelszó, azt továbbra is
  a telefon biztonságos tárolója őrzi.

## 6. Jelszó nélküli belépés – QR-kód + telefonos biometria (passkey)

**Hogyan működik (a felhasználó szemével):**
1. A gépen beírja a felhasználónevét, *Belépés* → a képernyőn egy **QR-kód** és egy **egyeztető szám** jelenik meg
   (a kód egyszer használható, 2 percig érvényes, csak abból a böngészőből váltható be, amelyik kérte).
2. A **telefon kamerájával** beolvassa → megnyílik a „Belépés jóváhagyása” oldal: látja, ki, milyen gépről,
   milyen IP-ről kér belépést, és ugyanazt az egyeztető számot, mint a gépen.
3. *Jóváhagyás* → a telefon **Face ID-t / ujjlenyomatot** kér (a rendszer megköveteli a biometrikus / eszközzáras
   igazolást, e nélkül a szerver elutasítja) → a gép 1–2 másodpercen belül belép.
   Ha nem ő kérte: *Nem én vagyok – elutasítás*.
4. Ha a felhasználó **magán a telefonon** (vagy Touch ID-s Macen, Windows Hello-s gépen) nyitja meg az oldalt,
   QR nélkül, közvetlenül a *Belépés ezen az eszközön* gombbal lép be.

**Technológia:** WebAuthn / passkey szabvány (ugyanaz, amit a Google, Apple, Facebook használ). A titkos kulcs
a telefon biztonsági chipjében marad, a szerver csak a **nyilvános kulcsot** tárolja (`passkeyek` tábla) –
nincs jelszó, amit el lehetne lopni vagy kiadathatnának adathalász oldalon (a passkey a domainhez kötött).
A szerver minden belépésnél ellenőrzi: challenge, origin, rpIdHash, UP+UV jelzőbitek (biometria), aláírás
(ES256/RS256, OpenSSL), aláírás-számláló (klónozott hitelesítő ellen). A belépési kérelmek 144 bites véletlen
tokenek, egyszer használatosak, lejárnak, és a kérő böngésző munkamenetéhez kötöttek. Minden lépés naplózva.

**Követelmények:**
* **HTTPS kötelező** (Let's Encrypt a cPanel-ben ingyenes). HTTP-n a böngészők nem engedik a passkey-t
  (a belépő oldal ilyenkor figyelmeztet). Kivétel: `localhost` teszteléshez.
* A QR-kódot ugyanazon a domainen kell megnyitni, ahol az oldal fut – ez automatikus, mert a QR maga az oldal linkje.
  Ha más domainről vagy portról érnéd el, állítsd be az `includes/config.php`-ban:
  `WEBAUTHN_RP_ID` (pl. `banina.cegem.hu`), `APP_ORIGIN` (pl. `https://banina.cegem.hu`), `APP_URL`.
* Telefon: iPhone iOS 16+ (Safari), Android 9+ (Chrome) eszközzárral / biometriával.

**Első beállítás (egyszer):**
1. Az admin belép a **jelszavával** (`admin` / a setup-ban megadott jelszó) – jelszóval csak addig lehet belépni,
   amíg a fiókhoz nincs regisztrált eszköz.
2. Menü → *Profil · eszközeim* → **„Ezt az eszközt regisztrálom”** (a telefonon, Face ID-val / ujjlenyomattal).
   Ettől kezdve az admin fiókba jelszóval **nem** lehet belépni, csak QR + biometriával.
3. Új felhasználó: Admin → *Új felhasználó* (a jelszó csak tartalék), majd a sorában **„Kód”** →
   megjelenik egy 15 percig érvényes, egyszer használatos **eszköz-regisztrációs QR-kód / link**.
   A felhasználó a telefonjával beolvassa, Face ID-val / ujjlenyomattal regisztrál, és már be is lépett.
4. Több eszköz (pl. iPhone + Mac Touch ID): *Profil* → „Ezt az eszközt regisztrálom” bármelyik bejelentkezett eszközön.

**Ha elveszett a telefon:** Admin → a felhasználó sorában a kuka ikon törli az összes eszközét → a felhasználó
a jelszavával léphet be (ha van), vagy új regisztrációs kódot kap. A saját eszközeit a Profil oldalon bárki törölheti.

**Meglévő telepítés frissítése 1.1-ről:** futtasd le az `sql/frissites_1.2.sql` fájlt phpMyAdmin-ban,
és töltsd fel az új fájlokat (`includes/webauthn.php`, `includes/api_passkey.php`, `assets/qr.js`, frissített
`app.js`, `app.css`, `index.php`, `api.php`, `auth.php`, `config.php`).

**Frissítés 1.13.1-ről 1.14.0-ra (a belépés a böngésző bezárásáig él, a böngésző nem menti a jelszót):**
adatbázis-módosítás nincs. Töltsd fel a frissített `api.php`, `index.php`, `includes/auth.php`, `includes/api_auth.php`,
`includes/jogok.php`, `includes/config.php` (új `ULES_*` beállítások – **ha saját config.php-t tartasz, vedd át belőle
a Munkamenet szakaszt**, a régi `SESSION_LIFETIME_DAYS` / `SESSION_IDLE_DAYS` sorokra nincs többé szükség), `assets/app.js`,
`assets/app.css` és `TELEPITES.md` fájlokat. A frissítés után minden felhasználónak **egyszer újra be kell lépnie**
(a régi, 30 napos sütik érvényüket vesztik – a belépő képernyő ezt ki is írja). Kérd meg a felhasználókat, hogy a
böngészőjükben mentett BaninaPRO-jelszót töröljék (lásd 5. fejezet, „A böngésző nem menti el a jelszót”).

**Frissítés 1.13.0-ról 1.13.1-re (a lakat elengedi a gyűjtőt):** adatbázis-módosítás nincs. Töltsd fel a frissített
`assets/app.js`, `includes/config.php` (csak a verziószám) és `TELEPITES.md` fájlokat.

**Frissítés 1.12.0-ról 1.13.0-ra (utalás lakat, gyűjtő-utalás választása):** futtasd le az `sql/frissites_1.13.sql`
fájlt phpMyAdmin-ban (az `utalasok` tábla `lezarva`, `lezarta`, `lezarva_at` oszlopot kap; meglévő adat nem változik),
majd töltsd fel a frissített `assets/app.js`, `assets/app.css`, `includes/api_utalas.php`, `includes/api_cegek.php`,
`includes/jogok.php`, `includes/config.php` (csak a verziószám), `sql/schema.sql` és `TELEPITES.md` fájlokat.

**Frissítés 1.11.0-ról 1.12.0-ra (kimenő fül-hiba javítása, banki megfeleltetés gyűjtő gomb):** adatbázis-módosítás
nincs. Töltsd fel a frissített `assets/app.js`, `assets/app.css`, `includes/api_kimeno.php`, `includes/jogok.php`,
`includes/config.php` (csak a verziószám) és `TELEPITES.md` fájlokat.

**Frissítés 1.10.0-ról 1.11.0-ra (státusz-fülek a bejövő oldalon):** adatbázis-módosítás nincs. Töltsd fel a
frissített `assets/app.js`, `assets/app.css`, `includes/config.php` (csak a verziószám) és `TELEPITES.md` fájlokat.
(Ha 1.9.0-ról jössz, előbb az 1.10-es lépés – `sql/frissites_1.10.sql` –, utána ez.)

**Frissítés 1.9.0-ról 1.10.0-ra (szerepkörök, megjegyzés-szabály, bejövő részteljesítés kapcsoló, EUR alapértelmezés,
utalási kosár gomb, nagyobb kártya-betűk):** futtasd le az `sql/frissites_1.10.sql` fájlt **phpMyAdmin-ban**: a
`felhasznalok.szerep` mező új értékeket kap (admin / irodavezeto / rogzito / uzletkoto – a régi „user” fiókok
Irodavezetők lesznek), a cégek / kötések / számlák / utalások `megjegyzes_irta` oszlopot kapnak (a megjegyzés szerzője,
visszamenőleg az utolsó módosítóval kitöltve), és bekerül a `reszt_bejovo` beállítás (kikapcsolva). Meglévő adat nem
változik. Utána töltsd fel: `includes/jogok.php` (új), és a frissített `api.php`, `index.php`, `import.php`,
`includes/auth.php`, `includes/helpers.php`, `includes/api_auth.php`, `includes/api_admin.php`, `includes/api_bejovo.php`,
`includes/api_kimeno.php`, `includes/api_cegek.php`, `includes/api_utalas.php`, `includes/api_reszteljesites.php`,
`includes/api_import.php`, `includes/config.php` (csak a verziószám), `assets/app.js`, `assets/app.css`, `sql/schema.sql`,
`TELEPITES.md`. Frissítés után nézd át az Admin → Felhasználók listát, és állítsd be mindenkinek a megfelelő szerepkört.

**Frissítés 1.8.0-ról 1.9.0-ra (számlaszám kötésenként, változásnapló, kétnyelvű PDF):** futtasd le az
`sql/frissites_1.9.sql` fájlt **phpMyAdmin-ban** (UTF-8 kapcsolattal – a fájl elején `SET NAMES utf8mb4` is van):
a bejövő számlaszám UNIQUE indexe kötésenkéntire vált, létrejön a `valtozasnaplo` tábla (visszamenőleg feltöltve a
meglévő kötések / számlák létrehozásával), és a `kotesek` tábla `modositva` / `modositotta` oszlopot kap. Meglévő adat
nem változik. Utána töltsd fel: `includes/audit.php` (új), és a frissített `api.php`,
`includes/ids.php`, `includes/helpers.php`, `includes/api_bejovo.php`, `includes/api_kimeno.php`,
`includes/api_utalas.php`, `includes/api_import.php`, `includes/import.php`, `includes/api_reszteljesites.php`,
`includes/nyomtatas.php`, `includes/pdf.php`, `includes/config.php` (csak a verziószám), `assets/app.js`,
`assets/app.css`, `sql/schema.sql`, `TELEPITES.md`.

**Frissítés 1.7.0-ról 1.8.0-ra (részteljesítések, fizetés dátuma, egyenleg a PDF-ben):** futtasd le az
`sql/frissites_1.8.sql` fájlt phpMyAdmin-ban (új `reszteljesitesek` tábla + `bejovo_szamlak.fizetve_datum` oszlop,
amit a már teljesített utalások dátumával ki is tölt; meglévő adat nem változik), majd töltsd fel:
`includes/api_reszteljesites.php` (új), és a frissített `api.php`, `pdf.php`, `includes/api_bejovo.php`,
`includes/api_kimeno.php`, `includes/api_utalas.php`, `includes/api_riport.php`, `includes/api_cegek.php`,
`includes/api_kereses.php`, `includes/nyomtatas.php`, `includes/pdf.php`, `includes/config.php` (csak a verziószám),
`assets/app.js`, `assets/app.css`, `sql/schema.sql`, `TELEPITES.md`.
(Ha régebbi verzióról jössz, előbb a korábbi lépések – 1.5, 1.6 SQL –, utána ez.)

**Frissítés 1.6.0-ról 1.7.0-ra (főoldali kereső):** adatbázis-módosítás nincs. Töltsd fel: `includes/api_kereses.php`
(új), és a frissített `api.php`, `assets/app.js`, `assets/app.css`, `includes/config.php` (csak a verziószám),
`TELEPITES.md`. (Ha 1.5.0-ról jössz, előbb az 1.6-os lépés – `sql/frissites_1.6.sql` –, utána ez.)

**Frissítés 1.5.0-ról 1.6.0-ra (kötéskönyv-import, cégegyeztetés, időszak → nyomtatási kosár):** futtasd le az
`sql/frissites_1.6.sql` fájlt phpMyAdmin-ban (1 új oszlop: `bejovo_szamlak.regi_k`, meglévő adat nem változik), majd
töltsd fel: `import.php`, `includes/xlsx.php`, `includes/import.php`, `includes/api_import.php`, `includes/api_bejovo.php`,
`includes/config.php` (csak a verziószám), `assets/app.js`, `assets/app.css`, `sql/schema.sql`, `TELEPITES.md`.
(Ha 1.4.0-ról jössz, előbb az 1.5-ös lépés, utána ez.)

**Frissítés 1.4.0-ról 1.5.0-ra (Excel import):** futtasd le az `sql/frissites_1.5.sql` fájlt phpMyAdmin-ban
(3 új oszlop, meglévő adat nem változik), majd töltsd fel: `import.php`, `includes/xlsx.php`, `includes/import.php`,
`includes/api_import.php`, és a frissített `api.php`, `includes/api_admin.php`, `includes/api_bejovo.php`,
`includes/api_cegek.php`, `includes/backup.php`, `includes/nyomtatas.php`, `includes/config.php` (csak a verziószám),
`assets/app.js`, `assets/app.css`, `setup.php`, `sql/schema.sql`, `TELEPITES.md`.

**Frissítés 1.3.0-ról 1.4.0-ra (adatbázis-mentés):** adatbázis-módosítás nincs. Töltsd fel: `cron_mentes.php`,
`mentes_letoltes.php`, `mentes_feltoltes.php`, `includes/backup.php`, és a frissített `api.php`, `includes/api_admin.php`,
`includes/config.php` (új BACKUP_* sorok – ha a sajátodban átírtad az adatbázis-adatokat, azokat tartsd meg),
`assets/app.js`, `assets/app.css`, `.htaccess` (a cron URL kivétele a robot-tiltás alól), `setup.php`, `TELEPITES.md`.
Utána: **állítsd be a cron jobot** (8.1 fejezet).

**Frissítés 1.2.x-ről 1.3.0-ra (nyomtatás):** adatbázis-módosítás nincs. Töltsd fel az új fájlokat:
`pdf.php`, `includes/pdf.php`, `includes/nyomtatas.php`, az `assets/fonts/` mappát (3 .ttf), és a frissített
`assets/app.js`, `assets/app.css`, `includes/config.php`, `setup.php`, `TELEPITES.md` fájlokat.
(A `config.php`-ból csak a verziószám változott – ha a sajátodban átírtad az adatbázis-adatokat, azt hagyd meg.)

## 7. Megjelenés (design rendszer)

Csak **világos téma** (a rendszer sötét beállítása sem váltja át). A színek az `assets/app.css` elején
tokenekként vannak definiálva, a megadott színtábla szerint:

| Szerep | Szín |
|---|---|
| Primary / CTA (gombok, FAB, linkek, fókusz, kijelölés) | zöld `#017F01`, hover `#006600` |
| Másodlagos hangsúly, figyelmeztetés (FIZETENDŐ, „PRO”, csipek) | narancs `#FE8302` |
| Kiemelés / jelvény (UTALÁSHOZ ADVA, hamarosan lejár, FAB-gyűrű) | sárga `#FDE20D` |
| Fő háttér / kártya | `#FAFAF8` / `#FFFFFF` |
| Szöveg: elsődleges / másodlagos / halvány | `#171717` / `#525252` / `#737373` |
| Keret / beviteli mező | `#E8E8E5` / `#F5F5F2` |
| Siker | zöld `#017F01` |
| Hiba, lejárt határidő (a táblában nem szereplő, de szükséges szín) | piros `#D64541` |

Mozgás: csak `transform`/`opacity` animációk (kártyák lépcsőzetes megjelenése, lapok felcsúszása,
felpörgő összegek, animált pipa, csúszó fülindikátor), üveghatás csak a fejlécen, a lapokon és a toaston.
Nincs külső betűtípus vagy könyvtár; a „kevesebb mozgás” rendszerbeállítást tiszteletben tartja.
A lapok (bottom sheet) telefonon lehúzással is bezárhatók.

## 8. Adatbázis-mentés minden éjjel 3:00-kor (DBBCKP) és visszaállítás

**Mi készül:** a TELJES adatbázis – minden tábla teljes szerkezete (CREATE TABLE minden beállítással: mezők,
kulcsok, indexek, idegen kulcsok, AUTO_INCREMENT, karakterkészlet) és minden sora – egyetlen konzisztens
pillanatképből, egy `.sql` fájlba a `DBBCKP/` mappába, `ÉÉÉÉHHNN_ÓÓPP.sql` néven (pl. `20260929_0300.sql`).
A fájl az admin felületről egy kattintással visszaállítható, de phpMyAdmin-ban is importálható (nincs hozzá
`mysqldump` vagy más külső program – tiszta PHP). Az `ő`/`ű` és minden karakter pontosan megmarad.

### 8.1 A cron job beállítása (ez KÖTELEZŐ – ettől készül a mentés az oldal megnyitása nélkül is)

1. cPanel → **Cron Jobs** (Advanced / Haladó rész).
2. Új feladat: **perc: `0`, óra: `3`, nap: `*`, hónap: `*`, hét napja: `*`** („Once Per Day” sablon, óra 3-ra állítva).
3. Parancs (a pontos, a te tárhelyedre kész sort az **Admin → Adatbázis-mentések → „Cron beállítása”** gomb mutatja,
   „Másolás” gombbal):
   ```
   php -q /home/CPANEL_FELHASZNALO/public_html/cron_mentes.php >/dev/null 2>&1
   ```
   Ha a tárhely nem ismeri a `php` parancsot, a teljes útvonalat add meg (cPanelben gyakran
   `/usr/local/bin/php`, vagy pl. `/opt/cpanel/ea-php82/root/usr/bin/php`).
   Ha a tárhely csak URL-hívást enged, a másik változat (a kulcsot az admin felület mutatja):
   ```
   wget -q -O /dev/null "https://a-domained.hu/cron_mentes.php?kulcs=KULCS" >/dev/null 2>&1
   ```
4. A cron a tárhely **szerverideje** szerint fut. Ha a cPanel nem magyar időt mutat, számold át az órát
   (UTC-s szervernél télen 2, nyáron 1) – az admin felület kiírja a mostani magyar és UTC időt.
5. Ellenőrzés másnap reggel: Admin → Adatbázis-mentések → zöld **„ÉJSZAKAI MENTÉS RENDBEN”** jelzés, és a listában
   egy 03:00-s „automatikus (éjszakai)” mentés. Ha piros a jelzés (26 órán belül nem volt cron-mentés), a cron
   nincs jól beállítva.

**Biztonsági háló:** ha a cron valamiért nem futott le, az alkalmazás a napi első használatkor (03:20 után)
**maga pótolja** a mentést (`…_potlas.sql`, `BACKUP_FALLBACK` a config-ban). Ez nem helyettesíti a cront (ha
aznap senki nem nyitja meg az oldalt, nem készül), csak plusz védelem.

### 8.2 Visszaállítás (csak admin)

Admin → **Adatbázis-mentések** → a listában a kívánt napi állapot mellett **„Visszaállítás”** →
figyelmeztető ablak (mi vész el) → **5 másodperces „Biztos?”** visszaszámláló → indul.
Ami közben történik, ebben a sorrendben:

1. **Automatikusan teljes mentés készül a mostani állapotról** (`…_visszaallitas_elott.sql`) – a visszaállítás
   enélkül el sem indul. Így a visszaállítás is visszavonható: ezt a fájlt kell visszaállítani.
2. A többi felhasználó kérései egy percre megállnak („Adatbázis-visszaállítás folyamatban”).
3. A kiválasztott fájl végrehajtása: minden tábla eldobása és újra létrehozása a mentett tartalommal.
4. Ha közben hiba történik, az 1. pontban készült mentés **automatikusan visszatöltődik**, és a felület
   megmondja, mi történt (a naplóban is: `DB_VISSZAALLITAS`).

Csonka fájlból (hiányzó záró sor) a rendszer nem hajlandó visszaállítani. Ha a visszaállított állapotban a te
felhasználód nem létezik vagy nem admin, újra be kell jelentkezned.

Vészhelyzet, ha a webes felület nem elérhető (SSH / cPanel Terminal):
```
php -q cron_mentes.php lista                      # mentések listája
php -q cron_mentes.php vissza 20260929_0300.sql   # visszaállítás (előtte automatikus mentés)
```
Ha az adatbázis teljesen elveszett (a táblák sincsenek meg): futtasd a `setup.php`-t (táblák + admin),
lépj be, és az Admin → Adatbázis-mentések listából állítsd vissza a kívánt napot – a `DBBCKP` mappa fájljai
a tárhelyen megvannak. Ha a tárhely is odaveszett: az admin felületről rendszeresen **töltsd le** a mentést
(„Letöltés” gomb) a saját gépedre; új tárhelyen a „Feltöltés” gombbal visszatöltheted és visszaállíthatod.

### 8.3 Egyéb

* **Mentés most** gomb: azonnali teljes mentés (`…_kezi.sql`), pl. nagyobb változtatás előtt.
* **Letöltés / Feltöltés / Törlés** fájlonként (törlés megerősítéssel). A `DBBCKP` mappa webről nem érhető el
  (saját `.htaccess` + a `.sql` fájlok tiltása), csak admin bejelentkezéssel, a felületen át.
* Megőrzés: alapból **minden mentés megmarad**. Ha korlátozni akarod, `includes/config.php` →
  `BACKUP_MEGORZES_NAP` (pl. 90): az ennél régebbi AUTOMATIKUS mentéseket törli, de a legfrissebb 7 mindig marad,
  a kézi és a visszaállítás előtti mentések sosem törlődnek. Egy mentés mérete kb. 20–500 kB.
* Minden mentés, letöltés, feltöltés, törlés és visszaállítás naplózódik (`DB_MENTES`, `DB_VISSZAALLITAS`, …).
* Jó szokás: havonta egyszer tölts le egy mentést a saját gépedre is (a tárhely hibája ellen).

## 9. Régi adatok felvitele Excelből (import)

Menü → **Excel import (régi adatok)**. Kétféle .xlsx fájlt fogad el, a felépítést **magától felismeri** (az első
adatot tartalmazó munkalapot olvassa; a diagram-lapokat átugorja). Minden felhasználó importálhat.

**A) Számlanapló** – a könyvelőprogram „Számla napló” exportja; egy fájlban lehet bejövő és kimenő, EUR és HUF sor is.
Oszlopok: **A** irány (BE = bejövő EUR, BH = bejövő HUF, KE = kimenő EUR, KH = kimenő HUF) ·
**B** tárgyév · **C** sorszám · **D** bejövő számlaszám (szabadkezes) · **J** kelt · **K** teljesítés · **M** fizetési határidő ·
**N** partnerkód · **O** cég neve · **P** adószám (ha van) · **Z** bruttó összeg devizában · **AA** deviza (ellenőrzés) ·
**AB** megjegyzés. Kimenő számla számlaszáma: **A-C/B** (pl. `KE-41/2026`). A fejléc- és összesítő sorok (ahol az A
oszlop nem BE/BH/KE/KH) automatikusan kimaradnak. Dátum lehet `2026.01.06` vagy Excel-dátum is.

**B) Kötéskönyv** – **csak bejövő számlák**, a régi rendszer kötései szerint. Oszlopok: **A** régi kötés ID ·
**B** régi K azonosító · **C** cég neve · **D** megnevezés / ügylet · **E** számla kelte · **F** teljesítés ·
**G** számlaszám (szabadkezes) · **H** összeg EUR · **I** összeg HUF (amelyik ki van töltve, az a pénznem) · **J** BESZÁM.
Fizetési határidő a kötéskönyvben nincs: a 2. lépésben megadható, hogy a kelt + hány nap legyen (pl. 30; csak a
FIZETENDŐ-ként importált számláknál számít). A fejléc, az üres és a csak sorszámot tartalmazó sorok kimaradnak; ha az
F oszlop nem dátum, a kelt kerül a teljesítésbe (figyelmeztetéssel); ha az I oszlopban szöveg van, az a megjegyzésbe kerül.

**1. lépés – elemzés (semmi nem íródik):** minden sor státuszt kap:
*új* (importálható) · *már megvan* (ugyanez a számlaszám ugyanannál a cégnél már a rendszerben van → kimarad, ezért
a fájl bármikor újra feltölthető) · *máshol szerepel* (ugyanaz a számlaszám másik cégnél / másik irányban – 1.9-től
alapból változatlanul importálható, mert csak kötésen belül egyedi a számlaszám) · *dupla* (ugyanaz a sor kétszer a fájlban) · *hibás* (hiányzó dátum/összeg/cég, eltérő deviza).
Legfeljebb 20 000 adatsor egy fájlban; az elemzés 2 óráig él (utána újra fel kell tölteni).

**Cégek egyeztetése (felügyelet, hogy ne jöjjön létre kétszer ugyanaz a cég):** a fájl cégneveit a rendszer a
meglévő cégekhez párosítja, sorrendben: **partnerkód** (N) → **pontos név** → **hasonló név**. A hasonlóságot
normalizált névvel számolja (kis/nagybetű, ékezet, írásjel, cégforma – Kft., Zrt., B.V., s.r.o., d.o.o., GmbH… –
nem számít, a „sped / trans / fresh / logisztika”-féle általános szavak kevesebbet nyomnak), és ha az egyezés
**eléri az 55 %-ot**, a névváltozatot **a meglévő céghez javasolja** (pl. `ASICA` → *Asica Group B.V.*), nem hoz
létre új céget. A fájlon belüli hasonló írásmódokat (`Dole Europe`, `DOLE EUROPE B.V.`) is **egy csoportba** vonja.
A „Cégek egyeztetése” kártya minden csoportnál mutatja a névváltozatokat (chipek), a sorok számát, a párosítás
alapját és százalékát (✓ zöld = egyértelmű: partnerkód vagy pontos név; **? sárga = bizonytalan**: csak hasonló
név alapján párosítva, vagy a fájlon belül 95 % alatti egyezéssel összevonva), és egy legördülőt:
*Új cég létrehozása* (a név átírható) · *Hasonló meglévő cégek* (százalékkal) · *Minden meglévő cég* ·
*Ugyanaz, mint a fájl másik csoportja* (két csoport összevonása). Egy tévesen összevont névváltozat a chip **×**
jelével leválasztható külön csoportba. A szűrők (*Bizonytalan / Új cég / Meglévő / Mind*) segítik az átnézést; a
kézi döntés után a sorok státusza azonnal újraszámolódik. Az indításkor a rendszer figyelmeztet, ha maradt
bizonytalan csoport. A nem talált cég a jóváhagyott néven létrejön (partnerkóddal, adószámmal, ha van).

**2. lépés – jóváhagyás:** a sorok pipálhatók (alapból az „új” sorok), szűrhetők; beállítások: bejövő számlák
státusza (FIZETENDŐ vagy FIZETVE – régi, rendezett tételekhez), kimenő státusza (NYITOTT vagy FIZETVE – ekkor
`IMPORT-dátum` banki azonosítót kapnak), kötéskönyvnél a határidő napok száma, a máshol már szereplő számlaszámok
kezelése (alapból **importálás változatlanul** – 1.9-től másik kötésben ugyanaz a számlaszám megengedett; vagy
kihagyás; vagy importálás toldalékkal: a számlaszám végére kerül a partnerkód – ha nincs, a cégnév eleje –
szögletes zárójelben, pl. `2026/0004 [10081]`). Egy kötésen belül (ARCHÍV kötés cégenként / régi ID szerinti kötés)
egy számlaszám csak egyszer kerülhet be – az ilyen sort az import kihagyja, és jelzi.
**Az import indításakor automatikusan teljes adatbázis-mentés készül** (`…_import_elott.sql` a DBBCKP mappában) –
ezt csak az admin kapcsolhatja ki (Admin → Excel import kapcsoló), a felhasználók nem. A rögzítés **400 soros
adagokban**, adagonként egy tranzakcióban történik (folyamatjelzővel), így egy több ezer soros kötéskönyv is
biztonságosan bemegy osztott tárhelyen; hiba esetén az adott adag teljes egészében visszagördül.

**Hová kerülnek a sorok:**
* *Számlanapló:* a kimenő számlák a rendszer saját azonosítóját kapják (év-pénznem-sorszám), számlaszámuk
  `KE-…/év`. A **bejövő** számlák cégenként, pénznemenként és évenként egy **ARCHÍV kötésbe** kerülnek
  (`ARCHÍV – Excel import 2026`, ARCHÍV jelvénnyel). Az archív kötés számlái ugyanúgy utaláshoz adhatók, fizethetők.
* *Kötéskönyv:* a régi kötés ID-k (A) szerint **cégenként és pénznemenként** kötés jön létre a rendszerben
  (a **régi kötés ID** mezőbe kerül a régi szám, megnevezése a D oszlop, éve a számla kelte), és a számlák ebbe
  kerülnek a rendszer szerinti új K sorszámmal (`2026-EUR-000041-K0001`); a **régi K azonosító** (B) a számlán
  megmarad („régi K”). Ha egy régi kötés ID-hoz több cég tartozik (pl. áru + fuvar), cégenként külön kötés lesz
  ugyanazzal a régi ID-val; újabb fájlból ugyanoda csatlakozik. Régi ID nélküli sor az ARCHÍV kötésbe kerül.

**Archív számlák kötésbe helyezése:** az archív kötés megnyitva a számlák elején kerek jelölő gomb van → kijelölés →
alsó zöld gomb „N számla áthelyezése kötésbe” → **új kötés** (megnevezés, **régi rendszerbeli kötés ID** szabad
szövegként, megjegyzés) vagy **meglévő kötés**. A számlák a mostani rendszer szerinti teljes azonosítót kapják
(kötés ID + új K sorszám, pl. `2026-EUR-000040-K0001`). A régi kötés ID-t gépelés közben ellenőrzi a rendszer; ha
már használtad egy másik kötésnél, mentéskor felugró ablak sorolja fel, mely kötéshez és mely számlákhoz tartozik,
**Mégse** (mindig aktív) és **Igen** (csak 5 másodperc után aktív) gombokkal. Ugyanez a mező az „Új kötés” /
„Kötés szerkesztése” űrlapon is elérhető.

Napló: `EXCEL_ELEMZES`, `EXCEL_IMPORT` (adagonként), `ARCHIV_ATHELYEZ`, `BEJOVO_IDOSZAK` (időszak-lekérdezés a cég
oldalán), `RESZTELJESITES` / `RESZTELJESITES_TOROL` (részteljesítés felvezetése / törlése – összeg, dátum, bank,
hátralék). A PDF-nyomtatás cégek táblázata a partnerkódot is mutatja.

## 10. Ha valami nem megy

| Tünet | Teendő |
|---|---|
| Fehér oldal / „Az adatbázis nem elérhető” | `includes/config.php` adatok; létezik-e a DB és a felhasználó; lefutott-e a `schema.sql` |
| „Adatbázis-hiba” egy műveletnél | nézd meg a `LOG/` legfrissebb fájlját, a hibaüzenet benne van |
| Nincs árfolyam | a tárhely nem enged kimenő HTTP-t → Admin → kézi EUR/HUF árfolyam |
| „Biztonsági hiba” / a telefon nem kér Face ID-t | nincs HTTPS, vagy a domain nem egyezik a `WEBAUTHN_RP_ID` / `APP_ORIGIN` beállítással |
| „Ehhez a fiókhoz már van regisztrált eszköz” jelszavas belépésnél | ez szándékos: QR + biometriával lépj be; ha elveszett a telefon, az admin törli az eszközöket |
| „A kód nem ehhez a böngészőhöz tartozik” | a QR-t másik böngészőben kérték – kérj újat ugyanabban a böngészőben |
| Napló nem íródik | a `LOG` mappa nem írható (jogosultság) |
| A nyomtató gomb új lapja üres / nem nyílik meg | a böngésző felugró-blokkolója – engedélyezd az oldalnak; a PDF a menü *Nyomtatási kosár* → *PDF készítése* gombjával is kérhető |
| „A PDF nem készíthető el” | lejárt munkamenet (lépj be újra), vagy hiányzik a `zlib`/`mbstring` PHP-bővítmény, vagy nem olvasható az `assets/fonts/` mappa |
| Piros „CRON NEM FUTOTT” / „CRON MÉG NINCS BEÁLLÍTVA” | a cron job hiányzik vagy rossz a parancs/útvonal – lásd 8.1; próbáld ki a parancsot cPanel Terminalban |
| A cron URL-es hívása 403-at ad | rossz kulcs (Admin → Cron beállítása → másold újra), vagy a tárhely blokkolja a wget/curl-t – használd a PHP-s parancsot |
| „A mentések mappája nem írható” | hozd létre a `DBBCKP` mappát és adj rá írási jogot (0755/0775), vagy nézd meg, nem telt-e be a tárhely |
| Visszaállítás után „Lejárt a munkameneted” | a visszaállított adatbázisban nincs meg a felhasználód – lépj be egy ott létező admin fiókkal |
| Excel import: „A fájl nem .xlsx formátumú” | régi .xls vagy CSV – Excelben Mentés másként → Excel-munkafüzet (.xlsx) |
| Excel import: „A fájl túl nagy” | a tárhely `upload_max_filesize` korlátja – bontsd több fájlra, vagy a cPanel PHP-beállításaiban emeld meg |
| Excel import: minden sor „hibás” vagy „Nem ismerem fel a fájl felépítését” | nem a várt oszloprend – számlanapló: A, D, J, K, M, N, O, Z, AA; kötéskönyv: A–J (régi kötés ID, régi K, cég, megnevezés, kelt, teljesítés, számlaszám, EUR, HUF, beszám) |
| Excel import: egy cég kétszer szerepel a rendszerben | az egyeztetésnél „Új cég” maradt egy hasonló név – a „Cégek egyeztetése” kártyán válaszd a meglévő céget (a legördülő a hasonlókat százalékkal mutatja); a már létrejött duplát a cég oldalán törölheted, ha nincs kötése |
| Excel import: „Az import megszakadt” | a szerver időkorlátja / hálózat – a már bekerült adagok bent maradnak; a fájl újra feltölthető, a bent lévő sorok „már megvan” jelzést kapnak, csak a hiányzók mennek be |
| Excel import: „Az elemzés lejárt” | 2 óránál régebbi előnézet vagy másik böngésző – töltsd fel újra a fájlt |
| „A részteljesítés nem lehet nagyobb vagy egyenlő a számla hátralékánál” | részteljesítés csak a hátraléknál kisebb összeg lehet; a teljes hátralékot a rendes úton rögzítsd (kimenő: bankkivonati azonosító, bejövő: utalás teljesítése) |
| Részteljesítés nem vezethető fel / nincs + gomb | csak nyitott számlára lehet (bejövő FIZETENDŐ, kimenő NYITOTT) és csak pozitív összegűre; az utalásban lévő számlát előbb vedd ki az utalásból |
| „Duplicate column name 'fizetve_datum'” a frissítő SQL-nél | a frissites_1.8.sql már lefutott – nem kell újra (a részteljesítés tábla `CREATE TABLE IF NOT EXISTS`, az nem hibázik) |
| „Ebben a kötésben már szerepel ez a számlaszám” – a mentés gomb szürke | egy kötésen belül egy számlaszám csak egyszer lehet; ha tényleg új számla, ellenőrizd a számlaszámot, vagy rögzítsd a megfelelő másik kötésbe (ott csak figyelmeztetés jön) |
| A könyv gomb (változásnapló) „A rekord nem található” / üres | a frissites_1.9.sql nem futott le (nincs `valtozasnaplo` tábla) – futtasd le phpMyAdmin-ban |
| A változásnaplóban ékezet-hibás szöveg (Ã, Å) a régi bejegyzéseknél | a frissítő SQL nem UTF-8 kapcsolattal futott – phpMyAdmin-ban futtasd újra csak a visszamenőleges INSERT részt, miután törölted a hibás sorokat (`DELETE FROM valtozasnaplo WHERE leiras LIKE BINARY '%Ã%'`) |
| A főoldali kereső nem ad találatot | legalább 3 karakter kell; összegnél a pontos összeg (55 EUR / 4003,20), dátumnál év.hó.nap; a kereső a rendszerben rögzített adatokban keres (az Excelből még nem importált régi számlákat nem találhatja meg) |
| „Ehhez a művelethez … jogosultság kell (a te szereped: …)” | a szerepköröd nem engedi a műveletet (pl. Rögzítő nem törölhet, Üzletkötő nem módosít) – ha mégis kell, az admin az Admin → Felhasználók oldalon átállítja a szerepkörödet |
| „A megjegyzést más írta – … csak hozzáfűzni lehet” | Rögzítő / Üzletkötő a más által írt megjegyzést nem írhatja át: a szerkesztő ablakban a régi szöveg alatti mezőbe írj, az új sorként a régi alá kerül |
| „A BESZÁM nem törölhető” | Üzletkötőként a kitöltött BESZÁM csak módosítható; törölni Rögzítő vagy magasabb szerep tud |
| Nincs + gomb / ceruza csak BESZÁM-ot és megjegyzést mutat | Üzletkötő szerepkör – ez szándékos; felvitelhez Rögzítő vagy magasabb szerep kell |
| A bejövő számla ablakában nincs részteljesítés (+ gomb) | alapból ki van kapcsolva: Admin → Részteljesítés → „Részteljesítés a bejövő számláknál” kapcsoló |
| A bankkártya (aktuális utalás) gomb nem jelenik meg | csak azután látszik, hogy számlát adtál egy nyitott utaláshoz ebben a böngészőben; teljesített / törölt utalásnál eltűnik |
| „Data truncated for column 'szerep'” a frissítő SQL-nél | a frissites_1.10.sql-t egyben, sorrendben futtasd (két lépésben módosítja az ENUM-ot); ha félbeszakadt, futtasd újra a `UPDATE … WHERE szerep = 'user'` sortól |
| A kötés oldalán „eltűntek” a fizetett számlák / a cég oldalán a fizetett kötések | a NYITOTT fül aktív – nyomd meg a FIZETVE / FIZETETT vagy a MIND fület a számlák (kötések) felett |
| Kimenő oldalon megfeleltetés után nem lehet a NYITOTT fülre váltani | 1.11-ig hiba volt (a cím végén maradt `?szamla=` minden újrarajzoláskor visszakényszerítette a fület) – 1.12-től javítva; régi verzión a cég újranyitása segít |
| A bank-épület gomb nem jelenik meg | csak akkor látszik, ha van számla a banki gyűjtőben: a kimenő számla elején lévő kerek gombbal tedd bele (Üzletkötőnek nincs kijelölés) |
| „Az utalás … le van lakatolva” | a gyűjtés le lett zárva: az utalás oldalán (vagy a gyűjtő ablakban) *Lakat kinyitása*, utána mehet a hozzáadás / kivétel / törlés |
| Az UTALÁSHOZ gomb új utalást ajánl, pedig van nyitott | a nyitott utalás(ok) le vannak lakatolva – nyisd ki a lakatot, vagy fogadd el az új utalást |
| Lelakatolás után eltűnt a bankkártyás gomb | ez szándékos (1.13.1): a lakat lezárja a gyűjtést; ha kell, kinyitás után az „Ehhez gyűjtök” gombbal választod újra |
| „A böngésző vagy a lap be lett zárva, ezért biztonsági okból kiléptettünk” | szándékos (1.14): a bezárt lap 3 percen belül visszanyitható belépve, utána újra be kell lépni; a böngésző bezárása után mindig |
| „Az alkalmazás több mint 10 percig nem volt nyitva…” | a lap nem jelzett a szervernek (alvó gép, háttérbe tett telefon, kilőtt böngésző) – lépj be újra; a korlát az `ULES_CSEND_PERC`-ben állítható |
| A böngésző újraindítás után is belépve marad | a böngésző „folytatás ott, ahol abbahagytam” beállítása visszaállítja a munkamenet-sütit – kapcsold ki (5. fejezet); a szerver ilyenkor is legfeljebb 3 percig engedi vissza |
| Régi, elmentett jelszót ajánl a böngésző | 1.14-től a jelszómező nem menthető; a korábban elmentett jelszót a böngésző jelszókezelőjéből töröld (5. fejezet) |
| „Unknown column 'lezarva'” | a frissites_1.13.sql nem futott le – futtasd le phpMyAdmin-ban |
| Fejlesztéshez részletes hibák | `includes/config.php` → `APP_DEBUG = true` (élesben legyen `false`!) |

Verzió: 1.14.0 (2026-09-30)
