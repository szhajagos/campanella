<?php

declare(strict_types=1);

namespace Campanella\Query\Condition;

/** Az objektum rendelkezik (vagy nem rendelkezik) a capability-vel. */
final readonly class HasCapability implements Condition
{
    public function __construct(
        public string $capability,
        public bool $negated = false,
    ) {
    }
}
