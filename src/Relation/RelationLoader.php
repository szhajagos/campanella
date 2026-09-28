<?php

declare(strict_types=1);

namespace Campanella\Relation;

use Campanella\Access\Actor;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;

/**
 * Betölti a kapcsolatok célobjektumait megjelenítés előtt.
 *
 * Az objektumok már tudják a kapcsolataik célazonosítóit; a loader ezeket
 * egyetlen, jogosultság-tudatos lekérdezéssel tölti be, akárhány objektumról
 * és kapcsolatról van szó. Amit az Actor nem láthat (pl. egy piszkozat
 * kategória), az kimarad. Az eredményt a relatedObjects() adja vissza.
 *
 * Így a View (sablon) nem kérdez adatbázist, csak kész adatot kap.
 */
final class RelationLoader
{
    public function __construct(private readonly QueryEngine $queries)
    {
    }

    /**
     * @param iterable<CampanellaObject> $objects
     * @param list<string>|null $relations Mely kapcsolatokat; null: mindet, ami az objektumon értelmezett.
     */
    public function resolve(iterable $objects, Actor $actor, ?array $relations = null): void
    {
        $targets = [];
        $work = [];
        foreach ($objects as $object) {
            foreach ($relations ?? array_keys($object->relations()) as $name) {
                if (!$object->hasRelation($name)) {
                    continue;
                }
                $work[] = [$object, $name];
                array_push($targets, ...$object->relatedIds($name));
            }
        }
        if ($work === []) {
            return;
        }

        $targets = array_values(array_unique($targets));
        $visible = [];
        if ($targets !== []) {
            foreach ($this->queries->execute(Query::objects()->where('id', 'IN', $targets), $actor) as $target) {
                $visible[(int) $target->id()] = $target;
            }
        }

        foreach ($work as [$object, $name]) {
            $resolved = [];
            foreach ($object->relatedIds($name) as $id) {
                if (isset($visible[$id])) {
                    $resolved[] = $visible[$id];
                }
            }
            $object->attachResolved($name, $resolved);
        }
    }
}
