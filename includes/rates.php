<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * EUR/HUF árfolyam.
 * Sorrend: gyorsítótár (RATE_CACHE_HOURS) → MNB hivatalos (SOAP) → ECB (frankfurter.app)
 *          → open.er-api.com → kézi beállítás → lejárt gyorsítótár → null
 * Visszatérés: ['eur_huf' => 395.5, 'forras' => 'MNB', 'datum' => '2026-09-29', 'lekerve' => '...'] vagy null
 */
function arfolyam_eur_huf(bool $frissit = false): ?array
{
    $cache = db_row('SELECT eur_huf, forras, arfolyam_datum, lekerve FROM arfolyam_cache WHERE id = 1');
    if ($cache && !$frissit) {
        $kor = time() - strtotime($cache['lekerve']);
        if ($kor < RATE_CACHE_HOURS * 3600 && $cache['forras'] !== 'kézi') {
            return arfolyam_valasz($cache['eur_huf'], $cache['forras'], $cache['arfolyam_datum'], $cache['lekerve']);
        }
    }

    $friss = arfolyam_mnb() ?? arfolyam_frankfurter() ?? arfolyam_erapi();
    if ($friss !== null) {
        db_exec(
            'INSERT INTO arfolyam_cache (id, eur_huf, forras, arfolyam_datum, lekerve) VALUES (1, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE eur_huf = VALUES(eur_huf), forras = VALUES(forras), arfolyam_datum = VALUES(arfolyam_datum), lekerve = NOW()',
            [$friss['eur_huf'], $friss['forras'], $friss['datum']]
        );
        return arfolyam_valasz($friss['eur_huf'], $friss['forras'], $friss['datum'], date('Y-m-d H:i:s'));
    }

    // kézi beállítás
    $kezi = db_val('SELECT ertek FROM beallitasok WHERE kulcs = ?', ['eur_huf_kezi']);
    if ($kezi !== null && is_numeric(str_replace(',', '.', (string)$kezi)) && (float)str_replace(',', '.', (string)$kezi) > 0) {
        $mod = db_val('SELECT modositva FROM beallitasok WHERE kulcs = ?', ['eur_huf_kezi']);
        return arfolyam_valasz((float)str_replace(',', '.', (string)$kezi), 'kézi', substr((string)$mod, 0, 10), (string)$mod);
    }

    // lejárt gyorsítótár – jobb, mint a semmi
    if ($cache) {
        return arfolyam_valasz($cache['eur_huf'], $cache['forras'] . ' (régi)', $cache['arfolyam_datum'], $cache['lekerve']);
    }
    return null;
}

function arfolyam_valasz($ertek, string $forras, ?string $datum, ?string $lekerve): array
{
    return [
        'eur_huf' => round((float)$ertek, 4),
        'forras'  => $forras,
        'datum'   => $datum,
        'lekerve' => $lekerve,
    ];
}

/** Egyszerű HTTP kliens (cURL, ha van; különben file_get_contents) */
function http_keres(string $url, ?string $post = null, array $fejlecek = []): ?string
{
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => RATE_HTTP_TIMEOUT,
                CURLOPT_TIMEOUT        => RATE_HTTP_TIMEOUT + 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'BaninaPRO/' . APP_VERSION,
                CURLOPT_HTTPHEADER     => $fejlecek,
            ]);
            if ($post !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            }
            $valasz = curl_exec($ch);
            $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($valasz === false || $kod < 200 || $kod >= 300) {
                return null;
            }
            return (string)$valasz;
        }
        $ctx = stream_context_create(['http' => [
            'method'  => $post !== null ? 'POST' : 'GET',
            'header'  => implode("\r\n", array_merge($fejlecek, ['User-Agent: BaninaPRO/' . APP_VERSION])),
            'content' => $post ?? '',
            'timeout' => RATE_HTTP_TIMEOUT,
        ]]);
        $valasz = @file_get_contents($url, false, $ctx);
        return $valasz === false ? null : $valasz;
    } catch (Throwable $e) {
        return null;
    }
}

/** MNB hivatalos középárfolyam – SOAP webszolgáltatás (ingyenes, nyilvános) */
function arfolyam_mnb(): ?array
{
    $soap = '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<soap:Body><GetCurrentExchangeRates xmlns="http://www.mnb.hu/webservices/" /></soap:Body>'
        . '</soap:Envelope>';
    $xml = http_keres('https://www.mnb.hu/arfolyamok.asmx', $soap, [
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: "http://www.mnb.hu/webservices/GetCurrentExchangeRates"',
    ]);
    if ($xml === null) {
        return null;
    }
    return mnb_valasz_feldolgozas($xml);
}

/** Az MNB SOAP válaszából kiszedi az EUR árfolyamot (a belső XML entitásokkal kódolva érkezik) */
function mnb_valasz_feldolgozas(string $xml): ?array
{
    $belso = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    if (!preg_match('/<Day\s+date="(\d{4}-\d{2}-\d{2})"/', $belso, $d)) {
        return null;
    }
    if (!preg_match('/<Rate\s+unit="(\d+)"\s+curr="EUR"\s*>([\d\.,]+)<\/Rate>/', $belso, $r)) {
        return null;
    }
    $unit = max(1, (int)$r[1]);
    $ertek = (float)str_replace(',', '.', $r[2]) / $unit;
    if ($ertek <= 0) {
        return null;
    }
    return ['eur_huf' => $ertek, 'forras' => 'MNB', 'datum' => $d[1]];
}

/** ECB referencia-árfolyam a frankfurter.app ingyenes API-ján keresztül */
function arfolyam_frankfurter(): ?array
{
    $json = http_keres('https://api.frankfurter.app/latest?from=EUR&to=HUF');
    if ($json === null) {
        return null;
    }
    $a = json_decode($json, true);
    if (!is_array($a) || empty($a['rates']['HUF'])) {
        return null;
    }
    return ['eur_huf' => (float)$a['rates']['HUF'], 'forras' => 'ECB', 'datum' => $a['date'] ?? date('Y-m-d')];
}

/** Tartalék: open.er-api.com */
function arfolyam_erapi(): ?array
{
    $json = http_keres('https://open.er-api.com/v6/latest/EUR');
    if ($json === null) {
        return null;
    }
    $a = json_decode($json, true);
    if (!is_array($a) || empty($a['rates']['HUF'])) {
        return null;
    }
    $datum = !empty($a['time_last_update_unix']) ? date('Y-m-d', (int)$a['time_last_update_unix']) : date('Y-m-d');
    return ['eur_huf' => (float)$a['rates']['HUF'], 'forras' => 'ER-API', 'datum' => $datum];
}
