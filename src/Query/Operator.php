<?php

declare(strict_types=1);

namespace Campanella\Query;

enum Operator: string
{
    case Equals = '=';
    case NotEquals = '!=';
    case LessThan = '<';
    case LessOrEqual = '<=';
    case GreaterThan = '>';
    case GreaterOrEqual = '>=';
    case In = 'IN';
    case NotIn = 'NOT IN';
    case Like = 'LIKE';
    case IsNull = 'IS NULL';
    case IsNotNull = 'IS NOT NULL';

    public static function parse(self|string $operator): self
    {
        if ($operator instanceof self) {
            return $operator;
        }
        $normalized = strtoupper(trim($operator));

        return self::tryFrom($normalized === '<>' ? '!=' : $normalized)
            ?? throw new QueryException("Ismeretlen operátor: {$operator}");
    }

    public function sql(): string
    {
        return $this === self::NotEquals ? '<>' : $this->value;
    }

    public function takesValue(): bool
    {
        return $this !== self::IsNull && $this !== self::IsNotNull;
    }

    public function takesList(): bool
    {
        return $this === self::In || $this === self::NotIn;
    }
}
