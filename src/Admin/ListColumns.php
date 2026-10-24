<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Model\Blueprint;
use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Relation\Relation;
use Campanella\Search\SearchIndex;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The own columns of a Blueprint's list in the admin (since 0.2.8): its
 * `admin_columns`, at most 4 of its own fields and relations. A queryable
 * single-valued field is sortable; the queryable fields get a filter by their
 * type above the list, and a relation one of its targets (if it has at most
 * MAX_TARGETS):
 *
 * | Type | Filter | In the address |
 * |---|---|---|
 * | choice | one of the choices | `f[fajta]=seta` |
 * | boolean | yes or no | `f[aktiv]=1`, `f[aktiv]=0` |
 * | date, datetime | from, to (days, in the site's time zone) | `f[nap][from]=2026-11-01&f[nap][to]=2026-11-30` |
 * | integer, decimal | at least, at most | `f[ar][min]=1000&f[ar][max]=5000` |
 * | a relation | one of its targets | `f[kategoria]=12` |
 *
 * A value that is not valid for its field is ignored (and not shown back).
 */
final class ListColumns
{
    /** The most targets a relation's filter offers; with more it has none. */
    public const int MAX_TARGETS = 200;

    /** The longest text shown of a text field in a cell, in characters. */
    public const int TEXT_LENGTH = 80;

    public function __construct(
        private readonly QueryEngine $queries,
        private readonly DateTimeZone $timezone,
    ) {
    }

    /**
     * The Blueprint's own columns, in their order.
     *
     * @return list<array{name: string, label: string, kind: string, type: string, sortable: bool, filter: string|null}>
     */
    public function columns(Blueprint $blueprint): array
    {
        $columns = [];
        foreach ($blueprint->adminColumns as $name) {
            $field = $blueprint->fields[$name] ?? null;
            $relation = $blueprint->relations[$name] ?? null;
            if ($field !== null) {
                $queryable = $field->storage === FieldStorage::Table;
                $columns[] = [
                    'name' => $name,
                    'label' => $field->label !== '' ? $field->label : $name,
                    'kind' => 'field',
                    'type' => $field->type->value,
                    'sortable' => $queryable && !$field->isMultiple(),
                    'filter' => $queryable ? self::filterOf($field) : null,
                ];
            } elseif ($relation !== null) {
                $columns[] = [
                    'name' => $name,
                    'label' => $relation->label !== '' ? $relation->label : $name,
                    'kind' => 'relation',
                    'type' => 'relation',
                    'sortable' => false,
                    'filter' => 'relation',
                ];
            }
        }

        return $columns;
    }

    /**
     * Applies the filters of the request's `f` to the query.
     *
     * @param array<mixed> $input The request's `f` (query string)
     * @return array{0: Query, 1: array<string, string|array<string, string>>, 2: array<string, list<array{value: string, label: string}>>}
     *         The query, the valid values (for the form and the addresses), and the options of
     *         the choice and relation filters, by column
     */
    public function apply(Query $query, Blueprint $blueprint, array $input, Actor $actor): array
    {
        $values = [];
        $options = [];
        foreach ($this->columns($blueprint) as $column) {
            $name = $column['name'];
            $raw = $input[$name] ?? null;
            if ($column['filter'] === null) {
                continue;
            }
            if ($column['kind'] === 'relation') {
                $relation = $blueprint->relations[$name];
                $targets = $this->targets($relation, $actor);
                if ($targets === null) {
                    continue; // too many to offer
                }
                $options[$name] = $targets;
                if (is_string($raw) && in_array($raw, array_column($targets, 'value'), true)) {
                    $query = $query->whereRelated($name, (int) $raw);
                    $values[$name] = $raw;
                }
                continue;
            }
            $field = $blueprint->fields[$name];
            switch ($column['filter']) {
                case 'choice':
                    $options[$name] = array_map(static fn (string|int $key, string $label): array => ['value' => (string) $key, 'label' => $label], array_keys($field->choices), array_values($field->choices));
                    if (is_string($raw) && array_key_exists($raw, $field->choices)) {
                        $query = $query->where($name, '=', $raw);
                        $values[$name] = $raw;
                    }
                    break;
                case 'boolean':
                    if ($raw === '1' || $raw === '0') {
                        $query = $query->where($name, '=', $raw === '1');
                        $values[$name] = $raw;
                    }
                    break;
                case 'range_date':
                    $range = [];
                    foreach (['from' => '>=', 'to' => '<='] as $end => $op) {
                        $day = is_array($raw) && is_string($raw[$end] ?? null) ? self::day($raw[$end]) : null;
                        if ($day === null) {
                            continue;
                        }
                        $range[$end] = $day->format('Y-m-d');
                        if ($field->type === FieldType::Date) {
                            $query = $query->where($name, $op, $field->type->cast($range[$end]));
                        } else {
                            // A moment: from the day's start, to the next day's start, in the site's time zone.
                            $moment = new DateTimeImmutable($range[$end] . ' 00:00:00', $this->timezone);
                            $query = $end === 'from'
                                ? $query->where($name, '>=', $moment)
                                : $query->where($name, '<', $moment->modify('+1 day'));
                        }
                    }
                    if ($range !== []) {
                        $values[$name] = $range;
                    }
                    break;
                case 'range_number':
                    $range = [];
                    foreach (['min' => '>=', 'max' => '<='] as $end => $op) {
                        $text = is_array($raw) && is_string($raw[$end] ?? null) ? str_replace([' ', ','], ['', '.'], trim($raw[$end])) : '';
                        if ($text === '' || !is_numeric($text) || strlen($text) > 30) {
                            continue;
                        }
                        try {
                            $value = $field->type->cast($text);
                        } catch (\InvalidArgumentException) {
                            continue;
                        }
                        $query = $query->where($name, $op, $value);
                        $range[$end] = $text;
                    }
                    if ($range !== []) {
                        $values[$name] = $range;
                    }
                    break;
            }
        }

        return [$query, $values, $options];
    }

    /**
     * The short plain text of the text columns (text and formatted text) of the objects.
     *
     * @param iterable<CampanellaObject> $objects
     * @return array<int, array<string, string>> object ID => column => text
     */
    public function texts(iterable $objects, Blueprint $blueprint): array
    {
        $texts = [];
        foreach ($this->columns($blueprint) as $column) {
            if (!in_array($column['type'], [FieldType::Text->value, FieldType::Html->value], true)) {
                continue;
            }
            foreach ($objects as $object) {
                $value = $object->get($column['name']);
                $plain = '';
                foreach ((array) $value as $item) {
                    if (is_scalar($item)) {
                        $plain .= ($plain === '' ? '' : ' ') . ($column['type'] === FieldType::Html->value ? SearchIndex::fromHtml((string) $item) : trim(preg_replace('/\s+/u', ' ', (string) $item) ?? ''));
                    }
                }
                $texts[(int) $object->id()][$column['name']] = mb_strlen($plain, 'UTF-8') > self::TEXT_LENGTH
                    ? rtrim(mb_substr($plain, 0, self::TEXT_LENGTH - 1, 'UTF-8')) . '…'
                    : $plain;
            }
        }

        return $texts;
    }

    /** The filter of a queryable field, by its type; null for a type without one. */
    private static function filterOf(Field $field): ?string
    {
        return match ($field->type) {
            FieldType::Choice => 'choice',
            FieldType::Boolean => 'boolean',
            FieldType::Date, FieldType::DateTime => 'range_date',
            FieldType::Integer, FieldType::Decimal => 'range_number',
            default => null,
        };
    }

    /**
     * A relation's targets the actor may see, by title; null if there are more than MAX_TARGETS.
     *
     * @return list<array{value: string, label: string}>|null
     */
    private function targets(Relation $relation, Actor $actor): ?array
    {
        $query = Query::objects();
        if ($relation->targetBlueprints !== []) {
            $query = $query->blueprint(...$relation->targetBlueprints);
        }
        if ($relation->targetCapabilities !== []) {
            $query = $query->having(...$relation->targetCapabilities);
        }
        $found = $this->queries->execute($query->orderBy('id')->limit(self::MAX_TARGETS + 1), $actor)->items;
        if (count($found) > self::MAX_TARGETS) {
            return null;
        }
        $targets = array_map(static fn (CampanellaObject $o): array => [
            'value' => (string) $o->id(),
            'label' => $o->hasField('title') && (string) $o->get('title') !== '' ? (string) $o->get('title') : '#' . $o->id(),
        ], $found);
        usort($targets, static fn (array $a, array $b): int => strcmp(mb_strtolower($a['label'], 'UTF-8'), mb_strtolower($b['label'], 'UTF-8')));

        return $targets;
    }

    /** A day typed as YYYY-MM-DD, or null. */
    private static function day(string $text): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($text));

        return $day !== false && $day->format('Y-m-d') === trim($text) ? $day : null;
    }
}
