<?php

declare(strict_types=1);

namespace Campanella\Display\Source;

use Campanella\Display\DisplayContext;
use Campanella\Display\Subject;
use Campanella\Display\SystemSource;

/** The site's search (since 0.3.3): its value is the words searched for, on the search page ('' elsewhere). */
final class SearchSource implements SystemSource
{
    public const string NAME = 'search';

    #[\Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function label(): string
    {
        return 'display.source.search';
    }

    #[\Override]
    public function subject(DisplayContext $context): Subject
    {
        return Subject::system(self::NAME, $context->search);
    }
}
