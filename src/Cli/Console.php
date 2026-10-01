<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Kernel;
use Campanella\Core\Version;
use Campanella\I18n\Translator;
use Campanella\Model\ValidationException;

/** The bin/campanella command-line tool. */
final class Console
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(private readonly Kernel $kernel, private readonly Output $output = new Output())
    {
        $commands = [
            new InstallCommand(),
            new SeedCommand(),
            new StatusCommand(),
            new UserCreateCommand(),
            new UserPasswordCommand(),
            new UserListCommand(),
        ];
        foreach ($commands as $command) {
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
        } catch (ValidationException $e) {
            foreach ($e->messages($this->translator()) as $field => $message) {
                $this->output->error("{$field}: {$message}");
            }

            return 1;
        } catch (\Throwable $e) {
            $this->output->error($e->getMessage());

            return 1;
        }
    }

    private function help(): void
    {
        $this->output->line('Campanella ' . Version::CAMPANELLA);
        $this->output->line();
        $this->output->line($this->translator()->translate('cli.usage'));
        $this->output->line();
        foreach ($this->commands as $command) {
            $this->output->line(sprintf('  %-14s %s', $command->name(), $this->translator()->translate($command->description())));
        }
    }

    private function translator(): Translator
    {
        return $this->kernel->container()->get(Translator::class);
    }
}
