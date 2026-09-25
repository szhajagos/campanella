<?php

declare(strict_types=1);

/*
 * Statikus útvonalak: útvonal => [handler, paraméterek].
 *
 * Ami itt nem szerepel, azt a Router egy Routable objektum útvonalaként
 * próbálja meg (pl. /neumann-janos → ObjectController).
 */
return [
    '/' => ['query', ['query' => 'frontpage', 'title' => '']],
    '/hirek' => ['query', ['query' => 'news', 'title' => 'Hírek', 'per_page' => 5]],
];
