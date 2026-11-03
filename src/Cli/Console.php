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
            new HtmlSanitizeCommand(),
            new SchemaCheckCommand(),
            new MigrateCommand(),
            new DbBackupCommand(),
            new ModelExportCommand(),
            new ModelImportCommand(),
            new MediaVariantsCommand(),
            new SearchIndexCommand(),
            new ImportRunCommand(),
            new ImportUndoCommand(),
            new SubmissionsPruneCommand(),
            new ModuleListCommand(),
            new ModuleEnableCommand(),
            new ModuleDisableCommand(),
            new ModuleUninstallCommand(),
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
        // The core's module:* commands run with no module loaded, so a module cannot stop
        // them (since 0.4.6); the other commands with the modules' own too.
        $container = $command !== null && str_starts_with($name, 'module:')
            ? (new Kernel($this->kernel->rootDir(), \Campanella\Core\Config::merge($this->kernel->overrides(), ['modules' => ['load' => false]])))->container()
            : null;
        if ($command === null) {
            $command = $this->moduleCommands()[$name] ?? null;
        }

        if ($command === null) {
            $this->help();

            return $name === 'help' ? 0 : 1;
        }

        try {
            return $command->run($container ?? $this->kernel->container(), array_slice($argv, 2), $this->output);
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
        foreach ([...$this->commands, ...$this->moduleCommands()] as $command) {
            $this->output->line(sprintf('  %-14s %s', $command->name(), $this->translator()->translate($command->description())));
        }
    }

    /**
     * The commands of the modules that are on (since 0.4.6), each `<module>:…`; never one of
     * the core's names.
     *
     * @return array<string, Command>
     */
    private function moduleCommands(): array
    {
        $commands = [];
        try {
            foreach ($this->kernel->container()->get(\Campanella\Module\ModuleLoader::class)->all('commands') as $command) {
                if ($command instanceof Command && !isset($this->commands[$command->name()])) {
                    $commands[$command->name()] ??= $command;
                }
            }
        } catch (\Throwable) {
            // No modules where they cannot be read (e.g. no database yet).
        }

        return $commands;
    }

    private function translator(): Translator
    {
        return $this->kernel->container()->get(Translator::class);
    }
}
