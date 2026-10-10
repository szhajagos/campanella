<?php

declare(strict_types=1);

namespace Campanella\Event;

use Campanella\Core\Container;

/**
 * An operation bound to events in the configuration (since 0.1.3), e.g. sending
 * an e-mail when an article is published:
 *
 *     // config/local.php
 *     'events' => [
 *         ObjectPublished::class => [MailAdministrators::class],
 *     ],
 *
 * An action is code (a class), the binding is configuration. It runs after the
 * operation was saved; if it fails, the failure is logged and the operation
 * stands (EventDispatcher).
 */
interface Action
{
    /** Makes the action, with the services it needs. Called on its first event. */
    public static function create(Container $container): self;

    public function handle(Event $event): void;
}
