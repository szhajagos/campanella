<?php

declare(strict_types=1);

namespace Campanella\Access;

final class AccessDeniedException extends \RuntimeException
{
    public static function for(Actor $actor, Operation $operation, string $target): self
    {
        return new self(sprintf(
            'Hozzáférés megtagadva: %s nem végezheti el ezt a műveletet: %s (%s).',
            $actor->name !== '' ? $actor->name : $actor->kind->value,
            $operation->value,
            $target,
        ));
    }
}
