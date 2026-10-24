<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Access\Actor;
use Campanella\Model\Blueprint;
use Campanella\Model\CampanellaObject;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;
use Campanella\Query\FieldFilters;
use Campanella\Query\Query;
use Campanella\Search\SearchIndex;

/**
 * The own columns of a Blueprint's list in the admin (since 0.2.8): its
 * `admin_columns`, at most 4 of its own fields and relations. A queryable
 * single-valued field is sortable; the queryable fields get a filter by their
 * type above the list, and a relation one of its targets (FieldFilters, since
 * 0.2.9 shared with the visitors' filters of the lists).
 */
final class ListColumns
{
    /** The longest text shown of a text field in a cell, in characters. */
    public const int TEXT_LENGTH = 80;

    public function __construct(private readonly FieldFilters $filters)
    {
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
                $columns[] = [
                    'name' => $name,
                    'label' => $field->label !== '' ? $field->label : $name,
                    'kind' => 'field',
                    'type' => $field->type->value,
                    'sortable' => $field->storage === FieldStorage::Table && !$field->isMultiple(),
                    'filter' => FieldFilters::kindOf($field),
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
     * Applies the filters of the request's `f` to the query (FieldFilters::apply()).
     *
     * @param array<mixed> $input The request's `f` (query string)
     * @return array{0: Query, 1: array<string, string|array<string, string>>, 2: array<string, list<array{value: string, label: string}>>}
     */
    public function apply(Query $query, Blueprint $blueprint, array $input, Actor $actor): array
    {
        $filters = [];
        foreach ($this->columns($blueprint) as $column) {
            if ($column['filter'] !== null) {
                $filters[] = $blueprint->fields[$column['name']] ?? $blueprint->relations[$column['name']];
            }
        }

        return $this->filters->apply($query, $filters, $input, $actor);
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
}
