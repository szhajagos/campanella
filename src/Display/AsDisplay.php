<?php

declare(strict_types=1);

namespace Campanella\Display;

use Attribute;
use Campanella\Capability\Capability;

/**
 * Declares a class as a display (since 0.3.3), as AsCapability declares a capability:
 *
 *     #[AsDisplay('download', requires: [Downloadable::class], label: 'display.download')]
 *     final class DownloadDisplay extends Display { ... }
 *
 * What it can show, one of three:
 *  - an object that has every capability in `requires`;
 *  - a list (`many: true`): its objects;
 *  - a system source it names (`sources: ['visitor']`).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsDisplay
{
    /**
     * @param string $name System-wide unique, lowercase name (it is stored in components)
     * @param list<class-string<Capability>> $requires For an object: the capabilities it must have
     * @param bool $many For a list of objects
     * @param list<string> $sources For these system sources (their names)
     * @param string $label Its name in the admin (a message key or text)
     */
    public function __construct(
        public string $name,
        public array $requires = [],
        public bool $many = false,
        public array $sources = [],
        public string $label = '',
    ) {
    }
}
