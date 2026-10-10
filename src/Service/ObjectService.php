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
use Campanella\Event\EventDispatcher;
use Campanella\Event\ObjectCreated;
use Campanella\Event\ObjectDeleted;
use Campanella\Event\ObjectPublished;
use Campanella\Event\ObjectUnpublished;
use Campanella\Event\ObjectUpdated;
use DateTimeImmutable;

/**
 * The business logic of operations on objects.
 *
 * The controller, the CLI (and later the API, the Webform) all call this,
 * so access control checks and the events (since 0.1.3: ObjectCreated,
 * ObjectUpdated, ObjectPublished, ObjectUnpublished, ObjectDeleted, after the
 * operation was saved) happen in a single place.
 */
final class ObjectService
{
    /** @var list<ObjectListener> */
    private array $listeners = [];

    public function __construct(
        private readonly ObjectRepository $repository,
        private readonly AccessPolicy $policy,
        private readonly ?EventDispatcher $events = null,
    ) {
    }

    /** Adds a listener, notified after operations (since 0.0.5: after deleting). */
    public function addListener(ObjectListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<int>> $relations relation name => target IDs, in order (since 0.0.4)
     */
    public function create(
        Actor $actor,
        string $blueprint,
        array $values,
        bool $publish = false,
        array $relations = [],
    ): CampanellaObject {
        $object = $this->repository->create($blueprint, $values);
        $this->authorize($actor, Operation::Create, $object);
        foreach ($relations as $name => $targets) {
            $object->setRelated($name, $targets);
        }

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
        $this->events?->dispatch(new ObjectCreated($object, $actor));
        if ($publish) {
            $this->events?->dispatch(new ObjectPublished($object, $actor));
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<int>> $relations relation name => target IDs, in order (since 0.0.4);
     *        relations not given stay as they are
     */
    public function update(Actor $actor, CampanellaObject $object, array $values, array $relations = []): void
    {
        $this->authorize($actor, Operation::Update, $object);
        $object->fill($values);
        foreach ($relations as $name => $targets) {
            $object->setRelated($name, $targets);
        }
        $this->repository->save($object);
        $this->events?->dispatch(new ObjectUpdated($object, $actor));
    }

    public function publish(Actor $actor, CampanellaObject $object, ?DateTimeImmutable $at = null): void
    {
        $this->authorize($actor, Operation::Publish, $object);
        $object->as(Publishable::class)->publish($at);
        $this->repository->save($object);
        $this->events?->dispatch(new ObjectPublished($object, $actor));
    }

    public function unpublish(Actor $actor, CampanellaObject $object): void
    {
        $this->authorize($actor, Operation::Unpublish, $object);
        $object->as(Publishable::class)->unpublish();
        $this->repository->save($object);
        $this->events?->dispatch(new ObjectUnpublished($object, $actor));
    }

    public function delete(Actor $actor, CampanellaObject $object): void
    {
        $this->authorize($actor, Operation::Delete, $object);
        $this->repository->delete($object);
        foreach ($this->listeners as $listener) {
            try {
                $listener->afterDelete($object);
            } catch (\Throwable $e) {
                error_log('Campanella: ' . $listener::class . ' failed after deleting #' . $object->id() . ': ' . $e->getMessage());
            }
        }
        $this->events?->dispatch(new ObjectDeleted($object, $actor));
    }

    private function authorize(Actor $actor, Operation $operation, CampanellaObject $object): void
    {
        if (!$this->policy->allows($actor, $operation, $object)) {
            throw AccessDeniedException::for($actor, $operation, $object->blueprint() . ' #' . ($object->id() ?? 'new'));
        }
    }
}
