<?php

declare(strict_types=1);

namespace Campanella\Model;

use Campanella\I18n\Message;

/**
 * The defaults of a CustomFieldType (since 0.4.8): a value as it is, valid if its base type
 * finds it valid, shown and edited as its base type, imported as given. A type overrides what
 * it does differently, and gives its name, base and label.
 */
abstract class AbstractFieldType implements CustomFieldType
{
    #[\Override]
    public function description(): string
    {
        return '';
    }

    #[\Override]
    public function normalize(mixed $value): mixed
    {
        return $value;
    }

    #[\Override]
    public function invalid(mixed $value): ?Message
    {
        return null;
    }

    #[\Override]
    public function format(mixed $value): ?string
    {
        return null;
    }

    #[\Override]
    public function widget(): ?string
    {
        return null;
    }

    #[\Override]
    public function attributes(): array
    {
        return [];
    }

    #[\Override]
    public function import(string $value): mixed
    {
        return $value;
    }
}
