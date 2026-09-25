<?php

declare(strict_types=1);

use Campanella\Capability\Publishable;
use Campanella\Capability\Routable;
use Campanella\Capability\Textual;
use Campanella\Capability\Titled;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/*
 * Blueprintek: elnevezett capability-csomagok. A „típus” így adat
 * marad, nem PHP-osztály.
 *
 * A függőségeket nem kell felsorolni: a Routable magával hozza a Titled-et.
 * A 'fields' alatti egyedi mezők a data (JSON) oszlopba kerülnek, ezért
 * szűrni és rendezni nem lehet rájuk.
 */
return [
    'article' => [
        'label' => 'Cikk',
        'capabilities' => [Titled::class, Textual::class, Routable::class, Publishable::class],
        'fields' => [
            new Field('lead', FieldType::Text, label: 'Bevezető'),
        ],
    ],

    'page' => [
        'label' => 'Oldal',
        'capabilities' => [Textual::class, Routable::class, Publishable::class],
    ],
];
