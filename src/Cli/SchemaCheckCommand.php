<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Installer;
use Campanella\Database\Schema\DifferenceKind;
use Campanella\I18n\Translator;

/**
 * `php bin/campanella schema:check [--sql]`: compares the table definitions
 * with the database and lists the differences. With `--sql` it prints the
 * statements that apply the additive ones (missing tables, columns, indexes);
 * statements that would lose data (dropping a column or an index) are printed
 * as comments only. Nothing is changed. Exits with 1 if there is a difference
 * (a table no definition has is only reported).
 */
final class SchemaCheckCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'schema:check';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.schema_check.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $installer = $container->get(Installer::class);
        if (!$installer->isInstalled()) {
            $output->error($t->translate('admin.system.schema_missing'));

            return 1;
        }
        $differences = $installer->differences();

        if (Args::parse($args)->flag('sql')) {
            $output->line('-- ' . $t->translate('cli.schema_check.sql_note'));
            foreach ($differences as $difference) {
                if ($difference->sql === null) {
                    continue;
                }
                $output->line($difference->additive
                    ? $difference->sql . ';'
                    : '-- ' . $t->translate('cli.schema_check.not_additive') . "\n-- " . str_replace("\n", "\n-- ", $difference->sql) . ';');
            }

            return 0;
        }

        if ($differences === []) {
            $output->success($t->translate('cli.schema_check.matches'));

            return 0;
        }
        $output->line($t->translate('cli.schema_check.found', ['count' => count($differences)]));
        $real = 0;
        foreach ($differences as $difference) {
            $label = match (true) {
                $difference->kind === DifferenceKind::ExtraTable => 'cli.schema_check.info',
                $difference->additive => 'cli.schema_check.additive',
                default => 'cli.schema_check.migration',
            };
            if ($difference->kind !== DifferenceKind::ExtraTable) {
                $real++;
            }
            $output->line('  ' . mb_str_pad('[' . $t->translate($label) . ']', 20) . $difference->message()->translate($t));
        }
        $output->line($t->translate('cli.schema_check.hint'));

        return $real > 0 ? 1 : 0;
    }
}
