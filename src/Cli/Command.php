<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;

interface Command
{
    public function name(): string;

    /** A message key (see lang/), translated for the help screen. */
    public function description(): string;

    /**
     * @param list<string> $args
     * @return int Exit code (0 = success).
     */
    public function run(Container $container, array $args, Output $output): int;
}
