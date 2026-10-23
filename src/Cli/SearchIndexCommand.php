<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Core\Container;
use Campanella\Database\Installer;
use Campanella\I18n\Translator;
use Campanella\Search\SearchIndexer;

/**
 * `php bin/campanella search:index [--all]` (since 0.2.7): puts the objects that
 * are not in the search's index yet into it (e.g. after the upgrade to 0.2.7);
 * `--all`: every object again.
 */
final class SearchIndexCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'search:index';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.search_index.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $t = $container->get(Translator::class);
        $installer = $container->get(Installer::class);
        if (!$installer->isInstalled() || $installer->needsUpgrade()) {
            $output->error($t->translate('cli.search_index.needs_upgrade'));

            return 1;
        }
        [$done] = $container->get(SearchIndexer::class)->build(INF, Args::parse($args)->flag('all'));
        $output->success($t->translate('cli.search_index.done', ['count' => $done]));

        return 0;
    }
}
