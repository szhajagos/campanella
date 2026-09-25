<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;

interface Command
{
    public function name(): string;

    public function description(): string;

    /**
     * @param list<string> $args
     * @return int Kilépési kód (0 = siker).
     */
    public function run(Container $container, array $args, Output $output): int;
}
