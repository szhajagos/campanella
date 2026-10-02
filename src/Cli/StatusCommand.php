<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Core\Container;
use Campanella\Core\Version;
use Campanella\Database\Installer;
use Campanella\Database\Schema\CoreSchema;
use Campanella\I18n\Translator;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\Field;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\System\CheckStatus;
use Campanella\System\SystemCheck;

/**
 * `php bin/campanella status`: versions, the system check, capabilities,
 * Blueprints and object counts. Exits with 1 if a check reports an error
 * (e.g. a missing PHP extension, or the database is not installed).
 */
final class StatusCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'status';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.status.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $output->line('Campanella ' . Version::CAMPANELLA . ' (PHP ' . PHP_VERSION . ')');

        // The system check (the same as the admin's System page, without the web server's settings).
        $results = $container->get(SystemCheck::class)->run();
        $output->line();
        $output->line($t->translate('cli.status.checks'));
        $group = null;
        foreach ($results as $result) {
            if ($result->group !== $group) {
                $group = $result->group;
                $output->line('  ' . $t->translate($group));
            }
            $output->line('    '
                . mb_str_pad('[' . $t->translate('admin.system.status.' . $result->status->value) . ']', 17)
                . mb_str_pad($t->translate($result->label), 30)
                . $t->translate($result->value));
            if ($result->hint !== null && in_array($result->status, [CheckStatus::Warning, CheckStatus::Error], true)) {
                $output->line(str_repeat(' ', 21) . $result->hint->translate($t));
            }
        }
        $output->line($t->translate('cli.status.checks_note'));
        $exit = SystemCheck::worst($results) === CheckStatus::Error ? 1 : 0;

        $output->line();
        $output->line($t->translate('cli.status.capabilities'));
        foreach ($container->get(CapabilityRegistry::class)->all() as $definition) {
            // Multi-valued fields are marked with their limit: phones[3], tags[*].
            $fields = array_map(
                static fn (Field $f): string => $f->name . ($f->isMultiple() ? '[' . ($f->isUnlimited() ? '*' : $f->cardinality) . ']' : ''),
                array_values($definition->fields),
            );
            $tables = array_filter([
                $definition->hasTable() ? $definition->tableName() : null,
                $definition->valueTableFields() !== [] ? CoreSchema::FIELD_VALUES : null,
            ]);
            $output->line(sprintf(
                '  %-12s %s: %-26s %s: %s',
                $definition->name,
                $t->translate('cli.status.fields'),
                implode(', ', $fields),
                $t->translate('cli.status.table'),
                $tables === [] ? '– (data)' : implode(', ', $tables),
            ));
        }

        $output->line();
        $output->line($t->translate('cli.status.blueprints'));
        foreach ($container->get(BlueprintRegistry::class)->all() as $blueprint) {
            $output->line(sprintf('  %-12s %s', $blueprint->name, implode(' + ', array_keys($blueprint->capabilities))));
        }

        $output->line();
        $installer = $container->get(Installer::class);
        try {
            $installed = $installer->isInstalled();
        } catch (\Throwable) {
            // The database cannot be reached: already reported by the system check above.
            return $exit;
        }
        if (!$installed) {
            $output->line($t->translate('cli.status.not_installed'));

            return $exit;
        }
        if ($installer->needsUpgrade()) {
            $output->line($t->translate('cli.status.needs_upgrade', [
                'database' => (string) $installer->systemValue('schema_version'),
                'code' => Version::SCHEMA,
            ]));

            return $exit;
        }
        $queries = $container->get(QueryEngine::class);
        $output->line($t->translate('cli.status.objects', [
            'total' => $queries->count(Query::objects(), Actor::system()),
            'public' => $queries->count(Query::objects(), Actor::anonymous()),
        ]));

        return $exit;
    }
}
