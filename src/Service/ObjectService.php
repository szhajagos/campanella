<?php

declare(strict_types=1);

namespace Campanella\Service;

use Campanella\Access\AccessDeniedException;
use Campanella\Access\AccessPolicy;
use Campanella\Access\Actor;
use Campanella\Access\ActorKind;
use Campanella\Access\Operation;
use Campanella\Capability\Authorable;
use Campanella\Capability\Publishable;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use DateTimeImmutable;

/**
 * The business logic of operations on objects.
 *
 * The controller, the CLI (and later the API, the Webform) all call this,
 * so access control checks (and later dispatching Events) happen
 * in a single place.
 */
final class ObjectService
{
    public function __construct(
        private readonly ObjectRepository $repository,
        private readonly AccessPolicy $policy,
    ) {
    }

    /** @param array<string, mixed> $values */
    public function create(Actor $actor, string $blueprint, array $values, bool $publish = false): CampanellaObject
    {
        $object = $this->repository->create($blueprint, $values);
        $this->authorize($actor, Operation::Create, $object);

        // The logged-in user becomes the author if no other is given.
        if ($actor->kind === ActorKind::User && $actor->id !== null
            && $object->has(Authorable::class) && $object->as(Authorable::class)->authorId() === null) {
            $object->as(Authorable::class)->setAuthor($actor->id);
        }

        if ($publish) {
            $this->authorize($actor, Operation::Publish, $object);
            $object->as(Publishable::class)->publish();
        }
        $this->repository->save($object);

        return $object;
    }

    /** @param array<string, mixed> $values */
    public function update(Actor $actor, CampanellaObject $object, array $values): void
    {
        $this->authorize($actor, Operation::Update, $object);
        $object->fill($values);
        $this->repository->save($object);
    }

    public function publish(Actor $actor, CampanellaObject $object, ?DateTimeImmutable $at = null): void
    {
        $this->authorize($actor, Operation::Publish, $object);
        $object->as(Publishable::class)->publish($at);
        $this->repository->save($object);
    }

    public function unpublish(Actor $actor, CampanellaObject $object): void
    {
        $this->authorize($actor, Operation::Unpublish, $object);
        $object->as(Publishable::class)->unpublish();
        $this->repository->save($object);
    }

    public function delete(Actor $actor, CampanellaObject $object): void
    {
        $this->authorize($actor, Operation::Delete, $object);
        $this->repository->delete($object);
    }

    private function authorize(Actor $actor, Operation $operation, CampanellaObject $object): void
    {
        if (!$this->policy->allows($actor, $operation, $object)) {
            throw AccessDeniedException::for($actor, $operation, $object->blueprint() . ' #' . ($object->id() ?? 'new'));
        }
    }
}
