<?php

declare(strict_types=1);

namespace Campanella\Import;

use Campanella\I18n\Message;
use Generator;

/**
 * The items of an importer's source file (since 0.3.1): a CSV file with a header row,
 * or a JSON file with a list of objects. Read as a stream (a CSV) or at once (JSON),
 * so a run can go on from where its last batch stopped (items($skip)).
 *
 * A CSV: UTF-8 (a byte order mark is left out), the delimiter `;`, `,` or a tab, by
 * the importer or found from the header row (the most frequent of them); fields in
 * double quotes may hold the delimiter, quotes ("") and line breaks; empty rows are
 * skipped. Its items are the rows, by the header's headings.
 */
final class ImportSource
{
    /** The largest JSON file read (it is read at once). A CSV is read as a stream. */
    public const int MAX_JSON_BYTES = 20 * 1024 * 1024;

    /** The most columns of a CSV, and the longest heading. */
    public const int MAX_COLUMNS = 200;

    /** @var list<string>|null */
    private ?array $columns = null;

    /** @var list<mixed>|null The JSON file's items */
    private ?array $json = null;

    private string $separator = ',';

    private function __construct(
        private readonly string $path,
        private readonly string $format,
        private readonly string $delimiter,
        private readonly string $itemsPath,
    ) {
    }

    /** @throws ImportException if the file cannot be read as the importer's format */
    public static function open(string $path, ImporterDefinition $importer): self
    {
        return self::file($path, $importer->format, $importer->delimiter, $importer->items);
    }

    /**
     * @param string $format `csv` or `json`
     * @param string $delimiter `auto`, `;`, `,` or `tab`
     * @param string $itemsPath a JSON file's list of items, as a dotted path
     * @throws ImportException
     */
    public static function file(string $path, string $format, string $delimiter = 'auto', string $itemsPath = ''): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImportException(new Message('import.error.unreadable'));
        }
        $source = new self($path, $format, $delimiter, $itemsPath);
        $format === 'json' ? $source->readJson() : $source->readHeader();

        return $source;
    }

    /**
     * The headings of a CSV, or the keys of a JSON file's first items (at most the first 20).
     *
     * @return list<string>
     */
    public function columns(): array
    {
        if ($this->columns === null) {
            $keys = [];
            foreach (array_slice($this->json ?? [], 0, 20) as $item) {
                if (is_array($item)) {
                    foreach (self::flatKeys($item) as $key) {
                        $keys[$key] = true;
                    }
                }
            }
            $this->columns = array_slice(array_keys($keys), 0, self::MAX_COLUMNS);
        }

        return $this->columns;
    }

    /**
     * The items, from the given one on: the item's number (1 for the first) => its values
     * by heading (a CSV: strings), or the JSON object as it is; an item that cannot be read
     * (not UTF-8, not an object) is an ImportException in its place.
     *
     * @return Generator<int, array<string, mixed>|ImportException>
     */
    public function items(int $skip = 0): Generator
    {
        if ($this->format === 'json') {
            foreach ($this->json ?? [] as $index => $item) {
                if ($index < $skip) {
                    continue;
                }
                yield $index + 1 => is_array($item) && !array_is_list($item) || $item === []
                    ? (array) $item
                    : new ImportException(new Message('import.error.not_object'));
            }

            return;
        }
        $handle = $this->handle();
        $headings = $this->columns();
        fgetcsv($handle, null, $this->separator, '"', ''); // the header
        $number = 0;
        while (($row = fgetcsv($handle, null, $this->separator, '"', '')) !== false) {
            if ($row === [null] || implode('', array_map('strval', $row)) === '') {
                continue; // an empty line
            }
            $number++;
            if ($number <= $skip) {
                continue;
            }
            $cells = array_map(static fn (mixed $c): string => (string) $c, $row);
            if (!mb_check_encoding(implode('', $cells), 'UTF-8')) {
                yield $number => new ImportException(new Message('import.error.not_utf8'));
                continue;
            }
            $item = [];
            foreach ($headings as $i => $heading) {
                $item[$heading] = $cells[$i] ?? '';
            }
            yield $number => $item;
        }
        fclose($handle);
    }

    /** The number of items. */
    public function count(): int
    {
        $count = 0;
        foreach ($this->items() as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * An item's value at a source: a CSV column by its heading; in a JSON item the key, or
     * a dotted path (`venue.name`). Null if the item has none.
     *
     * @param array<string, mixed> $item
     */
    public static function value(array $item, string $source): mixed
    {
        if (array_key_exists($source, $item)) {
            return $item[$source];
        }
        $value = $item;
        foreach (explode('.', $source) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function readHeader(): void
    {
        $handle = $this->handle();
        $line = fgets($handle);
        fclose($handle);
        if ($line === false || trim($line) === '') {
            throw new ImportException(new Message('import.error.no_header'));
        }
        if (!mb_check_encoding($line, 'UTF-8')) {
            throw new ImportException(new Message('import.error.not_utf8_file'));
        }
        $this->separator = match ($this->delimiter) {
            ';' => ';',
            ',' => ',',
            'tab' => "\t",
            default => self::guess($line),
        };
        $handle = $this->handle();
        $headings = fgetcsv($handle, null, $this->separator, '"', '');
        fclose($handle);
        if (!is_array($headings) || count($headings) > self::MAX_COLUMNS) {
            throw new ImportException(new Message('import.error.no_header'));
        }
        $columns = [];
        foreach ($headings as $i => $heading) {
            $heading = trim((string) $heading);
            if ($heading === '') {
                $heading = '#' . ($i + 1); // a column without a heading, by its place
            }
            if (in_array($heading, $columns, true) || mb_strlen($heading, 'UTF-8') > ImporterDefinition::MAX_SOURCE) {
                throw new ImportException(new Message('import.error.headings', ['heading' => mb_substr($heading, 0, 50)]));
            }
            $columns[] = $heading;
        }
        $this->columns = $columns;
    }

    private function readJson(): void
    {
        $size = filesize($this->path);
        if ($size === false || $size > self::MAX_JSON_BYTES) {
            throw new ImportException(new Message('import.error.too_large', ['size' => (int) (self::MAX_JSON_BYTES / 1048576) . ' MB']));
        }
        $text = (string) file_get_contents($this->path);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        try {
            $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ImportException(new Message('import.error.json', ['reason' => $e->getMessage()]));
        }
        if ($this->itemsPath !== '') {
            $data = is_array($data) ? self::value($data, $this->itemsPath) : null;
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new ImportException(new Message('import.error.no_items'));
        }
        $this->json = $data;
    }

    /** @return resource */
    private function handle()
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new ImportException(new Message('import.error.unreadable'));
        }
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle); // no byte order mark
        }

        return $handle;
    }

    /** The delimiter a header row most likely uses: the most frequent of ; , and tab. */
    private static function guess(string $line): string
    {
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? (string) $best : ',';
    }

    /**
     * The keys of an item, nested ones as dotted paths (lists of values are one key).
     *
     * @param array<mixed> $item
     * @return list<string>
     */
    private static function flatKeys(array $item, string $prefix = ''): array
    {
        $keys = [];
        foreach ($item as $key => $value) {
            $name = $prefix . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                array_push($keys, ...self::flatKeys($value, $name . '.'));
            } else {
                $keys[] = $name;
            }
        }

        return $keys;
    }
}
