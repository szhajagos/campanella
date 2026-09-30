<?php

declare(strict_types=1);

use Campanella\Capability\Authenticatable;
use Campanella\Capability\Authorable;
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
 * Blueprints: named capability bundles. This way the "type" stays data,
 * not a PHP class.
 *
 * Dependencies need not be listed: Routable brings Titled along with it.
 * Custom fields under 'fields' are stored in the data (JSON) column, so
 * they cannot be filtered or sorted on.
 *
 * 'relations': the Blueprint's own relations to other objects.
 * 'lists':     lists shown on the object's own page (label + Query).
 */
return [
    'article' => [
        'label' => 'Cikk',
        'capabilities' => [Titled::class, Textual::class, Routable::class, Publishable::class, Authorable::class],
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

    // User: name (Titled), e-mail (Authenticatable brings Identifiable),
    // password, account status, roles. Has no public page of its own.
    'user' => [
        'label' => 'Felhasználó',
        'capabilities' => [Titled::class, Authenticatable::class],
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
