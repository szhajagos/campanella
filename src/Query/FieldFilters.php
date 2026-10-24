<?php

declare(strict_types=1);

namespace Campanella\Query;

use Campanella\Access\Actor;
use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;
use Campanella\Relation\Relation;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Filters by a field's or a relation's value, typed in a form (since 0.2.9; in the
 * admin's lists since 0.2.8): the admin's own columns (Admin\ListColumns) and the
 * visitors' filters of a list made in the admin (Lists\ListDefinition::$filters).
 * Only queryable fields have one, by their type, and relations:
 *
 * | Kind | Type | Filter | In the address |
 * |---|---|---|---|
 * | `choice` | choice | one of the choices | `f[fajta]=seta` |
 * | `boolean` | boolean | yes or no | `f[aktiv]=1`, `f[aktiv]=0` |
 * | `range_date` | date, datetime | from, to (days, in the site's time zone) | `f[nap][from]=2026-11-01&f[nap][to]=2026-11-30` |
 * | `range_number` | integer, decimal | at least, at most | `f[ar][min]=1000&f[ar][max]=5000` |
 * | `relation` | a relation | one of its targets the actor may see | `f[kategoria]=12` |
 *
 * A value that is not valid for its field is ignored (and not given back).
 */
final class FieldFilters
{
    /** The most targets a relation's filter offers; with more it has none. */
    public const int MAX_TARGETS = 200;

    public function __construct(
        private readonly QueryEngine $queries,
        private readonly DateTimeZone $timezone,
    ) {
    }

    /** A field's filter kind: by its type, for a queryable field; null if it has none. */
    public static function kindOf(Field $field): ?string
    {
        if ($field->storage !== FieldStorage::Table || $field->hidden) {
            return null;
        }

        return match ($field->type) {
            FieldType::Choice => 'choice',
            FieldType::Boolean => 'boolean',
            FieldType::Date, FieldType::DateTime => 'range_date',
            FieldType::Integer, FieldType::Decimal => 'range_number',
            default => null,
        };
    }

    /**
     * Applies the filters to the query, by the form's values (the request's `f`).
     *
     * @param list<Field|Relation> $filters The fields (with a kindOf()) and relations filtered by
     * @param array<mixed> $input The request's `f`
     * @return array{0: Query, 1: array<string, string|array<string, string>>, 2: array<string, list<array{value: string, label: string}>>}
     *         The query, the valid values (for the form and the addresses), and the options of
     *         the choice and relation filters, by name (a relation with too many targets has none)
     */
    public function apply(Query $query, array $filters, array $input, Actor $actor): array
    {
        $values = [];
        $options = [];
        foreach ($filters as $filter) {
            $name = $filter->name;
            $raw = $input[$name] ?? null;
            if ($filter instanceof Relation) {
                $targets = $this->targets($filter, $actor);
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
            $field = $filter;
            switch (self::kindOf($field)) {
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
