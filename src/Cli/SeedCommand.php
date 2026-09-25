<?php

declare(strict_types=1);

namespace Campanella\Cli;

use Campanella\Access\Actor;
use Campanella\Core\Container;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;
use DateTimeImmutable;
use DateTimeZone;

/** Példatartalom létrehozása, hogy legyen mit megnézni. */
final class SeedCommand implements Command
{
    #[\Override]
    public function name(): string
    {
        return 'seed';
    }

    #[\Override]
    public function description(): string
    {
        return 'Példatartalmat hoz létre';
    }

    #[\Override]
    public function run(Container $container, array $args, Output $output): int
    {
        $actor = Actor::system();
        $service = $container->get(ObjectService::class);
        $queries = $container->get(QueryEngine::class);

        if ($queries->count(Query::objects(), $actor) > 0) {
            $output->line('Már van tartalom az adatbázisban, a seed kimarad.');

            return 0;
        }

        $utc = new DateTimeZone('UTC');
        $articles = [
            ['Neumann János', '-5 days', 'A számítógép-architektúra egyik atyja.', "Neumann János 1903-ban született Budapesten.\n\nNevéhez fűződik a tárolt programú számítógép elve, amelyet ma Neumann-architektúraként ismerünk."],
            ['Digitális Agora', '-3 days', 'Közösségi tér a hálózaton.', "A Digitális Agora a nyilvános vita és az együttműködés helye.\n\nEz a cikk a Campanella első példatartalmai közé tartozik."],
            ['Megjelent a Campanella 0.0.1', '-1 day', 'Az első, minimális mag.', "Object, Blueprint, Capability, Query és Presentation: ennyiből áll az első változat.\n\nMinden további funkció erre a magra épül."],
            ['Capability-k röviden', '-2 hours', 'Nem típusok, hanem képességek.', "Egy objektum attól cikk, hogy milyen képességei vannak: Titled, Textual, Routable, Publishable.\n\nA típus csak egy elnevezett capability-csomag, a Blueprint."],
            ['Időzített hír', '+2 days', 'Ez a hír még nem látható.', 'Jövőbeli publikálási dátummal mentve: a megadott időpontban magától jelenik meg.'],
        ];

        foreach ($articles as [$title, $when, $lead, $body]) {
            $object = $service->create($actor, 'article', ['title' => $title, 'lead' => $lead, 'body' => $body]);
            $service->publish($actor, $object, new DateTimeImmutable($when, $utc));
            $output->success(sprintf('article #%d  %s  %s', $object->id(), $object->get('path'), $title));
        }

        $draft = $service->create($actor, 'article', [
            'title' => 'Piszkozat',
            'body' => 'Ez egy publikálatlan cikk. Anonymous látogató nem látja, és a listákban sem jelenik meg.',
        ]);
        $output->success(sprintf('article #%d  %s  (piszkozat)', $draft->id(), $draft->get('path')));

        $about = $service->create($actor, 'page', [
            'title' => 'Rólunk',
            'body' => "A Campanella egy capability-vezérelt CMS.\n\nEz az oldal egy „page” Blueprint alapján készült objektum.",
        ], publish: true);
        $output->success(sprintf('page    #%d  %s  Rólunk', $about->id(), $about->get('path')));

        $output->line();
        $output->line(sprintf(
            'Kész: %d objektum, ebből %d publikált.',
            $queries->count(Query::objects(), $actor),
            $queries->count(Query::objects(), Actor::anonymous()),
        ));

        return 0;
    }
}
