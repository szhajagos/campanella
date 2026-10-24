<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Capability\Routable;
use Campanella\Capability\Titled;
use Campanella\Core\Config;
use Campanella\Core\Container;
use Campanella\Html\PlainText;
use Campanella\I18n\Translator;
use Campanella\Lists\ListStore;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\BlueprintStore;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;
use Campanella\Support\Slugger;
use Campanella\Transfer\ModelImport;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Creates sample content, so there is something to look at.
 *
 * Can be run repeatedly: whatever it already finds by path is not created
 * again. So after an upgrade the new samples (e.g. the 0.0.2 categories)
 * are added next to the existing content.
 *
 * Since 0.3.0 also a content model made as in the admin: the Blueprint `esemeny`
 * (Esemény) with own fields, a list of the upcoming events at `/esemenyek` with
 * visitors' filters, and a few events. Only if the site has no Blueprint and no list
 * of these names: an existing one (e.g. one's own) is never touched.
 */
final class SeedCommand implements Command
{
    /** The sample events' Blueprint and list (since 0.3.0). */
    private const string EVENT = 'esemeny';
    private const string EVENTS = 'esemenyek';

    /** The Blueprint `esemeny`, in the stored form of a Blueprint made in the admin. */
    private const array EVENT_BLUEPRINT = [
        'version' => 1,
        'label' => 'Esemény',
        'capabilities' => ['titled', 'textual', 'routable', 'publishable'],
        'fields' => [
            ['name' => 'kezdes', 'type' => 'datetime', 'label' => 'Kezdés', 'queryable' => true, 'required' => true],
            ['name' => 'fajta', 'type' => 'choice', 'label' => 'Fajta', 'queryable' => true, 'required' => true, 'choices' => [
                ['key' => 'eloadas', 'label' => 'Előadás'],
                ['key' => 'koncert', 'label' => 'Koncert'],
                ['key' => 'kiallitas', 'label' => 'Kiállítás'],
                ['key' => 'seta', 'label' => 'Séta'],
                ['key' => 'gyerek', 'label' => 'Gyerekprogram'],
            ]],
            ['name' => 'helyszin', 'type' => 'string', 'label' => 'Helyszín', 'length' => 120],
            ['name' => 'belepo', 'type' => 'decimal', 'label' => 'Belépő (Ft)', 'queryable' => true, 'scale' => 0],
        ],
        'relations' => [],
        'admin_columns' => ['kezdes', 'fajta', 'belepo'],
    ];

    /** The list of the upcoming events, with the visitors' filters. */
    private const array EVENTS_LIST = [
        'version' => 1,
        'title' => 'Közelgő események',
        'blueprint' => 'esemeny',
        'conditions' => [['field' => 'kezdes', 'op' => '>=', 'relative' => 'now']],
        'sort' => [['field' => 'kezdes', 'dir' => 'asc']],
        'per_page' => 10,
        'display' => 'table',
        'columns' => ['kezdes', 'fajta', 'helyszin', 'belepo'],
        'path' => '/esemenyek',
        'filters' => ['fajta', 'kezdes', 'belepo'],
    ];

    /** The sample events: title, days from today, time, kind, place, price, text. */
    private const array EVENT_SAMPLES = [
        ['Őszi könyvbörze', -6, '10:00', 'kiallitas', 'Városi könyvtár, aula', '0', "Régi és új könyvek cseréje, vására.\n\nEz az esemény már elmúlt: a közelgő események listáján nem szerepel."],
        ['Neumann János és a számítógép', 4, '18:00', 'eloadas', 'Városi könyvtár, nagyterem', '0', "Előadás a tárolt programú számítógép elvéről és születéséről.\n\nA belépés díjtalan."],
        ['Barokk est: vonósnégyes', 9, '19:30', 'koncert', 'Református templom', '3500', "Bach, Vivaldi és Telemann művei.\n\nJegyek a helyszínen, az előadás előtt egy órával."],
        ['Történelmi séta a belvárosban', 16, '10:00', 'seta', 'Indulás a Fő térről', '1500', "Kétórás séta a belváros régi házai és udvarai között, idegenvezetővel."],
        ['Régi utcák: fotókiállítás', 23, '17:00', 'kiallitas', 'Galéria', '0', "Száz év fényképei a város utcáiról. A megnyitón a gyűjtők is jelen lesznek."],
        ['Jazz a pincében', 37, '20:00', 'koncert', 'Kultúrház, pinceklub', '2500', "Trió-est sztenderdekkel és saját szerzeményekkel."],
        ['Bábszínház gyerekeknek', 52, '15:00', 'gyerek', 'Kultúrház, kisterem', '1000', "Mesejáték bábokkal, négy éves kortól."],
    ];

    private ObjectService $service;
    private QueryEngine $queries;
    private Output $output;
    private Translator $t;
    private Actor $actor;

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
        $this->actor = Actor::system();
        $this->service = $container->get(ObjectService::class);
        $this->queries = $container->get(QueryEngine::class);
        $this->output = $output;
        $this->t = $container->get(Translator::class);
        $repository = $container->get(ObjectRepository::class);
        $utc = new DateTimeZone('UTC');

        $categories = [
            'tudomany' => $this->ensure('category', 'Tudomány', [
                'body' => 'Tudománytörténet és technika.',
            ], new DateTimeImmutable('-10 days', $utc)),
            'campanella' => $this->ensure('category', 'Campanella', [
                'body' => 'Hírek és írások a Campanella CMS-ről.',
            ], new DateTimeImmutable('-10 days', $utc)),
        ];

        $articles = [
            ['Neumann János', '-5 days', 'A számítógép-architektúra egyik atyja.', "Neumann János 1903-ban született Budapesten.\n\nNevéhez fűződik a tárolt programú számítógép elve, amelyet ma Neumann-architektúraként ismerünk.", ['tudomany']],
            ['Digitális Agora', '-3 days', 'Közösségi tér a hálózaton.', "A Digitális Agora a nyilvános vita és az együttműködés helye.\n\nEz a cikk a Campanella első példatartalmai közé tartozik.", ['campanella']],
            ['Megjelent a Campanella 0.0.1', '-1 day', 'Az első, minimális mag.', "Object, Blueprint, Capability, Query és Presentation: ennyiből áll az első változat.\n\nMinden további funkció erre a magra épül.", ['campanella']],
            ['Capability-k röviden', '-2 hours', 'Nem típusok, hanem képességek.', "Egy objektum attól cikk, hogy milyen képességei vannak: Titled, Textual, Routable, Publishable.\n\nA típus csak egy elnevezett capability-csomag, a Blueprint.", ['campanella', 'tudomany']],
            ['Időzített hír', '+2 days', 'Ez a hír még nem látható.', 'Jövőbeli publikálási dátummal mentve: a megadott időpontban magától jelenik meg.', ['tudomany']],
        ];

        foreach ($articles as [$title, $when, $lead, $body, $categoryKeys]) {
            // Articles and pages have a formatted (HTML) body (Blueprint 'defaults').
            $article = $this->ensure('article', $title, ['lead' => $lead, 'body' => PlainText::toHtml($body)], new DateTimeImmutable($when, $utc));
            // Categories are only added if it has none yet (manual settings are not overwritten).
            if ($article->relatedIds('categories') === []) {
                $article->setRelated('categories', array_map(static fn (string $k) => $categories[$k], $categoryKeys));
                $repository->save($article);
                $this->output->success('         ' . $this->t->translate('cli.seed.categories', [
                    'path' => (string) $article->get('path'),
                    'categories' => implode(', ', $categoryKeys),
                ]));
            }
        }

        $this->ensure('article', 'Piszkozat', [
            'body' => PlainText::toHtml('Ez egy publikálatlan cikk. Anonymous látogató nem látja, és a listákban sem jelenik meg.'),
        ]);
        $about = $this->ensure('page', 'Rólunk', [
            'body' => PlainText::toHtml("A Campanella egy capability-vezérelt CMS.\n\nEz az oldal egy „page” Blueprint alapján készült objektum."),
        ], new DateTimeImmutable('-10 days', $utc));

        $events = $this->events($container);

        $this->mainMenu($categories, $about, $events);

        $output->line();
        $output->line($this->t->translate('cli.seed.done', [
            'total' => $this->queries->count(Query::objects(), $this->actor),
            'public' => $this->queries->count(Query::objects(), Actor::anonymous()),
        ]));

        return 0;
    }

    /**
     * The main menu (machine name `main`) with the links the site had built in, the
     * categories under "Kategóriák" as a dropdown. Only if there is no main menu yet:
     * a menu edited in the admin is never touched.
     *
     * @param array<string, CampanellaObject> $categories
     * @param string|null $events The events list's path, if the site has it
     */
    private function mainMenu(array $categories, CampanellaObject $about, ?string $events): void
    {
        $exists = $this->queries->first(Query::objects()->blueprint('menu')->where('machine_name', '=', 'main'), $this->actor);
        if ($exists !== null) {
            return;
        }
        $menu = $this->service->create($this->actor, 'menu', ['title' => 'Főmenü', 'machine_name' => 'main']);
        $count = 0;
        $item = function (string $title, int $weight, string $url = '', ?CampanellaObject $target = null, ?CampanellaObject $parent = null) use ($menu, &$count): CampanellaObject {
            $count++;

            return $this->service->create($this->actor, 'menu_item', ['title' => $title, 'url' => $url, 'weight' => $weight], false, [
                'menu' => [(int) $menu->id()],
                'target' => $target === null ? [] : [(int) $target->id()],
                'parent' => $parent === null ? [] : [(int) $parent->id()],
            ]);
        };
        $item('Kezdőlap', 0, '/');
        $item('Hírek', 10, '/hirek');
        $list = $item('Kategóriák', 20, '/kategoriak');
        $weight = 0;
        foreach ($categories as $category) {
            $item($category->as(Titled::class)->title(), $weight, '', $category, $list);
            $weight += 10;
        }
        if ($events !== null) {
            $item('Események', 15, $events);
        }
        $item('Rólunk', 30, '', $about);

        $this->output->success($this->t->translate('cli.seed.menu', ['count' => $count]));
    }

    /**
     * The events (since 0.3.0): the Blueprint `esemeny` and the list `esemenyek`, imported
     * as System → Content model imports them (so they can be edited in the admin), then
     * a few events from a week ago to the next two months.
     *
     * @return string|null The list's path, or null if the site has another list there or none
     */
    private function events(Container $container): ?string
    {
        $blueprints = $container->get(BlueprintRegistry::class);
        $lists = $container->get(ListStore::class);
        if ($blueprints->find(self::EVENT) === null && !isset($lists->load()[self::EVENTS])) {
            $import = $container->get(ModelImport::class);
            $plan = $import->plan(['blueprints' => [self::EVENT => self::EVENT_BLUEPRINT], 'lists' => [self::EVENTS => self::EVENTS_LIST], 'campanella' => '', 'exported_at' => '']);
            if ($plan->hasErrors()) {
                return null; // e.g. the path is taken
            }
            $import->apply($plan);
            // Defined now, so its objects can be made in this run too.
            $blueprints->loadStored([self::EVENT => $container->get(BlueprintStore::class)->load()[self::EVENT]]);
            $this->output->success($this->t->translate('cli.seed.model', ['blueprint' => self::EVENT, 'list' => self::EVENTS_LIST['path']]));
        }
        $blueprint = $blueprints->find(self::EVENT);
        $list = $lists->load()[self::EVENTS] ?? null;
        if ($blueprint === null || !$blueprints->isEditable(self::EVENT) || !isset($blueprint->fields['kezdes'], $blueprint->fields['fajta'], $blueprint->fields['helyszin'], $blueprint->fields['belepo'])) {
            return null; // not ours
        }

        $timezone = new DateTimeZone((string) $container->get(Config::class)->get('timezone', 'UTC'));
        $today = new DateTimeImmutable('today', $timezone);
        $published = new DateTimeImmutable('-7 days', new DateTimeZone('UTC'));
        foreach (self::EVENT_SAMPLES as [$title, $days, $time, $kind, $place, $price, $text]) {
            $this->ensure(self::EVENT, $title, [
                'kezdes' => $today->modify(sprintf('%+d days %s', $days, $time)),
                'fajta' => $kind,
                'helyszin' => $place,
                'belepo' => $price,
                'body' => PlainText::toHtml($text),
            ], $published);
        }

        return is_array($list) && is_string($list['path'] ?? null) ? $list['path'] : null;
    }

    /**
     * Looks up the object by the path derived from its title; creates it if it does not exist.
     *
     * @param array<string, mixed> $values
     * @param DateTimeImmutable|null $publishAt Publication time; if null, it stays a draft.
     */
    private function ensure(string $blueprint, string $title, array $values, ?DateTimeImmutable $publishAt = null): CampanellaObject
    {
        $path = Routable::normalize('/' . Slugger::slugify($title));
        $existing = $this->queries->first(Query::objects()->where('path', '=', $path), $this->actor);
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
