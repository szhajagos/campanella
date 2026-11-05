<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\I18n\Message;
use Campanella\Model\AbstractFieldType;
use Campanella\Model\FieldType;

/** A field type of a module (a test): a phone number, kept without spaces, shown in groups. */
final class TelefonType extends AbstractFieldType
{
    public function name(): string
    {
        return 'hirlevel_telefon';
    }

    public function base(): FieldType
    {
        return FieldType::String;
    }

    public function label(): string
    {
        return 'hirlevel.type.telefon';
    }

    public function description(): string
    {
        return 'hirlevel.type.telefon_help';
    }

    public function normalize(mixed $value): mixed
    {
        return is_string($value) ? (string) preg_replace('/[\s\-\/()]+/', '', $value) : $value;
    }

    public function invalid(mixed $value): ?Message
    {
        return preg_match('/^\+?[0-9]{6,15}$/', (string) $value) === 1 ? null : new Message('hirlevel.invalid_phone');
    }

    public function format(mixed $value): ?string
    {
        $digits = (string) $value;

        return str_starts_with($digits, '+36') && strlen($digits) === 12
            ? '+36 ' . substr($digits, 3, 2) . ' ' . substr($digits, 5, 3) . ' ' . substr($digits, 8)
            : $digits;
    }

    public function attributes(): array
    {
        return ['inputmode' => 'tel', 'autocomplete' => 'tel'];
    }

    public function import(string $value): mixed
    {
        return preg_replace('/^tel:/i', '', trim($value));
    }
}
