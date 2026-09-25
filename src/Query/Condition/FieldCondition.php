<?php

declare(strict_types=1);

namespace Campanella\Query\Condition;

use Campanella\Query\Operator;

/** mező OPERÁTOR érték, pl. status = 'published' */
final readonly class FieldCondition implements Condition
{
    public function __construct(
        public string $field,
        public Operator $operator,
        public mixed $value = null,
    ) {
    }
}
