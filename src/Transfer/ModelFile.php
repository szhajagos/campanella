<?php

declare(strict_types=1);

namespace Campanella\Transfer;

use InvalidArgumentException;
use JsonException;

/**
 * The file of the content model (since 0.2.6): the Blueprints and lists made in
 * the admin, as JSON, to carry them from one site to another.
 *
 * ```json
 * {
 *   "format": "campanella.content-model",
 *   "version": 1,
 *   "campanella": "0.2.6",
 *   "exported_at": "2026-10-23T14:47:00Z",
 *   "blueprints": {"esemeny": {"version": 1, "label": "Esemény", "capabilities": ["titled"], "fields": [], "relations": []}},
 *   "lists": {"esemenyek": {"version": 1, "title": "Események", "blueprint": "esemeny", "conditions": [], "sort": [], "per_page": 10, "display": "teaser", "columns": [], "path": "/esemenyek"}}
 * }
 * ```
 *
 * A Blueprint is in its stored form (BlueprintDefinition), a list too
 * (ListDefinition), except that a relation condition names its objects by
 * reference instead of by ID (an ID means another object on another site):
 * `{"blueprint": "category", "machine_name": "…", "path": "/hirek", "title": "Hírek"}`,
 * each key only if the object has it. The Blueprints of config/blueprints.php are
 * not in the file: they come with the code.
 */
final class ModelFile
{
    public const string FORMAT = 'campanella.content-model';

    /** The format's version; a newer one is not read. */
    public const int VERSION = 1;

    /** The largest file read (1 MB). */
    public const int MAX_BYTES = 1_048_576;

    /** The most Blueprints, and the most lists, in a file. */
    public const int MAX_ITEMS = 200;

    /** How deep the JSON may nest. */
    private const int MAX_DEPTH = 16;

    /** The suggested file name. */
    public const string FILE_NAME = 'campanella-model.json';

    /**
     * The file's contents, its frame checked (the items are checked by the import).
     *
     * @return array{blueprints: array<string, array<string, mixed>>, lists: array<string, array<string, mixed>>, campanella: string, exported_at: string}
     * @throws InvalidArgumentException for a file that is not a content model
     */
    public static function decode(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The file is larger than 1 MB.');
        }
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3); // a byte order mark, as some editors save it
        }
        try {
            $data = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Not a valid JSON file: ' . $e->getMessage());
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Not a Campanella content model file.');
        }
        $version = $data['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new InvalidArgumentException('The file has no valid format version.');
        }
        if ($version > self::VERSION) {
            throw new InvalidArgumentException("The file was made by a newer Campanella (format {$version}); upgrade this site first.");
        }

        return [
            'blueprints' => self::items($data['blueprints'] ?? [], 'blueprints'),
            'lists' => self::items($data['lists'] ?? [], 'lists'),
            'campanella' => is_string($data['campanella'] ?? null) ? mb_substr($data['campanella'], 0, 40) : '',
            'exported_at' => is_string($data['exported_at'] ?? null) ? mb_substr($data['exported_at'], 0, 40) : '',
        ];
    }

    /**
     * @param array{blueprints: array<string, array<string, mixed>>, lists: array<string, array<string, mixed>>, campanella: string, exported_at: string} $model
     */
    public static function encode(array $model): string
    {
        return json_encode(
            [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'campanella' => $model['campanella'],
                'exported_at' => $model['exported_at'],
                'blueprints' => $model['blueprints'] === [] ? new \stdClass() : $model['blueprints'],
                'lists' => $model['lists'] === [] ? new \stdClass() : $model['lists'],
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    /**
     * @return array<string, array<string, mixed>>
     * @throws InvalidArgumentException
     */
    private static function items(mixed $items, string $key): array
    {
        // An empty JSON object decodes to an empty array, like an empty list.
        if (!is_array($items) || ($items !== [] && array_is_list($items))) {
            throw new InvalidArgumentException("'{$key}' must be an object of names.");
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException(sprintf("At most %d items in '%s'.", self::MAX_ITEMS, $key));
        }
        $checked = [];
        foreach ($items as $name => $definition) {
            if (!is_array($definition) || ($definition !== [] && array_is_list($definition))) {
                throw new InvalidArgumentException("'{$key}': the definition of " . json_encode((string) $name) . ' must be an object.');
            }
            $checked[(string) $name] = $definition;
        }

        return $checked;
    }
}
