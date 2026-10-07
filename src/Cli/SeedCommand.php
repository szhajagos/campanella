<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Capability\Routable;
use Campanella\Capability\Titled;
use Campanella\Core\Container;
use Campanella\Html\PlainText;
use Campanella\I18n\Translator;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;
use Campanella\Support\Slugger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Creates sample content, so there is something to look at.
 *
 * Can be run repeatedly: whatever it already finds by path is not created
 * again. So after an upgrade the new samples (e.g. the 0.0.2 categories)
 * are added next to the existing content.
 */
final class SeedCommand implements Command
{
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

        $this->mainMenu($categories, $about);

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
     */
    private function mainMenu(array $categories, CampanellaObject $about): void
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
        $item('Rólunk', 30, '', $about);

        $this->output->success($this->t->translate('cli.seed.menu', ['count' => $count]));
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
