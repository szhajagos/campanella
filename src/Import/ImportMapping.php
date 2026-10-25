<?php

declare(strict_types=1);

namespace Campanella\Import;

/**
 * One line of an importer's mapping (since 0.3.1): a source (a CSV column's heading, or
 * a dotted path in a JSON item) to a target (a field or relation of the Blueprint);
 * several values are split by the separator.
 */
final readonly class ImportMapping
{
    public function __construct(
        public string $source,
        public string $target,
        public string $separator = '|',
    ) {
    }

    /** @return array{source: string, target: string, separator: string} */
    public function toArray(): array
    {
        return ['source' => $this->source, 'target' => $this->target, 'separator' => $this->separator];
    }
}
