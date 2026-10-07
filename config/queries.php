<?php

declare(strict_types=1);

use Campanella\Query\Query;

/*
 * Named queries (instead of Drupal Views).
 *
 * No separate access control condition is needed: the QueryEngine appends
 * the policies for the current Actor to every query.
 * The 'published' scope is defined by the Publishable capability.
 */
return [
    'frontpage' => static fn (): Query => Query::objects()
        ->blueprint('article')
        ->scope('published')
        ->orderBy('published_at', 'DESC')
        ->limit(3),

    'news' => static fn (): Query => Query::objects()
        ->having('textual', 'routable', 'publishable')
        ->blueprint('article')
        ->scope('published')
        ->orderBy('published_at', 'DESC')
        ->limit(10),

    // Shown as a tree (templates/query/categories.html.twig), so all of them, in
    // their hand-set order. A category under a draft one is shown at the top level.
    'categories' => static fn (): Query => Query::objects()
        ->blueprint('category')
        ->scope('published')
        ->scope('by_weight')
        ->limit(500),
];
