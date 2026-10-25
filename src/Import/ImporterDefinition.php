<?php

declare(strict_types=1);

namespace Campanella\Import;

use Campanella\Model\Blueprint;
use Campanella\Model\BlueprintDefinition;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\Field;
use Campanella\Relation\Relation;
use InvalidArgumentException;

/**
 * An importer (since 0.3.1): which objects a source's items become, and how. Made in
 * the admin (System → Importers), stored as JSON in the `importers` table
 * (ImporterStore), checked against the Blueprints when it is made and every time it
 * is loaded. The stored form:
 *
 * ```json
 * {
 *   "version": 1,
 *   "label": "Programok a táblázatból",
 *   "blueprint": "esemeny",
 *   "format": "csv",
 *   "delimiter": "auto",
 *   "items": "",
 *   "key": "Azonosító",
 *   "mapping": [
 *     {"source": "Cím", "target": "title"},
 *     {"source": "Kezdés", "target": "kezdes"},
 *     {"source": "Címkék", "target": "cimkek", "separator": "|"},
 *     {"source": "Helyszín", "target": "helyszin"}
 *   ],
 *   "publish": true,
 *   "missing_target": "error",
 *   "missing_items": "keep",
 *   "user": 1
 * }
 * ```
 *
 * - `format`: `csv` (a header row; `delimiter`: `auto`, `;`, `,` or `tab`; UTF-8) or
 *   `json` (`items`: the dotted path of the list of items in the file, empty: the file
 *   is the list; a source can be a dotted path within an item, e.g. `venue.name`).
 * - `key`: the source of the item's unique key: a later run updates the object made
 *   from it instead of making another.
 * - `mapping`: a source to a target: the title, the body, the path, the publication
 *   time, an own field or a relation of the Blueprint (each target once; at most
 *   MAX_MAPPINGS). Several values of a multi-valued field or a relation are split by
 *   `separator` (in a CSV; a JSON list is taken as it is).
 * - `publish`: whether the new objects are published at once (a Publishable Blueprint).
 * - `missing_target`: a relation's target that is not found (by machine name, path or
 *   title): `error` (the item fails), `skip` (imported without the link) or `create`
 *   (the target is made, with the value as its title).
 * - `missing_items`: an object whose item is not in a later run's source: `keep` it,
 *   or make it a `draft`.
 * - `user`: the user in whose name, and with whose permissions, the objects are made.
 */
final readonly class ImporterDefinition
{
    public const int VERSION = 1;

    public const array FORMATS = ['csv', 'json'];

    public const array DELIMITERS = ['auto', ';', ',', 'tab'];

    public const array MISSING_TARGET = ['error', 'skip', 'create'];

    public const array MISSING_ITEMS = ['keep', 'draft'];

    public const int MAX_MAPPINGS = 50;

    public const int MAX_LABEL = 200;

    /** The longest source name (a column's heading, or a dotted path). */
    public const int MAX_SOURCE = 200;

    /** The core fields that can be targets, besides the Blueprint's own fields and relations. */
    public const array CORE_TARGETS = ['title', 'body', 'path', 'published_at'];

    /**
     * @param list<ImportMapping> $mapping
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $blueprint,
        public string $format,
        public string $delimiter,
        public string $items,
        public string $key,
        public array $mapping,
        public bool $publish,
        public string $missingTarget,
        public string $missingItems,
        public int $user,
    ) {
    }

    /**
     * The definition of a stored or posted form, checked against the Blueprints.
     *
     * @param array<string, mixed> $stored
     * @throws InvalidArgumentException with what is wrong
     */
    public static function fromArray(string $name, array $stored, BlueprintRegistry $blueprints): self
    {
        BlueprintDefinition::name($name); // the same rules as a Blueprint's and a list's
        $label = trim(self::string($stored['label'] ?? $name, 'label'));
        if ($label === '' || mb_strlen($label, 'UTF-8') > self::MAX_LABEL) {
            throw new InvalidArgumentException('The label is empty or too long.');
        }
        $blueprint = $blueprints->find(self::string($stored['blueprint'] ?? '', 'blueprint'));
        if ($blueprint === null || !self::importable($blueprint)) {
            throw new InvalidArgumentException('Unknown Blueprint: ' . json_encode($stored['blueprint'] ?? null));
        }
        $format = self::oneOf($stored['format'] ?? 'csv', self::FORMATS, 'format');
        $delimiter = self::oneOf($stored['delimiter'] ?? 'auto', self::DELIMITERS, 'delimiter');
        $items = trim(self::string($stored['items'] ?? '', 'items'));
        if (mb_strlen($items, 'UTF-8') > self::MAX_SOURCE || ($items !== '' && preg_match('/^[^.\s][^\s]*$/u', $items) !== 1)) {
            throw new InvalidArgumentException('Invalid items path.');
        }
        $key = self::source($stored['key'] ?? '');

        $mapping = [];
        $targets = [];
        $list = $stored['mapping'] ?? [];
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_MAPPINGS) {
            throw new InvalidArgumentException('The mapping is a list of at most ' . self::MAX_MAPPINGS . '.');
        }
        foreach ($list as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid mapping.');
            }
            $target = self::string($item['target'] ?? '', 'target');
            if (self::target($blueprint, $target) === null) {
                throw new InvalidArgumentException('Unknown target: ' . json_encode($target));
            }
            if (isset($targets[$target])) {
                throw new InvalidArgumentException('A target mapped twice: ' . $target);
            }
            $targets[$target] = true;
            $separator = self::string($item['separator'] ?? '|', 'separator');
            if ($separator === '' || mb_strlen($separator, 'UTF-8') > 5 || trim($separator) === '' && $separator !== "\t") {
                throw new InvalidArgumentException('Invalid separator.');
            }
            $mapping[] = new ImportMapping(self::source($item['source'] ?? ''), $target, $separator);
        }
        if ($mapping === []) {
            throw new InvalidArgumentException('Nothing is mapped.');
        }
        if (isset($blueprint->capabilities['titled']) && !isset($targets['title'])) {
            throw new InvalidArgumentException('The title must be mapped.');
        }
        $user = $stored['user'] ?? null;
        if (!is_int($user) || $user < 1) {
            throw new InvalidArgumentException('Invalid user.');
        }

        return new self(
            $name,
            $label,
            $blueprint->name,
            $format,
            $delimiter,
            $items,
            $key,
            $mapping,
            (bool) ($stored['publish'] ?? false),
            self::oneOf($stored['missing_target'] ?? 'error', self::MISSING_TARGET, 'missing_target'),
            self::oneOf($stored['missing_items'] ?? 'keep', self::MISSING_ITEMS, 'missing_items'),
            $user,
        );
    }

    /** @return array<string, mixed> The stored form */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'label' => $this->label,
            'blueprint' => $this->blueprint,
            'format' => $this->format,
            'delimiter' => $this->delimiter,
            'items' => $this->items,
            'key' => $this->key,
            'mapping' => array_map(static fn (ImportMapping $m): array => $m->toArray(), $this->mapping),
            'publish' => $this->publish,
            'missing_target' => $this->missingTarget,
            'missing_items' => $this->missingItems,
            'user' => $this->user,
        ];
    }

    /**
     * Whether a Blueprint's objects can be imported: content, not users, files, forms'
     * messages or menus' links (those have their own pages).
     */
    public static function importable(Blueprint $blueprint): bool
    {
        foreach (['authenticatable', 'media_file', 'submitted', 'keyed', 'link'] as $capability) {
            if (isset($blueprint->capabilities[$capability])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The targets a Blueprint offers: the core fields it has, its own fields (not
     * hidden), and its relations to the objects of other Blueprints.
     *
     * @return array<string, Field|Relation> name => field or relation, in this order
     */
    public static function targets(Blueprint $blueprint): array
    {
        $targets = [];
        $fields = $blueprint->allFields();
        foreach (self::CORE_TARGETS as $name) {
            if (isset($fields[$name])) {
                $targets[$name] = $fields[$name];
            }
        }
        foreach ($blueprint->fields as $name => $field) {
            if (!$field->hidden) {
                $targets[$name] = $field;
            }
        }
        foreach ($blueprint->relations as $name => $relation) {
            if ($relation->targetBlueprints !== []) {
                $targets[$name] = $relation;
            }
        }

        return $targets;
    }

    /** A target of the Blueprint, or null if it has none of that name. */
    public static function target(Blueprint $blueprint, string $name): Field|Relation|null
    {
        return self::targets($blueprint)[$name] ?? null;
    }

    private static function source(mixed $value): string
    {
        $source = trim(self::string($value, 'source'));
        if ($source === '' || mb_strlen($source, 'UTF-8') > self::MAX_SOURCE || preg_match('/[\x00-\x1f\x7f]/', $source) === 1) {
            throw new InvalidArgumentException('Invalid source: ' . json_encode(mb_substr($source, 0, 50)));
        }

        return $source;
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $what): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Invalid ' . $what . ': ' . json_encode($value));
        }

        return $value;
    }

    private static function string(mixed $value, string $what): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid ' . $what . '.');
        }

        return $value;
    }
}
