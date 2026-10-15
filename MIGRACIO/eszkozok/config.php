<?php
/**
 * BaninaPRO – konfiguráció a MIGRÁCIÓS környezethez (MIGRACIO/eszkozok/kornyezet.sh).
 * A bp-mig-app konténerben ez lép az includes/config.php helyére. Az adatbázis neve a BANINA_DB
 * környezeti változóból jön, így minden futás saját, friss adatbázist kap (nincs DROP).
 * Minden más beállítás a fejlesztői docker/config.php-ból jön, így az mindig naprakész (APP_VERSION is).
 */
declare(strict_types=1);

define('DB_HOST', 'db');
define('DB_PORT', 3306);
define('DB_NAME', getenv('BANINA_DB') ?: 'baninapr_DATA');
define('DB_USER', 'baninapr_DATA');
define('DB_PASS', 'migracio_app');

// a többi konstans a fejlesztői configból; az ottani DB_* definíciók figyelmeztetését elnyeljük (az itteniek érvényesek)
set_error_handler(static fn() => true);
require '/var/www/html/docker/config.php';
restore_error_handler();
