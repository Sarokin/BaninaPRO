<?php
declare(strict_types=1);

/**
 * Kapcsolat-ellenőrzés + szívverés (1.14): a nyitott lap ULES_JELENLET_MP másodpercenként hívja.
 * 'tetlen' = hány másodperce nem volt felhasználói tevékenység a lapon (a tétlenségi korláthoz).
 * Válasz: belepve (él-e még a belépés), lejart (ha épp most járt le: az ok és a név a belépő képernyőhöz).
 */
function act_ping(array $be): array
{
    $u = aktualis_felhasznalo();
    if ($u !== null && array_key_exists('tetlen', $be) && is_numeric($be['tetlen'])) {
        munkamenet_tevekenyseg((int)$be['tetlen']);
    }
    return ['app' => APP_NAME, 'verzio' => APP_VERSION, 'ido' => date('Y-m-d H:i:s'),
            'belepve' => $u !== null, 'jelenlet_mp' => ules_korlat('jelenlet'), 'lejart' => munkamenet_lejarat_info()];
}

/**
 * „Lap bezárva” jelzés (1.14): a lap pagehide-kor küldi (sendBeacon / keepalive fetch, ezért a CSRF-token a törzsben jön).
 * Innentől a belépés csak ULES_LAPZARAS_PERC percig nyitható vissza – utána a szerver kilépteti.
 * Egy másik nyitott lap szívverése (vagy a lap gyors visszanyitása) érvényteleníti a jelzést.
 */
function act_lap_zaras(array $be): array
{
    if (!hash_equals(csrf_token(), (string)($be['csrf'] ?? ''))) {
        return ['jelezve' => false];
    }
    return ['jelezve' => munkamenet_lap_zaras()];
}

/** Bejelentkezés */
function act_belepes(array $be): array
{
    $u = bejelentkezes((string)($be['felhasznalonev'] ?? ''), (string)($be['jelszo'] ?? ''));
    return ['felhasznalo' => felhasznalo_publikus($u), 'beallitasok' => beallitasok_publikus()];
}

/** Ki vagyok? (oldalbetöltéskor) */
function act_en(array $be): array
{
    $u = aktualis_felhasznalo();
    return ['felhasznalo' => felhasznalo_publikus($u), 'app' => APP_NAME, 'verzio' => APP_VERSION, 'beallitasok' => $u ? beallitasok_publikus() : null,
            'lejart' => munkamenet_lejarat_info()];
}

/** Kijelentkezés */
function act_kilepes(array $be): array
{
    kijelentkezes();
    return ['kilepve' => true];
}

/** Saját jelszó módosítása */
function act_jelszo_modositas(array $be): array
{
    $u = csak_bejelentkezve();
    $regi = (string)($be['regi_jelszo'] ?? '');
    $uj   = (string)($be['uj_jelszo'] ?? '');
    $sor = db_row('SELECT jelszo_hash FROM felhasznalok WHERE id = ?', [$u['id']]);
    if (!$sor || !password_verify($regi, $sor['jelszo_hash'])) {
        naplo('JELSZO_MODOSITAS', 'hibás régi jelszó', 'HIBA');
        hiba('A jelenlegi jelszó nem megfelelő.');
    }
    jelszo_ellenorzes($uj);
    db_exec('UPDATE felhasznalok SET jelszo_hash = ? WHERE id = ?', [password_hash($uj, PASSWORD_BCRYPT), $u['id']]);
    naplo('JELSZO_MODOSITAS', 'saját jelszó módosítva');
    return ['modositva' => true];
}
