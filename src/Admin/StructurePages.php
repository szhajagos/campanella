<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Capability\CapabilityDefinition;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\Relation;

/**
 * The read-only overview of the site's structure for administrators (since 0.1.0):
 * the Blueprints (/admin/system/blueprints) and the capabilities
 * (/admin/system/capabilities), as plain arrays for the templates. Nothing can be
 * changed here: Blueprints are defined in config/blueprints.php, capabilities in code.
 */
final class StructurePages
{
    public function __construct(
        private readonly BlueprintRegistry $blueprints,
        private readonly CapabilityRegistry $capabilities,
        private readonly QueryEngine $queries,
        private readonly AdminAccess $access,
    ) {
    }

    /**
     * @return list<array{name: string, label: string, count: int, list_path: ?string, capabilities: list<string>,
     *     fields: list<array<string, mixed>>, relations: list<array<string, mixed>>, tree_scope: ?string, lists: list<string>}>
     */
    public function blueprints(): array
    {
        $rows = [];
        foreach ($this->blueprints->all() as $name => $blueprint) {
            $user = isset($blueprint->capabilities['authenticatable']);
            $rows[] = [
                'name' => $name,
                'label' => $blueprint->label,
                'count' => $this->queries->count(Query::objects()->blueprint($name), Actor::system()),
                'list_path' => $this->access->path($user ? 'user' : $name),
                'capabilities' => array_keys($blueprint->capabilities),
                // Only the Blueprint's own fields: the capabilities' are on their page.
                'fields' => array_values(array_map(self::field(...), $blueprint->fields)),
                'relations' => array_values(array_map(
                    fn (Relation $r): array => $this->relation($r, $this->ownerOf($r->name, $blueprint)),
                    $blueprint->allRelations(),
                )),
                'tree_scope' => $blueprint->treeScope,
                'lists' => array_keys($blueprint->lists),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, label: string, description: string, class: string, table: ?string, requires: list<string>,
     *     fields: list<array<string, mixed>>, relations: list<array<string, mixed>>, scopes: list<string>, used_by: list<array{name: string, label: string}>}>
     */
    public function capabilities(): array
    {
        $rows = [];
        foreach ($this->capabilities->all() as $definition) {
            $usedBy = [];
            foreach ($this->blueprints->all() as $name => $blueprint) {
                if (isset($blueprint->capabilities[$definition->name])) {
                    $usedBy[] = ['name' => $name, 'label' => $blueprint->label];
                }
            }
            $rows[] = [
                'name' => $definition->name,
                'label' => $definition->label,
                'description' => 'capability.' . $definition->name . '.description',
                'class' => $definition->class,
                'table' => $definition->hasTable() ? $definition->tableName() : null,
                'requires' => array_map(fn (string $class): string => $this->capabilities->get($class)->name, $definition->requires),
                'fields' => array_values(array_map(self::field(...), $definition->fields)),
                'relations' => array_values(array_map(fn (Relation $r): array => $this->relation($r, $definition->name), $definition->relations)),
                'scopes' => array_keys($definition->class::scopes()),
                'used_by' => $usedBy,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $rows;
    }

    /** The capability that brings the relation, or null for the Blueprint's own. */
    private function ownerOf(string $relation, Blueprint $blueprint): ?string
    {
        foreach ($blueprint->capabilities as $definition) {
            if (isset($definition->relations[$relation])) {
                return $definition->name;
            }
        }

        return null;
    }

    /** @return array{name: string, label: string, type: string, storage: string, flags: list<string>} */
    private static function field(Field $field): array
    {
        $flags = [];
        foreach (['required' => $field->required, 'unique' => $field->unique, 'indexed' => $field->indexed, 'hidden' => $field->hidden] as $flag => $on) {
            if ($on) {
                $flags[] = $flag;
            }
        }
        if ($field->isMultiple()) {
            $flags[] = $field->isUnlimited() ? 'multiple' : 'multiple_max:' . $field->cardinality;
        }

        return [
            'name' => $field->name,
            'label' => $field->label !== '' ? $field->label : $field->name,
            'type' => $field->type->value,
            'storage' => match (true) {
                $field->usesValueTable() => 'values',
                $field->storage === FieldStorage::Data => 'data',
                default => 'table',
            },
            'flags' => $flags,
        ];
    }

    /** @return array{name: string, label: string, many: bool, required: bool, targets: list<string>, target_capabilities: list<string>, owner: ?string} */
    private function relation(Relation $relation, ?string $owner): array
    {
        return [
            'name' => $relation->name,
            'label' => $relation->label !== '' ? $relation->label : $relation->name,
            'many' => $relation->isMany(),
            'required' => $relation->required,
            'targets' => $relation->targetBlueprints,
            'target_capabilities' => array_map(
                fn (string $c): string => $this->capabilities->has($c) ? $this->capabilities->get($c)->name : $c,
                $relation->targetCapabilities,
            ),
            'owner' => $owner,
        ];
    }
}
