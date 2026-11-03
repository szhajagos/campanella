<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\Cli\Command;
use Campanella\Cli\Output;
use Campanella\Core\Container;

/** A command of a module (a test): `hirlevel:hello`. */
final class Parancs implements Command
{
    public function name(): string
    {
        return 'hirlevel:hello';
    }

    public function description(): string
    {
        return 'hirlevel.command';
    }

    public function run(Container $container, array $args, Output $output): int
    {
        $output->line('Szia a hírlevélből');

        return 0;
    }
}
