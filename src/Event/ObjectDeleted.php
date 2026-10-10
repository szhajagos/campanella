<?php

declare(strict_types=1);

namespace Campanella\Event;

/** An object was deleted. Its ID is still readable; it cannot be loaded any more. Since 0.1.3. */
final class ObjectDeleted extends ObjectEvent
{
    protected const string SUMMARY = 'event.object_deleted';
}
