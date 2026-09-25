<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\Capability\CapabilityDefinition;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * Az objektumok betöltése és mentése (Data Mapper).
 *
 * Egy objektum több táblában él:
 *   objects              – identitás + a data (JSON) oszlop
 *   object_capabilities  – milyen capability-kkel rendelkezik
 *   cap_<név>            – a capability-k lekérdezhető mezői
 *
 * Listák betöltésekor táblánként egyetlen lekérdezés fut (nincs N+1).
 * Később ide épül be az object cache is.
 */
final class ObjectRepository
{
    /** A MySQL/MariaDB hibakódja egyedi kulcs megsértésekor. */
    private const int DUPLICATE_KEY = 1062;

    public function __construct(
        private readonly Connection $db,
        private readonly CapabilityRegistry $capabilities,
        private readonly BlueprintRegistry $blueprints,
    ) {
    }

    /**
     * Új, még el nem mentett objektum a Blueprint alapján.
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
                "A(z) '%s' Blueprintnek nincs ilyen mezője: %s",
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
            $values,
            $now,
            $now,
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
     * @return array<int, CampanellaObject> A bemenet sorrendjében, azonosító szerint kulcsolva.
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
            // Ismeretlen (pl. eltávolított modulhoz tartozó) capability: az adat
            // megmarad az adatbázisban, de az objektum nem kapja meg.
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

        $objects = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                continue;
            }
            $objects[$id] = $this->hydrate(
                $rows[$id],
                $this->orderCapabilities($objectCapabilities[$id] ?? []),
                $tableValues,
            );
        }

        return $objects;
    }

    public function save(CampanellaObject $object): void
    {
        foreach ($object->capabilities() as $definition) {
            $object->as($definition->class)->prepareForSave();
        }
        $this->validate($object);

        $now = self::now();
        $data = [];
        foreach ($object->fields() as $name => $field) {
            if ($field->storage === FieldStorage::Data) {
                $data[$name] = $field->type->toStorage($object->get($name));
            }
        }
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $format = FieldType::STORAGE_DATE_FORMAT;

        $id = $this->db->transactional(function (Connection $db) use ($object, $json, $now, $format): int {
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

            $db->delete(CoreSchema::OBJECT_CAPABILITIES, ['object_id' => $id]);
            foreach ($object->capabilityNames() as $name) {
                $db->insert(CoreSchema::OBJECT_CAPABILITIES, ['object_id' => $id, 'capability' => $name]);
            }

            foreach ($object->capabilities() as $definition) {
                if ($definition->hasTable()) {
                    $this->writeCapabilityRow($db, $definition, $id, $object);
                }
            }

            return $id;
        });

        $object->markSaved($id, $now);
    }

    public function delete(CampanellaObject $object): void
    {
        if ($object->isNew()) {
            return;
        }
        // A capability-táblák sorait az ON DELETE CASCADE törli.
        $this->db->delete(CoreSchema::OBJECTS, ['id' => $object->id()]);
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
                    $errors[$name] = sprintf('ez az érték már foglalt (%s)', (string) $row[$name]);
                }
            }
            throw new ValidationException($errors ?: [$definition->name => 'egyedi kulcs ütközés']);
        }
    }

    private function validate(CampanellaObject $object): void
    {
        $errors = [];
        foreach ($object->fields() as $name => $field) {
            if ($field->required && $field->isEmpty($object->get($name))) {
                $errors[$name] = 'kötelező mező';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, CapabilityDefinition> $capabilities
     * @param array<string, array<int, array<string, mixed>>> $tableValues
     */
    private function hydrate(array $row, array $capabilities, array $tableValues): CampanellaObject
    {
        $id = (int) $row['id'];
        $blueprint = $this->blueprints->find((string) $row['blueprint']);

        $fields = [];
        foreach ($capabilities as $definition) {
            $fields += $definition->fields;
        }
        if ($blueprint !== null) {
            $fields += $blueprint->fields;
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
                    $values[$name] = $field->type->fromStorage($data[$name]);
                }
                continue;
            }
            $owner = $this->capabilities->fieldOwner($name);
            if ($owner !== null && isset($tableValues[$owner->name][$id])) {
                $values[$name] = $field->type->fromStorage($tableValues[$owner->name][$id][$name] ?? null);
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
        );
    }

    /**
     * Függőségi sorrend, hogy a prepareForSave() hívások is jó sorrendben fussanak.
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
        // Másodperc pontosság, mert a DATETIME oszlop is ennyit tárol.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    }
}
