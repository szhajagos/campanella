<?php

declare(strict_types=1);

namespace Campanella\Event;

use Campanella\Access\Actor;
use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;
use Campanella\Service\UserService;

/**
 * A user's password was changed (since 0.1.3): by the user on the profile, by an
 * administrator (`$byAdministrator`), or with a forgotten password's link
 * (`$byReset`, since 0.1.4). Never carries the password.
 */
final class PasswordChanged extends Event implements UserEvent
{
    public function __construct(
        public readonly CampanellaObject $user,
        public readonly bool $byAdministrator,
        ?Actor $actor = null,
        public readonly bool $byReset = false,
    ) {
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
        return new Message('event.password_changed', ['name' => UserService::nameOf($this->user), 'id' => (int) $this->user->id()]);
    }
}
