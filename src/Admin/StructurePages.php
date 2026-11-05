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
 * changed here: Blueprints are defined in config/blueprints.php or made in the admin
 * (BlueprintEditor, since 0.2.3), capabilities in code.
 */
final class StructurePages
{
    public function __construct(
        private readonly BlueprintRegistry $blueprints,
        private readonly CapabilityRegistry $capabilities,
        private readonly QueryEngine $queries,
        private readonly AdminAccess $access,
        private readonly ?\Campanella\Model\FieldTypeRegistry $types = null,
        private readonly ?\Campanella\Module\ModuleLoader $modules = null,
    ) {
    }

    /**
     * The field types (since 0.4.8; /admin/system/field-types): the core's and the modules',
     * each with how it is stored and the Blueprints' fields that use it; and the fields of a
     * module's type whose module is off or gone (they work as their base type).
     *
     * @return array{types: list<array<string, mixed>>, missing: list<array{blueprint: string, field: string, type: string, base: string}>}
     */
    public function fieldTypes(): array
    {
        $uses = [];
        $missing = [];
        foreach ($this->blueprints->all() as $blueprint) {
            foreach ($blueprint->fields as $field) {
                $uses[$field->typeName()][] = ['blueprint' => $blueprint->name, 'label' => $blueprint->label, 'field' => $field->name, 'editable' => $this->blueprints->isEditable($blueprint->name)];
                if ($field->customType !== null && $field->custom === null) {
                    $missing[] = ['blueprint' => $blueprint->name, 'field' => $field->name, 'type' => $field->customType, 'base' => $field->type->value];
                }
            }
        }
        $owners = [];
        foreach ($this->modules?->loaded() ?? [] as $module => $registered) {
            foreach ((array) ($registered['field_types'] ?? []) as $type) {
                $owners[$type->name()] = $module;
            }
        }
        $types = [];
        foreach (\Campanella\Model\FieldType::cases() as $type) {
            $types[] = [
                'name' => $type->value,
                'label' => 'field_type.' . $type->value,
                'description' => 'field_type_info.' . $type->value,
                'origin' => null,
                'base' => null,
                'column' => strtolower($type->columnType()->name),
                'widget' => null,
                'uses' => $uses[$type->value] ?? [],
            ];
        }
        foreach ($this->types?->custom() ?? [] as $name => $type) {
            $types[] = [
                'name' => $name,
                'label' => $type->label(),
                'description' => $type->description(),
                'origin' => $owners[$name] ?? null,
                'base' => $type->base()->value,
                'column' => strtolower($type->base()->columnType()->name),
                'widget' => $type->widget(),
                'uses' => $uses[$name] ?? [],
            ];
        }

        return ['types' => $types, 'missing' => $missing];
    }

    /**
     * @return list<array{name: string, label: string, editable: bool, count: int, list_path: ?string, capabilities: list<string>,
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
                'editable' => $this->blueprints->isEditable($name),
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
