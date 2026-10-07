# PHP API

Reference for the Campanella 0.0.6 PHP API. Every class is in the `Campanella\`
namespace, in the `src/` folder (PSR-4).

## Chapters

1. [Overview](01-overview.md): layers, the path of a request, principles
2. [Objects](02-objects.md): `CampanellaObject`, `Field`, `FieldType`, `FieldStorage`, `Blueprint`
3. [Capabilities](03-capabilities.md): the contract, the built-in capabilities, writing a new capability
4. [Query](04-query.md): queries, conditions, scopes, `QueryEngine`, `ResultSet`
5. [Access control](05-access.md): `Actor`, `Operation`, `AccessPolicy`
6. [Services](06-services.md): `ObjectService`, `ObjectRepository`, validation
7. [HTTP and view](07-http-and-view.md): `Request`, `Router`, controllers, `Presentation`, Twig
8. [Database](08-database.md): `Connection`, schema, installer
9. [System](09-system.md): `Kernel`, `Container`, configuration, CLI, helper classes
10. [Relations](10-relations.md): `Relation`, `Cardinality`, `whereRelated`, `RelationLoader`, Blueprint lists
11. [Users and login](11-users.md): `Identifiable`, `Authenticatable`, `Authorable`, `AuthService`, session, CSRF, `LoginGuard`
12. [Translation](12-translation.md): `Translator`, `Message`, language files, `t()` in templates
13. [Admin UI](13-admin.md): access, `AdminAccess`, `Flash`, admin templates
14. [System check](14-system-check.md): `SystemCheck`, `CheckResult`, `TemplateCache`, the System page
15. [HTML texts](15-html.md): the allowlist, `HtmlSanitizer`, `html:sanitize`
16. [Images](16-media.md): the `image` Blueprint, `MediaFile`, `MediaService`, `ImageProcessor`, `MediaStorage`
17. [Migrations and backups](17-migrations.md): `Migration`, `MigrationContext`, `Migrator`, `migrate`, `DatabaseBackup`, `db:backup`
18. [Trees](18-trees.md): `Hierarchical`, the rules of a tree, `TreeBuilder`

## Namespaces

| Namespace | Layer | Contents |
|---|---|---|
| `Campanella\Model` | Model | Object, fields, Blueprint, Repository |
| `Campanella\Capability` | Model | The Capability contract and the built-in capabilities |
| `Campanella\Tree` | Model | Trees of Hierarchical objects: building them, keeping them consistent |
| `Campanella\Query` | Model | Query, conditions, compiler, executor |
| `Campanella\Access` | Model | Actors, operations, policies |
| `Campanella\Relation` | Model | Relation definitions and loading |
| `Campanella\Auth` | Service | Login, logout, current user, login guards |
| `Campanella\Security` | Infrastructure | CSRF token, login throttling |
| `Campanella\I18n` | Infrastructure | Translation of user-facing texts |
| `Campanella\Service` | Service | Business operations with access control checks |
| `Campanella\Controller` | Controller | Handling HTTP requests |
| `Campanella\View` | View | Presentation, Twig integration |
| `Campanella\Http` | Infrastructure | Request, response, routing |
| `Campanella\Database` | Infrastructure | PDO layer, schema (definitions, reading the database, comparing), installer, backups; `Database\Migration`: migrations |
| `Campanella\Core` | Infrastructure | Kernel, container, configuration |
| `Campanella\System` | Infrastructure | System check, template cache |
| `Campanella\Html` | Infrastructure | HTML filter |
| `Campanella\Media` | Service | Uploaded images: checking, re-encoding, storing |
| `Campanella\Cli` | Infrastructure | Command-line tool |
| `Campanella\Support` | Helper | Slug, UUID |

## Quick example

```php
use Campanella\Access\Actor;
use Campanella\Capability\Publishable;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Service\ObjectService;

$c = $kernel->container();
$service = $c->get(ObjectService::class);
$queries = $c->get(QueryEngine::class);

// Create and publish
$article = $service->create(Actor::system(), 'article', [
    'title' => 'Neumann János',
    'lead'  => 'One of the fathers of computer architecture.',
    'body'  => "Born in Budapest in 1903.\n\nHis name is associated with…",
], publish: true);

$article->get('path');                                   // '/neumann-janos'
$article->as(Publishable::class)->isPublished();         // true

// Query: what the given Actor is allowed to see
$news = $queries->execute(
    Query::objects()->blueprint('article')->scope('published')->orderBy('published_at', 'DESC')->limit(10),
    Actor::anonymous(),
);
foreach ($news as $item) {
    echo $item->get('title'), PHP_EOL;
}
```
