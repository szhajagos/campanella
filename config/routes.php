<?php

declare(strict_types=1);

/*
 * Static routes: path => [handler, parameters].
 *
 * Anything not listed here is tried by the Router as the path of a
 * Routable object (e.g. /neumann-janos → ObjectController).
 */
return [
    '/' => ['query', ['query' => 'frontpage', 'title' => '']],
    '/hirek' => ['query', ['query' => 'news', 'title' => 'Hírek', 'per_page' => 5]],
    '/kategoriak' => ['query', ['query' => 'categories', 'title' => 'Kategóriák']],
    '/belepes' => ['auth', ['action' => 'login']],
    '/kilepes' => ['auth', ['action' => 'logout']],
];
