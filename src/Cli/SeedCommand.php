<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Capability\Routable;
use Campanella\Capability\Titled;
use Campanella\Core\Config;
use Campanella\Core\Container;
use Campanella\Display\ComponentService;
use Campanella\Html\PlainText;
use Campanella\I18n\Translator;
use Campanella\Layout\LayoutSetup;
use Campanella\Lists\ListRegistry;
use Campanella\Lists\ListStore;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\BlueprintStore;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;
use Campanella\Settings\Settings;
use Campanella\Site\SiteSettings;
use Campanella\Support\Slugger;
use Campanella\Transfer\ModelImport;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Creates sample content, so there is something to look at:
 * `php bin/campanella seed [--locale=en]`.
 *
 * Since 0.3.7 the content is in the site's language (`locale` in config/app.php), or the
 * one asked for: `resources/seed/<locale>.json` (SeedContent), English for a language with
 * no file of its own.
 *
 * Can be run repeatedly: whatever it already finds by path is not created
 * again. So after an upgrade the new samples (e.g. the 0.0.2 categories)
 * are added next to the existing content.
 *
 * Since 0.3.0 also a content model made as in the admin: an events Blueprint with own
 * fields, a list of the upcoming events with visitors' filters, and a few events. Only if
 * the site has no Blueprint and no list of these names: an existing one (e.g. one's own)
 * is never touched.
 */
final class SeedCommand implements Command
{
    private ObjectService $service;
    private QueryEngine $queries;
    private Output $output;
    private Translator $t;
    private Actor $actor;
    private SeedContent $content;

    /** @param string|null $directory The sample content's folder (default: the core's resources/seed) */
    public function __construct(private readonly ?string $directory = null)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'seed';
    }

    #[\Override]
    public function description(): string
    {
        return 'cli.seed.description';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $this->t = $container->get(Translator::class);
        $locale = Args::parse($args)->option('locale') ?? (string) $container->get(Config::class)->get('locale', 'hu');
        try {
            $this->content = SeedContent::forLocale($locale, $this->directory);
        } catch (\InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return 1;
        }
        $this->actor = Actor::system();
        $this->service = $container->get(ObjectService::class);
        $this->queries = $container->get(QueryEngine::class);
        $this->output = $output;
        $repository = $container->get(ObjectRepository::class);
        $utc = new DateTimeZone('UTC');
        $c = $this->content;
        $output->line($this->t->translate('cli.seed.language', ['language' => $c->language, 'file' => 'resources/seed/' . $c->locale . '.json']));

        $categories = [];
        foreach ($c->categories as $key => $category) {
            $categories[$key] = $this->ensure('category', $category['title'], ['body' => $category['body']], new DateTimeImmutable('-10 days', $utc));
        }

        foreach ($c->articles as $sample) {
            // Articles and pages have a formatted (HTML) body (Blueprint 'defaults').
            $article = $this->ensure('article', $sample['title'], ['lead' => $sample['lead'], 'body' => PlainText::toHtml($sample['body'])], new DateTimeImmutable($sample['when'], $utc));
            // Categories are only added if it has none yet (manual settings are not overwritten).
            if ($article->relatedIds('categories') === []) {
                $article->setRelated('categories', array_map(static fn (string $k) => $categories[$k], $sample['categories']));
                $repository->save($article);
                $this->output->success('         ' . $this->t->translate('cli.seed.categories', [
                    'path' => (string) $article->get('path'),
                    'categories' => implode(', ', $sample['categories']),
                ]));
            }
        }

        $this->ensure('article', $c->draft['title'], ['body' => PlainText::toHtml($c->draft['body'])]);
        $about = $this->ensure('page', $c->about['title'], ['body' => PlainText::toHtml($c->about['body'])], new DateTimeImmutable('-10 days', $utc));

        $events = $this->events($container);

        $this->mainMenu($categories, $about, $events, $container->get(Settings::class));
        $this->frontPage($container, $events, new DateTimeImmutable('-10 days', $utc));
        $this->colorModeSwitch($container);

        $output->line();
        $output->line($this->t->translate('cli.seed.done', [
            'total' => $this->queries->count(Query::objects(), $this->actor),
            'public' => $this->queries->count(Query::objects(), Actor::anonymous()),
        ]));

        return 0;
    }

    /**
     * The main menu (machine name `main`) with the links the site had built in, the
     * categories under their link as a dropdown. Only if there is no main menu yet:
     * a menu edited in the admin is never touched.
     *
     * @param array<string, CampanellaObject> $categories
     * @param string|null $events The events list's path, if the site has it
     */
    private function mainMenu(array $categories, CampanellaObject $about, ?string $events, Settings $settings): void
    {
        $exists = $this->queries->first(Query::objects()->blueprint('menu')->where('machine_name', '=', 'main'), $this->actor);
        // The main menu the installation made with the built-in links (since 0.3.4,
        // LayoutSetup) is filled as a new one: its links are replaced.
        $starter = $exists !== null && $settings->get(LayoutSetup::STARTER_MENU) === (string) $exists->id();
        if ($exists !== null && !$starter) {
            return;
        }
        $texts = $this->content->menu;
        if ($starter) {
            $menu = $exists;
            foreach ($this->queries->execute(Query::objects()->blueprint('menu_item')->whereRelated('menu', $menu)->limit(100), $this->actor) as $old) {
                $this->service->delete($this->actor, $old);
            }
            $settings->set([LayoutSetup::STARTER_MENU => null]);
        } else {
            $menu = $this->service->create($this->actor, 'menu', ['title' => $texts['title'], 'machine_name' => 'main']);
        }
        $count = 0;
        $item = function (string $title, int $weight, string $url = '', ?CampanellaObject $target = null, ?CampanellaObject $parent = null) use ($menu, &$count): CampanellaObject {
            $count++;

            return $this->service->create($this->actor, 'menu_item', ['title' => $title, 'url' => $url, 'weight' => $weight], false, [
                'menu' => [(int) $menu->id()],
                'target' => $target === null ? [] : [(int) $target->id()],
                'parent' => $parent === null ? [] : [(int) $parent->id()],
            ]);
        };
        $item($texts['home'], 0, '/');
        $item($texts['news']['title'], 10, $texts['news']['url']);
        $list = $item($texts['categories']['title'], 20, $texts['categories']['url']);
        $weight = 0;
        foreach ($categories as $category) {
            $item($category->as(Titled::class)->title(), $weight, '', $category, $list);
            $weight += 10;
        }
        if ($events !== null) {
            $item($texts['events'], 15, $events);
        }
        $item($about->as(Titled::class)->title(), 30, '', $about);

        $this->output->success($this->t->translate('cli.seed.menu', ['count' => $count]));
    }

    /**
     * The colour mode switch (since 0.3.6): a component in the navigation, at its end, so a
     * visitor can choose light or dark; an administrator can move or delete it (Regions).
     * Only once, and only where the layouts are on (the header is made of components).
     */
    private function colorModeSwitch(Container $container): void
    {
        $components = $container->get(ComponentService::class);
        $switch = $this->content->colorModeSwitch;
        if (!$components->isAvailable() || $container->get(Settings::class)->get('layout.enabled') !== '1'
            || $components->find($switch['machine_name'], $this->actor) !== null) {
            return;
        }
        $component = $components->create($this->actor, ['title' => $switch['title'], 'machine_name' => $switch['machine_name']], 'system:visitor', 'color_mode_switch', ['show_labels' => '0']);
        $components->place($this->actor, $component, 'navigation', null, $components->nextWeight('navigation', $this->actor));
        $this->output->success($this->t->translate('cli.seed.color_mode_switch'));
    }

    /**
     * The sample front page (since 0.3.5): a page assembled from components, in the layout with
     * a sidebar: its text, the latest news as cards, the upcoming events, and a contact box
     * beside them; then made the front page. Only once, and only where the site has no front
     * page of its own and components are there (a page made before stays as it is).
     *
     * @param string|null $events The events list's path, if the site has it
     */
    private function frontPage(Container $container, ?string $events, DateTimeImmutable $at): void
    {
        $c = $this->content;
        $site = $container->get(SiteSettings::class);
        $components = $container->get(ComponentService::class);
        $page = $container->get(BlueprintRegistry::class)->find('page');
        if ($site->frontPage() !== null || !$components->isAvailable() || $page === null || !isset($page->capabilities['composed'])
            || $this->queries->first(Query::objects()->where('path', '=', self::pathOf($c->frontPage['title'])), $this->actor) !== null) {
            return;
        }
        $lists = $container->get(ListStore::class);
        if (!isset($lists->load()[$c->newsListName])) {
            $lists->save($c->newsListName, $c->newsList);
        }
        // The lists saved in this run (this one, the events'), so the components can show them.
        $container->get(ListRegistry::class)->loadStored($lists->load());
        $front = $this->ensure('page', $c->frontPage['title'], [
            'body' => PlainText::toHtml($c->frontPage['body']),
            'page_layout' => 'sidebar',
        ], $at);
        $contact = $this->service->create($this->actor, 'snippet', [
            'title' => $c->contact['title'],
            'body' => PlainText::toHtml($c->contact['body']),
        ]);
        $own = [
            [$c->components['news'], 'list:' . $c->newsListName, 'cards', ['count' => '3', 'columns' => '3', 'show_more' => '0', 'show_title' => '1'], 'content'],
        ];
        if ($events !== null) {
            $own[] = [$c->components['events'], 'list:' . $c->eventListName, 'cards', ['count' => '3', 'columns' => '1', 'show_more' => '1', 'show_title' => '1'], 'content'];
        }
        $own[] = [$c->components['contact'], 'object:' . $contact->id(), 'info_box', [], 'sidebar'];
        foreach ($own as $weight => [$names, $source, $display, $settings, $region]) {
            if ($components->find($names['machine_name'], $this->actor) !== null) {
                continue;
            }
            $component = $components->create($this->actor, ['title' => $names['title'], 'machine_name' => $names['machine_name']], $source, $display, $settings, $front);
            $components->place($this->actor, $component, $region, null, $weight);
        }
        $container->get(Settings::class)->set([SiteSettings::PREFIX . 'front_page' => (string) $front->id()]);
        $this->output->success($this->t->translate('cli.seed.front_page', ['path' => (string) $front->get('path')]));
    }

    /**
     * The events (since 0.3.0): their Blueprint and list, imported as System → Content model
     * imports them (so they can be edited in the admin), then a few events from a week ago to
     * the next two months.
     *
     * @return string|null The list's path, or null if the site has another list there or none
     */
    private function events(Container $container): ?string
    {
        $c = $this->content;
        $name = $c->eventBlueprintName;
        $blueprints = $container->get(BlueprintRegistry::class);
        $lists = $container->get(ListStore::class);
        if ($blueprints->find($name) === null && !isset($lists->load()[$c->eventListName])) {
            $import = $container->get(ModelImport::class);
            $plan = $import->plan(['blueprints' => [$name => $c->eventBlueprint], 'lists' => [$c->eventListName => $c->eventList], 'campanella' => '', 'exported_at' => '']);
            if ($plan->hasErrors()) {
                return null; // e.g. the path is taken
            }
            $import->apply($plan);
            // Defined now, so its objects can be made in this run too.
            $blueprints->loadStored([$name => $container->get(BlueprintStore::class)->load()[$name]]);
            $this->output->success($this->t->translate('cli.seed.model', ['blueprint' => $name, 'list' => (string) ($c->eventList['path'] ?? '')]));
        }
        $blueprint = $blueprints->find($name);
        $list = $lists->load()[$c->eventListName] ?? null;
        $fields = $c->eventFields;
        if ($blueprint === null || !$blueprints->isEditable($name) || array_diff($fields, array_keys($blueprint->fields)) !== []) {
            return null; // not ours
        }

        $timezone = new DateTimeZone((string) $container->get(Config::class)->get('timezone', 'UTC'));
        $today = new DateTimeImmutable('today', $timezone);
        $published = new DateTimeImmutable('-7 days', new DateTimeZone('UTC'));
        foreach ($c->eventSamples as $sample) {
            $this->ensure($name, $sample['title'], [
                $fields['start'] => $today->modify(sprintf('%+d days %s', $sample['days'], $sample['time'])),
                $fields['kind'] => $sample['kind'],
                $fields['place'] => $sample['place'],
                $fields['price'] => $sample['price'],
                'body' => PlainText::toHtml($sample['body']),
            ], $published);
        }

        return is_array($list) && is_string($list['path'] ?? null) ? $list['path'] : null;
    }

    /** The path an object of this title gets. */
    private static function pathOf(string $title): string
    {
        return Routable::normalize('/' . Slugger::slugify($title));
    }

    /**
     * Looks up the object by the path derived from its title; creates it if it does not exist.
     *
     * @param array<string, mixed> $values
     * @param DateTimeImmutable|null $publishAt Publication time; if null, it stays a draft.
     */
    private function ensure(string $blueprint, string $title, array $values, ?DateTimeImmutable $publishAt = null): CampanellaObject
    {
        $existing = $this->queries->first(Query::objects()->where('path', '=', self::pathOf($title)), $this->actor);
        if ($existing !== null) {
            return $existing;
        }

        $object = $this->service->create($this->actor, $blueprint, ['title' => $title] + $values);
        if ($publishAt !== null) {
            $this->service->publish($this->actor, $object, $publishAt);
        }
        $this->output->success(sprintf(
            '%-8s #%d  %s  %s%s',
            $blueprint,
            $object->id(),
            $object->get('path'),
            $object->as(Titled::class)->title(),
            $publishAt === null ? '  (' . $this->t->translate('cli.seed.draft') . ')' : '',
        ));

        return $object;
    }
}
