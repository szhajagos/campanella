<?php

declare(strict_types=1);

namespace Campanella\Access;

final class AccessDeniedException extends \RuntimeException
{
    public static function for(Actor $actor, Operation $operation, string $target): self
    {
        return new self(sprintf(
            'Access denied: %s may not perform this operation: %s (%s).',
            $actor->name !== '' ? $actor->name : $actor->kind->value,
            $operation->value,
            $target,
        ));
    }
}
