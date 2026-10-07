<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\CapabilityDefinition;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\I18n\Message;
use Campanella\Capability\TextFormat;
use Campanella\Capability\Hierarchical;
use Campanella\Capability\Textual;
use Campanella\Html\HtmlSanitizer;
use Campanella\Tree\TreeKeeper;
use Campanella\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * Loading and saving objects (Data Mapper).
 *
 * An object lives in several tables:
 *   objects              – identity + the data (JSON) column
 *   object_capabilities  – which capabilities it has
 *   cap_<name>           – the capabilities' queryable single-valued fields
 *   field_values         – the values of the queryable multi-valued fields
 *   relationships        – relationships to other objects
 *
 * When loading lists, a single query runs per table (no N+1).
 * The object cache will also be built in here later.
 */
final class ObjectRepository
{
    /** The MySQL/MariaDB error code for a unique key violation. */
    private const int DUPLICATE_KEY = 1062;

    private readonly HtmlSanitizer $html;
    private readonly TreeKeeper $tree;

    /**
     * @param HtmlSanitizer|null $html Filters texts in `html` format on save (since 0.0.5);
     *        null: the built-in allowlist. There is no way to save without it.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly CapabilityRegistry $capabilities,
        private readonly BlueprintRegistry $blueprints,
        ?HtmlSanitizer $html = null,
    ) {
        $this->html = $html ?? new HtmlSanitizer();
        $this->tree = new TreeKeeper($db);
    }

    /**
     * A new, not yet saved object based on the Blueprint.
     *
     * @param array<string, mixed> $values
     */
    public function create(string $blueprint, array $values = []): CampanellaObject
    {
        $definition = $this->blueprints->get($blueprint);
        $fields = $definition->allFields();

        $unknown = array_diff_key($values, $fields);
        if ($unknown !== []) {
            throw new \OutOfBoundsException(sprintf(
                "Blueprint '%s' has no such field: %s",
                $blueprint,
                implode(', ', array_keys($unknown)),
            ));
        }

        $now = self::now();

        return new CampanellaObject(
            null,
            Uuid::v7(),
            $blueprint,
            $definition->capabilities,
            $fields,
            $values + $definition->defaults,
            $now,
            $now,
            $definition->allRelations(),
        );
    }

    public function find(int $id): ?CampanellaObject
    {
        return $this->loadMany([$id])[$id] ?? null;
    }

    public function findByUuid(string $uuid): ?CampanellaObject
    {
        $id = $this->db->fetchValue('SELECT id FROM {objects} WHERE uuid = :uuid', ['uuid' => $uuid]);

        return $id === null ? null : $this->find((int) $id);
    }

    /**
     * @param list<int> $ids
     * @return array<int, CampanellaObject> In input order, keyed by ID.
     */
    public function loadMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        if ($ids === []) {
            return [];
        }
        [$in, $params] = self::inList($ids);

        $rows = [];
        foreach ($this->db->fetchAll("SELECT * FROM {objects} WHERE id IN ({$in})", $params) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        /** @var array<int, array<string, CapabilityDefinition>> $objectCapabilities */
        $objectCapabilities = [];
        /** @var array<string, CapabilityDefinition> $involved */
        $involved = [];
        $capabilityRows = $this->db->fetchAll(
            "SELECT object_id, capability FROM {object_capabilities} WHERE object_id IN ({$in})",
            $params,
        );
        foreach ($capabilityRows as $row) {
            // Unknown capability (e.g. from a removed module): the data stays
            // in the database, but the object does not get it.
            if (!$this->capabilities->has($row['capability'])) {
                continue;
            }
            $definition = $this->capabilities->get($row['capability']);
            $objectCapabilities[(int) $row['object_id']][$definition->name] = $definition;
            $involved[$definition->name] = $definition;
        }

        /** @var array<string, array<int, array<string, mixed>>> $tableValues */
        $tableValues = [];
        foreach ($involved as $definition) {
            if (!$definition->hasTable()) {
                continue;
            }
            $sql = sprintf('SELECT * FROM {%s} WHERE object_id IN (%s)', $definition->tableName(), $in);
            foreach ($this->db->fetchAll($sql, $params) as $row) {
                $tableValues[$definition->name][(int) $row['object_id']] = $row;
            }
        }

        // Multi-valued queryable fields: for all objects being loaded, in a single query, ordered.
        /** @var array<int, array<string, list<mixed>>> $multiValues */
        $multiValues = [];
        $multiFields = [];
        foreach ($involved as $definition) {
            $multiFields += $definition->valueTableFields();
        }
        if ($multiFields !== []) {
            $sql = "SELECT * FROM {field_values} WHERE object_id IN ({$in}) ORDER BY object_id, field, delta, id";
            foreach ($this->db->fetchAll($sql, $params) as $row) {
                $field = $multiFields[(string) $row['field']] ?? null;
                if ($field !== null) {
                    $multiValues[(int) $row['object_id']][$field->name][] = $row[$field->type->valueColumn()];
                }
            }
        }

        // Relations: for all objects being loaded, in a single query, ordered.
        /** @var array<int, array<string, list<int>>> $relatedIds */
        $relatedIds = [];
        $relationRows = $this->db->fetchAll(
            "SELECT source_id, type, target_id FROM {relationships} WHERE source_id IN ({$in})"
            . ' ORDER BY source_id, type, weight, id',
            $params,
        );
        foreach ($relationRows as $row) {
            $relatedIds[(int) $row['source_id']][(string) $row['type']][] = (int) $row['target_id'];
        }

        $objects = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                continue;
            }
            $objects[$id] = $this->hydrate(
                $rows[$id],
                $this->orderCapabilities($objectCapabilities[$id] ?? []),
                $tableValues,
                $relatedIds[$id] ?? [],
                $multiValues[$id] ?? [],
            );
        }

        return $objects;
    }

    public function save(CampanellaObject $object): void
    {
        foreach ($object->capabilities() as $definition) {
            $object->as($definition->class)->prepareForSave();
        }
        $tree = $object->has(Hierarchical::class);
        $scope = $tree ? $this->blueprints->find($object->blueprint())?->treeScope : null;
        $this->validate($object, $this->sanitizeHtml($object) + ($tree ? $this->tree->validate($object, $scope) : []));

        $now = self::now();
        $data = [];
        foreach ($object->fields() as $name => $field) {
            if ($field->storage === FieldStorage::Data) {
                $data[$name] = $field->toStorage($object->get($name));
            }
        }
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $format = FieldType::STORAGE_DATE_FORMAT;

        $id = $this->db->transactional(function (Connection $db) use ($object, $json, $now, $format, $tree, $scope): int {
            if ($object->isNew()) {
                $id = $db->insert(CoreSchema::OBJECTS, [
                    'uuid' => $object->uuid(),
                    'blueprint' => $object->blueprint(),
                    'data' => $json,
                    'created_at' => $object->created()->format($format),
                    'updated_at' => $now->format($format),
                ]);
            } else {
                $id = (int) $object->id();
                $db->update(
                    CoreSchema::OBJECTS,
                    ['data' => $json, 'updated_at' => $now->format($format)],
                    ['id' => $id],
                );
            }

            // The position in its tree, now that the ID is known (Hierarchical).
            $moveSubtree = $tree ? $this->tree->place($object, $id) : null;

            $db->delete(CoreSchema::OBJECT_CAPABILITIES, ['object_id' => $id]);
            foreach ($object->capabilityNames() as $name) {
                $db->insert(CoreSchema::OBJECT_CAPABILITIES, ['object_id' => $id, 'capability' => $name]);
            }

            foreach ($object->capabilities() as $definition) {
                if ($definition->hasTable()) {
                    $this->writeCapabilityRow($db, $definition, $id, $object);
                }
                foreach ($definition->valueTableFields() as $name => $field) {
                    $this->writeFieldValues($db, $field, $id, $object->get($name));
                }
            }

            foreach ($object->relations() as $name => $_) {
                $db->delete(CoreSchema::RELATIONSHIPS, ['source_id' => $id, 'type' => $name]);
                foreach ($object->relatedIds($name) as $weight => $targetId) {
                    $db->insert(CoreSchema::RELATIONSHIPS, [
                        'source_id' => $id,
                        'type' => $name,
                        'target_id' => $targetId,
                        'weight' => $weight,
                    ]);
                }
            }
            if ($moveSubtree !== null) {
                $moveSubtree();
            }
            // A node moved to another scope (e.g. menu) takes its subtree with it.
            $scopeTarget = $scope === null ? null : ($object->relatedIds($scope)[0] ?? null);
            if ($scopeTarget !== null) {
                $this->tree->carryScope($id, (string) $object->get('tree_path'), (string) $scope, $scopeTarget);
            }

            return $id;
        });

        $object->markSaved($id, $now);
    }

    /**
     * @throws ValidationException (on `children`) for a tree node that has children
     *         (they must be moved elsewhere first), or for an object that is the scope
     *         of a tree's nodes, e.g. a menu with items (since 0.0.7)
     */
    public function delete(CampanellaObject $object): void
    {
        if ($object->isNew()) {
            return;
        }
        if ($object->has(Hierarchical::class)) {
            $children = $this->tree->childCount((int) $object->id());
            if ($children > 0) {
                throw new ValidationException(['children' => new Message('tree.has_children', ['count' => $children])]);
            }
        }
        $members = $this->tree->scopeMembers((int) $object->id(), $this->blueprints->treeScopes());
        if ($members > 0) {
            throw new ValidationException(['children' => new Message('tree.scope_in_use', ['count' => $members])]);
        }
        // Rows in the capability tables are deleted by ON DELETE CASCADE.
        $this->db->delete(CoreSchema::OBJECTS, ['id' => $object->id()]);
    }

    /** Replaces the rows of a multi-valued field in the field_values table. */
    private function writeFieldValues(Connection $db, Field $field, int $id, mixed $value): void
    {
        $db->delete(CoreSchema::FIELD_VALUES, ['object_id' => $id, 'field' => $field->name]);
        $column = $field->type->valueColumn();
        foreach ((array) $field->toStorage($value) as $delta => $item) {
            $db->insert(CoreSchema::FIELD_VALUES, [
                'object_id' => $id,
                'field' => $field->name,
                'delta' => $delta,
                $column => $item,
            ]);
        }
    }

    private function writeCapabilityRow(
        Connection $db,
        CapabilityDefinition $definition,
        int $id,
        CampanellaObject $object,
    ): void {
        $row = [];
        foreach ($definition->tableFields() as $name => $field) {
            $row[$name] = $field->type->toStorage($object->get($name));
        }
        $table = $definition->tableName();

        try {
            $exists = $db->fetchValue(sprintf('SELECT 1 FROM {%s} WHERE object_id = :id', $table), ['id' => $id]);
            if ($exists === null) {
                $db->insert($table, ['object_id' => $id] + $row);
            } else {
                $db->update($table, $row, ['object_id' => $id]);
            }
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== self::DUPLICATE_KEY) {
                throw $e;
            }
            $errors = [];
            foreach ($definition->tableFields() as $name => $field) {
                if ($field->unique) {
                    $errors[$name] = new Message('validation.taken', ['value' => (string) $row[$name]]);
                }
            }
            throw new ValidationException($errors ?: [$definition->name => 'validation.unique_conflict']);
        }
    }

    /**
     * Filters the body of a text in `html` format (HtmlSanitizer). A text that cannot
     * be filtered (too long, too many tags, invalid UTF-8) is not changed but
     * reported as a validation error.
     *
     * @return array<string, Message> field name => message
     */
    private function sanitizeHtml(CampanellaObject $object): array
    {
        if (!$object->has(Textual::class) || $object->as(Textual::class)->format() !== TextFormat::Html) {
            return [];
        }
        $body = $object->as(Textual::class)->body();
        $problem = $this->html->problem($body);
        if ($problem !== null) {
            return ['body' => $problem];
        }
        $object->set('body', $this->html->sanitize($body));

        return [];
    }

    /** @param array<string, Message> $errors Errors found before (e.g. by sanitizeHtml()) */
    private function validate(CampanellaObject $object, array $errors = []): void
    {
        foreach ($object->fields() as $name => $field) {
            $value = $object->get($name);
            if ($field->required && $field->isEmpty($value)) {
                $errors[$name] = new Message('validation.required');
            } elseif ($field->exceedsCardinality($value)) {
                $errors[$name] = new Message('validation.too_many_values', ['max' => $field->cardinality]);
            } elseif ($field->hasTooLongItem($value) || $field->isTooLong($value)) {
                $errors[$name] = new Message('validation.value_too_long', ['max' => $field->length]);
            }
        }
        foreach ($object->capabilities() as $definition) {
            $errors += $object->as($definition->class)->validate();
        }
        $errors += $this->validateRelations($object);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Validates the relations: required relation, self-reference, and whether the
     * targets exist and match the definition (Blueprint, capabilities).
     *
     * @return array<string, Message> relation name => message
     */
    private function validateRelations(CampanellaObject $object): array
    {
        $errors = [];
        $allTargets = [];
        foreach ($object->relations() as $name => $relation) {
            $ids = $object->relatedIds($name);
            if ($relation->required && $ids === []) {
                $errors[$name] = new Message('validation.relation_required');
            } elseif ($relation->exceedsMax(count($ids))) {
                $errors[$name] = new Message('validation.too_many_relations', ['max' => (int) $relation->max]);
            } elseif ($object->id() !== null && in_array($object->id(), $ids, true)) {
                $errors[$name] = new Message('validation.self_reference');
            }
            array_push($allTargets, ...$ids);
        }
        $allTargets = array_values(array_unique($allTargets));
        if ($allTargets === []) {
            return $errors;
        }

        [$in, $params] = self::inList($allTargets);
        $blueprints = [];
        foreach ($this->db->fetchAll("SELECT id, blueprint FROM {objects} WHERE id IN ({$in})", $params) as $row) {
            $blueprints[(int) $row['id']] = (string) $row['blueprint'];
        }
        $capabilities = [];
        $sql = "SELECT object_id, capability FROM {object_capabilities} WHERE object_id IN ({$in})";
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $capabilities[(int) $row['object_id']][(string) $row['capability']] = true;
        }

        foreach ($object->relations() as $name => $relation) {
            if (isset($errors[$name])) {
                continue;
            }
            $required = array_map(fn (string $c): string => $this->capabilities->get($c)->name, $relation->targetCapabilities);
            foreach ($object->relatedIds($name) as $targetId) {
                if (!isset($blueprints[$targetId])) {
                    $errors[$name] = new Message('validation.target_missing', ['id' => $targetId]);
                } elseif ($relation->targetBlueprints !== [] && !in_array($blueprints[$targetId], $relation->targetBlueprints, true)) {
                    $errors[$name] = new Message('validation.target_blueprint', [
                        'id' => $targetId,
                        'blueprint' => $blueprints[$targetId],
                        'allowed' => implode(', ', $relation->targetBlueprints),
                    ]);
                } else {
                    $missing = array_diff($required, array_keys($capabilities[$targetId] ?? []));
                    if ($missing !== []) {
                        $errors[$name] = new Message('validation.target_capability', ['id' => $targetId, 'missing' => implode(', ', $missing)]);
                    }
                }
                if (isset($errors[$name])) {
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, CapabilityDefinition> $capabilities
     * @param array<string, array<int, array<string, mixed>>> $tableValues
     * @param array<string, list<int>> $relatedIds
     * @param array<string, list<mixed>> $multiValues field name => stored values, in order
     */
    private function hydrate(
        array $row,
        array $capabilities,
        array $tableValues,
        array $relatedIds,
        array $multiValues,
    ): CampanellaObject
    {
        $id = (int) $row['id'];
        $blueprint = $this->blueprints->find((string) $row['blueprint']);

        $fields = [];
        $relations = [];
        foreach ($capabilities as $definition) {
            $fields += $definition->fields;
            $relations += $definition->relations;
        }
        if ($blueprint !== null) {
            $fields = $blueprint->narrow($fields) + $blueprint->fields;
            $relations += $blueprint->relations;
        }

        $data = [];
        $json = (string) $row['data'];
        if ($json !== '' && json_validate($json)) {
            $data = (array) json_decode($json, true);
        }

        $values = [];
        foreach ($fields as $name => $field) {
            if ($field->storage === FieldStorage::Data) {
                if (array_key_exists($name, $data)) {
                    $values[$name] = $field->fromStorage($data[$name]);
                }
                continue;
            }
            if ($field->isMultiple()) {
                $values[$name] = $field->fromStorage($multiValues[$name] ?? []);
                continue;
            }
            $owner = $this->capabilities->fieldOwner($name);
            if ($owner !== null && isset($tableValues[$owner->name][$id])) {
                $values[$name] = $field->fromStorage($tableValues[$owner->name][$id][$name] ?? null);
            }
        }

        $utc = new DateTimeZone('UTC');

        return new CampanellaObject(
            $id,
            (string) $row['uuid'],
            (string) $row['blueprint'],
            $capabilities,
            $fields,
            $values,
            new DateTimeImmutable((string) $row['created_at'], $utc),
            new DateTimeImmutable((string) $row['updated_at'], $utc),
            $relations,
            $relatedIds,
        );
    }

    /**
     * Dependency order, so that the prepareForSave() calls also run in the right order.
     *
     * @param array<string, CapabilityDefinition> $capabilities
     * @return array<string, CapabilityDefinition>
     */
    private function orderCapabilities(array $capabilities): array
    {
        return $capabilities === [] ? [] : $this->capabilities->resolve(array_keys($capabilities));
    }

    /**
     * @param list<int> $ids
     * @return array{string, array<string, int>}
     */
    private static function inList(array $ids): array
    {
        $params = [];
        foreach ($ids as $i => $id) {
            $params['id' . $i] = $id;
        }

        return [implode(', ', array_map(static fn (string $k): string => ':' . $k, array_keys($params))), $params];
    }

    private static function now(): DateTimeImmutable
    {
        // Second precision, because the DATETIME column stores that much too.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    }
}
