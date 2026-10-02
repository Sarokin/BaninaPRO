<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Tevékenységnapló.
 * Minden nap ÉJJEL 3-KOR nyílik új fájl: LOG/ÉÉÉÉHHNN.txt
 * (a fájl "napja" = az aktuális idő mínusz 3 óra dátuma, így 02:59-kor még
 *  az előző napi fájlba, 03:00-tól az újba írunk – cron nélkül).
 *
 * Sor formátum:
 * [2026-09-29 14:03:22] felhasznalo | IP | MŰVELET | EREDMÉNY | részletek
 */
function naplo_fajl(?int $t = null): string
{
    $t = $t ?? time();
    return rtrim(LOG_DIR, '/') . '/' . date('Ymd', $t - LOG_ROTATE_HOUR * 3600) . '.txt';
}

function naplo(string $muvelet, string $reszletek = '', string $eredmeny = 'OK', ?string $felhasznalo = null): void
{
    try {
        if (!is_dir(LOG_DIR)) {
            @mkdir(LOG_DIR, 0750, true);
        }
        if ($felhasznalo === null) {
            $felhasznalo = function_exists('aktualis_felhasznalo_nev') ? aktualis_felhasznalo_nev() : '-';
        }
        $reszletek = preg_replace('/[\r\n\t]+/', ' ', $reszletek) ?? '';
        $sor = sprintf(
            "[%s] %s | %s | %s | %s | %s\n",
            date('Y-m-d H:i:s'),
            $felhasznalo !== '' ? $felhasznalo : '-',
            function_exists('kliens_ip') ? kliens_ip() : ($_SERVER['REMOTE_ADDR'] ?? '-'),
            $muvelet,
            $eredmeny,
            $reszletek
        );
        @file_put_contents(naplo_fajl(), $sor, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // a naplózás hibája soha ne törje meg a fő műveletet
    }
}
