<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;

/**
 * The object has a text body.
 *
 * The body is never filtered or sorted on, so it lives in the data (JSON)
 * column; the capability has no table of its own.
 */
#[AsCapability('textual', label: 'Szöveges')]
final class Textual extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('body', FieldType::Text, FieldStorage::Data, label: 'Törzsszöveg'),
            new Field('format', FieldType::String, FieldStorage::Data, default: TextFormat::Plain->value, length: 16),
        ];
    }

    public function body(): string
    {
        return (string) $this->object->get('body');
    }

    public function format(): TextFormat
    {
        return TextFormat::tryFrom((string) $this->object->get('format')) ?? TextFormat::Plain;
    }

    public function setBody(string $body, TextFormat $format = TextFormat::Plain): void
    {
        $this->object->set('body', $body);
        $this->object->set('format', $format);
    }
}
