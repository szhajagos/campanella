<?php

declare(strict_types=1);

namespace Campanella\Query;

use Campanella\Capability\CapabilityRegistry;
use Campanella\Database\Connection;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;
use Campanella\Query\Condition\Condition;
use Campanella\Query\Condition\FieldCondition;
use Campanella\Query\Condition\Group;
use Campanella\Query\Condition\HasCapability;

/**
 * A Query-t SQL-re fordítja. A Connection mellett ez az egyetlen hely,
 * ahol SQL keletkezik.
 *
 * A lekérdezés csak azonosítókat ad vissza; az objektumokat utána az
 * ObjectRepository tölti be kötegelten.
 */
final class QueryCompiler
{
    /** @var array<string, string> capability-név => JOIN-sor */
    private array $joins = [];

    /** @var array<string, mixed> */
    private array $params = [];

    public function __construct(private readonly CapabilityRegistry $capabilities)
    {
    }

    /** SELECT o.id ... a rendezéssel és lapozással. */
    public function compile(Query $query): CompiledQuery
    {
        $this->reset();
        $where = $this->compileCondition($query->conditions());

        $order = [];
        foreach ($query->ordering() as [$field, $direction]) {
            $order[] = $this->column($field, forOrdering: true) . ' ' . $direction->value;
        }
        $order[] = 'o.`id` ' . ($query->ordering()[0][1] ?? Direction::Asc)->value; // stabil sorrend

        $sql = 'SELECT o.`id` FROM {objects} o' . $this->joinSql()
            . ($where === '' ? '' : ' WHERE ' . $where)
            . ' ORDER BY ' . implode(', ', $order);

        if ($query->getLimit() !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $query->getLimit(), $query->getOffset());
        } elseif ($query->getOffset() > 0) {
            throw new QueryException('Offset csak limittel együtt adható meg.');
        }

        return new CompiledQuery($sql, $this->params);
    }

    /** SELECT COUNT(*) ... ugyanazokkal a feltételekkel, lapozás nélkül. */
    public function compileCount(Query $query): CompiledQuery
    {
        $this->reset();
        $where = $this->compileCondition($query->conditions());
        // A capability-táblák object_id szerint 1:1 kapcsolódnak, így a JOIN nem sokszoroz.
        $sql = 'SELECT COUNT(*) FROM {objects} o' . $this->joinSql() . ($where === '' ? '' : ' WHERE ' . $where);

        return new CompiledQuery($sql, $this->params);
    }

    private function reset(): void
    {
        $this->joins = [];
        $this->params = [];
    }

    private function compileCondition(Condition $condition): string
    {
        return match (true) {
            $condition instanceof Group => $this->compileGroup($condition),
            $condition instanceof FieldCondition => $this->compileField($condition),
            $condition instanceof HasCapability => $this->compileHasCapability($condition),
            default => throw new QueryException('Ismeretlen feltételtípus: ' . $condition::class),
        };
    }

    private function compileGroup(Group $group): string
    {
        $parts = array_values(array_filter(
            array_map($this->compileCondition(...), $group->conditions),
            static fn (string $sql): bool => $sql !== '',
        ));

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            default => '(' . implode($group->any ? ' OR ' : ' AND ', $parts) . ')',
        };
    }

    private function compileField(FieldCondition $condition): string
    {
        $column = $this->column($condition->field);
        $operator = $condition->operator;

        if (!$operator->takesValue()) {
            return "{$column} {$operator->sql()}";
        }
        $type = $this->fieldType($condition->field);

        if ($operator->takesList()) {
            $placeholders = [];
            foreach ((array) $condition->value as $value) {
                $placeholders[] = $this->param($type->toStorage($value));
            }

            return sprintf('%s %s (%s)', $column, $operator->sql(), implode(', ', $placeholders));
        }

        return sprintf('%s %s %s', $column, $operator->sql(), $this->param($type->toStorage($condition->value)));
    }

    private function compileHasCapability(HasCapability $condition): string
    {
        $name = $this->capabilities->get($condition->capability)->name;

        return sprintf(
            '%sEXISTS (SELECT 1 FROM {object_capabilities} oc WHERE oc.`object_id` = o.`id` AND oc.`capability` = %s)',
            $condition->negated ? 'NOT ' : '',
            $this->param($name),
        );
    }

    /** A mezőnévből oszlophivatkozás, szükség esetén JOIN-nal. */
    private function column(string $field, bool $forOrdering = false): string
    {
        if (isset(Query::BASE_FIELDS[$field])) {
            return 'o.' . Connection::quoteIdentifier(Query::BASE_FIELDS[$field]);
        }

        $owner = $this->capabilities->fieldOwner($field)
            ?? throw new QueryException("Ismeretlen vagy nem lekérdezhető mező: {$field}");
        $definition = $owner->fields[$field];

        if ($definition->storage === FieldStorage::Data) {
            throw new QueryException(sprintf(
                "A(z) '%s' mező a data (JSON) oszlopban él, ezért nem lehet rá %s. "
                . 'Ha erre szükség van, a mezőt saját táblás oszloppá kell előléptetni.',
                $field,
                $forOrdering ? 'rendezni' : 'szűrni',
            ));
        }

        $alias = 'c_' . $owner->name;
        $this->joins[$owner->name] ??= sprintf(
            ' LEFT JOIN {%s} %s ON %s.`object_id` = o.`id`',
            $owner->tableName(),
            $alias,
            $alias,
        );

        return $alias . '.' . Connection::quoteIdentifier($field);
    }

    private function fieldType(string $field): FieldType
    {
        return match ($field) {
            'id' => FieldType::Integer,
            'created', 'updated' => FieldType::DateTime,
            'uuid', 'blueprint' => FieldType::String,
            default => ($this->capabilities->field($field) ?? throw new QueryException("Ismeretlen mező: {$field}"))->type,
        };
    }

    private function param(mixed $value): string
    {
        $name = 'p' . count($this->params);
        $this->params[$name] = $value;

        return ':' . $name;
    }

    private function joinSql(): string
    {
        return implode('', $this->joins);
    }
}
