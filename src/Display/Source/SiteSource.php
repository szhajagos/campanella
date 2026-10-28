<?php

declare(strict_types=1);

namespace Campanella\Display\Source;

use Campanella\Display\DisplayContext;
use Campanella\Display\Subject;
use Campanella\Display\SystemSource;

/** The site itself (since 0.3.3): its name and slogan, which templates read as `site`. */
final class SiteSource implements SystemSource
{
    public const string NAME = 'site';

    #[\Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function label(): string
    {
        return 'display.source.site';
    }

    #[\Override]
    public function subject(DisplayContext $context): Subject
    {
        return Subject::system(self::NAME);
    }
}
