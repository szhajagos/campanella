<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\Field;
use Campanella\Model\FieldType;
use Campanella\Query\Query;

/**
 * The object has a weight: a hand-set order (a lighter one comes first).
 * Since 0.0.7 (before, only an example in the documentation).
 *
 *     Query::objects()->blueprint('category')->scope('by_weight')
 */
#[AsCapability('weighted', label: 'capability.weighted')]
final class Weighted extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            // Sorted on, so it is a column of the capability's own table, indexed.
            new Field('weight', FieldType::Integer, required: true, default: 0, indexed: true, label: 'field.weight'),
        ];
    }

    #[\Override]
    public static function scopes(): array
    {
        return [
            // Equal weights keep the order of creation.
            'by_weight' => static fn (Query $q): Query => $q->orderBy('weight', 'ASC')->orderBy('id', 'ASC'),
        ];
    }

    public function weight(): int
    {
        return (int) $this->object->get('weight');
    }

    public function setWeight(int $weight): void
    {
        $this->object->set('weight', $weight);
    }
}
