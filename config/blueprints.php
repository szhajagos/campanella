<?php

declare(strict_types=1);

use Campanella\Capability\Publishable;
use Campanella\Capability\Routable;
use Campanella\Capability\Textual;
use Campanella\Capability\Titled;
use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Query\Query;
use Campanella\Relation\Cardinality;
use Campanella\Relation\Relation;

/*
 * Blueprintek: elnevezett capability-csomagok. A „típus” így adat
 * marad, nem PHP-osztály.
 *
 * A függőségeket nem kell felsorolni: a Routable magával hozza a Titled-et.
 * A 'fields' alatti egyedi mezők a data (JSON) oszlopba kerülnek, ezért
 * szűrni és rendezni nem lehet rájuk.
 *
 * 'relations': a Blueprint saját kapcsolatai más objektumokkal.
 * 'lists':     az objektum saját oldalán megjelenő listák (felirat + Query).
 */
return [
    'article' => [
        'label' => 'Cikk',
        'capabilities' => [Titled::class, Textual::class, Routable::class, Publishable::class],
        'fields' => [
            new Field('lead', FieldType::Text, label: 'Bevezető'),
        ],
        'relations' => [
            new Relation('categories', Cardinality::Many, targetBlueprints: ['category'], label: 'Kategóriák'),
        ],
    ],

    'page' => [
        'label' => 'Oldal',
        'capabilities' => [Textual::class, Routable::class, Publishable::class],
    ],

    'category' => [
        'label' => 'Kategória',
        'capabilities' => [Textual::class, Routable::class, Publishable::class],
        'lists' => [
            'articles' => [
                'label' => 'Cikkek ebben a kategóriában',
                'query' => static fn (CampanellaObject $category): Query => Query::objects()
                    ->blueprint('article')
                    ->whereRelated('categories', $category)
                    ->scope('published')
                    ->orderBy('published_at', 'DESC')
                    ->limit(20),
            ],
        ],
    ],
];
