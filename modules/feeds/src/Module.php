<?php

declare(strict_types=1);

namespace CampanellaFeeds;

use Campanella\Core\Container;
use Campanella\Database\Migration\MigrationContext;
use Campanella\Import\Importer;
use Campanella\Module\ModuleContext;
use Campanella\Module\Uninstallable;
use Campanella\Net\SafeFetcher;

/**
 * The feeds module (since Campanella 0.4.9), the first of Campanella's own: web addresses whose
 * file an importer of the site (System → Importers) imports, on a schedule (its task in
 * cron:run), fetched with the core's SafeFetcher. Its sources are in its own table.
 */
final class Module implements \Campanella\Module\Module, Uninstallable
{
    public function register(ModuleContext $context): void
    {
        $context->migration(CreateSources::class)
            ->service(SourceStore::class, static fn (Container $c): SourceStore => new SourceStore($c->get(\Campanella\Database\Connection::class)))
            ->service(Feeds::class, static fn (Container $c): Feeds => new Feeds(
                $c->get(SourceStore::class),
                $c->get(SafeFetcher::class),
                $c->get(Importer::class),
            ))
            ->service(Admin::class, static fn (Container $c): Admin => new Admin(
                $c->get(SourceStore::class),
                $c->get(Feeds::class),
                $c->get(Importer::class),
                $c->get(SafeFetcher::class),
                $c->get(\Campanella\Schedule\Scheduler::class),
                $c->get(\Campanella\I18n\Translator::class),
                $c->get(\Campanella\Model\BlueprintRegistry::class),
                $c->get(\Campanella\Http\Flash::class),
                $c->get(\Campanella\Admin\AdminAccess::class),
            ))
            ->scheduled(FetchTask::class)
            ->adminPage(Admin::class, 'feeds.title', 'system');
    }

    public function uninstall(MigrationContext $m): void
    {
        $m->sql('DROP TABLE IF EXISTS {feeds_sources}');
    }
}
