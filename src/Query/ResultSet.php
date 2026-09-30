<?php

declare(strict_types=1);

namespace Campanella\Query;

use ArrayIterator;
use Campanella\Model\CampanellaObject;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The result of a Query. Not yet presentation: the same result can be
 * the source of HTML, JSON, RSS or CSV.
 *
 * @implements IteratorAggregate<int, CampanellaObject>
 */
final readonly class ResultSet implements IteratorAggregate, Countable
{
    /**
     * @param list<CampanellaObject> $items
     * @param int|null $total The total number of matches (if requested), for pagination.
     */
    public function __construct(
        public array $items,
        public ?int $total = null,
        public ?int $limit = null,
        public int $offset = 0,
    ) {
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    #[\Override]
    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function first(): ?CampanellaObject
    {
        return $this->items[0] ?? null;
    }

    public function currentPage(): int
    {
        return $this->limit === null ? 1 : intdiv($this->offset, $this->limit) + 1;
    }

    public function pageCount(): int
    {
        if ($this->limit === null || $this->total === null) {
            return 1;
        }

        return max(1, (int) ceil($this->total / $this->limit));
    }
}
