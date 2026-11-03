<?php

declare(strict_types=1);

namespace Utkozo;

use Campanella\Capability\AsCapability;
use Campanella\Capability\Capability;

/** A capability with a core one's name (a test). */
#[AsCapability('titled')]
final class Cim extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [];
    }
}
