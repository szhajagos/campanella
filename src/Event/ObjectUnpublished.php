<?php

declare(strict_types=1);

namespace Campanella\Event;

/** An object was unpublished (it became a draft). Since 0.1.3. */
final class ObjectUnpublished extends ObjectEvent
{
    protected const string SUMMARY = 'event.object_unpublished';
}
