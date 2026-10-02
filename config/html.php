<?php

declare(strict_types=1);

/*
 * The HTML allowlist: what may remain in a text in `html` format. Everything
 * else is removed on save (Campanella\Html\HtmlSanitizer). See
 * docs/php-api/15-html.md. Keys left out keep their default; to allow fewer
 * or more elements, give the whole `elements` list.
 */
return [
    // Element => its allowed attributes. Any other attribute (style, class, id,
    // on… handlers) is removed.
    'elements' => [
        'p' => [], 'div' => [], 'br' => [], 'hr' => [],
        'h2' => [], 'h3' => [], 'h4' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 's' => [], 'sub' => [], 'sup' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'blockquote' => [], 'code' => [], 'pre' => [],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'figure' => [], 'figcaption' => [],
        'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
    ],

    // Link schemes; relative links (/hirek, #section) are always allowed.
    'link_schemes' => ['http', 'https', 'mailto'],

    // Images only from this site (relative addresses, e.g. /media/…). External
    // images send the visitors' data to another server, and can change or vanish.
    'external_images' => false,

    // The longest HTML text accepted, in bytes; a longer one is a validation error.
    'max_length' => 1_000_000,

    // The most tags (counted as `<` characters) accepted: deeply nested markup makes
    // parsing very slow, so a text with more is a validation error. A long article has
    // a few thousand.
    'max_tags' => 20_000,
];
