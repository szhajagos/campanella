<?php

declare(strict_types=1);

namespace Campanella\Event;

use Campanella\Access\Actor;
use Campanella\Capability\Titled;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;

/** Something happened to an object (since 0.1.3). Listening to this class gets all of its kinds. */
abstract class ObjectEvent extends Event
{
    /** The message key of the summary, e.g. `event.object_published`. */
    protected const string SUMMARY = '';

    public function __construct(public readonly CampanellaObject $object, ?Actor $actor = null)
    {
        parent::__construct($actor);
    }

    #[\Override]
    public function summary(): Message
    {
        return new Message(static::SUMMARY, ['title' => $this->title(), 'id' => (int) $this->object->id()]);
    }

    /** The object's title, or `#<id>` if it has none. */
    public function title(): string
    {
        $title = $this->object->has(Titled::class) ? $this->object->as(Titled::class)->title() : '';

        return $title !== '' ? $title : '#' . $this->object->id();
    }
}
