<?php

declare(strict_types=1);

namespace Campanella\Event;

use Campanella\Access\Actor;
use Campanella\Capability\Publishable;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;
use DateTimeImmutable;

/**
 * An object was published (since 0.1.3), now or for a later time: `$publishedAt`
 * says when it becomes visible (isScheduled()). The event happens when it is
 * published, not when a scheduled time arrives.
 */
final class ObjectPublished extends ObjectEvent
{
    protected const string SUMMARY = 'event.object_published';

    public readonly ?DateTimeImmutable $publishedAt;

    public function __construct(CampanellaObject $object, ?Actor $actor = null)
    {
        parent::__construct($object, $actor);
        $this->publishedAt = $object->has(Publishable::class) ? $object->as(Publishable::class)->publishedAt() : null;
    }

    /** Whether it becomes visible only later. */
    public function isScheduled(): bool
    {
        return $this->publishedAt !== null && $this->publishedAt > $this->occurredAt;
    }

    #[\Override]
    public function summary(): Message
    {
        return $this->isScheduled() && $this->publishedAt !== null
            ? new Message('event.object_scheduled', ['title' => $this->title(), 'id' => (int) $this->object->id(), 'at' => $this->publishedAt->format('Y-m-d H:i') . ' UTC'])
            : parent::summary();
    }
}
