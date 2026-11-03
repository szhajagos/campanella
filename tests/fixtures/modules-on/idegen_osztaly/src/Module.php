<?php

declare(strict_types=1);

namespace IdegenOsztaly;

use Campanella\Module\ModuleContext;

/** A test module (0.4.6): it registers a class not of its own. */
final class Module implements \Campanella\Module\Module
{
    public function register(ModuleContext $context): void
    {
        $context->capability(\Campanella\Capability\Titled::class);
    }
}
