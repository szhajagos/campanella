<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;

/** The object has a title. */
#[AsCapability('titled', label: 'Címmel rendelkező')]
final class Titled extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('title', FieldType::String, required: true, indexed: true, label: 'Cím'),
        ];
    }

    public function title(): string
    {
        return (string) $this->object->get('title');
    }

    public function setTitle(string $title): void
    {
        $this->object->set('title', trim($title));
    }
}
