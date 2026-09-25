<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Kernel;
use Campanella\Core\Version;

/** A bin/campanella parancssori eszköz. */
final class Console
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(private readonly Kernel $kernel, private readonly Output $output = new Output())
    {
        foreach ([new InstallCommand(), new SeedCommand(), new StatusCommand()] as $command) {
            $this->commands[$command->name()] = $command;
        }
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'help';
        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            $this->help();

            return $name === 'help' ? 0 : 1;
        }

        try {
            return $command->run($this->kernel->container(), array_slice($argv, 2), $this->output);
        } catch (\Throwable $e) {
            $this->output->error($e->getMessage());

            return 1;
        }
    }

    private function help(): void
    {
        $this->output->line('Campanella ' . Version::CAMPANELLA);
        $this->output->line();
        $this->output->line('Használat: php bin/campanella <parancs>');
        $this->output->line();
        foreach ($this->commands as $command) {
            $this->output->line(sprintf('  %-10s %s', $command->name(), $command->description()));
        }
    }
}
