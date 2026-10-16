<?php
/**
 * IB-partner ↔ cég ellenőrzés (csak jelent): melyik cégnél vannak több IB-partnerkód számlái, és melyik IB-partnerkód számlái
 * oszlanak meg több cég között. Az app importja a cégeket a számlák szerint csoportosítja („szavazás”), így egy DOS-ban rossz
 * vevőnél rögzített számla a fájl egy másik partnerét is ugyanahhoz a céghez húzhatja – ezt mutatja meg.
 *
 *   kornyezet.sh php <db> /migracio/eszkozok/php/ib_partner_elemzes.php <xlsx>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db.php';
require_once '/var/www/html/includes/helpers.php';
require_once '/var/www/html/includes/logger.php';
require_once '/var/www/html/includes/ids.php';
require_once '/var/www/html/includes/audit.php';
require_once '/var/www/html/includes/import.php';

$el = import_elemez($argv[1], basename($argv[1]));
$t = import_sorok_allapot($el);
$cegPk = [];      // DB cég → IB partnerkód → [db, nevek, adószámok]
$pkCeg = [];      // IB partnerkód → DB cég → db
$cegNev = [];
foreach ($t['sorok'] as $s) {
    $l = $s['letezo'] ?? null;
    if ($s['irany'] !== 'KIMENO' || $l === null) {
        continue;
    }
    $pk = $s['partnerkod'] !== '' ? $s['partnerkod'] : '(nincs)';
    $c = (int)$l['ceg_id'];
    $cegNev[$c] = $l['ceg_nev'];
    $cegPk[$c][$pk]['db'] = ($cegPk[$c][$pk]['db'] ?? 0) + 1;
    $cegPk[$c][$pk]['nev'][$s['ceg_nev']] = true;
    $cegPk[$c][$pk]['ado'][$s['adoszam']] = true;
    $cegPk[$c][$pk]['pl'] = $cegPk[$c][$pk]['pl'] ?? "{$s['szamlaszam']} {$s['osszeg']}";
    $pkCeg[$pk][$c] = ($pkCeg[$pk][$c] ?? 0) + 1;
}
echo "CÉGEK TÖBB IB-PARTNERKÓDDAL (a rendszerben ennél a cégnél vannak a számláik):\n";
foreach ($cegPk as $c => $pks) {
    if (count($pks) > 1) {
        echo "  #$c {$cegNev[$c]}:\n";
        foreach ($pks as $pk => $x) {
            printf("     IB %-7s %4d számla  név: %s  adószám: %s  (pl. %s)\n", $pk, $x['db'], implode(' / ', array_keys($x['nev'])), implode(' / ', array_filter(array_keys($x['ado']))), $x['pl']);
        }
    }
}
echo "IB-PARTNERKÓDOK TÖBB CÉGNÉL:\n";
foreach ($pkCeg as $pk => $cs) {
    if (count($cs) > 1) {
        echo "  IB $pk: " . implode(', ', array_map(fn($c, $n) => "#$c {$cegNev[$c]} ($n)", array_keys($cs), $cs)) . "\n";
    }
}
