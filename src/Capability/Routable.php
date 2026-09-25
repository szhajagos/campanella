<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Support\Slugger;

/**
 * Az objektum saját URL-en érhető el.
 *
 * A Titled-re azért épül, hogy üres útvonal esetén a címből
 * készülhessen egy (pl. „Neumann János” → /neumann-janos).
 */
#[AsCapability('routable', requires: [Titled::class], label: 'Útvonallal rendelkező')]
final class Routable extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('path', FieldType::String, required: true, unique: true, label: 'Útvonal'),
        ];
    }

    public function route(): string
    {
        return (string) $this->object->get('path');
    }

    public function setPath(string $path): void
    {
        $this->object->set('path', self::normalize($path));
    }

    #[\Override]
    public function prepareForSave(): void
    {
        $path = (string) $this->object->get('path');
        if ($path === '') {
            $path = '/' . Slugger::slugify($this->object->as(Titled::class)->title());
        }
        $this->object->set('path', self::normalize($path));
    }

    /** Egységes alak: perjellel kezdődik, nem végződik perjelre, kisbetűs. */
    public static function normalize(string $path): string
    {
        $segments = array_filter(
            explode('/', mb_strtolower(trim($path))),
            static fn (string $s): bool => $s !== '',
        );

        return '/' . implode('/', $segments);
    }
}
