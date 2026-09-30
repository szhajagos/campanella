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

    'categories' => static fn (): Query => Query::objects()
        ->blueprint('category')
        ->scope('published')
        ->orderBy('title', 'ASC')
        ->limit(50),
];
