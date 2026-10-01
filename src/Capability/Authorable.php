<?php

declare(strict_types=1);

namespace Campanella\Capability;

use Campanella\Model\CampanellaObject;
use Campanella\Relation\Cardinality;
use Campanella\Relation\Relation;

/**
 * The object has an author: an `author` relation to a user.
 *
 * When a new object is created, ObjectService automatically sets the
 * creating user as the author if none is given yet.
 */
#[AsCapability('authorable', label: 'capability.authorable')]
final class Authorable extends Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [];
    }

    #[\Override]
    public static function relations(): array
    {
        return [
            new Relation('author', Cardinality::One, targetCapabilities: [Identifiable::class], label: 'relation.author'),
        ];
    }

    public function authorId(): ?int
    {
        return $this->object->relatedIds('author')[0] ?? null;
    }

    public function setAuthor(CampanellaObject|int $user): void
    {
        $this->object->relate('author', $user);
    }
}
