<?php

declare(strict_types=1);

namespace Campanella\Query;

use Campanella\Model\CampanellaObject;
use Campanella\Query\Condition\Condition;
use Campanella\Query\Condition\FieldCondition;
use Campanella\Query\Condition\Group;
use Campanella\Query\Condition\HasCapability;
use Campanella\Query\Condition\RelatedTo;

/**
 * A declarative query: "which objects do I want?"
 *
 *     Query::objects()
 *         ->having('textual', 'routable', 'publishable')
 *         ->scope('published')
 *         ->orderBy('published_at', 'DESC')
 *         ->limit(10);
 *
 * Immutable: every method returns a new instance, so a Query definition
 * can be safely reused and extended (the access control layer appends
 * its own conditions the same way).
 *
 * Base fields: id, uuid, blueprint, created, updated. Every other field
 * belongs to some capability; filtering and sorting are only possible on
 * fields stored in the capability's own table (FieldStorage::Table).
 */
final class Query
{
    public const array BASE_FIELDS = [
        'id' => 'id',
        'uuid' => 'uuid',
        'blueprint' => 'blueprint',
        'created' => 'created_at',
        'updated' => 'updated_at',
    ];

    private Group $where;

    /** @var list<string> */
    private array $scopes = [];

    /** @var list<array{string, Direction}> */
    private array $orderBy = [];

    private ?int $limit = null;
    private int $offset = 0;

    private function __construct()
    {
        $this->where = Group::all();
    }

    public static function objects(): self
    {
        return new self();
    }

    /** Only objects that have all of the capabilities. */
    public function having(string ...$capabilities): self
    {
        $query = $this;
        foreach ($capabilities as $capability) {
            $query = $query->whereCondition(new HasCapability($capability));
        }

        return $query;
    }

    public function blueprint(string ...$blueprints): self
    {
        return count($blueprints) === 1
            ? $this->where('blueprint', Operator::Equals, $blueprints[0])
            : $this->where('blueprint', Operator::In, array_values($blueprints));
    }

    public function where(string $field, Operator|string $operator, mixed $value = null): self
    {
        $operator = Operator::parse($operator);
        if ($operator->takesList() && (!is_array($value) || $value === [])) {
            throw new QueryException("Operator {$operator->value} expects a non-empty list.");
        }

        return $this->whereCondition(new FieldCondition($field, $operator, $value));
    }

    /**
     * Only objects whose relation points to any of the given targets;
     * without targets: those that have such a relation.
     *
     *     Query::objects()->whereRelated('categories', $category)   // the category's articles
     */
    public function whereRelated(string $relation, CampanellaObject|int ...$targets): self
    {
        return $this->whereCondition(new RelatedTo($relation, self::targetIds($targets)));
    }

    /** Only those whose relation does not point to the targets (without targets: those without such a relation). */
    public function whereNotRelated(string $relation, CampanellaObject|int ...$targets): self
    {
        return $this->whereCondition(new RelatedTo($relation, self::targetIds($targets), negated: true));
    }

    public function whereCondition(Condition $condition): self
    {
        $query = clone $this;
        $query->where = $this->where->with($condition);

        return $query;
    }

    /** A named filter defined by a capability (e.g. 'published'). */
    public function scope(string $name): self
    {
        $query = clone $this;
        $query->scopes[] = $name;

        return $query;
    }

    public function orderBy(string $field, Direction|string $direction = Direction::Asc): self
    {
        $query = clone $this;
        $query->orderBy[] = [$field, Direction::parse($direction)];

        return $query;
    }

    public function limit(?int $limit): self
    {
        if ($limit !== null && $limit < 1) {
            throw new QueryException('The limit must be at least 1.');
        }
        $query = clone $this;
        $query->limit = $limit;

        return $query;
    }

    public function offset(int $offset): self
    {
        $query = clone $this;
        $query->offset = max(0, $offset);

        return $query;
    }

    /** Pagination: page 1 is the first. */
    /** A page of $perPage items; an absurdly large page number is an empty page, not an overflow. */
    public function page(int $page, int $perPage): self
    {
        $perPage = max(1, $perPage);
        $page = min(max(1, $page), intdiv(PHP_INT_MAX, $perPage));

        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    public function conditions(): Group
    {
        return $this->where;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /** A copy without the scopes (used by the QueryEngine after resolving them). */
    public function withoutScopes(): self
    {
        $query = clone $this;
        $query->scopes = [];

        return $query;
    }

    /** @return list<array{string, Direction}> */
    public function ordering(): array
    {
        return $this->orderBy;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * @param array<CampanellaObject|int> $targets
     * @return list<int>
     */
    private static function targetIds(array $targets): array
    {
        return array_values(array_unique(array_map(
            static fn (CampanellaObject|int $t): int => $t instanceof CampanellaObject
                ? ($t->id() ?? throw new QueryException('Only saved objects can be used in a relation condition.'))
                : $t,
            $targets,
        )));
    }
}
