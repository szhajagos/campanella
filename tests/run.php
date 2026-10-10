<?php

declare(strict_types=1);

/*
 * A simple, dependency-free test runner for the 0.0.1 core.
 *
 *   php tests/run.php
 *
 * Runs against a real database (with the config/local.php settings), but
 * with a separate "test_" table prefix, and cleans up after itself at the end.
 */

use Campanella\Access\Actor;
use Campanella\Access\DefaultPolicy;
use Campanella\Admin\AdminAccess;
use Campanella\Admin\Form\ObjectForm;
use Campanella\Capability\CapabilityException;
use Campanella\Capability\CapabilityRegistry;
use Campanella\Capability\Publishable;
use Campanella\Capability\PublishStatus;
use Campanella\Capability\Routable;
use Campanella\Capability\Textual;
use Campanella\Capability\TextFormat;
use Campanella\Capability\Titled;
use Campanella\Core\Version;
use Campanella\Core\Config;
use Campanella\Database\Connection;
use Campanella\Database\Installer;
use Campanella\Http\Flash;
use Campanella\Http\Request;
use Campanella\Http\Router;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\ObjectRepository;
use Campanella\Model\FieldStorage;
use Campanella\Model\FieldType;
use Campanella\Model\Field;
use Campanella\Model\ValidationException;
use Campanella\Model\CampanellaObject;
use Campanella\I18n\Message;
use Campanella\Query\Query;
use Campanella\Query\QueryCompiler;
use Campanella\Query\QueryEngine;
use Campanella\Query\QueryException;
use Campanella\Service\ObjectService;
use Campanella\View\Theme;
use Campanella\Support\Slugger;
use Campanella\Support\Uuid;
use Campanella\Relation\Cardinality;
use Campanella\Relation\Relation;
use Campanella\Relation\RelationLoader;
use Campanella\Auth\AuthService;
use Campanella\Auth\Guard\HoneypotGuard;
use Campanella\Capability\Authenticatable;
use Campanella\Capability\Authorable;
use Campanella\Controller\AuthController;
use Campanella\Http\ArraySessionStorage;
use Campanella\Http\Session;
use Campanella\I18n\Translator;
use Campanella\Security\Csrf;
use Campanella\Security\Throttle;

require dirname(__DIR__) . '/vendor/autoload.php';

/** For testing: an unregistered capability. */
#[\Campanella\Capability\AsCapability('fake')]
final class FakeCapability extends \Campanella\Capability\Capability
{
    public static function fields(): array
    {
        return [];
    }
}

$passed = 0;
$failed = 0;

/** The capability of the documentation's example (docs/php-api/03-capabilities.md). */
#[\Campanella\Capability\AsCapability('featured', label: 'Kiemelt')]
final class Featured extends \Campanella\Capability\Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            new \Campanella\Model\Field('featured', \Campanella\Model\FieldType::Boolean, required: true, default: false, indexed: true, label: 'Kiemelt'),
        ];
    }

    #[\Override]
    public static function scopes(): array
    {
        return [
            'featured' => static fn (Query $q): Query => $q->where('featured', '=', true),
        ];
    }

    public function isFeatured(): bool
    {
        return (bool) $this->object->get('featured');
    }

    public function feature(bool $featured = true): void
    {
        $this->object->set('featured', $featured);
    }
}

/** Versions of one capability, for the SchemaSync tests: v2 adds fields, v3 a required one without a default. */
#[\Campanella\Capability\AsCapability('rated', label: 'Értékelt')]
final class RatedV1 extends \Campanella\Capability\Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('score', FieldType::Integer, required: true, default: 3),
            new Field('rating_note', FieldType::String, length: 50),
            new Field('rating_tags', FieldType::String, length: 32, cardinality: Field::UNLIMITED),
            new Field('rating_extra', FieldType::String, storage: FieldStorage::Data),
        ];
    }
}

#[\Campanella\Capability\AsCapability('rated', label: 'Értékelt')]
final class RatedV2 extends \Campanella\Capability\Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            ...RatedV1::fields(),
            new Field('level', FieldType::Integer, required: true, default: 1, indexed: true),
            new Field('rating_comment', FieldType::Text, required: true, default: 'nincs'),
        ];
    }
}

#[\Campanella\Capability\AsCapability('rated', label: 'Értékelt')]
final class RatedV3 extends \Campanella\Capability\Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [...RatedV2::fields(), new Field('secret_code', FieldType::String, required: true, length: 16)];
    }
}

#[\Campanella\Capability\AsCapability('coded')]
final class Coded extends \Campanella\Capability\Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [new Field('serial', FieldType::String, required: true, length: 16)];
    }
}

#[\Campanella\Capability\AsCapability('handled')]
final class Handled extends \Campanella\Capability\Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [new Field('handle', FieldType::String, required: true, default: 'x', unique: true, length: 16)];
    }
}

/** A test capability with multi-valued fields only (so it has no table of its own). */
#[\Campanella\Capability\AsCapability('contactable', label: 'Elérhető')]
final class Contactable extends \Campanella\Capability\Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            new Field('phones', FieldType::String, required: true, length: 32, cardinality: 3),
            new Field('tags', FieldType::String, length: 64, cardinality: Field::UNLIMITED),
            new Field('lucky_numbers', FieldType::Integer, cardinality: Field::UNLIMITED),
            new Field('event_dates', FieldType::DateTime, cardinality: Field::UNLIMITED),
            new Field('notes', FieldType::Text, storage: FieldStorage::Data, cardinality: Field::UNLIMITED),
            new Field('flags', FieldType::Boolean, cardinality: Field::UNLIMITED),
            new Field('bios', FieldType::Text, cardinality: 2),
        ];
    }
}

/** Invalid: a queryable multi-valued String longer than the field_values column. */
#[\Campanella\Capability\AsCapability('too_long')]
final class TooLong extends \Campanella\Capability\Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [new Field('long_tags', FieldType::String, length: 300, cardinality: 2)];
    }
}

/**
 * The validation messages in Hungarian (the tests check the texts end to end: key, parameters, language file).
 *
 * @return array<string, string>
 */
function huMessages(ValidationException $e): array
{
    static $hu = null;
    $hu ??= Translator::fromDirectory(dirname(__DIR__) . '/lang', 'hu');

    return $e->messages($hu);
}

function test(string $name, callable $body): void
{
    global $passed, $failed;
    try {
        $body();
        $passed++;
        echo "  ✔ {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✘ {$name}\n      " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function check(bool $condition, string $message = 'condition not met'): void
{
    if (!$condition) {
        // Where it failed, so an unnamed check can be found.
        throw new RuntimeException($message . ' (line ' . (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'] ?? '?') . ')');
    }
}

/** @param class-string<Throwable> $class */
function throws(string $class, callable $body): void
{
    try {
        $body();
    } catch (Throwable $e) {
        check($e instanceof $class, "{$class} expected, got " . get_class($e) . ': ' . $e->getMessage());

        return;
    }
    throw new RuntimeException("{$class} exception expected, but none was thrown.");
}

// --- Setup with a separate table prefix ------------------------------------

$config = Config::load(dirname(__DIR__) . '/config');
$db = Connection::fromConfig(['prefix' => 'test_'] + $config->get('database'));
$capabilities = new CapabilityRegistry($config->get('capabilities'));
$blueprints = new BlueprintRegistry($capabilities, require dirname(__DIR__) . '/config/blueprints.php');
$repository = new ObjectRepository($db, $capabilities, $blueprints);
$policy = new DefaultPolicy();
$engine = new QueryEngine($db, new QueryCompiler($capabilities, $blueprints), $repository, $capabilities, $policy);
$loader = new RelationLoader($engine);
$service = new ObjectService($repository, $policy);
$installer = new Installer($db, $capabilities, new \Campanella\Database\Migration\Migrator($db, \Campanella\Database\Migration\MigrationRegistry::fromClasses(\Campanella\Database\Migration\CoreMigrations::classes())));

$admin = Actor::system();
$anon = Actor::anonymous();

$dropAll = static function () use ($db, $installer): void {
    $db->execute('SET FOREIGN_KEY_CHECKS = 0');
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('cap_featured'));
    foreach (array_reverse($installer->tables()) as $table) {
        $db->execute('DROP TABLE IF EXISTS ' . $db->table($table->name));
    }
    $db->execute('SET FOREIGN_KEY_CHECKS = 1');
};
$dropAll();
$installer->install();

echo "Campanella tests (PHP " . PHP_VERSION . ", " . $db->serverVersion() . ")\n\n";

// --- Support classes --------------------------------------------------------

echo "Support\n";

test('Slugger: Hungarian accented letters', function (): void {
    check(Slugger::slugify('Árvíztűrő tükörfúrógép') === 'arvizturo-tukorfurogep');
    check(Slugger::slugify('  Megjelent a 0.0.1!  ') === 'megjelent-a-0-0-1');
});

test('UUID v7: format and time ordering', function (): void {
    $a = Uuid::v7();
    usleep(2000);
    $b = Uuid::v7();
    check(Uuid::isValid($a) && $a[14] === '7', $a);
    check(strcmp($a, $b) < 0, 'the later UUID is not greater');
});

test('Request: installation in a subdirectory', function (): void {
    $_SERVER = ['REQUEST_URI' => '/oldal/hirek/?page=2', 'SCRIPT_NAME' => '/oldal/public/index.php', 'REQUEST_METHOD' => 'GET'];
    $request = Request::fromGlobals();
    check($request->basePath === '/oldal' && $request->path === '/hirek', $request->basePath . ' ' . $request->path);
});

// --- Capability contract ----------------------------------------------------

echo "\nCapability\n";

test('Dependencies are resolved (Routable → Titled)', function () use ($capabilities): void {
    check(array_keys($capabilities->resolve([Routable::class])) === ['titled', 'routable']);
});

test('The page Blueprint gets Titled as a dependency', function () use ($blueprints): void {
    check(isset($blueprints->get('page')->capabilities['titled']));
});

test('Registering a conflicting field name fails', function (): void {
    throws(CapabilityException::class, fn () => new CapabilityRegistry([Titled::class, Titled::class]));
});

test('as() fails for a missing capability', function () use ($repository): void {
    $object = $repository->create('article', ['title' => 'x']);
    check($object->has('publishable') && $object->has(Publishable::class));
    throws(CapabilityException::class, fn () => $object->as(FakeCapability::class));
});

test('An unknown field on create fails', function () use ($repository): void {
    throws(OutOfBoundsException::class, fn () => $repository->create('article', ['titel' => 'elgépelve']));
});

// --- Storage ----------------------------------------------------------------

echo "\nRepository\n";

test('Save and reload: table and data fields', function () use ($service, $repository, $admin): void {
    $object = $service->create($admin, 'article', ['title' => 'Első cikk', 'lead' => 'Bevezető', 'body' => "A\n\nB", 'format' => 'plain']);
    $loaded = $repository->find((int) $object->id());
    check($loaded !== null);
    check($loaded->get('title') === 'Első cikk');
    check($loaded->get('lead') === 'Bevezető' && $loaded->get('body') === "A\n\nB");
    check($loaded->get('path') === '/elso-cikk', (string) $loaded->get('path'));
    check($loaded->as(Publishable::class)->status() === PublishStatus::Draft);
    check($loaded->as(Textual::class)->format() === TextFormat::Plain);
    check($loaded->uuid() === $object->uuid());
});

test('Missing required field: ValidationException', function () use ($service, $admin): void {
    throws(ValidationException::class, fn () => $service->create($admin, 'article', ['body' => 'cím nélkül']));
});

test('Path already taken: ValidationException, the transaction is rolled back', function () use ($service, $admin, $engine): void {
    $before = $engine->count(Query::objects(), $admin);
    throws(ValidationException::class, fn () => $service->create($admin, 'article', ['title' => 'Első cikk']));
    check($engine->count(Query::objects(), $admin) === $before, 'a half-created object was left in the database');
});

test('Update and delete (capability rows are deleted too)', function () use ($service, $repository, $admin, $db): void {
    $object = $service->create($admin, 'page', ['title' => 'Ideiglenes']);
    $service->update($admin, $object, ['title' => 'Átnevezett']);
    check($repository->find((int) $object->id())?->get('title') === 'Átnevezett');

    $service->delete($admin, $object);
    check($repository->find((int) $object->id()) === null);
    check((int) $db->fetchValue('SELECT COUNT(*) FROM {cap_titled} WHERE object_id = :id', ['id' => $object->id()]) === 0);
});

// --- Query and access control ----------------------------------------------

echo "\nQuery + Access\n";

$past = new DateTimeImmutable('-1 hour');
$future = new DateTimeImmutable('+1 day');
$published = $service->create($admin, 'article', ['title' => 'Publikált']);
$service->publish($admin, $published, $past);
$scheduled = $service->create($admin, 'article', ['title' => 'Időzített']);
$service->publish($admin, $scheduled, $future);

test('Anonymous sees only the published one (SQL-level filtering)', function () use ($engine, $anon): void {
    $titles = array_map(fn ($o) => $o->get('title'), $engine->execute(Query::objects(), $anon)->items);
    check($titles === ['Publikált'], implode(', ', $titles));
});

test('The administrator sees everything', function () use ($engine, $admin): void {
    check($engine->count(Query::objects()->blueprint('article'), $admin) === 3);
});

test('Scheduled publishing: a future one is not visible yet', function () use ($scheduled, $anon, $policy): void {
    check(!$policy->allows($anon, \Campanella\Access\Operation::View, $scheduled));
    check($scheduled->as(Publishable::class)->isPublished(new DateTimeImmutable('+2 days')));
});

test('Anonymous cannot create an object', function () use ($service, $anon): void {
    throws(\Campanella\Access\AccessDeniedException::class, fn () => $service->create($anon, 'article', ['title' => 'Nem']));
});

test('Scope, sorting, paging, total count', function () use ($engine, $admin): void {
    $result = $engine->execute(
        Query::objects()->having('routable')->orderBy('title', 'ASC')->page(1, 2),
        $admin,
        withTotal: true,
    );
    check($result->total === 3 && count($result) === 2 && $result->pageCount() === 2);
    check($result->items[0]->get('title') === 'Első cikk');

    $scoped = $engine->execute(Query::objects()->scope('published'), $admin);
    check(count($scoped) === 1 && $scoped->first()?->get('title') === 'Publikált');
});

test('Filtering on a data (JSON) field is not allowed', function () use ($engine, $admin): void {
    throws(QueryException::class, fn () => $engine->execute(Query::objects()->where('lead', '=', 'x'), $admin));
});

test('Unknown field and operator: QueryException', function () use ($engine, $admin): void {
    throws(QueryException::class, fn () => $engine->execute(Query::objects()->where('nincs', '=', 1), $admin));
    throws(QueryException::class, fn () => Query::objects()->where('title', 'LIKEE', 'x'));
});

test('Query is immutable', function (): void {
    $base = Query::objects()->limit(5);
    $derived = $base->limit(10)->where('title', '=', 'x');
    check($base->getLimit() === 5 && $base->conditions()->conditions === []);
    check($derived->getLimit() === 10);
});

// --- Relations (0.0.2) -------------------------------------------------------

echo "\nRelations\n";

$science = $service->create($admin, 'category', ['title' => 'Kat Tudomány'], publish: true);
$history = $service->create($admin, 'category', ['title' => 'Kat Történelem'], publish: true);
$hidden = $service->create($admin, 'category', ['title' => 'Kat Rejtett']);   // draft
$tagged = $service->create($admin, 'article', ['title' => 'Kapcsolt cikk'], publish: true);

test('Saving and reloading a relation, in order', function () use ($repository, $tagged, $science, $history, $hidden): void {
    $tagged->setRelated('categories', [$history, $science->id(), $hidden]);
    $repository->save($tagged);
    $loaded = $repository->find((int) $tagged->id());
    check($loaded?->relatedIds('categories') === [$history->id(), $science->id(), $hidden->id()], json_encode($loaded?->relatedIds('categories')));

    $loaded->unrelate('categories', $history);
    $loaded->relate('categories', $history);            // goes to the end
    $loaded->relate('categories', $science);            // already there: no duplicate
    $repository->save($loaded);
    check($repository->find((int) $tagged->id())?->relatedIds('categories') === [$science->id(), $hidden->id(), $history->id()]);
});

test('whereRelated / whereNotRelated (with access control)', function () use ($engine, $admin, $anon, $science, $history): void {
    $titles = fn ($q, $actor) => array_map(fn ($o) => $o->get('title'), $engine->execute($q, $actor)->items);
    check($titles(Query::objects()->whereRelated('categories', $science), $anon) === ['Kapcsolt cikk']);
    check($titles(Query::objects()->whereRelated('categories', $science, $history), $admin) === ['Kapcsolt cikk']);
    check($titles(Query::objects()->blueprint('article')->whereRelated('categories'), $admin) === ['Kapcsolt cikk']);
    check(!in_array('Kapcsolt cikk', $titles(Query::objects()->whereNotRelated('categories'), $admin), true));
    throws(QueryException::class, fn () => $engine->execute(Query::objects()->whereRelated('nincs_ilyen'), $admin));
});

test('RelationLoader: one query, the draft target is left out', function () use ($repository, $loader, $anon, $admin, $tagged): void {
    $forAnon = $repository->find((int) $tagged->id());
    $loader->resolve([$forAnon], $anon);
    check(array_map(fn ($o) => $o->get('title'), $forAnon->relatedObjects('categories')) === ['Kat Tudomány', 'Kat Történelem']);

    $forAdmin = $repository->find((int) $tagged->id());
    $loader->resolve([$forAdmin], $admin);
    check(count($forAdmin->relatedObjects('categories')) === 3);

    $fresh = $repository->find((int) $tagged->id());
    throws(LogicException::class, fn () => $fresh->relatedObjects('categories'));
});

test('Invalid target: wrong Blueprint, nonexistent, unsaved', function () use ($service, $repository, $admin): void {
    $page = $service->create($admin, 'page', ['title' => 'Nem kategória']);
    $article = $repository->create('article', ['title' => 'Hibás kapcsolat']);
    $article->relate('categories', $page);
    try {
        $repository->save($article);
        check(false, 'no exception was thrown');
    } catch (ValidationException $e) {
        check(isset(huMessages($e)['categories']) && str_contains(huMessages($e)['categories'], 'page'), json_encode(huMessages($e), JSON_UNESCAPED_UNICODE));
    }
    check($article->isNew(), 'the invalid object must not be saved');

    $article->setRelated('categories', [999999]);
    throws(ValidationException::class, fn () => $repository->save($article));
    throws(InvalidArgumentException::class, fn () => $article->relate('categories', $repository->create('category', ['title' => 'Mentetlen'])));
});

test('Single and required relation', function () use ($blueprints, $repository, $admin, $service): void {
    $blueprints->define('node', [
        'capabilities' => [Titled::class],
        'relations' => [
            new Relation('parent_node', Cardinality::One, targetBlueprints: ['node']),
            new Relation('owner_node', Cardinality::One, targetBlueprints: ['node'], required: true),
        ],
    ]);
    $root = $repository->create('node', ['title' => 'Gyökér']);
    try {
        $repository->save($root);
        check(false, 'no exception was thrown');
    } catch (ValidationException $e) {
        check((huMessages($e)['owner_node'] ?? '') === 'kötelező kapcsolat', json_encode(huMessages($e), JSON_UNESCAPED_UNICODE));
    }

    $node = $repository->create('node', ['title' => 'Node']);
    throws(InvalidArgumentException::class, fn () => $node->setRelated('parent_node', [1, 2]));
    $node->relate('parent_node', 1);
    $node->relate('parent_node', 2);                  // a single relation replaces it
    check($node->relatedIds('parent_node') === [2]);

    $node->relate('owner_node', $service->create($admin, 'category', ['title' => 'Nem node']));
    throws(ValidationException::class, fn () => $repository->save($node));   // wrong Blueprint
});

test('Self-reference is forbidden; deleting the target removes the relation', function () use ($blueprints, $repository, $service, $admin): void {
    $blueprints->define('loose', ['capabilities' => [Titled::class], 'relations' => [new Relation('parent_loose', Cardinality::One)]]);
    $loose = $repository->create('loose', ['title' => 'Laza']);
    $repository->save($loose);
    $loose->relate('parent_loose', $loose);
    throws(ValidationException::class, fn () => $repository->save($loose));

    $target = $service->create($admin, 'category', ['title' => 'Törlendő kategória'], publish: true);
    $article = $service->create($admin, 'article', ['title' => 'Kaszkád cikk']);
    $article->relate('categories', $target);
    $repository->save($article);
    $service->delete($admin, $target);
    check($repository->find((int) $article->id())?->relatedIds('categories') === []);
});

test('Relation name conflicts', function () use ($blueprints): void {
    throws(CapabilityException::class, fn () => $blueprints->define('x', [
        'capabilities' => [Titled::class],
        'relations' => [new Relation('title')],                              // field name
    ]));
    throws(CapabilityException::class, fn () => $blueprints->define('y', [
        'capabilities' => [Titled::class],
        'relations' => [new Relation('categories', Cardinality::One)],       // defined differently in 'article'
    ]));
    $blueprints->define('z', [
        'capabilities' => [Titled::class],
        'relations' => [new Relation('categories', Cardinality::Many, targetBlueprints: ['category'], label: 'relation.categories')],
    ]);                                                                      // identical definition: allowed
});

// --- Users and login (0.0.3) -------------------------------------------------

echo "\nUsers and login\n";

$newUser = static function (string $email, string $password, array $roles = []) use ($repository) {
    $user = $repository->create('user', ['title' => 'Teszt ' . $email, 'email' => $email]);
    $user->as(Authenticatable::class)->setPassword($password);
    $user->as(Authenticatable::class)->setRoles($roles);
    $repository->save($user);

    return $user;
};
$authFor = static function (ArraySessionStorage $storage, array $guards = []) use ($repository, $engine, $db) {
    $session = new Session($storage, 7200);
    $csrf = new Csrf($session);

    return [new AuthService($repository, $engine, $session, new Throttle($db), $csrf, ['max_attempts' => 3, 'max_attempts_per_ip' => 50, 'decay_seconds' => 900], $guards), $csrf, $session];
};
$req = static fn (array $post = [], string $ip = '10.0.0.1') => new Request($post === [] ? 'GET' : 'POST', '/login', post: $post, ip: $ip);

$editorUser = $newUser('Szerkeszto@Example.hu', 'szerkeszto-jelszo', ['editor']);

test('Identifiable: normalized, unique, valid e-mail address', function () use ($repository, $editorUser, $newUser): void {
    check($editorUser->get('email') === 'szerkeszto@example.hu');
    throws(ValidationException::class, fn () => $newUser('szerkeszto@example.hu', 'masik-jelszo-1'));
    $bad = $repository->create('user', ['title' => 'Rossz', 'email' => 'nem-email']);
    $bad->as(Authenticatable::class)->setPassword('eleg-hosszu-jelszo');
    try {
        $repository->save($bad);
        check(false, 'no exception was thrown');
    } catch (ValidationException $e) {
        check((huMessages($e)['email'] ?? '') === 'érvénytelen e-mail-cím', json_encode(huMessages($e), JSON_UNESCAPED_UNICODE));
    }
});

test('Authenticatable: password rule, hash, hidden fields, roles', function () use ($repository, $editorUser): void {
    $auth = $editorUser->as(Authenticatable::class);
    throws(ValidationException::class, fn () => $auth->setPassword('rovid'));
    throws(ValidationException::class, fn () => $auth->setPassword(str_repeat('x', 73)));
    check($auth->verifyPassword('szerkeszto-jelszo') && !$auth->verifyPassword('mas-jelszo-1'));
    check(str_starts_with((string) $editorUser->get('password_hash'), '$2y$') || str_starts_with((string) $editorUser->get('password_hash'), '$argon'));
    check(!isset($editorUser->password_hash) && $editorUser->password_hash === null, 'the hash is visible to the template');
    check(!isset($editorUser->email), 'the e-mail address is visible to the template');

    $reloaded = $repository->find((int) $editorUser->id());
    check($reloaded?->as(Authenticatable::class)->roles() === ['editor']);
    $reloaded->as(Authenticatable::class)->setRoles(['Rossz Szerep']);
    throws(ValidationException::class, fn () => $repository->save($reloaded));
});

test('Login: wrong credentials, success, new session ID, Actor', function () use ($authFor, $req): void {
    $storage = new ArraySessionStorage();
    [$auth] = $authFor($storage);

    $wrong = $auth->attempt($req(['x' => 1]), 'szerkeszto@example.hu', 'rossz-jelszo-1');
    $unknown = $auth->attempt($req(['x' => 1]), 'nincs@example.hu', 'rossz-jelszo-1');
    check(!$wrong->success && $wrong->error === AuthService::GENERIC_ERROR && $unknown->error === AuthService::GENERIC_ERROR);

    $ok = $auth->attempt($req(['x' => 1]), '  SZERKESZTO@example.hu ', 'szerkeszto-jelszo');
    check($ok->success && $storage->generation() === 1, 'the session ID was not regenerated');

    $storage->endRequest();
    [$next] = $authFor($storage);
    $actor = $next->currentActor($req());
    check($actor->kind === \Campanella\Access\ActorKind::User && $actor->hasRole('editor') && str_starts_with($actor->name, 'Teszt'));

    $next->logout();
    $storage->endRequest();
    [$after] = $authFor($storage);
    check($after->currentActor($req())->isAnonymous());
});

test('Login: login throttling', function () use ($authFor, $req): void {
    [$auth] = $authFor(new ArraySessionStorage());
    for ($i = 0; $i < 3; $i++) {
        $auth->attempt($req(['x' => 1], '10.9.9.9'), 'szerkeszto@example.hu', 'rossz-jelszo-1');
    }
    $blocked = $auth->attempt($req(['x' => 1], '10.9.9.9'), 'szerkeszto@example.hu', 'szerkeszto-jelszo');
    check(!$blocked->success && $blocked->error === 'auth.too_many_attempts' && ($blocked->errorParams['minutes'] ?? 0) >= 1, $blocked->error);
    // The same account can still log in from another IP address.
    check($auth->attempt($req(['x' => 1], '10.8.8.8'), 'szerkeszto@example.hu', 'szerkeszto-jelszo')->success);
});

test('Login: blocked account, and blocking also ends the existing session', function () use ($authFor, $req, $newUser, $repository): void {
    $user = $newUser('tiltott@example.hu', 'tiltott-jelszo-1');
    $storage = new ArraySessionStorage();
    [$auth] = $authFor($storage);
    check($auth->attempt($req(['x' => 1]), 'tiltott@example.hu', 'tiltott-jelszo-1')->success);

    $user->as(Authenticatable::class)->block();
    $repository->save($user);
    $storage->endRequest();
    [$next] = $authFor($storage);
    check($next->currentActor($req())->isAnonymous(), 'the blocked user stayed logged in');
    $again = $next->attempt($req(['x' => 1]), 'tiltott@example.hu', 'tiltott-jelszo-1');
    check(!$again->success && $again->error === 'auth.account_blocked');
});

test('Honeypot guard and CSRF', function () use ($authFor, $req): void {
    $storage = new ArraySessionStorage();
    [$auth, $csrf] = $authFor($storage, [new HoneypotGuard()]);
    $trap = $auth->attempt($req([HoneypotGuard::FIELD => 'http://spam.example']), 'szerkeszto@example.hu', 'szerkeszto-jelszo');
    check(!$trap->success && $trap->error === AuthService::GENERIC_ERROR);
    check(str_contains((new HoneypotGuard())->fields(), 'name="website"'));

    $token = $csrf->token($req());
    check(strlen($token) === 64);
    check($csrf->isValid($req([Csrf::FIELD => $token])) && !$csrf->isValid($req([Csrf::FIELD => 'hamis'])));
    check($auth->attempt($req([HoneypotGuard::FIELD => '']), 'szerkeszto@example.hu', 'szerkeszto-jelszo')->success);
    check(!$csrf->isValid($req([Csrf::FIELD => $token])), 'the pre-login token is still valid after login');
});

test('Session: idle timeout', function () use ($req): void {
    $storage = new ArraySessionStorage();
    $session = new Session($storage, 60);
    $session->start($req());
    $session->set('x', 1);
    $storage->set('_last_activity', time() - 120);
    $storage->endRequest();
    check(!$session->resume($req()) && $session->get('x') === null);
});

test('Access control: editor role', function () use ($policy, $engine, $editorUser, $service, $admin): void {
    $editor = AuthService::actorFor($editorUser);
    $draft = $service->create($admin, 'article', ['title' => 'Szerkesztői piszkozat']);
    check($policy->allows($editor, \Campanella\Access\Operation::View, $draft));
    check($policy->allows($editor, \Campanella\Access\Operation::Publish, $draft));
    check(!$policy->allows($editor, \Campanella\Access\Operation::Delete, $draft));
    check(!$policy->allows($editor, \Campanella\Access\Operation::Update, $editorUser), 'editor can update a user');
    check($engine->count(Query::objects()->where('id', '=', (int) $draft->id()), $editor) === 1);
});

test('Author: the creating user automatically becomes the author', function () use ($service, $editorUser, $repository, $loader, $anon): void {
    $article = $service->create(AuthService::actorFor($editorUser), 'article', ['title' => 'Saját cikk'], publish: true);
    check($article->as(Authorable::class)->authorId() === $editorUser->id());
    $loaded = $repository->find((int) $article->id());
    $loader->resolve([$loaded], $anon);
    check(($loaded->relatedObjects('author')[0] ?? null)?->get('title') === 'Teszt Szerkeszto@Example.hu');
});

test('Safe redirect', function (): void {
    foreach (['/hirek' => '/hirek', '//gonosz.hu' => '/', 'https://gonosz.hu' => '/', '/\\gonosz' => '/', '' => '/', "/x\n" => '/'] as $in => $out) {
        check(AuthController::safeTarget($in) === $out, json_encode($in));
    }
});

test('Kernel: full login and logout through the form', function (): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";

        return;
    }
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));

    check($kernel->handle(new Request('GET', '/szerkesztoi-piszkozat'))->status === 404);
    $storage->endRequest();

    $form = $kernel->handle(new Request('GET', '/login'));
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $form->body, $m);
    check(isset($m[1]) && str_contains($form->body, 'name="website"'), 'missing CSRF or honeypot field');
    check(($form->headers['Cache-Control'] ?? '') === 'private, no-store');
    check(str_contains($form->body, 'class="hp" hidden aria-hidden="true"') && !str_contains($form->body, 'style="'), 'the honeypot is hidden without CSS and without an inline style');
    check(str_contains($form->body, 'campanella.css?v=' . Version::CAMPANELLA), 'asset() does not append the version');
    $storage->endRequest();

    $login = $kernel->handle(new Request('POST', '/login', post: [
        '_csrf' => $m[1], 'email' => 'szerkeszto@example.hu', 'password' => 'szerkeszto-jelszo',
        'website' => '', 'return' => '/szerkesztoi-piszkozat',
    ], ip: '10.7.7.7'));
    check($login->status === 303 && ($login->headers['Location'] ?? '') === '/szerkesztoi-piszkozat', (string) $login->status);
    $storage->endRequest();

    $page = $kernel->handle(new Request('GET', '/szerkesztoi-piszkozat'));
    check($page->status === 200 && str_contains($page->body, 'Kilépés'), 'the draft is not visible even when logged in');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $page->body, $m2);
    $storage->endRequest();

    $kernel->handle(new Request('POST', '/logout', post: ['_csrf' => $m2[1] ?? '']));
    $storage->endRequest();
    check($kernel->handle(new Request('GET', '/szerkesztoi-piszkozat'))->status === 404, 'still visible after logout');
    putenv('CAMPANELLA_DB_PREFIX');
});

// --- Field cardinality (0.0.4) ----------------------------------------------

echo "\nField cardinality\n";

$contacts = (function () use ($db): array {
    $registry = new CapabilityRegistry([Titled::class, Contactable::class]);
    $blueprints = new BlueprintRegistry($registry, [
        'contact' => [
            'capabilities' => [Titled::class, Contactable::class],
            'fields' => [new Field('aliases', FieldType::String, cardinality: 2)],
            'relations' => [new Relation('friends', Cardinality::Many, targetBlueprints: ['contact', 'contact_free'], max: 2)],
            'cardinality' => ['tags' => 5],
        ],
        'contact_free' => ['capabilities' => [Titled::class, Contactable::class]],
        'note' => ['capabilities' => [Titled::class]],
    ]);
    (new Installer($db, $registry))->install();
    $repository = new ObjectRepository($db, $registry, $blueprints);
    $engine = new QueryEngine($db, new QueryCompiler($registry, $blueprints), $repository, $registry, new DefaultPolicy());

    return [$registry, $blueprints, $repository, $engine];
})();
[$contactRegistry, $contactBlueprints, $contactRepository, $contactEngine] = $contacts;

test('Field definition: cardinality rules', function (): void {
    throws(InvalidArgumentException::class, fn () => new Field('x', FieldType::String, cardinality: 0));
    throws(InvalidArgumentException::class, fn () => new Field('x', FieldType::String, unique: true, cardinality: 2));
    // Longer than the field_values column: rejected when the capability is registered,
    // but fine as a Blueprint's own field (those are stored in data).
    throws(CapabilityException::class, fn () => new CapabilityRegistry([TooLong::class]));
    new Field('x', FieldType::String, length: 300, cardinality: 2);

    $three = new Field('x', FieldType::String, cardinality: 3);
    check($three->isMultiple() && !$three->isUnlimited() && $three->usesValueTable());
    check($three->withCardinality(2)->cardinality === 2);
    throws(InvalidArgumentException::class, fn () => $three->withCardinality(4));   // an increase
    throws(InvalidArgumentException::class, fn () => $three->withCardinality(1));   // would become single-valued
    throws(InvalidArgumentException::class, fn () => (new Field('y', FieldType::String))->withCardinality(2));
    check((new Field('z', FieldType::String, cardinality: Field::UNLIMITED))->withCardinality(50)->cardinality === 50);
    check($three->asData()->cardinality === 3 && !$three->asData()->usesValueTable());
});

test('Multi-valued field values are always lists', function () use ($contactRepository): void {
    $contact = $contactRepository->create('contact', ['title' => 'Kovács Béla']);
    check($contact->get('phones') === [] && $contact->get('tags') === [] && $contact->get('aliases') === []);
    $contact->set('phones', '+36 1 111 1111');
    check($contact->get('phones') === ['+36 1 111 1111'], 'a single value becomes a one-element list');
    $contact->set('lucky_numbers', ['7', 13, '', null]);
    check($contact->get('lucky_numbers') === [7, 13], 'items are cast, empty items are dropped');
    $contact->set('tags', null);
    check($contact->get('tags') === []);
    $contact->set('tags', new ArrayIterator(['a', 'b']));
    check($contact->get('tags') === ['a', 'b'], 'an iterable is accepted');
    $contact->set('flags', [true, false, 0, '1']);
    check($contact->get('flags') === [true, false, false, true], 'booleans');
    check($contact->tags === ['a', 'b'], 'visible from templates as a list');
});

test('Multi-valued fields: save and load, in order', function () use ($contactRepository): void {
    $contact = $contactRepository->create('contact', [
        'title' => 'Nagy Anna',
        'phones' => ['+36 30 222 2222', '+36 1 333 3333'],
        'tags' => ['php', 'sql', 'php'],
        'lucky_numbers' => [42, 7],
        'event_dates' => ['2026-10-01 10:00:00', new DateTimeImmutable('2025-01-01 12:30:00', new DateTimeZone('Europe/Budapest'))],
        'notes' => ['első jegyzet', 'második jegyzet'],
        'aliases' => ['Anni'],
        'flags' => [false, true],
        'bios' => ["Hosszú\nszöveg", 'Második'],
    ]);
    $contactRepository->save($contact);

    $loaded = $contactRepository->find((int) $contact->id());
    check($loaded !== null);
    check($loaded->get('phones') === ['+36 30 222 2222', '+36 1 333 3333'], 'order of phones');
    check($loaded->get('tags') === ['php', 'sql', 'php'], 'duplicates and order are kept');
    check($loaded->get('lucky_numbers') === [42, 7]);
    $dates = array_map(fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i'), $loaded->get('event_dates'));
    check($dates === ['2026-10-01 10:00', '2025-01-01 11:30'], implode(', ', $dates) . ' (dates are stored in UTC)');
    check($loaded->get('notes') === ['első jegyzet', 'második jegyzet'], 'data (JSON) list');
    check($loaded->get('aliases') === ['Anni'], 'multi-valued Blueprint field');
    check($loaded->get('flags') === [false, true], 'booleans roundtrip');
    check($loaded->get('bios') === ["Hosszú\nszöveg", 'Második'], 'queryable text values');

    // Updating replaces the values; fewer values leave no leftovers.
    $loaded->set('phones', ['+36 20 444 4444']);
    $loaded->set('tags', []);
    $contactRepository->save($loaded);
    $again = $contactRepository->find((int) $contact->id());
    check($again !== null && $again->get('phones') === ['+36 20 444 4444'] && $again->get('tags') === []);
});

test('Multi-valued fields: required and value limits', function () use ($contactRepository): void {
    $contact = $contactRepository->create('contact', ['title' => 'Limit']);
    try {
        $contactRepository->save($contact);
        check(false, 'a contact without phones was saved');
    } catch (ValidationException $e) {
        check((huMessages($e)['phones'] ?? '') === 'kötelező mező', json_encode(huMessages($e), JSON_UNESCAPED_UNICODE) ?: '');
    }

    $contact->set('phones', ['1', '2', '3', '4']);
    $contact->set('tags', ['a', 'b', 'c', 'd', 'e', 'f']);      // narrowed to 5 in 'contact'
    $contact->set('aliases', ['x', 'y', 'z']);
    try {
        $contactRepository->save($contact);
        check(false, 'too many values were saved');
    } catch (ValidationException $e) {
        check((huMessages($e)['phones'] ?? '') === 'legfeljebb 3 érték adható meg', huMessages($e)['phones'] ?? '-');
        check((huMessages($e)['tags'] ?? '') === 'legfeljebb 5 érték adható meg', huMessages($e)['tags'] ?? '-');
        check((huMessages($e)['aliases'] ?? '') === 'legfeljebb 2 érték adható meg', huMessages($e)['aliases'] ?? '-');
    }

    // The narrowing also applies to a reloaded object.
    $contact->set('phones', ['1']);
    $contact->set('tags', []);
    $contact->set('aliases', []);
    $contactRepository->save($contact);
    $reloaded = $contactRepository->find((int) $contact->id());
    check($reloaded !== null && $reloaded->fields()['tags']->cardinality === 5, 'narrowed after loading');
    $reloaded->set('tags', ['a', 'b', 'c', 'd', 'e', 'f']);
    throws(ValidationException::class, fn () => $contactRepository->save($reloaded));

    // An item longer than the field's length (phones: 32 characters).
    $reloaded->set('tags', []);
    $reloaded->set('phones', [str_repeat('9', 33)]);
    try {
        $contactRepository->save($reloaded);
        check(false, 'a too long phone number was saved');
    } catch (ValidationException $e) {
        check((huMessages($e)['phones'] ?? '') === 'egy érték legfeljebb 32 karakter lehet', huMessages($e)['phones'] ?? '-');
    }

    // The same six tags are fine in the Blueprint that does not narrow the field.
    $free = $contactRepository->create('contact_free', ['title' => 'Szabad', 'phones' => ['1'], 'tags' => ['a', 'b', 'c', 'd', 'e', 'f']]);
    $contactRepository->save($free);
    $loaded = $contactRepository->find((int) $free->id());
    check($loaded !== null && count($loaded->get('tags')) === 6);
    $narrowed = $contactRepository->find((int) $free->id());
    check($narrowed !== null && $narrowed->fields()['tags']->isUnlimited(), 'no narrowing on contact_free');
});

test('Blueprint narrowing is checked when the Blueprint is defined', function () use ($contactRegistry): void {
    $blueprints = new BlueprintRegistry($contactRegistry);
    throws(CapabilityException::class, fn () => $blueprints->define('a', [
        'capabilities' => [Contactable::class], 'cardinality' => ['phones' => 4],          // an increase
    ]));
    throws(CapabilityException::class, fn () => $blueprints->define('b', [
        'capabilities' => [Contactable::class], 'cardinality' => ['title' => 2],           // not its capability field
    ]));
    throws(CapabilityException::class, fn () => $blueprints->define('c', [
        'capabilities' => [Titled::class, Contactable::class], 'cardinality' => ['title' => 2],   // single-valued
    ]));
    throws(CapabilityException::class, fn () => $blueprints->define('d', [
        'capabilities' => [Contactable::class],
        'fields' => [new Field('nicknames', FieldType::String, cardinality: 3)],
        'cardinality' => ['nicknames' => 2],                                                 // custom field
    ]));
    $ok = $blueprints->define('e', ['capabilities' => [Contactable::class], 'cardinality' => ['phones' => 2]]);
    check($ok->allFields()['phones']->cardinality === 2);
});

test('Queries on multi-valued fields', function () use ($contactRepository, $contactEngine, $admin): void {
    $make = function (string $title, array $values) use ($contactRepository): int {
        $object = $contactRepository->create('contact_free', ['title' => $title, 'phones' => ['1']] + $values);
        $contactRepository->save($object);

        return (int) $object->id();
    };
    $alpha = $make('Q Alfa', ['tags' => ['php', 'sql'], 'lucky_numbers' => [3, 30], 'event_dates' => ['2026-01-15 00:00:00']]);
    $beta = $make('Q Béta', ['tags' => ['go'], 'lucky_numbers' => [5]]);
    $gamma = $make('Q Gamma', ['flags' => [false]]);
    $note = $contactRepository->create('note', ['title' => 'Q Jegyzet']);   // does not have the fields at all
    $contactRepository->save($note);

    $ids = function (Query $query) use ($contactEngine, $admin): array {
        $query = $query->where('title', 'LIKE', 'Q %');
        $ids = array_map(fn ($o) => (int) $o->id(), $contactEngine->execute($query, $admin)->items);
        sort($ids);

        return $ids;
    };

    check($ids(Query::objects()->where('tags', '=', 'php')) === [$alpha], 'any value equals');
    check($ids(Query::objects()->where('tags', 'IN', ['go', 'sql'])) === [$alpha, $beta], 'IN');
    check($ids(Query::objects()->where('tags', 'LIKE', 's%')) === [$alpha], 'LIKE');
    check($ids(Query::objects()->where('tags', '!=', 'php')) === [$beta, $gamma], 'no value equals (objects without the field excluded)');
    check($ids(Query::objects()->where('tags', 'NOT IN', ['php', 'go', 'go'])) === [$gamma], 'NOT IN');
    check($ids(Query::objects()->where('tags', 'IS NULL')) === [$gamma, (int) $note->id()], 'no value at all (like a single-valued field)');
    check($ids(Query::objects()->where('flags', '=', false)) === [$gamma], 'boolean value');
    check($ids(Query::objects()->where('tags', 'IS NOT NULL')) === [$alpha, $beta], 'at least one value');
    check($ids(Query::objects()->where('lucky_numbers', '>', 20)) === [$alpha], 'integer comparison');
    check($ids(Query::objects()->where('event_dates', '>=', '2026-01-01 00:00:00')) === [$alpha], 'date comparison');
    check($ids(Query::objects()->where('tags', '=', 'php')->where('lucky_numbers', '=', 5)) === [], 'two subqueries together');

    $count = $contactEngine->count(Query::objects()->where('title', 'LIKE', 'Q %')->where('tags', 'IS NOT NULL'), $admin);
    check($count === 2, "count: {$count} (values must not multiply the rows)");
    $count = $contactEngine->count(Query::objects()->where('title', 'LIKE', 'Q %')->where('tags', '!=', 'php'), $admin);
    check($count === 2, "count with a negated condition: {$count}");

    throws(QueryException::class, fn () => $contactEngine->execute(Query::objects()->orderBy('tags'), $admin));
    throws(QueryException::class, fn () => $contactEngine->execute(Query::objects()->where('notes', '=', 'x'), $admin));
});

test('Deleting an object deletes its field values', function () use ($contactRepository, $db): void {
    $object = $contactRepository->create('contact_free', ['title' => 'Törlendő', 'phones' => ['1', '2'], 'tags' => ['x']]);
    $contactRepository->save($object);
    $id = (int) $object->id();
    $count = fn (): int => (int) $db->fetchValue('SELECT COUNT(*) FROM {field_values} WHERE object_id = :id', ['id' => $id]);
    check($count() === 3, (string) $count());
    $contactRepository->delete($object);
    check($count() === 0, 'field values left behind: ' . $count());
});

test('Relation limit (max)', function () use ($contactRepository): void {
    throws(InvalidArgumentException::class, fn () => new Relation('boss', Cardinality::One, max: 1));
    throws(InvalidArgumentException::class, fn () => new Relation('pals', Cardinality::Many, max: 0));

    $friends = [];
    foreach (['F1', 'F2', 'F3'] as $title) {
        $friend = $contactRepository->create('contact_free', ['title' => $title, 'phones' => ['1']]);
        $contactRepository->save($friend);
        $friends[] = $friend;
    }
    $contact = $contactRepository->create('contact', ['title' => 'Barátkozó', 'phones' => ['1']]);
    $contact->setRelated('friends', $friends);
    try {
        $contactRepository->save($contact);
        check(false, 'three friends were saved');
    } catch (ValidationException $e) {
        check((huMessages($e)['friends'] ?? '') === 'legfeljebb 2 kapcsolat adható meg', huMessages($e)['friends'] ?? '-');
    }
    $contact->unrelate('friends', $friends[2]);
    $contactRepository->save($contact);
    check(count($contact->relatedIds('friends')) === 2);
});

// --- Translation (0.0.4) -----------------------------------------------------

echo "\nTranslation\n";

test('Translator: current language, English fallback, parameters, plain text', function (): void {
    $translator = new Translator([
        'en' => ['a.hello' => 'Hello {name}!', 'a.only_en' => 'Only English'],
        'hu' => ['a.hello' => 'Szia {name}!'],
    ], 'hu');
    check($translator->translate('a.hello', ['name' => 'Anna']) === 'Szia Anna!');
    check($translator->translate('a.only_en') === 'Only English', 'falls back to English');
    check($translator->translate('Kész szöveg.') === 'Kész szöveg.', 'unknown key / plain text passes through');
    check($translator->has('a.only_en') && !$translator->has('a.nothing'));
    check($translator->locale() === 'hu' && $translator->locales() === ['en', 'hu']);
    throws(InvalidArgumentException::class, fn () => new Translator([], 'hungarian'));
});

test('Language files: every language has the same keys (lang:check)', function (): void {
    $catalogs = Translator::loadCatalogs(dirname(__DIR__) . '/lang');
    check(isset($catalogs['en'], $catalogs['hu']), 'lang/en.php and lang/hu.php are required');
    foreach (Translator::compare($catalogs) as $locale => $diff) {
        check($diff['missing'] === [] && $diff['extra'] === [], "{$locale}: missing " . implode(', ', $diff['missing'])
            . '; extra ' . implode(', ', $diff['extra']));
    }
    // Every t('...') key used in the templates exists in English.
    $templates = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/templates', FilesystemIterator::SKIP_DOTS));
    foreach ($templates as $file) {
        // Only complete keys: t('a.b') or t('a.b', …), not dynamic ones like t('admin.status.' ~ status).
        preg_match_all("/\\bt\\('([a-z0-9_.]+)'\\s*[,)]/", (string) file_get_contents((string) $file), $m);
        foreach ($m[1] as $key) {
            check(isset($catalogs['en'][$key]), "unknown key in {$file->getFilename()}: {$key}");
        }
    }
});

test('Kernel: the login page in English (CAMPANELLA_LOCALE=en)', function (): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    putenv('CAMPANELLA_LOCALE=en');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $locale = $kernel->container()->get(Translator::class)->locale();
    putenv('CAMPANELLA_LOCALE');
    if ($locale !== 'en') {
        echo "      (skipped: config/local.php sets its own locale)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $kernel->container()->set(Session::class, static fn (): Session => new Session(new ArraySessionStorage()));
    $page = $kernel->handle(new Request('GET', '/login'));
    check($page->status === 200 && str_contains($page->body, '<html lang="en">'), 'lang attribute');
    check(str_contains($page->body, '>Log in</h1>') && str_contains($page->body, 'E-mail address'), 'English texts');
    check(!str_contains($page->body, 'Belépés'), 'Hungarian text left on the page');
    $missing = $kernel->handle(new Request('GET', '/nincs-ilyen-oldal'));
    check($missing->status === 404 && str_contains($missing->body, 'The page was not found.'), 'English 404');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Validation messages are keys, translated where they are shown', function () use ($contactRepository): void {
    $contact = $contactRepository->create('contact', ['title' => 'Nyelv', 'phones' => ['1', '2', '3', '4']]);
    try {
        $contactRepository->save($contact);
        check(false, 'saved');
    } catch (ValidationException $e) {
        $message = $e->errors['phones'];
        check($message->key === 'validation.too_many_values' && $message->params === ['max' => 3], (string) $message);
        $en = Translator::fromDirectory(dirname(__DIR__) . '/lang', 'en');
        check($e->messages($en)['phones'] === 'at most 3 values can be given', $e->messages($en)['phones']);
        check(huMessages($e)['phones'] === 'legfeljebb 3 érték adható meg');
        check(str_contains($e->getMessage(), 'phones: validation.too_many_values (max=3)'), $e->getMessage());
    }
    // A plain string from custom code still works: it is shown as it is.
    check((new ValidationException(['x' => 'kész szöveg']))->messages(new Translator([], 'hu'))['x'] === 'kész szöveg');
});

test('Command line in English and in Hungarian', function (): void {
    $run = function (string $locale, string $args): string {
        $env = 'CAMPANELLA_LOCALE=' . $locale . ' CAMPANELLA_DB_PREFIX=test_';
        return (string) shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/campanella') . ' ' . $args . ' 2>&1');
    };
    $help = $run('en', '');
    if (!str_contains($help, 'Usage:') && str_contains($help, 'Használat:')) {
        echo "      (skipped: config/local.php sets its own locale)\n";

        return;
    }
    check(str_contains($help, 'Usage: php bin/campanella <command>') && str_contains($help, 'Creates the database tables'), $help);
    check(str_contains($run('hu', ''), 'Használat: php bin/campanella <parancs>'));
    check(str_contains($run('en', 'user:password nobody@example.com'), 'No such user: nobody@example.com'));
});

// --- Themes and Bootstrap (0.0.4) ---------------------------------------------

echo "\nThemes\n";

test('Theme: name and folder are checked', function (): void {
    check(!Theme::none()->isActive() && !Theme::fromRoot(dirname(__DIR__), '')->isActive());
    throws(LogicException::class, fn () => Theme::fromRoot(dirname(__DIR__), '../etc'));
    throws(LogicException::class, fn () => Theme::fromRoot(dirname(__DIR__), 'no-such-theme'));
    throws(LogicException::class, fn () => Theme::none()->assetPath('style.css'));
});

test('Kernel: Bootstrap is served locally, and a theme overrides a core template', function (): void {
    $root = dirname(__DIR__);
    $bootstrap = $root . '/public/assets/vendor/bootstrap';
    check(is_file($bootstrap . '/css/bootstrap.min.css') && is_file($bootstrap . '/js/bootstrap.bundle.min.js') && is_file($bootstrap . '/LICENSE'));

    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel($root);
    if ($kernel->container()->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $page = $kernel->handle(new Request('GET', '/nincs-ilyen-oldal'));
    check(str_contains($page->body, '/assets/vendor/bootstrap/css/bootstrap.min.css?v=' . Version::CAMPANELLA), 'Bootstrap CSS');
    check(str_contains($page->body, '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v='), 'Bootstrap JS');
    check(!str_contains($page->body, 'cdn.') && !str_contains($page->body, 'jquery'), 'no CDN, no jQuery');

    // A temporary theme: overrides the error page, extends the core version of it.
    $dir = $root . '/themes/zz-test/templates/page';
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/error.html.twig', "{% extends '@core/page/error.html.twig' %}\n"
        . "{% block content %}<p>THEME-OVERRIDE {{ theme_asset('style.css') }}</p>{{ parent() }}{% endblock %}\n");
    try {
        putenv('CAMPANELLA_THEME=zz-test');
        $themed = (new \Campanella\Core\Kernel($root))->handle(new Request('GET', '/nincs-ilyen-oldal'));
        check($themed->status === 404, (string) $themed->status);
        check(str_contains($themed->body, 'THEME-OVERRIDE /themes/zz-test/style.css?v='), 'the theme template is used');
        check(str_contains($themed->body, 'Az oldal nem található'), 'the core template is still reachable as @core');
    } finally {
        putenv('CAMPANELLA_THEME');
        putenv('CAMPANELLA_DB_PREFIX');
        @unlink($dir . '/error.html.twig');
        @rmdir($dir);
        @rmdir(dirname($dir));
        @rmdir(dirname($dir, 2));
        @rmdir($root . '/themes');
    }
});

// --- Admin UI (0.0.4) ---------------------------------------------------------

echo "\nAdmin UI\n";

test('Router: prefix routes pass the rest of the path', function (): void {
    $router = new Router(['/admin/exact' => ['exact']]);
    $router->prefix('/admin', 'admin');
    $router->prefix('/admin/deep', 'deep');
    check($router->match(new Request('GET', '/admin'))->params === ['subpath' => '']);
    check($router->match(new Request('GET', '/admin/article/12'))->params['subpath'] === 'article/12');
    check($router->match(new Request('GET', '/admin/exact'))->handler === 'exact', 'an exact route wins');
    check($router->match(new Request('GET', '/admin/deep/x'))->handler === 'deep', 'the longest prefix wins');
    check($router->match(new Request('GET', '/administration'))->handler === 'object', 'only whole path segments match');
});

test('AdminAccess and Flash', function (): void {
    $access = new AdminAccess('/admin', ['administrator', 'editor']);
    check($access->allows(Actor::system()) && $access->allows(new Actor(\Campanella\Access\ActorKind::User, 1, ['editor'])));
    check(!$access->allows(Actor::anonymous()) && !$access->allows(new Actor(\Campanella\Access\ActorKind::User, 2, ['member'])));
    check($access->path('article') === '/admin/article' && $access->path() === '/admin');
    throws(InvalidArgumentException::class, fn () => new AdminAccess('admin/'));

    $session = new Session(new ArraySessionStorage());
    $flash = new Flash($session);
    check($flash->take() === [], 'no session, no messages, no error');
    $session->start(new Request('GET', '/'));
    $flash->add(Flash::SUCCESS, new \Campanella\I18n\Message('admin.saved', ['title' => 'X']));
    $taken = $flash->take();
    check(count($taken) === 1 && $taken[0]['type'] === 'success' && $taken[0]['message']->params === ['title' => 'X']);
    check($flash->take() === [], 'a message is shown only once');
});

test('Kernel: the admin is only for admin roles', function () use ($editorUser, $newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $as = function ($user) use ($kernel, $container, $storage): void {
        $storage->endRequest();
        $container->get(AuthService::class)->login(new Request('GET', '/'), $user);
        $storage->endRequest();
    };

    $anonymous = $kernel->handle(new Request('GET', '/admin'));
    check($anonymous->status === 302 && ($anonymous->headers['Location'] ?? '') === '/login?return=%2Fadmin', 'anonymous: to the login page');
    $deep = $kernel->handle(new Request('GET', '/admin/article', query: ['status' => 'draft']));
    check(($deep->headers['Location'] ?? '') === '/login?return=' . rawurlencode('/admin/article?status=draft'), 'back to the requested page');

    $as($newUser('tag@example.hu', 'tag-jelszava-1', ['member']));
    check($kernel->handle(new Request('GET', '/admin'))->status === 403, 'a member may not enter');
    $storage->endRequest();

    $as($editorUser);
    $dashboard = $kernel->handle(new Request('GET', '/admin'));
    check($dashboard->status === 200, (string) $dashboard->status);
    check(str_contains($dashboard->body, 'Irányítópult') && str_contains($dashboard->body, 'Cikk'), 'dashboard with the translated Blueprint labels');
    check(($dashboard->headers['X-Robots-Tag'] ?? '') === 'noindex, nofollow' && ($dashboard->headers['Cache-Control'] ?? '') === 'private, no-store');
    check(str_contains($dashboard->body, 'admin.css?v='), 'the admin has its own stylesheet');
    check(str_contains($dashboard->body, 'href="/admin/article">Cikk</a>') && !str_contains($dashboard->body, 'href="/admin/user"'), 'the types link to their lists (users have none)');
    $storage->endRequest();
    check($kernel->handle(new Request('GET', '/admin/no-such-page'))->status === 404);
    $storage->endRequest();
    $home = $kernel->handle(new Request('GET', '/'));
    check(str_contains($home->body, 'href="/admin"'), 'the public header links to the admin for an editor');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: admin content list with search, status filter and sorting', function () use ($editorUser, $service, $admin): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $draft = $service->create($admin, 'article', ['title' => 'Listás vázlat 100%']);
    $live = $service->create($admin, 'article', ['title' => 'Listás élő cikk']);
    $service->publish($admin, $live, new DateTimeImmutable('-1 hour'));
    $later = $service->create($admin, 'article', ['title' => 'Listás időzített']);
    $service->publish($admin, $later, new DateTimeImmutable('+1 day'));

    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $container->get(AuthService::class)->login(new Request('GET', '/'), $editorUser);
    $get = function (string $path, array $query = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request('GET', $path, query: $query));
    };

    $list = $get('/admin/article', ['q' => 'Listás']);
    check($list->status === 200, (string) $list->status);
    foreach (['Listás vázlat 100%', 'Listás élő cikk', 'Listás időzített'] as $title) {
        check(str_contains($list->body, htmlspecialchars($title)), "missing from the list: {$title}");
    }
    check(str_contains($list->body, 'href="/admin/article"'), 'the sidebar links to the Blueprint lists');
    check(!str_contains($list->body, 'href="/admin/user"'), 'users are not managed as content');

    $drafts = $get('/admin/article', ['q' => 'Listás', 'status' => 'draft']);
    check(str_contains($drafts->body, 'Listás vázlat') && !str_contains($drafts->body, 'Listás élő cikk'), 'draft filter');
    $scheduled = $get('/admin/article', ['q' => 'Listás', 'status' => 'scheduled']);
    check(str_contains($scheduled->body, 'Listás időzített') && !str_contains($scheduled->body, 'Listás vázlat'), 'scheduled filter');
    $published = $get('/admin/article', ['q' => 'Listás', 'status' => 'published']);
    check(str_contains($published->body, 'Listás élő cikk') && !str_contains($published->body, 'Listás időzített'), 'published filter');

    $percent = $get('/admin/article', ['q' => '100%']);
    check(str_contains($percent->body, 'Listás vázlat 100%') && !str_contains($percent->body, 'Listás élő cikk'), '% is searched literally');

    $sorted = $get('/admin/article', ['q' => 'Listás', 'sort' => 'title', 'dir' => 'asc']);
    $a = strpos($sorted->body, 'Listás élő cikk');
    $b = strpos($sorted->body, 'Listás vázlat');
    check($a !== false && $b !== false && $a < $b, 'sorted by title');
    check($get('/admin/article', ['sort' => 'password_hash; DROP'])->status === 200, 'an unknown sort field falls back');

    check($get('/admin/user')->status === 403, 'an editor does not manage users (since 0.1.0)');
    check($get('/admin/no-such-blueprint')->status === 404);
    putenv('CAMPANELLA_DB_PREFIX');
});

test('ObjectForm: fields from the definitions, read back with types', function () use ($contactRepository, $contactEngine): void {
    $form = new ObjectForm($contactEngine, Translator::fromDirectory(dirname(__DIR__) . '/lang', 'hu'), 'Europe/Budapest');
    $contact = $contactRepository->create('contact', ['title' => 'Űrlap', 'phones' => ['1', '2'], 'event_dates' => ['2026-07-01 08:30:00']]);

    $fields = [];
    foreach ($form->build($contact, Actor::system(), order: ['phones', 'title']) as $f) {
        $fields[$f->name] = $f;
    }
    check(array_key_first($fields) === 'phones', 'form_order is applied');
    check($fields['phones']->multiple && $fields['phones']->max === 3 && $fields['phones']->value === ['1', '2']);
    check($fields['phones']->inputName() === 'f[phones][]' && $fields['title']->inputName() === 'f[title]');
    check($fields['tags']->max === 5, 'the Blueprint narrowing is shown');
    check($fields['event_dates']->value === ['2026-07-01T10:30:00'], 'dates are shown in the site time zone');
    check($fields['flags']->widget === 'boolean' && $fields['lucky_numbers']->widget === 'integer');
    check($fields['friends']->widget === 'checkboxes' && $fields['friends']->kind === 'relation');

    $read = $form->read($contact, actor: Actor::system(), post: ['f' => [
        'title' => '  Új név  ', 'phones' => ['+36 1', '', '+36 2'], 'lucky_numbers' => ['7', '13'],
        'event_dates' => ['2026-12-24T18:00'], 'flags' => ['1', '0'], 'tags' => ['a'],
    ], 'r' => ['friends' => ['', '5', '5', 'x', '6']]]);
    $offeredFriends = array_map(intval(...), array_column($fields['friends']->options, 'value'));
    check($read['errors'] === [], json_encode(array_map('strval', $read['errors'])) ?: '');
    check($read['values']['title'] === 'Új név', 'strings are trimmed');
    check($read['values']['lucky_numbers'] === [7, 13] && $read['values']['flags'] === [true, false]);
    check($read['values']['event_dates'][0]->format('Y-m-d H:i e') === '2026-12-24 17:00 UTC', 'local time to UTC');
    check($read['relations']['friends'] === array_values(array_intersect([5, 6], $offeredFriends)), 'relation IDs: only offered ones, no duplicates');
    check(!isset($read['values']['notes']) || $read['values']['notes'] === [], 'a field missing from the post is empty');

    $bad = $form->read($contact, ['f' => ['lucky_numbers' => ['hét', '99999999999'], 'event_dates' => ['tegnap']]], Actor::system());
    check($bad['errors']['lucky_numbers']->key === 'validation.invalid_number' && $bad['errors']['event_dates']->key === 'validation.invalid_date');
    check(($form->read($contact, ['f' => ['lucky_numbers' => ['99999999999']]], Actor::system()))['errors']['lucky_numbers']->key === 'validation.invalid_number', 'out of the INT range');
    check($fields['event_dates']->value === ['2026-07-01T10:30:00'], 'seconds are kept');

    // A current target that the form does not offer (here: another Blueprint) is kept on save.
    $note = $contactRepository->create('note', ['title' => 'Nem felajánlott']);
    $contactRepository->save($note);
    $saved = $contactRepository->create('contact', ['title' => 'Kapcsolt', 'phones' => ['1']]);
    $contactRepository->save($saved);
    $saved->setRelated('friends', [(int) $note->id()]);
    $keep = $form->read($saved, ['r' => ['friends' => ['']]], Actor::system());
    check($keep['relations']['friends'] === [(int) $note->id()], 'a target that was not offered is not removed');
    $forged = $form->read($saved, ['r' => ['friends' => [(string) $note->id(), '999999']]], Actor::system());
    check($forged['relations']['friends'] === [(int) $note->id()], 'posted IDs that were not offered are ignored');

});

test('Kernel: creating and editing in the admin, with conflict detection', function () use ($editorUser, $service, $admin): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $container->get(AuthService::class)->login(new Request('GET', '/'), $editorUser);
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $token = function (string $body): string {
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $body, $m);

        return $m[1] ?? '';
    };

    $category = $service->create($admin, 'category', ['title' => 'Űrlap kategória']);
    $new = $send('GET', '/admin/article/new');
    check($new->status === 200 && str_contains($new->body, 'name="f[title]"') && str_contains($new->body, 'name="r[categories][]"'), 'the generated form');
    check(!str_contains($new->body, 'name="f[status]"') && !str_contains($new->body, 'password'), 'managed and hidden fields are not in the form');

    $invalid = $send('POST', '/admin/article/new', ['_csrf' => $token($new->body), 'f' => ['title' => '']]);
    check($invalid->status === 422 && str_contains($invalid->body, 'kötelező mező'), 'required field: ' . $invalid->status);

    $noToken = $send('POST', '/admin/article/new', ['f' => ['title' => 'Token nélkül']]);
    check($noToken->status === 400, 'without CSRF token: ' . $noToken->status);

    $created = $send('POST', '/admin/article/new', ['_csrf' => $token($new->body), 'f' => [
        'title' => 'Űrlapból készült cikk', 'lead' => 'Bevezető', 'body' => "Szöveg.", 'path' => '',
    ], 'r' => ['categories' => ['', (string) $category->id()], 'author' => '']]);
    check($created->status === 303, 'created: ' . $created->status);
    $location = $created->headers['Location'] ?? '';
    check(preg_match('#^/admin/article/(\d+)$#', $location, $m) === 1, $location);
    $id = (int) $m[1];

    $repository = $container->get(\Campanella\Model\ObjectRepository::class);
    $article = $repository->find($id);
    check($article !== null && $article->get('path') === '/urlapbol-keszult-cikk' && $article->relatedIds('categories') === [(int) $category->id()]);
    check($article->as(\Campanella\Capability\Authorable::class)->authorId() === $editorUser->id(), 'the editor is the author');

    $edit = $send('GET', $location);
    check($edit->status === 200 && str_contains($edit->body, 'Létrehozva: Űrlapból készült cikk'), 'flash message after the redirect');
    preg_match('/name="_version" value="([0-9a-f]{64})"/', $edit->body, $u);
    $stamp = $u[1] ?? '';
    check($stamp !== '', 'the form carries a version token');

    $stale = $send('POST', $location, ['_csrf' => $token($edit->body), '_version' => str_repeat('0', 64), 'f' => ['title' => 'Elavult']]);
    check($stale->status === 409 && str_contains($stale->body, 'Valaki más mentette'), 'conflict: ' . $stale->status);
    check($repository->find($id)?->get('title') === 'Űrlapból készült cikk', 'a stale form does not overwrite');

    $long = $send('POST', $location, ['_csrf' => $token($edit->body), '_version' => $stamp, 'f' => ['title' => str_repeat('x', 300)]]);
    check($long->status === 422 && str_contains($long->body, 'legfeljebb 255 karakter'), 'too long title: ' . $long->status);
    check(str_contains($long->body, '<h1 class="h3 mb-0 me-2">Űrlapból készült cikk</h1>'), 'after a failed save the stored title is shown');
    $reserved = $send('POST', $location, ['_csrf' => $token($edit->body), '_version' => $stamp, 'f' => ['title' => 'Belépés', 'path' => '/login']]);
    check($reserved->status === 422 && str_contains($reserved->body, 'ezt az útvonalat a rendszer használja'), 'reserved path: ' . $reserved->status);
    $fromTitle = $send('POST', $location, ['_csrf' => $token($edit->body), '_version' => $stamp, 'f' => ['title' => 'Admin', 'path' => '']]);
    check($fromTitle->status === 422, 'a path made from the title is checked too');

    $saved = $send('POST', $location, ['_csrf' => $token($edit->body), '_version' => $stamp, 'f' => [
        'title' => 'Átírt cikk', 'lead' => 'Új bevezető', 'body' => 'Új szöveg.', 'path' => '/atirt-cikk',
    ], 'r' => ['categories' => [''], 'author' => (string) $editorUser->id()]]);
    check($saved->status === 303 && ($saved->headers['Location'] ?? '') === $location, 'saved: ' . $saved->status);
    $article = $repository->find($id);
    check($article !== null && $article->get('title') === 'Átírt cikk' && $article->get('path') === '/atirt-cikk');
    check($article->relatedIds('categories') === [], 'unchecking every category clears the relation');

    $list = $send('GET', '/admin/article', []);
    check(str_contains($list->body, 'href="/admin/article/' . $id . '"') && str_contains($list->body, 'href="/admin/article/new"'), 'list: edit link and New button');

    // HTML text: edited with the editor, filtered on save (0.0.5).
    $html = $service->create($admin, 'article', ['title' => 'HTML cikk', 'body' => '<p>Eredeti</p>', 'format' => 'html']);
    $htmlForm = $send('GET', '/admin/article/' . $html->id());
    check(str_contains($htmlForm->body, 'data-editor="full"') && str_contains($htmlForm->body, 'vendor/jodit/jodit.min.js'), 'the editor is loaded');
    check(str_contains($htmlForm->body, 'data-allow-tags="{&quot;p&quot;:true'), 'with the allowlist');
    check(str_contains((string) ($htmlForm->headers['Content-Security-Policy'] ?? ''), "script-src 'self'"), 'admin CSP');
    preg_match('/name="_version" value="([0-9a-f]{64})"/', $htmlForm->body, $hu);
    $send('POST', '/admin/article/' . $html->id(), ['_csrf' => $token($htmlForm->body), '_version' => $hu[1] ?? '', 'f' => [
        'title' => 'HTML cikk', 'lead' => '', 'path' => '/html-cikk', 'body' => '<h2>Cím</h2><p onclick="x()">Új</p><script>alert(1)</script>',
    ]]);
    check($repository->find((int) $html->id())?->get('body') === '<h2>Cím</h2><p>Új</p>', 'edited, and filtered on save');

    check($send('GET', '/admin/article/999999')->status === 404 && $send('GET', '/admin/article/abc')->status === 404);
    check($send('GET', '/admin/article/0' . $id)->status === 404, 'no duplicate URL with a leading zero');

    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: publishing, scheduling, unpublishing and deleting in the admin', function () use ($editorUser, $newUser, $service, $admin): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $auth = $container->get(AuthService::class);
    $auth->login(new Request('GET', '/'), $editorUser);
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $field = function (string $name, string $body): string {
        preg_match('/name="' . $name . '" value="([^"]*)"/', $body, $m);

        return html_entity_decode($m[1] ?? '');
    };
    $repository = $container->get(\Campanella\Model\ObjectRepository::class);
    $publishable = static fn (int $id) => $repository->find($id)?->as(\Campanella\Capability\Publishable::class);

    $article = $service->create($admin, 'article', ['title' => 'Megjelenő cikk']);
    $id = (int) $article->id();
    $location = '/admin/article/' . $id;

    // The editor: the publication panel, but no delete button.
    $edit = $send('GET', $location);
    check(str_contains($edit->body, 'action="' . $location . '/publish"') && str_contains($edit->body, 'Nem látható a webhelyen'), 'publication panel');
    check(!str_contains($edit->body, 'action="' . $location . '/unpublish"'), 'no unpublish for a draft');
    check(!str_contains($edit->body, $location . '/delete'), 'an editor sees no delete button');
    check($send('GET', $location . '/delete')->status === 403, 'an editor cannot open the delete page');
    check($send('POST', $location . '/delete', ['_csrf' => $field('_csrf', $edit->body)])->status === 403, 'an editor cannot delete');
    check($repository->find($id) !== null);

    check($send('GET', $location . '/publish')->status === 405, 'publish is POST only');
    check($send('POST', $location . '/archive')->status === 404, 'unknown action');

    // Publishing needs the CSRF token and the current version.
    $noToken = $send('POST', $location . '/publish', ['_version' => $field('_version', $edit->body)]);
    check($noToken->status === 303 && $publishable($id)?->isPublished() === false, 'without CSRF nothing happens');
    $stale = $send('POST', $location . '/publish', ['_csrf' => $field('_csrf', $edit->body), '_version' => str_repeat('0', 64)]);
    check($stale->status === 303 && $publishable($id)?->isPublished() === false, 'a stale version is refused');
    check(str_contains($send('GET', $location)->body, 'Közben valaki más mentette'), 'conflict message');

    $edit = $send('GET', $location);
    $badDate = $send('POST', $location . '/publish', ['_csrf' => $field('_csrf', $edit->body), '_version' => $field('_version', $edit->body), 'published_at' => '2026-13-45']);
    check($badDate->status === 303 && $publishable($id)?->isPublished() === false, 'an invalid date is refused');

    // Publish now.
    $edit = $send('GET', $location);
    $published = $send('POST', $location . '/publish', ['_csrf' => $field('_csrf', $edit->body), '_version' => $field('_version', $edit->body), 'published_at' => '']);
    check($published->status === 303 && ($published->headers['Location'] ?? '') === $location, 'publish: ' . $published->status);
    check($publishable($id)?->isPublished() === true, 'published');
    $edit = $send('GET', $location);
    check(str_contains($edit->body, 'Közzétéve: Megjelenő cikk') && str_contains($edit->body, 'action="' . $location . '/unpublish"'), 'flash, and unpublish offered');

    // Scheduling: a future time (typed in the site's time zone).
    $timezone = new DateTimeZone((string) $container->get(\Campanella\Core\Config::class)->get('timezone', 'UTC'));
    $future = (new DateTimeImmutable('+3 days', $timezone))->setTime(9, 30, 15);
    $scheduled = $send('POST', $location . '/publish', [
        '_csrf' => $field('_csrf', $edit->body), '_version' => $field('_version', $edit->body),
        'published_at' => $future->format('Y-m-d\TH:i:s'),
    ]);
    check($scheduled->status === 303);
    $state = $publishable($id);
    check($state?->isPublished() === false && $state->publishedAt()?->getTimestamp() === $future->getTimestamp(), 'scheduled, to the second');
    $edit = $send('GET', $location);
    check(str_contains($edit->body, 'Időzítve: Megjelenő cikk') && str_contains($edit->body, 'value="' . $future->format('Y-m-d\TH:i:s') . '"'), 'scheduled flash and time');
    $storage->endRequest();
    $scheduledList = $kernel->handle(new Request('GET', '/admin/article', query: ['status' => 'scheduled', 'q' => 'Megjelenő']));
    check(str_contains($scheduledList->body, 'Megjelenő cikk'), 'in the scheduled list');

    // Unpublish.
    $unpublished = $send('POST', $location . '/unpublish', ['_csrf' => $field('_csrf', $edit->body), '_version' => $field('_version', $edit->body)]);
    check($unpublished->status === 303 && $publishable($id)?->status() === \Campanella\Capability\PublishStatus::Draft, 'unpublished');
    check(str_contains($send('GET', $location)->body, 'Visszavonva: Megjelenő cikk'));

    // The administrator may delete, after a confirmation page listing what refers to it.
    $category = $service->create($admin, 'category', ['title' => 'Törlendő kategória']);
    $service->update($admin, $repository->find($id), [], ['categories' => [(int) $category->id()]]);
    $auth->logout();
    $auth->login(new Request('GET', '/'), $newUser('torlo-admin@example.hu', 'torlo-admin-jelszo', ['administrator']));
    $categoryPath = '/admin/category/' . $category->id();
    $categoryForm = $send('GET', $categoryPath);
    check(str_contains($categoryForm->body, 'href="' . $categoryPath . '/delete"'), 'the administrator sees the delete button');
    $confirm = $send('GET', $categoryPath . '/delete');
    check($confirm->status === 200 && str_contains($confirm->body, 'véglegesen törlődik') && str_contains($confirm->body, 'Megjelenő cikk'), 'confirmation page with the referrers');
    for ($i = 1; $i <= \Campanella\Controller\AdminController::MAX_REFERRERS + 1; $i++) {
        $service->create($admin, 'article', ['title' => "Hivatkozó cikk {$i}"], relations: ['categories' => [(int) $category->id()]]);
    }
    $many = $send('GET', $categoryPath . '/delete')->body;
    check(substr_count($many, '<li>') === \Campanella\Controller\AdminController::MAX_REFERRERS && str_contains($many, 'és még 2'), 'referrers: 20 listed, the rest counted');
    check($send('POST', $categoryPath . '/delete', [])->status === 400 && $repository->find((int) $category->id()) !== null, 'without CSRF not deleted');
    $deleted = $send('POST', $categoryPath . '/delete', ['_csrf' => $field('_csrf', $confirm->body)]);
    check($deleted->status === 303 && ($deleted->headers['Location'] ?? '') === '/admin/category', 'deleted: back to the list');
    check($repository->find((int) $category->id()) === null && $repository->find($id)?->relatedIds('categories') === [], 'gone, and the reference removed');
    check(str_contains($send('GET', '/admin/category')->body, 'Törölve: Törlendő kategória'));
    check($send('GET', $categoryPath . '/delete')->status === 404, 'a deleted item is not found');

    putenv('CAMPANELLA_DB_PREFIX');
});

test('Admin form widgets: multi-valued rows render', function () use ($contactRepository, $contactEngine): void {
    $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates', dirname(__DIR__)), ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->getLoader()->addPath(dirname(__DIR__) . '/templates', 'core');
    $translator = Translator::fromDirectory(dirname(__DIR__) . '/lang', 'hu');
    $twig->addExtension(new \Campanella\View\CampanellaTwigExtension(static fn () => throw new LogicException(), static fn (): string => '', [], null, null, static fn (): Translator => $translator));
    $contact = $contactRepository->create('contact', ['title' => 'Sorok', 'phones' => ['+36 1', '+36 2'], 'flags' => [true, false]]);
    $form = new ObjectForm($contactEngine, $translator);
    $html = '';
    foreach ($form->build($contact, Actor::system()) as $field) {
        $html .= $twig->render('@core/admin/form/_row.html.twig', ['field' => $field]);
    }
    check(substr_count($html, 'name="f[phones][]" value="+36') === 2, 'one input per value');
    check(str_contains($html, 'data-multi-max="3"') && str_contains($html, '<template data-multi-template>'), 'add button with the limit');
    check(substr_count($html, '<select class="form-select" id="field-flags') === 3 && !str_contains($html, 'name="f[flags][]" value="0">'), 'yes/no rows (2 + the template row) are selects');
});

// --- Documentation examples -------------------------------------------------

echo "\nSystem\n";

test('TemplateCache: a folder per version, usage, clearing every version', function (): void {
    $base = sys_get_temp_dir() . '/campanella-twig-' . bin2hex(random_bytes(4));
    $cache = new \Campanella\System\TemplateCache($base, '0.0.5');
    check($cache->directory() === $base . '/0.0.5');
    check($cache->twigCache() === $base . '/0.0.5' && is_dir($base . '/0.0.5'), 'created on demand');
    mkdir($base . '/0.0.4/ab', 0775, true);
    file_put_contents($base . '/0.0.4/ab/old.php', str_repeat('x', 100));
    file_put_contents($base . '/0.0.5/new.php', str_repeat('y', 50));
    check($cache->usage() === ['files' => 2, 'bytes' => 150], json_encode($cache->usage()));
    check($cache->clear() === 2 && $cache->usage()['files'] === 0 && !is_dir($base . '/0.0.4'), 'older versions are cleared too');
    check($cache->twigCache() !== false, 'usable again after clearing');
    @rmdir($base . '/0.0.5');
    @rmdir($base);
    throws(\InvalidArgumentException::class, fn () => new \Campanella\System\TemplateCache($base, '../x'));
});

test('SystemCheck: versions, extensions, web-only checks, own checks, no secrets', function (): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $c = $kernel->container();
    $system = $c->get(\Campanella\System\SystemCheck::class);

    check(\Campanella\System\SystemCheck::parseDatabaseVersion('10.11.6-MariaDB-0+deb12u1') === ['MariaDB', '10.11.6']);
    check(\Campanella\System\SystemCheck::parseDatabaseVersion('8.0.36') === ['MySQL', '8.0.36']);
    check(\Campanella\System\SystemCheck::parseDatabaseVersion('valami') === null);
    check(\Campanella\System\SystemCheck::parseDatabaseVersion('5.5.5-10.6.18-MariaDB-log') === ['MariaDB', '10.6.18'], 'the old 5.5.5- prefix');

    $byLabel = static function (array $results): array {
        $map = [];
        foreach ($results as $r) {
            $map[$r->label] = $r;
        }

        return $map;
    };
    $cli = $byLabel($system->run());
    check($cli['admin.system.php']->status === \Campanella\System\CheckStatus::Ok, 'PHP version');
    check($cli['admin.system.database']->status === \Campanella\System\CheckStatus::Ok, 'database: ' . $cli['admin.system.database']->value);
    check($cli['pdo_mysql']->status === \Campanella\System\CheckStatus::Ok && isset($cli['gd'], $cli['opcache']));
    check(!isset($cli['admin.system.https']) && !isset($cli['upload_max_filesize']) && !isset($cli['admin.system.opcache']), 'no web-only checks on the command line');

    $web = $byLabel($system->run(new Request('GET', '/admin/system')));
    check($web['admin.system.https']->status === \Campanella\System\CheckStatus::Warning, 'plain HTTP is a warning');
    check(isset($web['upload_max_filesize'], $web['admin.system.opcache']));
    check($byLabel($system->run(new Request('GET', '/', secure: true)))['admin.system.https']->status === \Campanella\System\CheckStatus::Ok);

    $system->add(static fn (?Request $r): array => [new \Campanella\System\CheckResult('test.group', 'test.label', \Campanella\System\CheckStatus::Error, $r === null ? 'cli' : 'web')]);
    $results = $system->run();
    check(end($results)->label === 'test.label' && end($results)->value === 'cli', 'own check, called with the request');
    check(\Campanella\System\SystemCheck::worst($results) === \Campanella\System\CheckStatus::Error);
    check(\Campanella\System\SystemCheck::worst([]) === null);

    // No secret appears in any result.
    $password = (string) $c->get(Config::class)->get('database.password', '');
    // (Only a password long enough not to occur in an ordinary value by chance.)
    $dump = json_encode(array_map(static fn ($r): array => [$r->value, $r->hint?->params], $system->run(new Request('GET', '/'))));
    check(strlen($password) < 12 || !str_contains((string) $dump, $password), 'the database password is not shown');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Reserved Blueprint names and the system roles', function (): void {
    $registry = new BlueprintRegistry(new CapabilityRegistry([Titled::class]));
    throws(CapabilityException::class, fn () => $registry->define('system', ['capabilities' => [Titled::class]]));
    throws(CapabilityException::class, fn () => $registry->define('media', ['capabilities' => [Titled::class]]));

    $access = new AdminAccess();
    check($access->allowsSystem(new Actor(\Campanella\Access\ActorKind::User, 1, [Actor::ADMINISTRATOR])));
    check(!$access->allowsSystem(new Actor(\Campanella\Access\ActorKind::User, 2, ['editor'])), 'an editor may enter, but not the system page');
    $custom = new AdminAccess('/admin', ['editor'], ['editor']);
    check($custom->allowsSystem(new Actor(\Campanella\Access\ActorKind::User, 2, ['editor'])));
    check(!$custom->allowsSystem(new Actor(\Campanella\Access\ActorKind::User, 3, ['owner'])), 'a system role without admin access is not enough');
});

test('Kernel: the system page, clearing the template cache, create and publish', function () use ($editorUser, $newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $auth = $container->get(AuthService::class);
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $token = static function (string $body): string {
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $body, $m);

        return $m[1] ?? '';
    };

    // Twig compiles into the folder of the current version.
    check($container->get(\Twig\Environment::class)->getCache() === $container->get(\Campanella\System\TemplateCache::class)->directory(), 'versioned Twig cache');

    // The editor: no menu item, 403.
    $auth->login(new Request('GET', '/'), $editorUser);
    $dashboard = $send('GET', '/admin');
    check(!str_contains($dashboard->body, 'href="/admin/system"'), 'no System menu item for an editor');
    check($send('GET', '/admin/system')->status === 403);
    check($send('POST', '/admin/system/clear-cache', ['_csrf' => $token($dashboard->body)])->status === 403);

    // Create and publish, in one step.
    $new = $send('GET', '/admin/article/new');
    check(str_contains($new->body, 'name="_publish" value="1"'), 'the button is offered');
    $created = $send('POST', '/admin/article/new', ['_csrf' => $token($new->body), '_publish' => '1', 'f' => ['title' => 'Rögtön megjelenő cikk', 'path' => '']]);
    check($created->status === 303);
    preg_match('#/admin/article/(\d+)$#', $created->headers['Location'] ?? '', $m);
    $article = $container->get(ObjectRepository::class)->find((int) ($m[1] ?? 0));
    check($article?->as(Publishable::class)->isPublished() === true, 'published on creation');
    check(str_contains($send('GET', $created->headers['Location'])->body, 'Létrehozva és közzétéve'), 'flash');
    $draft = $send('POST', '/admin/article/new', ['_csrf' => $token($new->body), 'f' => ['title' => 'Csak piszkozat', 'path' => '']]);
    preg_match('#/admin/article/(\d+)$#', $draft->headers['Location'] ?? '', $m);
    check($container->get(ObjectRepository::class)->find((int) ($m[1] ?? 0))?->as(Publishable::class)->isPublished() === false, 'plain Create stays a draft');

    // The administrator.
    $auth->logout();
    $auth->login(new Request('GET', '/'), $newUser('rendszer-admin@example.hu', 'rendszer-admin-jelszo', ['administrator']));
    $page = $send('GET', '/admin/system');
    check($page->status === 200 && str_contains($page->body, 'Kötelező PHP-bővítmények') && str_contains($page->body, 'pdo_mysql'), 'system page');
    check(str_contains($page->body, 'href="/admin/system"'), 'menu item');
    check($send('GET', '/admin/system/clear-cache')->status === 405 && $send('GET', '/admin/system/other')->status === 404);

    $cache = $container->get(\Campanella\System\TemplateCache::class);
    check($cache->usage()['files'] > 0, 'templates were compiled');
    $noToken = $send('POST', '/admin/system/clear-cache');
    check($noToken->status === 303 && $cache->usage()['files'] > 0, 'without CSRF nothing is cleared');
    $cleared = $send('POST', '/admin/system/clear-cache', ['_csrf' => $token($page->body)]);
    check($cleared->status === 303 && ($cleared->headers['Location'] ?? '') === '/admin/system');
    check(str_contains($send('GET', '/admin/system')->body, 'Sablon-gyorsítótár ürítve'), 'flash after clearing');

    // A failed check shows a bar on the dashboard.
    check(!str_contains($send('GET', '/admin')->body, 'A szerver nem teljesíti'), 'no bar while every requirement is met');
    $container->get(\Campanella\System\SystemCheck::class)->add(static fn (): array => [
        new \Campanella\System\CheckResult('test.group', 'test.label', \Campanella\System\CheckStatus::Error),
    ]);
    check(str_contains($send('GET', '/admin')->body, 'A szerver nem teljesíti'), 'the bar for a failed check');

    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nHTML sanitizer\n";

test('HtmlSanitizer: known XSS tricks are made harmless', function (): void {
    $sanitizer = new \Campanella\Html\HtmlSanitizer();
    $attacks = [
        '<script>alert(1)</script>',
        '<SCRIPT SRC=//evil.example/x.js></SCRIPT>',
        '<img src=x onerror=alert(1)>',
        '<IMG SRC="javascript:alert(1)">',
        '<a href="javascript:alert(1)">x</a>',
        '<a href="JaVaScRiPt:alert(1)">x</a>',
        '<a href=" javascript:alert(1)">x</a>',
        '<a href="java&#x09;script:alert(1)">x</a>',
        '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>',
        '<a href="vbscript:msgbox(1)">x</a>',
        '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
        '<svg onload=alert(1)><script>alert(1)</script></svg>',
        '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
        '<iframe src="javascript:alert(1)"></iframe>',
        '<object data="x.swf"></object><embed src="x.swf">',
        '<body onload=alert(1)>',
        '<div style="background:url(javascript:alert(1))">x</div>',
        '<p style="position:fixed;top:0;left:0;width:100%;height:100%">x</p>',
        '<style>@import "//evil.example/x.css";</style>',
        '<link rel=stylesheet href=//evil.example/x.css>',
        '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
        '<form action="//evil.example"><input name=password><button>OK</button></form>',
        '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
        '<template><script>alert(1)</script></template>',
        '<details open ontoggle=alert(1)>x</details>',
        '<p onclick="alert(1)" onmouseover="alert(1)">x</p>',
        '<a href="#" onfocus="alert(1)" autofocus>x</a>',
        '<base href="javascript:alert(1)//">',
        '<img src="//evil.example/track.gif">',
        '<img src="/\evil.example/track.gif">',
        '<img src="https://evil.example/track.gif">',
        '<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">',
        "<scr<script>ipt>alert(1)</script>",
        '<<script>script>alert(1)<</script>/script>',
        '<p>a</p><!--<img src=x onerror=alert(1)>-->',
    ];
    foreach ($attacks as $attack) {
        $clean = $sanitizer->sanitize($attack);
        foreach (['<script', 'javascript:', 'vbscript:', 'data:', ' on', '<iframe', '<object', '<embed', '<svg', '<math', '<style',
            '<link', '<meta', '<form', '<input', '<base', 'style=', 'evil.example', '<!--'] as $needle) {
            check(stripos($clean, $needle) === false, "{$attack}  →  {$clean}  (contains {$needle})");
        }
        check($sanitizer->sanitize($clean) === $clean, "idempotent: {$attack}");
    }
});

test('HtmlSanitizer: the allowed content stays, wrappers are unwrapped, links get rel', function (): void {
    $sanitizer = new \Campanella\Html\HtmlSanitizer();
    $kept = '<h2>Cím</h2><p>Egy <strong>félkövér</strong>, <em>dőlt</em> és <s>áthúzott</s> szó, H<sub>2</sub>O, x<sup>2</sup>.</p>'
        . '<ul><li>egy</li></ul><ol><li>kettő</li></ol><blockquote>idézet</blockquote><pre><code>kód</code></pre><hr />'
        . '<figure><img src="/media/2026/10/kep.jpg" alt="Kép" width="640" height="480" /><figcaption>Felirat</figcaption></figure>'
        . '<table><caption>T</caption><thead><tr><th scope="col">A</th></tr></thead><tbody><tr><td colspan="2">1</td></tr></tbody></table>';
    check($sanitizer->sanitize($kept) === $kept, $sanitizer->sanitize($kept));

    check($sanitizer->sanitize('<div><span style="color:red">Szöveg</span> <font face="Arial">itt</font></div>') === '<div>Szöveg itt</div>', 'wrappers unwrapped, text kept');
    check($sanitizer->sanitize('<style>body{}</style><title>T</title><p>a</p>') === '<p>a</p>', 'no style or title text');
    check($sanitizer->sanitize('<p class="x" id="y">a</p>') === '<p>a</p>', 'class and id removed');
    check($sanitizer->sanitize('<a href="/hirek">a</a>') === '<a href="/hirek" rel="noopener noreferrer">a</a>');
    check($sanitizer->sanitize('<a href="https://example.com" target="_blank">a</a>') === '<a href="https://example.com" rel="noopener noreferrer">a</a>');
    check($sanitizer->sanitize('<a href="mailto:info@example.com">a</a>') === '<a href="mailto:info&#64;example.com" rel="noopener noreferrer">a</a>', 'mailto (@ encoded, browsers decode it)');
    check($sanitizer->sanitize('<p>&lt;b&gt; &amp; ő</p>') === '<p>&lt;b&gt; &amp; ő</p>', 'entities stay escaped');
    check($sanitizer->sanitize('   ') === '' && $sanitizer->sanitize('csak szöveg') === 'csak szöveg');
    check($sanitizer->sanitize('<p>a<img src="https://example.com/x.gif" alt="külső"><img alt="nincs"><img src="/media/2026/10/a.jpg"></p>') === '<p>a<img src="/media/2026/10/a.jpg" /></p>', 'images left without an address are dropped');
    check(!$sanitizer->allowsExternalImages() && (new \Campanella\Html\HtmlSanitizer(['external_images' => true]))->allowsExternalImages());

    // Configurable: external images, and a narrower element list.
    $external = new \Campanella\Html\HtmlSanitizer(['external_images' => true]);
    check(str_contains($external->sanitize('<img src="https://example.com/a.png">'), 'src="https://example.com/a.png"'), 'external images when allowed');
    $narrow = new \Campanella\Html\HtmlSanitizer(['elements' => ['p' => []]]);
    check($narrow->sanitize('<p>a <strong>b</strong></p><table><tr><td>c</td></tr></table>') === '<p>a </p>', $narrow->sanitize('<p>a <strong>b</strong></p><table><tr><td>c</td></tr></table>'));

    $short = new \Campanella\Html\HtmlSanitizer(['max_length' => 10]);
    check($short->isTooLong('<p>12345678</p>') && !$short->isTooLong('<p>1</p>'));
    throws(\InvalidArgumentException::class, fn () => $short->sanitize('<p>12345678</p>'));
    throws(\InvalidArgumentException::class, fn () => new \Campanella\Html\HtmlSanitizer(['max_length' => 0]));

    // Problems that are reported instead of filtered: too many tags (slow to parse), invalid UTF-8.
    check($sanitizer->problem(str_repeat('<div>', 20_001))?->key === 'validation.html_too_many_tags', 'deep nesting is refused');
    check($sanitizer->problem("<p>a\xc3</p>")?->key === 'validation.invalid_encoding', 'invalid UTF-8 is refused, not emptied');
    check($sanitizer->problem('<p>rendben</p>') === null);

    // A backtick in an attribute: the library's extra space does not grow on every save.
    $once = $sanitizer->sanitize('<img alt="`x" src="/a.png">');
    check($sanitizer->sanitize($once) === $once && !str_contains($once, '&#96;x "'), $once);
});

test('Saving filters HTML texts; a too long one is a validation error', function () use ($db, $capabilities, $blueprints, $service, $admin): void {
    $article = $service->create($admin, 'article', ['title' => 'HTML-szűrés', 'body' => '<p onclick="x()">Szöveg</p><script>alert(1)</script>', 'format' => 'html']);
    check($article->get('body') === '<p>Szöveg</p>', 'filtered on create: ' . $article->get('body'));
    $stored = $db->fetchValue('SELECT data FROM {objects} WHERE id = :id', ['id' => $article->id()]);
    check(!str_contains((string) $stored, 'script'), 'the database has the filtered text');

    $service->update($admin, $article, ['body' => '<p>Új</p><img src=x onerror=alert(1)>']);
    check($article->get('body') === '<p>Új</p><img src="x" />', 'filtered on update: ' . $article->get('body'));

    $plain = $service->create($admin, 'article', ['title' => 'Sima szöveg', 'body' => '<b>marad</b> & így', 'format' => 'plain']);
    check($plain->get('body') === '<b>marad</b> & így', 'plain text is not touched (it is escaped when shown)');

    $short = new ObjectRepository($db, $capabilities, $blueprints, new \Campanella\Html\HtmlSanitizer(['max_length' => 20]));
    $long = $short->create('article', ['title' => 'Hosszú', 'body' => '<p>' . str_repeat('a', 30) . '</p>', 'format' => 'html']);
    try {
        $short->save($long);
        check(false, 'no exception');
    } catch (ValidationException $e) {
        check(isset($e->errors['body']) && $e->errors['body']->key === 'validation.html_too_long', 'too long');
    }
    try {
        $service->create($admin, 'article', ['title' => 'Rossz kódolás', 'body' => "<p>a\xc3</p>", 'format' => 'html']);
        check(false, 'no exception for invalid UTF-8');
    } catch (ValidationException $e) {
        check($e->errors['body']->key === 'validation.invalid_encoding', 'invalid UTF-8 is a validation error');
    }
});

test('html:sanitize filters texts stored before the sanitizer', function () use ($db, $service, $admin): void {
    $article = $service->create($admin, 'article', ['title' => 'Régi HTML', 'body' => '<p>tiszta</p>', 'format' => 'html']);
    // As if it was stored by 0.0.4, unfiltered.
    $data = json_decode((string) $db->fetchValue('SELECT data FROM {objects} WHERE id = :id', ['id' => $article->id()]), true);
    $data['body'] = '<p>régi</p><script>alert(1)</script>';
    $db->update('objects', ['data' => json_encode($data)], ['id' => $article->id()]);

    putenv('CAMPANELLA_DB_PREFIX=' . $db->prefix());
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $run = static function (array $args) use ($kernel): array {
        $stream = fopen('php://memory', 'w+');
        $code = (new \Campanella\Cli\HtmlSanitizeCommand())->run($kernel->container(), $args, new \Campanella\Cli\Output($stream, $stream));
        rewind($stream);

        return [$code, (string) stream_get_contents($stream)];
    };
    [$code, $out] = $run(['--dry-run']);
    check($code === 0 && str_contains($out, 'Régi HTML') && str_contains((string) $db->fetchValue('SELECT data FROM {objects} WHERE id = :id', ['id' => $article->id()]), 'script'), 'dry run: listed, not saved');
    [$code, $out] = $run([]);
    $data = json_decode((string) $db->fetchValue('SELECT data FROM {objects} WHERE id = :id', ['id' => $article->id()]), true);
    check($code === 0 && $data['body'] === '<p>régi</p>', 'filtered and saved: ' . $data['body']);
    [, $out] = $run([]);
    check(!str_contains($out, 'Régi HTML'), 'nothing left to filter');

    // An object that cannot be saved (here: too many tags) is reported, the rest are still filtered.
    $broken = $service->create($admin, 'article', ['title' => 'Túl sok címke', 'body' => '<p>x</p>', 'format' => 'html']);
    $later = $service->create($admin, 'article', ['title' => 'Utána jövő', 'body' => '<p>y</p>', 'format' => 'html']);
    foreach ([[$broken, str_repeat('<b>', 20_001)], [$later, '<p>y</p><script>z</script>']] as [$object, $body]) {
        $data = json_decode((string) $db->fetchValue('SELECT data FROM {objects} WHERE id = :id', ['id' => $object->id()]), true);
        $data['body'] = $body;
        $db->update('objects', ['data' => json_encode($data)], ['id' => $object->id()]);
    }
    [$code, $out] = $run([]);
    $data = json_decode((string) $db->fetchValue('SELECT data FROM {objects} WHERE id = :id', ['id' => $later->id()]), true);
    check($code === 1 && str_contains($out, 'Túl sok címke') && $data['body'] === '<p>y</p>', 'skipped one, filtered the next: ' . $out);
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nHTML editor\n";

test('PlainText::toHtml and Blueprint defaults', function () use ($repository, $capabilities): void {
    check(\Campanella\Html\PlainText::toHtml("Első <b>&\nsor\r\n\r\n  Második\n\n\nHarmadik ") === "<p>Első &lt;b&gt;&amp;<br>\nsor</p><p>Második</p><p>Harmadik</p>");
    check(\Campanella\Html\PlainText::toHtml("  \n ") === '');

    check($repository->create('article')->get('format') === 'html', 'a new article is HTML (Blueprint defaults)');
    check($repository->create('article', ['format' => 'plain'])->get('format') === 'plain', 'a given value wins');
    check($repository->create('category')->get('format') === 'plain', 'categories keep the field default');

    $registry = new BlueprintRegistry($capabilities);
    throws(CapabilityException::class, fn () => $registry->define('x', ['capabilities' => [Textual::class], 'defaults' => ['nincs' => 1]]));
    throws(CapabilityException::class, fn () => $registry->define('y', ['capabilities' => [Textual::class], 'editor' => ['nincs' => 'full']]));
    check($registry->define('z', ['capabilities' => [Textual::class], 'editor' => ['body' => 'basic']])->editors === ['body' => 'basic']);
});

test('ObjectForm: the editor widget for HTML, the convert button for a saved plain text', function () use ($repository, $engine, $service, $admin): void {
    $form = new ObjectForm($engine, new \Campanella\I18n\Translator([], 'hu'), 'Europe/Budapest', new \Campanella\Html\HtmlSanitizer(['elements' => ['p' => [], 'a' => ['href']]]));
    $byName = static fn (array $fields): array => array_column(array_map(static fn ($f) => ['n' => $f->name, 'f' => $f], $fields), 'f', 'n');

    $html = $byName($form->build($repository->create('article'), $admin, editors: ['body' => 'basic']));
    check($html['body']->widget === 'html' && $html['body']->attributes['editor'] === 'basic' && !$html['body']->disabled);
    check($html['body']->attributes['external_images'] === 0, 'the editor knows external images are not kept');
    $allow = json_decode((string) $html['body']->attributes['allow_tags'], true);
    check($allow['p'] === true && $allow['a'] === ['href' => true] && $allow['span'] === true && !isset($allow['script']), (string) $html['body']->attributes['allow_tags']);
    check($byName($form->build($repository->create('article'), $admin, editors: ['body' => 'ismeretlen']))['body']->attributes['editor'] === 'full', 'unknown profile → full');

    $plainNew = $byName($form->build($repository->create('article', ['format' => 'plain']), $admin));
    check($plainNew['body']->widget === 'text' && !isset($plainNew['body']->attributes['convertible']), 'a new plain text cannot be converted yet');
    $saved = $service->create($admin, 'article', ['title' => 'Sima törzs', 'body' => 'a', 'format' => 'plain']);
    check(($byName($form->build($saved, $admin))['body']->attributes['convertible'] ?? null) === 1, 'a saved one can');
    check(!isset($byName($form->build($saved, $admin))['lead']->attributes['convertible']), 'only the body');
});

test('Kernel: converting a plain body to HTML in the admin', function () use ($editorUser, $service, $admin): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $container->get(AuthService::class)->login(new Request('GET', '/'), $editorUser);
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $field = static function (string $name, string $body): string {
        preg_match('/name="' . $name . '" value="([^"]*)"/', $body, $m);

        return $m[1] ?? '';
    };
    $repository = $container->get(ObjectRepository::class);

    $article = $service->create($admin, 'article', ['title' => 'Átalakítandó', 'body' => "Első <b>bekezdés</b>.\n\nMásodik.", 'format' => 'plain']);
    $path = '/admin/article/' . $article->id();
    $form = $send('GET', $path);
    check(str_contains($form->body, 'form="convert-html"') && str_contains($form->body, 'action="' . $path . '/convert-html"'), 'the button and its form');
    check(!str_contains($form->body, 'jodit.min.js'), 'no editor for a plain text');

    check($send('GET', $path . '/convert-html')->status === 405);
    $send('POST', $path . '/convert-html', ['_csrf' => $field('_csrf', $form->body), '_version' => str_repeat('0', 64)]);
    check($repository->find((int) $article->id())?->get('format') === 'plain', 'a stale version is refused');

    $converted = $send('POST', $path . '/convert-html', ['_csrf' => $field('_csrf', $form->body), '_version' => $field('_version', $form->body)]);
    $stored = $repository->find((int) $article->id());
    check($converted->status === 303 && $stored?->get('format') === 'html', 'converted');
    check($stored?->get('body') === '<p>Első &lt;b&gt;bekezdés&lt;/b&gt;.</p><p>Második.</p>', (string) $stored?->get('body'));
    $after = $send('GET', $path);
    check(str_contains($after->body, 'Formázott szöveggé alakítva') && str_contains($after->body, 'data-editor='), 'flash, and now the editor');

    check($send('POST', '/admin/article/999999/convert-html')->status === 404);
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nImages\n";

/**
 * A test image file (made with GD) in a temporary folder; returns its path.
 * $type: jpeg, png, webp, gif, bmp.
 */
function testImage(string $type, int $width, int $height, bool $transparent = false): string
{
    $image = imagecreatetruecolor($width, $height);
    if ($transparent) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 0, 0, 127));
    } else {
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), intdiv($height, 2), (int) imagecolorallocate($image, 250, 200, 0));
    }
    $file = tempnam(sys_get_temp_dir(), 'cimg');
    match ($type) {
        'jpeg' => imagejpeg($image, $file, 90),
        'png' => imagepng($image, $file),
        'webp' => imagewebp($image, $file),
        'gif' => imagegif($image, $file),
        'bmp' => imagebmp($image, $file),
    };

    return $file;
}

/** The message key of the ValidationException the call throws on the `file` field. */
function mediaError(callable $call): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors['file']->key ?? '(other field)';
    }

    return '(no error)';
}

/** A JPEG with an EXIF orientation and a comment segment holding a "GPS position" inserted after its SOI marker. */
function jpegWithMetadata(string $jpeg, int $orientation): string
{
    $bytes = (string) file_get_contents($jpeg);
    // EXIF (big-endian TIFF): one IFD entry, Orientation (0x0112), SHORT, count 1.
    $tiff = "MM\x00\x2A" . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\x00\x00" . pack('N', 0);
    $app1 = "Exif\x00\x00" . $tiff;
    $comment = 'GPSLatitude 47.4979 GPSLongitude 19.0402 secret';
    $segments = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . "\xFF\xFE" . pack('n', strlen($comment) + 2) . $comment;
    $file = tempnam(sys_get_temp_dir(), 'cimg');
    file_put_contents($file, substr($bytes, 0, 2) . $segments . substr($bytes, 2));

    return $file;
}

test('ImageProcessor: accepted types, re-encoding, scaling, transparency', function (): void {
    if (!extension_loaded('gd')) {
        echo "      (skipped: no gd extension)\n";

        return;
    }
    $processor = new \Campanella\Media\ImageProcessor(maxDimension: 100);
    foreach (['jpeg' => ['image/jpeg', 'jpg'], 'png' => ['image/png', 'png'], 'webp' => ['image/webp', 'webp'], 'gif' => ['image/gif', 'gif']] as $type => [$mime, $ext]) {
        $result = $processor->process(testImage($type, 80, 60));
        check($result->mimeType === $mime && $result->extension === $ext && $result->reencoded, $type);
        check($result->width === 80 && $result->height === 60, "{$type}: size kept");
        check(getimagesizefromstring($result->bytes)['mime'] === $mime, "{$type}: a valid image");
    }

    $big = $processor->process(testImage('jpeg', 400, 300));
    check($big->width === 100 && $big->height === 75, 'scaled down to max_dimension: ' . $big->width . 'x' . $big->height);
    $tall = $processor->process(testImage('png', 50, 200));
    check($tall->width === 25 && $tall->height === 100, 'by the longer side');

    $alpha = $processor->process(testImage('png', 300, 300, transparent: true));
    $decoded = imagecreatefromstring($alpha->bytes);
    check($decoded !== false && (imagecolorat($decoded, 10, 10) >> 24) === 127, 'transparency survives scaling');
});

test('ImageProcessor: hostile and broken files are refused or made harmless', function (): void {
    if (!extension_loaded('gd')) {
        echo "      (skipped: no gd extension)\n";

        return;
    }
    $processor = new \Campanella\Media\ImageProcessor(maxBytes: 200_000);
    $temp = static function (string $content): string {
        $file = tempnam(sys_get_temp_dir(), 'cimg');
        file_put_contents($file, $content);

        return $file;
    };

    check(mediaError(fn () => $processor->process($temp(''))) === 'media.empty');
    check(mediaError(fn () => $processor->process('/nincs/ilyen/fajl.jpg')) === 'media.empty');
    check(mediaError(fn () => $processor->process($temp(str_repeat('x', 200_001)))) === 'media.too_large');
    check(mediaError(fn () => $processor->process($temp('<?php system($_GET["c"]); ?>'))) === 'media.not_image', 'PHP code named .jpg');
    check(mediaError(fn () => $processor->process($temp('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>'))) === 'media.not_image', 'SVG');
    check(mediaError(fn () => $processor->process($temp("GIF89a\x01\x00\x01\x00<?php echo 1; ?>"))) !== '(no error)', 'a broken GIF with code');
    check(mediaError(fn () => $processor->process(testImage('bmp', 10, 10))) === 'media.type_not_allowed', 'BMP');

    // A tiny PNG whose header claims 30 000 × 30 000 pixels: refused before decoding.
    $ihdr = pack('NN', 30000, 30000) . "\x08\x02\x00\x00\x00";
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $bomb = $temp("\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', gzcompress('')) . $chunk('IEND', ''));
    check(mediaError(fn () => $processor->process($bomb)) === 'media.too_many_pixels', 'decompression bomb');

    // A real image with PHP code appended: accepted, but the code does not survive re-encoding.
    $gif = testImage('gif', 20, 20);
    file_put_contents($gif, '<?php system($_GET["c"]); ?>', FILE_APPEND);
    $clean = $processor->process($gif);
    check(!str_contains($clean->bytes, '<?php') && !str_contains($clean->bytes, 'system('), 'the appended code is gone');

    // Metadata (here a "GPS position" in a comment, and the EXIF block) is removed;
    // the EXIF orientation is applied first, so the photo stays upright.
    $photo = jpegWithMetadata(testImage('jpeg', 40, 20), 6);
    check(str_contains((string) file_get_contents($photo), 'GPSLatitude'));
    $result = $processor->process($photo);
    check(!str_contains($result->bytes, 'GPSLatitude') && !str_contains($result->bytes, 'Exif'), 'metadata removed');
    if (function_exists('exif_read_data')) {
        check($result->width === 20 && $result->height === 40, 'turned upright: ' . $result->width . 'x' . $result->height);
    }

    // A JPEG with too many scans (start-of-scan markers) is refused before decoding.
    $scans = testImage('jpeg', 20, 20);
    file_put_contents($scans, str_repeat("\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00", 101), FILE_APPEND);
    check(mediaError(fn () => $processor->process($scans)) === 'media.too_complex', 'too many JPEG scans');

    // Every EXIF orientation is turned upright. The stored image is 40×20, yellow in its
    // top-left quarter; the corner where the yellow ends up, and the size, after correction:
    if (function_exists('exif_read_data')) {
        $expected = [1 => ['tl', 40, 20], 2 => ['tr', 40, 20], 3 => ['br', 40, 20], 4 => ['bl', 40, 20],
            5 => ['tl', 20, 40], 6 => ['tr', 20, 40], 7 => ['br', 20, 40], 8 => ['bl', 20, 40]];
        foreach ($expected as $orientation => [$corner, $w, $h]) {
            $upright = $processor->process(jpegWithMetadata(testImage('jpeg', 40, 20), $orientation));
            $img = imagecreatefromstring($upright->bytes);
            $x = str_ends_with($corner, 'l') ? intdiv($w, 4) : intdiv($w * 3, 4);
            $y = str_starts_with($corner, 't') ? intdiv($h, 4) : intdiv($h * 3, 4);
            $rgb = imagecolorat($img, $x, $y);
            check($upright->width === $w && $upright->height === $h && ($rgb >> 16 & 255) > 180 && ($rgb & 255) < 120, "orientation {$orientation}: yellow expected at {$corner}");
        }
    }

    // A type that cannot be re-encoded (here: no GD) is refused by default, so nothing is
    // stored with its metadata; with store_unprocessed the checked original is stored.
    $original = testImage('png', 30, 30);
    check(mediaError(fn () => (new \Campanella\Media\ImageProcessor(useGd: false))->process($original)) === 'media.type_not_processable', 'strict by default');
    $plain = (new \Campanella\Media\ImageProcessor(useGd: false, storeUnprocessed: true))->process($original);
    check(!$plain->reencoded && $plain->bytes === file_get_contents($original) && $plain->width === 30);
    check(mediaError(fn () => (new \Campanella\Media\ImageProcessor(useGd: false, storeUnprocessed: true))->process($temp('<?php ?>'))) === 'media.not_image', 'checked without GD too');

    // The system check: strict without GD is an error (nothing can be uploaded), a warning with store_unprocessed.
    $storage = new \Campanella\Media\MediaStorage(sys_get_temp_dir() . '/campanella-media-' . bin2hex(random_bytes(4)));
    $line = static function (\Campanella\Media\ImageProcessor $p) use ($storage): \Campanella\System\CheckResult {
        return \Campanella\Media\MediaCheck::checks($p, $storage)(null)[1];
    };
    check($line(new \Campanella\Media\ImageProcessor(useGd: false))->status === \Campanella\System\CheckStatus::Error);
    check($line(new \Campanella\Media\ImageProcessor(useGd: false, storeUnprocessed: true))->status === \Campanella\System\CheckStatus::Warning);
    $full = $line($processor);
    check(in_array($full->status, [\Campanella\System\CheckStatus::Ok, \Campanella\System\CheckStatus::Warning], true) && str_contains($full->value, 'JPEG'), $full->value);

    // The pixel limit follows memory_limit, which is raised for image processing if allowed.
    $limit = (string) ini_get('memory_limit');
    ini_set('memory_limit', '128M');
    $small = new \Campanella\Media\ImageProcessor(memoryLimit: '');
    $raising = new \Campanella\Media\ImageProcessor(memoryLimit: '512M');
    $pixels = $small->maxPixels();
    check($pixels < 25_000_000, 'lower with less memory: ' . $pixels);
    check($raising->maxPixels() > $pixels && ini_get('memory_limit') === '512M', 'raised: ' . $raising->maxPixels());
    ini_set('memory_limit', $limit);
    check(\Campanella\Media\ImageProcessor::iniBytes('128M') === 134217728 && \Campanella\Media\ImageProcessor::iniBytes('-1') === -1);
    throws(\InvalidArgumentException::class, fn () => new \Campanella\Media\ImageProcessor(quality: 0));

    // The system check: PHP's upload limits against max_bytes, memory_limit against max_pixels.
    $limits = static function (\Campanella\Media\ImageProcessor $p) use ($storage): array {
        $lines = [];
        foreach (\Campanella\Media\MediaCheck::checks($p, $storage)(null) as $result) {
            $lines[$result->label] = $result;
        }

        return [$lines['admin.system.media_max_file'], $lines['admin.system.media_max_image']];
    };
    [$file] = $limits(new \Campanella\Media\ImageProcessor(maxBytes: 1024 * 1024));
    check($file->status === \Campanella\System\CheckStatus::Ok && $file->value === '1 MB', $file->value);
    [$file] = $limits(new \Campanella\Media\ImageProcessor(maxBytes: 4 * 1024 ** 3));
    $upload = \Campanella\Media\ImageProcessor::iniBytes((string) ini_get('upload_max_filesize'));
    if ($upload > 0) {
        check($file->status === \Campanella\System\CheckStatus::Warning && str_contains((string) $file->hint?->params['settings'], 'upload_max_filesize') && $file->hint?->params['max'] === '4096 MB', 'limited by PHP: ' . $file->value);
    }
    ini_set('memory_limit', '128M');
    [, $image] = $limits(new \Campanella\Media\ImageProcessor(memoryLimit: ''));
    check($image->status === \Campanella\System\CheckStatus::Warning && $image->hint?->params['max'] === '25 MP', 'limited by memory: ' . $image->value);
    [, $image] = $limits(new \Campanella\Media\ImageProcessor(maxPixels: 4_000_000, memoryLimit: ''));
    check($image->status === \Campanella\System\CheckStatus::Ok && $image->value === '4 MP', $image->value);
    ini_set('memory_limit', $limit);
});

test('Admin: file sizes are shown readably in the current language', function (): void {
    $size = static function (string $locale): \Closure {
        $translator = Translator::fromDirectory(dirname(__DIR__) . '/lang', $locale);
        $extension = new \Campanella\View\CampanellaTwigExtension(static fn () => throw new LogicException(), static fn (): string => '', [], null, null, static fn (): Translator => $translator);

        return $extension->fileSize(...);
    };
    $hu = $size('hu');
    $en = $size('en');
    check($hu(840) === '840 bájt' && $en(840) === '840 bytes');
    check($hu(1536) === "1,5\u{a0}kB" || $hu(1536) === '1,5 kB', $hu(1536));
    check($en(1536) === '1.5 KB' && $en(2048) === '2 KB' && $en(56 * 1024) === '56 KB', $en(2048));
    check($en((int) (1.25 * 1024 * 1024)) === '1.3 MB' && $hu((int) (1.25 * 1024 * 1024)) === '1,3 MB' && $en(25 * 1024 * 1024) === '25 MB', $hu((int) (1.25 * 1024 * 1024)));
    check($en(1536 * 1024 * 1024) === "1\u{a0}536 MB" && $en(null) === '0 bytes', $en(1536 * 1024 * 1024));
});

test('MediaStorage: random names, safe paths, the .htaccess', function (): void {
    $dir = sys_get_temp_dir() . '/campanella-media-' . bin2hex(random_bytes(4));
    $storage = new \Campanella\Media\MediaStorage($dir, '/media');
    check($storage->isWritable());
    $path = $storage->store('tartalom', 'jpg', new DateTimeImmutable('2026-10-04', new DateTimeZone('UTC')));
    check(preg_match('#^2026/10/[0-9a-f]{24}\.jpg$#', $path) === 1, $path);
    check(file_get_contents($dir . '/' . $path) === 'tartalom' && $storage->path($path) === $dir . '/' . $path);
    check($storage->url($path) === '/media/' . $path);
    check(str_contains((string) file_get_contents($dir . '/.htaccess'), 'php_flag engine off'), 'the .htaccess is written');
    check($storage->store('x', 'png') !== $storage->store('x', 'png'), 'a new name every time');

    foreach (['../config/app.php', '2026/10/abc.php', '/etc/passwd', '2026/10/' . str_repeat('a', 24) . '.php', ''] as $bad) {
        throws(\InvalidArgumentException::class, fn () => $storage->path($bad));
    }
    throws(\InvalidArgumentException::class, fn () => $storage->store('x', 'php'));
    throws(\InvalidArgumentException::class, fn () => $storage->path($path . "\n"));
    check($storage->delete($path) && !is_file($dir . '/' . $path) && !$storage->delete($path), 'deleted once');
});

test('MediaService: uploading creates an image object; deleting it deletes the file', function () use ($repository, $policy, $admin, $editorUser): void {
    if (!extension_loaded('gd')) {
        echo "      (skipped: no gd extension)\n";

        return;
    }
    $dir = sys_get_temp_dir() . '/campanella-media-' . bin2hex(random_bytes(4));
    $storage = new \Campanella\Media\MediaStorage($dir);
    $objects = new ObjectService($repository, $policy);
    $objects->addListener(new \Campanella\Media\DeleteMediaFile($storage));
    $media = new \Campanella\Media\MediaService($objects, $repository, $policy, new \Campanella\Media\ImageProcessor(), $storage);
    $files = static fn (): array => glob($dir . '/*/*/*') ?: [];

    $editor = new Actor(\Campanella\Access\ActorKind::User, (int) $editorUser->id(), ['editor']);
    $image = $media->uploadImage($editor, testImage('jpeg', 64, 48), 'C:\\Képek\\Nyaralás 2026.JPG', '  Tengerpart  ');
    $file = $image->as(\Campanella\Capability\MediaFile::class);
    check($image->blueprint() === 'image' && $image->id() !== null);
    check($image->get('title') === 'Nyaralás 2026' && $image->get('alt') === 'Tengerpart', (string) $image->get('title'));
    check($file->mimeType() === 'image/jpeg' && $file->width() === 64 && $file->height() === 48);
    check(is_file($storage->path($file->path())) && $file->size() === filesize($storage->path($file->path())));
    check($file->hash() === hash_file('sha256', $storage->path($file->path())));
    check($image->as(\Campanella\Capability\Authorable::class)->authorId() === $editorUser->id(), 'the uploader is the author');
    check($media->url($image) === '/media/' . $file->path());

    $reloaded = $repository->find((int) $image->id());
    check($reloaded?->get('file_path') === $file->path() && $reloaded->get('width') === 64, 'stored in its capability table');

    // Nothing is stored for an invalid file or a user who may not upload.
    $before = count($files());
    check(mediaError(fn () => $media->uploadImage($admin, (string) tempnam(sys_get_temp_dir(), 'x'), 'ures.jpg')) === 'media.empty');
    throws(\Campanella\Access\AccessDeniedException::class, fn () => $media->uploadImage(Actor::anonymous(), testImage('png', 10, 10), 'a.png'));
    check(count($files()) === $before, 'no file left behind');

    $objects->delete($admin, $image);
    check(!is_file($storage->path($file->path())) && $repository->find((int) $image->id()) === null, 'deleted with its file');

    check(\Campanella\Media\MediaService::titleFrom('../../etc/passwd') === 'passwd');
    check(\Campanella\Media\MediaService::titleFrom('.jpg') === 'image' && \Campanella\Media\MediaService::titleFrom("a\x00b\n.png") === 'ab');
    check(\Campanella\Media\MediaService::titleFrom("szamla\u{202E}gpj.exe") === 'szamlagpj', 'direction-changing characters removed');
});

test('Kernel: images in the admin are listed, edited, but not created from a form', function () use ($editorUser, $admin, $newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_' || !extension_loaded('gd')) {
        echo "      (skipped: config/local.php sets its own prefix, or no gd)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dir = sys_get_temp_dir() . '/campanella-media-' . bin2hex(random_bytes(4));
    $container->set(\Campanella\Media\MediaStorage::class, static fn () => new \Campanella\Media\MediaStorage($dir));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $container->get(AuthService::class)->login(new Request('GET', '/'), $editorUser);
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post));
    };

    $image = $container->get(\Campanella\Media\MediaService::class)->uploadImage($admin, testImage('png', 20, 20), 'logo.png');

    // The system check reports the media folder and the re-encoded types.
    $checks = [];
    foreach ($container->get(\Campanella\System\SystemCheck::class)->run() as $result) {
        $checks[$result->label] = $result;
    }
    check($checks['admin.system.media_folder']->status === \Campanella\System\CheckStatus::Ok && $checks['admin.system.media_folder']->value === $dir);
    check(str_contains($checks['admin.system.media_reencode']->value, 'JPEG'), 'reencoded types listed');
    check(str_contains($checks['gd']->value, ' · ') && str_contains($checks['gd']->value, 'PNG'), 'gd with its formats: ' . $checks['gd']->value);
    check($checks['admin.system.media_max_file']->value !== '' && $checks['admin.system.media_max_image']->value !== '', 'upload limits checked');
    $list = $send('GET', '/admin/image');
    check($list->status === 200 && str_contains($list->body, 'logo') && !str_contains($list->body, 'href="/admin/image/new"'), 'listed, no New button');
    $url = $container->get(\Campanella\Media\MediaService::class)->url($image);
    check(str_contains($list->body, 'class="admin-thumb" src="' . $url . '"'), 'a thumbnail');
    check(str_contains($list->body, 'PNG · ') && str_contains($list->body, '20 × 20 px') && str_contains($list->body, 'nincs alternatív szöveg'), 'type, size, dimensions, missing alt');
    check(str_contains($list->body, 'data-media-upload') && str_contains($list->body, 'enctype="multipart/form-data"') && str_contains($list->body, 'admin-upload.js'), 'the upload form');
    $storage->endRequest();
    $sorted = $kernel->handle(new Request('GET', '/admin/image', query: ['sort' => 'file_size', 'dir' => 'asc']));
    check(str_contains($list->body, 'sort=file_size') && $sorted->status === 200, 'sortable by size: ' . $sorted->status . ' ' . substr(strip_tags($sorted->body), 0, 600));
    check(!str_contains($send('GET', '/admin/article')->body, 'data-media-upload'), 'no upload form on other lists');
    check($send('GET', '/admin/image/new')->status === 404, 'no empty form for a file');
    $form = $send('GET', '/admin/image/' . $image->id());
    check($form->status === 200 && str_contains($form->body, 'name="f[alt]"') && !str_contains($form->body, 'f[file_path]') && !str_contains($form->body, 'f[file_hash]'), 'file data is not in the form');
    check(str_contains($form->body, 'class="card mb-3 admin-media"') && str_contains($form->body, '<code class="text-break user-select-all">' . $url . '</code>') && str_contains($form->body, '20 × 20 px'), 'the preview and file data beside the form');

    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $form->body, $c);
    preg_match('/name="_version" value="([0-9a-f]{64})"/', $form->body, $v);
    $send('POST', '/admin/image/' . $image->id(), ['_csrf' => $c[1] ?? '', '_version' => $v[1] ?? '', 'f' => [
        'title' => 'Logó', 'alt' => 'A Campanella logója', 'file_path' => '../../config/local.php',
    ]]);
    $saved = $container->get(ObjectRepository::class)->find((int) $image->id());
    check($saved?->get('alt') === 'A Campanella logója' && $saved->get('file_path') === $image->get('file_path'), 'a posted file path is ignored');

    // Deleting (administrators): which texts show it (none here; since 0.1.2).
    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('kep-torlo@example.hu', 'kep-torlo-jelszo', ['administrator']));
    $delete = $send('GET', '/admin/image/' . $image->id() . '/delete');
    check($delete->status === 200 && str_contains($delete->body, 'Egyetlen szöveg sem mutatja ezt a képet.') && !str_contains($delete->body, 'hiányzó kép lesz') && str_contains($delete->body, 'src="' . $url . '"'), 'the delete page: not used: ' . $delete->status);
    check(!str_contains($send('GET', '/admin/article')->body, 'hiányzó kép'), 'no image warning elsewhere');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Request: only files PHP received as uploads are accepted', function (): void {
    $saved = [$_FILES, $_SERVER];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/admin/media/upload';
    $_SERVER['CONTENT_LENGTH'] = '1234';
    $_FILES = [
        'file' => ['name' => 'passwd.jpg', 'tmp_name' => '/etc/passwd', 'size' => 100, 'error' => UPLOAD_ERR_OK],
        'big' => ['name' => 'nagy.jpg', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE],
        'many' => ['name' => ['a.jpg', 'b.jpg'], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'size' => [1, 1], 'error' => [0, 0]],
    ];
    $request = Request::fromGlobals();
    check($request->file('file') === null, 'a path that was not uploaded is ignored');
    check($request->file('big')?->isTooLarge() === true, 'a failed upload keeps its error');
    check($request->file('many') === null && $request->headers['content-length'] === '1234');
    [$_FILES, $_SERVER] = $saved;
});

test('Kernel: uploading an image from the admin (POST /admin/media/upload)', function () use ($editorUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_' || !extension_loaded('gd')) {
        echo "      (skipped: config/local.php sets its own prefix, or no gd)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dir = sys_get_temp_dir() . '/campanella-media-' . bin2hex(random_bytes(4));
    $container->set(\Campanella\Media\MediaStorage::class, static fn () => new \Campanella\Media\MediaStorage($dir));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $auth = $container->get(AuthService::class);
    $auth->login(new Request('GET', '/'), $editorUser);
    $send = function (string $method, string $path, array $post = [], array $files = [], array $headers = ['accept' => 'application/json']) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post, headers: $headers, files: $files));
    };
    $form = $send('GET', '/admin/article/new');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $form->body, $m);
    $csrf = $m[1] ?? '';
    check(str_contains($form->body, 'data-upload-url="/admin/media/upload"') && str_contains($form->body, 'data-upload-max="'), 'the form offers the upload');
    $json = static fn ($response): array => (array) json_decode($response->body, true);
    $upload = static fn (string $file, string $name = 'kep.jpg', int $error = UPLOAD_ERR_OK) => ['file' => new \Campanella\Http\UploadedFile($name, $file, (int) @filesize($file), $error)];

    $ok = $send('POST', '/admin/media/upload', ['_csrf' => $csrf], $upload(testImage('jpeg', 120, 80), 'Nyaralás.jpg'));
    $data = $json($ok);
    check($ok->status === 201 && ($ok->headers['Content-Type'] ?? '') === 'application/json; charset=utf-8', 'created: ' . $ok->status . ' ' . $ok->body);
    check($data['success'] === true && $data['title'] === 'Nyaralás' && $data['width'] === 120 && preg_match('#^/media/\d{4}/\d{2}/[0-9a-f]{24}\.jpg$#', (string) $data['url']) === 1, $ok->body);
    check(is_file($dir . substr((string) $data['url'], strlen('/media'))), 'the file is stored');
    $image = $container->get(ObjectRepository::class)->find((int) $data['id']);
    check($image?->as(\Campanella\Capability\Authorable::class)->authorId() === $editorUser->id(), 'the editor is the author');

    $bad = $send('POST', '/admin/media/upload', ['_csrf' => $csrf], $upload((static function (): string {
        $f = (string) tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($f, '<?php echo 1; ?>');

        return $f;
    })(), 'rossz.jpg'));
    check($bad->status === 422 && $json($bad)['success'] === false && $json($bad)['message'] === 'A fájl nem kép (vagy sérült).', $bad->body);

    check($send('POST', '/admin/media/upload', [], $upload(testImage('png', 10, 10)))->status === 400, 'without the CSRF token');
    check($json($send('POST', '/admin/media/upload', ['_csrf' => $csrf]))['message'] === 'A fájl üres, vagy nem érkezett meg.', 'no file');
    $tooLarge = $send('POST', '/admin/media/upload', ['_csrf' => $csrf], $upload('', 'nagy.jpg', UPLOAD_ERR_INI_SIZE));
    check($tooLarge->status === 413 && str_contains((string) $json($tooLarge)['message'], 'MB'), 'refused by PHP for its size: ' . $tooLarge->body);
    check($send('POST', '/admin/media/upload', [], [], ['accept' => 'application/json', 'content-length' => '20', 'content-type' => 'application/json'])->status === 400, 'an empty non-form POST is not "too large"');
    $discarded = $send('POST', '/admin/media/upload', [], [], ['accept' => 'application/json', 'content-length' => '99999999', 'content-type' => 'multipart/form-data; boundary=x']);
    check($discarded->status === 413, 'a body PHP discarded (post_max_size) is reported as too large, not as an expired form');
    check($send('POST', '/admin/media/upload', ['_csrf' => $csrf], $upload('', 'x.jpg', UPLOAD_ERR_NO_TMP_DIR))->status === 500);
    check($send('GET', '/admin/media/upload')->status === 405 && $send('POST', '/admin/media/other')->status === 404);
    check(str_contains((string) ($ok->headers['X-Robots-Tag'] ?? ''), 'noindex'), 'admin headers');

    // The upload form without JavaScript (no Accept: application/json): back to the list, with a message.
    $html = ['accept' => 'text/html'];
    $plain = $send('POST', '/admin/media/upload', ['_csrf' => $csrf], $upload(testImage('png', 12, 12), 'Űrlapról.png'), $html);
    check($plain->status === 303 && ($plain->headers['Location'] ?? '') === '/admin/image', 'redirected: ' . $plain->status);
    check(str_contains($send('GET', '/admin/image', [], [], $html)->body, 'Feltöltve: Űrlapról'), 'success message');
    $plainBad = $send('POST', '/admin/media/upload', ['_csrf' => $csrf], $upload('', 'nagy.jpg', UPLOAD_ERR_INI_SIZE), $html);
    check($plainBad->status === 303 && str_contains($send('GET', '/admin/image', [], [], $html)->body, 'A fájl túl nagy'), 'error message');

    // After the session expired, the editor gets JSON, not the login page.
    $auth->logout();
    $expired = $send('POST', '/admin/media/upload', ['_csrf' => $csrf], $upload(testImage('png', 10, 10)));
    check($expired->status === 401 && $json($expired)['message'] === 'A munkamenet lejárt. Jelentkezz be újra (például egy másik lapon), majd próbáld újra.', $expired->body);
    check($send('GET', '/admin/article')->status === 302, 'other admin pages still redirect to the login page');
    $plainExpired = $send('POST', '/admin/media/upload', ['_csrf' => $csrf], $upload(testImage('png', 10, 10)), ['accept' => 'text/html']);
    check($plainExpired->status === 302 && str_ends_with($plainExpired->headers['Location'] ?? '', '?return=%2Fadmin%2Fimage'), 'the form without JavaScript: to the login page, then back to the list');

    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nDatabase schema\n";

// A database of its own (prefix sch_), so the comparison sees only these tables.
$schemaDb = Connection::fromConfig(['prefix' => 'sch_'] + $config->get('database'));
$dropSchema = static function () use ($schemaDb): void {
    $schemaDb->execute('SET FOREIGN_KEY_CHECKS = 0');
    foreach ((new \Campanella\Database\Schema\SchemaReader($schemaDb))->tableNames() as $name) {
        $schemaDb->execute('DROP TABLE IF EXISTS ' . $schemaDb->table($name));
    }
    $schemaDb->execute('SET FOREIGN_KEY_CHECKS = 1');
};

test('SchemaReader: defaults are read alike on MariaDB and MySQL', function (): void {
    $n = \Campanella\Database\Schema\SchemaReader::normalizeDefault(...);
    check($n("'active'") === 'active' && $n('active') === 'active' && $n("'it''s'") === "it's");
    check($n('NULL') === null && $n(null) === null && $n('0') === '0' && $n(0) === '0' && $n("''") === '');
});

test('SchemaReader: a fresh installation is read as defined, and matches its definitions', function () use ($schemaDb, $dropSchema, $capabilities): void {
    $dropSchema();
    $installer = new Installer($schemaDb, $capabilities);
    $installer->install();
    $differences = $installer->differences();
    check($differences === [], implode(' | ', array_map(static fn ($d): string => $d->kind->value . ' ' . $d->table . '.' . $d->name . ' ' . $d->actual, $differences)));

    $reader = new \Campanella\Database\Schema\SchemaReader($schemaDb);
    $names = $reader->tableNames();
    check(in_array('objects', $names, true) && in_array('cap_media_file', $names, true) && !in_array('sch_objects', $names, true), implode(', ', $names));
    $objects = $reader->read('objects');
    check($objects !== null && $objects->primaryKey === ['id'] && $objects->column('id')?->type === \Campanella\Database\Schema\ColumnType::Id && $objects->column('id')->autoIncrement);
    check($objects->column('uuid')?->type === \Campanella\Database\Schema\ColumnType::Uuid && $objects->column('data')?->type === \Campanella\Database\Schema\ColumnType::Json);
    check($objects->column('blueprint')?->length === 64 && !$objects->column('blueprint')->nullable && $objects->uniques === ['uniq_uuid' => ['uuid']]);
    $relationships = $reader->read('relationships');
    check($relationships?->column('weight')?->default === '0' && $relationships->indexes['idx_source'] === ['source_id', 'type', 'weight']);
    $fks = array_values($relationships->foreignKeys);
    check(count($fks) === 2 && $fks[0]['table'] === 'objects' && $fks[0]['cascadeDelete'], json_encode($fks));
    check($reader->read('missing') === null && $reader->tableExists('objects') && !$reader->tableExists('missing'));
    check($reader->columnExists('objects', 'uuid') && !$reader->columnExists('objects', 'nope') && $reader->indexExists('objects', 'idx_blueprint'));
});

test('SchemaComparator: finds the differences; the additive ones are applied with the generated SQL', function () use ($schemaDb): void {
    $builder = new \Campanella\Database\Schema\SchemaBuilder($schemaDb);
    $reader = new \Campanella\Database\Schema\SchemaReader($schemaDb);
    $comparator = new \Campanella\Database\Schema\SchemaComparator($reader, $builder);

    $v1 = new \Campanella\Database\Schema\Table('demo', [
        new \Campanella\Database\Schema\Column('object_id', \Campanella\Database\Schema\ColumnType::Id),
        new \Campanella\Database\Schema\Column('title', \Campanella\Database\Schema\ColumnType::String, length: 100),
        new \Campanella\Database\Schema\Column('note', \Campanella\Database\Schema\ColumnType::Text, nullable: true),
        new \Campanella\Database\Schema\Column('hits', \Campanella\Database\Schema\ColumnType::Integer, default: 0),
    ], ['object_id'], indexes: ['idx_title' => ['title']], foreignKeys: [new \Campanella\Database\Schema\ForeignKey('object_id', 'objects')]);
    $schemaDb->execute('DROP TABLE IF EXISTS ' . $schemaDb->table('demo'));
    $builder->create($v1);
    check($comparator->compareTable($v1, $reader->read('demo')) === [], 'v1 matches');
    $object = $schemaDb->insert('objects', ['uuid' => '00000000-0000-4000-8000-000000000001', 'blueprint' => 'x', 'data' => '{}', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
    $schemaDb->insert('demo', ['object_id' => $object, 'title' => 'Régi sor', 'note' => 'x', 'hits' => 3]);

    // The next version of the definition.
    $v2 = new \Campanella\Database\Schema\Table('demo', [
        new \Campanella\Database\Schema\Column('object_id', \Campanella\Database\Schema\ColumnType::Id),
        new \Campanella\Database\Schema\Column('title', \Campanella\Database\Schema\ColumnType::String, length: 120),
        new \Campanella\Database\Schema\Column('subtitle', \Campanella\Database\Schema\ColumnType::String, nullable: true, length: 80),
        new \Campanella\Database\Schema\Column('hits', \Campanella\Database\Schema\ColumnType::Integer, default: 1),
        new \Campanella\Database\Schema\Column('weight', \Campanella\Database\Schema\ColumnType::Integer, default: 5),
        new \Campanella\Database\Schema\Column('code', \Campanella\Database\Schema\ColumnType::String, length: 16),
    ], ['object_id'], indexes: ['idx_title' => ['title'], 'idx_weight' => ['weight']], uniques: ['uniq_code' => ['code']], foreignKeys: [new \Campanella\Database\Schema\ForeignKey('object_id', 'objects')]);
    $found = [];
    foreach ($comparator->compareTable($v2, $reader->read('demo')) as $d) {
        $found[$d->kind->value . ':' . $d->name] = $d;
    }
    ksort($found);
    check(array_keys($found) === [
        'column_default:hits', 'column_type:title', 'extra_column:note', 'missing_column:code', 'missing_column:subtitle',
        'missing_column:weight', 'missing_index:idx_weight', 'missing_index:uniq_code',
    ], implode(', ', array_keys($found)));
    check($found['column_type:title']->expected === 'VARCHAR(120)' && $found['column_type:title']->actual === 'varchar(100)' && $found['column_default:hits']->actual === '0');
    check($found['missing_column:subtitle']->additive && $found['missing_column:weight']->additive && $found['missing_index:idx_weight']->additive, 'NULL or a default: additive');
    check(!$found['missing_column:code']->additive && !$found['extra_column:note']->additive && $found['column_type:title']->sql === null, 'no guessing');
    check(str_contains((string) $found['missing_column:subtitle']->sql, 'AFTER `title`') && str_contains((string) $found['extra_column:note']->sql, 'DROP COLUMN `note`'));
    check($found['missing_column:code']->message()->key === 'schema.missing_column' && $found['missing_column:code']->message()->params['table'] === 'demo');

    // Applying the additive ones: the existing row gets the default; the rest is left alone.
    foreach ($found as $d) {
        if ($d->additive && $d->kind !== \Campanella\Database\Schema\DifferenceKind::MissingIndex) {
            $schemaDb->execute((string) $d->sql);
        }
    }
    $schemaDb->execute((string) $found['missing_index:idx_weight']->sql);
    $row = $schemaDb->fetchOne('SELECT * FROM {demo}');
    check($row !== null && (int) $row['weight'] === 5 && $row['subtitle'] === null && $row['note'] === 'x', json_encode($row));
    check(array_keys($reader->read('demo')?->columns ?? []) === ['object_id', 'title', 'subtitle', 'note', 'hits', 'weight'], 'in the order of the definition');
    $left = array_map(static fn ($d): string => $d->kind->value . ':' . $d->name, $comparator->compareTable($v2, $reader->read('demo')));
    sort($left);
    check($left === ['column_default:hits', 'column_type:title', 'extra_column:note', 'missing_column:code', 'missing_index:uniq_code'], implode(', ', $left));
    $schemaDb->execute((string) $found['extra_column:note']->sql);
    check(!$reader->columnExists('demo', 'note'), 'dropped');

    // An index the server added for a foreign key by itself is not a difference.
    $fkOnly = new \Campanella\Database\Schema\Table('demo_fk', [
        new \Campanella\Database\Schema\Column('id', \Campanella\Database\Schema\ColumnType::Id, autoIncrement: true),
        new \Campanella\Database\Schema\Column('object_id', \Campanella\Database\Schema\ColumnType::Id),
    ], ['id'], foreignKeys: [new \Campanella\Database\Schema\ForeignKey('object_id', 'objects')]);
    $schemaDb->execute('DROP TABLE IF EXISTS ' . $schemaDb->table('demo_fk'));
    $builder->create($fkOnly);
    $info = $reader->read('demo_fk');
    check($info !== null && ($info->indexes + $info->uniques) !== [] && $comparator->compareTable($fkOnly, $info) === [], json_encode($info?->indexes));
    $schemaDb->execute('ALTER TABLE ' . $schemaDb->table('demo_fk') . ' DROP FOREIGN KEY ' . \Campanella\Database\Connection::quoteIdentifier($builder->foreignKeyName($fkOnly, $fkOnly->foreignKeys[0])));
    $missingFk = $comparator->compareTable($fkOnly, (new \Campanella\Database\Schema\SchemaReader($schemaDb))->read('demo_fk') ?? $info);
    check(in_array(\Campanella\Database\Schema\DifferenceKind::MissingForeignKey, array_map(static fn ($d) => $d->kind, $missingFk), true), 'a missing foreign key');

    // A missing table: created by its SQL; a table no definition has: reported.
    $missing = $comparator->compare([new \Campanella\Database\Schema\Table('demo_new', [new \Campanella\Database\Schema\Column('id', \Campanella\Database\Schema\ColumnType::Id)], ['id'])]);
    $kinds = array_map(static fn ($d): string => $d->kind->value . ':' . $d->table, $missing);
    check(in_array('missing_table:demo_new', $kinds, true) && in_array('extra_table:demo', $kinds, true) && in_array('extra_table:objects', $kinds, true), implode(', ', $kinds));
    check(str_starts_with((string) $missing[0]->sql, 'CREATE TABLE IF NOT EXISTS `sch_demo_new`') && $missing[0]->additive);
});

test('schema:check and the System page report the differences', function () use ($schemaDb, $dropSchema): void {
    putenv('CAMPANELLA_DB_PREFIX=sch_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    if ($kernel->container()->get(Connection::class)->prefix() !== 'sch_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $run = static function (array $args) use ($kernel): array {
        $stream = fopen('php://memory', 'w+');
        $code = (new \Campanella\Cli\SchemaCheckCommand())->run($kernel->container(), $args, new \Campanella\Cli\Output($stream, $stream));
        rewind($stream);

        return [$code, (string) stream_get_contents($stream)];
    };
    // The previous test left the demo tables: tables no definition has, only reported.
    [$code, $out] = $run([]);
    check($code === 0 && str_contains($out, 'Definíció nélküli tábla: demo'), $out);

    $schemaDb->execute('ALTER TABLE ' . $schemaDb->table('cap_media_file') . ' DROP COLUMN `width`');
    $schemaDb->execute('ALTER TABLE ' . $schemaDb->table('objects') . ' DROP INDEX `idx_created`');
    [$code, $out] = $run([]);
    check($code === 1 && str_contains($out, 'Hiányzó oszlop: cap_media_file.width (INT).') && str_contains($out, '[hozzáadó]'), $out);
    [$code, $sql] = $run(['--sql']);
    check($code === 0 && str_contains($sql, 'ALTER TABLE `sch_cap_media_file` ADD COLUMN `width` INT NULL AFTER `file_size`;') && str_contains($sql, 'ADD INDEX `idx_created` (`created_at`);'), $sql);

    $lines = [];
    foreach ($kernel->container()->get(\Campanella\System\SystemCheck::class)->run() as $result) {
        if ($result->group === 'admin.system.group.schema') {
            $lines[$result->label] = $result;
        }
    }
    check(isset($lines['cap_media_file.width']) && $lines['cap_media_file.width']->status === \Campanella\System\CheckStatus::Warning && $lines['cap_media_file.width']->value === 'schema.kind.missing_column', implode(', ', array_keys($lines)));
    check(isset($lines['demo']) && $lines['demo']->status === \Campanella\System\CheckStatus::Info, 'extra table: info');

    // Applying the printed statements makes them match again.
    foreach (array_filter(explode(";\n", $sql), static fn (string $s): bool => str_starts_with(trim($s), 'ALTER') || str_starts_with(trim($s), 'CREATE')) as $statement) {
        $schemaDb->execute($statement);
    }
    $dropSchema();
    $kernel->container()->get(Installer::class)->install();
    [$code, $out] = $run([]);
    $lines = array_filter($kernel->container()->get(\Campanella\System\SystemCheck::class)->run(), static fn ($r): bool => $r->group === 'admin.system.group.schema');
    check($code === 0 && str_contains($out, 'megfelel') && count($lines) === 1 && array_values($lines)[0]->status === \Campanella\System\CheckStatus::Ok, $out);
    $dropSchema();
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nMigrations\n";

/** A migration for the tests: runs the given function. */
$migration = static function (string $id, Closure $up, string $description = 'Test migration'): \Campanella\Database\Migration\Migration {
    return new class ($id, $up, $description) implements \Campanella\Database\Migration\Migration {
        public function __construct(private string $id, private Closure $up, private string $description)
        {
        }

        public function id(): string
        {
            return $this->id;
        }

        public function description(): string
        {
            return $this->description;
        }

        public function up(\Campanella\Database\Migration\MigrationContext $m): void
        {
            ($this->up)($m);
        }
    };
};

test('MigrationRegistry: IDs are checked, the order is kept', function () use ($migration): void {
    $noop = static function (): void {
    };
    $registry = new \Campanella\Database\Migration\MigrationRegistry([$migration('core:0002_b', $noop), $migration('core:0001_a', $noop)]);
    check(array_map(static fn ($m): string => $m->id(), $registry->all()) === ['core:0002_b', 'core:0001_a'], 'registration order');
    throws(\InvalidArgumentException::class, fn () => $registry->add($migration('core:0001_a', $noop)));
    foreach (['nosource', 'Core:x', 'core:', 'core:a b', ':x', 'core:' . str_repeat('a', 130)] as $bad) {
        throws(\InvalidArgumentException::class, fn () => new \Campanella\Database\Migration\MigrationRegistry([$migration($bad, $noop)]));
    }
    throws(\LogicException::class, fn () => \Campanella\Database\Migration\MigrationRegistry::fromClasses([\stdClass::class]));
    throws(\LogicException::class, fn () => \Campanella\Database\Migration\MigrationRegistry::fromClasses(['No\\Such\\Migration']));
    check(count(\Campanella\Database\Migration\MigrationRegistry::fromClasses(\Campanella\Database\Migration\CoreMigrations::classes())->all()) === count(\Campanella\Database\Migration\CoreMigrations::classes()), 'the core migrations are valid');
});

test('Migrator: a fresh installation records every migration; an existing one runs the pending ones', function () use ($schemaDb, $dropSchema, $capabilities, $migration): void {
    $dropSchema();
    $calls = [];
    $registry = new \Campanella\Database\Migration\MigrationRegistry([
        $migration('test:0001_first', function () use (&$calls): void {
            $calls[] = 'first';
        }),
    ]);
    $migrator = new \Campanella\Database\Migration\Migrator($schemaDb, $registry);
    $installer = new Installer($schemaDb, $capabilities, $migrator);
    check($migrator->applied() === [], 'no table yet: nothing applied');
    $installer->install();
    check($calls === [] && array_keys($migrator->applied()) === ['test:0001_first'] && $migrator->pending() === [] && !$installer->needsUpgrade(), 'fresh: recorded, not run');

    // A new version brings two more migrations; the second fails the first time.
    $fail = true;
    $registry->add($migration('test:0002_add_column', function (\Campanella\Database\Migration\MigrationContext $m) use (&$calls): void {
        $calls[] = 'second';
        $m->addColumn('throttle', new \Campanella\Database\Schema\Column('note', \Campanella\Database\Schema\ColumnType::String, nullable: true, length: 32), 'hits');
        $m->addIndex('throttle', 'idx_note', ['note']);
        $m->log('note added');
    }, 'Adds a note'));
    $registry->add($migration('test:0003_fails', function () use (&$fail, &$calls): void {
        $calls[] = 'third';
        if ($fail) {
            throw new \RuntimeException('boom');
        }
    }));
    check(array_map(static fn ($m): string => $m->id(), $migrator->pending()) === ['test:0002_add_column', 'test:0003_fails'] && $installer->needsUpgrade(), 'pending: upgrade needed');
    $logged = [];
    $started = [];
    try {
        $migrator->run(function ($m) use (&$started): void {
            $started[] = $m->id();
        }, function (string $line) use (&$logged): void {
            $logged[] = $line;
        });
        check(false, 'the failure must be reported');
    } catch (\Campanella\Database\Migration\MigrationException $e) {
        check($e->migrationId === 'test:0003_fails' && $e->reason->key === 'migration.failed' && $e->getPrevious()?->getMessage() === 'boom');
        check(count($e->applied) === 1 && $e->applied[0]->id === 'test:0002_add_column', 'the earlier one is recorded');
    }
    check($started === ['test:0002_add_column', 'test:0003_fails'] && $logged === ['note added'], implode(',', $started));
    check(array_map(static fn ($m): string => $m->id(), $migrator->pending()) === ['test:0003_fails'], 'the failed one is still pending');
    check((new \Campanella\Database\Schema\SchemaReader($schemaDb))->columnExists('throttle', 'note'), 'the column was added');
    $row = $schemaDb->fetchOne('SELECT description, duration_ms FROM {migrations} WHERE id = :id', ['id' => 'test:0002_add_column']);
    check($row !== null && $row['description'] === 'Adds a note' && (int) $row['duration_ms'] >= 0);

    $fail = false;
    $results = $migrator->run();
    check(count($results) === 1 && $results[0]->id === 'test:0003_fails' && $migrator->pending() === [] && !$installer->needsUpgrade(), 'run again: done');
    check($calls === ['second', 'third', 'third'], implode(',', $calls));
    check($migrator->run() === [], 'nothing left');

    // Repeatable: running a migration's steps again does nothing.
    $context = new \Campanella\Database\Migration\MigrationContext($schemaDb);
    check(!$context->addColumn('throttle', new \Campanella\Database\Schema\Column('note', \Campanella\Database\Schema\ColumnType::String, nullable: true)) && !$context->addIndex('throttle', 'idx_note', ['note']));
});

test('MigrationContext: renaming, dropping, batches', function () use ($schemaDb): void {
    $m = new \Campanella\Database\Migration\MigrationContext($schemaDb);
    check($m->renameColumn('throttle', 'note', 'remark') && !$m->renameColumn('throttle', 'note', 'remark') && $m->columnExists('throttle', 'remark') && !$m->columnExists('throttle', 'note'));
    check($m->dropIndex('throttle', 'idx_note') && !$m->dropIndex('throttle', 'idx_note') && !$m->indexExists('throttle', 'idx_note'));
    check($m->dropColumn('throttle', 'remark') && !$m->dropColumn('throttle', 'remark'));
    check($m->tableExists('objects') && !$m->tableExists('nope'));
    foreach (range(1, 5) as $i) {
        $m->sql('INSERT INTO {system} (name, value) VALUES (:n, :v)', ['n' => "batch_{$i}", 'v' => (string) $i]);
    }
    $seen = [];
    $count = $m->eachRow('system', 'name', function (array $row) use (&$seen): void {
        $seen[] = $row['value'];
    }, 'name LIKE :p', ['p' => 'batch\_%'], 2);
    check($count === 5 && $seen === ['1', '2', '3', '4', '5'], implode(',', $seen));
    check($m->db() === $schemaDb);
});

test('Migrator: only one run at a time', function () use ($schemaDb, $config, $migration): void {
    $other = Connection::fromConfig(['prefix' => 'sch_'] + $config->get('database'));
    $name = 'cmp_mig_' . substr(hash('sha256', 'sch_'), 0, 16);
    check((int) $other->fetchValue('SELECT GET_LOCK(CONCAT(:n, MD5(DATABASE())), 0)', ['n' => $name]) === 1, 'the other run holds the lock');
    $ran = false;
    $migrator = new \Campanella\Database\Migration\Migrator($schemaDb, new \Campanella\Database\Migration\MigrationRegistry([
        $migration('test:0010_locked', function () use (&$ran): void {
            $ran = true;
        }),
    ]));
    try {
        $migrator->run();
        check(false, 'locked');
    } catch (\Campanella\Database\Migration\MigrationException $e) {
        check($e->reason->key === 'migration.locked' && !$ran);
    }
    $other->fetchValue('SELECT RELEASE_LOCK(CONCAT(:n, MD5(DATABASE())))', ['n' => $name]);
    check(count($migrator->run()) === 1 && $ran, 'runs once the lock is free');
});

test('DatabaseBackup: an SQL file that restores the tables', function () use ($schemaDb): void {
    $dir = sys_get_temp_dir() . '/campanella-backup-' . bin2hex(random_bytes(4));
    $backup = new \Campanella\Database\DatabaseBackup($schemaDb, $dir);
    $schemaDb->insert('system', ['name' => 'backup_test', 'value' => "it's \"quoted\";\nnew line \\ backslash, ünnep"]);
    $schemaDb->insert('system', ['name' => 'backup_null', 'value' => null]);

    $sql = '';
    $tables = [];
    $backup->write(function (string $text) use (&$sql): void {
        $sql .= $text;
    }, function (string $table, int $rows) use (&$tables): void {
        $tables[$table] = $rows;
    });
    check(str_contains($sql, 'DROP TABLE IF EXISTS `sch_objects`;') && str_contains($sql, 'CREATE TABLE `sch_objects`') && str_ends_with($sql, \Campanella\Database\DatabaseBackup::COMPLETE . "\n"));
    check(isset($tables['system'], $tables['migrations']) && $tables['system'] >= 2, json_encode($tables));

    // Restoring it brings back the data as it was.
    $schemaDb->update('system', ['value' => 'changed'], ['name' => 'backup_test']);
    $schemaDb->delete('system', ['name' => 'backup_null']);
    $schemaDb->pdo()->exec($sql);
    check($schemaDb->fetchValue('SELECT value FROM {system} WHERE name = :n', ['n' => 'backup_test']) === "it's \"quoted\";\nnew line \\ backslash, ünnep", 'restored');
    check($schemaDb->fetchOne('SELECT value FROM {system} WHERE name = :n', ['n' => 'backup_null']) === ['value' => null], 'NULL kept');

    $gz = $backup->create();
    $plain = $backup->create(false);
    check(str_ends_with($gz, '.sql.gz') && str_ends_with($plain, '.sql') && is_file($dir . '/.htaccess'), $gz);
    check((fileperms($plain) & 0777) === 0600, decoct(fileperms($plain) & 0777));
    check(str_ends_with(trim((string) file_get_contents($plain)), \Campanella\Database\DatabaseBackup::COMPLETE) && str_ends_with(trim((string) gzdecode((string) file_get_contents($gz))), \Campanella\Database\DatabaseBackup::COMPLETE));
    $files = $backup->files();
    check(count($files) === 2 && $files[0]['size'] > 0 && glob($dir . '/*.part') === [], json_encode($files));
    throws(\RuntimeException::class, fn () => (new \Campanella\Database\DatabaseBackup($schemaDb, '/proc/campanella-no'))->create());
});

test('migrate, install and the System page: pending migrations', function () use ($schemaDb, $dropSchema, $migration): void {
    putenv('CAMPANELLA_DB_PREFIX=sch_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'sch_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dropSchema();
    $dir = sys_get_temp_dir() . '/campanella-backup-' . bin2hex(random_bytes(4));
    $registry = new \Campanella\Database\Migration\MigrationRegistry();
    $container->set(\Campanella\Database\Migration\Migrator::class, static fn (\Campanella\Core\Container $c) => new \Campanella\Database\Migration\Migrator($c->get(Connection::class), $registry));
    $container->set(\Campanella\Database\DatabaseBackup::class, static fn (\Campanella\Core\Container $c) => new \Campanella\Database\DatabaseBackup($c->get(Connection::class), $dir));
    $run = static function (\Campanella\Cli\Command $command, array $args) use ($container): array {
        $stream = fopen('php://memory', 'w+');
        $code = $command->run($container, $args, new \Campanella\Cli\Output($stream, $stream));
        rewind($stream);

        return [$code, (string) stream_get_contents($stream)];
    };
    $nonInteractive = static fn () => new \Campanella\Cli\Input(fopen('php://memory', 'r'));

    [$code] = $run(new \Campanella\Cli\InstallCommand($nonInteractive()), []);
    check($code === 0, 'fresh install');
    [$code, $out] = $run(new \Campanella\Cli\MigrateCommand($nonInteractive()), []);
    check($code === 0 && str_contains($out, 'naprakész'), $out);

    $ran = 0;
    $registry->add($migration('test:0020_cli', function (\Campanella\Database\Migration\MigrationContext $m) use (&$ran): void {
        $ran++;
        $m->log('egy sor');
    }, 'CLI test'));
    $lines = array_values(array_filter($container->get(\Campanella\System\SystemCheck::class)->run(), static fn ($r): bool => $r->label === 'admin.system.migrations'));
    check(count($lines) === 1 && $lines[0]->status === \Campanella\System\CheckStatus::Error && $lines[0]->value === '0 / 1', 'the System page: pending is an error');

    [$code, $out] = $run(new \Campanella\Cli\MigrateCommand($nonInteractive()), ['--dry-run']);
    check($code === 0 && str_contains($out, 'test:0020_cli  CLI test') && $ran === 0, 'dry run lists only');
    [$code, $out] = $run(new \Campanella\Cli\MigrateCommand($nonInteractive()), []);
    check($code === 1 && str_contains($out, '--yes') && $ran === 0, 'not a terminal: needs --yes');
    [$code, $out] = $run(new \Campanella\Cli\InstallCommand($nonInteractive()), ['--yes']);
    check($code === 0 && $ran === 1 && str_contains($out, 'Mentés: ' . $dir) && str_contains($out, '    egy sor') && str_contains($out, '1 migráció lefutott'), $out);
    check(count(glob($dir . '/campanella-*.sql.gz') ?: []) === 1, 'a backup was made first');
    $lines = array_values(array_filter($container->get(\Campanella\System\SystemCheck::class)->run(), static fn ($r): bool => $r->label === 'admin.system.migrations'));
    check($lines[0]->status === \Campanella\System\CheckStatus::Ok && $lines[0]->value === '1 / 1');

    $registry->add($migration('test:0021_skip_backup', function () use (&$ran): void {
        $ran++;
    }));
    [$code, $out] = $run(new \Campanella\Cli\MigrateCommand($nonInteractive()), ['--yes', '--no-backup']);
    check($code === 0 && $ran === 2 && !str_contains($out, 'Mentés:') && count(glob($dir . '/*.gz') ?: []) === 1, $out);
    [$code, $out] = $run(new \Campanella\Cli\DbBackupCommand(), ['--plain']);
    check($code === 0 && count(glob($dir . '/*.sql') ?: []) === 1 && str_contains($out, 'jelszó-hasheket'), $out);

    $dropSchema();
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Upgrading from the browser: the site waits, an administrator or the key runs it', function () use ($dropSchema, $migration): void {
    putenv('CAMPANELLA_DB_PREFIX=sch_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'sch_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dropSchema();
    $dir = sys_get_temp_dir() . '/campanella-backup-' . bin2hex(random_bytes(4));
    $registry = new \Campanella\Database\Migration\MigrationRegistry();
    $container->set(\Campanella\Database\Migration\Migrator::class, static fn (\Campanella\Core\Container $c) => new \Campanella\Database\Migration\Migrator($c->get(Connection::class), $registry));
    $container->set(\Campanella\Database\DatabaseBackup::class, static fn (\Campanella\Core\Container $c) => new \Campanella\Database\DatabaseBackup($c->get(Connection::class), $dir));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $installer = $container->get(Installer::class);
    $installer->install();
    $send = function (string $method, string $path, array $post = [], string $ip = '10.1.1.1') use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post, ip: $ip));
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';

    check($send('GET', '/')->status === 200 && $send('GET', '/admin/upgrade')->status === 200, 'nothing pending: the site works');
    check(str_contains($send('GET', '/admin/upgrade')->body, 'naprakész'), 'up to date');

    $ran = 0;
    $registry->add($migration('test:0030_web', function (\Campanella\Database\Migration\MigrationContext $m) use (&$ran): void {
        $ran++;
        $m->log('webes sor');
    }, 'Web test'));
    $home = $send('GET', '/');
    check($home->status === 503 && ($home->headers['Retry-After'] ?? '') === '300' && str_contains($home->body, 'frissítés alatt'), 'visitors wait: ' . $home->status);
    $admin = $send('GET', '/admin');
    check($admin->status === 503 && str_contains($admin->body, '/admin/upgrade'), 'the admin points to the upgrade page');
    check($send('GET', '/login')->status === 200, 'logging in still works');

    // Anonymous, no key configured: no details, how to set a key, no running.
    $page = $send('GET', '/admin/upgrade');
    check($page->status === 200 && !str_contains($page->body, 'test:0030_web') && str_contains($page->body, "'upgrade' => ['key' => '") && str_contains($page->body, '/login?return=%2Fadmin%2Fupgrade'), 'anonymous: no details');
    check(!str_contains($page->body, 'name="key"') && str_contains((string) ($page->headers['Content-Security-Policy'] ?? ''), "script-src 'self'"));
    // (The page has no form without a key; a token from the login page.)
    $refused = $send('POST', '/admin/upgrade', ['_csrf' => $csrfOf($send('GET', '/login'))]);
    check($refused->status === 403 && $ran === 0 && str_contains($refused->body, 'csak adminisztrátor'), $refused->status . ' ' . strip_tags($refused->body));

    // With a key in the configuration.
    $key = str_repeat('k', 24);
    $container->set('controller.upgrade', static fn (\Campanella\Core\Container $c) => new \Campanella\Controller\UpgradeController(
        $c->get(Installer::class),
        $c->get(\Campanella\Database\Migration\Migrator::class),
        $c->get(\Campanella\Database\Sync\SchemaSync::class),
        $c->get(\Campanella\Database\DatabaseBackup::class),
        $c->get(\Campanella\Admin\AdminAccess::class),
        $c->get(Csrf::class),
        $c->get(Throttle::class),
        $c->get(\Campanella\View\Presentation::class),
        $key,
    ));
    check(\Campanella\Controller\UpgradeController::usableKey('short') === null && \Campanella\Controller\UpgradeController::usableKey($key) === $key);
    $page = $send('GET', '/admin/upgrade');
    check(str_contains($page->body, 'name="key"') && !str_contains($page->body, 'test:0030_web'), 'the key field');
    $wrong = $send('POST', '/admin/upgrade', ['_csrf' => $csrfOf($page), 'key' => 'rossz-kulcs-rossz-kulcs']);
    check($wrong->status === 403 && str_contains($wrong->body, 'Hibás frissítési kulcs') && $ran === 0, 'wrong key');
    check($send('POST', '/admin/upgrade', ['key' => $key])->status === 400 && $ran === 0, 'without the CSRF token');
    foreach (range(1, 5) as $_) {
        $send('POST', '/admin/upgrade', ['_csrf' => $csrfOf($page), 'key' => 'rossz-kulcs-rossz-kulcs'], '10.9.9.9');
    }
    $blocked = $send('POST', '/admin/upgrade', ['_csrf' => $csrfOf($page), 'key' => $key], '10.9.9.9');
    check($blocked->status === 403 && str_contains($blocked->body, 'Túl sok') && $ran === 0, 'throttled, even with the right key');

    $done = $send('POST', '/admin/upgrade', ['_csrf' => $csrfOf($page), 'key' => $key]);
    check($done->status === 200 && $ran === 1 && str_contains($done->body, 'A frissítés kész: 1 migráció') && str_contains($done->body, "Web test\n    webes sor\n"), strip_tags($done->body));
    check(count(glob($dir . '/campanella-*.sql.gz') ?: []) === 1 && str_contains($done->body, 'var/backups/campanella-'), 'a backup first');
    check($send('GET', '/')->status === 200 && !$installer->needsUpgrade(), 'the site works again');
    check($send('POST', '/admin/upgrade', ['_csrf' => $csrfOf($page), 'key' => $key])->status === 200 && $ran === 1, 'nothing left to run');

    // A logged-in administrator needs no key; "without a backup".
    $repository = $container->get(ObjectRepository::class);
    $user = $repository->create('user', ['title' => 'Frissítő', 'email' => 'frissito@example.hu']);
    $user->as(Authenticatable::class)->setPassword('frissito-jelszo-1');
    $user->as(Authenticatable::class)->setRoles(['administrator']);
    $repository->save($user);
    $container->get(AuthService::class)->login(new Request('GET', '/'), $user);
    $registry->add($migration('test:0031_admin', function () use (&$ran): void {
        $ran++;
    }));
    $page = $send('GET', '/admin/upgrade');
    check($page->status === 200 && str_contains($page->body, 'test:0031_admin') && !str_contains($page->body, 'name="key"') && str_contains($page->body, 'name="no_backup"'), 'an administrator sees the details');
    $done = $send('POST', '/admin/upgrade', ['_csrf' => $csrfOf($page), 'no_backup' => '1']);
    check($done->status === 200 && $ran === 2 && count(glob($dir . '/*.gz') ?: []) === 1, 'run without a backup');

    // A failing migration: reported, the site keeps waiting.
    $registry->add($migration('test:0032_fails', function (): void {
        throw new \RuntimeException('hibás lépés');
    }));
    $page = $send('GET', '/admin/upgrade');
    $failed = $send('POST', '/admin/upgrade', ['_csrf' => $csrfOf($page)]);
    check($failed->status === 500 && str_contains($failed->body, 'test:0032_fails') && str_contains($failed->body, 'hibás lépés') && $send('GET', '/')->status === 503, strip_tags($failed->body));

    $dropSchema();
    putenv('CAMPANELLA_DB_PREFIX');
});

test('System page: the backup folder and the upgrade key', function (): void {
    $root = sys_get_temp_dir() . '/campanella-root-' . bin2hex(random_bytes(4));
    mkdir($root . '/var/cache', 0777, true);
    $config = new Config(['upgrade' => ['key' => 'short']]);
    $db = Connection::fromConfig(['prefix' => 'sch_'] + Config::load(dirname(__DIR__) . '/config')->get('database'));
    $check = new \Campanella\System\SystemCheck($config, $db, new Installer($db, new CapabilityRegistry([])), new \Campanella\System\TemplateCache($root . '/var/cache/twig', '0.0.6'), $root);
    $lines = [];
    foreach ($check->run() as $result) {
        $lines[$result->label] = $result;
    }
    check(($lines['var/backups'] ?? null)?->status === \Campanella\System\CheckStatus::Ok, 'var/ writable: fine');
    check(($lines['admin.system.upgrade_key'] ?? null)?->hint?->key === 'admin.system.upgrade_key_short');
});

echo "\nApplying the definitions\n";

test('SchemaSync: a capability added to a Blueprint, new fields, and what it does not guess', function () use ($schemaDb, $dropSchema): void {
    $dropSchema();
    $setup = static function (array $classes, array $blueprints) use ($schemaDb): array {
        $capabilities = new CapabilityRegistry($classes);
        $registry = new BlueprintRegistry($capabilities, $blueprints);
        $installer = new Installer($schemaDb, $capabilities);

        return [new \Campanella\Database\Sync\SchemaSync($schemaDb, $installer, $capabilities, $registry), new ObjectRepository($schemaDb, $capabilities, $registry), $installer];
    };
    $kinds = static fn (\Campanella\Database\Sync\SyncPlan $plan): array => array_map(static fn ($s): string => $s->kind->value . ':' . $s->message->key, $plan->steps);

    // v1: notes without the capability.
    [$sync, $repository, $installer] = $setup([Titled::class, RatedV1::class], ['note' => ['capabilities' => [Titled::class]]]);
    $installer->install();
    $ids = [];
    foreach (['Első', 'Második', 'Harmadik'] as $title) {
        $note = $repository->create('note', ['title' => $title]);
        $repository->save($note);
        $ids[] = (int) $note->id();
    }
    check(!$sync->plan()->hasWork() && $sync->plan()->steps === [], 'nothing to do');

    // The Blueprint gets the capability: the existing objects get it, with the defaults.
    [$sync, $repository] = $setup([Titled::class, RatedV1::class], ['note' => ['capabilities' => [Titled::class, RatedV1::class], 'defaults' => ['rating_note' => 'alap']]]);
    $plan = $sync->plan();
    check($kinds($plan) === ['add_capability:sync.add_capability'] && $plan->steps[0]->message->params['count'] === 3, implode(', ', $kinds($plan)));
    $sync->apply();
    $loaded = $repository->find($ids[0]);
    check($loaded !== null && $loaded->has(RatedV1::class) && $loaded->get('score') === 3 && $loaded->get('rating_note') === 'alap' && $loaded->get('rating_tags') === [], json_encode($loaded?->get('score')));
    check(!$sync->plan()->hasWork(), 'done once');

    // v2: new fields of the capability, required with a default, and an index.
    $loaded->set('rating_extra', 'adat');
    $loaded->set('rating_tags', ['a', 'b']);
    $repository->save($loaded);
    [$sync, $repository, $installer] = $setup([Titled::class, RatedV2::class], ['note' => ['capabilities' => [Titled::class, RatedV2::class]]]);
    $plan = $sync->plan();
    check($kinds($plan) === ['add_column:sync.add_column_default', 'add_column:sync.add_column_default', 'add_index:sync.add_index'], implode(', ', $kinds($plan)));
    $sync->apply();
    $loaded = $repository->find($ids[1]);
    check($loaded?->get('level') === 1 && $loaded->get('rating_comment') === 'nincs', 'the existing rows got the defaults');
    check($installer->differences() === [], 'the schema matches: ' . implode(', ', array_map(static fn ($d) => $d->kind->value . ' ' . $d->name, $installer->differences())));

    // v3: a required field without a default is not guessed; neither is a unique default, nor a required one.
    [$sync] = $setup([Titled::class, RatedV3::class, Coded::class, Handled::class], ['note' => ['capabilities' => [Titled::class, RatedV3::class, Coded::class, Handled::class]]]);
    $plan = $sync->plan();
    $sorted = $kinds($plan);
    sort($sorted);
    check($sorted === ['blocked:sync.blocked_column', 'blocked:sync.blocked_required', 'blocked:sync.blocked_unique', 'create_table:sync.create_table', 'create_table:sync.create_table'], implode(', ', $kinds($plan)));
    check(count($plan->blocked()) === 3 && $plan->hasWork(), 'blocked, but the new tables are work');
    $sync->apply();
    check(!$sync->plan()->hasWork() && count($sync->plan()->blocked()) === 3, 'the blocked ones stay');

    // A capability whose table cannot get its new column is not added to more objects.
    [$sync, $repository] = $setup([Titled::class, RatedV3::class], ['note' => ['capabilities' => [Titled::class, RatedV3::class]], 'card' => ['capabilities' => [Titled::class, RatedV3::class]]]);
    $schemaDb->insert('objects', ['uuid' => '00000000-0000-4000-8000-0000000000c1', 'blueprint' => 'card', 'data' => '{}', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
    $sorted = $kinds($sync->plan());
    sort($sorted);
    check(in_array('blocked:sync.blocked_table', $sorted, true) && in_array('blocked:sync.blocked_column', $sorted, true), implode(', ', $sorted));
    $schemaDb->execute("DELETE FROM {objects} WHERE blueprint = 'card'");

    // Removed from the Blueprint: kept (a note); with prune: the data is deleted.
    [$sync, $repository] = $setup([Titled::class, RatedV2::class], ['note' => ['capabilities' => [Titled::class]]]);
    $plan = $sync->plan();
    check($kinds($plan) === ['note:sync.kept'] && !$plan->hasWork(), implode(', ', $kinds($plan)));
    check($repository->find($ids[0])?->has(RatedV2::class) === true, 'kept: the objects still have it');
    $pruned = $sync->apply(prune: true);
    check($kinds($pruned) === ['prune_capability:sync.prune'] && $pruned->hasPrune());
    $after = $repository->find($ids[0]);
    check($after !== null && !$after->has(RatedV2::class), 'gone');
    check((int) $schemaDb->fetchValue('SELECT COUNT(*) FROM {cap_rated}') === 0 && (int) $schemaDb->fetchValue("SELECT COUNT(*) FROM {field_values} WHERE field = 'rating_tags'") === 0, 'its rows and values');
    check(!str_contains((string) $schemaDb->fetchValue('SELECT data FROM {objects} WHERE id = :id', ['id' => $ids[0]]), 'rating_extra'), 'its JSON data');
    check($after->get('title') === 'Első', 'the rest is untouched');

    // Objects of a Blueprint no longer defined: a note.
    [$sync] = $setup([Titled::class], ['page' => ['capabilities' => [Titled::class]]]);
    check($kinds($sync->plan()) === ['note:sync.unknown_blueprint'], implode(', ', $kinds($sync->plan())));
    $dropSchema();
});

test('migrate: the additive changes without asking or a backup; blocked ones fail the run', function () use ($schemaDb, $dropSchema): void {
    $dropSchema();
    $capabilities = new CapabilityRegistry([Titled::class, RatedV1::class]);
    $installer = new Installer($schemaDb, $capabilities);
    $installer->install();
    $repository = new ObjectRepository($schemaDb, $capabilities, new BlueprintRegistry($capabilities, ['note' => ['capabilities' => [Titled::class]]]));
    $repository->save($repository->create('note', ['title' => 'Egy']));

    $dir = sys_get_temp_dir() . '/campanella-backup-' . bin2hex(random_bytes(4));
    $translator = Translator::fromDirectory(dirname(__DIR__) . '/lang', 'hu');
    $run = static function (array $classes, array $blueprints, array $args) use ($schemaDb, $dir, $translator): array {
        $capabilities = new CapabilityRegistry($classes);
        $sync = new \Campanella\Database\Sync\SchemaSync($schemaDb, new Installer($schemaDb, $capabilities), $capabilities, new BlueprintRegistry($capabilities, $blueprints));
        $stream = fopen('php://memory', 'w+');
        $runner = new \Campanella\Cli\MigrationRunner(
            new \Campanella\Database\Migration\Migrator($schemaDb, new \Campanella\Database\Migration\MigrationRegistry()),
            new \Campanella\Database\DatabaseBackup($schemaDb, $dir),
            $translator,
            new \Campanella\Cli\Input(fopen('php://memory', 'r')),
            new \Campanella\Cli\Output($stream, $stream),
            $sync,
        );
        $code = $runner->run(\Campanella\Cli\Args::parse($args));
        rewind($stream);

        return [$code, (string) stream_get_contents($stream)];
    };
    $v2 = ['note' => ['capabilities' => [Titled::class, RatedV2::class]]];
    [$code, $out] = $run([Titled::class, RatedV2::class], $v2, ['--dry-run']);
    check($code === 0 && str_contains($out, 'Alkalmazandó definícióváltozások') && str_contains($out, 'megkapja a(z) rated capability-t'), $out);
    check(!(new \Campanella\Database\Schema\SchemaReader($schemaDb))->columnExists('cap_rated', 'level'), 'dry run: nothing changed');
    [$code, $out] = $run([Titled::class, RatedV2::class], $v2, []);
    check($code === 0 && str_contains($out, '✔') && str_contains($out, 'Új oszlop: cap_rated.level') && !is_dir($dir), 'additive only: not asked, no backup: ' . $out);
    [$code, $out] = $run([Titled::class, RatedV2::class], $v2, []);
    check($code === 0 && str_contains($out, 'naprakész'), $out);
    [$code, $out] = $run([Titled::class, RatedV3::class], ['note' => ['capabilities' => [Titled::class, RatedV3::class]]], []);
    check($code === 1 && str_contains($out, 'cap_rated.secret_code oszlop nem adható hozzá'), $out);
    [$code, $out] = $run([Titled::class, RatedV2::class], ['note' => ['capabilities' => [Titled::class]]], ['--prune']);
    check($code === 1 && str_contains($out, '--yes'), 'pruning asks: ' . $out);
    [$code, $out] = $run([Titled::class, RatedV2::class], ['note' => ['capabilities' => [Titled::class]]], ['--prune', '--yes']);
    check($code === 0 && str_contains($out, 'elveszíti') && count(glob($dir . '/*.sql.gz') ?: []) === 1, 'pruned after a backup: ' . $out);
    $dropSchema();
});

echo "\nThe roles migration (0.0.6)\n";

test('RolesMultiValue: reading the stored lists', function (): void {
    $d = \Campanella\Database\Migration\Core\RolesMultiValue::decode(...);
    check($d('["administrator","editor"]') === ['administrator', 'editor'] && $d('editor, administrator,editor') === ['editor', 'administrator']);
    check($d(null) === [] && $d('') === [] && $d('[]') === [] && $d('[" editor ", "", "editor"]') === ['editor']);
});

test('An unfinished 0.0.6 upgrade: the roles move to field_values, the old column goes', function () use ($dropSchema, $capabilities): void {
    putenv('CAMPANELLA_DB_PREFIX=sch_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    $db = $container->get(Connection::class);
    if ($db->prefix() !== 'sch_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dropSchema();
    $dir = sys_get_temp_dir() . '/campanella-backup-' . bin2hex(random_bytes(4));
    $container->set(\Campanella\Database\DatabaseBackup::class, static fn (\Campanella\Core\Container $c) => new \Campanella\Database\DatabaseBackup($c->get(Connection::class), $dir));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));

    // 0.0.6 uploaded, its upgrade not run yet: the tables (no migrations recorded), the roles as JSON in a column.
    (new Installer($db, $container->get(CapabilityRegistry::class)))->install();
    $db->execute('ALTER TABLE ' . $db->table('cap_authenticatable') . ' ADD COLUMN `roles` MEDIUMTEXT NULL AFTER `account_status`');
    $repository = $container->get(ObjectRepository::class);
    $users = [];
    foreach (['admin@regi.hu' => '["administrator"]', 'szerk@regi.hu' => '["editor"]', 'mindketto@regi.hu' => 'editor, administrator', 'tag@regi.hu' => null] as $email => $roles) {
        $user = $repository->create('user', ['title' => $email, 'email' => $email]);
        $user->as(Authenticatable::class)->setPassword('regi-jelszo-123');
        $repository->save($user);
        $db->update('cap_authenticatable', ['roles' => $roles], ['object_id' => (int) $user->id()]);
        $users[$email] = $user;
    }
    $installer = $container->get(Installer::class);
    check(array_map(static fn ($m): string => $m->id(), $installer->pendingMigrations()) === ['core:0006_roles_multi_value', 'core:0008_media_usage'] && $installer->needsUpgrade(), 'the roles migration is pending');
    $extra = array_map(static fn ($d): string => $d->kind->value . ':' . $d->table . '.' . $d->name, $installer->differences());
    check($extra === ['extra_column:cap_authenticatable.roles'], implode(', ', $extra));

    // The new code reads no roles yet: since 0.1.0 the old column is not read any more,
    // so the upgrade page asks for the key; the command line (or the key) does the upgrade.
    $auth = $container->get(AuthService::class);
    $auth->login(new Request('GET', '/'), $users['admin@regi.hu']);
    $storage->endRequest();
    check($auth->currentActor(new Request('GET', '/'))->roles === [], 'no roles before the migration');
    $storage->endRequest();
    check($kernel->handle(new Request('GET', '/admin'))->status === 503, 'the admin waits');
    $storage->endRequest();
    $page = $kernel->handle(new Request('GET', '/admin/upgrade'));
    check($page->status === 200 && str_contains($page->body, 'core:0006_roles_multi_value') === false, 'no details without the role');
    $installer->install();
    $results = $installer->migrator()?->run() ?? [];
    check(count($results) === 2, 'the roles migration ran (and the media usage one of 0.1.2)');

    check(!$installer->needsUpgrade() && $installer->differences() === [], 'matches a fresh installation');
    $roles = static fn (string $email) => $repository->find((int) $users[$email]->id())?->as(Authenticatable::class)->roles();
    check($roles('admin@regi.hu') === ['administrator'] && $roles('mindketto@regi.hu') === ['editor', 'administrator'] && $roles('tag@regi.hu') === [], json_encode($roles('mindketto@regi.hu')));
    $storage->endRequest();
    check($auth->currentActor(new Request('GET', '/'))->roles === ['administrator'], 'the roles are read again');

    // Users can be queried by role.
    $stream = fopen('php://memory', 'w+');
    (new \Campanella\Cli\UserListCommand())->run($container, ['--role=editor'], new \Campanella\Cli\Output($stream, $stream));
    rewind($stream);
    $out = (string) stream_get_contents($stream);
    check(str_contains($out, 'szerk@regi.hu') && str_contains($out, 'mindketto@regi.hu') && !str_contains($out, 'admin@regi.hu') && !str_contains($out, 'tag@regi.hu'), $out);

    // Repeatable: running it again changes nothing.
    (new \Campanella\Database\Migration\Core\RolesMultiValue())->up(new \Campanella\Database\Migration\MigrationContext($db));
    check($roles('mindketto@regi.hu') === ['editor', 'administrator']);

    $dropSchema();
    putenv('CAMPANELLA_DB_PREFIX');
});

test('An installation older than 0.0.6 is not upgraded: through 0.0.7 first', function () use ($dropSchema): void {
    putenv('CAMPANELLA_DB_PREFIX=sch_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    $db = $container->get(Connection::class);
    if ($db->prefix() !== 'sch_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dropSchema();
    $installer = $container->get(Installer::class);
    $installer->install();
    $db->execute("UPDATE {system} SET value = '5' WHERE name = 'schema_version'");
    check($installer->tooOld() === '5' && $installer->needsUpgrade());
    throws(\Campanella\Database\UnsupportedUpgradeException::class, fn () => $installer->install());
    check($installer->systemValue('schema_version') === '5', 'nothing changed');

    $stream = fopen('php://memory', 'w+');
    $code = (new \Campanella\Cli\MigrateCommand())->run($container, ['--yes'], new \Campanella\Cli\Output($stream, $stream));
    rewind($stream);
    $out = (string) stream_get_contents($stream);
    check($code === 1 && str_contains($out, '0.0.7'), $out);

    $page = $kernel->handle(new Request('GET', '/admin/upgrade'));
    check($page->status === 409 && str_contains($page->body, 'Campanella 0.0.7') && !str_contains($page->body, 'type="submit" class="btn btn-primary'), 'the upgrade page explains it');
    check($kernel->handle(new Request('GET', '/'))->status === 503, 'the site waits');

    $dropSchema();
    putenv('CAMPANELLA_DB_PREFIX');
});

test('setRoles: trimmed, without empty and repeated roles', function () use ($repository): void {
    $user = $repository->create('user', ['title' => 'x', 'email' => 'x@example.hu']);
    $user->as(Authenticatable::class)->setRoles([' editor', 'editor', '', 'administrator']);
    check($user->as(Authenticatable::class)->roles() === ['editor', 'administrator']);
});

echo "\nTrees (Hierarchical)\n";

test('Hierarchical: paths kept on save, moving a subtree, the rules of a tree', function () use ($db, $admin): void {
    $H = \Campanella\Capability\Hierarchical::class;
    $W = \Campanella\Capability\Weighted::class;
    $registry = new CapabilityRegistry([Titled::class, $H, $W]);
    $blueprints = new BlueprintRegistry($registry, ['node' => ['capabilities' => [Titled::class, $H, $W]], 'other' => ['capabilities' => [Titled::class, $H]]]);
    (new Installer($db, $registry))->install();
    $repository = new ObjectRepository($db, $registry, $blueprints);
    $engine = new QueryEngine($db, new QueryCompiler($registry, $blueprints), $repository, $registry, new DefaultPolicy());
    $make = static function (string $title, ?CampanellaObject $parent = null, int $weight = 0, string $blueprint = 'node') use ($repository, $H, $W): CampanellaObject {
        $node = $repository->create($blueprint, ['title' => $title]);
        $node->as($H)->setParent($parent);
        if ($node->has($W)) {
            $node->as($W)->setWeight($weight);
        }
        $repository->save($node);

        return $node;
    };
    $reload = static fn (CampanellaObject $o): CampanellaObject => $repository->find((int) $o->id()) ?? throw new LogicException();
    $path = static fn (CampanellaObject $o): string => $reload($o)->as($H)->path() . ' ' . $reload($o)->as($H)->depth();
    $id = static fn (CampanellaObject $o): int => (int) $o->id();
    $error = static function (Closure $f): string {
        try {
            $f();
        } catch (ValidationException $e) {
            return implode(',', array_map(static fn ($m) => $m instanceof Message ? $m->key : (string) $m, $e->errors));
        }

        return 'none';
    };

    $a = $make('A', null, 20);
    $b = $make('B', $a, 10);
    $c = $make('C', $b);
    $d = $make('D', null, 10);
    check($path($a) === "/{$id($a)}/ 0" && $path($b) === "/{$id($a)}/{$id($b)}/ 1" && $path($c) === "/{$id($a)}/{$id($b)}/{$id($c)}/ 2", $path($c));
    check($reload($c)->as($H)->ancestorIds() === [$id($a), $id($b)] && $reload($a)->as($H)->isRoot());

    // Moving B (with C) under D.
    $b = $reload($b);
    $b->as($H)->setParent($d);
    $repository->save($b);
    check($path($b) === "/{$id($d)}/{$id($b)}/ 1" && $path($c) === "/{$id($d)}/{$id($b)}/{$id($c)}/ 2", 'the subtree moved: ' . $path($c));
    check($path($a) === "/{$id($a)}/ 0", 'the rest is untouched');

    // The rules.
    $d = $reload($d);
    $d->as($H)->setParent($c);
    check($error(fn () => $repository->save($d)) === 'tree.circular', 'under its own descendant');
    $d = $reload($d);
    $d->as($H)->setParent($d);
    check($error(fn () => $repository->save($d)) === 'tree.circular', 'under itself');
    $alien = $make('Idegen', null, 0, 'other');
    $x = $repository->create('node', ['title' => 'X']);
    $x->as($H)->setParent($alien);
    check($error(fn () => $repository->save($x)) === 'tree.other_blueprint', 'another Blueprint');

    // At most 10 levels, also when a subtree is moved.
    $chain = [$make('L0')];
    for ($i = 1; $i < 10; $i++) {
        $chain[] = $make('L' . $i, $chain[$i - 1]);
    }
    check($reload($chain[9])->as($H)->depth() === 9, 'ten levels');
    $tooDeep = $repository->create('node', ['title' => 'L10']);
    $tooDeep->as($H)->setParent($chain[9]);
    check($error(fn () => $repository->save($tooDeep)) === 'tree.too_deep', 'the eleventh level');
    $b = $reload($b);
    $b->as($H)->setParent($chain[8]);
    check($error(fn () => $repository->save($b)) === 'tree.too_deep', 'a subtree that would not fit');
    $b->as($H)->setParent($chain[7]);
    $repository->save($b);
    check($reload($c)->as($H)->depth() === 9, 'it fits exactly');

    // Back to a root.
    $b = $reload($b);
    $b->as($H)->setParent(null);
    $repository->save($b);
    check($path($b) === "/{$id($b)}/ 0" && $path($c) === "/{$id($b)}/{$id($c)}/ 1");

    // A node with children cannot be deleted.
    check($error(fn () => $repository->delete($reload($b))) === 'tree.has_children', 'B has a child');
    $repository->delete($reload($c));
    $repository->delete($reload($b));
    check($repository->find($id($b)) === null, 'deleted once empty');

    // Queries and the tree builder.
    $e = $make('E', $a, 5);
    $f = $make('F', $a, 1);
    $g = $make('G', $f);
    $titles = static fn ($objects): array => array_map(static fn ($o) => $o->get('title'), is_array($objects) ? $objects : $objects->items);
    $node = static fn () => Query::objects()->blueprint('node');
    check($titles($engine->execute($H::roots($node())->where('title', 'IN', ['A', 'D'])->scope('by_weight'), $admin)) === ['D', 'A']);
    check($titles($engine->execute($H::childrenOf($node(), $a)->scope('by_weight'), $admin)) === ['F', 'E']);
    check($titles($engine->execute($H::descendantsOf($node(), $reload($a))->orderBy('title'), $admin)) === ['E', 'F', 'G']);
    check($titles($engine->execute($H::ancestorsOf($node(), $reload($g))->orderBy('depth'), $admin)) === ['A', 'F']);
    check($titles($engine->execute($H::ancestorsOf($node(), $reload($a)), $admin)) === [], 'a root has no ancestors');

    $all = $engine->execute($node()->where('title', 'IN', ['A', 'D', 'E', 'F', 'G'])->scope('by_weight'), $admin);
    $tree = \Campanella\Tree\TreeBuilder::build($all);
    $flat = array_map(static fn ($n) => str_repeat('-', $n->level) . $n->object->get('title'), \Campanella\Tree\TreeBuilder::flatten($tree));
    check($flat === ['D', 'A', '-F', '--G', '-E'], implode(' ', $flat));
    $sub = \Campanella\Tree\TreeBuilder::build($engine->execute($H::descendantsOf($node(), $reload($a))->scope('by_weight'), $admin));
    check(array_map(static fn ($n) => $n->object->get('title'), $sub) === ['F', 'E'] && $sub[0]->hasChildren() && !$sub[1]->hasChildren(), 'a subtree: its own roots');
    $strict = \Campanella\Tree\TreeBuilder::build($engine->execute($node()->where('title', 'IN', ['D', 'F', 'G', 'E'])->scope('by_weight'), $admin), false);
    check(array_map(static fn ($n) => $n->object->get('title'), \Campanella\Tree\TreeBuilder::flatten($strict)) === ['D'], 'without orphans: the branches under a missing parent are left out');

    $db->execute("DELETE FROM {objects} WHERE blueprint IN ('node', 'other')");
});

test('Kernel: trees in the admin: indented list, the parent offered without circles, up/down, delete', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $H = \Campanella\Capability\Hierarchical::class;
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('fa-admin@example.hu', 'fa-admin-jelszo-1', ['administrator']));
    $send = function (string $method, string $path, array $post = [], array $query = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, query: $query, post: $post));
    };
    $csrf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';
    $repository = $container->get(ObjectRepository::class);
    $db = $container->get(Connection::class);
    $make = static function (string $title, ?CampanellaObject $parent = null) use ($repository, $H): CampanellaObject {
        $category = $repository->create('category', ['title' => $title, 'path' => '/fa-' . bin2hex(random_bytes(3))]);
        $category->as($H)->setParent($parent);
        $repository->save($category);

        return $category;
    };
    $a = $make('Fa A');
    $b = $make('Fa B', $a);
    $c = $make('Fa C', $b);
    $d = $make('Fa D');
    $mine = array_map(static fn ($o): int => (int) $o->id(), [$a, $b, $c, $d]);
    $order = static function ($response) use ($mine): array {
        preg_match_all('/<tr id="row-(\d+)"/', $response->body, $m);

        return array_values(array_filter(array_map(intval(...), $m[1]), static fn (int $id): bool => in_array($id, $mine, true)));
    };

    $list = $send('GET', '/admin/category');
    check($list->status === 200 && $order($list) === [(int) $a->id(), (int) $b->id(), (int) $c->id(), (int) $d->id()], 'tree order: ' . implode(',', $order($list)));
    check(str_contains($list->body, 'padding-left: 2rem') && str_contains($list->body, 'padding-left: 3.5rem') && str_contains($list->body, 'Fa szerinti sorrend'), 'indented');
    check(str_contains($list->body, '/admin/category/new?parent=' . $a->id()) && str_contains($list->body, '/move-up'), 'add a child, order buttons');
    $flat = $send('GET', '/admin/category', [], ['q' => 'Fa']);
    check(!str_contains($flat->body, 'Fa szerinti sorrend') && !str_contains($flat->body, 'padding-left'), 'searching: flat');

    // Moving D before A (both roots); C stays where it is (the only child of B).
    $moved = $send('POST', '/admin/category/' . $d->id() . '/move-up', ['_csrf' => $csrf($list)]);
    check($moved->status === 303 && str_ends_with($moved->headers['Location'] ?? '', '#row-' . $d->id()));
    check($order($send('GET', '/admin/category')) === [(int) $d->id(), (int) $a->id(), (int) $b->id(), (int) $c->id()], 'D before A');
    check($repository->find((int) $d->id())?->get('weight') < $repository->find((int) $a->id())?->get('weight'), 'renumbered');
    $send('POST', '/admin/category/' . $c->id() . '/move-up', ['_csrf' => $csrf($list)]);
    check($order($send('GET', '/admin/category'))[3] === (int) $c->id(), 'the only child: unchanged');
    check($send('GET', '/admin/category/' . $d->id() . '/move-up')->status === 405 && $send('POST', '/admin/article/1/move-up')->status !== 303, 'POST only, Weighted only');

    // The form: the parent preselected; the object itself and its descendants are not offered.
    $new = $send('GET', '/admin/category/new', [], ['parent' => (string) $a->id()]);
    check(str_contains($new->body, '<option value="' . $a->id() . '" selected>Fa A</option>'), 'preselected parent');
    $form = $send('GET', '/admin/category/' . $a->id());
    check(!str_contains($form->body, '>— Fa B</option>') && !str_contains($form->body, '>Fa A</option>') && str_contains($form->body, '>Fa D</option>'), 'no circle offered');
    $formB = $send('GET', '/admin/category/' . $b->id());
    check(str_contains($formB->body, '>Fa A</option>') && !str_contains($formB->body, 'Fa C</option>'), 'B: A offered, its own child not');

    // Deleting: refused while it has children; they are listed.
    $page = $send('GET', '/admin/category/' . $b->id() . '/delete');
    check(str_contains($page->body, 'Alatta lévő elemek') && str_contains($page->body, 'Fa C') && str_contains($page->body, 'disabled>'), 'children listed, button disabled');
    $refused = $send('POST', '/admin/category/' . $b->id() . '/delete', ['_csrf' => $csrf($page)]);
    check($refused->status === 409 && str_contains($refused->body, 'Nem törölhető, amíg elemek vannak alatta (1)') && $repository->find((int) $b->id()) !== null, 'refused: ' . $refused->status);

    foreach ([$c, $b, $a, $d] as $category) {
        $repository->delete($category);
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

test('TreeKeeper: missing paths are filled in from the parents (a capability added later)', function () use ($db): void {
    $keeper = new \Campanella\Tree\TreeKeeper($db);
    $repository = new ObjectRepository($db, new CapabilityRegistry([Titled::class, \Campanella\Capability\Hierarchical::class]), new BlueprintRegistry(new CapabilityRegistry([Titled::class, \Campanella\Capability\Hierarchical::class]), ['knot' => ['capabilities' => [Titled::class, \Campanella\Capability\Hierarchical::class]]]));
    $root = $repository->create('knot', ['title' => 'gyökér']);
    $repository->save($root);
    $child = $repository->create('knot', ['title' => 'gyerek']);
    $child->as(\Campanella\Capability\Hierarchical::class)->setParent($root);
    $repository->save($child);
    $db->execute("UPDATE {cap_hierarchical} SET tree_path = NULL WHERE object_id IN (:a, :b)", ['a' => (int) $root->id(), 'b' => (int) $child->id()]);
    check($keeper->repair() >= 2);
    $row = $db->fetchOne('SELECT tree_path, depth FROM {cap_hierarchical} WHERE object_id = :id', ['id' => (int) $child->id()]);
    check($row !== null && $row['tree_path'] === '/' . $root->id() . '/' . $child->id() . '/' && (int) $row['depth'] === 1, json_encode($row));
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'knot' AND id = :id", ['id' => (int) $child->id()]);
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'knot'");
});

test('Kernel: the category tree on the site: /kategoriak, breadcrumbs, subcategories, their articles', function () use ($service, $admin): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $H = \Campanella\Capability\Hierarchical::class;
    $repository = $container->get(ObjectRepository::class);
    $service = $container->get(\Campanella\Service\ObjectService::class);
    $category = static function (string $title, string $path, ?CampanellaObject $parent, bool $publish = true) use ($service, $admin, $H): CampanellaObject {
        return $service->create($admin, 'category', ['title' => $title, 'path' => $path], $publish, $parent === null ? [] : ['parent' => [(int) $parent->id()]]);
    };
    $root = $category('Fa Gyökér', '/fa-gyoker', null);
    $sub = $category('Fa Al', '/fa-al', $root);
    $leaf = $category('Fa Levél', '/fa-level', $sub);
    $draft = $category('Fa Piszkozat', '/fa-piszkozat', $root, false);
    $underDraft = $category('Fa Rejtett Ág', '/fa-rejtett-ag', $draft);
    $article = $service->create($admin, 'article', ['title' => 'Mély cikk', 'path' => '/fa-mely-cikk'], true, ['categories' => [(int) $leaf->id()]]);
    $other = $service->create($admin, 'article', ['title' => 'Máshol lévő cikk', 'path' => '/fa-mashol'], true);

    $page = $kernel->handle(new Request('GET', '/fa-gyoker'));
    check($page->status === 200 && str_contains($page->body, 'Mély cikk') && !str_contains($page->body, 'Máshol lévő cikk'), 'the root lists the articles of its subtree');
    check(str_contains($page->body, 'Alkategóriák') && str_contains($page->body, 'href="/fa-al"') && !str_contains($page->body, 'Fa Piszkozat'), 'its visible children');
    check(!str_contains($page->body, 'class="breadcrumb'), 'a root has no breadcrumbs');
    $leafPage = $kernel->handle(new Request('GET', '/fa-level'));
    check(preg_match('#<ol class="breadcrumb small">.*href="/".*href="/fa-gyoker">Fa Gyökér</a>.*href="/fa-al">Fa Al</a>.*aria-current="page">Fa Levél<#s', $leafPage->body) === 1, 'breadcrumbs: home, root, parent, itself');
    check(str_contains($kernel->handle(new Request('GET', '/fa-al'))->body, 'Mély cikk'), 'the parent too');

    $list = $kernel->handle(new Request('GET', '/kategoriak'));
    check($list->status === 200 && preg_match('#Fa Gyökér</a>\s*<ul class="category-tree">\s*<li class="category-tree__item">\s*<a href="/fa-al">Fa Al</a>\s*<ul class="category-tree">.*Fa Levél#s', $list->body) === 1, 'nested lists');
    check(!str_contains($list->body, 'Fa Piszkozat'), 'drafts are not shown');
    check(!str_contains($list->body, 'Fa Rejtett Ág'), 'nor the branch under a draft');

    foreach ([$article, $other, $leaf, $underDraft, $draft, $sub, $root] as $object) {
        $repository->delete($object);
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

test('RelatedTo: a subtree path must be a tree path', function (): void {
    throws(\InvalidArgumentException::class, fn () => new \Campanella\Query\Condition\RelatedTo('categories', [], false, "/1/' OR 1=1 --"));
    throws(\InvalidArgumentException::class, fn () => new \Campanella\Query\Condition\RelatedTo('categories', [], false, '/1/%'));
    check((new \Campanella\Query\Condition\RelatedTo('categories', [], false, '/1/22/'))->subtree === '/1/22/');
});

test('Link: only safe URLs; either a target or a URL', function () use ($repository, $service, $admin): void {
    $L = \Campanella\Capability\Link::class;
    foreach (['/', '/hirek', '/hirek?oldal=2#lista', '#kapcsolat', 'https://example.hu', 'http://example.hu/a?b=c', 'mailto:info@example.hu', 'tel:+36-1-234-5678', '/kategóriák'] as $good) {
        check($L::isSafeUrl($good), "safe: {$good}");
    }
    foreach (['javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,x', '//evil.example', '/\\evil', 'https://', 'http:///x', 'hirek', ' /hirek', "/hi\nrek", '/hi rek', 'vbscript:x', 'mailto:', 'tel:abc', 'ftp://example.hu'] as $bad) {
        check(!$L::isSafeUrl($bad), "unsafe: {$bad}");
    }
    check($L::isLocal('/a') && $L::isLocal('#a') && !$L::isLocal('//a') && !$L::isLocal('https://a'));

    $menu = $service->create($admin, 'menu', ['title' => 'Teszt menü', 'machine_name' => 'teszt_link']);
    $item = static fn (array $values, array $relations = []) => $repository->create('menu_item', ['title' => 'Pont'] + $values);
    $errorOf = static function ($object, array $relations) use ($repository): ?string {
        foreach ($relations as $name => $ids) {
            $object->setRelated($name, $ids);
        }
        try {
            $repository->save($object);
        } catch (\Campanella\Model\ValidationException $e) {
            return (string) (($e->errors['url'] ?? null)?->key ?? array_key_first($e->errors));
        }

        return null;
    };
    $page = $service->create($admin, 'page', ['title' => 'Link cél', 'path' => '/link-cel']);
    check($errorOf($item([]), ['menu' => [(int) $menu->id()]]) === 'link.missing');
    check($errorOf($item(['url' => '/x']), ['menu' => [(int) $menu->id()], 'target' => [(int) $page->id()]]) === 'link.both');
    check($errorOf($item(['url' => 'javascript:alert(1)']), ['menu' => [(int) $menu->id()]]) === 'link.invalid_url');
    check($errorOf($item(['url' => '/x']), []) === 'menu', 'the menu is required');
    $ok = $item(['url' => '  /hirek  ']);
    check($errorOf($ok, ['menu' => [(int) $menu->id()]]) === null && $ok->get('url') === '/hirek', 'trimmed');
    check($ok->as($L)->href() === '/hirek');
    $toPage = $item([]);
    check($errorOf($toPage, ['menu' => [(int) $menu->id()], 'target' => [(int) $page->id()]]) === null);
    check($toPage->as($L)->href(null) === null && $toPage->as($L)->href($page) === '/link-cel', 'a target link needs its loaded target');

    $repository->delete($ok);
    $repository->delete($toPage);
    $repository->delete($page);
    $repository->delete($menu);
});

test('Keyed: a unique machine name in a fixed format', function () use ($service, $admin, $repository): void {
    $menu = $service->create($admin, 'menu', ['title' => 'Lábléc', 'machine_name' => '  Footer_1 ']);
    check($menu->get('machine_name') === 'footer_1', 'trimmed and lowercased');
    foreach (['1menu', 'fő', 'a-b', 'a b'] as $bad) {
        try {
            $service->create($admin, 'menu', ['title' => 'Rossz', 'machine_name' => $bad]);
            check(false, "accepted: {$bad}");
        } catch (\Campanella\Model\ValidationException $e) {
            check(isset($e->errors['machine_name']), $bad);
        }
    }
    try {
        $service->create($admin, 'menu', ['title' => 'Másik', 'machine_name' => 'footer_1']);
        check(false, 'a duplicate was accepted');
    } catch (\Campanella\Model\ValidationException $e) {
        check(true);
    }
    $repository->delete($menu);
});

test('BlueprintRegistry: tree_scope must be a required single relation of a Hierarchical Blueprint', function () use ($capabilities): void {
    $H = \Campanella\Capability\Hierarchical::class;
    $rel = static fn (bool $required = true, $card = \Campanella\Relation\Cardinality::One) => new \Campanella\Relation\Relation('group_of', $card, required: $required);
    throws(\Campanella\Capability\CapabilityException::class, fn () => new BlueprintRegistry($capabilities, ['x' => ['capabilities' => [Titled::class], 'relations' => [$rel()], 'tree_scope' => 'group_of']]));
    throws(\Campanella\Capability\CapabilityException::class, fn () => new BlueprintRegistry($capabilities, ['x' => ['capabilities' => [Titled::class, $H], 'relations' => [$rel(false)], 'tree_scope' => 'group_of']]));
    throws(\Campanella\Capability\CapabilityException::class, fn () => new BlueprintRegistry($capabilities, ['x' => ['capabilities' => [Titled::class, $H], 'relations' => [$rel(true, \Campanella\Relation\Cardinality::Many)], 'tree_scope' => 'group_of']]));
    throws(\Campanella\Capability\CapabilityException::class, fn () => new BlueprintRegistry($capabilities, ['x' => ['capabilities' => [Titled::class, $H], 'tree_scope' => 'parent']]));
    $ok = new BlueprintRegistry($capabilities, ['x' => ['capabilities' => [Titled::class, $H], 'relations' => [$rel()], 'tree_scope' => 'group_of']]);
    check($ok->get('x')->treeScope === 'group_of' && $ok->treeScopes() === ['group_of']);
});

test('Menu trees: a parent from the same menu, a moved item takes its subtree, siblings per menu, a menu with items is kept', function () use ($service, $admin, $repository, $db, $blueprints): void {
    $H = \Campanella\Capability\Hierarchical::class;
    $one = $service->create($admin, 'menu', ['title' => 'Egyik', 'machine_name' => 'fa_egyik']);
    $two = $service->create($admin, 'menu', ['title' => 'Másik', 'machine_name' => 'fa_masik']);
    $item = static fn (string $title, CampanellaObject $menu, ?CampanellaObject $parent = null, int $weight = 0) => $service->create($admin, 'menu_item', ['title' => $title, 'url' => '/' . $title, 'weight' => $weight], false, ['menu' => [(int) $menu->id()]] + ($parent === null ? [] : ['parent' => [(int) $parent->id()]]));
    $a = $item('a', $one);
    $a1 = $item('a1', $one, $a);
    $a11 = $item('a11', $one, $a1);
    $b = $item('b', $two);
    try {
        $item('rossz', $two, $a);
        check(false, 'a parent from another menu was accepted');
    } catch (\Campanella\Model\ValidationException $e) {
        check(($e->errors['parent'] ?? null)?->key === 'tree.other_scope');
    }

    // Moving "a1" (with "a11") under "b" in the other menu: the subtree follows.
    $a1->setRelated('menu', [(int) $two->id()]);
    $a1->as($H)->setParent($b);
    $repository->save($a1);
    $menuOf = static fn (CampanellaObject $o): int => (int) $db->fetchValue("SELECT target_id FROM {relationships} WHERE source_id = :id AND type = 'menu'", ['id' => (int) $o->id()]);
    check($menuOf($a11) === (int) $two->id(), 'the subtree moved to the other menu');
    check($repository->find((int) $a11->id())?->as($H)->path() === '/' . $b->id() . '/' . $a1->id() . '/' . $a11->id() . '/');

    // The top items of a menu are siblings among themselves only.
    $c = $item('c', $one, null, 10);
    $order = new \Campanella\Tree\SiblingOrder($db, $blueprints);
    check($order->siblings($a) === [(int) $a->id(), (int) $c->id()], json_encode($order->siblings($a)));
    check($order->siblings($b) === [(int) $b->id()]);

    try {
        $repository->delete($one);
        check(false, 'a menu with items was deleted');
    } catch (\Campanella\Model\ValidationException $e) {
        check(($e->errors['children'] ?? null)?->key === 'tree.scope_in_use');
    }
    foreach ([$a11, $a1, $b, $c, $a, $one, $two] as $object) {
        $repository->delete($object);
    }
});

test('MenuBuilder: the visitor\'s items as a tree, hidden targets left out with their subtree, the current item marked', function () use ($service, $admin, $anon, $repository, $engine, $loader): void {
    $menus = new \Campanella\Menu\MenuBuilder($engine, $loader);
    check($menus->build('nincs_ilyen', $anon) === null, 'no such menu');
    $menu = $service->create($admin, 'menu', ['title' => 'Épít', 'machine_name' => 'epit']);
    check($menus->build('epit', $anon) === [], 'an empty menu');
    $page = $service->create($admin, 'page', ['title' => 'Látható', 'path' => '/epit-lathato'], true);
    $draft = $service->create($admin, 'page', ['title' => 'Rejtett', 'path' => '/epit-rejtett']);
    $item = static fn (string $title, int $weight, array $values, ?CampanellaObject $parent = null, ?CampanellaObject $target = null) => $service->create($admin, 'menu_item', ['title' => $title, 'weight' => $weight] + $values, false, ['menu' => [(int) $menu->id()]] + ($parent === null ? [] : ['parent' => [(int) $parent->id()]]) + ($target === null ? [] : ['target' => [(int) $target->id()]]));
    $home = $item('Kezdő', 0, ['url' => '/']);
    $parent = $item('Szülő', 10, ['url' => '#']);
    $child = $item('Gyerek', 0, [], $parent, $page);
    $deep = $item('Mély', 0, ['url' => '/mely'], $child);
    $hidden = $item('Rejtett pont', 20, [], null, $draft);
    $under = $item('Alatta', 0, ['url' => '/alatta'], $hidden);
    $external = $item('Külső', 30, ['url' => 'https://example.hu']);

    $built = $menus->build('epit', $anon, '/epit-lathato', 2, '/alkonyvtar');
    check(array_map(static fn ($e) => $e->title, $built) === ['Kezdő', 'Szülő', 'Külső'], json_encode(array_map(static fn ($e) => $e->title, $built)));
    check($built[0]->href === '/alkonyvtar/' && !$built[0]->current, 'the base path; home is not current');
    check($built[1]->href === '#' && $built[1]->active && !$built[1]->current, 'the parent of the current item is active');
    check(count($built[1]->children) === 1 && $built[1]->children[0]->current && $built[1]->children[0]->href === '/alkonyvtar/epit-lathato', 'a target item: its path, current');
    check($built[1]->children[0]->children === [], 'two levels only');
    check($built[2]->external && $built[2]->href === 'https://example.hu');
    $editor = $menus->build('epit', $admin, '/', 3);
    check(count($editor) === 4 && count($editor[1]->children[0]->children) === 1, 'the editor sees the draft\'s item and a third level');
    check($editor[0]->current, 'the home page is current on /');

    foreach ([$deep, $child, $under, $hidden, $home, $parent, $external, $menu, $page, $draft] as $object) {
        $repository->delete($object);
    }
});

test('Kernel: the main menu in the layout (with the built-in links until it exists), and in the admin', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $service = $container->get(\Campanella\Service\ObjectService::class);
    $repository = $container->get(ObjectRepository::class);
    $system = Actor::system();
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));

    $fallback = $kernel->handle(new Request('GET', '/hirek'));
    check(str_contains($fallback->body, 'href="/rolunk"') && str_contains($fallback->body, 'href="/kategoriak"'), 'the built-in links');

    $main = $service->create($system, 'menu', ['title' => 'Főmenü teszt', 'machine_name' => 'main']);
    $other = $service->create($system, 'menu', ['title' => 'Lábléc teszt', 'machine_name' => 'labl']);
    $make = static fn (string $title, int $weight, string $url, CampanellaObject $menu, ?CampanellaObject $parent = null) => $service->create($system, 'menu_item', ['title' => $title, 'url' => $url, 'weight' => $weight], false, ['menu' => [(int) $menu->id()]] + ($parent === null ? [] : ['parent' => [(int) $parent->id()]]));
    $news = $make('Friss hírek', 0, '/hirek', $main);
    $more = $make('Továbbiak', 10, '/kategoriak', $main);
    $sub = $make('Al-menüpont <b>', 0, '/al-pont', $main, $more);
    $foot = $make('Lábléc pont', 0, '/labl', $other);

    $page = $kernel->handle(new Request('GET', '/hirek'));
    check(!str_contains($page->body, 'href="/rolunk"'), 'the built-in links are gone');
    check(preg_match('#<a class="nav-link active" href="/hirek" aria-current="page">Friss hírek</a>#', $page->body) === 1, 'the current item');
    check(preg_match('#nav-link dropdown-toggle" href="\#" role="button"\s+data-bs-toggle="dropdown" aria-expanded="false">Továbbiak</a>.*href="/kategoriak">Továbbiak</a>.*href="/al-pont">Al-menüpont &lt;b&gt;</a>#s', $page->body) === 1, 'a dropdown, escaped');
    check(!str_contains($page->body, 'Lábléc pont'), 'only the main menu');

    // The admin: the menu's page lists its items; the item list can be filtered to one menu.
    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('menu-admin@example.hu', 'menu-admin-jelszo-1', ['administrator']));
    $send = function (string $method, string $path, array $post = [], array $query = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, query: $query, post: $post));
    };
    $edit = $send('GET', '/admin/menu/' . $main->id());
    check($edit->status === 200 && str_contains($edit->body, 'A hozzá tartozó elemek (menüpont)'), 'the items section');
    check(preg_match('#href="/admin/menu_item/' . $more->id() . '">Továbbiak</a>.*padding-left: 2.5rem.*href="/admin/menu_item/' . $sub->id() . '"#s', $edit->body) === 1, 'as a tree');
    check(str_contains($edit->body, 'href="/admin/menu_item/new?menu=' . $main->id() . '"') && str_contains($edit->body, 'href="/admin/menu_item?menu=' . $main->id() . '"'), 'new item and list links');
    check(!str_contains($edit->body, 'Lábléc pont'), 'not the other menu\'s items');

    $new = $send('GET', '/admin/menu_item/new', query: ['menu' => (string) $main->id()]);
    check(preg_match('#<option value="' . $main->id() . '" selected#', $new->body) === 1, 'the menu preselected');
    check(str_contains($new->body, '>Továbbiak</option>') && !str_contains($new->body, 'Lábléc pont</option>'), 'parents from the same menu');

    $all = $send('GET', '/admin/menu_item');
    check(preg_match('#scope="colgroup"[^>]*>Menü: Főmenü teszt</th>.*Friss hírek.*scope="colgroup"[^>]*>Menü: Lábléc teszt</th>.*Lábléc pont#s', $all->body) === 1, 'grouped by menu');
    $filtered = $send('GET', '/admin/menu_item', query: ['menu' => (string) $main->id()]);
    check(str_contains($filtered->body, 'Friss hírek') && !str_contains($filtered->body, 'Lábléc pont') && str_contains($filtered->body, '/move-down?menu=' . $main->id()), 'filtered to one menu');
    check(str_contains($filtered->body, 'new?parent=' . $news->id() . '&amp;menu=' . $main->id()), 'a sub-item keeps the menu');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $filtered->body, $m);
    $moved = $send('POST', '/admin/menu_item/' . $news->id() . '/move-down', ['_csrf' => $m[1] ?? ''], ['menu' => (string) $main->id()]);
    check($moved->status === 303 && str_contains($moved->headers['Location'] ?? '', '/admin/menu_item?menu=' . $main->id() . '#row-'), 'back to the filtered list');
    $after = $kernel->handle(new Request('GET', '/'));
    check(strpos($after->body, '>Továbbiak</a>') < strpos($after->body, '>Friss hírek</a>'), 'the new order on the site');

    $delete = $send('GET', '/admin/menu/' . $main->id() . '/delete');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $delete->body, $m);
    check($send('POST', '/admin/menu/' . $main->id() . '/delete', ['_csrf' => $m[1] ?? ''])->status === 409, 'a menu with items is kept');

    foreach ([$sub, $more, $news, $foot, $main, $other] as $object) {
        $repository->delete($object);
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

test('TrustedProxies: proxy headers believed only from the listed proxies', function (): void {
    $T = \Campanella\Http\TrustedProxies::class;
    check($T::inRange('10.1.2.3', '10.0.0.0/8') && !$T::inRange('11.1.2.3', '10.0.0.0/8'));
    check($T::inRange('192.168.1.130', '192.168.1.128/25') && !$T::inRange('192.168.1.127', '192.168.1.128/25'));
    check($T::inRange('127.0.0.1', '127.0.0.1') && !$T::inRange('127.0.0.2', '127.0.0.1'));
    check($T::inRange('fd00::5', 'fd00::/8') && !$T::inRange('fe80::1', 'fd00::/8') && !$T::inRange('10.0.0.1', 'fd00::/8'));
    check(!$T::inRange('nem-ip', '10.0.0.0/8'));
    foreach ([['10.0.0.0/33'], ['nem-ip'], [42], ['10.0.0.0/x']] as $bad) {
        throws(\InvalidArgumentException::class, fn () => new $T($bad));
    }

    $request = static fn (string $remote, array $headers, bool $secure = false) => new Request('GET', '/', headers: $headers, ip: $remote, secure: $secure);
    $none = new $T();
    $spoofed = $none->apply($request('6.6.6.6', ['x-forwarded-for' => '1.1.1.1', 'x-forwarded-proto' => 'https']));
    check($spoofed->ip === '6.6.6.6' && !$spoofed->secure, 'no trusted proxy: the headers are ignored');

    $proxies = new $T(['10.0.0.0/8']);
    $via = $proxies->apply($request('10.0.0.2', ['x-forwarded-for' => '203.0.113.7, 10.0.0.9', 'x-forwarded-proto' => 'https']));
    check($via->ip === '203.0.113.7' && $via->secure, $via->ip);
    $forged = $proxies->apply($request('10.0.0.2', ['x-forwarded-for' => '1.1.1.1, 203.0.113.7']));
    check($forged->ip === '203.0.113.7', 'an address the visitor put in front is not believed: ' . $forged->ip);
    $direct = $proxies->apply($request('203.0.113.9', ['x-forwarded-for' => '1.1.1.1', 'x-forwarded-proto' => 'https']));
    check($direct->ip === '203.0.113.9' && !$direct->secure, 'not from the proxy: ignored');
    $broken = $proxies->apply($request('10.0.0.2', ['x-forwarded-for' => 'szemét, 203.0.113.7', 'x-forwarded-proto' => 'http'], true));
    check($broken->ip === '203.0.113.7' && !$broken->secure, 'the proxy says plain HTTP');
});

test('SecurityHeaders: a strict policy on the public site, the admin keeps its own, HSTS only on request', function (): void {
    $S = \Campanella\Http\SecurityHeaders::class;
    $plain = new Request('GET', '/');
    $secure = new Request('GET', '/', secure: true);
    $default = (new $S())->apply(new \Campanella\Http\Response('x'), $secure);
    $csp = $default->headers['Content-Security-Policy'] ?? '';
    check(str_contains($csp, "script-src 'self';") && str_contains($csp, "img-src 'self' data:;") && str_contains($csp, "object-src 'none'") && !str_contains($csp, 'unsafe'), $csp);
    check(($default->headers['Permissions-Policy'] ?? '') === $S::PERMISSIONS_POLICY && !isset($default->headers['Strict-Transport-Security']));
    check(str_contains((new $S(externalImages: true))->contentSecurityPolicy(), "img-src 'self' data: https:;"), 'external images allowed by the HTML filter');
    $own = (new $S())->apply((new \Campanella\Http\Response('x'))->withHeader('Content-Security-Policy', "default-src 'none'"), $plain);
    check($own->headers['Content-Security-Policy'] === "default-src 'none'", 'a page\'s own policy stays');
    $custom = new $S("default-src 'self' https://fonts.example");
    check($custom->isCustomPolicy() && $custom->contentSecurityPolicy() === "default-src 'self' https://fonts.example");
    throws(\InvalidArgumentException::class, fn () => new $S("default-src 'self'\r\nX-Evil: 1"));
    throws(\InvalidArgumentException::class, fn () => new $S('  '));
    $hsts = new $S(hsts: 31536000, hstsSubdomains: true);
    check(($hsts->apply(new \Campanella\Http\Response('x'), $secure)->headers['Strict-Transport-Security'] ?? '') === 'max-age=31536000; includeSubDomains');
    check(!isset($hsts->apply(new \Campanella\Http\Response('x'), $plain)->headers['Strict-Transport-Security']), 'never over plain HTTP');
});

test('Kernel: the security headers of public and admin pages', function (): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $home = $kernel->handle(new Request('GET', '/'));
    check(str_contains($home->headers['Content-Security-Policy'] ?? '', "script-src 'self';") && isset($home->headers['Permissions-Policy']), 'the public page');
    $missing = $kernel->handle(new Request('GET', '/nincs-ilyen-oldal'));
    check($missing->status === 404 && isset($missing->headers['Content-Security-Policy']), 'an error page too');
    $login = $kernel->handle(new Request('GET', '/admin'));
    check(isset($login->headers['Content-Security-Policy']), 'the admin redirect');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('SecurityCheck: the web root, proxy headers, the policy and HSTS', function (): void {
    $root = dirname(__DIR__);
    $run = static function (?string $documentRoot, array $proxies = [], array $headers = [], string $remote = '203.0.113.5', ?string $csp = null, int $hsts = 0) use ($root): array {
        $check = \Campanella\System\SecurityCheck::checks($root, new \Campanella\Http\TrustedProxies($proxies), new \Campanella\Http\SecurityHeaders($csp, false, $hsts), static fn (): array => ['document_root' => $documentRoot, 'remote_addr' => $remote]);
        $lines = [];
        foreach ($check(new Request('GET', '/', headers: $headers, ip: $remote)) as $line) {
            $lines[$line->label] = $line->status->name . ':' . $line->value;
        }

        return $lines;
    };
    check($run($root . '/public')['admin.system.document_root'] === 'Ok:public/');
    check($run($root)['admin.system.document_root'] === 'Warning:admin.system.document_root_project');
    check($run(null)['admin.system.document_root'] === 'Info:–');
    check($run($root . '/public')['admin.system.proxy'] === 'Info:admin.system.proxy_none', 'plain HTTP without proxy headers: the connecting address is shown');
    check($run($root . '/public', [], ['x-forwarded-proto' => 'https'])['admin.system.proxy'] === 'Warning:admin.system.proxy_untrusted');
    $hint = null;
    $check = \Campanella\System\SecurityCheck::checks($root, new \Campanella\Http\TrustedProxies(), new \Campanella\Http\SecurityHeaders(), static fn (): array => ['document_root' => null, 'remote_addr' => '172.18.0.4']);
    foreach ($check(new Request('GET', '/', headers: ['x-forwarded-for' => '1.2.3.4', 'x-forwarded-proto' => 'https', 'x-real-ip' => '1.2.3.4'], ip: '172.18.0.4')) as $line) {
        $hint = $line->label === 'admin.system.proxy' ? $line->hint : $hint;
    }
    check($hint !== null && $hint->params === ['address' => '172.18.0.4', 'headers' => 'x-forwarded-for, x-forwarded-proto, x-real-ip'], 'the proxy\'s address and the header names (not their values)');
    $secureCheck = \Campanella\System\SecurityCheck::checks($root, new \Campanella\Http\TrustedProxies(), new \Campanella\Http\SecurityHeaders(), static fn (): array => ['document_root' => null, 'remote_addr' => '1.2.3.4']);
    check(array_filter($secureCheck(new Request('GET', '/', secure: true)), static fn ($l): bool => $l->label === 'admin.system.proxy') === [], 'HTTPS without a proxy: nothing to say');
    check($run($root . '/public', ['10.0.0.0/8'], ['x-forwarded-proto' => 'https'], '10.0.0.3')['admin.system.proxy'] === 'Ok:admin.system.proxy_trusted');
    $lines = $run($root . '/public', csp: "default-src *", hsts: 600);
    check($lines['admin.system.csp'] === 'Warning:admin.system.csp_custom' && $lines['admin.system.hsts'] === 'Ok:600 s');
    check($run($root . '/public')['admin.system.hsts'] === 'Info:admin.system.off');
    $cli = \Campanella\System\SecurityCheck::checks($root, new \Campanella\Http\TrustedProxies(), new \Campanella\Http\SecurityHeaders(), static fn (): array => ['document_root' => null, 'remote_addr' => null]);
    check(count($cli(null)) === 2, 'on the command line: only the settings');
});

test('FileThrottle: attempts per key in a file, the window expires', function (): void {
    $file = sys_get_temp_dir() . '/campanella-throttle-' . bin2hex(random_bytes(4)) . '/t.json';
    $t = new \Campanella\Security\FileThrottle($file);
    check(!$t->tooManyAttempts('a', 2) && $t->hit('a', 60) === 1 && $t->hit('a', 60) === 2 && $t->tooManyAttempts('a', 2));
    check(!$t->tooManyAttempts('b', 2) && $t->availableIn('a') > 0 && $t->availableIn('b') === 0);
    check(!str_contains((string) file_get_contents($file), '"a"'), 'only hashes are stored');
    $t->clear('a');
    check(!$t->tooManyAttempts('a', 1));
    $t->hit('c', -1);
    check(!$t->tooManyAttempts('c', 1), 'an expired window is forgotten');
    @unlink($file);
    @rmdir(dirname($file));
});

test('Installing from the browser: only with the key, only while there is no user, then 404', function () use ($dropSchema): void {
    putenv('CAMPANELLA_DB_PREFIX=sch_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    $db = $container->get(Connection::class);
    if ($db->prefix() !== 'sch_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dropSchema();
    $root = dirname(__DIR__);
    @unlink($root . '/var/cache/install-throttle.json');
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $send = function (string $method, string $path, array $post = [], string $ip = '10.2.2.2') use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post, ip: $ip));
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';

    $home = $send('GET', '/');
    check($home->status === 503 && str_contains($home->body, '/install'), 'not installed: the site points to the installer');

    // No key configured: how to set one, no form.
    $page = $send('GET', '/install');
    check($page->status === 200 && str_contains($page->body, "'install' => ['key' => '") && !str_contains($page->body, 'name="key"'), 'no key: the instructions');
    check(str_contains($page->body, 'Követelmények') && str_contains((string) ($page->headers['Content-Security-Policy'] ?? ''), "script-src 'self'") && ($page->headers['X-Robots-Tag'] ?? '') === 'noindex, nofollow');

    // With a key (a fresh kernel reads the setting).
    $key = str_repeat('t', 24);
    $kernel = new \Campanella\Core\Kernel($root);
    $container = $kernel->container();
    $config = $container->get(\Campanella\Core\Config::class);
    $container->set(\Campanella\Core\Config::class, static fn () => new \Campanella\Core\Config(['install' => ['key' => $key]] + $config->all()));
    $container->set(Session::class, static fn () => new Session($storage));
    $send = function (string $method, string $path, array $post = [], string $ip = '10.2.2.2') use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post, ip: $ip));
    };
    check(\Campanella\Controller\InstallController::usableKey('rövid') === null);
    $page = $send('GET', '/install');
    check(str_contains($page->body, 'name="key"') && str_contains($page->body, 'name="password_again"'), 'the form');
    $form = ['name' => 'Első Admin', 'email' => 'elso@example.hu', 'password' => 'telepito-jelszo-1', 'password_again' => 'telepito-jelszo-1', 'seed' => '1'];

    check($send('POST', '/install', ['key' => $key] + $form)->status === 400, 'without the CSRF token');
    $wrong = $send('POST', '/install', ['_csrf' => $csrfOf($page), 'key' => 'rossz-kulcs-rossz-kulcs'] + $form);
    check($wrong->status === 403 && str_contains($wrong->body, 'Hibás telepítési kulcs') && !(new Installer($db, $container->get(CapabilityRegistry::class)))->isInstalled(), 'a wrong key installs nothing');
    for ($i = 0; $i < 5; $i++) {
        $send('POST', '/install', ['_csrf' => $csrfOf($page), 'key' => 'rossz-kulcs-rossz-kulcs'] + $form, '10.7.7.7');
    }
    check($send('POST', '/install', ['_csrf' => $csrfOf($page), 'key' => $key] + $form, '10.7.7.7')->status === 403, 'too many wrong keys: even the right one waits');
    $mismatch = $send('POST', '/install', ['_csrf' => $csrfOf($page), 'key' => $key, 'password_again' => 'masik-jelszo-123'] + $form);
    check($mismatch->status === 422 && str_contains($mismatch->body, 'A két jelszó eltér') && str_contains($mismatch->body, 'value="elso@example.hu"') && !str_contains($mismatch->body, 'telepito-jelszo-1'), 'checked before installing; the password is not shown again');
    check(!(new Installer($db, $container->get(CapabilityRegistry::class)))->isInstalled());

    $done = $send('POST', '/install', ['_csrf' => $csrfOf($page), 'key' => $key] + $form);
    check($done->status === 303 && ($done->headers['Location'] ?? '') === '/admin', (string) $done->status . ' ' . strip_tags(substr($done->body, 0, 400)));
    $admin = $send('GET', '/admin');
    check($admin->status === 200 && str_contains($admin->body, 'Első Admin') && str_contains($admin->body, 'install.key'), 'logged in as the administrator, reminded of the key');
    $user = $container->get(AuthService::class)->findUserByEmail('elso@example.hu');
    check($user !== null && $user->as(Authenticatable::class)->roles() === ['administrator'] && $user->as(Authenticatable::class)->verifyPassword('telepito-jelszo-1'));
    check($send('GET', '/')->status === 200 && str_contains($send('GET', '/')->body, 'Kezdőlap'), 'the sample content and the main menu');
    check($send('GET', '/install')->status === 404 && $send('POST', '/install', ['_csrf' => $csrfOf($page), 'key' => $key] + $form)->status === 404, 'closed once there is a user');

    @unlink($root . '/var/cache/install-throttle.json');
    $dropSchema();
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Installing from the browser: tables made in phpMyAdmin, but no user yet', function () use ($dropSchema): void {
    putenv('CAMPANELLA_DB_PREFIX=sch_');
    $root = dirname(__DIR__);
    $kernel = new \Campanella\Core\Kernel($root);
    $container = $kernel->container();
    $db = $container->get(Connection::class);
    if ($db->prefix() !== 'sch_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dropSchema();
    $container->get(Installer::class)->install();
    $key = str_repeat('u', 30);
    $config = $container->get(\Campanella\Core\Config::class);
    $container->set(\Campanella\Core\Config::class, static fn () => new \Campanella\Core\Config(['install' => ['key' => $key]] + $config->all()));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $page = $kernel->handle(new Request('GET', '/install'));
    check($page->status === 200 && str_contains($page->body, 'A táblák már léteznek'), 'open while there is no user');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $page->body, $m);
    $storage->endRequest();
    $done = $kernel->handle(new Request('POST', '/install', post: ['_csrf' => $m[1] ?? '', 'key' => $key, 'name' => 'Admin', 'email' => 'admin@example.hu', 'password' => 'masodik-jelszo-1', 'password_again' => 'masodik-jelszo-1'], headers: ['host' => 'uj-webhely.example:8080']));
    check($done->status === 303 && $container->get(Installer::class)->userCount() === 1, 'no sample content without the box');
    check($container->get(\Campanella\Settings\Settings::class)->get('site.url') === 'http://uj-webhely.example:8080', 'the site\'s address: where it was installed from');
    check($container->get(\Campanella\Query\QueryEngine::class)->count(Query::objects()->blueprint('article'), Actor::system()) === 0);
    $dropSchema();
    putenv('CAMPANELLA_DB_PREFIX');
});

test('UserService: users managed by administrators, the last active administrator kept', function () use ($repository, $engine, $policy, $db, $newUser): void {
    $users = new \Campanella\Service\UserService($repository, $engine, $policy, new \Campanella\Security\Throttle($db), ['administrator', 'editor']);
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'user'");
    $boss = $newUser('fonok@example.hu', 'fonok-jelszo-1', ['administrator']);
    $bossActor = AuthService::actorFor($boss);
    $editor = AuthService::actorFor($newUser('szerk2@example.hu', 'szerk-jelszo-1', ['editor']));
    check($users->canManage($bossActor) && !$users->canManage($editor) && !$users->canManage(Actor::anonymous()));
    throws(\Campanella\Access\AccessDeniedException::class, fn () => $users->create($editor, 'X', 'x@example.hu', 'jelszo-jelszo-1', []));

    try {
        $users->create($bossActor, 'Rossz', 'nem-email', 'rovid', ['tulajdonos']);
        check(false, 'accepted');
    } catch (\Campanella\Model\ValidationException $e) {
        check(isset($e->errors['roles'], $e->errors['password']), implode(',', array_keys($e->errors)));
    }
    $anna = $users->create($bossActor, ' Kovács Anna ', 'Anna@Example.hu', 'anna-jelszo-123', ['editor']);
    check($anna->get('title') === 'Kovács Anna' && $anna->get('email') === 'anna@example.hu' && $anna->as(Authenticatable::class)->roles() === ['editor']);
    try {
        $users->create($bossActor, 'Másik Anna', 'anna@example.hu', 'anna-jelszo-123', []);
        check(false, 'a duplicate e-mail address');
    } catch (\Campanella\Model\ValidationException $e) {
        check(isset($e->errors['email']));
    }

    // The only administrator can neither lose the role nor be blocked, nor block themselves.
    $error = static function (callable $f): ?string {
        try {
            $f();
        } catch (\Campanella\Model\ValidationException $e) {
            return implode(',', array_map(static fn ($m) => $m->key, $e->errors));
        }

        return null;
    };
    check($error(fn () => $users->update($bossActor, $boss, 'Főnök', 'fonok@example.hu', ['editor'], true)) === 'users.last_admin');
    check($error(fn () => $users->update($bossActor, $boss, 'Főnök', 'fonok@example.hu', ['administrator'], false)) === 'users.self_block');
    $users->update($bossActor, $anna, 'Kovács Anna', 'anna@example.hu', ['administrator', 'editor'], true);
    check($users->activeAdministrators() === 2);
    check($error(fn () => $users->update($bossActor, $boss, 'Főnök', 'fonok@example.hu', ['editor'], true)) === null, 'with another administrator, the role can go');
    $annaActor = AuthService::actorFor($repository->find((int) $anna->id()));
    check($error(fn () => $users->update($annaActor, $anna, 'Kovács Anna', 'anna@example.hu', ['editor'], true)) === 'users.last_admin', 'now Anna is the last one');
    check($error(fn () => $users->update($annaActor, $boss, 'Főnök', 'fonok@example.hu', ['editor'], false)) === null, 'a non-administrator can be blocked');
    check(!$repository->find((int) $boss->id())?->as(Authenticatable::class)->isActive());

    // Passwords.
    $users->setPassword($annaActor, $boss, 'uj-fonok-jelszo-1');
    check($repository->find((int) $boss->id())?->as(Authenticatable::class)->verifyPassword('uj-fonok-jelszo-1'));
    $fresh = $repository->find((int) $anna->id());
    check($error(fn () => $users->changeOwnPassword($fresh, 'rossz-jelszo-1', 'anna-uj-jelszo-1')) === 'users.wrong_password');
    $users->changeOwnPassword($fresh, 'anna-jelszo-123', 'anna-uj-jelszo-1');
    check($repository->find((int) $anna->id())?->as(Authenticatable::class)->verifyPassword('anna-uj-jelszo-1'));
    for ($i = 0; $i < 5; $i++) {
        $error(fn () => $users->changeOwnPassword($fresh, 'rossz-jelszo-' . $i, 'mindegy-jelszo-1'));
    }
    check($error(fn () => $users->changeOwnPassword($fresh, 'anna-uj-jelszo-1', 'mindegy-jelszo-1')) === 'users.too_many', 'limited');
    $db->execute("DELETE FROM {throttle}");
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'user'");
});

test('Kernel: users in the admin, the profile, and a changed password ends the other sessions', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'user'");
    $boss = $newUser('kezelo@example.hu', 'kezelo-jelszo-1', ['administrator']);
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $auth = $container->get(AuthService::class);
    $auth->login(new Request('GET', '/'), $boss);
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';

    $dashboard = $send('GET', '/admin');
    check(str_contains($dashboard->body, 'href="/admin/user">Felhasználó</a>') && str_contains($dashboard->body, 'href="/admin/user">Felhasználók</a>') && str_contains($dashboard->body, 'href="/admin/profile"'), 'links for an administrator');
    $list = $send('GET', '/admin/user');
    check($list->status === 200 && str_contains($list->body, 'kezelo@example.hu') && str_contains($list->body, 'Adminisztrátor') && str_contains($list->body, '>te<'), 'the list');

    $new = $send('GET', '/admin/user/new');
    check(str_contains($new->body, 'name="roles[]" value="editor"') && str_contains($new->body, 'name="password_again"') && !str_contains($new->body, 'set-password'), 'the new user form');
    $bad = $send('POST', '/admin/user/new', ['_csrf' => $csrfOf($new), 'name' => 'Béla', 'email' => 'bela@example.hu', 'roles' => ['editor'], 'password' => 'bela-jelszo-12', 'password_again' => 'masik-jelszo-12']);
    check($bad->status === 422 && str_contains($bad->body, 'A két jelszó eltér') && !str_contains($bad->body, 'bela-jelszo-12') && str_contains($bad->body, 'value="bela@example.hu"'), 'the passwords must match');
    $made = $send('POST', '/admin/user/new', ['_csrf' => $csrfOf($new), 'name' => 'Béla', 'email' => 'bela@example.hu', 'roles' => ['editor'], 'password' => 'bela-jelszo-12', 'password_again' => 'bela-jelszo-12']);
    check($made->status === 303);
    $bela = $auth->findUserByEmail('bela@example.hu');
    check($bela !== null && $bela->as(Authenticatable::class)->roles() === ['editor']);

    // Béla logs in elsewhere; then the administrator sets a new password for him: that session ends.
    $belaStorage = new ArraySessionStorage();
    $belaKernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $belaKernel->container()->set(Session::class, static fn () => new Session($belaStorage));
    $belaKernel->container()->get(AuthService::class)->login(new Request('GET', '/'), $bela);
    $belaStorage->endRequest();
    check($belaKernel->handle(new Request('GET', '/admin'))->status === 200, 'Béla is in');
    $edit = $send('GET', '/admin/user/' . $bela->id());
    check($edit->status === 200 && str_contains($edit->body, 'value="bela@example.hu"') && str_contains($edit->body, '/admin/user/' . $bela->id() . '/password'));
    $set = $send('POST', '/admin/user/' . $bela->id() . '/password', ['_csrf' => $csrfOf($edit), 'password' => 'bela-uj-jelszo-1', 'password_again' => 'bela-uj-jelszo-1']);
    check($set->status === 303);
    $belaStorage->endRequest();
    $belaKernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $belaKernel->container()->set(Session::class, static fn () => new Session($belaStorage));
    check($belaKernel->handle(new Request('GET', '/admin'))->status === 302, 'his old session ended');

    // The last administrator cannot demote themselves through the form.
    $self = $send('GET', '/admin/user/' . $boss->id());
    check(str_contains($self->body, 'id="user-status-blocked" name="status" value="blocked" disabled'), 'cannot block oneself');
    $demote = $send('POST', '/admin/user/' . $boss->id(), ['_csrf' => $csrfOf($self), 'name' => 'Kezelő', 'email' => 'kezelo@example.hu', 'roles' => ['editor'], 'status' => 'active']);
    check($demote->status === 422 && str_contains($demote->body, 'aktív adminisztrátor nélkül'));
    check($send('GET', '/admin/user/999999')->status === 404 && $send('GET', '/admin/user/' . $bela->id() . '/password')->status === 405);

    // The profile: one's own name and password; this session stays.
    $profile = $send('GET', '/admin/profile');
    check($profile->status === 200 && str_contains($profile->body, 'name="current_password"'));
    $wrong = $send('POST', '/admin/profile/password', ['_csrf' => $csrfOf($profile), 'current_password' => 'nem-ez-a-jelszo', 'password' => 'kezelo-uj-jelszo-1', 'password_again' => 'kezelo-uj-jelszo-1']);
    check($wrong->status === 422 && str_contains($wrong->body, 'Hibás a jelenlegi jelszó'));
    $changed = $send('POST', '/admin/profile/password', ['_csrf' => $csrfOf($profile), 'current_password' => 'kezelo-jelszo-1', 'password' => 'kezelo-uj-jelszo-1', 'password_again' => 'kezelo-uj-jelszo-1']);
    check($changed->status === 303);
    $after = $send('GET', '/admin/profile');
    check($after->status === 200 && str_contains($after->body, 'A jelszavad megváltozott'), 'still logged in');
    $named = $send('POST', '/admin/profile', ['_csrf' => $csrfOf($after), 'name' => 'Új Név']);
    check($named->status === 303 && $auth->findUserByEmail('kezelo@example.hu')?->get('title') === 'Új Név');

    // An editor: only the profile.
    $storage->endRequest();
    $editorStorage = new ArraySessionStorage();
    $editorKernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $editorKernel->container()->set(Session::class, static fn () => new Session($editorStorage));
    $editorKernel->container()->get(AuthService::class)->login(new Request('GET', '/'), $auth->findUserByEmail('bela@example.hu'));
    $editorStorage->endRequest();
    $editorDash = $editorKernel->handle(new Request('GET', '/admin'));
    check(!str_contains($editorDash->body, 'href="/admin/user"') && str_contains($editorDash->body, 'href="/admin/profile"'), 'an editor sees no user links');
    $editorStorage->endRequest();
    check($editorKernel->handle(new Request('GET', '/admin/user'))->status === 403);
    $editorStorage->endRequest();
    check($editorKernel->handle(new Request('GET', '/admin/profile'))->status === 200);

    $db->execute("DELETE FROM {throttle}");
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'user'");
    putenv('CAMPANELLA_DB_PREFIX');
});

test('StructurePages: the Blueprints and the capabilities, read-only, for administrators', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $structure = new \Campanella\Admin\StructurePages($container->get(BlueprintRegistry::class), $container->get(CapabilityRegistry::class), $container->get(\Campanella\Query\QueryEngine::class), $container->get(\Campanella\Admin\AdminAccess::class));
    $blueprints = array_column($structure->blueprints(), null, 'name');
    check(in_array('routable', $blueprints['article']['capabilities'], true) && in_array('titled', $blueprints['page']['capabilities'], true), 'the capabilities, with the dependencies');
    check(array_column($blueprints['article']['fields'], 'storage', 'name') === ['lead' => 'data'], 'the own fields');
    $relations = array_column($blueprints['menu_item']['relations'], null, 'name');
    check($relations['menu']['owner'] === null && $relations['menu']['required'] && $relations['parent']['owner'] === 'hierarchical' && $relations['target']['target_capabilities'] === ['routable']);
    check($blueprints['menu_item']['tree_scope'] === 'menu' && $blueprints['category']['lists'] === ['articles'] && $blueprints['user']['list_path'] === '/admin/user');
    $capabilities = array_column($structure->capabilities(), null, 'name');
    check($capabilities['routable']['requires'] === ['titled'] && in_array('article', array_column($capabilities['routable']['used_by'], 'name'), true));
    check($capabilities['link']['table'] === null && $capabilities['weighted']['scopes'] === ['by_weight'] && $capabilities['weighted']['table'] === 'cap_weighted');
    $roles = array_column($capabilities['authenticatable']['fields'], null, 'name')['roles'];
    check($roles['storage'] === 'values' && in_array('multiple', $roles['flags'], true));
    check(in_array('hidden', array_column($capabilities['authenticatable']['fields'], null, 'name')['password_hash']['flags'], true));

    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $boss = $newUser('szerkezet@example.hu', 'szerkezet-jelszo-1', ['administrator']);
    $container->get(AuthService::class)->login(new Request('GET', '/'), $boss);
    $storage->endRequest();
    $page = $kernel->handle(new Request('GET', '/admin/system/blueprints'));
    check($page->status === 200 && str_contains($page->body, 'id="bp-menu_item"') && str_contains($page->body, 'href="/admin/system/capabilities#cap-hierarchical"') && str_contains($page->body, 'Külön fák eszerint'), 'the Blueprints page');
    $storage->endRequest();
    $caps = $kernel->handle(new Request('GET', '/admin/system/capabilities'));
    check($caps->status === 200 && str_contains($caps->body, 'id="cap-link"') && str_contains($caps->body, 'Valahová mutat') && str_contains($caps->body, 'href="/admin/system/blueprints#bp-article"') && substr_count($caps->body, '<form') === 1, 'the capabilities page: no form but the logout');
    $storage->endRequest();
    check(str_contains($kernel->handle(new Request('GET', '/admin'))->body, 'href="/admin/system/capabilities"'), 'in the sidebar');

    $editorStorage = new ArraySessionStorage();
    $editorKernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $editorKernel->container()->set(Session::class, static fn () => new Session($editorStorage));
    $editorKernel->container()->get(AuthService::class)->login(new Request('GET', '/'), $newUser('szerkezet-szerk@example.hu', 'szerkezet-jelszo-1', ['editor']));
    $editorStorage->endRequest();
    check($editorKernel->handle(new Request('GET', '/admin/system/blueprints'))->status === 403, 'not for editors');

    $container->get(Connection::class)->execute("DELETE FROM {objects} WHERE blueprint = 'user'");
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Security review fixes: IPv4-mapped addresses, login counters, own password, hidden fields in templates, page overflow', function () use ($repository, $engine, $db, $newUser, $req, $policy): void {
    // IPv4-mapped IPv6 addresses are plain IPv4 (one shared /64 bucket for every visitor otherwise).
    check(Request::normalizeIp('::ffff:1.2.3.4') === '1.2.3.4' && Request::normalizeIp('::FFFF:10.0.0.1') === '10.0.0.1' && Request::normalizeIp('2001:db8::1') === '2001:db8::1' && Request::normalizeIp('nem-ip') === 'nem-ip');
    check(AuthService::clientKey('::ffff:1.2.3.4') === '1.2.3.4' && AuthService::clientKey('::ffff:9.9.9.9') === '9.9.9.9');
    check(\Campanella\Http\TrustedProxies::inRange('::ffff:127.0.0.1', '127.0.0.1') && (new Request('GET', '/'))->withClient('::ffff:5.6.7.8', false)->ip === '5.6.7.8');
    $via = (new \Campanella\Http\TrustedProxies(['127.0.0.1']))->apply(new Request('GET', '/', headers: ['x-forwarded-for' => '::ffff:203.0.113.9'], ip: '::ffff:127.0.0.1'));
    check($via->ip === '203.0.113.9', 'a mapped proxy address is trusted, a mapped client address read plainly');

    // A locked account and an unknown address answer alike; the address counter counts before the password.
    $db->execute('DELETE FROM {throttle}');
    $storage = new ArraySessionStorage();
    $session = new Session($storage, 7200);
    $auth = new AuthService($repository, $engine, $session, new Throttle($db), new Csrf($session), ['max_attempts' => 5, 'max_attempts_per_ip' => 100, 'max_attempts_per_account' => 3, 'decay_seconds' => 900]);
    $newUser('zarolt@example.hu', 'zarolt-jelszo-1');
    foreach (['zarolt@example.hu', 'nincs-ilyen@example.hu'] as $i => $email) {
        for ($n = 0; $n < 3; $n++) {
            $auth->attempt($req([], '10.50.' . $i . '.' . $n), $email, 'rossz-jelszo-1');
        }
    }
    $known = $auth->attempt($req([], '10.60.0.1'), 'zarolt@example.hu', 'rossz-jelszo-1');
    $unknown = $auth->attempt($req([], '10.60.0.1'), 'nincs-ilyen@example.hu', 'rossz-jelszo-1');
    check(!$known->success && $known->error === $unknown->error && $known->error === 'auth.too_many_attempts', $known->error . ' / ' . $unknown->error);
    $db->execute('DELETE FROM {throttle}');
    $ipLimited = new AuthService($repository, $engine, $session, new Throttle($db), new Csrf($session), ['max_attempts' => 5, 'max_attempts_per_ip' => 3, 'decay_seconds' => 900]);
    for ($n = 0; $n < 3; $n++) {
        $ipLimited->attempt($req([], '10.70.0.1'), 'valaki' . $n . '@example.hu', 'rossz-jelszo-1');
    }
    check($ipLimited->attempt($req([], '10.70.0.1'), 'zarolt@example.hu', 'zarolt-jelszo-1')->error === 'auth.too_many_attempts', 'the address limit holds before the password is checked');
    $db->execute('DELETE FROM {throttle}');

    // An administrator's own password: only on the profile, with the current one.
    $users = new \Campanella\Service\UserService($repository, $engine, $policy, new Throttle($db));
    $boss = $newUser('sajat@example.hu', 'sajat-jelszo-12', ['administrator']);
    throws(ValidationException::class, fn () => $users->setPassword(AuthService::actorFor($boss), $boss, 'uj-sajat-jelszo-1'));

    // Templates cannot read hidden fields through get(), values() or as().
    $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
        'ok' => '[{{ u.title }}|{{ u.password_hash }}|{{ u.email }}|{{ u.roles|join(",") }}|{{ u.id > 0 ? "id" }}]',
        'get' => '{{ u.get("password_hash") }}',
        'values' => '{{ u.values|json_encode }}',
        'as' => '{{ u.as("Campanella\\\\Capability\\\\Identifiable").email }}',
    ]), ['autoescape' => 'html']);
    $twig->addExtension(new \Twig\Extension\SandboxExtension(new \Campanella\View\TemplatePolicy(), true));
    $loaded = $repository->find((int) $boss->id());
    check($twig->render('ok', ['u' => $loaded]) === '[Teszt sajat@example.hu||||id]', $twig->render('ok', ['u' => $loaded]));
    foreach (['get', 'values', 'as'] as $template) {
        throws(\Twig\Sandbox\SecurityError::class, fn () => $twig->render($template, ['u' => $loaded]));
    }

    // An absurd page number is an empty page, not an error.
    $q = Query::objects()->page(PHP_INT_MAX, 10);
    check($q->getLimit() === 10 && $q->getOffset() > 0);
    check($engine->execute(Query::objects()->page(PHP_INT_MAX, 7), Actor::system())->isEmpty());

    $db->execute("DELETE FROM {objects} WHERE blueprint = 'user' AND id <> 0 AND id IN (SELECT object_id FROM {cap_identifiable} WHERE email IN ('zarolt@example.hu', 'sajat@example.hu'))");
});

test('Kernel: an absurd page number answers 404, not 500', function (): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    check($kernel->handle(new Request('GET', '/hirek', query: ['page' => '9223372036854775807']))->status === 404);
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Paths and URLs: no backslash or control character can turn a site path into another site', function () use ($service, $admin, $repository): void {
    $R = \Campanella\Capability\Routable::class;
    foreach (['/hirek', '/a/b-c', '/kategóriák'] as $good) {
        check($R::isSafePath($good), $good);
    }
    foreach (['/\\evil.com', "/\tevil", '/a b', '/a?b', '/a#b', "/a\x7f"] as $bad) {
        check(!$R::isSafePath($bad), json_encode($bad));
    }
    try {
        $service->create($admin, 'page', ['title' => 'Rossz út', 'path' => '/\\evil.com']);
        check(false, 'a backslash path was accepted');
    } catch (ValidationException $e) {
        check(($e->errors['path'] ?? null)?->key === 'validation.invalid_path');
    }
    $ext = new \Campanella\View\CampanellaTwigExtension(static fn () => throw new \LogicException(), static fn (): string => '/alap');
    check($ext->url('/\\evil.com') === '/alap/%5Cevil.com' && $ext->url("/a\tb") === '/alap/a%09b' && $ext->url('//x') === '/alap/x' && $ext->url('/hirek') === '/alap/hirek');
    check(!\Campanella\Capability\Link::isLocal('/\\evil') && \Campanella\Capability\Link::isLocal('/ok'));
});

test('Throttle: one atomic count per attempt; an expired window starts again', function () use ($db): void {
    $t = new Throttle($db);
    $t->clear('atom');
    check($t->hit('atom', 60) === 1 && $t->hit('atom', 60) === 2 && $t->tooManyAttempts('atom', 2));
    $t->clear('lejart');
    $t->hit('lejart', -5);
    check($t->hit('lejart', 60) === 1, 'an expired window starts at 1');
    $t->clear('atom');
    $t->clear('lejart');
});

echo "\nSite basics (0.1.1)\n";

/** An image object without a file (enough for meta tags and settings). */
$fakeImage = static function (string $title, int $width = 1200, int $height = 630) use ($repository) {
    $image = $repository->create('image', [
        'title' => $title,
        'alt' => $title . ' leírása',
        'file_path' => '2026/10/' . bin2hex(random_bytes(12)) . '.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1000,
        'width' => $width,
        'height' => $height,
        'file_hash' => hash('sha256', random_bytes(8)),
    ]);
    $repository->save($image);

    return $image;
};

test('Settings: saved values by name, removed with null; no table is no error', function () use ($db, $config): void {
    $S = \Campanella\Settings\Settings::class;
    $settings = new $S($db);
    $settings->set(['site.name' => 'Próba', 'site.slogan' => '', 'other.key' => 'x']);
    check($settings->isAvailable() && $settings->get('site.name') === 'Próba' && $settings->get('site.slogan') === '' && $settings->get('site.url') === null);
    check($settings->all('site.') === ['site.name' => 'Próba', 'site.slogan' => ''], json_encode($settings->all('site.')));
    $other = new $S($db);
    $other->set(['site.name' => null]);
    check($settings->get('site.name') === 'Próba', 'read once per instance');
    $settings->reset();
    check($settings->get('site.name') === null, 'removed: falls back again');
    foreach (['Site.name', 'site', 'site..x', 'site.név', "site.a\n"] as $bad) {
        throws(\InvalidArgumentException::class, fn () => $settings->set([$bad => 'x']));
    }
    $settings->set(['site.slogan' => null, 'other.key' => null]);
    $missing = new $S(Connection::fromConfig(['prefix' => 'nincs_'] + $config->get('database')));
    check($missing->get('site.name') === null && !$missing->isAvailable(), 'before the upgrade: the configuration file decides');
});

test('SiteSettings: the configuration file until saved, validation, addresses', function () use ($db, $repository, $fakeImage, $service, $admin): void {
    $T = \Campanella\Site\SiteSettings::class;
    $settings = new \Campanella\Settings\Settings($db);
    $settings->set(array_fill_keys(array_map(static fn (string $k): string => 'site.' . $k, $T::KEYS), null));
    $site = new $T($settings, ['name' => 'Konfig', 'slogan' => 'Szlogen', 'theme_color' => 'kék'], $repository);
    $values = $site->values();
    check($values['name'] === 'Konfig' && $values['slogan'] === 'Szlogen' && $values['description'] === '' && $values['url'] === ''
        && $values['share_image'] === null && $values['indexing'] === true && $values['theme_color'] === 'kék', json_encode($values));
    check($site->absolute('/hirek') === null, 'no address: no absolute URL');

    foreach ([
        'https://Example.HU/' => 'https://example.hu',
        'http://example.hu:8080/campanella/' => 'http://example.hu:8080/campanella',
        'https://[::1]:8443' => 'https://[::1]:8443',
        'https://example.hu:443/' => 'https://example.hu',
        'http://example.hu:80' => 'http://example.hu',
        'http://127.0.0.1:8096' => 'http://127.0.0.1:8096',
        'https://example.hu/a%20b' => 'https://example.hu/a%20b',
        '  ' => '',
    ] as $input => $expected) {
        check($T::normalizeUrl($input) === $expected, $input . ' → ' . var_export($T::normalizeUrl($input), true));
    }
    foreach (['example.hu', 'ftp://example.hu', 'https://user:pw@example.hu', 'https://example.hu/?a=1', 'https://example.hu/#x', 'https://exa mple.hu', 'javascript:alert(1)', 'https://-x.hu', 'https://example.hu/a\\b', 'https://example.hu/a b', 'https://example.hu:0', 'https://example.hu/a%', 'https://example.hu/a%zz', 'http://999.999.999.999', 'http://0x7f.1', 'http://123'] as $bad) {
        check($T::normalizeUrl($bad) === null, $bad);
    }
    check($T::originOf(new Request('GET', '/', basePath: '/cms', headers: ['host' => 'Pelda.HU:8080'])) === 'http://pelda.hu:8080/cms');
    check($T::originOf(new Request('GET', '/', headers: ['host' => 'pelda.hu'], secure: true)) === 'https://pelda.hu');
    foreach (['evil.hu/x', 'a b', '', 'pelda.hu:99999x', '<x>'] as $host) {
        check($T::originOf(new Request('GET', '/', headers: ['host' => $host])) === null, 'Host: ' . $host);
    }

    $article = $service->create($admin, 'article', ['title' => 'Nem kép']);
    try {
        $site->save(['name' => '  ', 'slogan' => str_repeat('x', 201), 'description' => '', 'url' => 'example.hu', 'share_image' => (string) $article->id(), 'indexing' => '1']);
        check(false, 'invalid settings were saved');
    } catch (ValidationException $e) {
        check(array_keys($e->errors) === ['slogan', 'name', 'url', 'share_image'], implode(',', array_keys($e->errors)));
        check($e->errors['slogan']->params === ['max' => 200]);
    }
    check($settings->get('site.name') === null, 'nothing saved');

    $image = $fakeImage('Megosztás');
    $site->save(['name' => " Új\n  név ", 'slogan' => '', 'description' => 'Leírás', 'url' => 'https://pelda.hu/', 'share_image' => (string) $image->id(), 'indexing' => '0']);
    $values = $site->values();
    check($values['name'] === 'Új név' && $values['slogan'] === '' && $values['url'] === 'https://pelda.hu' && $values['share_image'] === $image->id() && $values['indexing'] === false, json_encode($values));
    check($site->absolute('/kategóriák/a b') === 'https://pelda.hu/kateg%C3%B3ri%C3%A1k/a%20b' && $site->absolute('/100%') === 'https://pelda.hu/100%25', (string) $site->absolute('/kategóriák/a b'));
    $site->rememberUrl('https://masik.hu');
    check($site->url() === 'https://pelda.hu', 'a saved address is kept by the installer');

    $settings->set(array_fill_keys(array_map(static fn (string $k): string => 'site.' . $k, $T::KEYS), null));
    $configured = new $T($settings, ['name' => 'Konfig', 'url' => 'https://valodi.hu']);
    $configured->rememberUrl('http://belso-nev:8080');
    check($settings->get('site.url') === null && $configured->url() === 'https://valodi.hu', 'an address in the configuration file is not overridden by the request\'s');
    try {
        $site->save(['name' => "X\xff", 'slogan' => "ok\xff", 'description' => '', 'url' => '', 'share_image' => '', 'indexing' => '1']);
        check(false, 'invalid UTF-8 was saved');
    } catch (ValidationException $e) {
        check(($e->errors['name'] ?? null)?->key === 'validation.invalid_encoding' && ($e->errors['slogan'] ?? null)?->key === 'validation.invalid_encoding');
    }
    $site->reset();
    $site->rememberUrl('nem cím');
    check($site->url() === '');
    $site->rememberUrl('http://localhost:8096');
    check($site->url() === 'http://localhost:8096');
    $settings->set(['site.url' => null]);
    $service->delete($admin, $article);
    $repository->delete($image);
});

test('SiteValues: read on first use, read-only in templates', function (): void {
    $reads = 0;
    $values = new \Campanella\Site\SiteValues(static function () use (&$reads): array {
        $reads++;

        return ['name' => 'Webhely', 'share_image' => null];
    });
    check($reads === 0);
    check($values['name'] === 'Webhely' && $values['share_image'] === null && $values->offsetExists('share_image') && !$values->offsetExists('x') && $reads === 1);
    $values->reset();
    check(iterator_to_array($values) === ['name' => 'Webhely', 'share_image' => null] && $reads === 2);
    throws(\LogicException::class, function () use ($values): void {
        $values['name'] = 'x';
    });
    $broken = new \Campanella\Site\SiteValues(static fn (): array => throw new \RuntimeException('nincs adatbázis'));
    $log = ini_set('error_log', '/dev/null');
    check($broken['name'] === 'Campanella', 'the page still renders');
    ini_set('error_log', (string) $log);

    $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['t' => '{{ site.name }}|{{ site.slogan ?? "–" }}|{{ site.share_image is defined ? "d" : "u" }}']), ['strict_variables' => true]);
    $twig->addExtension(new \Twig\Extension\SandboxExtension(new \Campanella\View\TemplatePolicy(), true));
    $twig->addGlobal('site', \Campanella\Site\SiteValues::of(['name' => 'N', 'share_image' => null]));
    check($twig->render('t') === 'N|–|d', $twig->render('t'));
});

test('MetaBuilder: description, image, type and canonical URL of a page', function () use ($db, $repository, $engine, $service, $admin, $fakeImage): void {
    $M = \Campanella\Site\MetaBuilder::class;
    check($M::excerpt("  egy\n\nkettő  ") === 'egy kettő');
    $long = str_repeat('szó ', 60);
    $cut = $M::excerpt($long);
    check(mb_strlen($cut) <= 161 && str_ends_with($cut, 'szó…'), $cut);
    foreach (['компьютер ', 'voilà '] as $word) {
        check(mb_check_encoding($M::excerpt(str_repeat($word, 40)), 'UTF-8'), 'a cut never breaks a character: ' . $word);
    }
    check($M::textOf('<p>Első&nbsp;bekezdés</p><p>Második<br>sor</p><script>x()</script>') === "Első\u{a0}bekezdés Második sor  ", json_encode($M::textOf('<p>Első&nbsp;bekezdés</p><p>Második<br>sor</p><script>x()</script>')));

    $settings = new \Campanella\Settings\Settings($db);
    $site = new \Campanella\Site\SiteSettings($settings, ['name' => 'Webhely', 'description' => 'Az alapértelmezett leírás.'], $repository);
    $share = $fakeImage('Megosztási kép');
    $inText = $fakeImage('Szövegbeli kép', 800, 600);
    $settings->set(['site.url' => 'https://pelda.hu/cms', 'site.share_image' => (string) $share->id(), 'site.indexing' => '1']);
    $meta = new $M($site, $engine, new \Campanella\Media\MediaStorage(sys_get_temp_dir() . '/campanella-meta'), '/media');

    $withLead = $service->create($admin, 'article', [
        'title' => 'Cikk bevezetővel',
        'lead' => 'A cikk bevezetője.',
        'format' => 'html',
        'body' => '<p>Szöveg</p><p><img src="/cms/media/' . $inText->get('file_path') . '" alt="x"></p>',
        'path' => '/cikk-bevezetovel',
    ], publish: true);
    $page = $meta->forObject($withLead);
    check($page->title === 'Cikk bevezetővel' && $page->siteName === 'Webhely' && $page->description === 'A cikk bevezetője.' && $page->type === 'article', json_encode($page));
    check($page->canonical === 'https://pelda.hu/cms/cikk-bevezetovel' && $page->robots === null);
    check($page->imageUrl === 'https://pelda.hu/cms/media/' . $inText->get('file_path') && $page->imageWidth === 800 && $page->imageHeight === 600 && $page->imageAlt === 'Szövegbeli kép leírása', (string) $page->imageUrl);
    check($page->publishedTime !== null && str_contains($page->publishedTime, 'T'), 'an article\'s publication time');

    $plain = $service->create($admin, 'page', ['title' => 'Oldal', 'body' => "Első bekezdés.\n\nMásodik bekezdés.", 'path' => '/egy-oldal']);
    $pageMeta = $meta->forObject($plain);
    check($pageMeta->description === 'Első bekezdés. Második bekezdés.' && $pageMeta->imageUrl === 'https://pelda.hu/cms/media/' . $share->get('file_path') && $pageMeta->imageWidth === 1200, json_encode($pageMeta));
    $foreign = $meta->imageInHtml('<img src="https://mas.hu/media/2026/10/' . str_repeat('a', 24) . '.jpg"><img src="/media/2026/10/' . str_repeat('b', 24) . '.jpg">');
    check($foreign === null, 'only an image object of this site');

    $list = $meta->forPath('/hirek', 'Hírek', 3);
    check($list->title === 'Hírek' && $list->canonical === 'https://pelda.hu/cms/hirek?page=3' && $list->description === 'Az alapértelmezett leírás.' && $list->type === 'website');
    check($meta->forPath('/')->title === 'Webhely' && $meta->forPath('/')->canonical === 'https://pelda.hu/cms/');

    $settings->set(['site.url' => null, 'site.indexing' => '0']);
    $site->reset();
    $hidden = $meta->forObject($withLead);
    check($hidden->canonical === null && $hidden->imageUrl === null && $hidden->robots === 'noindex', 'no address: no absolute URLs');

    $settings->set(['site.share_image' => null, 'site.indexing' => null]);
    $service->delete($admin, $withLead);
    $service->delete($admin, $plain);
    $repository->delete($share);
    $repository->delete($inText);
});

test('Kernel: meta tags, robots.txt and sitemap.xml', function () use ($fakeImage, $repository): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $container->set(Session::class, static fn () => new Session(new ArraySessionStorage()));
    $settings = $container->get(\Campanella\Settings\Settings::class);
    $settings->set(['site.url' => null, 'site.indexing' => null, 'site.share_image' => null, 'site.description' => null]);
    $service = $container->get(ObjectService::class);
    $system = Actor::system();
    $public = $service->create($system, 'article', ['title' => 'Térképes cikk', 'lead' => 'Bevezető <b>szöveg</b> & más', 'path' => '/terkepes-cikk'], publish: true);
    $draft = $service->create($system, 'article', ['title' => 'Vázlat cikk', 'path' => '/vazlat-cikk']);

    // Without the site's address: a description, but no canonical URL and no sitemap.
    $page = $kernel->handle(new Request('GET', '/terkepes-cikk'));
    check($page->status === 200 && str_contains($page->body, '<meta name="description" content="Bevezető &lt;b&gt;szöveg&lt;/b&gt; &amp; más">') && !str_contains($page->body, 'rel="canonical"'), 'escaped description');
    check(str_contains($page->body, '<meta property="og:type" content="article">') && str_contains($page->body, '<meta property="og:title" content="Térképes cikk">'));
    check($kernel->handle(new Request('GET', '/sitemap.xml'))->status === 404, 'no sitemap without the address');
    $robots = $kernel->handle(new Request('GET', '/robots.txt'));
    check($robots->status === 200 && str_starts_with($robots->headers['Content-Type'] ?? '', 'text/plain') && str_contains($robots->body, "User-agent: *\nDisallow: /admin/\nDisallow: /login\nDisallow: /logout\nDisallow: /install\n") && !str_contains($robots->body, 'Sitemap:'), $robots->body);

    $settings->set(['site.url' => 'https://pelda.hu']);
    $page = $kernel->handle(new Request('GET', '/terkepes-cikk'));
    check(str_contains($page->body, '<link rel="canonical" href="https://pelda.hu/terkepes-cikk">') && str_contains($page->body, '<meta property="og:url" content="https://pelda.hu/terkepes-cikk">'), 'the canonical URL');
    $news = $kernel->handle(new Request('GET', '/hirek'));
    check(str_contains($news->body, '<link rel="canonical" href="https://pelda.hu/hirek">') && str_contains($news->body, '<meta property="og:title" content="Hírek">'), 'a list\'s canonical URL');
    $sitemap = $kernel->handle(new Request('GET', '/sitemap.xml'));
    check($sitemap->status === 200 && str_starts_with($sitemap->headers['Content-Type'] ?? '', 'application/xml') && str_contains($sitemap->body, '<loc>https://pelda.hu/terkepes-cikk</loc><lastmod>'), 'the published article');
    check(str_contains($sitemap->body, '<loc>https://pelda.hu/</loc>') && str_contains($sitemap->body, '<loc>https://pelda.hu/hirek</loc>') && !str_contains($sitemap->body, 'vazlat-cikk'), 'the lists, but no draft');
    check(simplexml_load_string($sitemap->body) !== false, 'well-formed XML');
    check($kernel->handle(new Request('GET', '/sitemap.xml', query: ['page' => '99']))->status === 404 && $kernel->handle(new Request('GET', '/sitemap.xml', query: ['page' => 'x']))->status === 404);
    check(str_contains($kernel->handle(new Request('GET', '/robots.txt'))->body, "\nSitemap: https://pelda.hu/sitemap.xml\n"));

    // The share image of the site, on a page without an image of its own.
    $image = $fakeImage('Közös kép');
    $settings->set(['site.share_image' => (string) $image->id(), 'site.description' => 'A webhely leírása']);
    $home = $kernel->handle(new Request('GET', '/'));
    check(str_contains($home->body, '<meta property="og:image" content="https://pelda.hu/media/' . $image->get('file_path') . '">') && str_contains($home->body, '<meta name="description" content="A webhely leírása">') && str_contains($home->body, 'summary_large_image'), 'the front page');

    check(($kernel->handle(new Request('GET', '/nincs-ilyen'))->headers['X-Robots-Tag'] ?? '') === 'noindex' && ($kernel->handle(new Request('GET', '/login'))->headers['X-Robots-Tag'] ?? '') === 'noindex', 'error and login pages are never indexed');
    check(!isset($kernel->handle(new Request('GET', '/terkepes-cikk'))->headers['X-Robots-Tag']), 'content pages are');

    // Indexing turned off: every page asks not to be indexed, but crawling stays allowed (or the noindex could not be read).
    $settings->set(['site.indexing' => '0']);
    $off = $kernel->handle(new Request('GET', '/terkepes-cikk'));
    check(str_contains($off->body, '<meta name="robots" content="noindex">') && ($off->headers['X-Robots-Tag'] ?? '') === 'noindex');
    $robots = $kernel->handle(new Request('GET', '/robots.txt'))->body;
    check(!str_contains($robots, "Disallow: /\n") && str_contains($robots, "Disallow: /admin/\n") && !str_contains($robots, 'Sitemap:') && $kernel->handle(new Request('GET', '/sitemap.xml'))->status === 404, $robots);

    $router = $container->get(\Campanella\Http\Router::class);
    check($router->isRouted('/sitemap.xml') && $router->isRouted('/robots.txt'), 'no object can take these paths');
    check($container->get(\Campanella\Http\Router::class)->paths('query') === ['/', '/hirek', '/kategoriak']);

    $settings->set(['site.url' => null, 'site.indexing' => null, 'site.share_image' => null, 'site.description' => null]);
    $service->delete($system, $public);
    $service->delete($system, $draft);
    $repository->delete($image);
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: the site settings page, for administrators', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $settings = $container->get(\Campanella\Settings\Settings::class);
    $settings->set(array_fill_keys(array_map(static fn (string $k): string => 'site.' . $k, \Campanella\Site\SiteSettings::KEYS), null));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $auth = $container->get(AuthService::class);
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post, headers: ['host' => 'pelda.hu']));
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';

    $auth->login(new Request('GET', '/'), $newUser('beallito-szerk@example.hu', 'beallito-jelszo-1', ['editor']));
    check($send('GET', '/admin/system/settings')->status === 403, 'not for editors');
    $auth->login(new Request('GET', '/'), $newUser('beallito@example.hu', 'beallito-jelszo-1', ['administrator']));

    $page = $send('GET', '/admin/system/settings');
    check($page->status === 200 && str_contains($page->body, 'value="Campanella"') && str_contains($page->body, 'placeholder="https://example.hu"') && str_contains($page->body, 'Még nincs megadva.') && str_contains($page->body, 'a(z) http://pelda.hu címen') && str_contains($page->body, 'href="/admin/system/settings">Webhely-beállítások</a>'), 'the form, with the address it was opened at');
    $form = ['_csrf' => $csrfOf($page), 'name' => '', 'slogan' => 'Új szlogen', 'description' => 'Leírás', 'url' => 'pelda.hu', 'share_image' => '', 'indexing' => '1'];
    check($send('POST', '/admin/system/settings', ['_csrf' => 'x'] + $form)->status === 400);
    $bad = $send('POST', '/admin/system/settings', $form);
    check($bad->status === 422 && str_contains($bad->body, 'value="Új szlogen"') && str_contains($bad->body, 'is-invalid') && $settings->get('site.slogan') === null, 'nothing saved with an error');
    $done = $send('POST', '/admin/system/settings', ['name' => 'Beállított webhely', 'url' => 'https://pelda.hu/'] + $form);
    check($done->status === 303 && ($done->headers['Location'] ?? '') === '/admin/system/settings');
    check(str_contains($send('GET', '/admin/system/settings')->body, 'A beállításokat mentettük.'));
    $home = $send('GET', '/');
    check(str_contains($home->body, '<title>Beállított webhely</title>') && str_contains($home->body, 'Új szlogen') && str_contains($home->body, '<link rel="canonical" href="https://pelda.hu/">'), 'the site uses them at once');

    $system = $send('GET', '/admin/system');
    check(str_contains($system->body, 'Webhely') && str_contains($system->body, 'https://pelda.hu'), 'the System page shows the address');

    $settings->set(array_fill_keys(array_map(static fn (string $k): string => 'site.' . $k, \Campanella\Site\SiteSettings::KEYS), null));
    putenv('CAMPANELLA_DB_PREFIX');
});

test('SiteCheck: the site\'s address and indexing', function () use ($db): void {
    $settings = new \Campanella\Settings\Settings($db);
    $settings->set(['site.url' => null, 'site.indexing' => null]);
    $site = new \Campanella\Site\SiteSettings($settings, ['name' => 'X']);
    $publicDir = sys_get_temp_dir() . '/campanella-public-' . bin2hex(random_bytes(4));
    mkdir($publicDir);
    $run = static function (?Request $request) use ($site, $publicDir): array {
        $lines = [];
        foreach ((\Campanella\System\SiteCheck::checks($site, $publicDir))($request) as $line) {
            $lines[$line->label] = $line->status->name . ':' . $line->value . ($line->hint !== null ? '|' . $line->hint->key . json_encode($line->hint->params) : '');
        }

        return $lines;
    };
    $request = new Request('GET', '/admin/system', headers: ['host' => 'pelda.hu'], secure: true);
    check($run($request)['admin.system.site_url'] === 'Warning:admin.system.site_url_missing|admin.system.site_url_suggest{"origin":"https:\/\/pelda.hu"}', $run($request)['admin.system.site_url']);
    check(str_starts_with($run(null)['admin.system.site_url'], 'Warning:admin.system.site_url_missing|admin.system.site_url_missing_hint'));
    $settings->set(['site.url' => 'https://pelda.hu']);
    $site->reset();
    check($run($request)['admin.system.site_url'] === 'Ok:https://pelda.hu' && $run($request)['admin.system.indexing'] === 'Ok:admin.system.indexing_on' && !isset($run($request)['robots.txt']));
    check(str_starts_with($run(new Request('GET', '/', headers: ['host' => 'localhost:8080']))['admin.system.site_url'], 'Warning:https://pelda.hu|admin.system.site_url_other'));
    $settings->set(['site.indexing' => '0']);
    $site->reset();
    file_put_contents($publicDir . '/robots.txt', "User-agent: *\n");
    check(str_starts_with($run($request)['admin.system.indexing'], 'Warning:admin.system.indexing_off') && str_starts_with($run($request)['robots.txt'], 'Info:'));
    unlink($publicDir . '/robots.txt');
    rmdir($publicDir);
    $settings->set(['site.url' => null, 'site.indexing' => null]);
});

echo "\nImages: copies and usage (0.1.2)\n";

test('ImageProcessor: smaller copies at the configured widths, in the image\'s own type', function (): void {
    if (!extension_loaded('gd')) {
        echo "      (skipped: no gd extension)\n";

        return;
    }
    $P = \Campanella\Media\ImageProcessor::class;
    $processor = new $P();
    check($processor->variantWidths() === [320, 640, 1024, 1600] && $processor->widthsFor(2000) === [320, 640, 1024, 1600] && $processor->widthsFor(720) === [320, 640] && $processor->widthsFor(700) === [320] && $processor->widthsFor(340) === []);
    $big = $processor->process(testImage('jpeg', 2000, 1000));
    check(array_map(static fn ($v): string => $v->width . 'x' . $v->height, $big->variants) === ['320x160', '640x320', '1024x512', '1600x800'], json_encode(array_map(static fn ($v) => $v->width, $big->variants)));
    foreach ($big->variants as $variant) {
        $info = getimagesizefromstring($variant->bytes);
        check($info !== false && $info[0] === $variant->width && $info[2] === IMAGETYPE_JPEG, 'a real JPEG of its width');
    }
    $png = $processor->process(testImage('png', 800, 400, true));
    check(count($png->variants) === 2, 'an 800 pixel image: 320 and 640');
    $copy = imagecreatefromstring($png->variants[0]->bytes);
    check($copy !== false && (imagecolorat($copy, 5, 5) >> 24 & 0x7F) === 127, 'transparency kept');
    check($processor->process(testImage('gif', 300, 200))->variants === [], 'a small image needs none');

    $custom = new $P(variantWidths: [500, 100]);
    check($custom->variantWidths() === [100, 500] && count($custom->process(testImage('webp', 1000, 500))->variants) === 2);
    check((new $P(variantWidths: []))->process(testImage('jpeg', 2000, 1000))->variants === []);
    foreach ([[8], [20000], ['640'], range(100, 1000, 100)] as $bad) {
        throws(\InvalidArgumentException::class, fn () => new $P(variantWidths: $bad));
    }
    // A drawing with few colours (a palette PNG): its copies keep a palette, and one that would
    // not be smaller than the image is left out.
    $drawing = imagecreate(1200, 800);
    imagecolorallocate($drawing, 255, 255, 255);
    $ink = imagecolorallocate($drawing, 0, 0, 0);
    for ($x = 0; $x < 1200; $x += 7) {
        imageline($drawing, $x, 0, 1200 - $x, 800, $ink);
    }
    $drawingFile = tempnam(sys_get_temp_dir(), 'cimg');
    imagepng($drawing, $drawingFile);
    $processed = $processor->process($drawingFile);
    foreach ($processed->variants as $variant) {
        check(strlen($variant->bytes) < strlen($processed->bytes), "a copy smaller than the image ({$variant->width}: " . strlen($variant->bytes) . ' / ' . strlen($processed->bytes) . ')');
    }
    check(count($processor->variantsOf(testImage('jpeg', 1200, 900))) === 3, 'of a stored file');
    check(mediaError(fn () => $processor->variantsOf(__FILE__)) === 'media.not_image');
    check((new $P(useGd: false))->variantsOf(testImage('jpeg', 1200, 900)) === [], 'without GD: none');
});

test('MediaStorage and MediaFile: copies beside their image, deleted with it', function (): void {
    $F = \Campanella\Capability\MediaFile::class;
    check($F::variantPath('2026/10/' . str_repeat('a', 24) . '.jpg', 640) === '2026/10/' . str_repeat('a', 24) . '-640.jpg');
    check($F::formatWidths([640, 320, 640, 0]) === '320,640' && $F::parseWidths('640,x,320,320') === [320, 640] && $F::parseWidths(null) === [] && $F::parseWidths('') === []);

    $dir = sys_get_temp_dir() . '/campanella-variants-' . bin2hex(random_bytes(4));
    $storage = new \Campanella\Media\MediaStorage($dir);
    $path = $storage->store('eredeti', 'jpg');
    $other = $storage->store('masik', 'jpg');
    $variant = $storage->storeVariant($path, 640, 'kicsi');
    check($variant === $F::variantPath($path, 640) && file_get_contents($storage->path($variant)) === 'kicsi' && $storage->url($variant) === '/media/' . $variant);
    $storage->storeVariant($path, 320, 'kisebb');
    $storage->storeVariant($other, 320, 'masik kicsi');
    throws(\InvalidArgumentException::class, fn () => $storage->storeVariant($variant, 320, 'x'));
    throws(\InvalidArgumentException::class, fn () => $storage->path('2026/10/' . str_repeat('a', 24) . '-0.jpg'));
    check($storage->deleteVariants($path) === 2 && is_file($storage->path($path)) && is_file($storage->path($F::variantPath($other, 320))), 'only its own copies');
    $storage->deleteVariants($other);
    $storage->delete($path);
    $storage->delete($other);
});

test('MediaService: copies on upload, made later for older images, deleted with the image', function () use ($repository, $policy, $admin, $engine): void {
    if (!extension_loaded('gd')) {
        echo "      (skipped: no gd extension)\n";

        return;
    }
    $dir = sys_get_temp_dir() . '/campanella-media-' . bin2hex(random_bytes(4));
    $storage = new \Campanella\Media\MediaStorage($dir);
    $objects = new ObjectService($repository, $policy);
    $objects->addListener(new \Campanella\Media\DeleteMediaFile($storage));
    $media = new \Campanella\Media\MediaService($objects, $repository, $policy, new \Campanella\Media\ImageProcessor(), $storage, 'image', $engine);
    $files = static fn (): array => glob($dir . '/*/*/*') ?: [];

    $image = $media->uploadImage($admin, testImage('jpeg', 1200, 800), 'nagy.jpg');
    $file = $image->as(\Campanella\Capability\MediaFile::class);
    check($file->variantWidths() === [320, 640, 1024] && $file->hasVariants() && count($files()) === 4, json_encode($files()));
    check($media->thumbnailUrl($image) === '/media/' . \Campanella\Capability\MediaFile::variantPath($file->path(), 320));
    $small = $media->uploadImage($admin, testImage('png', 200, 100), 'kicsi.png');
    check($small->get('variants') === '' && $small->as(\Campanella\Capability\MediaFile::class)->hasVariants() && $media->thumbnailUrl($small) === $media->url($small), 'none needed: recorded as empty');

    // An image uploaded before 0.1.2: no copies recorded.
    $storage->deleteVariants($file->path());
    $image->set('variants', null);
    $repository->save($image);
    check($media->countWithoutVariants() >= 1 && in_array($image->id(), array_map(static fn ($i) => $i->id(), $media->withoutVariants(1000)), true));
    [$made, $left] = $media->makeMissingVariants();
    check($made >= 1 && $left === 0 && $repository->find((int) $image->id())?->get('variants') === '320,640,1024' && count($files()) === 5, "{$made} {$left}");
    check(in_array($image->id(), array_map(static fn ($i) => $i->id(), $media->images(0, 1000)), true));

    // A missing file: recorded as having none, not tried again.
    $broken = $repository->find((int) $image->id());
    $storage->delete($broken->as(\Campanella\Capability\MediaFile::class)->path());
    $broken->set('variants', null);
    $repository->save($broken);
    $log = ini_set('error_log', '/dev/null');
    check($media->makeVariants($broken) === [] && $repository->find((int) $broken->id())?->get('variants') === '');
    ini_set('error_log', (string) $log);

    $objects->delete($admin, $small);
    $log = ini_set('error_log', '/dev/null');
    $objects->delete($admin, $broken);
    ini_set('error_log', (string) $log);
    check($files() === [], 'every copy deleted with its image: ' . json_encode($files()));
});

test('ResponsiveImages: srcset, sizes, size and lazy loading for the images of this site', function () use ($repository, $engine, $service, $admin): void {
    $storage = new \Campanella\Media\MediaStorage(sys_get_temp_dir() . '/campanella-ri');
    $images = new \Campanella\Media\ResponsiveImages($engine, $storage);
    $name = bin2hex(random_bytes(12));
    $image = $repository->create('image', ['title' => 'Kép', 'file_path' => "2026/10/{$name}.jpg", 'mime_type' => 'image/jpeg', 'file_size' => 1, 'width' => 2000, 'height' => 1000, 'file_hash' => str_repeat('0', 64), 'variants' => '320,640']);
    $repository->save($image);
    $plainName = bin2hex(random_bytes(12));
    $plain = $repository->create('image', ['title' => 'Régi', 'file_path' => "2026/10/{$plainName}.png", 'mime_type' => 'image/png', 'file_size' => 1, 'width' => 300, 'height' => 200, 'file_hash' => str_repeat('1', 64)]);
    $repository->save($plain);

    check($images->url($image) === "/media/2026/10/{$name}.jpg" && $images->url($image, 500) === "/media/2026/10/{$name}-640.jpg" && $images->url($image, 3000) === "/media/2026/10/{$name}.jpg");
    check($images->srcset($image, '/cms') === "/cms/media/2026/10/{$name}-320.jpg 320w, /cms/media/2026/10/{$name}-640.jpg 640w, /cms/media/2026/10/{$name}.jpg 2000w" && $images->srcset($plain) === '');

    $html = '<p><img src="/cms/media/2026/10/' . $name . '.jpg" alt="a"></p>'
        . '<p><img src="/cms/media/2026/10/' . $name . '.jpg" alt="b" width="400" /></p>'
        . '<p><img src="/media/2026/10/' . $plainName . '.png" alt="c"></p>'
        . '<p><img src="https://mas.hu/x.jpg" alt="d"><img src="/media/2026/10/' . str_repeat('f', 24) . '.jpg" alt="e" loading="eager"></p>';
    $out = $images->enrich($html, '/cms');
    check(str_contains($out, '<img src="/cms/media/2026/10/' . $name . '.jpg" alt="a" srcset="/cms/media/2026/10/' . $name . '-320.jpg 320w, /cms/media/2026/10/' . $name . '-640.jpg 640w, /cms/media/2026/10/' . $name . '.jpg 2000w" sizes="(max-width: 800px) 100vw, 800px" width="2000" height="1000" loading="lazy" decoding="async">'), $out);
    check(str_contains($out, 'alt="b" width="400" srcset="') && str_contains($out, 'sizes="(max-width: 400px) 100vw, 400px" loading="lazy" decoding="async" />'), 'a width of its own: sizes from it, no height added');
    check(str_contains($out, 'alt="c" width="300" height="200" loading="lazy" decoding="async">') && !str_contains(substr($out, (int) strpos($out, 'alt="c"'), 80), 'srcset'), 'no copies: no srcset');
    check(str_contains($out, '<img src="https://mas.hu/x.jpg" alt="d">') && str_contains($out, 'alt="e" loading="eager">'), 'not an image of this site: unchanged');
    $tricky = $images->enrich('<img alt=\' src=/media/2026/10/' . $name . '.jpg \' src=\'/media/2026/10/' . $plainName . '.png\'>');
    check(!str_contains($tricky, 'srcset') && str_contains($tricky, 'width="300"'), 'an attribute inside another\'s value is not read: ' . $tricky);
    check($images->enrich('<p>Nincs kép</p>') === '<p>Nincs kép</p>');

    $repository->delete($image);
    $repository->delete($plain);
});

test('MediaUsage: the texts that show an image, kept on every save', function () use ($repository, $engine, $service, $admin, $db): void {
    $U = \Campanella\Media\MediaUsage::class;
    $a = bin2hex(random_bytes(12));
    $b = bin2hex(random_bytes(12));
    check($U::pathsIn('<img src="/media/2026/10/' . $a . '-640.jpg"><a href="/cms/media/2026/10/' . $b . '.png">x</a><img src=\'/media/2026/10/' . $a . '.jpg\'><img data-src="/media/2026/10/' . str_repeat('c', 24) . '.jpg">') === ["2026/10/{$a}.jpg", "2026/10/{$b}.png"]);

    $make = static function (string $name, string $ext) use ($repository) {
        $image = $repository->create('image', ['title' => $name, 'file_path' => "2026/10/{$name}.{$ext}", 'mime_type' => 'image/jpeg', 'file_size' => 1, 'file_hash' => str_repeat('0', 64), 'variants' => '']);
        $repository->save($image);

        return $image;
    };
    $first = $make($a, 'jpg');
    $second = $make($b, 'png');
    $usage = new $U($db, $engine);
    $article = $service->create($admin, 'article', ['title' => 'Képes cikk', 'format' => 'html', 'body' => '<p><img src="/media/2026/10/' . $a . '.jpg" alt=""></p>']);
    $page = $service->create($admin, 'page', ['title' => 'Képes oldal', 'format' => 'html', 'body' => '<p><a href="/media/2026/10/' . $a . '-320.jpg">kép</a></p>', 'path' => '/kepes-oldal-' . $a]);
    $plain = $service->create($admin, 'page', ['title' => 'Sima', 'body' => '/media/2026/10/' . $a . '.jpg', 'path' => '/sima-' . $a]);
    $titles = static fn (array $found): array => array_map(static fn ($o) => $o->get('title'), $found['items']);
    $found = $usage->usedBy($first, $admin);
    check($found['total'] === 2 && in_array('Képes cikk', $titles($found), true) && in_array('Képes oldal', $titles($found), true), json_encode($titles($found)));
    check($usage->usedBy($second, $admin)['total'] === 0, 'a plain text does not count');

    $service->update($admin, $article, ['body' => '<p><img src="/media/2026/10/' . $b . '.png" alt=""></p>']);
    check($usage->usedBy($first, $admin)['total'] === 1 && $titles($usage->usedBy($second, $admin)) === ['Képes cikk'], 'kept up to date on save');
    check($usage->usedBy($first, $admin, 1)['items'] !== [] && count($usage->usedBy($first, $admin, 1)['items']) === 1);

    $service->delete($admin, $page);
    check($usage->usedBy($first, $admin)['total'] === 0, 'a deleted text');
    $repository->delete($second);
    check((int) $db->fetchValue('SELECT COUNT(*) FROM {media_usage} WHERE object_id = :id', ['id' => $article->id()]) === 0, 'a deleted image');

    // The migration fills it from the texts saved before 0.1.2.
    $service->update($admin, $article, ['body' => '<p><img src="/media/2026/10/' . $a . '.jpg" alt=""></p>']);
    $db->execute('DELETE FROM {media_usage}');
    $migration = new \Campanella\Database\Migration\Core\MediaUsageIndex();
    $migration->up(new \Campanella\Database\Migration\MigrationContext($db));
    check($usage->usedBy($first, $admin)['total'] === 1);
    $migration->up(new \Campanella\Database\Migration\MigrationContext($db));
    check($usage->usedBy($first, $admin)['total'] === 1, 'repeatable');

    $settings = new \Campanella\Settings\Settings($db);
    $settings->set(['site.share_image' => (string) $first->id()]);
    check((new $U($db, $engine, new \Campanella\Site\SiteSettings($settings)))->sharedBySite($first) && !$usage->sharedBySite($first));
    $settings->set(['site.share_image' => null]);

    $service->delete($admin, $article);
    $service->delete($admin, $plain);
    $repository->delete($first);
});

test('Kernel: srcset in texts, the delete page of a used image, making the missing copies', function () use ($newUser): void {
    if (!extension_loaded('gd')) {
        echo "      (skipped: no gd extension)\n";

        return;
    }
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $root = dirname(__DIR__);
    $kernel = new \Campanella\Core\Kernel($root);
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dir = sys_get_temp_dir() . '/campanella-kernel-media-' . bin2hex(random_bytes(4));
    $container->set(\Campanella\Media\MediaStorage::class, static fn () => new \Campanella\Media\MediaStorage($dir));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';
    $media = $container->get(\Campanella\Media\MediaService::class);
    $system = Actor::system();
    $image = $media->uploadImage($system, testImage('jpeg', 1000, 500), 'Napfény.jpg');
    $path = $image->as(\Campanella\Capability\MediaFile::class)->path();
    $article = $container->get(ObjectService::class)->create($system, 'article', ['title' => 'Napos cikk', 'format' => 'html', 'body' => '<p><img src="/media/' . $path . '" alt="Napfény"></p>', 'path' => '/napos-cikk'], publish: true);

    $page = $send('GET', '/napos-cikk');
    check($page->status === 200 && str_contains($page->body, 'srcset="/media/' . \Campanella\Capability\MediaFile::variantPath($path, 320) . ' 320w, /media/' . \Campanella\Capability\MediaFile::variantPath($path, 640) . ' 640w, /media/' . $path . ' 1000w"'), 'the copies in the text');

    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('kepek-012@example.hu', 'kepek-jelszo-012', ['administrator']));
    $delete = $send('GET', '/admin/image/' . $image->id() . '/delete');
    check(str_contains($delete->body, 'Ezek a szövegek mutatják a képet') && str_contains($delete->body, '>Napos cikk</a>') && str_contains($delete->body, 'hiányzó kép lesz'), 'the delete page lists the text');

    // An image from before 0.1.2: the System page offers to make its copies.
    $repo = $container->get(ObjectRepository::class);
    $old = $repo->find((int) $image->id());
    $old->set('variants', null);
    $repo->save($old);
    $container->get(\Campanella\Media\MediaStorage::class)->deleteVariants($path);
    $before = $repo->find((int) $image->id())?->updated()->format('c');
    sleep(1);
    $systemPage = $send('GET', '/admin/system');
    check(str_contains($systemPage->body, 'action="/admin/system/media-variants"') && str_contains($systemPage->body, 'Változatok elkészítése'), 'the button');
    check($send('GET', '/admin/system/media-variants')->status === 405);
    $log = ini_set('error_log', '/dev/null'); // other tests' images have no files here
    $made = $send('POST', '/admin/system/media-variants', ['_csrf' => $csrfOf($systemPage)]);
    ini_set('error_log', (string) $log);
    check($made->status === 303 && $repo->find((int) $image->id())?->get('variants') === '320,640' && str_contains($send('GET', '/admin/system')->body, 'Elkészültek a kisebb változatok'), 'made');
    check($repo->find((int) $image->id())?->updated()->format('c') === $before, 'the image\'s modification time stays');

    $old = $repo->find((int) $image->id());
    $old->set('variants', null);
    $repo->save($old);
    $stream = fopen('php://memory', 'w+');
    (new \Campanella\Cli\MediaVariantsCommand())->run($container, [], new \Campanella\Cli\Output($stream, $stream));
    rewind($stream);
    $out = (string) stream_get_contents($stream);
    check(str_contains($out, '320, 640') && $repo->find((int) $image->id())?->get('variants') === '320,640', $out);

    $container->get(ObjectService::class)->delete($system, $article);
    $container->get(ObjectService::class)->delete($system, $repo->find((int) $image->id()));
    check((glob($dir . '/*/*/*') ?: []) === [], 'the files are gone');
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nEvents and e-mail (0.1.3)\n";

/** A mail transport that keeps the messages (or fails, with a message). */
function captureTransport(?string $fail = null): \Symfony\Component\Mailer\Transport\TransportInterface
{
    return new class ($fail) extends \Symfony\Component\Mailer\Transport\AbstractTransport {
        /** @var list<\Symfony\Component\Mime\Email> */
        public array $sent = [];

        public function __construct(private readonly ?string $fail)
        {
            parent::__construct();
        }

        protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
        {
            if ($this->fail !== null) {
                throw new \Symfony\Component\Mailer\Exception\TransportException($this->fail);
            }
            $original = $message->getOriginalMessage();
            if ($original instanceof \Symfony\Component\Mime\Email) {
                $this->sent[] = $original;
            }
        }

        public function __toString(): string
        {
            return 'capture://';
        }
    };
}

test('EventDispatcher: listeners of the class and of its parents; a failing one does not stop the others', function (): void {
    $events = new \Campanella\Event\EventDispatcher();
    $seen = [];
    $events->listen(\Campanella\Event\ObjectPublished::class, static function ($e) use (&$seen): void { $seen[] = 'published:' . $e->name(); });
    $events->listen(\Campanella\Event\ObjectEvent::class, static function ($e) use (&$seen): void { $seen[] = 'object:' . $e->name(); });
    $events->listen(\Campanella\Event\ObjectEvent::class, static function (): void { throw new \RuntimeException('hiba'); });
    $events->listen(\Campanella\Event\Event::class, static function ($e) use (&$seen): void { $seen[] = 'any:' . $e->name(); });
    $repository = $GLOBALS['repository'];
    $article = $repository->create('article', ['title' => 'Esemény']);
    $log = ini_set('error_log', '/dev/null');
    $events->dispatch(new \Campanella\Event\ObjectPublished($article));
    ini_set('error_log', (string) $log);
    check($seen === ['published:ObjectPublished', 'object:ObjectPublished', 'any:ObjectPublished'], json_encode($seen));
    check($events->hasListeners(\Campanella\Event\ObjectDeleted::class) && !(new \Campanella\Event\EventDispatcher())->hasListeners(\Campanella\Event\ObjectDeleted::class));
    throws(\InvalidArgumentException::class, fn () => $events->listen(\stdClass::class, static fn () => null));

    $made = 0;
    $action = new class () implements \Campanella\Event\Action {
        public static array $handled = [];

        public function __construct(array $unused = [])
        {
        }

        public static function create(\Campanella\Core\Container $container): self
        {
            return new self();
        }

        public function handle(\Campanella\Event\Event $event): void
        {
            self::$handled[] = $event->name();
        }
    };
    $bound = new \Campanella\Event\EventDispatcher();
    $bound->bind([\Campanella\Event\UserCreated::class => [$action::class]], static function (string $class) use (&$made) {
        $made++;

        return $class::create(new \Campanella\Core\Container());
    });
    check($made === 0, 'made on its first event');
    $bound->dispatch(new \Campanella\Event\UserCreated($article));
    $bound->dispatch(new \Campanella\Event\UserCreated($article));
    $bound->dispatch(new \Campanella\Event\ObjectDeleted($article));
    check($made === 1 && $action::$handled === ['UserCreated', 'UserCreated'], json_encode($action::$handled));
    $log = ini_set('error_log', '/dev/null');
    $problems = $bound->bind(['NincsIlyen' => [$action::class], \Campanella\Event\UserCreated::class => [\stdClass::class]], static fn () => null);
    ini_set('error_log', (string) $log);
    check(count($problems) === 2 && $bound->problems() === $problems, 'a wrong binding is skipped and reported, not fatal');

    // In the order they were added, whatever class they listen to.
    $order = new \Campanella\Event\EventDispatcher();
    $ran = [];
    $order->listen(\Campanella\Event\Event::class, static function () use (&$ran): void { $ran[] = 'A'; });
    $order->listen(\Campanella\Event\ObjectPublished::class, static function () use (&$ran): void { $ran[] = 'B'; });
    $order->listen(\Campanella\Event\Event::class, static function () use (&$ran): void { $ran[] = 'C'; });
    $order->dispatch(new \Campanella\Event\ObjectPublished($article));
    check($ran === ['A', 'B', 'C'], implode($ran));
});

test('ObjectService and UserService dispatch their events after saving', function () use ($repository, $policy, $admin, $engine, $db): void {
    $events = new \Campanella\Event\EventDispatcher();
    $seen = [];
    $events->listen(\Campanella\Event\Event::class, static function (\Campanella\Event\Event $e) use (&$seen): void {
        $seen[] = $e->name() . ($e instanceof \Campanella\Event\ObjectEvent ? ($e->object->isNew() ? ':new' : ':saved') : '');
    });
    $service = new ObjectService($repository, $policy, $events);
    $article = $service->create($admin, 'article', ['title' => 'Eseményes cikk'], publish: true);
    $service->update($admin, $article, ['title' => 'Eseményes cikk 2']);
    $service->unpublish($admin, $article);
    $scheduled = null;
    $events->listen(\Campanella\Event\ObjectPublished::class, static function (\Campanella\Event\ObjectPublished $e) use (&$scheduled): void { $scheduled = $e; });
    $service->publish($admin, $article, new DateTimeImmutable('+2 days'));
    check($scheduled !== null && $scheduled->isScheduled() && $scheduled->summary()->key === 'event.object_scheduled' && $scheduled->summary()->params['title'] === 'Eseményes cikk 2');
    $service->delete($admin, $article);
    check($seen === ['ObjectCreated:saved', 'ObjectPublished:saved', 'ObjectUpdated:saved', 'ObjectUnpublished:saved', 'ObjectPublished:saved', 'ObjectDeleted:saved'], json_encode($seen));
    check((new \Campanella\Event\ObjectDeleted($article))->summary()->key === 'event.object_deleted' && (new \Campanella\Event\ObjectDeleted($article))->title() === 'Eseményes cikk 2');

    $seen = [];
    $users = new \Campanella\Service\UserService($repository, $engine, $policy, new Throttle($db), [Actor::ADMINISTRATOR, 'editor'], $db, $events);
    $user = $users->create($admin, 'Esemény Ernő', 'esemeny@example.hu', 'esemeny-jelszo-1', ['editor']);
    $users->setPassword($admin, $user, 'esemeny-jelszo-2');
    $changed = null;
    $events->listen(\Campanella\Event\PasswordChanged::class, static function ($e) use (&$changed): void { $changed = $e; });
    $users->changeOwnPassword($user, 'esemeny-jelszo-2', 'esemeny-jelszo-3');
    check($seen === ['UserCreated', 'PasswordChanged', 'PasswordChanged'] && $changed !== null && !$changed->byAdministrator && $changed->actor?->id === $user->id(), json_encode($seen));
    check((new \Campanella\Event\UserCreated($user))->summary()->params['name'] === 'Esemény Ernő');
    $repository->delete($user);
});

test('Mailer: from templates, logged, never throws; nothing is sent without settings', function () use ($db): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $env = (new \Campanella\Core\Kernel(dirname(__DIR__)))->container()->get(\Twig\Environment::class);
    putenv('CAMPANELLA_DB_PREFIX');
    $M = \Campanella\Mail\Mailer::class;
    $db->execute('DELETE FROM {mail_log}');

    $off = $M::fromConfig([], static fn () => $env, static fn () => 'Webhely', $db);
    check(!$off->isConfigured() && $off->configError() === null && $off->send('valaki@example.hu', 'test')->status === 'not_configured');
    check($off->log(5)[0]['status'] === 'not_configured' && $off->log(5)[0]['recipient'] === 'valaki@example.hu');

    $bad = $M::fromConfig(['dsn' => 'nemletezik://user:titok@host', 'from' => 'noreply@example.hu'], static fn () => $env, static fn () => 'W', $db);
    check(!$bad->isConfigured() && $bad->configError() !== null && !str_contains((string) $bad->configError(), 'titok') && $bad->description() === 'nemletezik://host', (string) $bad->configError());
    check($M::fromConfig(['dsn' => 'null://null', 'from' => 'nem cím'], static fn () => $env, static fn () => 'W')->configError() === 'mail.from is not an e-mail address');
    check($M::describeDsn('smtp://user:pass@smtp.example.hu:587?verify_peer=0') === 'smtp://smtp.example.hu:587' && $M::describeDsn('') === '');
    check($M::fromConfig(['dsn' => 'null://null', 'from' => 'noreply@example.hu'], static fn () => $env, static fn () => 'W')->isConfigured(), 'a valid DSN');

    $transport = captureTransport();
    $mailer = new $M($transport, 'noreply@example.hu', static fn () => $env, static fn () => "Webhely & Társa\r\nBcc: x@y.hu", $db, 90, 'capture://');
    $result = $mailer->send('olvaso@example.hu', 'test', ['sent_at' => '2026-10-10 08:00:00 UTC'], 'Kiss & Nagy');
    check($result->sent() && count($transport->sent) === 1, (string) $result->error);
    $email = $transport->sent[0];
    check($email->getTo()[0]->getAddress() === 'olvaso@example.hu' && $email->getFrom()[0]->getAddress() === 'noreply@example.hu');
    check(!str_contains($email->getFrom()[0]->getName(), "\n") && str_starts_with($email->getFrom()[0]->getName(), 'Webhely & Társa'), 'one line: ' . json_encode($email->getFrom()[0]->getName()));
    check(str_starts_with((string) $email->getSubject(), 'Teszt e-mail: ') && !str_contains((string) $email->getSubject(), '&amp;'), (string) $email->getSubject());
    $text = (string) $email->getTextBody();
    check(str_contains($text, 'Kedves Kiss & Nagy!') && str_contains($text, 'Elküldve: 2026-10-10 08:00:00 UTC') && !str_contains($text, '&amp;') && $email->getHtmlBody() === null, $text);
    check($email->getHeaders()->get('Auto-Submitted')?->getBodyAsString() === 'auto-generated');

    $failing = new $M(captureTransport('Could not connect to smtp://user:titok@mail.example.hu:25'), 'noreply@example.hu', static fn () => $env, static fn () => 'W', $db);
    $log = ini_set('error_log', '/dev/null');
    $failed = $failing->send('olvaso@example.hu', 'test', ['sent_at' => '']);
    $invalid = $mailer->send('nem-cím', 'test', ['sent_at' => '']);
    ini_set('error_log', (string) $log);
    check($failed->status === 'failed' && str_contains((string) $failed->error, 'smtp://***@mail.example.hu') && !str_contains((string) $failed->error, 'titok'), (string) $failed->error);
    $log = ini_set('error_log', '/dev/null');
    $next = $failing->send('masik@example.hu', 'test', ['sent_at' => '']);
    ini_set('error_log', (string) $log);
    check($next->status === 'failed' && str_starts_with((string) $next->error, 'not tried: the mail server failed earlier'), 'a failed server is not tried again in the request');
    $smtp = $M::fromConfig(['dsn' => 'smtp://127.0.0.1:2599', 'from' => 'noreply@example.hu', 'timeout' => 3], static fn () => $env, static fn () => 'W');
    $transportProperty = new \ReflectionProperty($M, 'transport');
    $stream = $transportProperty->getValue($smtp)->getStream();
    check($stream->getTimeout() === 3.0, 'the timeout: ' . $stream->getTimeout());
    check($invalid->status === 'failed' && count($transport->sent) === 1, 'an invalid address: a failure, not an exception');
    throws(\InvalidArgumentException::class, fn () => $mailer->send('a@b.hu', '../../config/local'));

    $entries = $mailer->log(10);
    check(count($entries) === 5 && $entries[0]['status'] === 'failed' && $entries[3]['status'] === 'sent' && $entries[3]['subject'] !== '', json_encode(array_column($entries, 'status')));
    // Old entries are removed on the next write.
    $db->insert('mail_log', ['created_at' => '2020-01-01 00:00:00', 'recipient' => 'regi@example.hu', 'template' => 'test', 'subject' => 'Régi', 'status' => 'sent', 'error' => null]);
    (new $M(null, '', static fn () => $env, static fn () => 'W', $db, 30))->send('a@b.hu', 'test');
    check((int) $db->fetchValue("SELECT COUNT(*) FROM {mail_log} WHERE recipient = 'regi@example.hu'") === 0, 'pruned');
    $db->execute('DELETE FROM {mail_log}');
});

test('MailAdministrators: the active administrators get the event, not the one who did it', function () use ($repository, $engine, $db, $newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $env = (new \Campanella\Core\Kernel(dirname(__DIR__)))->container()->get(\Twig\Environment::class);
    putenv('CAMPANELLA_DB_PREFIX');
    $transport = captureTransport();
    $mailer = new \Campanella\Mail\Mailer($transport, 'noreply@example.hu', static fn () => $env, static fn () => 'Webhely');
    $first = $newUser('mail-admin-1@example.hu', 'mail-admin-jelszo-1', ['administrator']);
    $second = $newUser('mail-admin-2@example.hu', 'mail-admin-jelszo-2', ['administrator']);
    $blocked = $newUser('mail-admin-3@example.hu', 'mail-admin-jelszo-3', ['administrator']);
    $blocked->as(Authenticatable::class)->block();
    $repository->save($blocked);
    $settings = new \Campanella\Settings\Settings($db);
    $settings->set(['site.url' => 'https://pelda.hu']);
    $action = new \Campanella\Event\Action\MailAdministrators($mailer, $engine, new \Campanella\Site\SiteSettings($settings, ['name' => 'Webhely']), new \Campanella\Admin\AdminAccess());
    $article = $repository->create('article', ['title' => 'Friss cikk']);
    $repository->save($article);
    $action->handle(new \Campanella\Event\ObjectPublished($article, new Actor(\Campanella\Access\ActorKind::User, (int) $first->id(), ['administrator'])));
    $to = array_map(static fn ($e) => $e->getTo()[0]->getAddress(), $transport->sent);
    check(in_array('mail-admin-2@example.hu', $to, true) && !in_array('mail-admin-1@example.hu', $to, true) && !in_array('mail-admin-3@example.hu', $to, true), json_encode($to));
    $mail = $transport->sent[array_search('mail-admin-2@example.hu', $to, true)];
    check(preg_match('/^\[.+\] Közzétéve: Friss cikk$/u', (string) $mail->getSubject()) === 1, (string) $mail->getSubject());
    check(str_contains((string) $mail->getTextBody(), 'Végezte: ') && str_contains((string) $mail->getTextBody(), 'mail-admin-1@example.hu') && str_contains((string) $mail->getTextBody(), 'https://pelda.hu/admin/article/' . $article->id()), (string) $mail->getTextBody());
    $settings->set(['site.url' => null]);
    foreach ([$first, $second, $blocked, $article] as $object) {
        $repository->delete($object);
    }
});

test('Kernel: the e-mail lines of the System page, a test e-mail to oneself, the log', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $container->get(Connection::class)->execute('DELETE FROM {mail_log}');
    $container->get(Connection::class)->execute("DELETE FROM {throttle}");
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';
    $auth = $container->get(AuthService::class);
    $auth->login(new Request('GET', '/'), $newUser('levelezo@example.hu', 'levelezo-jelszo-1', ['administrator']));

    $system = $send('GET', '/admin/system');
    check(str_contains($system->body, 'E-mail') && str_contains($system->body, 'nincs beállítva') && !str_contains($system->body, 'system/mail-test'), 'not set up: a warning, no button');
    check(str_contains($send('GET', '/admin/system/mail')->body, 'Még nem ment ki e-mail.'));

    // Set up (a new kernel: the System page's checks are made once).
    $transport = captureTransport();
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    $container->set(Session::class, static fn () => new Session($storage));
    $container->set(\Campanella\Mail\Mailer::class, static fn (\Campanella\Core\Container $c) => new \Campanella\Mail\Mailer($transport, 'noreply@example.hu', static fn () => $c->get(\Twig\Environment::class), static fn () => 'Teszt webhely', $c->get(Connection::class), 90, 'smtp://mail.example.hu:587'));
    $auth = $container->get(AuthService::class);
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $system = $send('GET', '/admin/system');
    check(str_contains($system->body, 'smtp://mail.example.hu:587') && str_contains($system->body, 'action="/admin/system/mail-test"') && str_contains($system->body, 'noreply@example.hu'), 'the button');
    check($send('GET', '/admin/system/mail-test')->status === 405);
    $sent = $send('POST', '/admin/system/mail-test', ['_csrf' => $csrfOf($system), 'to' => 'mas@example.hu']);
    check($sent->status === 303 && count($transport->sent) === 1 && $transport->sent[0]->getTo()[0]->getAddress() === 'levelezo@example.hu', 'only to one\'s own address');
    $page = $send('GET', '/admin/system');
    check(str_contains($page->body, 'A teszt e-mailt elküldtük ide: levelezo@example.hu') && str_contains($page->body, 'Legutóbbi e-mail'), 'the result and the latest e-mail');
    for ($i = 0; $i < 5; $i++) {
        $send('POST', '/admin/system/mail-test', ['_csrf' => $csrfOf($system)]);
    }
    check(count($transport->sent) === 5 && str_contains($send('GET', '/admin/system')->body, 'Túl sok teszt e-mail'), 'at most 5: ' . count($transport->sent));
    $log = $send('GET', '/admin/system/mail');
    check($log->status === 200 && str_contains($log->body, 'levelezo@example.hu') && str_contains($log->body, 'Teszt e-mail: ') && str_contains($log->body, 'elküldve'), 'the log');

    $auth->login(new Request('GET', '/'), $newUser('levelezo-szerk@example.hu', 'levelezo-jelszo-2', ['editor']));
    check($send('GET', '/admin/system/mail')->status === 403 && $send('POST', '/admin/system/mail-test', [])->status === 403, 'not for editors');
    $container->get(Connection::class)->execute('DELETE FROM {mail_log}');
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nLogin and sessions (0.1.4)\n";

test('SitePaths: English by default, configurable, checked', function (): void {
    $P = \Campanella\Http\SitePaths::class;
    $default = new $P();
    check($default->get('login') === '/login' && $default->get('logout') === '/logout' && $default->login('/admin/article?status=draft') === '/login?return=%2Fadmin%2Farticle%3Fstatus%3Ddraft');
    $hu = new $P(['login' => '/belepes/', 'logout' => 'kilepes']);
    check($hu->all() === ['login' => '/belepes', 'logout' => '/kilepes'], json_encode($hu->all()));
    foreach ([['bejelentkezes' => '/x'], ['login' => '/'], ['login' => '/a b'], ['login' => '/a?b'], ['login' => 42], ['login' => '/x', 'logout' => '/x']] as $bad) {
        throws(\InvalidArgumentException::class, fn () => new $P($bad));
    }
    throws(\InvalidArgumentException::class, fn () => $default->get('nincs'));
});

test('Kernel: logging in at a configured path, back to where one came from', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $config = $container->get(\Campanella\Core\Config::class);
    $container->set(\Campanella\Core\Config::class, static fn () => new \Campanella\Core\Config(['paths' => ['login' => '/belepes', 'logout' => '/kilepes']] + $config->all()));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $send = function (string $method, string $path, array $post = [], array $query = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, query: $query, post: $post));
    };
    $newUser('utvonal@example.hu', 'utvonal-jelszo-1', ['editor']);
    check($send('GET', '/login')->status === 404, 'the default path is free for content');
    $admin = $send('GET', '/admin/article');
    check(($admin->headers['Location'] ?? '') === '/belepes?return=%2Fadmin%2Farticle', (string) ($admin->headers['Location'] ?? ''));
    $evil = $send('GET', '/belepes', [], ['return' => '//evil.example']);
    check(str_contains($evil->body, 'name="return" value="/"'), 'only a path of this site');
    $form = $send('GET', '/belepes', [], ['return' => '/admin/article']);
    check($form->status === 200 && str_contains($form->body, 'action="/belepes"') && str_contains($form->body, 'name="return" value="/admin/article"'), 'the form');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $form->body, $m);
    $in = $send('POST', '/belepes', ['_csrf' => $m[1] ?? '', 'email' => 'utvonal@example.hu', 'password' => 'utvonal-jelszo-1', 'website' => '', 'return' => '/admin/article']);
    check($in->status === 303 && ($in->headers['Location'] ?? '') === '/admin/article', 'back to the list');
    $page = $send('GET', '/admin');
    check(str_contains($page->body, 'action="/kilepes"'), 'the logout form uses the configured path');
    check(str_contains($send('GET', '/robots.txt')->body, "Disallow: /belepes\nDisallow: /kilepes\n"));
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nDocumentation examples\n";

test('New capability as in the docs example (Featured)', function () use ($db, $admin): void {
    $registry = new CapabilityRegistry([Titled::class, Textual::class, Routable::class, Publishable::class, Featured::class]);
    $blueprints = new BlueprintRegistry($registry, [
        'page' => ['capabilities' => [Textual::class, Routable::class, Publishable::class, Featured::class]],
    ]);
    (new Installer($db, $registry))->install();
    $repository = new ObjectRepository($db, $registry, $blueprints);
    $engine = new QueryEngine($db, new QueryCompiler($registry), $repository, $registry, new DefaultPolicy());
    $service = new ObjectService($repository, new DefaultPolicy());

    foreach (['Kiemelt egy' => true, 'Sima' => false, 'Kiemelt kettő' => true] as $title => $featured) {
        $page = $service->create($admin, 'page', ['title' => $title]);
        $page->as(Featured::class)->feature($featured);
        $repository->save($page);
    }
    $titles = array_map(
        fn ($o) => $o->get('title'),
        $engine->execute(Query::objects()->having('featured')->scope('featured')->orderBy('title'), $admin)->items,
    );
    check($titles === ['Kiemelt egy', 'Kiemelt kettő'], implode(', ', $titles));
});

test('Weighted: a hand-set order; equal weights in the order of creation', function () use ($db, $admin): void {
    $registry = new CapabilityRegistry([Titled::class, \Campanella\Capability\Weighted::class]);
    $blueprints = new BlueprintRegistry($registry, ['item' => ['capabilities' => [Titled::class, \Campanella\Capability\Weighted::class]]]);
    (new Installer($db, $registry))->install();
    $repository = new ObjectRepository($db, $registry, $blueprints);
    $engine = new QueryEngine($db, new QueryCompiler($registry), $repository, $registry, new DefaultPolicy());
    foreach (['Harmadik' => 30, 'Első' => 10, 'Második A' => 20, 'Második B' => 20] as $title => $weight) {
        $item = $repository->create('item', ['title' => $title]);
        $item->as(\Campanella\Capability\Weighted::class)->setWeight($weight);
        $repository->save($item);
    }
    $plain = $repository->create('item', ['title' => 'Súly nélkül']);
    $repository->save($plain);
    check($plain->as(\Campanella\Capability\Weighted::class)->weight() === 0, 'the default is 0');
    $titles = array_map(fn ($o) => $o->get('title'), $engine->execute(Query::objects()->blueprint('item')->scope('by_weight'), $admin)->items);
    check($titles === ['Súly nélkül', 'Első', 'Második A', 'Második B', 'Harmadik'], implode(', ', $titles));
    check(in_array(\Campanella\Capability\Weighted::class, (array) Config::load(dirname(__DIR__) . '/config')->get('capabilities'), true), 'registered by default');
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'item'");
});

test('Kernel: an overridden service persists across requests', function (): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";

        return;
    }
    // Following docs/php-api/05-access.md: everything is visible.
    $container->set(\Campanella\Access\AccessPolicy::class, static fn () => new class implements \Campanella\Access\AccessPolicy {
        public function constrain(Query $query, Actor $actor): Query { return $query; }
        public function allows(Actor $actor, \Campanella\Access\Operation $operation, \Campanella\Model\CampanellaObject $object): bool { return true; }
    });

    $first = $kernel->handle(new Request('GET', '/idozitett', basePath: '/alkonyvtar'));
    $second = $kernel->handle(new Request('GET', '/idozitett'));
    check($first->status === 200 && $second->status === 200, "{$first->status} / {$second->status}");
    check(str_contains($first->body, 'href="/alkonyvtar/hirek"'), 'the first request is missing the URL prefix');
    check(str_contains($second->body, 'href="/hirek"') && !str_contains($second->body, '/alkonyvtar'), 'the second request has the wrong URL prefix');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: category page with its articles, article page with visible categories', function (): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    if ($kernel->container()->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";

        return;
    }
    $category = $kernel->handle(new Request('GET', '/kat-tudomany'));
    check($category->status === 200 && str_contains($category->body, 'Cikkek ebben a kategóriában'), (string) $category->status);
    check(str_contains($category->body, 'Kapcsolt cikk'), 'the category article is missing');

    $article = $kernel->handle(new Request('GET', '/kapcsolt-cikk'));
    check(str_contains($article->body, 'href="/kat-tudomany"') && str_contains($article->body, 'href="/kat-tortenelem"'));
    check(!str_contains($article->body, 'Kat Rejtett'), 'the draft category is visible');
    putenv('CAMPANELLA_DB_PREFIX');
});

// --- Cleanup ----------------------------------------------------------------

$dropAll();

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
