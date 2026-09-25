<?php

declare(strict_types=1);

namespace Campanella\Query;

use Campanella\Query\Condition\Condition;
use Campanella\Query\Condition\FieldCondition;
use Campanella\Query\Condition\Group;
use Campanella\Query\Condition\HasCapability;

/**
 * Deklaratív lekérdezés: „milyen objektumokat szeretnék?”
 *
 *     Query::objects()
 *         ->having('textual', 'routable', 'publishable')
 *         ->scope('published')
 *         ->orderBy('published_at', 'DESC')
 *         ->limit(10);
 *
 * Megváltoztathatatlan: minden metódus új példányt ad vissza, így egy
 * Query-definíció biztonságosan újrahasználható és kiegészíthető
 * (a jogosultsági réteg is így fűzi hozzá a saját feltételeit).
 *
 * Alapmezők: id, uuid, blueprint, created, updated. Minden más mező
 * valamelyik capability-hez tartozik; szűrni és rendezni csak a saját
 * táblában tárolt (FieldStorage::Table) mezőkre lehet.
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

    /** Csak azok az objektumok, amelyek mindegyik capability-vel rendelkeznek. */
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
            throw new QueryException("A(z) {$operator->value} operátor nem üres listát vár.");
        }

        return $this->whereCondition(new FieldCondition($field, $operator, $value));
    }

    public function whereCondition(Condition $condition): self
    {
        $query = clone $this;
        $query->where = $this->where->with($condition);

        return $query;
    }

    /** Capability által definiált, elnevezett szűrő (pl. 'published'). */
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
            throw new QueryException('A limit legalább 1.');
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

    /** Lapozás: az 1. oldal az első. */
    public function page(int $page, int $perPage): self
    {
        return $this->limit($perPage)->offset((max(1, $page) - 1) * $perPage);
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

    /** A scope-ok nélküli másolat (a QueryEngine használja feloldás után). */
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
}
