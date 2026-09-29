<?php

declare(strict_types=1);

use Campanella\Auth\Guard\HoneypotGuard;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Authorable;
use Campanella\Capability\Identifiable;
use Campanella\Capability\Publishable;
use Campanella\Capability\Routable;
use Campanella\Capability\Textual;
use Campanella\Capability\Titled;

/*
 * Alapbeállítások. A gépfüggő értékeket (adatbázis, debug) a
 * config/local.php írja felül; mintának lásd: config/local.php.dist
 */
return [
    'debug' => filter_var(getenv('CAMPANELLA_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
    'timezone' => 'Europe/Budapest',

    'site' => [
        'name' => 'Campanella',
        'slogan' => 'Capability-vezérelt CMS',
        'language' => 'hu',
    ],

    // Környezeti változókkal is megadható (pl. Dockerben), a local.php felülírja.
    'database' => [
        'host' => getenv('CAMPANELLA_DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('CAMPANELLA_DB_PORT') ?: 3306),
        'name' => getenv('CAMPANELLA_DB_NAME') ?: 'campanella',
        'user' => getenv('CAMPANELLA_DB_USER') ?: 'campanella',
        'password' => getenv('CAMPANELLA_DB_PASSWORD') ?: '',
        'prefix' => getenv('CAMPANELLA_DB_PREFIX') ?: 'cc_',
    ],

    // A rendszerben elérhető capability-k. Egy modul később ide
    // regisztrálja a sajátjait.
    'capabilities' => [
        Titled::class,
        Textual::class,
        Routable::class,
        Publishable::class,
        Identifiable::class,
        Authenticatable::class,
        Authorable::class,
    ],

    // Munkamenet (bejelentkezés). A munkamenet csak belépéskor indul, a
    // névtelen látogatók nem kapnak cookie-t.
    'session' => [
        'name' => 'campanella_session',
        'idle_timeout' => 7200,        // másodperc tétlenség után kilépteti a felhasználót
        'secure' => 'auto',            // 'auto': csak HTTPS-en küldi a cookie-t; true / false: kényszerítve
    ],

    // Belépési próbálkozások korlátozása.
    'auth' => [
        'max_attempts' => 5,           // ennyi sikertelen próbálkozás e-mail-cím + IP-cím páronként
        'max_attempts_per_ip' => 20,   // és ennyi IP-címenként
        'decay_seconds' => 900,        // ennyi idő alatt (15 perc)
        // A jelszó-ellenőrzés előtt futó kiegészítő védelmek (Campanella\Auth\LoginGuard).
        'guards' => [
            HoneypotGuard::class,
        ],
    ],
];
