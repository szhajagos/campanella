<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\Core\Container;
use Campanella\Event\Action;
use Campanella\Event\Event;

/** An action of a module on an event (a test): counts what it got. */
final class Ertesites implements Action
{
    public static int $handled = 0;

    public static function create(Container $container): self
    {
        return new self();
    }

    public function handle(Event $event): void
    {
        ++self::$handled;
    }
}
