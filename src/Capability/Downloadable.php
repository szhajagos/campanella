<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * The object is a stored file to download (since 0.3.2): a document, a
 * spreadsheet, a text file (the `file` Blueprint). Unlike an image (MediaFile), it is
 * never shown in a page, only downloaded: the site serves it through PHP
 * (Campanella\Controller\FileController), with the name it was uploaded with, after
 * checking who may see it.
 *
 * The fields are set by the upload (Campanella\Media\FileService), never by a form.
 * The file itself is deleted together with the object (DeleteStoredFile).
 */
#[AsCapability('downloadable', label: 'capability.downloadable')]
final class Downloadable extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            // Relative to the files folder: YYYY/MM/<random name>.<extension>
            new Field('download_path', FieldType::String, required: true, unique: true, length: 64, label: 'field.download_path'),
            // The name it is downloaded with, made from the uploaded file's name: `aprilisi-hirlevel.pdf`.
            new Field('download_name', FieldType::String, required: true, length: 128, label: 'field.download_name'),
            new Field('download_type', FieldType::String, required: true, indexed: true, length: 100, label: 'field.download_type'),
            new Field('download_size', FieldType::Integer, required: true, label: 'field.download_size'),
            // SHA-256 of the stored file: finds the same file uploaded twice; the download's ETag.
            new Field('download_hash', FieldType::String, required: true, indexed: true, length: 64, label: 'field.download_hash'),
        ];
    }

    /** The path relative to the files folder, e.g. `2026/10/3f…a1.pdf`. */
    public function path(): string
    {
        return (string) $this->object->get('download_path');
    }

    /** The name it is downloaded with, e.g. `aprilisi-hirlevel.pdf`. */
    public function name(): string
    {
        return (string) $this->object->get('download_name');
    }

    /** The extension, as checked on upload: `pdf`, `docx` … */
    public function extension(): string
    {
        return strtolower(pathinfo($this->path(), PATHINFO_EXTENSION));
    }

    public function mimeType(): string
    {
        return (string) $this->object->get('download_type');
    }

    /** The size in bytes. */
    public function size(): int
    {
        return (int) $this->object->get('download_size');
    }

    public function hash(): string
    {
        return (string) $this->object->get('download_hash');
    }
}
