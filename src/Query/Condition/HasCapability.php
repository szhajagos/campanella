<?php

declare(strict_types=1);

namespace Campanella\Query\Condition;

/** The object has (or does not have) the capability. */
final readonly class HasCapability implements Condition
{
    public function __construct(
        public string $capability,
        public bool $negated = false,
    ) {
    }
}
