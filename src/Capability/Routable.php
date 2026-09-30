<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Support\Slugger;

/**
 * The object is reachable at its own URL.
 *
 * It builds on Titled so that, when the path is empty, one can be
 * generated from the title (e.g. "Neumann János" → /neumann-janos).
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

    /** Canonical form: starts with a slash, does not end with a slash, lowercase. */
    public static function normalize(string $path): string
    {
        $segments = array_filter(
            explode('/', mb_strtolower(trim($path))),
            static fn (string $s): bool => $s !== '',
        );

        return '/' . implode('/', $segments);
    }
}
