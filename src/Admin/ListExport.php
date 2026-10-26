<?php

declare(strict_types=1);

namespace Campanella\Admin;

use Campanella\Capability\Publishable;
use Campanella\Import\ImporterDefinition;
use Campanella\Model\Blueprint;
use Campanella\Model\CalendarDate;
use Campanella\Model\CampanellaObject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Relation\Relation;
use DateTimeInterface;
use DateTimeZone;

/**
 * The admin's list of a Blueprint as a CSV file (since 0.3.1), in the form an
 * importer reads: a header row of the machine names, `;` between the values, UTF-8
 * with a byte order mark (so Excel opens it as UTF-8), a line break CRLF.
 *
 * The columns: `id`, the importer's targets (`title`, `body`, `path`,
 * `published_at`, the own fields, the relations), then `status`, `created`,
 * `updated`. A date as `2026-11-05`, a moment in the site's time zone as
 * `2026-11-05 18:00:00`, a decimal with a point, yes/no as 1 or 0, a choice by its
 * key, several values (and a relation's targets, by title) joined by `|`.
 *
 * A text that starts with `=`, `+`, `-`, `@`, a tab or a line break gets an
 * apostrophe in front, so a spreadsheet does not take it as a formula ("CSV
 * injection"); numbers and dates never start so.
 */
final class ListExport
{
    /** The most objects exported at once. */
    public const int MAX_ROWS = 20000;

    public const string SEPARATOR = '|';

    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    /**
     * The header row's columns.
     *
     * @return list<string>
     */
    public function columns(Blueprint $blueprint): array
    {
        $columns = ['id', ...array_keys(ImporterDefinition::targets($blueprint))];
        if (isset($blueprint->capabilities['publishable'])) {
            $columns[] = 'status';
        }

        return [...$columns, 'created', 'updated'];
    }

    /**
     * The file's contents.
     *
     * @param iterable<CampanellaObject> $objects With their relations resolved (their targets' titles)
     */
    public function csv(Blueprint $blueprint, iterable $objects): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write the CSV.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $this->columns($blueprint), ';', '"', '', "\r\n");
        $targets = ImporterDefinition::targets($blueprint);
        foreach ($objects as $object) {
            $row = [(string) $object->id()];
            foreach ($targets as $name => $target) {
                $row[] = $target instanceof Relation ? self::safe($this->related($object, $name)) : $this->value($object, $target);
            }
            if (isset($blueprint->capabilities['publishable'])) {
                $row[] = $object->as(Publishable::class)->status()->value;
            }
            $row[] = $this->moment($object->created());
            $row[] = $this->moment($object->updated());
            fputcsv($handle, $row, ';', '"', '', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function value(CampanellaObject $object, Field $field): string
    {
        if (!$object->hasField($field->name)) {
            return '';
        }
        $value = $object->get($field->name);
        $values = $field->isMultiple() ? (array) $value : [$value];
        $texts = array_map(fn (mixed $v): string => $this->one($field, $v), $values);
        $text = implode(self::SEPARATOR, array_filter($texts, static fn (string $t): bool => $t !== ''));

        return in_array($field->type, [FieldType::Integer, FieldType::Decimal, FieldType::Boolean, FieldType::Date, FieldType::DateTime], true)
            ? $text
            : self::safe($text);
    }

    private function one(Field $field, mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof CalendarDate => $value->format('Y-m-d'),
            $value instanceof DateTimeInterface => $this->moment($value),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private function moment(DateTimeInterface $moment): string
    {
        return \DateTimeImmutable::createFromInterface($moment)->setTimezone($this->timezone)->format('Y-m-d H:i:s');
    }

    /** A relation's targets, by title (by ID if one has none). */
    private function related(CampanellaObject $object, string $relation): string
    {
        if (!$object->isResolved($relation)) {
            return implode(self::SEPARATOR, $object->relatedIds($relation));
        }

        return implode(self::SEPARATOR, array_map(
            static fn (CampanellaObject $t): string => $t->hasField('title') && (string) $t->get('title') !== '' ? (string) $t->get('title') : (string) $t->id(),
            $object->relatedObjects($relation),
        ));
    }

    /** A text a spreadsheet would read as a formula gets an apostrophe in front. */
    public static function safe(string $text): string
    {
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true) ? "'" . $text : $text;
    }
}
