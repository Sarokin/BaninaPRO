-- =====================================================================
--  BaninaPRO – számla-nyilvántartás
--  Adatbázis séma (MySQL 8.0+ / MariaDB 10.4+)
--  Karakterkészlet: utf8mb4 / utf8mb4_unicode_ci (teljes UTF-8, ékezetek, emoji)
-- =====================================================================
--
--  cPanel-es tárhelyen az adatbázist és a felhasználót általában a
--  cPanel "MySQL Databases" menüjében kell létrehozni (a CREATE DATABASE /
--  CREATE USER parancsokat ott a rendszer nem engedi). Ebben az esetben:
--    1. hozd létre az adatbázist:   baninapr_DATA
--    2. hozd létre a felhasználót:  baninapr_DATA   (jelszó: az includes/config.php DB_PASS értéke)
--    3. add hozzá a felhasználót az adatbázishoz "ALL PRIVILEGES" joggal
--    4. phpMyAdmin-ban válaszd ki a baninapr_DATA adatbázist, és futtasd le
--       ezt a fájlt az "SQL" fülön (a lenti CREATE DATABASE / CREATE USER
--       sorok kommentben vannak, nem zavarnak).
--
--  Saját szerveren (root joggal) a kommentek eltávolítása után egyben
--  lefuttatható:  mysql -u root -p < schema.sql
-- ---------------------------------------------------------------------

-- CREATE DATABASE IF NOT EXISTS `baninapr_DATA`
--   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- CREATE USER IF NOT EXISTS 'baninapr_DATA'@'localhost' IDENTIFIED BY 'A_CONFIG_PHP_DB_PASS_ERTEKE';
-- GRANT ALL PRIVILEGES ON `baninapr_DATA`.* TO 'baninapr_DATA'@'localhost';
-- FLUSH PRIVILEGES;
-- USE `baninapr_DATA`;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
--  Felhasználók (1 admin + tetszőleges számú "user")
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `felhasznalok` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `felhasznalonev`  VARCHAR(64)  NOT NULL,
  `nev`             VARCHAR(128) NOT NULL DEFAULT '',
  `jelszo_hash`     VARCHAR(255) NULL COMMENT 'csak tartalék; ha van passkey, a jelszavas belépés tiltott',
  `szerep`          ENUM('admin','irodavezeto','rogzito','uzletkoto') NOT NULL DEFAULT 'rogzito' COMMENT 'admin: minden; irodavezeto: minden adat, admin nélkül; rogzito: felvitel + szerkesztés, törlés nélkül; uzletkoto: csak olvasás + BESZÁM + megjegyzés hozzáfűzés',
  `aktiv`           TINYINT(1)   NOT NULL DEFAULT 1,
  `letrehozva`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `letrehozta`      INT UNSIGNED NULL,
  `utolso_belepes`  DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_felhasznalonev` (`felhasznalonev`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  PASSKEY-ek (WebAuthn): a felhasználó telefonjának / gépének biometrikus
--  hitelesítője – csak a NYILVÁNOS kulcs tárolódik
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passkeyek` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `felhasznalo_id`   INT UNSIGNED NOT NULL,
  `credential_id`    VARCHAR(512) NOT NULL COMMENT 'base64url',
  `public_key`       TEXT         NOT NULL COMMENT 'PEM',
  `alg`              SMALLINT     NOT NULL COMMENT '-7 = ES256, -257 = RS256',
  `sign_count`       INT UNSIGNED NOT NULL DEFAULT 0,
  `aaguid`           CHAR(36)     NULL,
  `transports`       VARCHAR(100) NULL,
  `eszkoz_nev`       VARCHAR(100) NOT NULL DEFAULT 'Eszköz',
  `aktiv`            TINYINT(1)   NOT NULL DEFAULT 1,
  `letrehozva`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `letrehozo_ip`     VARCHAR(45)  NULL,
  `utolso_hasznalat` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passkey_cred` (`credential_id`(191)),
  KEY `ix_passkey_felh` (`felhasznalo_id`),
  CONSTRAINT `fk_passkey_felh` FOREIGN KEY (`felhasznalo_id`) REFERENCES `felhasznalok` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Belépési / regisztrációs kérelmek (QR-kódos, egyszer használatos tokenek)
--  BELEPES: asztali gép kér belépést, telefon hagyja jóvá biometriával
--  HELYI:   ugyanazon az eszközön passkey-jel belépés
--  REGISZTRACIO: admin által kiadott eszköz-regisztrációs kód
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `belepesi_kerelmek` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`               VARCHAR(64)  NOT NULL,
  `tipus`               ENUM('BELEPES','HELYI','REGISZTRACIO') NOT NULL,
  `felhasznalonev`      VARCHAR(64)  NOT NULL,
  `felhasznalo_id`      INT UNSIGNED NULL,
  `session_hash`        CHAR(64)     NULL COMMENT 'sha256(session id) – csak a kérő böngésző válthatja be',
  `challenge`           VARCHAR(64)  NOT NULL,
  `kod`                 CHAR(2)      NOT NULL COMMENT 'egyeztető szám a két képernyőn',
  `keres_ip`            VARCHAR(45)  NULL,
  `keres_ua`            VARCHAR(255) NULL,
  `statusz`             ENUM('PENDING','APPROVED','USED','EXPIRED','DENIED') NOT NULL DEFAULT 'PENDING',
  `letrehozva`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lejar`               DATETIME     NOT NULL,
  `jovahagyva`          DATETIME     NULL,
  `jovahagyo_passkey_id` INT UNSIGNED NULL,
  `jovahagyo_ip`        VARCHAR(45)  NULL,
  `letrehozta`          INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kerelem_token` (`token`),
  KEY `ix_kerelem_lejar` (`lejar`),
  KEY `ix_kerelem_felh` (`felhasznalo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Sikertelen / sikeres belépési kísérletek (brute-force védelem)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `belepesi_kiserletek` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `felhasznalonev`  VARCHAR(64)  NOT NULL,
  `ip`              VARCHAR(45)  NOT NULL,
  `idopont`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sikeres`         TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_bk_nev` (`felhasznalonev`, `idopont`),
  KEY `ix_bk_ip`  (`ip`, `idopont`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Cégek (közös törzs a bejövő és a kimenő irányhoz)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cegek` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nev`         VARCHAR(191) NOT NULL,
  `adoszam`     VARCHAR(32)  NULL,
  `partnerkod`  VARCHAR(32)  NULL COMMENT 'a régi (könyvelő) rendszer partnerkódja – Excel importhoz',
  `megjegyzes`  TEXT         NULL,
  `megjegyzes_irta` INT UNSIGNED NULL COMMENT 'a megjegyzés utolsó szerzője (NULL = több szerző / ismeretlen)',
  `aktiv`       TINYINT(1)   NOT NULL DEFAULT 1,
  `letrehozva`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `letrehozta`  INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ceg_nev` (`nev`),
  KEY `ix_ceg_partnerkod` (`partnerkod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Sorszám-generátor: (típus, év, pénznem) → utolsó kiadott 6 jegyű szám
--  KOTES  → 2026-HUF-000001
--  UTALAS → U-HUF-2026-000001
--  KIMENO → 2026-HUF-000001 (kimenő számla)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sorszamok` (
  `tipus`    ENUM('KOTES','UTALAS','KIMENO') NOT NULL,
  `ev`       SMALLINT UNSIGNED NOT NULL,
  `penznem`  ENUM('HUF','EUR') NOT NULL,
  `utolso`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`tipus`, `ev`, `penznem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  KÖTÉSEK (ügyletek) – bejövő irány
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kotesek` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ceg_id`      INT UNSIGNED NOT NULL,
  `ev`          SMALLINT UNSIGNED NOT NULL,
  `penznem`     ENUM('HUF','EUR') NOT NULL,
  `sorszam`     INT UNSIGNED NOT NULL,
  `kod`         VARCHAR(20)  NOT NULL COMMENT 'pl. 2026-HUF-000001',
  `megnevezes`  VARCHAR(191) NULL,
  `megjegyzes`  TEXT         NULL,
  `megjegyzes_irta` INT UNSIGNED NULL COMMENT 'a megjegyzés utolsó szerzője (NULL = több szerző / ismeretlen)',
  `archiv`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = Excel importból létrejött ARCHÍV kötés (cégenként, pénznemenként, évenként)',
  `regi_kod`    VARCHAR(100) NULL COMMENT 'a régi rendszerbeli kötés-azonosító (szabad szöveg)',
  `utolso_k`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'utoljára kiadott K sorszám',
  `letrehozva`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `letrehozta`  INT UNSIGNED NULL,
  `modositva`   DATETIME     NULL COMMENT 'utolsó módosítás (megnevezés, megjegyzés, régi ID)',
  `modositotta` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kotes_kod` (`kod`),
  UNIQUE KEY `uq_kotes_sorszam` (`ev`, `penznem`, `sorszam`),
  KEY `ix_kotes_ceg` (`ceg_id`),
  KEY `ix_kotes_regi` (`regi_kod`),
  CONSTRAINT `fk_kotes_ceg` FOREIGN KEY (`ceg_id`) REFERENCES `cegek` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  UTALÁSOK (egy utalás = egy cég, egy pénznem)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `utalasok` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ceg_id`            INT UNSIGNED NOT NULL,
  `ev`                SMALLINT UNSIGNED NOT NULL,
  `penznem`           ENUM('HUF','EUR') NOT NULL,
  `sorszam`           INT UNSIGNED NOT NULL,
  `uid`               VARCHAR(24)  NOT NULL COMMENT 'pl. U-EUR-2026-000001',
  `utalas_mod`        ENUM('MANUAL','HATARERTEK') NOT NULL DEFAULT 'MANUAL',
  `hatarertek`        DECIMAL(15,2) NULL COMMENT 'határértékes utalásnál a felső limit',
  `statusz`           ENUM('NYITOTT','UTALVA') NOT NULL DEFAULT 'NYITOTT',
  `utalva_datum`      DATE         NULL,
  `banki_hivatkozas`  VARCHAR(100) NULL,
  `megjegyzes`        TEXT         NULL,
  `megjegyzes_irta`   INT UNSIGNED NULL COMMENT 'a megjegyzés utolsó szerzője (NULL = több szerző / ismeretlen)',
  `lezarva`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'lakat: a gyűjtés befejeződött (a státusz NYITOTT marad) – nem adható hozzá / vehető ki számla',
  `lezarta`           INT UNSIGNED NULL,
  `lezarva_at`        DATETIME     NULL,
  `letrehozva`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `letrehozta`        INT UNSIGNED NULL,
  `utalva_at`         DATETIME     NULL,
  `utalta`            INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_utalas_uid` (`uid`),
  UNIQUE KEY `uq_utalas_sorszam` (`penznem`, `ev`, `sorszam`),
  KEY `ix_utalas_ceg` (`ceg_id`),
  KEY `ix_utalas_statusz` (`statusz`),
  CONSTRAINT `fk_utalas_ceg` FOREIGN KEY (`ceg_id`) REFERENCES `cegek` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  BEJÖVŐ SZÁMLÁK (kötésen belül, K sorszámmal)
--  A `szamlaszam_norm` a kézzel beírt számlaszám normalizált alakja
--  (nagybetű, csak betű+szám) – ezen van a globális UNIQUE index, így
--  ugyanaz a számlaszám kétszer soha nem rögzíthető (formátumtól függetlenül).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bejovo_szamlak` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kotes_id`           INT UNSIGNED NOT NULL,
  `k_sorszam`          SMALLINT UNSIGNED NOT NULL,
  `kod`                VARCHAR(30)  NOT NULL COMMENT 'pl. 2026-HUF-000001-K0001',
  `szamlaszam`         VARCHAR(100) NOT NULL,
  `szamlaszam_norm`    VARCHAR(100) NOT NULL,
  `teljesites_datum`   DATE         NOT NULL,
  `kelt`               DATE         NOT NULL,
  `osszeg`             DECIMAL(15,2) NOT NULL COMMENT 'lehet negatív is',
  `beszam`             VARCHAR(191) NULL COMMENT 'BESZÁM – szabad szöveg (pl. raktári ellenőrző irat száma)',
  `regi_k`             VARCHAR(50)  NULL COMMENT 'a régi rendszerbeli K azonosító (kötéskönyv import)',
  `fizetesi_hatarido`  DATE         NOT NULL,
  `statusz`            ENUM('FIZETENDO','UTALASHOZ_ADVA','FIZETVE','BESZAMITVA') NOT NULL DEFAULT 'FIZETENDO',
  `fizetve_datum`      DATE         NULL COMMENT 'FIZETVE / BESZÁMÍTVA: az utalás dátuma',
  `utalas_id`          INT UNSIGNED NULL,
  `megjegyzes`         TEXT         NULL,
  `megjegyzes_irta`    INT UNSIGNED NULL COMMENT 'a megjegyzés utolsó szerzője (NULL = több szerző / ismeretlen)',
  `letrehozva`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `letrehozta`         INT UNSIGNED NULL,
  `modositva`          DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `modositotta`        INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bejovo_kod` (`kod`),
  UNIQUE KEY `uq_bejovo_k` (`kotes_id`, `k_sorszam`),
  UNIQUE KEY `uq_bejovo_kotes_szamlaszam` (`kotes_id`, `szamlaszam_norm`) COMMENT 'egy kötésen belül egy számlaszám csak egyszer; másik kötésben megengedett (figyelmeztetéssel)',
  KEY `ix_bejovo_szamlaszam` (`szamlaszam_norm`),
  KEY `ix_bejovo_statusz` (`statusz`),
  KEY `ix_bejovo_hatarido` (`fizetesi_hatarido`),
  KEY `ix_bejovo_teljesites` (`teljesites_datum`),
  KEY `ix_bejovo_utalas` (`utalas_id`),
  CONSTRAINT `fk_bejovo_kotes`  FOREIGN KEY (`kotes_id`)  REFERENCES `kotesek` (`id`),
  CONSTRAINT `fk_bejovo_utalas` FOREIGN KEY (`utalas_id`) REFERENCES `utalasok` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  BANKI KIVONAT azonosítók (kimenő számlák lefedéséhez)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `banki_kivonatok` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `azonosito`       VARCHAR(100) NOT NULL,
  `azonosito_norm`  VARCHAR(100) NOT NULL,
  `datum`           DATE         NOT NULL,
  `letrehozva`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `letrehozta`      INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_norm` (`azonosito_norm`),
  KEY `ix_bank_datum` (`datum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  KIMENŐ SZÁMLÁK
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kimeno_szamlak` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ceg_id`             INT UNSIGNED NOT NULL,
  `ev`                 SMALLINT UNSIGNED NOT NULL,
  `penznem`            ENUM('HUF','EUR') NOT NULL,
  `sorszam`            INT UNSIGNED NOT NULL,
  `kod`                VARCHAR(20)  NOT NULL COMMENT 'pl. 2026-EUR-000001',
  `szamlaszam`         VARCHAR(100) NOT NULL,
  `szamlaszam_norm`    VARCHAR(100) NOT NULL,
  `teljesites_datum`   DATE         NOT NULL,
  `kelt`               DATE         NOT NULL,
  `fizetesi_hatarido`  DATE         NOT NULL,
  `osszeg`             DECIMAL(15,2) NOT NULL,
  `statusz`            ENUM('NYITOTT','FIZETVE') NOT NULL DEFAULT 'NYITOTT',
  `banki_kivonat_id`   INT UNSIGNED NULL,
  `fizetve_datum`      DATE         NULL,
  `megjegyzes`         TEXT         NULL,
  `megjegyzes_irta`    INT UNSIGNED NULL COMMENT 'a megjegyzés utolsó szerzője (NULL = több szerző / ismeretlen)',
  `letrehozva`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `letrehozta`         INT UNSIGNED NULL,
  `modositva`          DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `modositotta`        INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kimeno_kod` (`kod`),
  UNIQUE KEY `uq_kimeno_sorszam` (`ev`, `penznem`, `sorszam`),
  UNIQUE KEY `uq_kimeno_szamlaszam` (`szamlaszam_norm`),
  KEY `ix_kimeno_ceg` (`ceg_id`),
  KEY `ix_kimeno_statusz` (`statusz`),
  KEY `ix_kimeno_hatarido` (`fizetesi_hatarido`),
  KEY `ix_kimeno_teljesites` (`teljesites_datum`),
  KEY `ix_kimeno_bank` (`banki_kivonat_id`),
  CONSTRAINT `fk_kimeno_ceg`  FOREIGN KEY (`ceg_id`)           REFERENCES `cegek` (`id`),
  CONSTRAINT `fk_kimeno_bank` FOREIGN KEY (`banki_kivonat_id`) REFERENCES `banki_kivonatok` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  RÉSZTELJESÍTÉSEK – nyitott bejövő / kimenő számlára érkezett részfizetések
--  (a számla NYITOTT / FIZETENDŐ marad, a főértéke = összeg − részteljesítések)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reszteljesitesek` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `irany`            ENUM('BEJOVO','KIMENO') NOT NULL,
  `szamla_id`        INT UNSIGNED NOT NULL COMMENT 'bejovo_szamlak.id vagy kimeno_szamlak.id (irány szerint)',
  `osszeg`           DECIMAL(15,2) NOT NULL,
  `datum`            DATE         NOT NULL COMMENT 'az utalás dátuma',
  `banki_azonosito`  VARCHAR(100) NULL,
  `letrehozva`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `letrehozta`       INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `ix_reszt_szamla` (`irany`, `szamla_id`),
  KEY `ix_reszt_datum` (`datum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  VÁLTOZÁSNAPLÓ – ki, mikor, mit módosított egy kötésen / számlán
--  (a kártyák „könyv” gombja mutatja; a bejövő számla bejegyzései a kötés
--   naplójában is megjelennek a szulo_id révén)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `valtozasnaplo` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipus`          ENUM('KOTES','BEJOVO','KIMENO') NOT NULL,
  `rekord_id`      INT UNSIGNED NOT NULL,
  `szulo_id`       INT UNSIGNED NULL COMMENT 'bejövő számlánál a kötés id-ja',
  `muvelet`        VARCHAR(40)  NOT NULL COMMENT 'LETREHOZ, MODOSIT, TOROL, STATUSZ, RESZT, ATHELYEZ, IMPORT',
  `leiras`         VARCHAR(500) NOT NULL DEFAULT '',
  `valtozasok`     TEXT         NULL COMMENT 'JSON: [{"m":"mező","r":"régi","u":"új"}]',
  `felhasznalo_id` INT UNSIGNED NULL,
  `felhasznalonev` VARCHAR(64)  NOT NULL DEFAULT '',
  `idopont`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_vn_rekord` (`tipus`, `rekord_id`, `idopont`),
  KEY `ix_vn_szulo` (`szulo_id`, `idopont`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Beállítások (kulcs–érték), pl. kézi EUR/HUF árfolyam
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `beallitasok` (
  `kulcs`      VARCHAR(64) NOT NULL,
  `ertek`      TEXT        NULL,
  `modositva`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kulcs`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Árfolyam gyorsítótár (MNB / ECB lekérés eredménye)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `arfolyam_cache` (
  `id`              TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `eur_huf`         DECIMAL(12,4) NOT NULL,
  `forras`          VARCHAR(64)   NOT NULL,
  `arfolyam_datum`  DATE          NULL,
  `lekerve`         DATETIME      NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  Kezdő ADMIN felhasználó
--  felhasználónév: admin      jelszó: BaninaPRO-2026!
--  A jelszó csak az első belépéshez kell: utána a Profil menüben regisztráld a
--  telefonodat (passkey), és onnantól jelszó nélkül, QR + biometriával lépsz be.
-- ---------------------------------------------------------------------
INSERT INTO `felhasznalok` (`felhasznalonev`, `nev`, `jelszo_hash`, `szerep`, `aktiv`)
VALUES ('admin', 'Adminisztrátor', '$2y$12$rMqhmhqzmBEZe9LjD9aPsuP7qLntfGHgp93KvUSXNRiDlbVeuy6ce', 'admin', 1)
ON DUPLICATE KEY UPDATE `felhasznalonev` = `felhasznalonev`;

INSERT INTO `beallitasok` (`kulcs`, `ertek`) VALUES ('eur_huf_kezi', NULL)
ON DUPLICATE KEY UPDATE `kulcs` = `kulcs`;

INSERT INTO `beallitasok` (`kulcs`, `ertek`) VALUES ('reszt_bejovo', '0')
ON DUPLICATE KEY UPDATE `kulcs` = `kulcs`;
