<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Capability\Routable;
use Campanella\Capability\Titled;
use Campanella\Core\Container;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;
use Campanella\Support\Slugger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Példatartalom létrehozása, hogy legyen mit megnézni.
 *
 * Ismételten futtatható: amit útvonal alapján már talál, azt nem hozza
 * létre újra. Így egy frissítés után az új példák (pl. a 0.0.2 kategóriái)
 * a meglévő tartalom mellé kerülnek.
 */
final class SeedCommand implements Command
{
    private ObjectService $service;
    private QueryEngine $queries;
    private Output $output;
    private Actor $actor;

    #[\Override]
    public function name(): string
    {
        return 'seed';
    }

    #[\Override]
    public function description(): string
    {
        return 'Példatartalmat hoz létre (ismételten futtatható)';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $this->actor = Actor::system();
        $this->service = $container->get(ObjectService::class);
        $this->queries = $container->get(QueryEngine::class);
        $this->output = $output;
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
            $article = $this->ensure('article', $title, ['lead' => $lead, 'body' => $body], new DateTimeImmutable($when, $utc));
            // A kategóriák csak akkor kerülnek rá, ha még nincs egy sem (a kézi beállítást nem írja felül).
            if ($article->relatedIds('categories') === []) {
                $article->setRelated('categories', array_map(static fn (string $k) => $categories[$k], $categoryKeys));
                $repository->save($article);
                $this->output->success(sprintf('         %s → kategóriák: %s', $article->get('path'), implode(', ', $categoryKeys)));
            }
        }

        $this->ensure('article', 'Piszkozat', [
            'body' => 'Ez egy publikálatlan cikk. Anonymous látogató nem látja, és a listákban sem jelenik meg.',
        ]);
        $this->ensure('page', 'Rólunk', [
            'body' => "A Campanella egy capability-vezérelt CMS.\n\nEz az oldal egy „page” Blueprint alapján készült objektum.",
        ], new DateTimeImmutable('-10 days', $utc));

        $output->line();
        $output->line(sprintf(
            'Kész: %d objektum, ebből %d nyilvánosan látható.',
            $this->queries->count(Query::objects(), $this->actor),
            $this->queries->count(Query::objects(), Actor::anonymous()),
        ));

        return 0;
    }

    /**
     * Megkeresi az objektumot a címből képzett útvonal alapján; ha nincs, létrehozza.
     *
     * @param array<string, mixed> $values
     * @param DateTimeImmutable|null $publishAt Publikálás ideje; null esetén piszkozat marad.
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
            $publishAt === null ? '  (piszkozat)' : '',
        ));

        return $object;
    }
}
