<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\I18n\Message;
use Campanella\Model\ValidationException;

/**
 * Checks an uploaded file by its content, not its name (since 0.3.2): what it really
 * is decides its type, and only the accepted types pass.
 *
 *  - PDF: it starts as a PDF does;
 *  - Office (docx, xlsx, pptx): a ZIP archive with the content types of a document,
 *    a spreadsheet or a presentation; macro-enabled ones (VBA projects, ActiveX) are
 *    refused, also under the plain name;
 *  - OpenDocument (odt, ods, odp): a ZIP archive whose `mimetype` entry says which;
 *    those with Basic or other scripts are refused;
 *  - text (txt, csv): no zero bytes; the name's extension tells which of the two;
 *  - zip: only if the setting lists it (`files.types`), as its content cannot be
 *    checked.
 *
 * Anything else is refused, whatever its name: never HTML, SVG, scripts or programs,
 * whatever the setting lists (KNOWN has the only types there can be). A file's type
 * and stored extension come from its content: a document named `.pdf` that is a
 * `.docx` is stored and downloaded as a `.docx`.
 */
final class FileInspector
{
    /** Every type that can be accepted: extension => MIME type. */
    public const array KNOWN = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'zip' => 'application/zip',
    ];

    /** The types accepted by default (zip is not: its content cannot be checked). */
    public const array DEFAULT_TYPES = ['pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv'];

    public const int DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    /** The main part's content type in [Content_Types].xml => extension. */
    private const array OFFICE = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml' => 'pptx',
    ];

    /** @var list<string> */
    private readonly array $types;

    /**
     * @param list<string> $types The accepted extensions (`files.types`); unknown ones are ignored
     */
    public function __construct(array $types = self::DEFAULT_TYPES, private readonly int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        $this->types = array_values(array_intersect(array_keys(self::KNOWN), array_map(static fn ($t): string => strtolower(trim((string) $t)), $types)));
    }

    /** @return list<string> The accepted extensions, in KNOWN's order. */
    public function types(): array
    {
        return $this->types;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * @param string $file The uploaded file
     * @param string $originalName The name the uploader gave it (only its extension is used, for text files)
     * @return array{extension: string, mime: string, bytes: string}
     * @throws ValidationException on the `file` field, if it is not an accepted file
     */
    public function inspect(string $file, string $originalName): array
    {
        $size = is_file($file) ? (int) filesize($file) : 0;
        if ($size === 0) {
            throw self::error('file.empty');
        }
        if ($size > $this->maxBytes) {
            throw self::error('file.too_large', ['max' => self::megabytes($this->maxBytes)]);
        }
        $bytes = (string) @file_get_contents($file);
        if (strlen($bytes) !== $size) {
            throw self::error('file.empty');
        }
        $extension = $this->detect($bytes, strtolower(pathinfo(basename(str_replace('\\', '/', $originalName)), PATHINFO_EXTENSION)));
        if (!in_array($extension, $this->types, true)) {
            throw self::error('file.type_not_allowed', ['types' => implode(', ', $this->types)]);
        }

        return ['extension' => $extension, 'mime' => self::KNOWN[$extension], 'bytes' => $bytes];
    }

    /**
     * What the content is: one of KNOWN's extensions.
     *
     * @throws ValidationException if it is none of them
     */
    private function detect(string $bytes, string $nameExtension): string
    {
        if (str_starts_with($bytes, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return $this->archive($bytes);
        }
        if (!str_contains($bytes, "\0") && in_array($nameExtension, ['txt', 'csv'], true)) {
            return $nameExtension;
        }

        throw self::error('file.not_recognized', ['types' => implode(', ', $this->types)]);
    }

    private function archive(string $bytes): string
    {
        try {
            $zip = new ZipDirectory($bytes);
        } catch (\InvalidArgumentException) {
            throw self::error('file.damaged');
        }
        $names = $zip->names();
        $risky = preg_grep('#(^|/)(vbaProject\.bin|vbaData\.xml)$|(^|/)activeX/|^(Basic|Scripts)/#i', $names) ?: [];

        // OpenDocument: the first entry is `mimetype`, stored, with the type in it.
        if (($names[0] ?? '') === 'mimetype' && $zip->method('mimetype') === 0) {
            $type = match (trim((string) $zip->read('mimetype'))) {
                self::KNOWN['odt'] => 'odt',
                self::KNOWN['ods'] => 'ods',
                self::KNOWN['odp'] => 'odp',
                default => null,
            };
            if ($type !== null) {
                if ($risky !== []) {
                    throw self::error('file.macros');
                }

                return $type;
            }
        }

        // Office: [Content_Types].xml names the main part's type.
        if ($zip->has('[Content_Types].xml')) {
            $types = $zip->read('[Content_Types].xml');
            if ($types === null) {
                throw self::error('file.damaged');
            }
            if ($risky !== [] || stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                throw self::error('file.macros');
            }
            $found = [];
            foreach (self::OFFICE as $contentType => $extension) {
                if (str_contains($types, '"' . $contentType . '"')) {
                    $found[] = $extension;
                }
            }
            if (count($found) === 1) {
                return $found[0];
            }
        }

        foreach ($names as $name) {
            if ($zip->isEncrypted($name)) {
                throw self::error('file.damaged');
            }
        }

        return 'zip';
    }

    /** @param array<string, string|int> $params */
    private static function error(string $key, array $params = []): ValidationException
    {
        return new ValidationException(['file' => new Message($key, $params)]);
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, '.', ''), '0'), '.');
    }
}
