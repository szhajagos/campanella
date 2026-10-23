<?php

declare(strict_types=1);

namespace Campanella\Search;

use Campanella\Model\CampanellaObject;

/**
 * A page of a search's results (since 0.2.7): the objects, best first, each with
 * an excerpt of its text around the words searched for.
 */
final readonly class SearchResult
{
    /**
     * @param list<string> $words The words searched for (Search::words())
     * @param list<array{object: CampanellaObject, excerpt: list<array{text: string, mark: bool}>}> $items
     * @param int $total How many objects matched (at most Search::MAX_RESULTS)
     */
    public function __construct(
        public string $query,
        public array $words,
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
