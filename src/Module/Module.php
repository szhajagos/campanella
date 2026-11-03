<?php

declare(strict_types=1);

namespace Campanella\Module;

/**
 * A module's entry point (since 0.4.6): the `Module` class of its namespace
 * (`modules/<name>/src/Module.php`). register() says what the module brings; it runs on
 * every request while the module is on, so it only registers, it does nothing else (no
 * database, no output). What it may register is ModuleContext's.
 */
interface Module
{
    public function register(ModuleContext $context): void;
}
