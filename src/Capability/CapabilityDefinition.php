<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Database\Schema\Column;
use Campanella\Database\Schema\ColumnType;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Database\Schema\ForeignKey;
use Campanella\Database\Schema\Table;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use ReflectionClass;

/**
 * Egy capability osztály feldolgozott, gyorsan használható leírása.
 */
final readonly class CapabilityDefinition
{
    /**
     * @param class-string<Capability> $class
     * @param list<class-string<Capability>> $requires
     * @param array<string, Field> $fields
     */
    public function __construct(
        public string $name,
        public string $class,
        public array $requires,
        public array $fields,
        public string $label,
    ) {
    }

    /** @param class-string<Capability> $class */
    public static function fromClass(string $class): self
    {
        if (!is_subclass_of($class, Capability::class)) {
            throw new CapabilityException("{$class} nem Capability.");
        }
        $attributes = (new ReflectionClass($class))->getAttributes(AsCapability::class);
        if ($attributes === []) {
            throw new CapabilityException("{$class} osztályon hiányzik az #[AsCapability] attribútum.");
        }
        $meta = $attributes[0]->newInstance();
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $meta->name) !== 1) {
            throw new CapabilityException("Érvénytelen capability-név: {$meta->name}");
        }

        $fields = [];
        foreach ($class::fields() as $field) {
            $fields[$field->name] = $field;
        }

        return new self($meta->name, $class, $meta->requires, $fields, $meta->label ?: ucfirst($meta->name));
    }

    /** A capability saját táblájának neve (prefix nélkül). */
    public function tableName(): string
    {
        return 'cap_' . $this->name;
    }

    /** @return array<string, Field> */
    public function tableFields(): array
    {
        return array_filter($this->fields, static fn (Field $f): bool => $f->storage === FieldStorage::Table);
    }

    /** @return array<string, Field> */
    public function dataFields(): array
    {
        return array_filter($this->fields, static fn (Field $f): bool => $f->storage === FieldStorage::Data);
    }

    public function hasTable(): bool
    {
        return $this->tableFields() !== [];
    }

    /** A capability táblájának sémája, a mezőkből levezetve. */
    public function table(): ?Table
    {
        if (!$this->hasTable()) {
            return null;
        }

        $columns = [new Column('object_id', ColumnType::Id)];
        $indexes = [];
        $uniques = [];
        foreach ($this->tableFields() as $field) {
            $columns[] = new Column(
                name: $field->name,
                type: $field->type->columnType(),
                nullable: !$field->required,
                length: $field->length,
            );
            if ($field->unique) {
                $uniques['uniq_' . $field->name] = [$field->name];
            } elseif ($field->indexed) {
                $indexes['idx_' . $field->name] = [$field->name];
            }
        }

        return new Table(
            name: $this->tableName(),
            columns: $columns,
            primaryKey: ['object_id'],
            indexes: $indexes,
            uniques: $uniques,
            foreignKeys: [new ForeignKey('object_id', CoreSchema::OBJECTS)],
        );
    }
}
