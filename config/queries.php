<?php

declare(strict_types=1);

use Campanella\Query\Query;

/*
 * Elnevezett lekérdezések (a Drupal Views helyett).
 *
 * Nem kell külön jogosultsági feltételt írni: a QueryEngine minden
 * lekérdezéshez hozzáfűzi az aktuális Actorra vonatkozó szabályokat.
 * A 'published' scope-ot a Publishable capability definiálja.
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
];
