<?php

declare(strict_types=1);

namespace Campanella\Event;

use Campanella\Access\Actor;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;
use Campanella\Service\UserService;

/** A user was created in the admin (UserService; since 0.1.3). */
final class UserCreated extends Event implements UserEvent
{
    public function __construct(public readonly CampanellaObject $user, ?Actor $actor = null)
    {
        parent::__construct($actor);
    }

    #[\Override]
    public function user(): CampanellaObject
    {
        return $this->user;
    }

    #[\Override]
    public function summary(): Message
    {
        return new Message('event.user_created', ['name' => UserService::nameOf($this->user), 'id' => (int) $this->user->id()]);
    }
}
