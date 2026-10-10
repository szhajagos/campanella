<?php

declare(strict_types=1);

namespace Campanella\Event;

/** An object was created (saved for the first time). Since 0.1.3. */
final class ObjectCreated extends ObjectEvent
{
    protected const string SUMMARY = 'event.object_created';
}
