<?php

declare(strict_types=1);

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
    ],
];
