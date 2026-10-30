<?php

declare(strict_types=1);

use Campanella\Capability\Authenticatable;
use Campanella\Capability\Authorable;
use Campanella\Capability\Component;
use Campanella\Capability\Composed;
use Campanella\Capability\Downloadable;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\Keyed;
use Campanella\Capability\Link;
use Campanella\Capability\MediaFile;
use Campanella\Capability\Publishable;
use Campanella\Capability\Restricted;
use Campanella\Capability\Routable;
use Campanella\Capability\Submitted;
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
 * they cannot be filtered or sorted on, except those listed under
 * 'queryable' (since 0.2.2): they get a column in the Blueprint's own table
 * (bp_<name>), e.g.
 *
 *   'fields' => [new Field('price', FieldType::Decimal), new Field('day', FieldType::Date)],
 *   'queryable' => ['price', 'day'],
 *
 * Field types: String, Text, Integer, Boolean, DateTime, and since 0.2.2
 * Decimal, Date, Email, Url, Choice (with 'choices') and Html (formatted text).
 *
 * Blueprints can also be made in the admin (System → Blueprints, since 0.2.3);
 * those are stored in the database, and the names used here cannot be taken there.
 *
 * 'relations': the Blueprint's own relations to other objects.
 * 'lists':     lists shown on the object's own page (label + Query).
 * 'form_order': the order of fields and relations in the admin form; the ones
 *              not listed follow in their natural order.
 * 'cardinality': narrows the value limit of a capability's multi-valued field.
 * 'defaults':  initial values of a new object (values given on creation win).
 * 'editor':    the toolbar profile of HTML text fields in the admin: 'full' or 'basic'.
 * 'tree_scope': for a Hierarchical Blueprint, a required single relation that splits
 *              the objects into separate trees (a menu item's menu).
 * 'admin_columns': at most 4 own fields and relations shown as columns of the list in
 *              the admin; the queryable ones get filters, those with one value sorting
 *              (since 0.2.8).
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
        // The categories in the list of articles in the admin, with a filter (since 0.2.8).
        'admin_columns' => ['categories'],
    ],

    // A page (since 0.3.5 also assembled from components: Composed, with a layout of its own).
    'page' => [
        'label' => 'blueprint.page',
        'capabilities' => [Textual::class, Routable::class, Publishable::class, Composed::class],
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

    // A file to download (since 0.3.2): a document, a spreadsheet, a text file. Its file
    // (Downloadable), a title (the original file name at first) and who may download it
    // (Restricted: everyone, logged-in users, or some roles). The uploader becomes the author.
    'file' => [
        'label' => 'blueprint.file',
        'capabilities' => [Titled::class, Downloadable::class, Restricted::class, Authorable::class],
        'form_order' => ['title', 'visible_to', 'visible_roles', 'author'],
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

    // A menu (since 0.0.7): templates find it by its machine name, e.g. menu('main').
    'menu' => [
        'label' => 'blueprint.menu',
        'capabilities' => [Titled::class, Keyed::class],
        'form_order' => ['title', 'machine_name'],
    ],

    // A menu item: in a menu, under another item of the same menu (or at the top),
    // in a hand-set order; it leads to an object of the site or to a URL (Link).
    'menu_item' => [
        'label' => 'blueprint.menu_item',
        'capabilities' => [Titled::class, Link::class, Hierarchical::class, Weighted::class],
        'relations' => [
            new Relation('menu', Cardinality::One, targetBlueprints: ['menu'], required: true, label: 'relation.menu'),
        ],
        // Each menu is its own tree.
        'tree_scope' => 'menu',
        'form_order' => ['title', 'menu', 'parent', 'weight', 'target', 'url'],
    ],

    // A snippet (since 0.3.3): a short text with a title (opening hours, a contact, a notice) to be
    // shown by a component, e.g. in an info box. It has no page of its own.
    'snippet' => [
        'label' => 'blueprint.snippet',
        'capabilities' => [Titled::class, Textual::class],
        'form_order' => ['title', 'body'],
        'defaults' => ['format' => 'html'],
        'editor' => ['body' => 'basic'],
    ],

    // A component (since 0.3.3): a source (an object, a list, the page's object, the visitor …)
    // shown with a display, with its settings; for administrators only. Templates show one by
    // its machine name: component('lablec_menu'); from 0.3.4 on, in a region of the layout.
    'component' => [
        'label' => 'blueprint.component',
        // Since 0.3.4 also its order within its region (Weighted).
        'capabilities' => [Titled::class, Keyed::class, Restricted::class, Weighted::class, Component::class],
        'form_order' => ['title', 'machine_name', 'visible_to', 'visible_roles'],
    ],

    // A message sent through the contact form (since 0.1.5): the sender's name (Titled),
    // e-mail address, subject and message. Only administrators may see it (DefaultPolicy);
    // in the admin under Submissions, read-only.
    'submission' => [
        'label' => 'blueprint.submission',
        'capabilities' => [Titled::class, Submitted::class],
    ],
];
