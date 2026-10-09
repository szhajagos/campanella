<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * The object is a stored file (e.g. an uploaded image): where it is, what type,
 * how big. The fields are queryable (their own table), so e.g. the images of a
 * type, or a file by its checksum, can be found.
 *
 * The fields are set by the upload (Campanella\Media\MediaService), never by a
 * form. The file itself is deleted together with the object (DeleteMediaFile),
 * its smaller variants too.
 *
 * Since 0.1.2 an image has smaller copies (variants) for `srcset`, e.g. 320, 640,
 * 1024 and 1600 pixels wide, stored beside it as `<name>-<width>.<extension>`. The
 * `variants` field lists their widths (`320,640`); empty: none needed (a small
 * image); null: not made yet (an image uploaded before 0.1.2).
 */
#[AsCapability('media_file', label: 'capability.media_file')]
final class MediaFile extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            // Relative to the media folder: YYYY/MM/<random name>.<extension>
            new Field('file_path', FieldType::String, required: true, unique: true, length: 64, label: 'field.file_path'),
            new Field('mime_type', FieldType::String, required: true, indexed: true, length: 32, label: 'field.mime_type'),
            new Field('file_size', FieldType::Integer, required: true, label: 'field.file_size'),
            new Field('width', FieldType::Integer, label: 'field.width'),
            new Field('height', FieldType::Integer, label: 'field.height'),
            // SHA-256 of the stored file: finds the same file uploaded twice.
            new Field('file_hash', FieldType::String, required: true, indexed: true, length: 64, label: 'field.file_hash'),
            // The widths of the smaller copies, comma-separated (since 0.1.2).
            new Field('variants', FieldType::String, length: 64, label: 'field.variants'),
        ];
    }

    /** The path relative to the media folder, e.g. `2026/10/3f…a1.jpg`. */
    public function path(): string
    {
        return (string) $this->object->get('file_path');
    }

    public function mimeType(): string
    {
        return (string) $this->object->get('mime_type');
    }

    /** The size in bytes. */
    public function size(): int
    {
        return (int) $this->object->get('file_size');
    }

    public function width(): ?int
    {
        $width = $this->object->get('width');

        return $width === null ? null : (int) $width;
    }

    public function height(): ?int
    {
        $height = $this->object->get('height');

        return $height === null ? null : (int) $height;
    }

    public function hash(): string
    {
        return (string) $this->object->get('file_hash');
    }

    /**
     * The widths of the smaller copies, smallest first (empty: none, or not made yet).
     *
     * @return list<int>
     */
    public function variantWidths(): array
    {
        return self::parseWidths($this->object->get('variants'));
    }

    /** Whether the smaller copies were made (or found unnecessary); false for an image uploaded before 0.1.2. */
    public function hasVariants(): bool
    {
        return $this->object->get('variants') !== null;
    }

    /** The path of a smaller copy: `2026/10/3f…a1.jpg` → `2026/10/3f…a1-640.jpg`. */
    public static function variantPath(string $path, int $width): string
    {
        return (string) preg_replace('/(\.[a-z0-9]+)$/', '-' . $width . '$1', $path);
    }

    /**
     * The stored form of the widths: `320,640` (sorted, unique).
     *
     * @param list<int> $widths
     */
    public static function formatWidths(array $widths): string
    {
        $widths = array_values(array_unique(array_filter($widths, static fn (int $w): bool => $w > 0)));
        sort($widths);

        return implode(',', $widths);
    }

    /** @return list<int> */
    public static function parseWidths(mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }
        $widths = [];
        foreach (explode(',', $stored) as $width) {
            if (ctype_digit($width) && (int) $width > 0) {
                $widths[] = (int) $width;
            }
        }
        sort($widths);

        return array_values(array_unique($widths));
    }
}
