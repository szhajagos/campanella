# 5. Jogosultság

A jogosultság három fogalomra épül: **ki** (Actor), **mit** tenne (Operation),
és **milyen szabály** dönt (AccessPolicy).

## Actor

`Campanella\Access\Actor` · **Nyilvános** · `final readonly class`

Aki cselekszik. A szerepkör nem Actor, hanem az Actor egyik tulajdonsága.

| Tag | Leírás |
|---|---|
| `$kind` | `ActorKind` |
| `$id` | A felhasználó azonosítója, ha van |
| `$roles` | `list<string>` |
| `$name` | Megjelenítendő név (naplóhoz, hibaüzenethez) |
| `ADMINISTRATOR` | A `'administrator'` szerepkör neve |
| `static anonymous(): self` | Bejelentkezés nélküli látogató |
| `static system(): self` | Maga a rendszer (CLI, telepítő, ütemezett feladat), `administrator` szerepkörrel |
| `hasRole(string $role): bool` | |
| `isAnonymous(): bool` | |

```php
$editor = new Actor(ActorKind::User, id: 12, roles: ['editor'], name: 'Kovács Anna');
```

A 0.0.1-ben még nincs bejelentkezés: a webes kérések mind `Actor::anonymous()`
nevében futnak, a CLI pedig `Actor::system()` nevében.

## ActorKind

`Campanella\Access\ActorKind` · **Nyilvános** · `enum: string`

`Anonymous = 'anonymous'`, `User = 'user'`, `Service = 'service'` (CLI, cron,
API-token).

## Operation

`Campanella\Access\Operation` · **Nyilvános** · `enum: string`

A rendszer műveleti nyelve: `View`, `Create`, `Update`, `Delete`, `Publish`,
`Unpublish` (értékük a kisbetűs név, pl. `'view'`).

## AccessPolicy

`Campanella\Access\AccessPolicy` · **Nyilvános** · `interface` · konténer: `AccessPolicy::class`

Egy jogosultsági szabálynak két arca van, és a kettőnek ugyanazt kell
jelentenie:

| Metódus | Mikor fut | Feladat |
|---|---|---|
| `constrain(Query $query, Actor $actor): Query` | Minden lekérdezés előtt (`QueryEngine`) | A Query-hez fűzi a láthatósági feltételeket, hogy a szűrés SQL-ben történjen |
| `allows(Actor $actor, Operation $operation, CampanellaObject $object): bool` | Egyedi műveletnél (`ObjectService`) | Egy konkrét, betöltött objektumra dönt |

Mivel a `constrain()` a [feltétel-nyelven](04-query.md#feltételek) dolgozik,
tetszőleges PHP-logika nem kerülhet bele. Ez szándékos: csak így fordítható SQL-re.

## DefaultPolicy

`Campanella\Access\DefaultPolicy` · **Nyilvános** · `final class`

A 0.0.1 szabálya, alapból tiltó (default deny):

| Actor | View | Minden más művelet |
|---|---|---|
| `administrator` szerepkörrel | mindent | mindent |
| bárki más | ami nem Publishable, vagy publikált és a `published_at` már elmúlt | tilos |

A `constrain()` ugyanezt a Query-ben így fejezi ki:

```
(NOT HasCapability(publishable)) OR (status = 'published' AND published_at <= most)
```

## Saját szabály

A szabály cserélhető. A konténerben az `AccessPolicy::class` bejegyzést kell
felülírni (lásd [9. Rendszer](09-rendszer.md#container)).

```php
final class EditorPolicy implements AccessPolicy
{
    public function __construct(private readonly AccessPolicy $fallback = new DefaultPolicy())
    {
    }

    public function constrain(Query $query, Actor $actor): Query
    {
        // A szerkesztő a piszkozatokat is látja.
        return $actor->hasRole('editor') ? $query : $this->fallback->constrain($query, $actor);
    }

    public function allows(Actor $actor, Operation $operation, CampanellaObject $object): bool
    {
        if ($actor->hasRole('editor') && $operation !== Operation::Delete) {
            return true;
        }

        return $this->fallback->allows($actor, $operation, $object);
    }
}
```

## AccessDeniedException

`Campanella\Access\AccessDeniedException` · **Nyilvános** · `RuntimeException`

Az `ObjectService` dobja, ha az `allows()` tilt.
`static for(Actor $actor, Operation $operation, string $target): self` készíti el
az üzenetet.
