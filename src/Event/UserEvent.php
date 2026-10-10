<?php

declare(strict_types=1);

namespace Campanella\Event;

use Campanella\Model\CampanellaObject;

/**
 * An event about a user account (since 0.1.4): UserCreated, PasswordChanged. The
 * MailUser action e-mails that user.
 */
interface UserEvent
{
    /** The user the event is about. */
    public function user(): CampanellaObject;
}
