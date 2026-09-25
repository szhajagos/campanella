<?php

declare(strict_types=1);

namespace Campanella\Model;

final class ValidationException extends \RuntimeException
{
    /** @param array<string, string> $errors mezőnév => hibaüzenet */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Érvénytelen objektum: ' . implode('; ', array_map(
            static fn (string $field, string $message): string => "{$field}: {$message}",
            array_keys($errors),
            $errors,
        )));
    }
}
