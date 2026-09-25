<?php

declare(strict_types=1);

namespace Campanella\Query;

final readonly class CompiledQuery
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public string $sql,
        public array $params,
    ) {
    }
}
