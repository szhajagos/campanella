<?php

declare(strict_types=1);

namespace Campanella\Display\Source;

use Campanella\Capability\Hierarchical;
use Campanella\Display\DisplayContext;
use Campanella\Display\Subject;
use Campanella\Display\SystemSource;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;

/**
 * Where the page is (since 0.3.3): its object, and above it, for a tree node (a
 * category), the ancestors the visitor may see. Its value: `ancestors` (a list of
 * objects, from the top) and `current`; nothing on a page without an object.
 */
final class BreadcrumbsSource implements SystemSource
{
    public const string NAME = 'breadcrumbs';

    public function __construct(private readonly ?QueryEngine $queries = null)
    {
    }

    #[\Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function label(): string
    {
        return 'display.source.breadcrumbs';
    }

    #[\Override]
    public function subject(DisplayContext $context): ?Subject
    {
        $current = $context->current;
        if ($current === null) {
            return null;
        }
        $ancestors = [];
        if ($this->queries !== null && $current->has(Hierarchical::class)) {
            $ancestors = $this->queries->execute(Hierarchical::ancestorsOf(Query::objects(), $current)->orderBy('depth'), $context->actor)->items;
        }

        return Subject::system(self::NAME, ['ancestors' => $ancestors, 'current' => $current], $current);
    }
}
