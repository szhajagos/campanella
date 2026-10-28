<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Source\VisitorSource;
use Campanella\Display\Subject;

/** The logged-in user's card: their name, the admin and logout buttons (nothing for an anonymous visitor; since 0.3.3). */
#[AsDisplay('user_card', sources: [VisitorSource::NAME], label: 'display.user_card')]
final class UserCardDisplay extends Display
{
    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): ?array
    {
        return $subject->object === null ? null : ['user' => $subject->object];
    }
}
