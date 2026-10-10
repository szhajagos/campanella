<?php

declare(strict_types=1);

namespace Campanella\Event;

/** An object's values or relations were changed and saved. Since 0.1.3. */
final class ObjectUpdated extends ObjectEvent
{
    protected const string SUMMARY = 'event.object_updated';
}
