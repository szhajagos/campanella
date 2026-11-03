<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\Capability\AsCapability;
use Campanella\Capability\Capability;

/** A capability of a module (a test). */
#[AsCapability('hirlevel_feliratkozhato', label: 'hirlevel.capability')]
final class Feliratkozhato extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [];
    }
}
