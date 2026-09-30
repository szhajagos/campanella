<?php

declare(strict_types=1);

namespace Campanella\Relation;

/** How many target objects a relation can have. */
enum Cardinality: string
{
    /** At most one (e.g. author, parent). */
    case One = 'one';

    /** Any number, ordered (e.g. categories, images). */
    case Many = 'many';
}
