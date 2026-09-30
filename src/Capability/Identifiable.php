<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * The object is identified by a unique e-mail address (e.g. a user).
 *
 * Before saving, the address is normalized to lowercase without whitespace,
 * so "Kovacs.Anna@Example.hu" and "kovacs.anna@example.hu" are the same account.
 */
#[AsCapability('identifiable', label: 'Azonosítható')]
final class Identifiable extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('email', FieldType::String, required: true, unique: true, length: 254, label: 'E-mail-cím', hidden: true),
        ];
    }

    public function email(): string
    {
        return (string) $this->object->get('email');
    }

    public function setEmail(string $email): void
    {
        $this->object->set('email', self::normalize($email));
    }

    #[\Override]
    public function prepareForSave(): void
    {
        $this->object->set('email', self::normalize((string) $this->object->get('email')));
    }

    #[\Override]
    public function validate(): array
    {
        $email = $this->email();
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['email' => 'érvénytelen e-mail-cím'];
        }

        return [];
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }
}
