<?php

declare(strict_types=1);

namespace Campanella\Http;

final readonly class RouteMatch
{
    /**
     * @param string $handler The controller name (e.g. 'query', 'object').
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $handler,
        public array $params = [],
    ) {
    }
}
