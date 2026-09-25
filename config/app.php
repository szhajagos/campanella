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
    'debug' => false,
    'timezone' => 'Europe/Budapest',

    'site' => [
        'name' => 'Campanella',
        'slogan' => 'Capability-vezérelt CMS',
        'language' => 'hu',
    ],

    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'campanella',
        'user' => 'campanella',
        'password' => '',
        'prefix' => 'cc_',
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
