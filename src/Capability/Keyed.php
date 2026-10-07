<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\I18n\Message;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * The object has a unique machine name, by which code and templates find it
 * (e.g. the menu `main`: `menu('main')`). Since 0.0.7.
 *
 * Lowercase letters, digits and underscores, starting with a letter; it is
 * trimmed and lowercased before saving.
 *
 *     Query::objects()->blueprint('menu')->where('machine_name', '=', 'main')
 */
#[AsCapability('keyed', label: 'capability.keyed')]
final class Keyed extends Capability
{
    public const string PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('machine_name', FieldType::String, required: true, unique: true, length: 64, label: 'field.machine_name'),
        ];
    }

    public function key(): string
    {
        return (string) $this->object->get('machine_name');
    }

    #[\Override]
    public function prepareForSave(): void
    {
        $this->object->set('machine_name', mb_strtolower(trim($this->key()), 'UTF-8'));
    }

    #[\Override]
    public function validate(): array
    {
        $key = $this->key();

        return $key !== '' && preg_match(self::PATTERN, $key) !== 1
            ? ['machine_name' => new Message('validation.machine_name')]
            : [];
    }
}
