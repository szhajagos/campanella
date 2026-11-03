<?php

declare(strict_types=1);

namespace Figyelmeztet;

use Campanella\Module\ModuleContext;

/** A test module (0.4.6): its register() raises a warning. */
final class Module implements \Campanella\Module\Module
{
    public function register(ModuleContext $context): void
    {
        trigger_error("Vigyázat", E_USER_WARNING);
    }
}
