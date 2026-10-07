<?php
/**
 * BaninaPRO – konfiguráció a HELYI DOCKER környezethez (docker-compose.yml)
 * A konténerben ez a fájl lép az includes/config.php helyére – az éles config.php-hoz nem nyúl.
 * Ezt a fájlt a webről NEM lehet letölteni (.htaccess tiltja az includes mappát).
 */
declare(strict_types=1);

// ---- Adatbázis -----------------------------------------------------------
define('DB_HOST', 'db');                 // a MySQL konténer neve
define('DB_PORT', 3306);
define('DB_NAME', 'baninapr_DATA');
define('DB_USER', 'baninapr_DATA');
define('DB_PASS', 'baninapro_helyi');    // csak a helyi Docker adatbázishoz

// ---- Alkalmazás ----------------------------------------------------------
define('APP_NAME', 'BaninaPRO');
define('APP_VERSION', '1.19.0');
define('APP_TIMEZONE', 'Europe/Budapest');

// Naplófájlok mappája (naponta új fájl, éjjel 3-kor váltva: ÉÉÉÉHHNN.txt)
define('LOG_DIR', '/var/lib/baninapro/LOG');         // Docker-kötet
define('LOG_ROTATE_HOUR', 3);

// Munkamenet (1.14): a belépés a böngésző bezárásáig él – a süti nem marad meg, és a nyitott lap
// „szívveréssel” jelzi, hogy még nyitva van. Ha a jelzés elmarad, a belépés a szerveren is megszűnik.
define('SESSION_NAME', 'BANINAPRO');
define('ULES_JELENLET_MP', 30);        // a nyitott lap ennyi másodpercenként jelez a szervernek (szívverés)
define('ULES_LAPZARAS_PERC', 3);       // a lap / böngésző bezárása után ennyi percig még visszanyitható belépve (véletlen bezárás)
define('ULES_CSEND_PERC', 10);         // ha semmilyen jelzés nem jön (kilőtt böngésző, elaltatott gép, háttérbe tett telefon): ennyi perc után lejár
define('ULES_TETLENSEG_ORA', 8);       // ennyi óra tétlenség (kattintás, gépelés nélkül) után akkor is kiléptet, ha a lap nyitva maradt

// Belépési korlát (brute-force védelem)
define('LOGIN_MAX_ATTEMPTS', 8);       // ennyi hibás próbálkozás után
define('LOGIN_BLOCK_MINUTES', 15);     // ennyi percig blokkol

// Árfolyam (MNB → ECB → er-api → kézi beállítás); gyorsítótár órában
define('RATE_CACHE_HOURS', 6);
define('RATE_HTTP_TIMEOUT', 6);

// ---- Jelszó nélküli belépés (WebAuthn / passkey, QR-kódos jóváhagyás) ----
// RP_ID: a weboldal domainje port nélkül (pl. banina.cegem.hu). Üresen hagyva automatikus.
define('WEBAUTHN_RP_ID', '');
// APP_ORIGIN: pl. https://banina.cegem.hu  (üresen automatikus – HTTPS kötelező, kivéve localhost)
define('APP_ORIGIN', '');
// APP_URL: az alkalmazás alap-URL-je a QR-kódokhoz, pl. https://banina.cegem.hu/  (üresen automatikus)
define('APP_URL', '');
define('QR_LEJARAT_MP', 120);          // a belépési QR-kód érvényessége (másodperc)
define('REGKOD_LEJARAT_PERC', 15);     // eszköz-regisztrációs kód érvényessége (perc)
define('QR_KERELEM_LIMIT', 40);        // ennyi QR-kérés / IP / 15 perc

// ---- Adatbázis-mentés (DBBCKP mappa) --------------------------------------
// A teljes adatbázis minden nap BACKUP_HOUR órakor .sql fájlba mentődik (cron: cron_mentes.php).
define('BACKUP_DIR', '/var/lib/baninapro/DBBCKP');   // Docker-kötet
define('BACKUP_HOUR', 3);              // az éjszakai mentés esedékessége (óra) – a cron is ekkor fusson
define('BACKUP_FALLBACK', true);       // ha a cron nem futott le, az app az első használatkor pótolja (…_potlas.sql)
define('BACKUP_MEGORZES_NAP', 0);      // 0 = minden automatikus mentés megmarad; N = az N napnál régebbi AUTOMATIKUS
                                       // mentéseket törli (a legfrissebb 7 mindig marad; kézi és visszaállítás előtti sosem törlődik)

// Fejlesztői hibakiírás (éles környezetben legyen false!)
define('APP_DEBUG', true);           // helyi fejlesztés: részletes hibák
