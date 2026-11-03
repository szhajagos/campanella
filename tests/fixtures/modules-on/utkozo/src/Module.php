<?php

declare(strict_types=1);

namespace Utkozo;

use Campanella\Module\ModuleContext;

/** A test module (0.4.6): its capability has a core one's name. */
final class Module implements \Campanella\Module\Module
{
    public function register(ModuleContext $context): void
    {
        $context->capability(Cim::class);
    }
}
