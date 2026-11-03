<?php

declare(strict_types=1);

namespace HibasRegisztracio;

use Campanella\Module\ModuleContext;

/** A test module (0.4.6): its register() throws. */
final class Module implements \Campanella\Module\Module
{
    public function register(ModuleContext $context): void
    {
        throw new \RuntimeException("Nem sikerült.");
    }
}
