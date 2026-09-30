<?php

declare(strict_types=1);

namespace Campanella\Relation;

use Campanella\Access\Actor;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;

/**
 * Loads the target objects of relations before rendering.
 *
 * The objects already know their relations' target IDs; the loader loads
 * them with a single, access-control-aware query, however many objects and
 * relations are involved. Whatever the Actor may not see (e.g. a draft
 * category) is left out. The result is returned by relatedObjects().
 *
 * This way the View (template) never queries the database; it only gets
 * ready data.
 */
final class RelationLoader
{
    public function __construct(private readonly QueryEngine $queries)
    {
    }

    /**
     * @param iterable<CampanellaObject> $objects
     * @param list<string>|null $relations Which relations; null: all relations defined on the object.
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
