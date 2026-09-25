<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Attribute;

/**
 * Egy osztályt capability-ként deklarál.
 *
 *     #[AsCapability('routable', requires: [Titled::class])]
 *     final class Routable extends Capability { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsCapability
{
    /**
     * @param string $name Rendszerszinten egyedi, kisbetűs név (ez kerül az adatbázisba).
     * @param list<class-string<Capability>> $requires Mely capability-k nélkül nem működik.
     */
    public function __construct(
        public string $name,
        public array $requires = [],
        public string $label = '',
    ) {
    }
}
