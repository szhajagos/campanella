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
 * form. The file itself is deleted together with the object (DeleteMediaFile).
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
}
