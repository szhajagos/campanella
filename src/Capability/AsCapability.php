<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Attribute;

/**
 * Declares a class as a capability.
 *
 *     #[AsCapability('routable', requires: [Titled::class])]
 *     final class Routable extends Capability { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsCapability
{
    /**
     * @param string $name System-wide unique, lowercase name (this is stored in the database).
     * @param list<class-string<Capability>> $requires Capabilities it cannot work without.
     */
    public function __construct(
        public string $name,
        public array $requires = [],
        public string $label = '',
    ) {
    }
}
