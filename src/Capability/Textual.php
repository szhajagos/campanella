<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;

/**
 * Az objektumnak van szöveges törzse.
 *
 * A törzsre nem szűrünk és nem rendezünk, ezért a data (JSON) oszlopban
 * él, a capability-nek nincs saját táblája.
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
