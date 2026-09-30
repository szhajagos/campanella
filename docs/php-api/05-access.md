# 5. Access control

Access control is built on three concepts: **who** (Actor), **what** they want
to do (Operation), and **which policy** decides (AccessPolicy).

## Actor

`Campanella\Access\Actor` · **Public** · `final readonly class`

Whoever acts. A role is not an Actor but one of the Actor's properties.

| Member | Description |
|---|---|
| `$kind` | `ActorKind` |
| `$id` | The user's ID, if any |
| `$roles` | `list<string>` |
| `$name` | Display name (for logs and error messages) |
| `ADMINISTRATOR` | Name of the `'administrator'` role |
| `static anonymous(): self` | A visitor who is not logged in |
| `static system(): self` | The system itself (CLI, installer, scheduled task), with the `administrator` role |
| `hasRole(string $role): bool` | |
| `isAnonymous(): bool` | |

```php
$editor = new Actor(ActorKind::User, id: 12, roles: ['editor'], name: 'Kovács Anna');
```

Web requests run on behalf of the logged-in user (`AuthService::currentActor()`,
see [chapter 11](11-users.md)), or on behalf of `Actor::anonymous()` without
login; the CLI runs on behalf of `Actor::system()`.

## ActorKind

`Campanella\Access\ActorKind` · **Public** · `enum: string`

`Anonymous = 'anonymous'`, `User = 'user'`, `Service = 'service'` (CLI, cron,
API token).

## Operation

`Campanella\Access\Operation` · **Public** · `enum: string`

The system's vocabulary of operations: `View`, `Create`, `Update`, `Delete`,
`Publish`, `Unpublish` (their value is the lowercase name, e.g. `'view'`).

## AccessPolicy

`Campanella\Access\AccessPolicy` · **Public** · `interface` · container: `AccessPolicy::class`

An access policy has two faces, and both must mean the same thing:

| Method | When it runs | Task |
|---|---|---|
| `constrain(Query $query, Actor $actor): Query` | Before every query (`QueryEngine`) | Adds the visibility conditions to the Query, so that filtering happens in SQL |
| `allows(Actor $actor, Operation $operation, CampanellaObject $object): bool` | For individual operations (`ObjectService`) | Decides for a concrete, loaded object |

Because `constrain()` works in the [condition language](04-query.md#conditions),
arbitrary PHP logic cannot go into it. This is intentional: it is the only way
it can be compiled into SQL.

## DefaultPolicy

`Campanella\Access\DefaultPolicy` · **Public** · `final class`

The built-in policy, deny by default:

| Actor | View | Every other operation |
|---|---|---|
| with the `administrator` role | everything | everything |
| with the `editor` role (`DefaultPolicy::EDITOR`, since 0.0.3) | everything, drafts included | Create, Update, Publish, Unpublish, but not on users (Authenticatable); Delete is denied |
| anyone else | what is not Publishable, or is published and whose `published_at` has passed | denied |

`constrain()` expresses the same in the Query as follows (`administrator` and
`editor` get no condition):

```
(NOT HasCapability(publishable)) OR (status = 'published' AND published_at <= now)
```

## Custom policy

The policy can be replaced. Override the `AccessPolicy::class` entry in the
container (see [9. System](09-system.md#container)).

```php
final class ModeratorPolicy implements AccessPolicy
{
    public function __construct(private readonly AccessPolicy $fallback = new DefaultPolicy())
    {
    }

    public function constrain(Query $query, Actor $actor): Query
    {
        // Moderators see drafts too.
        return $actor->hasRole('moderator') ? $query : $this->fallback->constrain($query, $actor);
    }

    public function allows(Actor $actor, Operation $operation, CampanellaObject $object): bool
    {
        if ($actor->hasRole('moderator') && $operation !== Operation::Delete) {
            return true;
        }

        return $this->fallback->allows($actor, $operation, $object);
    }
}
```

## AccessDeniedException

`Campanella\Access\AccessDeniedException` · **Public** · `RuntimeException`

Thrown by `ObjectService` when `allows()` denies the operation.
`static for(Actor $actor, Operation $operation, string $target): self` builds
the message.
