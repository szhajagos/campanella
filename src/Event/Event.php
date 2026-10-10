<?php

declare(strict_types=1);

namespace Campanella\Event;

use Campanella\Access\Actor;
use Campanella\I18n\Message;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Something that happened (since 0.1.3), after it was saved: an object was
 * published, a user was created. The EventDispatcher passes it to the actions
 * bound to its class (or to a class it extends).
 *
 * An event says what happened, not what to do: the actions decide that.
 */
abstract class Event
{
    public readonly DateTimeImmutable $occurredAt;

    /** @param Actor|null $actor Who did it (null: the system, e.g. the command line) */
    public function __construct(public readonly ?Actor $actor = null)
    {
        $this->occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** A short, translatable sentence about what happened (e.g. for an e-mail or a log). */
    abstract public function summary(): Message;

    /** The event's name for logs and messages: its class's short name (`ObjectPublished`). */
    public function name(): string
    {
        return substr(strrchr('\\' . static::class, '\\') ?: '', 1);
    }
}
