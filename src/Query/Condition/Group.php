<?php

declare(strict_types=1);

namespace Campanella\Query\Condition;

/** Feltételek ÉS / VAGY kapcsolatban. Egymásba ágyazható. */
final readonly class Group implements Condition
{
    /** @param list<Condition> $conditions */
    public function __construct(
        public bool $any,
        public array $conditions,
    ) {
    }

    public static function all(Condition ...$conditions): self
    {
        return new self(false, array_values($conditions));
    }

    public static function any(Condition ...$conditions): self
    {
        return new self(true, array_values($conditions));
    }

    public function with(Condition $condition): self
    {
        return new self($this->any, [...$this->conditions, $condition]);
    }
}
