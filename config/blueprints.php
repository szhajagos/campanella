<?php

declare(strict_types=1);

use Campanella\Capability\Authenticatable;
use Campanella\Capability\Authorable;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\MediaFile;
use Campanella\Capability\Publishable;
use Campanella\Capability\Routable;
use Campanella\Capability\Textual;
use Campanella\Capability\Titled;
use Campanella\Capability\Weighted;
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
 * 'form_order': the order of fields and relations in the admin form; the ones
 *              not listed follow in their natural order.
 * 'cardinality': narrows the value limit of a capability's multi-valued field.
 * 'defaults':  initial values of a new object (values given on creation win).
 * 'editor':    the toolbar profile of HTML text fields in the admin: 'full' or 'basic'.
 */
return [
    'article' => [
        'label' => 'blueprint.article',
        'capabilities' => [Titled::class, Textual::class, Routable::class, Publishable::class, Authorable::class],
        'fields' => [
            new Field('lead', FieldType::Text, label: 'field.lead'),
        ],
        'relations' => [
            new Relation('categories', Cardinality::Many, targetBlueprints: ['category'], label: 'relation.categories'),
        ],
        'form_order' => ['title', 'lead', 'body', 'categories', 'author', 'path'],
        // New articles get a formatted (HTML) body, edited with the editor.
        'defaults' => ['format' => 'html'],
        'editor' => ['body' => 'full'],
    ],

    'page' => [
        'label' => 'blueprint.page',
        'capabilities' => [Textual::class, Routable::class, Publishable::class],
        'form_order' => ['title', 'body', 'path'],
        'defaults' => ['format' => 'html'],
        'editor' => ['body' => 'full'],
    ],

    // User: name (Titled), e-mail (Authenticatable brings Identifiable),
    // password, account status, roles. Has no public page of its own.
    'user' => [
        'label' => 'blueprint.user',
        'capabilities' => [Titled::class, Authenticatable::class],
    ],

    // An uploaded image: its file data (MediaFile), a title (the original file name
    // at first) and an alternative text. The uploader becomes the author.
    'image' => [
        'label' => 'blueprint.image',
        'capabilities' => [Titled::class, MediaFile::class, Authorable::class],
        'fields' => [
            new Field('alt', FieldType::String, label: 'field.alt'),
        ],
        'form_order' => ['title', 'alt', 'author'],
    ],

    'category' => [
        'label' => 'blueprint.category',
        // A tree (since 0.0.7): a category under a category, in a hand-set order.
        'capabilities' => [Textual::class, Routable::class, Publishable::class, Hierarchical::class, Weighted::class],
        'form_order' => ['title', 'parent', 'weight', 'body', 'path'],
        'lists' => [
            'articles' => [
                'label' => 'list.category.articles',
                // The articles of the category and of its subcategories (since 0.0.7).
                'query' => static fn (CampanellaObject $category): Query => Hierarchical::relatedWithin(
                    Query::objects()->blueprint('article'),
                    'categories',
                    $category,
                )
                    ->scope('published')
                    ->orderBy('published_at', 'DESC')
                    ->limit(20),
            ],
        ],
    ],
];
