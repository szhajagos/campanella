<?php

declare(strict_types=1);

namespace Campanella\Query;

enum Direction: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';

    public static function parse(self|string $direction): self
    {
        return $direction instanceof self
            ? $direction
            : (self::tryFrom(strtoupper($direction)) ?? throw new QueryException("Ismeretlen irány: {$direction}"));
    }
}
