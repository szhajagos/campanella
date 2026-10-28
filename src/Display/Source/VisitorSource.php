<?php

declare(strict_types=1);

namespace Campanella\Display\Source;

use Campanella\Display\DisplayContext;
use Campanella\Display\Subject;
use Campanella\Display\SystemSource;

/**
 * The one who looks at the page (since 0.3.3): its value is the Actor, its object the
 * logged-in user (null for an anonymous visitor). Shown by the login and logout button,
 * the admin button, the login form and the user's card.
 */
final class VisitorSource implements SystemSource
{
    public const string NAME = 'visitor';

    #[\Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function label(): string
    {
        return 'display.source.visitor';
    }

    #[\Override]
    public function subject(DisplayContext $context): Subject
    {
        return Subject::system(self::NAME, $context->actor, $context->user());
    }
}
