<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Core\Container;
use Campanella\Core\Version;
use Campanella\Database\Installer;
use Campanella\Model\BlueprintRegistry;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;

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
        return 'Verzió, capability-k, Blueprintek és objektumszám';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $output->line('Campanella ' . Version::CAMPANELLA . ' (PHP ' . PHP_VERSION . ')');

        $output->line();
        $output->line('Capability-k:');
        foreach ($container->get(CapabilityRegistry::class)->all() as $definition) {
            $output->line(sprintf(
                '  %-12s mezők: %-26s tábla: %s',
                $definition->name,
                implode(', ', array_keys($definition->fields)),
                $definition->hasTable() ? $definition->tableName() : '– (data)',
            ));
        }

        $output->line();
        $output->line('Blueprintek:');
        foreach ($container->get(BlueprintRegistry::class)->all() as $blueprint) {
            $output->line(sprintf('  %-12s %s', $blueprint->name, implode(' + ', array_keys($blueprint->capabilities))));
        }

        $output->line();
        if (!$container->get(Installer::class)->isInstalled()) {
            $output->line('Az adatbázis még nincs telepítve (php bin/campanella install).');

            return 0;
        }
        $queries = $container->get(QueryEngine::class);
        $output->line(sprintf(
            'Objektumok: %d összesen, ebből %d nyilvánosan látható.',
            $queries->count(Query::objects(), Actor::system()),
            $queries->count(Query::objects(), Actor::anonymous()),
        ));

        return 0;
    }
}
