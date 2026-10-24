<?php

declare(strict_types=1);

namespace Campanella\Lists;

use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintDefinition;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\CalendarDate;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;
use Campanella\Database\Schema\ColumnType;
use Campanella\Capability\Routable;
use Campanella\Query\FieldFilters;
use Campanella\Query\Query;
use Campanella\Relation\Relation;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A list made in the admin (since 0.2.5): which objects, in what order, how many
 * per page, shown how, and at which path. Checked against the Blueprints when it
 * is made, and every time it is loaded. The stored form:
 *
 * ```json
 * {
 *   "version": 1,
 *   "title": "Közelgő események",
 *   "blueprint": "esemeny",
 *   "conditions": [
 *     {"field": "nap", "op": ">=", "relative": "today"},
 *     {"field": "fajta", "op": "=", "value": "eloadas"},
 *     {"relation": "kategoria", "targets": [12]}
 *   ],
 *   "sort": [{"field": "nap", "dir": "asc"}],
 *   "per_page": 10,
 *   "display": "table",
 *   "columns": ["nap", "fajta"],
 *   "path": "/esemenyek",
 *   "filters": ["fajta", "nap"]
 * }
 * ```
 *
 * Conditions and sorting only on queryable fields (they have columns), and on
 * `created` and `updated`. `relative` is `today` or `now`, worked out on every
 * request in the site's time zone. The Query engine adds the visitor's limits
 * (e.g. only published objects for visitors) as to any query; a Publishable
 * Blueprint's list shows only the published ones to everyone.
 *
 * `filters` (since 0.2.9): at most MAX_FILTERS queryable fields (of a type that has
 * a filter: FieldFilters::kindOf()) and relations the visitor may filter the list by,
 * on its page, added to its own conditions.
 */
final readonly class ListDefinition
{
    public const int VERSION = 1;

    /** The displays: the objects' short cards, or a table of chosen fields. */
    public const array DISPLAYS = ['teaser', 'table'];

    /** The condition operators. */
    public const array OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'contains', 'empty', 'not_empty'];

    /** The changing values of a date condition. */
    public const array RELATIVE = ['today', 'now'];

    public const int MAX_PER_PAGE = 100;
    public const int MAX_CONDITIONS = 10;
    public const int MAX_SORT = 3;
    public const int MAX_COLUMNS = 8;
    public const int MAX_TITLE = 200;
    public const int MAX_FILTERS = 4;

    /**
     * @param list<array<string, mixed>> $conditions As stored, checked
     * @param list<array{field: string, dir: string}> $sort
     * @param list<string> $columns
     * @param list<string> $filters The fields and relations visitors may filter by (since 0.2.9)
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $blueprint,
        public array $conditions,
        public array $sort,
        public int $perPage,
        public string $display,
        public array $columns,
        public ?string $path,
        public array $filters = [],
    ) {
    }

    /**
     * A definition from its stored form, checked against the Blueprints.
     *
     * @param array<string, mixed> $stored
     * @throws InvalidArgumentException for anything that does not pass
     */
    public static function fromArray(string $name, array $stored, BlueprintRegistry $blueprints): self
    {
        BlueprintDefinition::name($name);
        $title = $stored['title'] ?? null;
        if (!is_string($title) || trim($title) === '' || mb_strlen($title, 'UTF-8') > self::MAX_TITLE || preg_match('/[\x00-\x1f\x7f]/', $title) === 1) {
            throw new InvalidArgumentException('Invalid title.');
        }
        $blueprint = $blueprints->find(is_string($stored['blueprint'] ?? null) ? $stored['blueprint'] : '')
            ?? throw new InvalidArgumentException('Unknown Blueprint: ' . json_encode($stored['blueprint'] ?? null));
        if (isset($blueprint->capabilities['authenticatable']) || isset($blueprint->capabilities['submitted'])) {
            throw new InvalidArgumentException("The objects of {$blueprint->name} cannot be listed.");
        }

        $conditions = [];
        foreach (self::listOf($stored['conditions'] ?? [], 'conditions', self::MAX_CONDITIONS) as $condition) {
            $conditions[] = self::condition($blueprint, is_array($condition) ? $condition : throw new InvalidArgumentException('A condition must be an object.'));
        }
        $sort = [];
        foreach (self::listOf($stored['sort'] ?? [], 'sort', self::MAX_SORT) as $item) {
            $field = is_array($item) && is_string($item['field'] ?? null) ? self::queryable($blueprint, $item['field']) : null;
            if ($field === null || $field->isMultiple()) {
                throw new InvalidArgumentException('A list can be sorted only by a queryable single-valued field: ' . json_encode($item));
            }
            $sort[] = ['field' => $field->name, 'dir' => ($item['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
        }
        $perPage = $stored['per_page'] ?? 10;
        if (!is_int($perPage) || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException('The number per page is 1 to ' . self::MAX_PER_PAGE . '.');
        }
        $display = $stored['display'] ?? 'teaser';
        if (!in_array($display, self::DISPLAYS, true)) {
            throw new InvalidArgumentException('Unknown display: ' . json_encode($display));
        }
        $columns = [];
        foreach (self::listOf($stored['columns'] ?? [], 'columns', self::MAX_COLUMNS) as $column) {
            $field = is_string($column) ? ($blueprint->allFields()[$column] ?? null) : null;
            if ($field === null || $field->hidden) {
                throw new InvalidArgumentException('Unknown column: ' . json_encode($column));
            }
            $columns[] = $field->name;
        }
        $path = $stored['path'] ?? null;
        if ($path !== null) {
            $path = self::path($path);
        }
        $filters = [];
        foreach (self::listOf($stored['filters'] ?? [], 'filters', self::MAX_FILTERS) as $filter) {
            if (!is_string($filter) || self::filter($blueprint, $filter) === null || in_array($filter, $filters, true)) {
                throw new InvalidArgumentException('A list can be filtered only by a queryable field (a choice, yes/no, a date, a number) or a relation: ' . json_encode($filter));
            }
            $filters[] = $filter;
        }

        return new self($name, trim($title), $blueprint->name, $conditions, $sort, $perPage, $display, array_values(array_unique($columns)), $path, $filters);
    }

    /** @return array<string, mixed> The stored form. */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'title' => $this->title,
            'blueprint' => $this->blueprint,
            'conditions' => $this->conditions,
            'sort' => $this->sort,
            'per_page' => $this->perPage,
            'display' => $this->display,
            'columns' => $this->columns,
            'path' => $this->path,
            'filters' => $this->filters,
        ];
    }

    /**
     * The Query of the list, at the given moment (for `today` and `now`), without paging.
     */
    public function query(BlueprintRegistry $blueprints, DateTimeZone $timezone, ?DateTimeImmutable $now = null): Query
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $blueprint = $blueprints->get($this->blueprint);
        $query = Query::objects()->blueprint($this->blueprint);
        if (isset($blueprint->capabilities['publishable'])) {
            $query = $query->scope('published');
        }
        foreach ($this->conditions as $condition) {
            if (isset($condition['relation'])) {
                $query = $query->whereRelated((string) $condition['relation'], ...array_map(intval(...), (array) $condition['targets']));
                continue;
            }
            $field = self::queryable($blueprint, (string) $condition['field']) ?? throw new InvalidArgumentException('Unknown field.');
            $op = (string) $condition['op'];
            $value = isset($condition['relative'])
                ? self::relative((string) $condition['relative'], $field, $timezone, $now)
                : ($condition['value'] ?? null);
            $query = match ($op) {
                'empty' => $query->where($field->name, 'IS NULL'),
                'not_empty' => $query->where($field->name, 'IS NOT NULL'),
                'contains' => $query->where($field->name, 'LIKE', '%' . addcslashes((string) $value, '%_\\') . '%'),
                default => $query->where($field->name, $op, $value),
            };
        }
        foreach ($this->sort as $item) {
            $query = $query->orderBy($item['field'], $item['dir']);
        }
        if ($this->sort === []) {
            $query = $query->orderBy('created', 'desc');
        }

        return $query->limit($this->perPage);
    }

    /**
     * A field a list may filter or sort by: `created`, `updated`, or a queryable field of the Blueprint.
     */
    public static function queryable(Blueprint $blueprint, string $name): ?Field
    {
        if ($name === 'created' || $name === 'updated') {
            return new Field($name, FieldType::DateTime, label: 'list.field.' . $name);
        }
        $field = $blueprint->allFields()[$name] ?? null;

        return $field !== null && $field->storage === FieldStorage::Table && !$field->hidden ? $field : null;
    }

    /**
     * A field or relation visitors may filter a list by (since 0.2.9): a queryable field
     * of a type with a filter (FieldFilters::kindOf()), or a relation; null otherwise.
     */
    public static function filter(Blueprint $blueprint, string $name): Field|Relation|null
    {
        $field = $blueprint->allFields()[$name] ?? null;
        if ($field !== null) {
            // Not the publication status: a list shows only what is published anyway.
            return FieldFilters::kindOf($field) !== null && $name !== 'status' ? $field : null;
        }

        return $blueprint->allRelations()[$name] ?? null;
    }

    /**
     * A list's path: lowercase, starting with a slash, a few safe characters.
     *
     * @throws InvalidArgumentException
     */
    public static function path(mixed $path): string
    {
        if (!is_string($path)) {
            throw new InvalidArgumentException('Invalid path.');
        }
        $path = Routable::normalize($path);
        if ($path === '/' || mb_strlen($path, 'UTF-8') > 200 || !Routable::isSafePath($path) || preg_match('#^(/[\p{Ll}\p{N}_.-]+)+$#u', $path) !== 1) {
            throw new InvalidArgumentException('Invalid path: ' . json_encode($path));
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $condition
     * @return array<string, mixed> The checked condition, as stored
     */
    private static function condition(Blueprint $blueprint, array $condition): array
    {
        if (isset($condition['relation'])) {
            $relation = is_string($condition['relation']) ? ($blueprint->allRelations()[$condition['relation']] ?? null) : null;
            if ($relation === null) {
                throw new InvalidArgumentException('Unknown relation: ' . json_encode($condition['relation']));
            }
            $targets = [];
            foreach (is_array($condition['targets'] ?? null) ? $condition['targets'] : [] as $target) {
                if (!is_int($target) || $target < 1) {
                    throw new InvalidArgumentException('A relation condition needs object IDs.');
                }
                $targets[] = $target;
            }
            if ($targets === []) {
                throw new InvalidArgumentException('A relation condition needs a target.');
            }

            return ['relation' => $relation->name, 'targets' => array_values(array_unique($targets))];
        }

        $field = is_string($condition['field'] ?? null) ? self::queryable($blueprint, $condition['field']) : null;
        if ($field === null) {
            throw new InvalidArgumentException('A list can filter only by a queryable field: ' . json_encode($condition['field'] ?? null));
        }
        $op = $condition['op'] ?? null;
        if (!in_array($op, self::OPERATORS, true)) {
            throw new InvalidArgumentException('Unknown operator: ' . json_encode($op));
        }
        $text = in_array($field->type->columnType(), [ColumnType::String, ColumnType::Text], true);
        if ($op === 'contains' && !$text) {
            throw new InvalidArgumentException("'contains' is for texts.");
        }
        if (in_array($op, ['<', '<=', '>', '>='], true) && ($text || $field->type === FieldType::Boolean)) {
            throw new InvalidArgumentException("{$op} is for numbers and dates.");
        }
        if ($op === 'empty' || $op === 'not_empty') {
            return ['field' => $field->name, 'op' => $op];
        }
        if (isset($condition['relative'])) {
            if (!in_array($condition['relative'], self::RELATIVE, true) || !in_array($field->type, [FieldType::Date, FieldType::DateTime], true)) {
                throw new InvalidArgumentException('Today and now are for dates.');
            }

            return ['field' => $field->name, 'op' => $op, 'relative' => $condition['relative']];
        }
        $value = $condition['value'] ?? null;
        if (!is_scalar($value) || $value === '') {
            throw new InvalidArgumentException("The condition on {$field->name} needs a value.");
        }
        if ($op !== 'contains') {
            try {
                $cast = $field->type->cast($value);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException("Not a value of {$field->name}: " . json_encode($value));
            }
            if ($field->type === FieldType::Choice && !array_key_exists((string) $cast, $field->choices)) {
                throw new InvalidArgumentException("Not a choice of {$field->name}: " . json_encode($value));
            }
            $value = $field->type->toStorage($cast);
        }

        return ['field' => $field->name, 'op' => $op, 'value' => $value];
    }

    /** `today` or `now` as a value of the field: a day, or a moment (today: its midnight in the time zone). */
    private static function relative(string $relative, Field $field, DateTimeZone $timezone, DateTimeImmutable $now): CalendarDate|DateTimeImmutable
    {
        if ($field->type === FieldType::Date) {
            return CalendarDate::of($now, $timezone);
        }
        if ($relative === 'today') {
            return $now->setTimezone($timezone)->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
        }

        return $now;
    }

    /**
     * @return list<mixed>
     * @throws InvalidArgumentException
     */
    private static function listOf(mixed $value, string $key, int $max): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            throw new InvalidArgumentException("'{$key}' must be a list of at most {$max}.");
        }

        return $value;
    }
}
