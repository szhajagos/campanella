<?php

declare(strict_types=1);

/*
 * Static routes: path => [handler, parameters].
 *
 * Anything not listed here is tried by the Router as the path of a
 * Routable object (e.g. /neumann-janos → ObjectController).
 *
 * The system's own pages (logging in and out, the admin, installing) are not
 * here: their paths are settings (`paths`, `admin.path` in config/app.php).
 *
 * The news and the categories are routed in the site's language (since 0.3.7,
 * Campanella\Site\ContentPaths: /news on an English site, /hirek on a Hungarian
 * one or one installed before). A route here for the `news` or `categories` query
 * (e.g. in a copy of this file from before 0.3.7) takes their place.
 */
return [
    '/' => ['query', ['query' => 'frontpage', 'title' => '']],
];
