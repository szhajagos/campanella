<?php

declare(strict_types=1);

namespace Campanella\Query;

use Campanella\Capability\CapabilityRegistry;
use Campanella\Database\Connection;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;
use Campanella\Query\Condition\Condition;
use Campanella\Query\Condition\FieldCondition;
use Campanella\Query\Condition\Group;
use Campanella\Query\Condition\HasCapability;
use Campanella\Query\Condition\RelatedTo;

/**
 * Compiles a Query to SQL. Besides the Connection, this is the only
 * place where SQL is generated.
 *
 * The query returns only IDs; the objects are then batch-loaded by the
 * ObjectRepository.
 */
final class QueryCompiler
{
    /** @var array<string, string> capability name => JOIN clause */
    private array $joins = [];

    /** @var array<string, mixed> */
    private array $params = [];

    /** Counter of relation subqueries (for unique aliases). */
    private int $relationCount = 0;

    /** Counter of multi-valued field subqueries (for unique aliases). */
    private int $valueCount = 0;

    /**
     * @param BlueprintRegistry|null $blueprints For checking relation names; without it,
     *        relation conditions (whereRelated) cannot be compiled.
     */
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly ?BlueprintRegistry $blueprints = null,
    ) {
    }

    /** SELECT o.id ... with ordering and pagination. */
    public function compile(Query $query): CompiledQuery
    {
        $this->reset();
        $where = $this->compileCondition($query->conditions());

        $order = [];
        foreach ($query->ordering() as [$field, $direction]) {
            $order[] = $this->column($field, forOrdering: true) . ' ' . $direction->value;
        }
        $order[] = 'o.`id` ' . ($query->ordering()[0][1] ?? Direction::Asc)->value; // stable ordering

        $sql = 'SELECT o.`id` FROM {objects} o' . $this->joinSql()
            . ($where === '' ? '' : ' WHERE ' . $where)
            . ' ORDER BY ' . implode(', ', $order);

        if ($query->getLimit() !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $query->getLimit(), $query->getOffset());
        } elseif ($query->getOffset() > 0) {
            throw new QueryException('Offset can only be given together with a limit.');
        }

        return new CompiledQuery($sql, $this->params);
    }

    /** SELECT COUNT(*) ... with the same conditions, without pagination. */
    public function compileCount(Query $query): CompiledQuery
    {
        $this->reset();
        $where = $this->compileCondition($query->conditions());
        // Capability tables join 1:1 on object_id, so the JOIN does not multiply rows.
        $sql = 'SELECT COUNT(*) FROM {objects} o' . $this->joinSql() . ($where === '' ? '' : ' WHERE ' . $where);

        return new CompiledQuery($sql, $this->params);
    }

    private function reset(): void
    {
        $this->joins = [];
        $this->params = [];
        $this->relationCount = 0;
        $this->valueCount = 0;
    }

    private function compileCondition(Condition $condition): string
    {
        return match (true) {
            $condition instanceof Group => $this->compileGroup($condition),
            $condition instanceof FieldCondition => $this->compileField($condition),
            $condition instanceof HasCapability => $this->compileHasCapability($condition),
            $condition instanceof RelatedTo => $this->compileRelatedTo($condition),
            default => throw new QueryException('Unknown condition type: ' . $condition::class),
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
        $multi = isset(Query::BASE_FIELDS[$condition->field]) ? null : $this->capabilities->field($condition->field);
        if ($multi !== null && $multi->usesValueTable()) {
            return $this->compileMultiField($condition, $multi);
        }

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

    /**
     * A condition on a multi-valued field, as a subquery on the field_values table:
     *
     *   =, <, <=, >, >=, LIKE, IN   – at least one value matches (EXISTS)
     *   !=, NOT IN                  – the object has the field's capability, and no value matches
     *   IS NULL                     – the field has no value at all
     *   IS NOT NULL                 – the field has at least one value
     *
     * The capability check keeps != and NOT IN consistent with single-valued fields:
     * an object that does not have the field at all is not a match.
     */
    private function compileMultiField(FieldCondition $condition, Field $field): string
    {
        $alias = 'fv' . $this->valueCount++;
        $column = $alias . '.' . Connection::quoteIdentifier($field->type->valueColumn());
        $operator = $condition->operator;

        $negated = in_array($operator, [Operator::NotEquals, Operator::NotIn, Operator::IsNull], true);
        $match = match ($operator) {
            Operator::IsNull, Operator::IsNotNull => '',
            Operator::In, Operator::NotIn => sprintf(' AND %s IN (%s)', $column, implode(', ', array_map(
                fn (mixed $value): string => $this->param($field->type->toStorage($value)),
                (array) $condition->value,
            ))),
            Operator::NotEquals => sprintf(' AND %s = %s', $column, $this->param($field->type->toStorage($condition->value))),
            default => sprintf(
                ' AND %s %s %s',
                $column,
                $operator->sql(),
                $this->param($field->type->toStorage($condition->value)),
            ),
        };

        $sql = sprintf(
            '%1$sEXISTS (SELECT 1 FROM {field_values} %2$s WHERE %2$s.`object_id` = o.`id` AND %2$s.`field` = %3$s%4$s)',
            $negated ? 'NOT ' : '',
            $alias,
            $this->param($field->name),
            $match,
        );
        if ($operator === Operator::NotEquals || $operator === Operator::NotIn) {
            $owner = $this->capabilities->fieldOwner($field->name)
                ?? throw new QueryException("Unknown field: {$field->name}");
            $sql = sprintf(
                '(EXISTS (SELECT 1 FROM {object_capabilities} %1$s WHERE %1$s.`object_id` = o.`id` AND %1$s.`capability` = %2$s) AND %3$s)',
                $alias . 'c',
                $this->param($owner->name),
                $sql,
            );
        }

        return $sql;
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

    private function compileRelatedTo(RelatedTo $condition): string
    {
        if ($this->blueprints?->relation($condition->relation) === null) {
            throw new QueryException("Unknown relation: {$condition->relation}");
        }
        $alias = 'rl' . $this->relationCount++;
        $sql = sprintf(
            'EXISTS (SELECT 1 FROM {relationships} %1$s WHERE %1$s.`source_id` = o.`id` AND %1$s.`type` = %2$s',
            $alias,
            $this->param($condition->relation),
        );
        if ($condition->targets !== []) {
            $placeholders = array_map($this->param(...), $condition->targets);
            $sql .= sprintf(' AND %s.`target_id` IN (%s)', $alias, implode(', ', $placeholders));
        }

        return ($condition->negated ? 'NOT ' : '') . $sql . ')';
    }

    /** Column reference from the field name, with a JOIN if needed. */
    private function column(string $field, bool $forOrdering = false): string
    {
        if (isset(Query::BASE_FIELDS[$field])) {
            return 'o.' . Connection::quoteIdentifier(Query::BASE_FIELDS[$field]);
        }

        $owner = $this->capabilities->fieldOwner($field)
            ?? throw new QueryException("Unknown or non-queryable field: {$field}");
        $definition = $owner->fields[$field];

        if ($definition->isMultiple() && $forOrdering) {
            throw new QueryException(
                "Field '{$field}' is multi-valued, so it cannot be sorted on.",
            );
        }

        if ($definition->storage === FieldStorage::Data) {
            throw new QueryException(sprintf(
                "Field '%s' lives in the data (JSON) column, so it cannot be %s. "
                . 'If this is needed, the field must be promoted to a column in its own table.',
                $field,
                $forOrdering ? 'sorted on' : 'filtered on',
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
            default => ($this->capabilities->field($field) ?? throw new QueryException("Unknown field: {$field}"))->type,
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
