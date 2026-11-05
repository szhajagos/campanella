<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\I18n\Message;
use Campanella\Model\AbstractFieldType;
use Campanella\Model\FieldType;

/** A field type of a module with its own widget (a test): a colour, #rrggbb. */
final class SzinType extends AbstractFieldType
{
    public function name(): string
    {
        return 'hirlevel_szin';
    }

    public function base(): FieldType
    {
        return FieldType::String;
    }

    public function label(): string
    {
        return 'hirlevel.type.szin';
    }

    public function normalize(mixed $value): mixed
    {
        return is_string($value) ? strtolower(trim($value)) : $value;
    }

    public function invalid(mixed $value): ?Message
    {
        return preg_match('/^#[0-9a-f]{6}$/', (string) $value) === 1 ? null : new Message('hirlevel.invalid_color');
    }

    public function widget(): ?string
    {
        return '@hirlevel/form/szin.html.twig';
    }
}
