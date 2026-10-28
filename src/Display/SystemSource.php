<?php

declare(strict_types=1);

namespace Campanella\Display;

/**
 * Something the system knows that a display can show (since 0.3.3): the visitor, the
 * search, the site, the breadcrumbs of the page. Not data stored as objects, so a class
 * in code, registered in config/app.php (`display_sources`). Its name is what a
 * component stores (`system:visitor`), and what a display names in `sources`.
 */
interface SystemSource
{
    /** Lowercase, unique: `visitor`. */
    public function name(): string;

    /** Its name in the admin (a message key or text). */
    public function label(): string;

    /** What it knows here, for this visitor; null: nothing to show. */
    public function subject(DisplayContext $context): ?Subject;
}
