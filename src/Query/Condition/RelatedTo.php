<?php

declare(strict_types=1);

namespace Campanella\Query\Condition;

/**
 * Whether the object has (or does not have) the given relation to any of
 * the specified target objects. Empty target list: to any target.
 *
 *     new RelatedTo('categories', [12])      // belongs to category 12
 *     new RelatedTo('categories', [])        // has at least one category
 *
 * `$subtree` (since 0.0.7): a tree path (`/1/5/`); the target may be anywhere in
 * that subtree (Hierarchical::relatedWithin(): the articles of a category and
 * of its subcategories).
 */
final readonly class RelatedTo implements Condition
{
    /** @param list<int> $targets */
    public function __construct(
        public string $relation,
        public array $targets = [],
        public bool $negated = false,
        public ?string $subtree = null,
    ) {
        if ($subtree !== null && preg_match('#^/(\d+/)+$#', $subtree) !== 1) {
            throw new \InvalidArgumentException("Invalid tree path: {$subtree}");
        }
    }
}
