<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * Az objektumot egy egyedi e-mail-cím azonosítja (pl. felhasználó).
 *
 * A cím mentés előtt kisbetűs, szóközök nélküli alakra normalizálódik, így
 * a „Kovacs.Anna@Example.hu” és a „kovacs.anna@example.hu” ugyanaz a fiók.
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
