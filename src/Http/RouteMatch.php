<?php

declare(strict_types=1);

namespace Campanella\Http;

final readonly class RouteMatch
{
    /**
     * @param string $handler A controller neve (pl. 'query', 'object').
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $handler,
        public array $params = [],
    ) {
    }
}
