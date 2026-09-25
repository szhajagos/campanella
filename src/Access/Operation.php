<?php

declare(strict_types=1);

namespace Campanella\Access;

/** A rendszer műveleti nyelve. */
enum Operation: string
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Publish = 'publish';
    case Unpublish = 'unpublish';
}
