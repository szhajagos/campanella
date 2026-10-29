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
use Campanella\Auth\SessionInfo;
use Campanella\Auth\SessionRegistry;
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
    // The same answer as a wrong password (since 0.2.0): it must not tell that the password is right.
    check(!$again->success && $again->error === AuthService::GENERIC_ERROR, (string) $again->error);
});

test('Login counts an IPv6 address by its /48 for all accounts (0.2.0 review)', function () use ($repository, $engine, $db, $newUser): void {
    check(Throttle::network('2001:db8:1:2:3:4:5:6', 48) === '2001:db8:1::/48' && Throttle::network('2001:db8:1:2:3:4:5:6', 64) === '2001:db8:1:2::/64');
    check(Throttle::network('2001:db8:1:ff00::1', 56) === '2001:db8:1:ff00::/56' && Throttle::network('2001:db8:1:ffff::1', 52) === '2001:db8:1:f000::/52');
    check(Throttle::network('::ffff:192.0.2.7', 48) === '192.0.2.7' && Throttle::network('192.0.2.7') === '192.0.2.7' && Throttle::network('nem-ip') === 'nem-ip');
    check(AuthService::clientKey('2001:db8:1:2::9') === '2001:db8:1:2::/64' && \Campanella\Webform\ContactForm::networkOf('2001:db8:1:2::9') === '2001:db8:1::/48');

    $newUser('halozat@example.hu', 'halozat-jelszo-1');
    $auth = new AuthService($repository, $engine, new Session(new ArraySessionStorage(), 7200), new Throttle($db), new Csrf(new Session(new ArraySessionStorage(), 7200)), ['max_attempts' => 5, 'max_attempts_per_ip' => 3, 'decay_seconds' => 900]);
    $from = static fn (string $ip) => new Request('POST', '/login', post: ['x' => '1'], ip: $ip);
    foreach (['2001:db8:77:1::1', '2001:db8:77:2::1', '2001:db8:77:3::1'] as $i => $ip) {
        $auth->attempt($from($ip), "talalgatas{$i}@example.hu", 'rossz-jelszo-123');
    }
    // A fourth /64 of the same /48: refused, even with the right password.
    $refused = $auth->attempt($from('2001:db8:77:4::1'), 'halozat@example.hu', 'halozat-jelszo-1');
    check(!$refused->success && $refused->error === 'auth.too_many_attempts', 'another /64 of the same /48 is a fresh address: ' . $refused->error);
    // Another /48 is another address.
    check($auth->attempt($from('2001:db8:78::1'), 'halozat@example.hu', 'halozat-jelszo-1')->success, 'another /48 is refused too');
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

// --- Displays (0.3.3) ----------------------------------------------------------

echo "\nDisplays (0.3.3)\n";

/** For testing: a display that claims to be for objects and for a list at once. */
#[\Campanella\Display\AsDisplay('confused', requires: [Titled::class], many: true)]
final class ConfusedDisplay extends \Campanella\Display\Display
{
}

/** A kernel on the test tables, with its display renderer; null if config/local.php sets its own prefix. */
$displayKernel = static function (): ?\Campanella\Core\Kernel {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $prefix = $kernel->container()->get(Connection::class)->prefix();
    putenv('CAMPANELLA_DB_PREFIX');
    if ($prefix !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";

        return null;
    }

    return $kernel;
};

test('DisplayRegistry: the core\'s displays, which one shows what; a display declared wrong is refused', function () use ($repository): void {
    $registry = new \Campanella\Display\DisplayRegistry(\Campanella\Display\DisplayRegistry::BUILTIN, \Campanella\Display\DisplayRegistry::BUILTIN_SOURCES);
    $names = static fn (\Campanella\Display\Subject $s): array => array_map(static fn ($d): string => $d->name, $registry->for($s));
    $S = \Campanella\Display\Subject::class;

    $article = $repository->create('article', ['title' => 'Cikk', 'path' => '/cikk-display']);
    check($names($S::object($article)) === ['full', 'teaser', 'link', 'info_box'], implode(',', $names($S::object($article))));
    $menu = $repository->create('menu', ['title' => 'Fő', 'machine_name' => 'fo']);
    check(in_array('navigation', $names($S::object($menu)), true) && !in_array('link', $names($S::object($menu)), true));
    $category = $repository->create('category', ['title' => 'Kat']);
    check(!in_array('navigation', $names($S::object($category)), true), 'navigation is only for menus');
    $file = $repository->create('file', ['title' => 'Fájl']);
    check(in_array('download', $names($S::object($file)), true));
    $image = $repository->create('image', ['title' => 'Kép']);
    check(in_array('image', $names($S::object($image)), true) && !in_array('download', $names($S::object($image)), true));
    check($names($S::list([$article])) === ['cards', 'table']);
    check($names($S::system('visitor', Actor::anonymous())) === ['login_button', 'admin_button', 'login_form', 'user_card']);
    check($names($S::system('search', '')) === ['search_box'] && $names($S::system('site')) === ['branding'] && $names($S::system('breadcrumbs')) === ['breadcrumbs']);
    check(array_keys($registry->sources()) === ['visitor', 'search', 'site', 'breadcrumbs']);
    check(!$registry->accepts('nincs-ilyen', $S::object($article)), 'an unknown display accepts nothing');

    throws(\Campanella\Display\DisplayException::class, static fn () => new \Campanella\Display\DisplayRegistry([ConfusedDisplay::class]));
    throws(\Campanella\Display\DisplayException::class, static fn () => new \Campanella\Display\DisplayRegistry([\Campanella\Display\Builtin\FullDisplay::class, \Campanella\Display\Builtin\FullDisplay::class]));
    throws(\Campanella\Display\DisplayException::class, static fn () => new \Campanella\Display\DisplayRegistry([Titled::class]));
    throws(\Campanella\Display\DisplayException::class, static fn () => $registry->display('nincs-ilyen'));
    throws(\Campanella\Display\DisplayException::class, static fn () => $registry->source('nincs-ilyen'));
});

test('DisplayRenderer: sources written as stored; settings cast, defaults for the missing and invalid, problems for a form', function () use ($displayKernel): void {
    $R = \Campanella\Display\DisplayRenderer::class;
    check($R::parseSource('object:12') === ['object', '12'] && $R::parseSource('list:esemenyek') === ['list', 'esemenyek']);
    check($R::parseSource('current') === ['current', ''] && $R::parseSource('system:visitor') === ['system', 'visitor']);
    foreach (['object:0', 'object:-1', 'object:x', 'list:', 'list:Nagy', 'system:a b', 'curren', '', 'page:1', "list:a\n"] as $bad) {
        throws(\Campanella\Display\DisplayException::class, static fn () => $R::parseSource($bad));
    }

    $kernel = $displayKernel();
    if ($kernel === null) {
        return;
    }
    $renderer = $kernel->container()->get(\Campanella\Display\DisplayRenderer::class);
    check($renderer->settings('cards', []) === ['count' => 5, 'columns' => 1, 'show_more' => true]);
    check($renderer->settings('cards', ['count' => '3', 'columns' => 9, 'show_more' => '0', 'other' => 1]) === ['count' => 3, 'columns' => 1, 'show_more' => false], 'out of range: the default; unknown: left out');
    check($renderer->settings('cards', ['count' => 'sok', 'columns' => 1000])['count'] === 5);
    check($renderer->settings('navigation', ['style' => 'diagonal'])['style'] === 'horizontal', 'a choice not offered: the default');
    check($renderer->settings('link', ['text' => str_repeat('a', 101)])['text'] === null, 'too long: none');

    $checked = $renderer->check('cards', ['count' => '0', 'columns' => '2', 'show_more' => '1']);
    check(array_keys($checked['errors']) === ['count'] && $checked['settings']['columns'] === 2, json_encode(array_keys($checked['errors'])));
    $checked = $renderer->check('cards', ['count' => 'sok']);
    check(isset($checked['errors']['count']), 'not a number');
    $checked = $renderer->check('navigation', ['style' => 'diagonal', 'depth' => '2']);
    check(array_keys($checked['errors']) === ['style']);
    $checked = $renderer->check('image', ['width' => '8']);
    check(isset($checked['errors']['width']), 'too narrow');
    check($renderer->check('image', [])['errors'] === [] && $renderer->check('image', [])['settings']['caption'] === false, 'the defaults are valid');
    throws(\Campanella\Display\DisplayException::class, static fn () => $renderer->settings('nincs-ilyen', []));
});

test('DisplayRenderer: a source is read with the visitor\'s rights: a draft, a file for users, a list\'s hidden items', function () use ($displayKernel, $newUser): void {
    $kernel = $displayKernel();
    if ($kernel === null) {
        return;
    }
    $c = $kernel->container();
    $renderer = $c->get(\Campanella\Display\DisplayRenderer::class);
    $objects = $c->get(ObjectService::class);
    $system = Actor::system();
    $anonymous = new \Campanella\Display\DisplayContext(Actor::anonymous());
    $administrator = new \Campanella\Display\DisplayContext($system);

    $draft = $objects->create($system, 'article', ['title' => 'Komponens piszkozat', 'path' => '/komponens-piszkozat', 'body' => 'Titkos szöveg']);
    check($renderer->show('object:' . $draft->id(), 'teaser', [], $anonymous) === '', 'a draft is shown to an anonymous visitor');
    check(str_contains($renderer->show('object:' . $draft->id(), 'teaser', [], $administrator), 'Komponens piszkozat'));
    check($renderer->show('object:999999999', 'teaser', [], $administrator) === '', 'a deleted object shows nothing');

    $file = $objects->create($system, 'file', [
        'title' => 'Tagoknak szóló', 'download_path' => '2026/10/' . str_repeat('a', 24) . '.pdf', 'download_name' => 'tagoknak.pdf',
        'download_type' => 'application/pdf', 'download_size' => 2048, 'download_hash' => str_repeat('0', 64),
        'visible_to' => \Campanella\Capability\Restricted::USERS,
    ]);
    check($renderer->show('object:' . $file->id(), 'download', [], $anonymous) === '', 'a file for users is shown to an anonymous visitor');
    $user = $newUser('komponens-tag@example.hu', 'komponens-jelszo');
    $member = new \Campanella\Display\DisplayContext(\Campanella\Auth\AuthService::actorFor($user));
    $html = $renderer->show('object:' . $file->id(), 'download', [], $member);
    check(str_contains($html, 'href="/files/' . $file->id() . '/tagoknak.pdf"') && str_contains($html, '(PDF, 2 kB)'), $html);
    check(!str_contains($renderer->show('object:' . $file->id(), 'download', ['show_details' => false], $member), 'PDF,'), 'the details are not left out');
    throws(\Campanella\Display\DisplayException::class, static fn () => $renderer->show('object:' . $file->id(), 'navigation', [], $member));
    throws(\Campanella\Display\DisplayException::class, static fn () => $renderer->show('system:nincs', 'branding', [], $member));

    // A list made in the admin: only what the visitor may see, at most `count`.
    $c->get(\Campanella\Lists\ListStore::class)->save('komponens_cikkek', \Campanella\Lists\ListDefinition::fromArray('komponens_cikkek', [
        'version' => 1, 'title' => 'Komponens cikkek', 'blueprint' => 'article', 'sort' => [['field' => 'title', 'dir' => 'asc']], 'per_page' => 20, 'display' => 'teaser', 'path' => '/komponens-cikkek',
    ], $c->get(BlueprintRegistry::class))->toArray());
    $kernel = $displayKernel(); // the registry reads the lists again
    $renderer = $kernel->container()->get(\Campanella\Display\DisplayRenderer::class);
    $html = $renderer->show('list:komponens_cikkek', 'cards', ['count' => 50], $anonymous);
    check(str_contains($html, 'object--teaser') && !str_contains($html, 'Komponens piszkozat'), 'the draft is in the list for an anonymous visitor');
    check(str_contains($html, 'href="/komponens-cikkek"'), 'the link to the list\'s page is missing');
    check(substr_count($renderer->show('list:komponens_cikkek', 'cards', ['count' => 1, 'show_more' => false], $administrator), 'object--teaser') === 1, 'count is not kept');
    check(str_contains($renderer->show('list:komponens_cikkek', 'cards', ['count' => 2, 'columns' => 3], $administrator), 'row-cols-md-3'));
    check(str_contains($renderer->show('list:komponens_cikkek', 'table', [], $administrator), '<table'));
    check($renderer->show('list:nincs_ilyen', 'cards', [], $administrator) === '', 'an unknown list shows nothing');
    check($renderer->show('current', 'full', [], $anonymous) === '', 'no page object, nothing');
    $current = new \Campanella\Display\DisplayContext(Actor::anonymous(), '/x', $draft);
    check(str_contains($renderer->show('current', 'link', ['text' => 'Ide <b>'], $current), '>Ide &lt;b&gt;</a>'), 'the link text is not escaped');
});

test('Displays: render_object() is the display, as before; the snippet in an info box; an image; display() in a template', function () use ($displayKernel): void {
    $kernel = $displayKernel();
    if ($kernel === null) {
        return;
    }
    $c = $kernel->container();
    $system = Actor::system();
    $objects = $c->get(ObjectService::class);
    $article = $objects->create($system, 'article', ['title' => 'Megjelenített cikk', 'path' => '/megjelenitett', 'lead' => 'A bevezető', 'body' => 'A szöveg'], publish: true);
    // The page's object, for the `current` source: set on its page, forgotten on the next request.
    check($kernel->handle(new Request('GET', '/megjelenitett'))->status === 200);
    check($c->get(\Campanella\Display\CurrentPage::class)->object()?->id() === $article->id(), 'the page object is not set');
    $kernel->handle(new Request('GET', '/nincs-ilyen-oldal'));
    check($c->get(\Campanella\Display\CurrentPage::class)->object() === null, 'the page object is kept for the next request');
    $presentation = $c->get(\Campanella\View\Presentation::class);
    $twig = $c->get(\Twig\Environment::class);
    $render = static fn (string $template, array $context = []): string => $twig->createTemplate($template)->render($context);

    foreach (['teaser', 'full'] as $mode) {
        check($render("{{ render_object(a, '{$mode}') }}", ['a' => $article]) === $presentation->renderObject($article, $mode), "render_object({$mode}) changed");
    }
    $teaser = $render("{{ display(a, 'teaser', {show_lead: false, show_date: false}) }}", ['a' => $article]);
    check(str_contains($teaser, 'Megjelenített cikk') && !str_contains($teaser, 'A bevezető') && !str_contains($teaser, '<time'), $teaser);
    check(!str_contains($render("{{ display(a, 'full', {show_title: false}) }}", ['a' => $article]), '<h1'), 'the title is shown');

    $snippet = $objects->create($system, 'snippet', ['title' => 'Nyitvatartás', 'body' => '<p>Hétfőtől péntekig</p>']);
    check(!$snippet->has(Routable::class) && $snippet->as(Textual::class)->format() === TextFormat::Html, 'a snippet has no page; its text is formatted');
    $box = $render("{{ display(s, 'info_box', {style: 'warning'}) }}", ['s' => $snippet]);
    check(str_contains($box, 'bg-warning-subtle') && str_contains($box, '<h2 class="h5" id="display-') && str_contains($box, '<p>Hétfőtől péntekig</p>'), $box);
    check(str_contains($render("{{ display(s, 'info_box') }}", ['s' => $snippet]), 'bg-body-tertiary'));

    $image = $c->get(ObjectRepository::class)->create('image', ['title' => 'Kép címe', 'alt' => 'Egy "kép"', 'file_path' => '2026/10/' . str_repeat('b', 24) . '.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 10, 'width' => 800, 'height' => 600]);
    $html = $render("{{ display(i, 'image', {caption: true}) }}", ['i' => $image]);
    check(str_contains($html, 'src="/media/2026/10/' . str_repeat('b', 24) . '.jpg"') && str_contains($html, 'alt="Egy &quot;kép&quot;"') && str_contains($html, '<figcaption class="figure-caption">Kép címe</figcaption>'), $html);
    check(str_contains($render("{{ display(i, 'image', {width: 300}) }}", ['i' => $image]), 'width="300"'));

    // A list of objects given by the template, e.g. a query's result.
    check(substr_count($render("{{ display([a, a, a], 'cards', {count: 2}) }}", ['a' => $article]), 'object--teaser') === 2);
    if (!$twig->isDebug()) {
        check($render("{{ display(a, 'nincs_ilyen') }}|{{ display(a, 'download') }}|{{ display('rossz', 'teaser') }}", ['a' => $article]) === '||', 'an error is not left out');
    }
    $twig->enableDebug();
    throws(\Twig\Error\RuntimeError::class, static fn () => $render("{{ display(a, 'download') }}", ['a' => $article]));
    $twig->disableDebug();
});

test('Displays: the visitor\'s (login, admin, form, card), the search box, the site\'s name, the breadcrumbs, a menu', function () use ($displayKernel, $newUser): void {
    $kernel = $displayKernel();
    if ($kernel === null) {
        return;
    }
    $c = $kernel->container();
    $c->set(Session::class, static fn () => new Session(new ArraySessionStorage()));
    $renderer = $c->get(\Campanella\Display\DisplayRenderer::class);
    $anonymous = new \Campanella\Display\DisplayContext(Actor::anonymous(), '/hirek/egy cikk');
    $user = $newUser('komponens-szerkeszto@example.hu', 'komponens-jelszo', ['editor']);
    $editor = new \Campanella\Display\DisplayContext(\Campanella\Auth\AuthService::actorFor($user), '/', loadUser: static fn () => $user);
    $memberUser = $newUser('komponens-latogato@example.hu', 'komponens-jelszo');
    $member = new \Campanella\Display\DisplayContext(\Campanella\Auth\AuthService::actorFor($memberUser), '/', loadUser: static fn () => $memberUser);

    $button = $renderer->show('system:visitor', 'login_button', [], $anonymous);
    check(str_contains($button, 'href="/login?return=%2Fhirek%2Fegy%20cikk"'), $button);
    check(str_contains($renderer->show('system:visitor', 'login_button', [], $editor), 'action="/logout"'));
    check($renderer->show('system:visitor', 'admin_button', [], $anonymous) === '' && $renderer->show('system:visitor', 'admin_button', [], $member) === '', 'the admin button for one who may not enter');
    check(str_contains($renderer->show('system:visitor', 'admin_button', [], $editor), 'href="/admin"'));
    $form = $renderer->show('system:visitor', 'login_form', [], $anonymous);
    check(str_contains($form, 'name="_csrf"') && str_contains($form, 'name="website"') && str_contains($form, 'name="return" value="/hirek/egy cikk"') && str_contains($form, 'id="display-'), $form);
    check($renderer->show('system:visitor', 'login_form', [], $member) === '', 'the login form for a logged-in visitor');
    check($renderer->show('system:visitor', 'user_card', [], $anonymous) === '');
    $card = $renderer->show('system:visitor', 'user_card', [], $member);
    check(str_contains($card, 'Teszt komponens-latogato@example.hu') && str_contains($card, 'action="/logout"') && !str_contains($card, 'href="/admin"'), $card);

    $search = new \Campanella\Display\DisplayContext(Actor::anonymous(), '/kereses', search: '"><script>');
    $box = $renderer->show('system:search', 'search_box', ['placeholder' => 'Mit keresel?'], $search);
    check(str_contains($box, 'value="&quot;&gt;&lt;script&gt;"') && str_contains($box, 'placeholder="Mit keresel?"'), $box);
    check(str_contains($renderer->show('system:search', 'search_box', [], $anonymous), 'placeholder="Keresés…"'));
    check(str_contains($renderer->show('system:site', 'branding', [], $anonymous), 'display-branding__name'));

    // Breadcrumbs: the ancestors the visitor may see, and the page.
    $system = Actor::system();
    $objects = $c->get(ObjectService::class);
    $top = $objects->create($system, 'category', ['title' => 'Felső kat', 'path' => '/felso-kat'], publish: true);
    $hidden = $objects->create($system, 'category', ['title' => 'Rejtett kat', 'path' => '/rejtett-kat'], relations: ['parent' => [$top]]);
    $leaf = $objects->create($system, 'category', ['title' => 'Alsó kat', 'path' => '/also-kat'], publish: true, relations: ['parent' => [$hidden]]);
    $crumbs = $renderer->show('system:breadcrumbs', 'breadcrumbs', [], new \Campanella\Display\DisplayContext(Actor::anonymous(), '/also-kat', $leaf));
    check(str_contains($crumbs, 'href="/felso-kat"') && !str_contains($crumbs, 'Rejtett kat') && str_contains($crumbs, 'aria-current="page">Alsó kat') && str_contains($crumbs, 'href="/"'), $crumbs);
    check(!str_contains($renderer->show('system:breadcrumbs', 'breadcrumbs', ['show_home' => false], new \Campanella\Display\DisplayContext(Actor::anonymous(), '/also-kat', $leaf)), 'href="/"'));
    check($renderer->show('system:breadcrumbs', 'breadcrumbs', [], $anonymous) === '', 'breadcrumbs without a page object');

    // A menu: the items the visitor may see, as a bar or a list.
    $menu = $objects->create($system, 'menu', ['title' => 'Lábléc', 'machine_name' => 'komponens_lablec']);
    $first = $objects->create($system, 'menu_item', ['title' => 'Első pont', 'url' => '/elso'], relations: ['menu' => [$menu]]);
    $objects->create($system, 'menu_item', ['title' => 'Alpont', 'url' => '/alpont'], relations: ['menu' => [$menu], 'parent' => [$first]]);
    $objects->create($system, 'menu_item', ['title' => 'Rejtett cél'], relations: ['menu' => [$menu], 'target' => [$hidden]]);
    $bar = $renderer->show('object:' . $menu->id(), 'navigation', [], new \Campanella\Display\DisplayContext(Actor::anonymous(), '/alpont'));
    check(str_contains($bar, 'dropdown-toggle active') && str_contains($bar, 'aria-current="page">Alpont') && !str_contains($bar, 'Rejtett cél'), $bar);
    $list = $renderer->show('object:' . $menu->id(), 'navigation', ['style' => 'vertical', 'depth' => 1], $anonymous);
    check(str_contains($list, 'nav flex-column') && str_contains($list, 'Első pont') && !str_contains($list, 'Alpont'), $list);
    $empty = $objects->create($system, 'menu', ['title' => 'Üres', 'machine_name' => 'komponens_ures']);
    check($renderer->show('object:' . $empty->id(), 'navigation', [], $anonymous) === '', 'an empty menu shows a nav');
});

test('AdminMenu: the groups and their links, by who looks; a module\'s links', function () use ($displayKernel, $newUser): void {
    $kernel = $displayKernel();
    if ($kernel === null) {
        return;
    }
    $c = $kernel->container();
    $menu = $c->get(\Campanella\Admin\AdminMenu::class);
    $names = static fn (array $built): array => array_map(static fn (array $g): string => $g['name'], $built);
    $items = static fn (array $built, string $group): array => array_map(static fn ($i): string => $i->name, array_values(array_filter($built, static fn (array $g): bool => $g['name'] === $group))[0]['items'] ?? []);

    $administrator = \Campanella\Auth\AuthService::actorFor($newUser('adminmenu-admin@example.hu', 'menu-jelszo-1', ['administrator']));
    $editor = \Campanella\Auth\AuthService::actorFor($newUser('adminmenu-szerk@example.hu', 'menu-jelszo-1', ['editor']));
    $full = $menu->build($administrator);
    check($names($full) === ['content', 'media', 'audience', 'appearance', 'structure', 'system'], implode(',', $names($full)));
    $content = $items($full, 'content');
    check(in_array('article', $content, true) && in_array('snippet', $content, true) && !array_intersect(['user', 'submission', 'component', 'image', 'file'], $content), implode(',', $content));
    check($items($full, 'media') === ['media', 'image', 'file'] && $items($full, 'audience') === ['submission', 'user']);
    check($items($full, 'appearance') === ['component'] && $items($full, 'structure') === ['blueprints', 'capabilities', 'lists', 'model']);
    check($items($full, 'system') === ['system', 'settings', 'mail', 'importers', 'redirects']);
    check($names($menu->build($editor)) === ['content', 'media'], 'an editor sees the system groups: ' . implode(',', $names($menu->build($editor))));

    $menu->add(\Campanella\Admin\AdminMenu::SYSTEM, static fn (Actor $actor): array => [new \Campanella\Admin\AdminMenuItem('backup', 'Mentések', 'system/backup')]);
    $menu->group('shop', 'Bolt');
    check(array_slice($items($menu->build($administrator), 'system'), -1) === ['backup'] && $menu->groups()[6] === 'shop', 'a module\'s link or group');
    check($names($menu->build($administrator)) === $names($full), 'an empty group is shown');
    throws(\InvalidArgumentException::class, static fn () => $menu->add('nincs', static fn (): array => []));
});

test('Components: checked on save, administrators only, shown by machine name with the visitor\'s rights, marked when they stop working', function () use ($displayKernel, $newUser): void {
    $kernel = $displayKernel();
    if ($kernel === null) {
        return;
    }
    $c = $kernel->container();
    $components = $c->get(\Campanella\Display\ComponentService::class);
    $objects = $c->get(ObjectService::class);
    $system = Actor::system();
    $editor = \Campanella\Auth\AuthService::actorFor($newUser('komp-szerk@example.hu', 'komp-jelszo-1', ['editor']));
    $anonymous = new \Campanella\Display\DisplayContext(Actor::anonymous());

    $menu = $objects->create($system, 'menu', ['title' => 'Komponens menü', 'machine_name' => 'komp_menu']);
    $item = $objects->create($system, 'menu_item', ['title' => 'Komp pont', 'url' => '/komp-pont'], relations: ['menu' => [$menu]]);
    $snippet = $objects->create($system, 'snippet', ['title' => 'Nyitva', 'body' => '<p>Mindig</p>']);

    check(array_map(static fn ($d): string => $d->name, $components->displaysFor('object:' . $menu->id())) === ['full', 'teaser', 'info_box', 'navigation']);
    check(array_map(static fn ($d): string => $d->name, $components->displaysFor('system:visitor')) === ['login_button', 'admin_button', 'login_form', 'user_card']);
    check(in_array('download', array_map(static fn ($d): string => $d->name, $components->displaysFor('current')), true), 'current: every display for objects');
    check($components->displaysFor('object:999999999') === [] && $components->displaysFor('list:nincs') === [] && $components->displaysFor('rossz') === []);

    $checked = $components->check('object:' . $snippet->id(), 'navigation', []);
    check(array_keys($checked['errors']) === ['display'], 'a display that cannot show the source');
    check(array_keys($components->check('object:999999999', 'teaser', [])['errors']) === ['source']);
    check(array_keys($components->check('system:visitor', 'login_form', ['show_reset' => 'igen'])['errors']) === [], 'a yes/no is cast');
    throws(ValidationException::class, static fn () => $components->create($system, ['title' => 'Rossz', 'machine_name' => 'komp_rossz'], 'object:' . $menu->id(), 'navigation', ['depth' => '9']));

    $nav = $components->create($system, ['title' => 'Lábléc menü', 'machine_name' => 'komp_lablec'], 'object:' . $menu->id(), 'navigation', ['style' => 'vertical', 'depth' => '1']);
    check($nav->as(\Campanella\Capability\Component::class)->settings() === ['style' => 'vertical', 'depth' => 1], json_encode($nav->as(\Campanella\Capability\Component::class)->settings()));
    check($components->problem($nav) === null);
    check(str_contains($components->show('komp_lablec', $anonymous), 'Komp pont') && str_contains($components->show('komp_lablec', $anonymous), 'nav flex-column'));
    check($components->show('nincs_ilyen', $anonymous) === '' && $components->show('Rossz Név', $anonymous) === '');

    // Who sees it: its own Restricted setting.
    $box = $components->create($system, ['title' => 'Tagoknak', 'machine_name' => 'komp_tagoknak', 'visible_to' => \Campanella\Capability\Restricted::USERS], 'object:' . $snippet->id(), 'info_box', []);
    check($components->show('komp_tagoknak', $anonymous) === '', 'a component for users is shown to an anonymous visitor');
    check(str_contains($components->show('komp_tagoknak', new \Campanella\Display\DisplayContext($editor)), '<p>Mindig</p>'));

    // Administrators only: an editor may see one, not make or change it.
    $policy = $c->get(\Campanella\Access\AccessPolicy::class);
    check($policy->allows($editor, \Campanella\Access\Operation::View, $nav) && !$policy->allows($editor, \Campanella\Access\Operation::Update, $nav) && !$policy->allows($editor, \Campanella\Access\Operation::Delete, $nav));
    throws(\Campanella\Access\AccessDeniedException::class, static fn () => $components->create($editor, ['title' => 'Szerk', 'machine_name' => 'komp_szerk'], 'system:visitor', 'login_button'));

    // The capability checks the stored form, whoever saves it.
    throws(ValidationException::class, static fn () => $objects->create($system, 'component', ['title' => 'Kézi', 'machine_name' => 'komp_kezi', 'component_source' => 'object:abc', 'component_display' => 'Teaser!', 'component_settings' => '[1']));

    // A component whose source is gone shows nothing and says why.
    $objects->delete($system, $item);
    $objects->delete($system, $menu);
    check($components->problem($nav)?->key === 'components.source_missing' && $components->show('komp_lablec', $anonymous) === '');
    $components->update($system, $nav, ['title' => 'Lábléc gomb'], 'system:visitor', 'login_button', []);
    check($components->problem($nav) === null && str_contains($components->show('komp_lablec', $anonymous), '/login'), 'changed to another source');
    // A site whose `component` Blueprint was made in the admin before 0.3.3: no components.
    $theirs = new \Campanella\Display\ComponentService(
        $c->get(\Campanella\Display\DisplayRenderer::class), $objects, $c->get(ObjectRepository::class), $c->get(QueryEngine::class), null,
        new BlueprintRegistry($c->get(CapabilityRegistry::class), ['component' => ['capabilities' => [Titled::class]]]),
    );
    check($components->isAvailable() && !$theirs->isAvailable() && $theirs->find('komp_lablec', $system) === null);
    throws(\Campanella\Display\DisplayException::class, static fn () => $theirs->create($system, ['title' => 'X', 'machine_name' => 'komp_x'], 'system:visitor', 'login_button'));
    $twig = $c->get(\Twig\Environment::class);
    check(str_contains($twig->createTemplate("{{ component('komp_lablec') }}|{{ component('nincs') }}|")->render(), '/login') && str_ends_with($twig->createTemplate("{{ component('nincs') }}|")->render(), '|'));
});

test('Kernel: the components in the admin: the three steps, saving, the list, deleting; only for administrators', function () use ($displayKernel, $newUser): void {
    $kernel = $displayKernel();
    if ($kernel === null) {
        return;
    }
    $c = $kernel->container();
    $storage = new ArraySessionStorage();
    $c->set(Session::class, static fn () => new Session($storage));
    $auth = $c->get(AuthService::class);
    $send = function (string $method, string $path, array $post = []) use ($kernel, $storage) {
        $storage->endRequest();
        [$path, $query] = explode('?', $path, 2) + [1 => ''];
        parse_str($query, $params);

        return $kernel->handle(new Request($method, $path, query: $params, post: $post));
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';

    $auth->login(new Request('GET', '/'), $newUser('komp-admin-szerk@example.hu', 'komp-jelszo-1', ['editor']));
    $page = $send('GET', '/admin');
    check($page->status === 200 && str_contains($page->body, 'admin-menu-group--content') && !str_contains($page->body, 'admin-menu-group--appearance'), 'the editor\'s menu');
    check($send('GET', '/admin/component')->status === 403 && $send('GET', '/admin/component/new/source')->status === 403, 'the components for an editor');
    check($send('GET', '/admin/component/new')->status === 403);

    $auth->login(new Request('GET', '/'), $newUser('komp-admin@example.hu', 'komp-jelszo-1', ['administrator']));
    $page = $send('GET', '/admin/component');
    check($page->status === 200 && str_contains($page->body, 'admin-menu-group--appearance') && str_contains($page->body, 'href="/admin/component" aria-current="page">Komponensek'), 'the list, marked in the menu');
    check(preg_match('#Megjelenés</li>.*Komponensek.*Szerkezet</li>.*Blueprintek.*Rendszer</li>.*Állapot#s', $page->body) === 1, 'the groups in their order');
    check($send('GET', '/admin/component/new')->status === 303, 'the form without a source: to the first step');

    $step1 = $send('GET', '/admin/component/new/source?blueprint=snippet&q=Nyit');
    check($step1->status === 200 && str_contains($step1->body, 'source=system%3Avisitor') && str_contains($step1->body, 'source=current') && str_contains($step1->body, 'Nyitva'), 'the sources');
    $step2 = $send('GET', '/admin/component/new/display?source=system:visitor');
    check($step2->status === 200 && str_contains($step2->body, 'display=login_form') && !str_contains($step2->body, 'display=teaser'), 'the displays of the source');
    check($send('GET', '/admin/component/new/display?source=rossz')->status === 404);
    $form = $send('GET', '/admin/component/new?source=system:visitor&display=login_form');
    check($form->status === 200 && str_contains($form->body, 'name="s[show_reset]"') && str_contains($form->body, 'name="f[machine_name]"') && str_contains($form->body, 'name="f[visible_to]"') && !str_contains($form->body, 'f[component_source]'), 'the form');

    $post = ['_csrf' => $csrfOf($form), 'source' => 'system:visitor', 'display' => 'login_form', 'f' => ['title' => 'Belépés oldalt', 'machine_name' => 'oldalso_belepes', 'visible_to' => 'public'], 's' => ['show_reset' => '0']];
    check($send('POST', '/admin/component/new', ['_csrf' => 'x'] + $post)->status === 400, 'without the CSRF token');
    $bad = $send('POST', '/admin/component/new', ['display' => 'navigation'] + $post);
    check($bad->status === 422 && str_contains($bad->body, 'ez a megjelenítés nem tudja megmutatni ezt a forrást'), 'a display that does not fit');
    $saved = $send('POST', '/admin/component/new', $post);
    check($saved->status === 303 && ($saved->headers['Location'] ?? '') === '/admin/component', (string) $saved->status);
    $component = $c->get(\Campanella\Display\ComponentService::class)->find('oldalso_belepes', Actor::system());
    check($component !== null && $component->as(\Campanella\Capability\Component::class)->settings() === ['show_reset' => false]);
    check(str_contains($send('GET', '/admin/component')->body, '{{ component(&#039;oldalso_belepes&#039;) }}'));

    $edit = $send('GET', '/admin/component/' . $component->id());
    check($edit->status === 200 && str_contains($edit->body, 'value="Belépés oldalt"') && str_contains($edit->body, 'id="setting-show_reset"'));
    $changed = $send('POST', '/admin/component/' . $component->id(), ['_csrf' => $csrfOf($edit), 'source' => 'system:visitor', 'display' => 'login_button', 'f' => ['title' => 'Belépés gomb', 'machine_name' => 'oldalso_belepes', 'visible_to' => 'public']]);
    check($changed->status === 303 && $c->get(\Campanella\Display\ComponentService::class)->find('oldalso_belepes', Actor::system())?->as(\Campanella\Capability\Component::class)->display() === 'login_button', 'changed display');

    $delete = $send('GET', '/admin/component/' . $component->id() . '/delete');
    check($delete->status === 200 && str_contains($delete->body, 'component(&#039;oldalso_belepes&#039;)'));
    check($send('POST', '/admin/component/' . $component->id() . '/delete', ['_csrf' => $csrfOf($delete)])->status === 303);
    check($c->get(\Campanella\Display\ComponentService::class)->find('oldalso_belepes', Actor::system()) === null, 'not deleted');
    check($send('GET', '/admin/component/' . $component->id())->status === 404 && $send('GET', '/admin/component/x')->status === 404);
    check($send('GET', '/admin/component/new/bogus')->status === 404);
});

// --- Layouts and regions (0.3.4) -----------------------------------------------

echo "\nLayouts and regions (0.3.4)\n";

test('Conditions: paths with a wildcard, Blueprints, the front page, except; their problems', function () use ($repository): void {
    $C = \Campanella\Layout\Conditions::class;
    $article = $repository->create('article', ['title' => 'Feltétel']);
    check($C::everywhere()->matches('/akarmi') && $C::everywhere()->isEverywhere());
    $news = $C::fromArray(['paths' => ['/Hirek/*', ' /rolunk ', '/rolunk', ''], 'except' => ['/hirek/archivum*']]);
    check($news->paths === ['/Hirek/*', '/rolunk'], json_encode($news->paths));
    check($news->matches('/hirek/egy-cikk') && $news->matches('/HIREK/Masik/') && $news->matches('/rolunk'));
    check(!$news->matches('/hirek') && !$news->matches('/hirek/archivum/2025') && !$news->matches('/kapcsolat'), 'a wildcard or except is wrong');
    $front = new $C(front: true, blueprints: ['article']);
    check($front->matches('/') && $front->matches('/index.php') && $front->matches('/barhol', $article) && !$front->matches('/barhol'));
    check($C::pathMatches('/a.b/*', '/a.b/c') && !$C::pathMatches('/a.b/*', '/axb/c'), 'a dot is not a wildcard');
    check($C::fromArray($news->toArray()) == $news && $C::fromArray(['front' => 'igen'])->front === false);
    check($C::fromText("/a\n\n /b \r\n/a", ['article'], true, "/c")->toArray() === ['paths' => ['/a', '/b'], 'blueprints' => ['article'], 'front' => true, 'except' => ['/c']]);
    check(array_keys((new $C(paths: ['hirek'], except: ['/a b']))->problems()) === ['paths', 'except']);
    check(array_keys((new $C(blueprints: ['nincs_ilyen']))->problems(['article'])) === ['blueprints'] && (new $C(blueprints: ['article']))->problems(['article']) === []);
    check(isset((new $C(paths: array_map(static fn (int $i): string => '/p' . $i, range(1, 21))))->problems()['paths']), 'too many');
});

test('Layouts: from JSON beside their templates, the core\'s two, a theme\'s own and its override; the invalid ones left out', function (): void {
    $L = \Campanella\Layout\LayoutDefinition::class;
    $core = new \Campanella\Layout\LayoutRegistry([dirname(__DIR__) . '/templates']);
    check(array_keys($core->all()) === ['one_column', 'sidebar'] && $core->errors() === [], implode(',', array_keys($core->all())) . json_encode($core->errors()));
    check(array_keys($core->get('sidebar')->regions) === ['header', 'navigation', 'content', 'sidebar', 'footer'] && $core->get('sidebar')->template() === 'layout/sidebar.html.twig');
    check($core->get('one_column')->regions['navigation']->small === 'collapse' && $core->get('one_column')->regions['navigation']->breakpoint === 'md');
    check($core->get('nincs_ilyen')->name === 'one_column', 'an unknown layout: the default');
    check($core->regionNames() === ['header', 'navigation', 'content', 'footer', 'sidebar']);

    foreach ([
        '{"version": 2, "regions": {"content": {}}}', '{"version": 1, "regions": {"header": {}}}', '{"version": 1, "regions": []}',
        '{"version": 1, "regions": {"content": {"small": "sometimes"}}}', '{"version": 1, "regions": {"content": {"breakpoint": "xxl"}}}',
        '{"version": 1, "regions": {"Content": {}}}', 'nem json', '{"version": 1, "label": "", "regions": {"content": {}}}',
    ] as $json) {
        throws(\InvalidArgumentException::class, static fn () => $L::fromJson('proba', $json));
    }
    throws(\InvalidArgumentException::class, static fn () => $L::fromJson('Rossz-Nev', '{"version": 1, "regions": {"content": {}}}'));

    $theme = sys_get_temp_dir() . '/campanella-layouts-' . bin2hex(random_bytes(4));
    mkdir($theme . '/layout', 0777, true);
    file_put_contents($theme . '/layout/sidebar.json', '{"version": 1, "label": "Saját oldalsáv", "regions": {"content": {}, "aside": {"label": "Mellék", "small": "hide", "breakpoint": "lg"}}}');
    file_put_contents($theme . '/layout/sidebar.html.twig', '{{ main }}');
    file_put_contents($theme . '/layout/landing.json', '{"version": 1, "label": "Nyitóoldal", "regions": {"hero": {}, "content": {}}}');
    file_put_contents($theme . '/layout/landing.html.twig', '{{ region("hero") }}{{ main }}');
    file_put_contents($theme . '/layout/broken.json', '{"version": 1, "regions": {"content": {}}}');
    $registry = new \Campanella\Layout\LayoutRegistry([dirname(__DIR__) . '/templates', $theme]);
    check(array_keys($registry->all()) === ['one_column', 'sidebar', 'landing'] && $registry->get('sidebar')->label === 'Saját oldalsáv' && $registry->get('sidebar')->origin === 'theme');
    check(isset($registry->errors()['broken']) && str_contains($registry->errors()['broken'], 'template'), json_encode($registry->errors()));
    check($registry->get('sidebar')->regions['aside']->small === 'hide');
    array_map('unlink', glob($theme . '/layout/*') ?: []);
    rmdir($theme . '/layout');
    rmdir($theme);
});

test('LayoutService: the site\'s layout, a Blueprint\'s own, the regions\' looks as saved; on only when enabled', function () use ($db, $repository): void {
    $settings = new \Campanella\Settings\Settings($db);
    $service = new \Campanella\Layout\LayoutService(new \Campanella\Layout\LayoutRegistry([dirname(__DIR__) . '/templates']), $settings);
    $settings->set(['layout.enabled' => null, 'layout.site' => null, 'layout.blueprint.article' => null, 'layout.region.sidebar.navigation' => null]);
    check(!$service->isEnabled() && $service->site()->name === 'one_column' && $service->forBlueprint('article')->name === 'one_column');
    $settings->set(['layout.enabled' => '1', 'layout.blueprint.article' => 'sidebar', 'layout.region.sidebar.navigation' => 'hide:lg']);
    $settings->reset();
    check($service->isEnabled() && $service->forPage($repository->create('article', ['title' => 'x']))->name === 'sidebar' && $service->forPage(null)->name === 'one_column');
    check($service->blueprintChoice('article') === 'sidebar' && $service->blueprintChoice('page') === null);
    $navigation = $service->forBlueprint('article')->regions['navigation'];
    check($navigation->small === 'hide' && $navigation->breakpoint === 'lg', 'the saved look');
    $settings->set(['layout.region.sidebar.navigation' => 'sometimes:md', 'layout.site' => 'nincs_ilyen']);
    $settings->reset();
    check($service->forBlueprint('article')->regions['navigation']->small === 'collapse' && $service->site()->name === 'one_column', 'an invalid setting is ignored');
    $settings->set(['layout.enabled' => null, 'layout.site' => null, 'layout.blueprint.article' => null, 'layout.region.sidebar.navigation' => null]);
});

test('Kernel: a page in its layout: the regions\' components in their order, where they show, for whom; the old header until enabled', function () use ($displayKernel, $newUser): void {
    $kernel = $displayKernel();
    if ($kernel === null) {
        return;
    }
    $c = $kernel->container();
    $system = Actor::system();
    $objects = $c->get(ObjectService::class);
    $components = $c->get(\Campanella\Display\ComponentService::class);
    $settings = $c->get(\Campanella\Settings\Settings::class);
    $C = \Campanella\Layout\Conditions::class;

    $article = $objects->create($system, 'article', ['title' => 'Elrendezett cikk', 'path' => '/elrendezett-cikk', 'body' => 'A cikk szövege'], publish: true);
    $snippet = $objects->create($system, 'snippet', ['title' => 'Oldalsáv doboz', 'body' => '<p>Oldalsáv szöveg</p>']);
    $place = static function (string $key, string $source, string $display, string $region, ?\Campanella\Layout\Conditions $where = null, int $weight = 0, array $values = []) use ($components, $system) {
        $component = $components->create($system, ['title' => $key, 'machine_name' => $key] + $values, $source, $display);
        $components->place($system, $component, $region, $where, $weight);

        return $component;
    };
    $place('elr_nev', 'system:site', 'branding', 'header');
    $place('elr_kereso', 'system:search', 'search_box', 'navigation', weight: 2);
    $place('elr_gomb', 'system:visitor', 'login_button', 'navigation', weight: 1);
    $place('elr_doboz', 'object:' . $snippet->id(), 'info_box', 'sidebar', new $C(blueprints: ['article']));
    $members = $objects->create($system, 'snippet', ['title' => 'Tagoknak doboz', 'body' => '<p>Csak tagoknak</p>']);
    $place('elr_csak_tagoknak', 'object:' . $members->id(), 'info_box', 'sidebar', values: ['visible_to' => \Campanella\Capability\Restricted::USERS]);
    $place('elr_hireknel', 'system:site', 'branding', 'footer', new $C(paths: ['/hirek*']));
    throws(ValidationException::class, static fn () => $components->place($system, $components->find('elr_nev', $system), 'nincs_ilyen_regio'));
    throws(ValidationException::class, static fn () => $components->place($system, $components->find('elr_nev', $system), 'header', new $C(paths: ['hirek'])));

    $before = $kernel->handle(new Request('GET', '/elrendezett-cikk'))->body;
    check(str_contains($before, 'navbar-expand-md') && !str_contains($before, 'region--header'), 'the old header is gone before layouts are on');

    $settings->set(['layout.enabled' => '1', 'layout.blueprint.article' => 'sidebar']);
    try {
        $page = $kernel->handle(new Request('GET', '/elrendezett-cikk'));
        check($page->status === 200 && str_contains($page->body, 'region--header') && str_contains($page->body, 'display-branding'), 'the header region');
        check(preg_match('#region--navigation region--collapse-md.*display-login-button.*display-search-box#s', $page->body) === 1, 'the navigation, collapsed below md, in its order');
        check(str_contains($page->body, 'data-bs-target="#region-navigation"') && str_contains($page->body, 'd-md-none'), 'the toggle button');
        check(preg_match('#<main class="col-lg-8">.*A cikk szövege.*</main>\s*<aside class="col-lg-4"[^>]*>.*Oldalsáv szöveg#s', $page->body) === 1, 'the content and the sidebar');
        check(!str_contains($page->body, 'Csak tagoknak') && !str_contains($page->body, 'site-footer'), 'a component for users, or one for other paths');

        $front = $kernel->handle(new Request('GET', '/'))->body;
        check(str_contains($front, 'region--header') && !str_contains($front, 'Oldalsáv szöveg') && !str_contains($front, '<aside'), 'the front page in the site\'s one-column layout');
        $news = $kernel->handle(new Request('GET', '/hirek'))->body;
        check(str_contains($news, 'site-footer') && substr_count($news, 'display-branding"') === 2, 'the footer component on its paths: ' . substr($news, (int) strpos($news, '<body'), 1500));

        // The look of a region from the settings; a theme can override the region's template.
        $settings->set(['layout.region.sidebar.navigation' => 'hide:lg']);
        $hidden = $kernel->handle(new Request('GET', '/elrendezett-cikk'))->body;
        check(str_contains($hidden, 'd-none d-lg-block region region--navigation') && !str_contains($hidden, 'data-bs-target="#region-navigation"'), 'hidden below lg');

    } finally {
        $settings->set(['layout.enabled' => null, 'layout.blueprint.article' => null, 'layout.region.sidebar.navigation' => null]);
    }
    foreach (['elr_nev', 'elr_kereso', 'elr_gomb', 'elr_doboz', 'elr_csak_tagoknak', 'elr_hireknel'] as $key) {
        $objects->delete($system, $components->find($key, $system));
    }
    check(str_contains($kernel->handle(new Request('GET', '/elrendezett-cikk'))->body, 'navbar-expand-md'), 'the old header again');
});

/** For testing: a provider whose prefix the core's already has. */
final class SecondSiteTokens implements \Campanella\Token\TokenProvider
{
    public function prefix(): string { return 'site'; }
    public function tokens(): array { return []; }
    public function value(string $name): ?string { return null; }
}

test('Tokens: replaced by their escaped values, unknown ones left; the providers', function (): void {
    $registry = new \Campanella\Token\TokenRegistry(
        \Campanella\Token\TokenRegistry::BUILTIN,
        ['snippet'],
        static fn (string $class): object => $class === \Campanella\Token\SiteTokens::class
            ? new \Campanella\Token\SiteTokens(\Campanella\Site\SiteValues::of(['name' => 'Bor & <b>Kert</b>', 'slogan' => null, 'url' => 'https://pelda.hu']))
            : new $class(),
    );
    $year = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
    check($registry->replace('<p>© [date:year] [site:name] · Campanella [campanella:version]</p>') === '<p>© ' . $year . ' Bor &amp; &lt;b&gt;Kert&lt;/b&gt; · Campanella ' . Version::CAMPANELLA . '</p>');
    check($registry->replace('<a href="[site:url]">[site:slogan]</a>') === '<a href="https://pelda.hu"></a>', 'in an attribute, and an empty value');
    check($registry->replace('[site:nincs] [nincs:name] [Site:Name] [site: name] [date:year') === '[site:nincs] [nincs:name] [Site:Name] [site: name] [date:year', 'an unknown or miswritten token is changed');
    check($registry->replace('nincs benne') === 'nincs benne');
    check(array_column($registry->tokens(), 'token') === ['[site:name]', '[site:slogan]', '[site:url]', '[date:year]', '[campanella:version]']);
    check($registry->appliesTo('snippet') && !$registry->appliesTo('article'));
    throws(\InvalidArgumentException::class, static fn () => (new \Campanella\Token\TokenRegistry([\Campanella\Token\SiteTokens::class, SecondSiteTokens::class]))->providers());
    throws(\InvalidArgumentException::class, static fn () => (new \Campanella\Token\TokenRegistry([Titled::class]))->providers());
});

test('Kernel: tokens in a snippet\'s text, not in an article\'s; the tokens filter; the list under the snippet\'s editor', function () use ($displayKernel, $newUser): void {
    $kernel = $displayKernel();
    if ($kernel === null) {
        return;
    }
    $c = $kernel->container();
    $storage = new ArraySessionStorage();
    $c->set(Session::class, static fn () => new Session($storage));
    $system = Actor::system();
    $objects = $c->get(ObjectService::class);
    $twig = $c->get(\Twig\Environment::class);
    $snippet = $objects->create($system, 'snippet', ['title' => 'Lábléc', 'body' => '<p>© [date:year] [site:name] [ismeretlen:token]</p>']);
    $article = $objects->create($system, 'article', ['title' => 'Tokenes cikk', 'path' => '/tokenes-cikk', 'body' => '<p>Itt [site:name] marad.</p>'], publish: true);
    $name = (string) $c->get(\Campanella\Site\SiteValues::class)['name'];
    $html = $twig->createTemplate("{{ display(s, 'info_box') }}|{{ display(a, 'full') }}")->render(['s' => $snippet, 'a' => $article]);
    check(str_contains($html, '© ' . date('Y') . ' ' . htmlspecialchars($name) . ' [ismeretlen:token]') && str_contains($html, 'Itt [site:name] marad.'), $html);
    check($twig->createTemplate("{{ '<i>[site:name]</i>'|tokens }}")->render() === '&lt;i&gt;' . htmlspecialchars($name) . '&lt;/i&gt;');

    $c->get(AuthService::class)->login(new Request('GET', '/'), $newUser('token-admin@example.hu', 'token-jelszo-1', ['administrator']));
    $storage->endRequest();
    $form = $kernel->handle(new Request('GET', '/admin/snippet/' . $snippet->id()));
    check($form->status === 200 && str_contains($form->body, 'admin-tokens') && str_contains($form->body, '<code>[date:year]</code>'), 'the token list');
    $storage->endRequest();
    check(!str_contains($kernel->handle(new Request('GET', '/admin/article/' . $article->id()))->body, 'admin-tokens'), 'the token list under an article');
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

test('TemplateLoader: a changed template is compiled again, even with an older file time', function (): void {
    $dir = sys_get_temp_dir() . '/campanella-tpl-' . bin2hex(random_bytes(4));
    mkdir($dir . '/cache', 0775, true);
    $file = $dir . '/page.html.twig';
    $render = static function () use ($dir): string {
        $loader = new \Campanella\View\TemplateLoader();
        $loader->addPath($dir);

        return (new \Twig\Environment($loader, ['cache' => $dir . '/cache', 'auto_reload' => true]))->render('page.html.twig');
    };
    file_put_contents($file, '<a href="/belepes">old</a>');
    touch($file, time() - 7200);
    check($render() === '<a href="/belepes">old</a>');
    // As after unpacking a ZIP: new content, but a file time older than the compiled copy.
    file_put_contents($file, '<a href="/login">new</a>');
    touch($file, time() - 3600);
    clearstatcache();
    check($render() === '<a href="/login">new</a>', 'the old compiled copy was served');
    check($render() === '<a href="/login">new</a>');
    exec('rm -rf ' . escapeshellarg($dir));
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
    // The JIT (since 0.3.4): opcache_get_status() first, otherwise the settings.
    $jit = \Campanella\System\SystemCheck::jitIsOn(...);
    check($jit(['jit' => ['on' => true]], 'disable', '0') === true && $jit(['jit' => ['on' => false]], 'tracing', '256M') === false, 'the status decides');
    check($jit(null, '1235', '256M') === true && $jit(null, 'tracing', '64M') === true, 'a buffer and a mode: on');
    check($jit(null, 'disable', '256M') === false && $jit(null, 'off', '256M') === false && $jit(null, '0', '256M') === false, 'turned off');
    check($jit(null, 'tracing', '0') === false && $jit(null, 'tracing', '') === false && $jit(['opcache_enabled' => true], 'tracing', '0') === false, 'no buffer (PHP\'s default): off');

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

/**
 * A ZIP archive made without the zip extension, for the file tests (since 0.3.2): name =>
 * content, each entry deflated unless its name is in $stored.
 *
 * @param array<string, string> $entries
 * @param list<string> $stored
 */
function testZip(array $entries, array $stored = []): string
{
    $body = '';
    $directory = '';
    foreach ($entries as $name => $content) {
        $method = in_array($name, $stored, true) ? 0 : 8;
        $data = $method === 8 ? (string) gzdeflate($content) : $content;
        $crc = crc32($content);
        $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0, $crc, strlen($data), strlen($content), strlen($name), 0);
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, 0, 0, $crc, strlen($data), strlen($content), strlen($name), 0, 0, 0, 0, 0, strlen($body)) . $name;
        $body .= $header . $name . $data;
    }

    return $body . $directory . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($directory), strlen($body), 0);
}

/** A temporary file with the content. */
function testFile(string $content): string
{
    $file = (string) tempnam(sys_get_temp_dir(), 'cf');
    file_put_contents($file, $content);

    return $file;
}

/** The content types of an Office document whose main part has the type. */
function officeTypes(string $main): string
{
    return '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="' . $main . '"/></Types>';
}

test('FileInspector: files by their content; macros, programs and pages refused', function (): void {
    $inspector = new \Campanella\Media\FileInspector();
    $type = static function (string $content, string $name, ?\Campanella\Media\FileInspector $with = null) use ($inspector): string {
        try {
            return ($with ?? $inspector)->inspect(testFile($content), $name)['extension'];
        } catch (ValidationException $e) {
            return $e->errors['file']->key;
        }
    };
    $docx = testZip(['[Content_Types].xml' => officeTypes('application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'), 'word/document.xml' => '<w:document/>']);
    $xlsx = testZip(['[Content_Types].xml' => officeTypes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml')]);
    $pptx = testZip(['[Content_Types].xml' => officeTypes('application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml')]);
    $odt = testZip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<office:document-content/>'], ['mimetype']);
    $ods = testZip(['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet'], ['mimetype']);
    $odp = testZip(['mimetype' => 'application/vnd.oasis.opendocument.presentation'], ['mimetype']);

    check($type("%PDF-1.7\n1 0 obj\n%%EOF", 'Számla.PDF') === 'pdf');
    check($type($docx, 'level.docx') === 'docx' && $type($xlsx, 'tabla.xlsx') === 'xlsx' && $type($pptx, 'eloadas.pptx') === 'pptx');
    check($type($odt, 'level.odt') === 'odt' && $type($ods, 'a.ods') === 'ods' && $type($odp, 'a.odp') === 'odp');
    check($type("név;kor\nAnna;30\n", 'lista.CSV') === 'csv' && $type('Hello', 'olvass.txt') === 'txt');
    check($type($docx, 'valami.pdf') === 'docx', 'the content decides, not the name');
    $info = $inspector->inspect(testFile($docx), 'x.docx');
    check($info['mime'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' && $info['bytes'] === $docx);

    // Macros and scripts, under any name.
    check($type(testZip(['[Content_Types].xml' => officeTypes('application/vnd.ms-word.document.macroEnabled.main+xml')]), 'a.docx') === 'file.macros', 'a .docm named .docx');
    check($type(testZip(['[Content_Types].xml' => officeTypes('application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'), 'word/vbaProject.bin' => 'x']), 'a.docx') === 'file.macros', 'a VBA project');
    check($type(testZip(['[Content_Types].xml' => officeTypes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'), 'xl/activeX/activeX1.bin' => 'x']), 'a.xlsx') === 'file.macros', 'ActiveX');
    check($type(testZip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'Basic/Standard/Module1.xml' => 'x'], ['mimetype']), 'a.odt') === 'file.macros', 'OpenDocument Basic');

    // Never: pages, images as files, programs; a plain zip only if allowed.
    check($type('<html><script>alert(1)</script></html>', 'oldal.html') === 'file.not_recognized');
    check($type('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'kep.svg') === 'file.not_recognized');
    check($type("MZ\x90\x00\x03\x00\x00\x00", 'program.exe') === 'file.not_recognized' && $type("MZ\x90\x00", 'program.txt') === 'file.not_recognized', 'zero bytes are not text');
    check($type("\x89PNG\r\n\x1a\n", 'kep.png') === 'file.not_recognized');
    $zip = testZip(['program.exe' => 'MZ']);
    check($type($zip, 'csomag.zip') === 'file.type_not_allowed', 'zip is not accepted by default');
    check($type($zip, 'csomag.zip', new \Campanella\Media\FileInspector([...\Campanella\Media\FileInspector::DEFAULT_TYPES, 'zip'])) === 'zip', 'unless the setting lists it');
    check((new \Campanella\Media\FileInspector(['pdf', 'svg', 'html', 'exe', 'ZIP']))->types() === ['pdf', 'zip'], 'only the known types can be accepted');
    check($type("%PDF-1.4", 'a.pdf', new \Campanella\Media\FileInspector(['docx'])) === 'file.type_not_allowed');

    // Damaged, empty, too large.
    check($type(substr($docx, 0, 40), 'a.docx') === 'file.damaged' && $type("PK\x03\x04" . str_repeat('x', 100), 'a.docx') === 'file.damaged');
    check($type('', 'a.pdf') === 'file.empty');
    check($type("%PDF-1.4\n" . str_repeat('x', 2000), 'a.pdf', new \Campanella\Media\FileInspector(maxBytes: 1000)) === 'file.too_large');

    $directory = new \Campanella\Media\ZipDirectory($odt);
    check($directory->names() === ['mimetype', 'content.xml'] && $directory->read('content.xml') === '<office:document-content/>' && $directory->method('mimetype') === 0);
    check($directory->read('nincs') === null && !$directory->isEncrypted('mimetype'));
});

test('FileService: uploading a file, its download name, deleting it with its file', function () use ($repository, $policy, $admin, $editorUser, $blueprints): void {
    $dir = sys_get_temp_dir() . '/campanella-files-' . bin2hex(random_bytes(4));
    $storage = new \Campanella\Media\FileStorage($dir);
    $objects = new ObjectService($repository, $policy);
    $objects->addListener(new \Campanella\Media\DeleteStoredFile($storage));
    $files = new \Campanella\Media\FileService($objects, $repository, $policy, new \Campanella\Media\FileInspector(), $storage);
    $stored = static fn (): array => glob($dir . '/*/*/*') ?: [];

    $editor = new Actor(\Campanella\Access\ActorKind::User, (int) $editorUser->id(), ['editor']);
    $file = $files->upload($editor, testFile("%PDF-1.7\n%%EOF"), 'C:\\Dokumentumok\\Áprilisi hírlevél.PDF');
    $download = $file->as(\Campanella\Capability\Downloadable::class);
    check($file->blueprint() === 'file' && $file->get('title') === 'Áprilisi hírlevél', (string) $file->get('title'));
    check($download->name() === 'aprilisi-hirlevel.pdf' && $download->mimeType() === 'application/pdf' && $download->size() === 14 && $download->extension() === 'pdf');
    check(preg_match('#^\d{4}/\d{2}/[0-9a-f]{24}\.pdf$#', $download->path()) === 1 && is_file($storage->path($download->path())));
    check($download->hash() === hash_file('sha256', $storage->path($download->path())));
    check($file->as(\Campanella\Capability\Restricted::class)->isPublic() && $file->as(\Campanella\Capability\Authorable::class)->authorId() === $editorUser->id());
    check($files->url($file) === '/files/' . $file->id() . '/aprilisi-hirlevel.pdf');
    check(is_file($dir . '/.htaccess') && str_contains((string) file_get_contents($dir . '/.htaccess'), 'Require all denied'), 'the folder denies the web');

    $members = $files->upload($admin, testFile("név;kor\n"), 'tagok.csv', \Campanella\Capability\Restricted::ROLES, ['member', ' member ', '']);
    check($repository->find((int) $members->id())?->get('visible_to') === 'roles' && $members->as(\Campanella\Capability\Restricted::class)->roles() === ['member']);
    check($files->upload($admin, testFile('x'), 'Hírek.txt', title: '  Saját cím ')->get('title') === 'Saját cím');

    // Nothing is stored for an invalid file, an invalid restriction, or a user who may not upload.
    $before = count($stored());
    check(mediaError(fn () => $files->upload($admin, testFile('<html>'), 'a.html')) === 'file.not_recognized');
    throws(ValidationException::class, fn () => $files->upload($admin, testFile('x'), 'a.txt', \Campanella\Capability\Restricted::ROLES, []));
    throws(ValidationException::class, fn () => $files->upload($admin, testFile('x'), 'a.txt', 'everyone'));
    throws(\Campanella\Access\AccessDeniedException::class, fn () => $files->upload(Actor::anonymous(), testFile('x'), 'a.txt'));
    check(count($stored()) === $before, 'no file left behind');

    $path = $storage->path($download->path());
    $objects->delete($admin, $file);
    check(!is_file($path) && $repository->find((int) $file->id()) === null, 'deleted with its file');

    check(\Campanella\Media\FileService::downloadName('../../etc/passwd', 'txt') === 'passwd.txt');
    check(\Campanella\Media\FileService::downloadName('.pdf', 'pdf') === 'file.pdf' && \Campanella\Media\FileService::downloadName("szamla\u{202E}fdp.exe", 'pdf') === 'szamla-fdp.pdf');
    check(\Campanella\Media\FileService::downloadName(str_repeat('a', 300) . '.pdf', 'docx') === str_repeat('a', 80) . '.docx', 'the content\'s extension, a limited length');
    check(\Campanella\Media\FileService::titleFrom('.pdf') === 'file');
    // Why files may be off: the reason the system page tells.
    $withFiles = new \Campanella\Media\FileService($objects, $repository, $policy, new \Campanella\Media\FileInspector(), $storage, $blueprints);
    check($withFiles->isAvailable() && $withFiles->unavailableReason() === null);
    $other = new \Campanella\Media\FileService($objects, $repository, $policy, new \Campanella\Media\FileInspector(), $storage, $blueprints, 'nincs_ilyen');
    check(!$other->isAvailable() && $other->unavailableReason() === 'missing');
    $notFiles = new \Campanella\Media\FileService($objects, $repository, $policy, new \Campanella\Media\FileInspector(), $storage, $blueprints, 'page');
    check($notFiles->unavailableReason() === 'not_files');
    throws(\InvalidArgumentException::class, fn () => $storage->path('../../config/local.php'));
    throws(\InvalidArgumentException::class, fn () => $storage->path('2026/10/' . str_repeat('a', 24) . '.php'));
});

test('DefaultPolicy: a restricted object for logged-in users or for roles', function () use ($repository, $engine, $policy, $admin, $editorUser): void {
    $make = static function (string $scope, array $roles = []) use ($repository, $admin): CampanellaObject {
        $object = $repository->create('file', [
            'title' => 'Korlátozott ' . $scope,
            'download_path' => '2026/10/' . bin2hex(random_bytes(12)) . '.pdf',
            'download_name' => 'a.pdf', 'download_type' => 'application/pdf', 'download_size' => 1, 'download_hash' => str_repeat('0', 64),
        ]);
        $object->as(\Campanella\Capability\Restricted::class)->restrict($scope, $roles);
        $repository->save($object);

        return $object;
    };
    $public = $make('public');
    $users = $make('users');
    $members = $make('roles', ['member', 'patron']);
    $staff = $make('roles', ['tanar']);
    $ids = [(int) $public->id(), (int) $users->id(), (int) $members->id(), (int) $staff->id()];
    $seen = static function (Actor $actor) use ($engine, $ids): array {
        return array_map(static fn (CampanellaObject $o): int => (int) $o->id(), $engine->execute(Query::objects()->blueprint('file')->where('id', 'IN', $ids)->orderBy('id'), $actor)->items);
    };
    $user = new Actor(\Campanella\Access\ActorKind::User, 9001, []);
    $member = new Actor(\Campanella\Access\ActorKind::User, 9002, ['patron']);
    $editor = new Actor(\Campanella\Access\ActorKind::User, (int) $editorUser->id(), ['editor']);
    check($seen(Actor::anonymous()) === [$ids[0]], 'anonymous: only the public one');
    check($seen($user) === [$ids[0], $ids[1]], 'a logged-in user: the users\' one too');
    check($seen($member) === [$ids[0], $ids[1], $ids[2]], 'one of its roles: that one too');
    check($seen($editor) === $ids && $seen($admin) === $ids, 'the staff: all');
    check($seen(new Actor(\Campanella\Access\ActorKind::Anonymous, null, ['patron'])) === [$ids[0]], 'a role without a login counts for nothing');
    foreach ([[Actor::anonymous(), [true, false, false, false]], [$user, [true, true, false, false]], [$member, [true, true, true, false]], [$editor, [true, true, true, true]]] as [$actor, $expected]) {
        $allowed = array_map(static fn (CampanellaObject $o): bool => $policy->allows($actor, \Campanella\Access\Operation::View, $o), [$public, $users, $members, $staff]);
        check($allowed === $expected, 'allows() agrees with the query: ' . json_encode($allowed));
    }
    check(!$policy->allows($member, \Campanella\Access\Operation::Update, $members), 'seeing is not changing');
    $members->set('visible_to', 'roles');
    $members->set('visible_roles', ['patron']);
    $members->set('visible_to', 'Roles');
    throws(ValidationException::class, fn () => $repository->save($members));
});

test('Kernel: downloading a file (GET /files/<id>/<name>), for whom it is meant', function () use ($newUser, $editorUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dir = sys_get_temp_dir() . '/campanella-files-' . bin2hex(random_bytes(4));
    $container->set(\Campanella\Media\FileStorage::class, static fn () => new \Campanella\Media\FileStorage($dir));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $send = function (string $method, string $path, array $headers = []) use ($kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, headers: $headers));
    };
    $files = $container->get(\Campanella\Media\FileService::class);
    check($files->isAvailable());
    $public = $files->upload(Actor::system(), testFile("%PDF-1.7\nnyilvános\n%%EOF"), 'Műsor 2026.pdf');
    $private = $files->upload(Actor::system(), testFile("név;tagdíj\n"), 'Tagok.csv', \Campanella\Capability\Restricted::ROLES, ['member']);
    $url = $files->url($public);

    $ok = $send('GET', $url);
    check($ok->status === 200 && $ok->file === $files->path($public) && $ok->body === '', 'sent from its file: ' . $ok->status);
    check(($ok->headers['Content-Type'] ?? '') === 'application/pdf' && ($ok->headers['Content-Disposition'] ?? '') === 'attachment; filename="musor-2026.pdf"', json_encode($ok->headers));
    check(($ok->headers['Content-Security-Policy'] ?? '') === "default-src 'none'; sandbox" && ($ok->headers['Content-Length'] ?? '') === (string) filesize($files->path($public)));
    check(($ok->headers['Cache-Control'] ?? '') === 'public, max-age=3600' && ($ok->headers['ETag'] ?? '') === '"' . $public->get('download_hash') . '"' && !isset($ok->headers['X-Robots-Tag']));
    check($send('GET', $url, ['if-none-match' => '"x", ' . $ok->headers['ETag']])->status === 304, 'not modified');
    $head = $send('HEAD', $url);
    check($head->status === 200 && $head->file === null && ($head->headers['Content-Length'] ?? '') === $ok->headers['Content-Length'], 'HEAD: the headers only');
    check($send('GET', '/files/' . $public->id() . '/regi-nev.pdf')->headers['Location'] === $url && $send('GET', '/files/' . $public->id())->status === 301, 'another name: to its own');
    check($send('POST', $url)->status === 405);
    foreach (['/files/0/a.pdf', '/files/abc', '/files', '/files/' . $public->id() . '/a/b', '/files/99999999/a.pdf'] as $path) {
        check($send('GET', $path)->status === 404, $path);
    }
    $page = $container->get(ObjectService::class)->create(Actor::system(), 'page', ['title' => 'Nem fájl', 'body' => 'x']);
    check($send('GET', '/files/' . $page->id() . '/x')->status === 404, 'an object that is not a file');

    // The members' file: not even its existence, for others.
    $privateUrl = $files->url($private);
    $denied = $send('GET', $privateUrl);
    check($denied->status === 404 && $denied->file === null && !str_contains($denied->body, 'Tagok'), 'anonymous: 404');
    $auth = $container->get(AuthService::class);
    $plain = $newUser('letolto-sima@example.hu', 'sima-felhasznalo-1', []);
    $auth->login(new Request('GET', '/'), $plain);
    check($send('GET', $privateUrl)->status === 404, 'a user without the role: 404');
    $auth->logout(new Request('GET', '/'));
    $member = $newUser('letolto-tag2@example.hu', 'tag-jelszava-1', ['member']);
    $auth->login(new Request('GET', '/'), $member);
    $got = $send('GET', $privateUrl);
    check($got->status === 200 && ($got->headers['Content-Type'] ?? '') === 'text/csv; charset=utf-8' && ($got->headers['X-Robots-Tag'] ?? '') === 'noindex', json_encode($got->headers));
    check(($got->headers['Cache-Control'] ?? '') === 'private, no-store', 'with a session: never kept by a proxy');
    $auth->logout(new Request('GET', '/'));

    // A missing file: 404, not an error.
    @unlink($files->path($public));
    check($send('GET', $url)->status === 404);

    // Templates link to it; the admin lists and edits files, but does not make one from an empty form.
    $auth->login(new Request('GET', '/'), $editorUser);
    check($send('GET', '/admin/file')->status === 200 && $send('GET', '/admin/file/' . $private->id())->status === 200);
    check($send('GET', '/admin/file/new')->status === 404 && !str_contains($send('GET', '/admin/file')->body, '/admin/file/new'));
    check(!str_contains($send('GET', '/admin/file/' . $private->id())->body, 'name="f[download_path]"'), 'the file\'s data is not in the form');
    $auth->logout(new Request('GET', '/'));
    $twig = $container->get(\Twig\Environment::class);
    check($twig->createTemplate('{{ file_url(f) }}|{{ file_url(p) }}')->render(['f' => $private, 'p' => $page]) === $privateUrl . '|');
    check(in_array('file', array_keys($container->get(BlueprintRegistry::class)->all()), true));
    putenv('CAMPANELLA_DB_PREFIX');
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

test('Kernel: files in the admin: uploading, the media library, who may see them, the file picker', function () use ($editorUser, $newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (skipped: config/local.php sets its own prefix)\n";
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $dir = sys_get_temp_dir() . '/campanella-files-' . bin2hex(random_bytes(4));
    $container->set(\Campanella\Media\FileStorage::class, static fn () => new \Campanella\Media\FileStorage($dir));
    $mediaDir = sys_get_temp_dir() . '/campanella-media-' . bin2hex(random_bytes(4));
    $container->set(\Campanella\Media\MediaStorage::class, static fn () => new \Campanella\Media\MediaStorage($mediaDir));
    $storage = new ArraySessionStorage();
    $container->set(Session::class, static fn () => new Session($storage));
    $auth = $container->get(AuthService::class);
    $auth->login(new Request('GET', '/'), $editorUser);
    $send = function (string $method, string $path, array $post = [], array $files = [], array $headers = ['accept' => 'application/json']) use ($kernel, $storage) {
        $storage->endRequest();
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return $kernel->handle(new Request($method, (string) parse_url($path, PHP_URL_PATH), query: $query, post: $post, headers: $headers, files: $files));
    };
    $json = static fn ($response): array => (array) json_decode($response->body, true);
    $upload = static fn (string $content, string $name) => ['file' => new \Campanella\Http\UploadedFile($name, testFile($content), strlen($content))];
    $html = ['accept' => 'text/html'];

    // The Files list: its upload form, with who may download them.
    $list = $send('GET', '/admin/file', [], [], $html);
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $list->body, $m);
    $csrf = $m[1] ?? '';
    check($list->status === 200 && str_contains($list->body, 'action="/admin/media/file-upload"') && str_contains($list->body, 'name="visible_to" id="upload-visible-users" value="users"'), 'the Files list offers the upload');
    check(!str_contains($list->body, 'upload-visible-roles'), 'no "some roles" without roles of the site\'s own');
    check(str_contains($list->body, 'accept=".pdf,application/pdf,.docx'), 'the accepted types');

    $ok = $send('POST', '/admin/media/file-upload', ['_csrf' => $csrf, 'visible_to' => 'users'], $upload("%PDF-1.7\n%%EOF", 'Belső szabályzat.pdf'));
    $data = $json($ok);
    check($ok->status === 201 && $data['success'] === true && $data['kind'] === 'file' && $data['title'] === 'Belső szabályzat', $ok->body);
    check(preg_match('#^/files/\d+/belso-szabalyzat\.pdf$#', (string) $data['url']) === 1 && $data['edit'] === '/admin/file/' . $data['id'], $ok->body);
    $file = $container->get(ObjectRepository::class)->find((int) $data['id']);
    check($file?->get('visible_to') === 'users', 'uploaded for the logged-in users');
    $bad = $send('POST', '/admin/media/file-upload', ['_csrf' => $csrf], $upload('<html><script>x</script>', 'oldal.html'));
    check($bad->status === 422 && str_contains((string) $json($bad)['message'], 'PDF'), $bad->body);
    check($send('POST', '/admin/media/file-upload', [], $upload('x', 'a.txt'))->status === 400, 'without the CSRF token');
    check($send('POST', '/admin/media/file-upload', ['_csrf' => $csrf, 'visible_to' => 'roles'], $upload('x', 'a.txt'))->status === 422, 'some roles, without a role');
    $plain = $send('POST', '/admin/media/file-upload', ['_csrf' => $csrf], $upload("név;kor\n", 'Lista.csv'), $html);
    check($plain->status === 303 && ($plain->headers['Location'] ?? '') === '/admin/file', 'without JavaScript: back to the list');
    $list = $send('GET', '/admin/file', [], [], $html);
    check(str_contains($list->body, 'Feltöltve: Lista') && str_contains($list->body, 'CSV-táblázat') && str_contains($list->body, 'Bejelentkezett felhasználók'), 'the list: the message, the type, who may see it');

    // The media library: images and files, one form for both.
    $library = $send('GET', '/admin/media', [], [], $html);
    check($library->status === 200 && str_contains($library->body, 'action="/admin/media/add"') && str_contains($library->body, 'Belső szabályzat') && str_contains($library->body, 'Lista'), 'the library: ' . $library->status);
    check(str_contains($library->body, 'href="/admin/media"') && str_contains($library->body, '>Fájlok</a>'), 'the menu: Media, with Images and Files under it');
    $added = $send('POST', '/admin/media/add', ['_csrf' => $csrf], $upload("%PDF-1.4\n%%EOF", 'Műsor.pdf'));
    check($json($added)['kind'] === 'file', $added->body);
    if (extension_loaded('gd')) {
        $image = $send('POST', '/admin/media/add', ['_csrf' => $csrf], ['file' => new \Campanella\Http\UploadedFile('Fotó.png', testImage('png', 20, 20), 100)]);
        check($image->status === 201 && $json($image)['kind'] === 'image' && str_starts_with((string) $json($image)['url'], '/media/'), 'an image becomes an image: ' . $image->body);
        check(str_contains($send('GET', '/admin/media?kind=image', [], [], $html)->body, 'Fotó') && !str_contains($send('GET', '/admin/media?kind=image', [], [], $html)->body, 'Műsor'), 'the images only');
    }
    $files = $send('GET', '/admin/media?kind=file&q=Műsor', [], [], $html)->body;
    check(str_contains($files, 'Műsor') && !str_contains($files, 'Belső szabályzat'), 'the files, by title');

    // The edit form: the file's data, who may see it.
    $form = $send('GET', '/admin/file/' . $file->id(), [], [], $html);
    check(str_contains($form->body, 'belso-szabalyzat.pdf') && str_contains($form->body, 'href="/files/' . $file->id() . '/belso-szabalyzat.pdf" download'), 'the side panel');
    check(str_contains($form->body, 'name="f[visible_to]"') && str_contains($form->body, '<option value="users" selected>Bejelentkezett felhasználók</option>'), 'who may see it: a choice');
    check(str_contains($form->body, 'config/local.php, users.roles'), 'no roles of its own: the hint');
    preg_match('/name="_version" value="([^"]+)"/', $form->body, $v);
    $saved = $send('POST', '/admin/file/' . $file->id(), ['_csrf' => $csrf, '_version' => $v[1] ?? '', 'f' => ['title' => 'Szabályzat', 'visible_to' => 'public', 'visible_roles' => ['']]], [], $html);
    check($saved->status === 303, 'saved: ' . $saved->status);
    check($container->get(ObjectRepository::class)->find((int) $file->id())?->get('visible_to') === 'public', 'made public');

    // The editor's file picker.
    $article = $send('GET', '/admin/article/new', [], [], $html);
    check(str_contains($article->body, 'data-files-url="/admin/media/files"') && str_contains($article->body, 'Hivatkozás feltöltött fájlra'), 'the article form offers the file picker');
    $picked = $json($send('GET', '/admin/media/files?q=szab'));
    check($picked['success'] === true && $picked['total'] === 1 && $picked['items'][0]['url'] === '/files/' . $file->id() . '/belso-szabalyzat.pdf' && $picked['items'][0]['type'] === 'PDF', json_encode($picked));

    // A text that links to it: shown on its delete page.
    $text = $container->get(ObjectService::class)->create(Actor::system(), 'page', ['title' => 'Letöltések', 'format' => 'html', 'body' => '<p><a href="/files/' . $file->id() . '/belso-szabalyzat.pdf">A szabályzat</a></p>']);
    check(\Campanella\Media\MediaUsage::downloadsIn('<a href="/x/files/12/a-b.pdf?v=1">x</a> <a href=\'/files/3/c.txt\'>y</a> <a href="/files/4/d.exe">z</a>') === [12, 3]);
    $auth->logout();
    $auth->login(new Request('GET', '/'), $newUser('fajl-admin@example.hu', 'fajl-admin-jelszo', ['administrator']));
    $delete = $send('GET', '/admin/file/' . $file->id() . '/delete', [], [], $html);
    check($delete->status === 200 && str_contains($delete->body, 'Letöltések') && str_contains($delete->body, 'Ezek a szövegek hivatkoznak a fájlra'), 'the delete page lists the text that links to it');

    // An expired session: JSON for the scripts.
    $auth->logout();
    check($send('GET', '/admin/media/files')->status === 401 && $send('POST', '/admin/media/file-upload', ['_csrf' => $csrf], $upload('x', 'a.txt'))->status === 401);
    check(str_ends_with($send('POST', '/admin/media/file-upload', [], [], $html)->headers['Location'] ?? '', '?return=%2Fadmin%2Ffile'), 'without JavaScript: to the login page, then back to the Files list');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('ObjectForm: a restricted object\'s roles are checkboxes of the site\'s own roles', function () use ($engine, $repository): void {
    $translator = \Campanella\I18n\Translator::fromDirectory(dirname(__DIR__) . '/lang', 'hu');
    $form = new \Campanella\Admin\Form\ObjectForm($engine, $translator, 'UTC', null, ['member', 'patron']);
    $file = $repository->create('file', ['title' => 'Tagoknak']);
    $file->as(\Campanella\Capability\Restricted::class)->restrict('roles', ['regi']);
    $fields = [];
    foreach ($form->build($file, Actor::system()) as $field) {
        $fields[$field->name] = $field;
    }
    $roles = $fields['visible_roles'];
    check($roles->widget === 'checkboxes' && $roles->inputName() === 'f[visible_roles][]', $roles->widget);
    check(array_column($roles->options, 'value') === ['member', 'patron', 'regi'], 'the site\'s roles, and the one it has');
    check($fields['visible_to']->widget === 'choice' && array_column($fields['visible_to']->options, 'value') === ['public', 'users', 'roles']);
    $read = $form->read($file, ['f' => ['title' => 'Tagoknak', 'visible_to' => 'roles', 'visible_roles' => ['', 'member']]], Actor::system());
    check($read['values']['visible_roles'] === ['', 'member'] && $read['errors'] === [], json_encode($read['values']));
    $file->fill($read['values']);
    $file->as(\Campanella\Capability\Restricted::class)->prepareForSave();
    check($file->get('visible_roles') === ['member'], 'the empty value of the checkboxes is left out');
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

    // The backup holds the database as it was before the upgrade (since 0.2.0): the
    // schema version is written after it, not before.
    $container->get(Connection::class)->execute("UPDATE {system} SET value = '11' WHERE name = 'schema_version'");
    check($installer->needsUpgrade() && $send('GET', '/')->status === 503, 'an older schema version: an upgrade needed');
    $page = $send('GET', '/admin/upgrade');
    $before = glob($dir . '/campanella-*.sql.gz') ?: [];
    check($send('POST', '/admin/upgrade', ['_csrf' => $csrfOf($page), 'key' => $key])->status === 200 && !$installer->needsUpgrade(), 'the schema version upgraded');
    $new = array_values(array_diff(glob($dir . '/campanella-*.sql.gz') ?: [], $before));
    $newest = count($new) === 1 ? (string) gzdecode((string) file_get_contents($new[0])) : '';
    check(str_contains($newest, "('schema_version', '11')") && str_contains($newest, "the database's schema version: 11;") && !str_contains($newest, "('schema_version', '" . Version::SCHEMA . "')"), 'the backup holds the old schema version');

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
    check($done->status === 200 && $ran === 2 && count(glob($dir . '/*.gz') ?: []) === 2, 'run without a backup');

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
    check($robots->status === 200 && str_starts_with($robots->headers['Content-Type'] ?? '', 'text/plain') && str_contains($robots->body, "User-agent: *\nDisallow: /admin/\nDisallow: /login\nDisallow: /logout\nDisallow: /password-reset\nDisallow: /search\nDisallow: /install\n") && !str_contains($robots->body, 'Sitemap:'), $robots->body);

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
    check($page->status === 200 && str_contains($page->body, 'value="Campanella"') && str_contains($page->body, 'placeholder="https://example.hu"') && str_contains($page->body, 'Még nincs megadva.') && str_contains($page->body, 'a(z) http://pelda.hu címen') && str_contains($page->body, 'href="/admin/system/settings" aria-current="page">Webhely-beállítások</a>'), 'the form, with the address it was opened at');
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
    check($hu->all() === ['login' => '/belepes', 'logout' => '/kilepes', 'password_reset' => '/password-reset', 'contact' => '/contact', 'search' => '/search', 'files' => '/files'], json_encode($hu->all()));
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

test('Session: absolute lifetime, also for an active session; refresh keeps it; 0 turns it off', function () use ($repository, $engine, $db, $req, $newUser): void {
    $newUser('elettartam@example.hu', 'elettartam-jelszo-1', ['editor']);
    $make = static function (ArraySessionStorage $storage, int $timeout) use ($repository, $engine, $db): AuthService {
        $session = new Session($storage, 7200);

        return new AuthService($repository, $engine, $session, new Throttle($db), new Csrf($session), ['max_attempts' => 50, 'max_attempts_per_ip' => 50, 'decay_seconds' => 900], [], $timeout);
    };
    $next = static function (ArraySessionStorage $storage, int $timeout = 3600) use ($make, $req): ?CampanellaObject {
        $storage->endRequest();

        return $make($storage, $timeout)->currentUser($req());
    };
    check((new AuthService($repository, $engine, new Session(new ArraySessionStorage()), new Throttle($db), new Csrf(new Session(new ArraySessionStorage()))))->absoluteTimeout() === 12 * 3600, 'the default is 12 hours');

    $storage = new ArraySessionStorage();
    $result = $make($storage, 3600)->attempt($req(['x' => 1], '10.0.4.1'), 'elettartam@example.hu', 'elettartam-jelszo-1');
    check($result->success, (string) json_encode($result->error));
    $loginAt = $storage->get(AuthService::SESSION_LOGIN_AT);
    check(is_int($loginAt) && abs($loginAt - time()) <= 1, 'the login time is stored');
    check($next($storage) !== null, 'within the lifetime');

    // Active all along (the idle timeout never ran out), but logged in 61 minutes ago.
    $storage->set(AuthService::SESSION_LOGIN_AT, time() - 3660);
    $generation = $storage->generation();
    check($next($storage) === null, 'logged out after the absolute lifetime');
    check($storage->generation() === $generation + 1, 'with a new session ID');
    check($storage->get(AuthService::SESSION_USER) === null && $storage->get(AuthService::SESSION_LOGIN_AT) === null);
    check($next($storage) === null, 'and stays logged out');

    // 0: no absolute limit.
    $storage = new ArraySessionStorage();
    $make($storage, 0)->attempt($req(['x' => 1], '10.0.4.1'), 'elettartam@example.hu', 'elettartam-jelszo-1');
    $storage->set(AuthService::SESSION_LOGIN_AT, time() - 400 * 86400);
    check($next($storage, 0) !== null, 'no limit');

    // A session from before 0.1.4 (no login time) starts counting now.
    $storage->remove(AuthService::SESSION_LOGIN_AT);
    check($next($storage) !== null && is_int($storage->get(AuthService::SESSION_LOGIN_AT)), 'an old session gets a login time');

    // refresh() (after changing one's own password) does not lengthen the login.
    $storage->set(AuthService::SESSION_LOGIN_AT, time() - 3000);
    $storage->endRequest();
    $auth = $make($storage, 3600);
    $request = $req();
    $user = $auth->currentUser($request);
    check($user !== null);
    $auth->refresh($request, $user);
    check($storage->get(AuthService::SESSION_LOGIN_AT) === time() - 3000 || $storage->get(AuthService::SESSION_LOGIN_AT) === time() - 3001, 'refresh keeps the login time');
});

test('Blocking from the admin ends the logins at once, even if the account is activated again before their next request (0.2.0 review)', function () use ($repository, $engine, $db, $newUser, $policy): void {
    $boss = $newUser('blokkolo-fonok@example.hu', 'blokkolo-fonok-1', ['administrator']);
    $user = $newUser('blokkolt@example.hu', 'blokkolt-jelszo-1', ['editor']);
    $registry = new SessionRegistry($db, 7200, 43200);
    $storage = new ArraySessionStorage();
    $make = static function () use ($repository, $engine, $db, $registry, $storage): AuthService {
        $session = new Session($storage, 7200);

        return new AuthService($repository, $engine, $session, new Throttle($db), new Csrf($session), [], [], 43200, $registry);
    };
    $make()->login(new Request('GET', '/admin', ip: '10.0.9.1'), $user);
    $users = new \Campanella\Service\UserService($repository, $engine, $policy, new Throttle($db), [Actor::ADMINISTRATOR, 'editor'], $db);
    $admin = AuthService::actorFor($boss);
    $users->update($admin, $user, 'Blokkolt', 'blokkolt@example.hu', ['editor'], false);
    check((int) $db->fetchValue('SELECT COUNT(*) FROM {sessions} WHERE user_id = :u', ['u' => $user->id()]) === 0, 'blocking left the logins recorded');
    $users->update($admin, $repository->find((int) $user->id()) ?? throw new RuntimeException('gone'), 'Blokkolt', 'blokkolt@example.hu', ['editor'], true);
    $storage->endRequest();
    check($make()->currentUser(new Request('GET', '/admin', ip: '10.0.9.1')) === null, 'the login from before the block came back with the activation');
});

test('SessionRegistry: logins recorded and checked on every request; ending one, the others, logging out', function () use ($repository, $engine, $db, $newUser): void {
    $user = $newUser('munkamenet@example.hu', 'munkamenet-jelszo-1', ['editor']);
    $other = $newUser('masik-munkamenet@example.hu', 'masik-munkamenet-1', ['editor']);
    $registry = new SessionRegistry($db, 7200, 43200);
    $make = static function (ArraySessionStorage $storage, ?SessionRegistry $sessions) use ($repository, $engine, $db): AuthService {
        $session = new Session($storage, 7200);

        return new AuthService($repository, $engine, $session, new Throttle($db), new Csrf($session), [], [], 43200, $sessions);
    };
    $firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0';
    $android = "Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36\x07";
    $request = static fn (string $agent = '', string $ip = '10.0.5.1') => new Request('GET', '/admin', headers: ['user-agent' => $agent], ip: $ip);
    $next = static function (ArraySessionStorage $storage, Request $r) use ($make, $registry): array {
        $storage->endRequest();
        $auth = $make($storage, $registry);

        return [$auth, $auth->currentUser($r)];
    };

    // Two browsers.
    $a = new ArraySessionStorage();
    $make($a, $registry)->login($request($firefox), $user);
    $token = $a->get(AuthService::SESSION_TOKEN);
    check(is_string($token) && strlen($token) === 64, 'a token in the session');
    $row = $db->fetchOne('SELECT * FROM {sessions} WHERE user_id = :u', ['u' => $user->id()]);
    check($row !== null && $row['token_hash'] === hash('sha256', $token) && !str_contains(json_encode($row), $token), 'only its hash in the table');
    $b = new ArraySessionStorage();
    $make($b, $registry)->login($request($android, '10.0.5.2'), $user);
    $make(new ArraySessionStorage(), $registry)->login($request(), $other);

    [$authA, $me] = $next($a, $reqA = $request($firefox));
    check($me !== null);
    $list = $authA->sessions($reqA);
    check(count($list) === 2, 'only the user\'s own logins: ' . count($list));
    $mine = array_values(array_filter($list, static fn ($s) => $s->current));
    $theirs = array_values(array_filter($list, static fn ($s) => !$s->current));
    check(count($mine) === 1 && $mine[0]->browser() === 'Firefox' && $mine[0]->system() === 'Windows' && $mine[0]->ip === '10.0.5.1');
    check($theirs[0]->browser() === 'Chrome' && $theirs[0]->system() === 'Android' && !str_contains($theirs[0]->userAgent, "\x07"), 'control characters removed');
    check(!$authA->endSession($reqA, $mine[0]->id), 'not this one');
    $otherRow = (int) $db->fetchValue('SELECT id FROM {sessions} WHERE user_id = :u', ['u' => $other->id()]);
    check(!$authA->endSession($reqA, $otherRow), 'not someone else\'s');

    // A ends B: B's next request is anonymous, with a new session ID.
    check($authA->endSession($reqA, $theirs[0]->id));
    $generation = $b->generation();
    [, $bUser] = $next($b, $request($android, '10.0.5.2'));
    check($bUser === null && $b->generation() === $generation + 1 && $b->get(AuthService::SESSION_TOKEN) === null, 'B is logged out');

    // Logging in again in B, then "everywhere else" from A.
    $make($b, $registry)->login($request($android), $user);
    [$authA] = $next($a, $reqA = $request($firefox));
    check($authA->endOtherSessions($reqA) === 1);
    check($next($b, $request($android))[1] === null && $next($a, $request($firefox))[1] !== null, 'B out, A in');

    // The IP address follows the login; logging out deletes its row.
    [, $still] = $next($a, $request($firefox, '10.0.5.9'));
    check($still !== null && $db->fetchValue('SELECT ip FROM {sessions} WHERE token_hash = :h', ['h' => hash('sha256', $token)]) === '10.0.5.9');
    $make($a, $registry)->logout();
    check((int) $db->fetchValue('SELECT COUNT(*) FROM {sessions} WHERE user_id = :u', ['u' => $user->id()]) === 0, 'logged out: no row');

    // A login from before 0.1.4 (no token) is recorded on its next request.
    $old = new ArraySessionStorage();
    $make($old, null)->login($request($firefox), $user);
    check($old->get(AuthService::SESSION_TOKEN) === null);
    $old->set(AuthService::SESSION_LOGIN_AT, time() - 1800);
    [, $oldUser] = $next($old, $request($firefox));
    check($oldUser !== null && is_string($old->get(AuthService::SESSION_TOKEN)), 'recorded now');
    $since = $registry->forUser((int) $user->id())[0]->createdAt->getTimestamp();
    check(abs($since - (time() - 1800)) <= 1, 'with the time of logging in');

    // Expired rows are not listed, and go at the next login.
    $db->execute('UPDATE {sessions} SET last_seen_at = :t WHERE user_id = :u', ['t' => gmdate('Y-m-d H:i:s', time() - 7300), 'u' => $user->id()]);
    check($registry->forUser((int) $user->id()) === []);
    $make(new ArraySessionStorage(), $registry)->login($request(), $other);
    check((int) $db->fetchValue('SELECT COUNT(*) FROM {sessions} WHERE user_id = :u', ['u' => $user->id()]) === 0, 'cleaned up');

    // Before the upgrade (no table): logging in still works, nothing is recorded.
    $missing = new SessionRegistry(Connection::fromConfig(['prefix' => 'nincs_'] + Config::load(dirname(__DIR__) . '/config')->get('database')));
    $c = new ArraySessionStorage();
    $make($c, $missing)->login($request(), $user);
    $c->endRequest();
    check(!$missing->isAvailable() && $make($c, $missing)->currentUser($request()) !== null, 'works without the table');

    check(SessionInfo::browserOf('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15') === 'Safari');
    check(SessionInfo::browserOf('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0') === 'Edge');
    check(SessionInfo::systemOf('Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X)') === 'iOS' && SessionInfo::browserOf('x') === '' && SessionInfo::systemOf('') === '');
    $db->execute('DELETE FROM {sessions}');
});

test('Kernel: the session list on the profile; an administrator\'s new password ends every login', function () use ($newUser, $db): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    if ($kernel->container()->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $user = $newUser('lista@example.hu', 'lista-jelszo-1', ['editor']);
    $browser = static function (string $agent) use ($user): \Closure {
        $storage = new ArraySessionStorage();
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));
        $kernel->container()->get(AuthService::class)->login(new Request('GET', '/', headers: ['user-agent' => $agent]), $user);
        $send = static function (string $method, string $path, array $post = []) use ($kernel, $storage, $agent) {
            $storage->endRequest();

            return $kernel->handle(new Request($method, $path, post: $post, headers: ['user-agent' => $agent]));
        };

        return $send;
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';
    $a = $browser('Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0');
    $b = $browser('Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1');

    $profile = $a('GET', '/admin/profile');
    check($profile->status === 200 && str_contains($profile->body, 'Hol vagy belépve') && str_contains($profile->body, 'Firefox, Linux') && str_contains($profile->body, 'Safari, iOS') && str_contains($profile->body, '>Ez az<'), 'the list');
    check(str_contains($profile->body, 'action="/admin/profile/sessions/others"'));
    preg_match('/name="session" value="(\d+)"/', $profile->body, $m);
    check(isset($m[1]), 'the other one can be ended');
    check($a('GET', '/admin/profile/sessions/end')->status === 405 && $a('POST', '/admin/profile/sessions/nincs', ['_csrf' => $csrfOf($profile)])->status === 404);
    check($a('POST', '/admin/profile/sessions/end', ['_csrf' => 'rossz', 'session' => $m[1]])->status === 400, 'CSRF');
    $ended = $a('POST', '/admin/profile/sessions/end', ['_csrf' => $csrfOf($profile), 'session' => $m[1]]);
    check($ended->status === 303 && ($ended->headers['Location'] ?? '') === '/admin/profile#sessions');
    check(str_contains($a('GET', '/admin/profile')->body, 'Az a belépés véget ért'));
    check($b('GET', '/admin')->status === 302, 'B was logged out');
    $again = $a('POST', '/admin/profile/sessions/end', ['_csrf' => $csrfOf($profile), 'session' => $m[1]]);
    check($again->status === 303 && str_contains($a('GET', '/admin/profile')->body, 'már véget ért'));
    check(str_contains($a('GET', '/admin/profile')->body, 'Máshol nem vagy belépve'));

    // Everywhere else.
    $c = $browser('curl/8.0');
    $page = $a('GET', '/admin/profile');
    check($a('POST', '/admin/profile/sessions/others', ['_csrf' => $csrfOf($page)])->status === 303);
    check(str_contains($a('GET', '/admin/profile')->body, '1 belépés ért véget') && $c('GET', '/admin')->status === 302 && $a('GET', '/admin')->status === 200);

    // An administrator sets a new password: every login of the user ends, rows too.
    $boss = $newUser('lista-admin@example.hu', 'lista-admin-jelszo-1', ['administrator']);
    $bossKernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $bossKernel->container()->get(\Campanella\Service\UserService::class)->setPassword(AuthService::actorFor($boss), $user, 'lista-uj-jelszo-1');
    check((int) $db->fetchValue('SELECT COUNT(*) FROM {sessions} WHERE user_id = :u', ['u' => $user->id()]) === 0, 'no rows left');
    check($a('GET', '/admin')->status === 302);

    $db->execute('DELETE FROM {sessions}');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Deferred: work after the response, in order; a failure does not stop the rest', function (): void {
    $deferred = new \Campanella\Core\Deferred();
    $done = [];
    $deferred->add(static function () use (&$done, $deferred): void {
        $done[] = 1;
        $deferred->add(static function () use (&$done): void { $done[] = 3; });
    });
    $deferred->add(static function (): void { throw new \RuntimeException('hiba'); });
    $deferred->add(static function () use (&$done): void { $done[] = 2; });
    check(!$deferred->isEmpty());
    $log = ini_set('error_log', '/dev/null');
    check($deferred->run() === 4 && $done === [1, 2, 3] && $deferred->isEmpty(), json_encode($done));
    ini_set('error_log', (string) $log);
});

test('Kernel: the forgotten password, from the request to the new password', function () use ($newUser, $db, $repository): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $make = static function (?object $transport, ArraySessionStorage $storage): \Campanella\Core\Kernel {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $c = $kernel->container();
        $config = $c->get(\Campanella\Core\Config::class);
        $c->set(\Campanella\Core\Config::class, static fn () => new \Campanella\Core\Config(['site' => ['url' => 'https://pelda.example'] + (array) $config->get('site')] + $config->all()));
        $c->set(Session::class, static fn () => new Session($storage));
        if ($transport !== null) {
            $c->set(\Campanella\Mail\Mailer::class, static fn (\Campanella\Core\Container $c) => new \Campanella\Mail\Mailer($transport, 'noreply@example.hu', static fn () => $c->get(\Twig\Environment::class), static fn () => 'Teszt webhely', $c->get(Connection::class)));
        }

        return $kernel;
    };
    $probe = $make(null, new ArraySessionStorage());
    if ($probe->container()->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db->execute('DELETE FROM {settings} WHERE name = :n', ['n' => 'site.url']);
    $db->execute('DELETE FROM {throttle}');
    $user = $newUser('felejto@example.hu', 'felejto-jelszo-1', ['editor']);

    // Without e-mail: not offered.
    check($probe->handle(new Request('GET', '/password-reset'))->status === 404, 'no e-mail: 404');
    check(!str_contains($probe->handle(new Request('GET', '/login'))->body, 'Elfelejtetted'), 'no link');

    $transport = captureTransport();
    $storage = new ArraySessionStorage();
    $kernel = $make($transport, $storage);
    $send = static function (string $method, string $path, array $post = [], array $query = [], string $ip = '10.0.7.1') use (&$kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, query: $query, post: $post, headers: ['host' => 'tamado.example'], ip: $ip));
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';
    check(str_contains($send('GET', '/login')->body, 'href="/password-reset">Elfelejtetted a jelszavad?'), 'the link on the login page');
    $form = $send('GET', '/password-reset');
    check($form->status === 200 && str_contains($form->body, 'name="email"') && ($form->headers['X-Robots-Tag'] ?? '') === 'noindex' && str_contains($form->body, 'name="website"'), 'the form, with the honeypot');

    // The same answer for an unknown, a blocked and a known address; the e-mail goes after the response.
    $blocked = $newUser('letiltott-felejto@example.hu', 'letiltott-jelszo-1', ['editor']);
    $blocked->as(Authenticatable::class)->block();
    $repository->save($blocked);
    $answers = [];
    foreach (['nincs-ilyen@example.hu', 'letiltott-felejto@example.hu', ' Felejto@Example.hu '] as $email) {
        $r = $send('POST', '/password-reset', ['_csrf' => $csrfOf($form), 'email' => $email, 'website' => '']);
        $answers[] = $r->status . ' ' . ($r->headers['Location'] ?? '') . ' ' . $r->body;
    }
    check(count(array_unique($answers)) === 1 && str_starts_with($answers[0], '303 /password-reset?sent=1'), json_encode($answers));
    check($transport->sent === [], 'nothing sent before the response is out');
    $kernel->terminate();
    check(count($transport->sent) === 1, 'one e-mail: ' . count($transport->sent));
    $mail = $transport->sent[0];
    check($mail->getTo()[0]->getAddress() === 'felejto@example.hu' && str_contains((string) $mail->getSubject(), 'Új jelszó beállítása'));
    check(preg_match('#https://pelda\.example/password-reset\?token=([0-9a-f]{96})#', (string) $mail->getTextBody(), $m) === 1, 'the link, on the site\'s address (not the Host header): ' . $mail->getTextBody());
    $token = $m[1];
    check(str_contains((string) $mail->getTextBody(), '60 percig') && !str_contains(json_encode($db->fetchAll('SELECT * FROM {password_resets}')), substr($token, 32)), 'the verifier is not stored');
    check(str_contains($send('GET', '/password-reset', [], ['sent' => '1'])->body, 'Ha ez a cím egy fiókhoz tartozik'));

    // A bot (the honeypot filled in) gets the same answer, and nothing.
    $bot = $send('POST', '/password-reset', ['_csrf' => $csrfOf($form), 'email' => 'felejto@example.hu', 'website' => 'http://spam.example']);
    $kernel->terminate();
    check($bot->status === 303 && count($transport->sent) === 1, 'the honeypot');
    check($send('POST', '/password-reset', ['_csrf' => $csrfOf($form), 'email' => 'nem-cim', 'website' => ''])->status === 422);
    check($send('POST', '/password-reset', ['_csrf' => 'rossz', 'email' => 'felejto@example.hu', 'website' => ''])->status === 400);

    // At most 3 per address in an hour (whether it exists or not).
    $send('POST', '/password-reset', ['_csrf' => $csrfOf($form), 'email' => 'felejto@example.hu', 'website' => ''], [], '10.0.7.2');
    $send('POST', '/password-reset', ['_csrf' => $csrfOf($form), 'email' => 'felejto@example.hu', 'website' => ''], [], '10.0.7.3');
    $limited = $send('POST', '/password-reset', ['_csrf' => $csrfOf($form), 'email' => 'felejto@example.hu', 'website' => ''], [], '10.0.7.4');
    check($limited->status === 429 && str_contains($limited->body, 'Túl sok kérés'), 'the address limit');
    $kernel->terminate();
    check(count($transport->sent) === 3, 'two more e-mails: ' . count($transport->sent));
    preg_match('#token=([0-9a-f]{96})#', (string) $transport->sent[2]->getTextBody(), $m);
    $newest = $m[1];

    // An older link stops working when a newer one is made.
    $generation = $storage->generation();
    $old = $send('GET', '/password-reset', [], ['token' => $token]);
    check($storage->generation() === $generation + 1, 'a new session ID for the token');
    check($old->status === 303 && ($old->headers['Location'] ?? '') === '/password-reset' && $storage->get(\Campanella\Controller\AuthController::RESET_TOKEN) === $token, 'into the session, out of the address');
    $invalid = $send('GET', '/password-reset');
    check($invalid->status === 410 && str_contains($invalid->body, 'nem érvényes') && $storage->get(\Campanella\Controller\AuthController::RESET_TOKEN) === null, 'the older link');

    // The user is logged in elsewhere; the newest link sets the new password.
    $elsewhere = new ArraySessionStorage();
    $other = $make($transport, $elsewhere);
    $other->container()->get(AuthService::class)->login(new Request('GET', '/'), $user);
    $send('GET', '/password-reset', [], ['token' => $newest]);
    $new = $send('GET', '/password-reset');
    check($new->status === 200 && str_contains($new->body, 'name="password_again"') && !str_contains($new->body, 'felejto@example.hu'), 'the new password form');
    check($send('POST', '/password-reset', ['_csrf' => $csrfOf($new), 'password' => 'uj-jelszo-felejto-1', 'password_again' => 'mas-jelszo-felejto-1'])->status === 422, 'mismatch');
    $short = $send('POST', '/password-reset', ['_csrf' => $csrfOf($new), 'password' => 'rovid', 'password_again' => 'rovid']);
    check($short->status === 422 && str_contains($short->body, 'is-invalid'), 'too short; the link still works');
    $done = $send('POST', '/password-reset', ['_csrf' => $csrfOf($new), 'password' => 'uj-jelszo-felejto-1', 'password_again' => 'uj-jelszo-felejto-1']);
    check($done->status === 303 && ($done->headers['Location'] ?? '') === '/login?reset=1', (string) ($done->headers['Location'] ?? $done->status));
    check(str_contains($send('GET', '/login', [], ['reset' => '1'])->body, 'Az új jelszavad beállítva'));
    $fresh = $repository->find((int) $user->id());
    check($fresh !== null && $fresh->as(Authenticatable::class)->verifyPassword('uj-jelszo-felejto-1'), 'the new password');
    $elsewhere->endRequest();
    check($other->handle(new Request('GET', '/admin'))->status === 302, 'logged out elsewhere');
    check((int) $db->fetchValue('SELECT COUNT(*) FROM {password_resets} WHERE user_id = :u', ['u' => $user->id()]) === 0, 'used up');
    $send('GET', '/password-reset', [], ['token' => $newest]);
    check($send('GET', '/password-reset')->status === 410, 'once only');

    // Expired, wrong, voided by a password change (from anywhere), an e-mail change, blocking.
    $service = $kernel->container()->get(\Campanella\Auth\PasswordReset::class);
    $expired = $service->issue($fresh);
    $db->execute('UPDATE {password_resets} SET expires_at = :t', ['t' => gmdate('Y-m-d H:i:s', time() - 1)]);
    check($service->verify($expired) === null, 'expired');
    $valid = $service->issue($fresh);
    check($service->verify($valid)?->id() === $user->id());
    check($service->verify(substr($valid, 0, 32) . str_repeat('0', 64)) === null && $service->verify('x') === null, 'a wrong verifier');
    $users = $kernel->container()->get(\Campanella\Service\UserService::class);
    $users->changeOwnPassword($fresh, 'uj-jelszo-felejto-1', 'uj-jelszo-felejto-2');
    check($service->verify($valid) === null, 'voided by the password change');
    // Even a change that dispatches no event (e.g. saved directly) voids it: the link covers the password hash.
    $valid = $service->issue($fresh);
    $fresh->as(Authenticatable::class)->setPassword('uj-jelszo-felejto-3');
    $repository->save($fresh);
    check($service->verify($valid) === null, 'voided by a password saved directly');
    $valid = $service->issue($fresh);
    $boss = $newUser('felejto-admin@example.hu', 'felejto-admin-jelszo-1', ['administrator']);
    $users->update(AuthService::actorFor($boss), $fresh, 'Felejtő', 'felejto-uj@example.hu', ['editor'], true);
    check($service->verify($valid) === null, 'voided by an e-mail change');
    $valid = $service->issue($fresh);
    $users->update(AuthService::actorFor($boss), $fresh, 'Felejtő', 'felejto-uj@example.hu', ['editor'], false);
    $users->update(AuthService::actorFor($boss), $fresh, 'Felejtő', 'felejto-uj@example.hu', ['editor'], true);
    check($service->verify($valid) === null, 'blocking voids it for good');
    // A wrong password for complete(): checked before the link is used up.
    $valid = $service->issue($fresh);
    throws(ValidationException::class, fn () => $service->complete($valid, 'rovid'));
    check($service->verify($valid) !== null, 'still usable');

    $db->execute('DELETE FROM {password_resets}');
    $db->execute('DELETE FROM {throttle}');
    $db->execute('DELETE FROM {sessions}');
    $db->execute('DELETE FROM {mail_log}');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('MailUser: the user an event is about, with its own template; not a blocked one', function () use ($newUser, $repository): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    putenv('CAMPANELLA_DB_PREFIX');
    $c = $kernel->container();
    $transport = captureTransport();
    $mailer = new \Campanella\Mail\Mailer($transport, 'noreply@example.hu', static fn () => $c->get(\Twig\Environment::class), static fn () => 'Teszt webhely');
    $action = new \Campanella\Event\Action\MailUser($mailer);
    $user = $newUser('ertesitett@example.hu', 'ertesitett-jelszo-1', ['editor']);
    $action->handle(new \Campanella\Event\PasswordChanged($user, true, Actor::system()));
    $action->handle(new \Campanella\Event\UserCreated($user));
    $action->handle(new \Campanella\Event\ObjectDeleted($user));
    check(count($transport->sent) === 2, 'only the user events: ' . count($transport->sent));
    check($transport->sent[0]->getTo()[0]->getAddress() === 'ertesitett@example.hu' && str_contains((string) $transport->sent[0]->getTextBody(), 'egy adminisztrátor új jelszót állított be') && !str_contains((string) $transport->sent[0]->getTextBody(), 'ertesitett-jelszo'), 'the password changed, by an administrator');
    check(str_contains((string) $transport->sent[1]->getSubject(), 'Fiókot kaptál'));
    check(\Campanella\Event\Action\MailUser::templateFor(new \Campanella\Event\PasswordChanged($user, false)) === 'user_password_changed');
    $user->as(Authenticatable::class)->block();
    $repository->save($user);
    $action->handle(new \Campanella\Event\PasswordChanged($user, true));
    check(count($transport->sent) === 2, 'not to a blocked account');
    $repository->delete($user);
});

test('Review fixes (0.1.4): the command line ends logins and voids links; only a missing table is "no"; deferred responses', function () use ($newUser, $db): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $c = $kernel->container();
    if ($c->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $user = $newUser('parancssor@example.hu', 'parancssor-jelszo-1', ['editor']);
    $registry = $c->get(SessionRegistry::class);
    $reset = $c->get(\Campanella\Auth\PasswordReset::class);
    $command = static function (string $args, string $stdin = '') use ($c): int {
        $in = fopen('php://memory', 'r+');
        fwrite($in, $stdin);
        rewind($in);
        $out = fopen('php://memory', 'w+');

        return (new \Campanella\Cli\UserPasswordCommand(new \Campanella\Cli\Input($in)))->run($c, explode(' ', $args), new \Campanella\Cli\Output($out, $out));
    };
    $registry->start((int) $user->id(), new Request('GET', '/'));
    $link = $reset->issue($user);
    check($command('parancssor@example.hu', "parancssor-uj-jelszo-1\n") === 0);
    check($registry->forUser((int) $user->id()) === [] && (int) $db->fetchValue('SELECT COUNT(*) FROM {password_resets} WHERE user_id = :u', ['u' => $user->id()]) === 0, 'a new password: logins and link gone');
    $fresh = $c->get(AuthService::class)->findUserByEmail('parancssor@example.hu');
    $registry->start((int) $user->id(), new Request('GET', '/'));
    $link = $reset->issue($fresh);
    check($command('parancssor@example.hu --block') === 0);
    check($registry->forUser((int) $user->id()) === [] && $reset->verify($link) === null, 'blocked: logins and link gone');
    check($command('parancssor@example.hu --activate') === 0 && $reset->verify($link) === null, 'and stays gone');

    // Only "no such table" is a no.
    check(!$db->tableExists('nincs_ilyen_tabla') && $db->tableExists('sessions'));
    $limited = Connection::fromConfig(['prefix' => 'test_'] + Config::load(dirname(__DIR__) . '/config')->get('database'));
    // Under LOCK TABLES, another table answers "was not locked with LOCK TABLES" (an error, not a missing table).
    $limited->execute('LOCK TABLES {objects} READ');
    try {
        throws(PDOException::class, fn () => $limited->tableExists('sessions'));
    } finally {
        $limited->execute('UNLOCK TABLES');
    }

    // A response with deferred work: its length and Connection: close (no fastcgi_finish_request here).
    $c->get(\Campanella\Core\Deferred::class)->add(static function (): void {});
    $response = $kernel->handle(new Request('GET', '/robots.txt'));
    check(($response->headers['Connection'] ?? '') === 'close' && ($response->headers['Content-Length'] ?? '') === (string) strlen($response->body), json_encode($response->headers));
    $kernel->terminate();
    check(!isset($kernel->handle(new Request('GET', '/robots.txt'))->headers['Content-Length']), 'not without deferred work');

    $db->execute('DELETE FROM {sessions}');
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nForms (0.1.5)\n";

test('Submissions: saved from a form, checked; only administrators see them', function () use ($repository, $engine, $db, $newUser): void {
    $policy = new DefaultPolicy();
    $service = new \Campanella\Service\SubmissionService($repository, $engine, $policy);
    $errors = static function (callable $make): array {
        try {
            $make();
        } catch (ValidationException $e) {
            return array_keys($e->errors);
        }

        return [];
    };
    check($errors(fn () => $service->submit('', '', '', '')) === ['title', 'sender_email', 'message']);
    check($errors(fn () => $service->submit('Valaki', 'nem-cim', '', 'Szia')) === ['sender_email']);
    check($errors(fn () => $service->submit('Valaki', 'a@example.hu', str_repeat('t', 201), str_repeat('ü', 5001))) === ['subject', 'message']);
    check($errors(fn () => $service->submit(str_repeat('n', 101), 'a@example.hu', '', 'Szia')) === ['title']);

    $one = $service->submit("  Kiss\x07 Anna ", ' Anna@Example.HU ', "Kérdés\r\nfejléc", "Első sor\r\nMásodik sor\x00 <script>x</script>");
    $lens = $one->as(\Campanella\Capability\Submitted::class);
    check($one->get('title') === 'Kiss  Anna' && $lens->email() === 'Anna@example.hu' && $lens->subject() === 'Kérdés fejléc' && $lens->form() === 'contact', json_encode([$one->get('title'), $lens->subject()]));
    check($lens->message() === "Első sor\nMásodik sor <script>x</script>" && !$lens->isRead(), 'line breaks kept, control characters gone');
    check($one->sender_email === null && !isset($one->message), 'hidden from templates');
    $two = $service->submit('Nagy Béla', 'bela@example.hu', '', 'Második');

    $admin = AuthService::actorFor($newUser('bekuldes-admin@example.hu', 'bekuldes-admin-1', ['administrator']));
    $editor = AuthService::actorFor($newUser('bekuldes-szerk@example.hu', 'bekuldes-szerk-1', ['editor']));
    check($service->canView($admin) && !$service->canView($editor) && !$service->canView(Actor::anonymous()));
    $all = Query::objects()->blueprint('submission');
    check($engine->count($all, $admin) === 2 && $engine->count($all, $editor) === 0 && $engine->count($all, Actor::anonymous()) === 0, 'the policy');
    check($engine->count(Query::objects(), Actor::anonymous()) === $engine->count(Query::objects()->whereCondition(new \Campanella\Query\Condition\HasCapability('submitted', negated: true)), Actor::anonymous()), 'not in any query');
    check(!$policy->allows($editor, \Campanella\Access\Operation::View, $one) && !$policy->allows(Actor::anonymous(), \Campanella\Access\Operation::View, $one) && $policy->allows($admin, \Campanella\Access\Operation::Delete, $one));
    check($service->find($editor, (int) $one->id()) === null && $service->find($admin, (int) $one->id())?->id() === $one->id());

    check($service->unreadCount($admin) === 2);
    $service->mark($admin, $one, true);
    check($service->unreadCount($admin) === 1 && $repository->find((int) $one->id())?->as(\Campanella\Capability\Submitted::class)->isRead() === true);
    check($service->page($admin, 1, unreadOnly: true)->items[0]->id() === $two->id() && $service->page($admin)->total === 2);
    $service->mark($admin, $one, false);
    check($service->unreadCount($admin) === 2);
    throws(\Campanella\Access\AccessDeniedException::class, fn () => $service->delete($editor, $one));
    throws(\Campanella\Access\AccessDeniedException::class, fn () => $service->mark($editor, $one, true));
    $service->delete($admin, $one);
    check($repository->find((int) $one->id()) === null);
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'submission'");
});

test('Kernel: the submissions in the admin, for administrators only', function () use ($newUser, $db): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $c = $kernel->container();
    if ($c->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $service = $c->get(\Campanella\Service\SubmissionService::class);
    $first = $service->submit('Kovács Éva', 'eva@example.hu', 'Ajánlatkérés', "Jó napot!\n<b>Kérek</b> egy ajánlatot.");
    $service->submit('Tóth Gábor', 'gabor@example.hu', '', 'Tárgy nélküli üzenet');
    $browser = static function ($user) {
        $storage = new ArraySessionStorage();
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));
        $kernel->container()->get(AuthService::class)->login(new Request('GET', '/'), $user);

        return static function (string $method, string $path, array $post = [], array $query = []) use ($kernel, $storage) {
            $storage->endRequest();

            return $kernel->handle(new Request($method, $path, query: $query, post: $post));
        };
    };
    $csrfOf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';
    $admin = $browser($newUser('lista-bekuldes@example.hu', 'lista-bekuldes-1', ['administrator']));
    $editor = $browser($newUser('lista-szerk@example.hu', 'lista-szerk-1', ['editor']));

    $dash = $admin('GET', '/admin');
    check(str_contains($dash->body, 'href="/admin/submission">Beküldések') && str_contains($dash->body, '>2<span class="visually-hidden"> Olvasatlan'), 'the menu, with the unread ones');
    check(!str_contains($dash->body, 'Kovács Éva'), 'not among the latest content');
    $editorDash = $editor('GET', '/admin');
    check(!str_contains($editorDash->body, '/admin/submission') && !str_contains($editorDash->body, 'Beküldés'), 'an editor: nothing');
    check($editor('GET', '/admin/submission')->status === 403 && $editor('GET', '/admin/submission/' . $first->id())->status === 403);

    $list = $admin('GET', '/admin/submission');
    check($list->status === 200 && str_contains($list->body, 'Kovács Éva') && str_contains($list->body, 'Ajánlatkérés') && str_contains($list->body, 'Tárgy nélküli üzenet') && substr_count($list->body, '>új</span>') === 2, 'the list');
    check($admin('GET', '/admin/submission/new')->status === 404 && $admin('GET', '/admin/submission/999999')->status === 404 && $admin('GET', '/admin/submission', [], ['page' => '9'])->status === 404);

    $view = $admin('GET', '/admin/submission/' . $first->id());
    check($view->status === 200 && str_contains($view->body, '&lt;b&gt;Kérek&lt;/b&gt;') && !str_contains($view->body, '<b>Kérek</b>'), 'the message, escaped');
    check(str_contains($view->body, 'href="mailto:eva%40example.hu?subject=Re%3A%20Aj%C3%A1nlatk%C3%A9r%C3%A9s"'), 'reply by e-mail');
    check(str_contains($admin('GET', '/admin/submission')->body, '>1<span class="visually-hidden">'), 'opened: read');
    check(substr_count($admin('GET', '/admin/submission', [], ['unread' => '1'])->body, 'Tóth Gábor') === 1 && !str_contains($admin('GET', '/admin/submission', [], ['unread' => '1'])->body, 'Kovács Éva'), 'only the unread ones');

    check($admin('GET', '/admin/submission/' . $first->id() . '/unread')->status === 405);
    check($admin('POST', '/admin/submission/' . $first->id() . '/unread', ['_csrf' => 'rossz'])->status === 303 && $service->unreadCount(Actor::system()) === 1, 'CSRF');
    $unread = $admin('POST', '/admin/submission/' . $first->id() . '/unread', ['_csrf' => $csrfOf($view)]);
    check($unread->status === 303 && ($unread->headers['Location'] ?? '') === '/admin/submission' && $service->unreadCount(Actor::system()) === 2, 'unread again');
    check($editor('POST', '/admin/submission/' . $first->id() . '/delete', ['_csrf' => $csrfOf($view)])->status === 403);
    $deleted = $admin('POST', '/admin/submission/' . $first->id() . '/delete', ['_csrf' => $csrfOf($view)]);
    check($deleted->status === 303 && $service->find(Actor::system(), (int) $first->id()) === null && str_contains($admin('GET', '/admin/submission')->body, 'Az üzenet törölve'), 'deleted');

    $db->execute("DELETE FROM {objects} WHERE blueprint = 'submission'");
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: the contact form, from the page to a saved message', function () use ($db): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $storage = new ArraySessionStorage();
    $make = static function (array $override = []) use ($storage): \Campanella\Core\Kernel {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $c = $kernel->container();
        $config = $c->get(\Campanella\Core\Config::class);
        if ($override !== []) {
            $c->set(\Campanella\Core\Config::class, static fn () => new \Campanella\Core\Config(array_replace_recursive($config->all(), $override)));
        }
        $c->set(Session::class, static fn () => new Session($storage));

        return $kernel;
    };
    $kernel = $make();
    if ($kernel->container()->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db->execute('DELETE FROM {throttle}');
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'submission'");
    $send = static function (string $method, string $path, array $post = [], array $query = [], string $ip = '10.0.9.1') use (&$kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, query: $query, post: $post, ip: $ip));
    };
    // The form's signed time, as if it had been shown $ago seconds ago.
    $ts = static function (int $ago) use ($storage): string {
        $time = (string) (time() - $ago);

        return $time . '.' . hash_hmac('sha256', 'contact|' . $time, (string) $storage->get(\Campanella\Webform\ContactForm::SESSION_KEY));
    };
    $count = static fn (): int => (int) $db->fetchValue("SELECT COUNT(*) FROM {objects} WHERE blueprint = 'submission'");

    $page = $send('GET', '/contact');
    check($page->status === 200 && str_contains($page->body, 'action="/contact"') && str_contains($page->body, 'name="_ts"') && str_contains($page->body, 'name="website"')
        && str_contains($page->body, 'name="message"') && str_contains($page->body, 'maxlength="5000"') && !isset($page->headers['X-Robots-Tag']), 'the page');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $page->body, $m);
    $csrf = $m[1];
    preg_match('/name="_ts" value="([0-9.a-f]+)"/', $page->body, $m);
    $fields = ['_csrf' => $csrf, 'website' => '', 'name' => 'Szabó Péter', 'email' => 'Peter@Example.hu', 'subject' => 'Kérdés', 'message' => "Helló!\nMikor vagytok nyitva?"];

    // Sent at once: a bot's speed; shown again, nothing saved.
    $fast = $send('POST', '/contact', $fields + ['_ts' => $m[1]]);
    check($fast->status === 422 && str_contains($fast->body, 'nagyon gyorsan') && str_contains($fast->body, 'value="Szabó Péter"') && $count() === 0, 'too fast');
    $fastWrong = $send('POST', '/contact', ['_ts' => $m[1], 'email' => 'nem-cim'] + $fields);
    check($fastWrong->status === 422 && str_contains($fastWrong->body, 'id="contact-email-error"') && !str_contains($fastWrong->body, 'nagyon gyorsan'), 'the fields come first');
    // Forged, missing or old time; a wrong CSRF token.
    check($send('POST', '/contact', $fields + ['_ts' => (time() - 60) . '.' . str_repeat('0', 64)])->status === 400, 'a forged time');
    check($send('POST', '/contact', $fields)->status === 400 && $send('POST', '/contact', $fields + ['_ts' => $ts(90000)])->status === 400, 'missing, or a day old');
    check($send('POST', '/contact', ['_csrf' => 'rossz', '_ts' => $ts(10)] + $fields)->status === 400 && $count() === 0, 'CSRF');

    // A bot filling in the honeypot: "sent", nothing saved.
    $bot = $send('POST', '/contact', ['website' => 'http://spam.example', '_ts' => $ts(10)] + $fields);
    check($bot->status === 303 && ($bot->headers['Location'] ?? '') === '/contact?sent=1' && $count() === 0, 'the honeypot');

    // Missing and invalid fields, escaped when shown again.
    $bad = $send('POST', '/contact', ['_ts' => $ts(10), 'name' => '', 'email' => 'nem-cim', 'message' => '', 'subject' => '<script>alert(1)</script>'] + $fields);
    check($bad->status === 422 && substr_count($bad->body, 'is-invalid') === 3 && str_contains($bad->body, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($bad->body, '<script>alert(1)'), 'invalid: ' . substr_count($bad->body, 'is-invalid'));
    $long = $send('POST', '/contact', ['_ts' => $ts(10), 'subject' => str_repeat('x', 201)] + $fields);
    check($long->status === 422 && str_contains($long->body, 'id="contact-subject-error"'), 'too long');

    // A good one.
    $ok = $send('POST', '/contact', ['_ts' => $ts(10)] + $fields);
    check($ok->status === 303 && ($ok->headers['Location'] ?? '') === '/contact?sent=1' && $count() === 1, 'sent');
    $thanks = $send('GET', '/contact', [], ['sent' => '1']);
    check($thanks->status === 200 && str_contains($thanks->body, 'Köszönjük, megkaptuk') && !str_contains($thanks->body, 'name="message"'));
    $saved = $kernel->container()->get(\Campanella\Service\SubmissionService::class)->page(Actor::system())->items[0];
    $lens = $saved->as(\Campanella\Capability\Submitted::class);
    check($saved->get('title') === 'Szabó Péter' && $lens->email() === 'Peter@example.hu' && $lens->message() === "Helló!\nMikor vagytok nyitva?" && $lens->form() === 'contact' && !$lens->isRead());

    // At most 3 per e-mail address (from any address), 5 per IP address, in an hour.
    $send('POST', '/contact', ['_ts' => $ts(10)] + $fields, [], '10.0.9.2');
    $send('POST', '/contact', ['_ts' => $ts(10)] + $fields, [], '10.0.9.3');
    $limited = $send('POST', '/contact', ['_ts' => $ts(10)] + $fields, [], '10.0.9.4');
    check($limited->status === 429 && str_contains($limited->body, 'Túl sok üzenet') && $count() === 3, 'per e-mail address: ' . $count());
    for ($i = 1; $i <= 6; $i++) {
        $last = $send('POST', '/contact', ['_ts' => $ts(10), 'email' => "masik{$i}@example.hu"] + $fields, [], '10.0.9.5');
    }
    check($last->status === 429 && $count() === 8, 'per IP address: ' . $count());

    // On any page: contact_form(); turned off: 404, and nothing on the pages.
    $twig = $kernel->container()->get(\Twig\Environment::class);
    $html = $twig->createTemplate('{{ contact_form() }}')->render([]);
    check(str_contains($html, 'action="/contact"') && str_contains($html, 'name="_ts"') && str_contains($html, 'name="_csrf"'), 'contact_form()');
    $kernel = $make(['contact' => ['enabled' => false]]);
    check($send('GET', '/contact')->status === 404 && $kernel->container()->get(\Twig\Environment::class)->createTemplate('[{{ contact_form() }}]')->render([]) === '[]', 'turned off');

    $db->execute('DELETE FROM {throttle}');
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'submission'");
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: an e-mail about each new message, after the response, with Reply-To', function () use ($newUser, $db): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $transport = captureTransport();
    $make = static function (array $override = []) use ($transport): \Campanella\Core\Kernel {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $c = $kernel->container();
        $config = $c->get(\Campanella\Core\Config::class);
        $c->set(\Campanella\Core\Config::class, static fn () => new \Campanella\Core\Config(array_replace_recursive($config->all(), ['site' => ['url' => 'https://pelda.example']], $override)));
        $c->set(\Campanella\Mail\Mailer::class, static fn (\Campanella\Core\Container $c) => new \Campanella\Mail\Mailer($transport, 'noreply@example.hu', static fn () => $c->get(\Twig\Environment::class), static fn () => 'Teszt webhely', $c->get(Connection::class), 90, '', null, (string) $c->get(\Campanella\Core\Config::class)->get('timezone', 'UTC')));

        return $kernel;
    };
    $kernel = $make();
    if ($kernel->container()->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db->execute("DELETE FROM {settings} WHERE name = 'site.url'");
    $db->execute("DELETE FROM {objects} WHERE blueprint IN ('submission', 'user')");
    $db->execute('DELETE FROM {mail_log}');
    $newUser('ertesites-admin@example.hu', 'ertesites-admin-1', ['administrator']);
    $newUser('ertesites-szerk@example.hu', 'ertesites-szerk-1', ['editor']);
    $events = [];
    $kernel->container()->get(\Campanella\Event\EventDispatcher::class)->listen(\Campanella\Event\FormSubmitted::class, static function ($e) use (&$events): void { $events[] = $e; });

    $service = $kernel->container()->get(\Campanella\Service\SubmissionService::class);
    $submission = $service->submit("Gonosz\r\nBcc: aldozat@example.hu", 'kuldo@example.hu', 'Titkos tárgy', "Első sor\nMásodik sor");
    check(count($events) === 1 && $events[0]->form === 'contact' && $events[0]->actor === null && $events[0]->summary()->key === 'event.form_submitted', 'the event');
    check($transport->sent === [], 'not before the response');
    $kernel->terminate();
    check(count($transport->sent) === 1, 'only the administrators: ' . count($transport->sent));
    $mail = $transport->sent[0];
    $text = (string) $mail->getTextBody();
    check($mail->getTo()[0]->getAddress() === 'ertesites-admin@example.hu' && $mail->getReplyTo()[0]->getAddress() === 'kuldo@example.hu', 'to the administrator, replies to the sender');
    check(!$mail->getHeaders()->has('Bcc') && !str_contains($mail->getHeaders()->toString(), "\r\nBcc:") && str_contains((string) $mail->getReplyTo()[0]->getName(), 'Gonosz'), 'no header injection');
    check(str_contains((string) $mail->getSubject(), 'Új üzenet a kapcsolati űrlapról') && !str_contains((string) $mail->getSubject(), 'Titkos'), 'a subject without what the sender wrote');
    check(str_contains($text, "Első sor\nMásodik sor") && str_contains($text, 'Tárgy: Titkos tárgy') && str_contains($text, 'https://pelda.example/admin/submission/' . $submission->id()), $text);
    check(!str_contains((string) json_encode($db->fetchAll('SELECT * FROM {mail_log}'), JSON_UNESCAPED_UNICODE), 'Titkos'), 'the log keeps nothing the sender wrote');
    // The time in the site's time zone (since 0.2.0; the default config: Europe/Budapest), not UTC.
    $local = $submission->created()->setTimezone(new \DateTimeZone((string) $kernel->container()->get(\Campanella\Core\Config::class)->get('timezone')))->format('Y-m-d H:i T');
    check(str_contains($text, $local) && !str_contains($text, ' UTC'), 'the time in the site\'s time zone: ' . $local);

    // To given addresses; or nobody.
    $kernel = $make(['contact' => ['notify_to' => ['Info@Example.hu', 'nem-cim', 'info@example.hu']]]);
    $kernel->container()->get(\Campanella\Service\SubmissionService::class)->submit('Valaki', 'valaki@example.hu', '', 'Szia');
    $kernel->terminate();
    check(count($transport->sent) === 2 && $transport->sent[1]->getTo()[0]->getAddress() === 'info@example.hu', 'notify_to: ' . count($transport->sent));
    $kernel = $make(['contact' => ['notify' => false]]);
    $kernel->container()->get(\Campanella\Service\SubmissionService::class)->submit('Valaki', 'valaki@example.hu', '', 'Szia');
    $kernel->terminate();
    check(count($transport->sent) === 2, 'notify: false');
    // Another form's messages: not by default.
    $kernel = $make();
    $kernel->container()->get(\Campanella\Service\SubmissionService::class)->submit('Valaki', 'valaki@example.hu', '', 'Szia', 'hirlevel');
    $kernel->terminate();
    check(count($transport->sent) === 2, 'only the contact form');
    check(\Campanella\Event\Action\MailSubmission::recipientsOf('egy@example.hu') === ['egy@example.hu'] && \Campanella\Event\Action\MailSubmission::recipientsOf(null) === []);
    // At most contact.notify_per_hour messages are e-mailed (2 here); all are kept.
    $db->execute('DELETE FROM {throttle}');
    $kernel = $make(['contact' => ['notify_to' => ['info@example.hu'], 'notify_per_hour' => 2]]);
    $before = count($transport->sent);
    foreach ([1, 2, 3] as $i) {
        $kernel->container()->get(\Campanella\Service\SubmissionService::class)->submit('Valaki ' . $i, "valaki{$i}@example.hu", '', 'Szia');
    }
    $kernel->terminate();
    check(count($transport->sent) - $before === 2 && (int) $db->fetchValue("SELECT COUNT(*) FROM {objects} WHERE blueprint = 'submission'") >= 3, 'the e-mails are capped, the messages kept');

    $db->execute("DELETE FROM {objects} WHERE blueprint IN ('submission', 'user')");
    $db->execute('DELETE FROM {mail_log}');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Mailer::time() and event summaries: the site\'s time zone (since 0.2.0)', function () use ($repository): void {
    $M = \Campanella\Mail\Mailer::class;
    $twig = static fn () => throw new \LogicException('no template needed');
    $budapest = new $M(null, 'noreply@example.hu', $twig, static fn () => 'W', null, 90, '', null, 'Europe/Budapest');
    $utc = new \DateTimeZone('UTC');
    check($budapest->time(new \DateTimeImmutable('2026-07-01 10:00', $utc)) === '2026-07-01 12:00 CEST', 'summer time');
    check($budapest->time(new \DateTimeImmutable('2026-12-01 23:30', $utc)) === '2026-12-02 00:30 CET', 'winter time, the next day');
    check((new $M(null, '', $twig, static fn () => 'W', null, 90, '', null, 'Nincs/Ilyen'))->time(new \DateTimeImmutable('2026-07-01 10:00', $utc)) === '2026-07-01 10:00 UTC', 'an invalid zone: UTC');
    check($M::fromConfig([], $twig, static fn () => 'W', null, 'Europe/Budapest')->time(new \DateTimeImmutable('2026-07-01 10:00', $utc)) === '2026-07-01 12:00 CEST', 'fromConfig');

    // A scheduled publication's time: summary() in UTC as before, summaryWith() as the reader's.
    $article = $repository->create('article', ['title' => 'Időzített', 'path' => '/idozitett-' . bin2hex(random_bytes(3))]);
    $article->as(\Campanella\Capability\Publishable::class)->publish(new \DateTimeImmutable('2030-07-01 10:00', $utc));
    $event = new \Campanella\Event\ObjectPublished($article);
    check($event->isScheduled() && $event->summary()->params['at'] === '2030-07-01 10:00 UTC', (string) json_encode($event->summary()->params));
    check($event->summaryWith($budapest->time(...))->params['at'] === '2030-07-01 12:00 CEST');
});

test('Submissions: old ones deleted after contact.keep_days; the privacy note; the System page', function () use ($repository, $engine, $db): void {
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'submission'");
    $service = new \Campanella\Service\SubmissionService($repository, $engine, new DefaultPolicy(), null, 30);
    $old = $service->submit('Régi', 'regi@example.hu', '', 'Régi üzenet');
    $recent = $service->submit('Friss', 'friss@example.hu', '', 'Friss üzenet');
    $db->execute('UPDATE {objects} SET created_at = :t WHERE id = :id', ['t' => gmdate('Y-m-d H:i:s', time() - 31 * 86400), 'id' => $old->id()]);
    check($service->keepDays() === 30 && $service->prune() === 1, 'one deleted');
    check($repository->find((int) $old->id()) === null && $repository->find((int) $recent->id()) !== null);
    $forever = new \Campanella\Service\SubmissionService($repository, $engine, new DefaultPolicy(), null, 0);
    $db->execute('UPDATE {objects} SET created_at = :t WHERE id = :id', ['t' => gmdate('Y-m-d H:i:s', time() - 400 * 86400), 'id' => $recent->id()]);
    check($forever->prune() === 0 && $repository->find((int) $recent->id()) !== null, '0: kept for ever');

    // The privacy notice's address: a site path or an http(s) URL only.
    $S = \Campanella\Site\SiteSettings::class;
    foreach (['', '/adatkezeles', '/adat?x=1', 'https://example.hu/adatvedelem'] as $ok) {
        check($S::isPrivacyUrl($ok), $ok);
    }
    foreach (['//evil.example', 'javascript:alert(1)', 'data:text/html,x', '/a b', 'https://x.hu/a"b', 'adatkezeles'] as $bad) {
        check(!$S::isPrivacyUrl($bad), $bad);
    }
    $site = new $S(new \Campanella\Settings\Settings($db), ['name' => 'Teszt']);
    throws(ValidationException::class, fn () => $site->save(['name' => 'Teszt', 'privacy_url' => 'javascript:alert(1)']));
    $site->save(['name' => 'Teszt', 'privacy_url' => '/adatkezeles']);
    check($site->privacyUrl() === '/adatkezeles');

    // The System page's lines.
    $form = new \Campanella\Webform\ContactForm($service, new Csrf(new Session(new ArraySessionStorage())), new Throttle($db), new Session(new ArraySessionStorage()));
    $mailOff = \Campanella\Mail\Mailer::fromConfig([], static fn () => throw new \LogicException(), static fn () => 'x');
    $lines = static function (array $config, $submissions, $site) use ($form, $mailOff): array {
        $out = [];
        foreach ((\Campanella\Webform\ContactCheck::checks($config, $form, $submissions, $mailOff, $site, new \Campanella\Http\SitePaths()))(null) as $r) {
            $out[$r->label] = $r->status->name . ':' . $r->value;
        }

        return $out;
    };
    $got = $lines([], $service, $site);
    check($got['admin.system.contact_form'] === 'Ok:/contact' && $got['admin.system.contact_notify'] === 'Warning:admin.system.contact_notify_no_mail'
        && $got['admin.system.contact_keep'] === 'Ok:30' && $got['admin.system.contact_privacy'] === 'Ok:/adatkezeles' && $got['admin.system.contact_unread'] === 'Info:1', json_encode($got));
    $site->save(['name' => 'Teszt', 'privacy_url' => '']);
    $got = $lines(['notify' => false], $forever, $site);
    check($got['admin.system.contact_notify'] === 'Info:admin.system.contact_notify_off' && $got['admin.system.contact_keep'] === 'Warning:admin.system.contact_keep_forever' && $got['admin.system.contact_privacy'] === 'Info:admin.system.contact_privacy_none', json_encode($got));
    $off = (\Campanella\Webform\ContactCheck::checks([], new \Campanella\Webform\ContactForm($service, new Csrf(new Session(new ArraySessionStorage())), new Throttle($db), new Session(new ArraySessionStorage()), enabled: false), $service, $mailOff, $site, new \Campanella\Http\SitePaths()))(null);
    check(count($off) === 1 && $off[0]->value === 'admin.system.contact_off');

    $db->execute("DELETE FROM {objects} WHERE blueprint = 'submission'");
    $db->execute("DELETE FROM {settings} WHERE name LIKE 'site.%'");
});

test('Kernel: the contact form tells what happens to a message; old messages go after a new one', function () use ($db): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $c = $kernel->container();
    if ($c->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $c->set(Session::class, static fn () => new Session(new ArraySessionStorage()));
    $c->get(\Campanella\Site\SiteSettings::class)->save(['name' => 'Teszt webhely', 'privacy_url' => '/adatkezeles']);
    $page = $kernel->handle(new Request('GET', '/contact'));
    check(str_contains($page->body, 'csak a válaszhoz használjuk, és 180 nap után töröljük') && str_contains($page->body, '<a href="/adatkezeles">Adatkezelési tájékoztató</a>'), 'the note and the link');

    $service = $c->get(\Campanella\Service\SubmissionService::class);
    $old = $service->submit('Régi', 'regi@example.hu', '', 'Régi üzenet');
    $db->execute('UPDATE {objects} SET created_at = :t WHERE id = :id', ['t' => gmdate('Y-m-d H:i:s', time() - 181 * 86400), 'id' => $old->id()]);
    $new = $service->submit('Új', 'uj@example.hu', '', 'Új üzenet');
    check($c->get(\Campanella\Core\Deferred::class)->isEmpty() === false);
    $kernel->terminate();
    check($service->find(Actor::system(), (int) $old->id()) === null && $service->find(Actor::system(), (int) $new->id()) !== null, 'the old one went after the response');

    $db->execute("DELETE FROM {objects} WHERE blueprint = 'submission'");
    $db->execute("DELETE FROM {settings} WHERE name LIKE 'site.%'");
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Review fixes (0.1.5): unforgeable time, limits, line breaks, keyed throttle, sitemap, private error pages', function () use ($db, $newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $storage = new ArraySessionStorage();
    $make = static function (array $override = []) use ($storage): \Campanella\Core\Kernel {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $c = $kernel->container();
        $config = $c->get(\Campanella\Core\Config::class);
        $c->set(\Campanella\Core\Config::class, static fn () => new \Campanella\Core\Config(array_replace_recursive($config->all(), ['site' => ['url' => 'https://pelda.example']], $override)));
        $c->set(Session::class, static fn () => new Session($storage));

        return $kernel;
    };
    $kernel = $make();
    if ($kernel->container()->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db->execute('DELETE FROM {throttle}');
    $db->execute("DELETE FROM {objects} WHERE blueprint = 'submission'");
    $db->execute("DELETE FROM {settings} WHERE name LIKE 'site.%'");
    $send = static function (string $method, string $path, array $post = [], string $ip = '10.0.10.1') use (&$kernel, $storage) {
        $storage->endRequest();

        return $kernel->handle(new Request($method, $path, post: $post, ip: $ip));
    };
    $page = $send('GET', '/contact');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $page->body, $m);
    $csrf = $m[1];
    $ts = static function (int $ago) use ($storage): string {
        $time = (string) (time() - $ago);

        return $time . '.' . hash_hmac('sha256', 'contact|' . $time, (string) $storage->get(\Campanella\Webform\ContactForm::SESSION_KEY));
    };
    $fields = ['_csrf' => $csrf, 'website' => '', 'name' => 'Próba', 'email' => 'proba@example.hu', 'subject' => '', 'message' => 'Üzenet'];
    $count = static fn (): int => (int) $db->fetchValue("SELECT COUNT(*) FROM {objects} WHERE blueprint = 'submission'");

    // The time cannot be signed with what the page shows (the CSRF token).
    $time = (string) (time() - 60);
    check($send('POST', '/contact', ['_ts' => $time . '.' . hash_hmac('sha256', 'contact|' . $time, $csrf)] + $fields)->status === 400 && $count() === 0, 'forged with the CSRF token');
    check(!str_contains($page->body, (string) $storage->get(\Campanella\Webform\ContactForm::SESSION_KEY)), 'the secret is not on the page');
    // Without a valid CSRF token, nothing sent is shown again.
    $foreign = $send('POST', '/contact', ['_csrf' => 'rossz', 'message' => 'Hamis üzenet egy másik oldalról'] + $fields);
    check($foreign->status === 400 && !str_contains($foreign->body, 'Hamis üzenet'), 'not shown again');
    // A line break counts as one, as in the browser.
    $long = str_repeat(str_repeat('a', 49) . "\r\n", 100);   // 5,000 in the browser, 5,100 with CRLF
    check($send('POST', '/contact', ['_ts' => $ts(10), 'message' => $long] + $fields)->status === 303 && $count() === 1, 'CRLF');
    // +tags count as the same address.
    $send('POST', '/contact', ['_ts' => $ts(10), 'email' => 'proba+1@example.hu'] + $fields, '10.0.10.2');
    $send('POST', '/contact', ['_ts' => $ts(10), 'email' => 'Proba+2@Example.hu'] + $fields, '10.0.10.3');
    check($send('POST', '/contact', ['_ts' => $ts(10), 'email' => 'proba+3@example.hu'] + $fields, '10.0.10.4')->status === 429 && $count() === 3, '+tags');

    $F = \Campanella\Webform\ContactForm::class;
    check($F::networkOf('2001:db8:1:2::1') === '2001:db8:1::/48' && $F::networkOf('::ffff:192.0.2.1') === '192.0.2.1' && $F::addressKey(' Anna+hirlevel@Example.HU ') === 'anna@example.hu');

    // The throttle keeps only keyed hashes, and forgets expired rows.
    $hashes = $db->fetchColumn('SELECT key_hash FROM {throttle}');
    $siteKey = 'contact-ip|' . \Campanella\Webform\ContactForm::networkOf('10.0.10.1');
    check(!in_array(hash('sha256', $siteKey), $hashes, true) && in_array(hash_hmac('sha256', $siteKey, (string) $db->fetchValue("SELECT value FROM {system} WHERE name = 'throttle_key'")), $hashes, true), 'keyed');
    $db->execute('UPDATE {throttle} SET reset_at = :t', ['t' => gmdate('Y-m-d H:i:s', time() - 1)]);
    check($kernel->container()->get(Throttle::class)->purge() === count($hashes) && (int) $db->fetchValue('SELECT COUNT(*) FROM {throttle}') === 0, 'purged');

    // The contact page is in the sitemap only while the form is on.
    check(str_contains($send('GET', '/sitemap.xml')->body, 'https://pelda.example/contact'));
    $kernel = $make(['contact' => ['enabled' => false]]);
    check(!str_contains($send('GET', '/sitemap.xml')->body, '/contact'), 'not when off');

    // An error page of a visitor with a session is not stored by caches either.
    $kernel = $make();
    $send('GET', '/contact');
    $missing = $send('GET', '/nincs-ilyen-oldal');
    check($missing->status === 404 && ($missing->headers['Cache-Control'] ?? '') === 'private, no-store', json_encode($missing->headers));

    // The command line deletes the old ones.
    $db->execute("UPDATE {objects} SET created_at = :t WHERE blueprint = 'submission'", ['t' => gmdate('Y-m-d H:i:s', time() - 200 * 86400)]);
    $out = fopen('php://memory', 'w+');
    check((new \Campanella\Cli\SubmissionsPruneCommand())->run($kernel->container(), [], new \Campanella\Cli\Output($out, $out)) === 0 && $count() === 0, 'submissions:prune');

    $db->execute('DELETE FROM {throttle}');
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nThe content model: field types and queryable own fields (0.2.2)\n";

test('Field types: decimal, date, e-mail, web address, choice, formatted text', function (): void {
    $price = new Field('price', FieldType::Decimal);
    check($price->cast('1 234,5') === '1234.50' && $price->cast(3) === '3.00' && $price->cast('-0.004') === '0.00', (string) $price->cast('1 234,5'));
    check($price->cast('2.345') === '2.35' && $price->cast('-2.345') === '-2.35' && $price->cast('9.999') === '10.00' && $price->cast(0.1 + 0.2) === '0.30', (string) $price->cast('9.999'));
    check((new Field('n', FieldType::Decimal, scale: 0))->cast('2.5') === '3' && (new Field('n', FieldType::Decimal, scale: 6))->cast('.5') === '0.500000');
    throws(\InvalidArgumentException::class, static fn () => $price->cast('12a'));
    throws(\InvalidArgumentException::class, static fn () => $price->cast('1e5'));
    check($price->invalid('123456789012345.00') instanceof Message && $price->invalid('12345678901234.99') === null, 'at most 14 digits before the point');
    throws(\InvalidArgumentException::class, static fn () => new Field('n', FieldType::Decimal, scale: 7));

    $day = new Field('day', FieldType::Date);
    $value = $day->cast('2026-10-13');
    check($value instanceof \Campanella\Model\CalendarDate && (string) $value === '2026-10-13', get_debug_type($value));
    check($value->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d') === '2026-10-13', 'a date does not move west of UTC');
    check($day->toStorage($value) === '2026-10-13' && (string) $day->fromStorage('2026-10-13 00:00:00') === '2026-10-13', 'storage, also from a DATETIME column');
    check((string) \Campanella\Model\CalendarDate::of(new DateTimeImmutable('2026-10-13 23:30:00', new DateTimeZone('UTC')), new DateTimeZone('Europe/Budapest')) === '2026-10-14', 'the day of a moment in a time zone');
    throws(\InvalidArgumentException::class, static fn () => $day->cast('2026-02-30'));

    $email = new Field('contact', FieldType::Email);
    check($email->invalid('nem-cim') instanceof Message && $email->invalid('info@example.com') === null && $email->invalid('') === null);
    $site = new Field('site', FieldType::Url);
    check($site->invalid('javascript:alert(1)') instanceof Message && $site->invalid('/oldal') instanceof Message && $site->invalid('https://example.com/a?b=1') === null);
    check($site->invalid('https://exa mple.com') instanceof Message, 'no spaces');

    $color = new Field('color', FieldType::Choice, choices: ['red' => 'Piros', 'blue' => 'Kék']);
    check($color->invalid('green') instanceof Message && $color->invalid('red') === null && $color->choiceLabel('blue') === 'Kék');
    throws(\InvalidArgumentException::class, static fn () => new Field('c', FieldType::Choice));
    throws(\InvalidArgumentException::class, static fn () => new Field('c', FieldType::String, choices: ['a' => 'A']));
    throws(\InvalidArgumentException::class, static fn () => new Field('c', FieldType::Choice, choices: ['a b' => 'A']));
    throws(\InvalidArgumentException::class, static fn () => new Field('h', FieldType::Html, cardinality: 2));

    check((new Field('t', FieldType::String, translatable: true))->asData()->translatable, 'translatable is kept by the copies');
    check($color->asTable()->choices === $color->choices && (new Field('m', FieldType::String, storage: FieldStorage::Data, cardinality: 3))->asTable()->usesValueTable());
    check(FieldType::Email->columnType() === \Campanella\Database\Schema\ColumnType::String && FieldType::Html->columnType() === \Campanella\Database\Schema\ColumnType::Text
        && FieldType::Decimal->valueColumn() === 'value_decimal' && FieldType::Date->valueColumn() === 'value_datetime');
});

test('Blueprint: queryable own fields, checked', function (): void {
    $capabilities = new CapabilityRegistry([Titled::class, Textual::class]);
    $registry = new BlueprintRegistry($capabilities, [
        'product' => ['capabilities' => [Titled::class], 'fields' => [new Field('price', FieldType::Decimal), new Field('note', FieldType::Text)], 'queryable' => ['price']],
    ]);
    $product = $registry->get('product');
    check($product->fields['price']->storage === FieldStorage::Table && $product->fields['note']->storage === FieldStorage::Data);
    check($product->tableName() === 'bp_product' && array_keys($product->tableFields()) === ['price'] && $product->table()?->columns[1]->type === \Campanella\Database\Schema\ColumnType::Decimal);
    check(array_map(static fn ($b) => $b->name, $registry->queryableOwners('price')) === ['product'] && $registry->queryableOwners('note') === []);

    throws(CapabilityException::class, static fn () => $registry->define('x', ['capabilities' => [Titled::class], 'fields' => [new Field('a', FieldType::String)], 'queryable' => ['b']]));
    throws(CapabilityException::class, static fn () => $registry->define('offer', ['capabilities' => [Titled::class], 'fields' => [new Field('price', FieldType::Integer)]]));
    throws(CapabilityException::class, static fn () => $registry->define('y', ['capabilities' => [Titled::class], 'fields' => [new Field('code', FieldType::String, length: 300)], 'queryable' => ['code']]));
    $many = [];
    for ($i = 0; $i <= BlueprintRegistry::MAX_TABLE_FIELDS; $i++) {
        $many[] = new Field('f' . $i, FieldType::Integer);
    }
    throws(CapabilityException::class, static fn () => $registry->define('z', ['capabilities' => [Titled::class], 'fields' => $many, 'queryable' => array_map(static fn (Field $f) => $f->name, $many)]));
    // The same field name in two Blueprints: the same type; queryable in one only is fine.
    $registry->define('offer', ['capabilities' => [Titled::class], 'fields' => [new Field('price', FieldType::Decimal)]]);
    check(count($registry->queryableOwners('price')) === 1);
});

test('Queryable own fields: stored in bp_<name>, loaded, filtered and sorted', function () use ($schemaDb, $dropSchema): void {
    $dropSchema();
    $capabilities = new CapabilityRegistry([Titled::class]);
    $registry = new BlueprintRegistry($capabilities, [
        'product' => [
            'capabilities' => [Titled::class],
            'fields' => [
                new Field('price', FieldType::Decimal, indexed: true),
                new Field('released', FieldType::Date),
                new Field('color', FieldType::Choice, choices: ['red' => 'Piros', 'blue' => 'Kék']),
                new Field('tags', FieldType::String, length: 32, cardinality: Field::UNLIMITED),
                new Field('summary', FieldType::Html),
                new Field('note', FieldType::Text),
            ],
            'queryable' => ['price', 'released', 'color', 'tags'],
        ],
        'offer' => ['capabilities' => [Titled::class], 'fields' => [new Field('price', FieldType::Decimal)], 'queryable' => ['price']],
        'plain' => ['capabilities' => [Titled::class]],
    ]);
    $installer = new Installer($schemaDb, $capabilities, null, $registry);
    $installer->install();
    check($installer->differences() === [], 'the tables match');
    $info = (new \Campanella\Database\Schema\SchemaReader($schemaDb))->read('bp_product');
    check($info !== null && $info->column('price')?->type === \Campanella\Database\Schema\ColumnType::Decimal && $info->column('released')?->type === \Campanella\Database\Schema\ColumnType::Date && $info->hasIndex('idx_price'), 'bp_product');
    check($info->column('tags') === null && $info->column('note') === null, 'multi-valued and data fields have no column');

    $repository = new ObjectRepository($schemaDb, $capabilities, $registry);
    $engine = new QueryEngine($schemaDb, new QueryCompiler($capabilities, $registry), $repository, $capabilities, new DefaultPolicy());
    $make = static function (string $blueprint, array $values) use ($repository): int {
        $object = $repository->create($blueprint, $values);
        $repository->save($object);

        return (int) $object->id();
    };
    $a = $make('product', ['title' => 'A', 'price' => '19,90', 'released' => '2026-10-01', 'color' => 'red', 'tags' => ['új', 'akció'], 'summary' => '<p onclick="x()">Jó <script>alert(1)</script>termék</p>', 'note' => 'belső']);
    $b = $make('product', ['title' => 'B', 'price' => '5', 'released' => '2025-01-15', 'color' => 'blue', 'tags' => ['régi']]);
    $c = $make('offer', ['title' => 'C', 'price' => '12.5']);
    $make('plain', ['title' => 'D']);

    $loaded = $repository->find($a);
    check($loaded?->get('price') === '19.90' && (string) $loaded->get('released') === '2026-10-01' && $loaded->get('tags') === ['új', 'akció'] && $loaded->get('note') === 'belső', json_encode($loaded?->values()));
    check($loaded->get('summary') === '<p>Jó termék</p>', 'the Html field is filtered on save: ' . $loaded->get('summary'));
    $row = $schemaDb->fetchOne('SELECT * FROM {bp_product} WHERE object_id = :id', ['id' => $a]);
    check($row !== null && $row['price'] === '19.900000' && $row['released'] === '2026-10-01' && $row['color'] === 'red', json_encode($row));
    $data = json_decode((string) $schemaDb->fetchValue('SELECT data FROM {objects} WHERE id = :id', ['id' => $a]), true);
    check(is_array($data) && !isset($data['price']) && !isset($data['tags']) && isset($data['note']) && isset($data['summary']), 'only the data fields in the JSON: ' . json_encode($data));
    check((int) $schemaDb->fetchValue("SELECT COUNT(*) FROM {field_values} WHERE field = 'tags'") === 3, 'the tags in field_values');

    $ids = static fn (Query $q): array => array_map(static fn ($o): int => (int) $o->id(), $engine->execute($q, Actor::system())->items);
    check($ids(Query::objects()->blueprint('product')->where('price', '>', '10')) === [$a], 'filtered by a decimal');
    check($ids(Query::objects()->blueprint('product')->orderBy('released')) === [$b, $a], 'sorted by a date');
    check($ids(Query::objects()->where('price', '>=', 5)->orderBy('price', 'DESC')) === [$a, $c, $b], 'across two Blueprints (COALESCE)');
    check($ids(Query::objects()->where('released', '=', new \Campanella\Model\CalendarDate('2025-01-15'))) === [$b]);
    check($ids(Query::objects()->where('color', 'IN', ['blue'])) === [$b] && $ids(Query::objects()->where('tags', '=', 'akció')) === [$a]);
    check($ids(Query::objects()->where('tags', '!=', 'akció')->orderBy('id')) === [$b], 'not equal: only the objects of a Blueprint that has the field');
    check($engine->count(Query::objects()->where('price', 'IS NULL'), Actor::system()) === 1, 'the plain object has no price');
    throws(QueryException::class, static fn () => $ids(Query::objects()->where('note', '=', 'x')));
    throws(QueryException::class, static fn () => $ids(Query::objects()->orderBy('tags')));

    // Validation of the types; deleting removes the row.
    $bad = $repository->create('product', ['title' => 'E', 'color' => 'green']);
    try {
        $repository->save($bad);
        check(false, 'an invalid choice was saved');
    } catch (ValidationException $e) {
        check(isset($e->errors['color']), json_encode(array_keys($e->errors)));
    }
    $repository->delete($repository->find($b) ?? throw new RuntimeException());
    check($schemaDb->fetchValue('SELECT COUNT(*) FROM {bp_product} WHERE object_id = :id', ['id' => $b]) == 0, 'deleted with the object');
    $dropSchema();
});

test('SchemaSync: an own field made queryable moves its values, and back', function () use ($schemaDb, $dropSchema): void {
    $dropSchema();
    $capabilities = new CapabilityRegistry([Titled::class]);
    $fields = [new Field('lead', FieldType::Text), new Field('score', FieldType::Integer, required: true, default: 1), new Field('labels', FieldType::String, cardinality: 5)];
    $setup = static function (array $queryable) use ($schemaDb, $capabilities, $fields): array {
        $registry = new BlueprintRegistry($capabilities, ['item' => ['capabilities' => [Titled::class], 'fields' => $fields, 'queryable' => $queryable]]);
        $installer = new Installer($schemaDb, $capabilities, null, $registry);

        return [new \Campanella\Database\Sync\SchemaSync($schemaDb, $installer, $capabilities, $registry), new ObjectRepository($schemaDb, $capabilities, $registry), $installer, $registry];
    };
    $kinds = static fn (\Campanella\Database\Sync\SyncPlan $plan): array => array_map(static fn ($s): string => $s->kind->value . ':' . $s->message->key, $plan->steps);

    [, $repository, $installer] = $setup([]);
    $installer->install();
    $first = $repository->create('item', ['title' => 'Első', 'lead' => 'bevezető', 'score' => 7, 'labels' => ['a', 'b']]);
    $repository->save($first);
    $second = $repository->create('item', ['title' => 'Második']);
    $repository->save($second);

    // Made queryable: the table is created, the values move out of the JSON.
    [$sync, $repository, $installer, $registry] = $setup(['score', 'labels']);
    $plan = $sync->plan();
    check($kinds($plan) === ['create_table:sync.create_table', 'move_data:sync.move_to_table'] && $plan->hasMove(), implode(', ', $kinds($plan)));
    check($plan->steps[1]->message->params['count'] === 2 && $plan->steps[1]->message->params['fields'] === 'score, labels', json_encode($plan->steps[1]->message->params));
    $installer->install(); // the table only (as a new column would be): the values are still read from the data
    $early = $repository->find((int) $first->id());
    check($early?->get('score') === 7 && $early->get('labels') === ['a', 'b'], 'read from the data until moved');
    $sync->apply();
    check(!$sync->plan()->hasWork(), 'done once: ' . implode(', ', $kinds($sync->plan())));
    $row = $schemaDb->fetchOne('SELECT score FROM {bp_item} WHERE object_id = :id', ['id' => $first->id()]);
    check($row !== null && (int) $row['score'] === 7, json_encode($row));
    $data = json_decode((string) $schemaDb->fetchValue('SELECT data FROM {objects} WHERE id = :id', ['id' => $first->id()]), true);
    check(is_array($data) && $data === ['lead' => 'bevezető'], 'left the JSON: ' . json_encode($data));
    check((int) $schemaDb->fetchValue('SELECT score FROM {bp_item} WHERE object_id = :id', ['id' => $second->id()]) === 1, 'the second one, with its default');
    $loaded = $repository->find((int) $first->id());
    check($loaded?->get('score') === 7 && $loaded->get('labels') === ['a', 'b'] && $loaded->get('lead') === 'bevezető', json_encode($loaded?->values()));
    $engine = new QueryEngine($schemaDb, new QueryCompiler($capabilities, $registry), $repository, $capabilities, new DefaultPolicy());
    check($engine->count(Query::objects()->where('score', '>', 5), Actor::system()) === 1, 'queryable now');

    // Not queryable again: copied back into the JSON; the column keeps its values.
    $loaded->set('score', 9);
    $repository->save($loaded);
    [$sync, $repository] = $setup([]);
    $plan = $sync->plan();
    check($kinds($plan) === ['move_data:sync.move_to_data'] && $plan->steps[0]->message->params['fields'] === 'score, labels', implode(', ', $kinds($plan)) . ' ' . json_encode($plan->steps[0]->message->params));
    $sync->apply();
    check(!$sync->plan()->hasWork(), 'done once');
    $back = $repository->find((int) $first->id());
    check($back?->get('score') === 9 && $back->get('labels') === ['a', 'b'] && $back->get('lead') === 'bevezető', json_encode($back?->values()));
    check((int) $schemaDb->fetchValue('SELECT score FROM {bp_item} WHERE object_id = :id', ['id' => $first->id()]) === 9, 'nothing is deleted');

    // And queryable once more: the JSON (edited meanwhile) wins over the old column.
    $back->set('score', 11);
    $repository->save($back);
    [$sync, $repository] = $setup(['score']);
    $sync->apply();
    check($repository->find((int) $first->id())?->get('score') === 11, 'the newer value');
    $dropSchema();
});

test('Admin form and site: the new types', function () use ($engine): void {
    $capabilities = new CapabilityRegistry([Titled::class]);
    $registry = new BlueprintRegistry($capabilities, ['event' => ['capabilities' => [Titled::class], 'fields' => [
        new Field('fee', FieldType::Decimal, label: 'Díj'),
        new Field('day', FieldType::Date),
        new Field('kind', FieldType::Choice, choices: ['talk' => 'Előadás', 'walk' => 'Séta']),
        new Field('contact', FieldType::Email),
        new Field('site', FieldType::Url),
        new Field('details', FieldType::Html),
        new Field('open', FieldType::Boolean),
        new Field('memo', FieldType::Text),
    ]]]);
    $repository = new ObjectRepository(Connection::fromConfig(['prefix' => 'unused_'] + Config::load(dirname(__DIR__) . '/config')->get('database')), $capabilities, $registry);
    $event = $repository->create('event', ['title' => 'Nyílt nap', 'fee' => '1500', 'day' => '2026-10-24', 'kind' => 'walk', 'contact' => 'info@example.com', 'site' => 'https://example.com/?a=1&b=2', 'details' => '<p>Részletek</p>', 'open' => true, 'memo' => "Első sor\n<második>"]);

    $form = new ObjectForm($engine, Translator::fromDirectory(dirname(__DIR__) . '/lang', 'hu'), 'Europe/Budapest');
    $fields = [];
    foreach ($form->build($event, Actor::system()) as $field) {
        $fields[$field->name] = $field;
    }
    check($fields['fee']->widget === 'decimal' && $fields['fee']->value === '1500.00' && $fields['day']->widget === 'date' && $fields['day']->value === '2026-10-24', $fields['day']->value);
    check($fields['kind']->widget === 'choice' && $fields['kind']->options[1] === ['value' => 'walk', 'label' => 'Séta'] && $fields['contact']->widget === 'email' && $fields['site']->widget === 'url');
    check($fields['details']->widget === 'html' && ($fields['details']->attributes['rows'] ?? 0) === 8, 'an Html field gets the editor');
    $read = $form->read($event, ['f' => ['title' => 'X', 'fee' => '2 000,5', 'day' => '2026-11-01', 'kind' => 'talk', 'contact' => ' a@b.hu ', 'site' => '', 'details' => '<p>Új</p>', 'memo' => '']], Actor::system());
    check($read['errors'] === [] && $read['values']['fee'] === '2000.50' && (string) $read['values']['day'] === '2026-11-01' && $read['values']['contact'] === 'a@b.hu', json_encode($read['values']));
    $wrong = $form->read($event, ['f' => ['fee' => '12x', 'day' => '2026-13-01']], Actor::system());
    check(($wrong['errors']['fee'] ?? null)?->key === 'validation.invalid_number' && ($wrong['errors']['day'] ?? null)?->key === 'validation.invalid_date', json_encode(array_keys($wrong['errors'])));

    $translator = Translator::fromDirectory(dirname(__DIR__) . '/lang', 'hu');
    $extension = new \Campanella\View\CampanellaTwigExtension(static fn () => throw new LogicException(), static fn (): string => '', translator: static fn (): Translator => $translator);
    $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([]));
    $twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone('Europe/Budapest');
    $shown = [];
    foreach ($extension->objectFields($twig, $event) as $field) {
        $shown[$field['name']] = (string) $field['html'];
    }
    check(!isset($shown['title']), 'only the own fields');
    check($shown['fee'] === "1\u{a0}500,00" && $shown['day'] === '2026. 10. 24.' && $shown['kind'] === 'Séta' && $shown['open'] === 'igen', json_encode($shown, JSON_UNESCAPED_UNICODE));
    check($shown['contact'] === '<a href="mailto:info@example.com">info@example.com</a>' && $shown['site'] === '<a href="https://example.com/?a=1&amp;b=2">https://example.com/?a=1&amp;b=2</a>', $shown['site']);
    check($shown['memo'] === "Első sor<br>\n&lt;második&gt;" && $shown['details'] === '<p>Részletek</p>', $shown['memo']);

    // The widgets render (with the real templates, in the sandbox).
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $env = $kernel->container()->get(\Twig\Environment::class);
    foreach ($fields as $field) {
        if ($field->kind !== 'field') {
            continue;
        }
        $html = $env->render('@core/admin/form/_row.html.twig', ['field' => $field]);
        check(str_contains($html, 'name="f[' . $field->name . ']"'), $field->widget . ': ' . $html);
    }
    $choice = $env->render('@core/admin/form/_row.html.twig', ['field' => $fields['kind']]);
    check(str_contains($choice, '<option value="walk" selected>Séta</option>'), $choice);
    check(str_contains($env->render('@core/admin/form/_row.html.twig', ['field' => $fields['day']]), 'type="date"'), 'a date input');
    $page = $env->render('@core/object/_fields.html.twig', ['object' => $event]);
    check(str_contains($page, '<dt class="col-sm-4 object-fields__label">Díj</dt>') && str_contains($page, 'Séta') && !str_contains($page, 'Nyílt nap'), $page);
});

echo "\nBlueprints made in the admin (0.2.3)\n";

test('BlueprintDefinition: the stored form, checked', function (): void {
    $stored = ['version' => 1, 'label' => 'Esemény', 'capabilities' => ['textual', 'routable'], 'fields' => [
        ['name' => 'nap', 'type' => 'date', 'label' => 'Nap', 'required' => true, 'queryable' => true],
        ['name' => 'fajta', 'type' => 'choice', 'label' => 'Fajta', 'choices' => [['key' => 'eloadas', 'label' => 'Előadás']], 'default' => 'eloadas'],
    ], 'relations' => [['name' => 'kep', 'label' => 'Kép', 'targets' => ['image']]]];
    $definition = \Campanella\Model\BlueprintDefinition::toRegistry($stored);
    check($definition['capabilities'] === ['titled', 'textual', 'routable'] && $definition['queryable'] === ['nap'], json_encode($definition['capabilities']));
    check($definition['form_order'] === ['title', 'nap', 'fajta', 'body', 'kep'] && $definition['defaults'] === ['format' => 'html']);
    check($definition['fields'][1]->default === 'eloadas' && !$definition['relations'][0]->isMany() && $definition['relations'][0]->targetBlueprints === ['image']);
    $field = \Campanella\Model\BlueprintDefinition::field(['name' => 'ar', 'type' => 'decimal', 'label' => 'Ár', 'scale' => 0, 'default' => '1500']);
    $legacy = \Campanella\Model\BlueprintDefinition::field(['name' => 'f', 'type' => 'choice', 'label' => 'F', 'choices' => ['b' => 'B', 'a' => 'A']]);
    check($legacy->choices === ['b' => 'B', 'a' => 'A'] && \Campanella\Model\BlueprintDefinition::fieldArray($legacy)['choices'] === [['key' => 'b', 'label' => 'B'], ['key' => 'a', 'label' => 'A']], 'the form 0.2.3 wrote is read');
    check(\Campanella\Model\BlueprintDefinition::fieldArray($field) === ['name' => 'ar', 'type' => 'decimal', 'label' => 'Ár', 'required' => false, 'queryable' => false, 'cardinality' => 1, 'translatable' => false, 'length' => 255, 'scale' => 0, 'choices' => [], 'default' => '1500'], json_encode(\Campanella\Model\BlueprintDefinition::fieldArray($field)));

    $bad = static fn (array $change): bool => (static function () use ($stored, $change): bool {
        try {
            \Campanella\Model\BlueprintDefinition::toRegistry(array_replace_recursive($stored, $change));
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    })();
    check($bad(['capabilities' => ['authenticatable']]), 'no system capability');
    check($bad(['fields' => [['type' => 'nope']]]) && $bad(['fields' => [['name' => 'Nap']]]) && $bad(['fields' => [['name' => 'created']]]), 'type and name');
    check($bad(['fields' => [1 => ['name' => 'nap']]]) && $bad(['relations' => [['targets' => ['Kép']]]]) && $bad(['label' => '']), 'duplicate names, an invalid target, an empty label');
    check($bad(['relations' => [['name' => 'nap']]]), 'a relation with a field\'s name');
    check($bad(['fields' => [1 => ['default' => 'nincs']]]) && $bad(['fields' => [0 => ['length' => 300]]]), 'a default not among the choices, a length over 255');

    $registry = new BlueprintRegistry(new CapabilityRegistry([Titled::class, Textual::class, Routable::class]), ['page' => ['capabilities' => [Titled::class]]]);
    $registry->loadStored(['esemeny' => $stored + ['relations' => []], 'page' => $stored, 'hibas' => ['capabilities' => ['authenticatable']]]);
    check($registry->isEditable('esemeny') && $registry->find('hibas') === null, 'loaded, the rest left out');
    check(array_keys($registry->storedErrors()) === ['hibas'], json_encode($registry->storedErrors()));
    // A stored Blueprint keeps its name when the file brings one of it later (since 0.3.2: `file`).
    check($registry->isEditable('page') && $registry->yielded() === ['page'] && $registry->get('page')->label === $stored['label'], 'the stored one wins');
    throws(CapabilityException::class, static fn () => $registry->define('x', ['capabilities' => [Titled::class], 'fields' => [new Field('updated', FieldType::String)]]));
});

test('Kernel: a Blueprint made in the admin, its fields, relations and objects', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        // A new Kernel for each request, as a new page load: the Blueprints are read again.
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $backups = dirname(__DIR__) . '/var/backups';
    $before = glob($backups . '/campanella-*') ?: [];
    $send = function (string $method, string $path, array $post = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $csrf = static fn ($response): string => preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $response->body, $m) === 1 ? $m[1] : '';
    $auth = $container->get(AuthService::class);

    $auth->login(new Request('GET', '/'), $newUser('bp-szerk@example.hu', 'blueprint-jelszo-1', ['editor']));
    check($send('GET', '/admin/system/blueprints/new')->status === 403, 'not for editors');
    $boot()->get(AuthService::class)->login(new Request('GET', '/'), $newUser('bp-admin@example.hu', 'blueprint-jelszo-1', ['administrator']));

    $overview = $send('GET', '/admin/system/blueprints');
    check($overview->status === 200 && str_contains($overview->body, 'Új Blueprint') && str_contains($overview->body, 'kódban megadott'), 'the overview');
    check($send('GET', '/admin/system/blueprints/article')->status === 404, 'a Blueprint of the file cannot be edited');
    $page = $send('GET', '/admin/system/blueprints/new');
    $token = $csrf($page);
    check($page->status === 200 && $token !== '' && str_contains($page->body, 'value="titled"') && !str_contains($page->body, 'value="authenticatable"'), 'the form offers the content capabilities');
    check($send('POST', '/admin/system/blueprints/new', ['_csrf' => 'x', 'name' => 'esemeny', 'label' => 'Esemény'])->status === 400, 'CSRF');
    check($send('POST', '/admin/system/blueprints/new', ['_csrf' => $token, 'name' => 'article', 'label' => 'Cikk'])->status === 422, 'a name taken');
    check($send('POST', '/admin/system/blueprints/new', ['_csrf' => $token, 'name' => 'Esemény', 'label' => 'Esemény'])->status === 422, 'an invalid name');
    $created = $send('POST', '/admin/system/blueprints/new', ['_csrf' => $token, 'name' => 'esemeny', 'label' => 'Esemény', 'capabilities' => ['textual', 'routable', 'publishable', 'authenticatable']]);
    check($created->status === 303 && ($created->headers['Location'] ?? '') === '/admin/system/blueprints/esemeny', (string) $created->status);
    $registry = $boot()->get(BlueprintRegistry::class);
    check($registry->isEditable('esemeny') && array_keys($registry->get('esemeny')->capabilities) === ['titled', 'textual', 'routable', 'publishable'], json_encode(array_keys($registry->get('esemeny')->capabilities)));

    $field = static fn (array $post) => $send('POST', '/admin/system/blueprints/esemeny/fields/new', ['_csrf' => $token] + $post + ['required' => '', 'queryable' => '', 'multiple' => '', 'max' => '', 'length' => '255', 'scale' => '2', 'choices' => '', 'default' => '']);
    check($field(['name' => 'nap', 'label' => 'Nap', 'type' => 'date', 'required' => '1', 'queryable' => '1'])->status === 303, 'a date field');
    check($field(['name' => 'ar', 'label' => 'Ár', 'type' => 'decimal', 'queryable' => '1', 'scale' => '0'])->status === 303, 'a decimal field');
    check($field(['name' => 'fajta', 'label' => 'Fajta', 'type' => 'choice', 'choices' => "eloadas | Előadás\nSéta"])->status === 303, 'a choice field');
    check($field(['name' => 'nap', 'label' => 'Megint', 'type' => 'string'])->status === 422 && $field(['name' => 'title', 'label' => 'Cím', 'type' => 'string'])->status === 422, 'names taken');
    check($field(['name' => 'rossz', 'label' => 'Rossz', 'type' => 'choice', 'choices' => ''])->status === 422, 'a choice field without choices');
    $relation = $send('POST', '/admin/system/blueprints/esemeny/relations/new', ['_csrf' => $token, 'name' => 'kep', 'label' => 'Kép', 'target' => 'image', 'many' => '', 'required' => '']);
    check($relation->status === 303, (string) $relation->status);
    check($send('POST', '/admin/system/blueprints/esemeny/relations/new', ['_csrf' => $token, 'name' => 'tag', 'label' => 'Tag', 'target' => 'user'])->status === 422, 'users are not a target');

    $info = (new \Campanella\Database\Schema\SchemaReader($db))->read('bp_esemeny');
    check($info !== null && $info->column('nap')?->type === \Campanella\Database\Schema\ColumnType::Date && !$info->column('nap')->nullable && $info->column('ar') !== null && $info->column('fajta') === null, 'the table and its columns');
    $stored = $boot()->get(\Campanella\Model\BlueprintStore::class)->find('esemeny');
    check(($stored['fields'][2]['choices'] ?? null) === [['key' => 'eloadas', 'label' => 'Előadás'], ['key' => 'seta', 'label' => 'Séta']], json_encode($stored['fields'][2] ?? null, JSON_UNESCAPED_UNICODE));
    check(array_keys($boot()->get(BlueprintRegistry::class)->get('esemeny')->fields['fajta']->choices) === ['eloadas', 'seta'], 'in their order (MySQL sorts the keys of a JSON object)');

    // Objects of it: the admin's form, the list, the site.
    $form = $send('GET', '/admin/esemeny/new');
    check($form->status === 200 && str_contains($form->body, 'type="date"') && str_contains($form->body, '<option value="seta"') && str_contains($form->body, 'name="r[kep]"'), 'the generic form');
    $repository = $boot()->get(ObjectRepository::class);
    $event = $repository->create('esemeny', ['title' => 'Nyílt nap', 'path' => '/nyilt-nap', 'nap' => '2026-10-24', 'ar' => '1500', 'fajta' => 'seta', 'status' => PublishStatus::Published]);
    $repository->save($event);
    check($send('GET', '/admin/esemeny')->status === 200, 'the list');
    $public = $send('GET', '/nyilt-nap');
    check($public->status === 200 && str_contains($public->body, 'Séta') && str_contains($public->body, '2026. 10. 24.') && str_contains($public->body, "1\u{a0}500"), 'the page shows its fields');

    // With objects: a required queryable field needs a default; adding a column backs up first.
    check($field(['name' => 'hely', 'label' => 'Hely', 'type' => 'string', 'required' => '1', 'queryable' => '1'])->status === 422, 'needs a default');
    $added = $field(['name' => 'hely', 'label' => 'Hely', 'type' => 'string', 'required' => '1', 'queryable' => '1', 'default' => 'Budapest']);
    check($added->status === 303, (string) $added->status);
    check((string) $db->fetchValue('SELECT hely FROM {bp_esemeny} WHERE object_id = :id', ['id' => $event->id()]) === 'Budapest', 'the existing object got the default');
    $made = array_diff(glob($backups . '/campanella-*') ?: [], $before);
    check(count($made) === 1, 'one backup: ' . count($made));
    $edit = $send('GET', '/admin/system/blueprints/esemeny');
    check(str_contains($edit->body, 'Előtte mentés készült') && str_contains($edit->body, 'Hely'), 'the backup is reported');

    // The safe changes: label, order; the type stays.
    check($send('POST', '/admin/system/blueprints/esemeny/fields/ar', ['_csrf' => $token, 'label' => 'Ár (Ft)', 'type' => 'string', 'queryable' => '1', 'length' => '255', 'scale' => '0', 'default' => '', 'choices' => ''])->status === 422, 'not to a narrower type');
    check($send('POST', '/admin/system/blueprints/esemeny/fields/ar', ['_csrf' => $token, 'label' => 'Ár (Ft)', 'type' => 'decimal', 'queryable' => '1', 'length' => '255', 'scale' => '0', 'default' => '', 'choices' => ''])->status === 303);
    check($send('POST', '/admin/system/blueprints/esemeny/fields/ar/move-up', ['_csrf' => $token])->status === 303);
    $fields = $boot()->get(BlueprintRegistry::class)->get('esemeny')->fields;
    check(array_keys($fields) === ['ar', 'nap', 'fajta', 'hely'] && $fields['ar']->label === 'Ár (Ft)' && $fields['ar']->type === FieldType::Decimal, json_encode(array_keys($fields)));
    check($send('POST', '/admin/system/blueprints/esemeny/fields/fajta', ['_csrf' => $token, 'label' => 'Fajta', 'type' => 'choice', 'length' => '255', 'choices' => 'eloadas | Előadás', 'scale' => '2', 'default' => ''])->status === 422, 'a choice in use stays');

    // Deleting: only without objects; the empty table goes too.
    $send('POST', '/admin/system/blueprints/esemeny/delete', ['_csrf' => $token]);
    check($boot()->get(BlueprintRegistry::class)->find('esemeny') !== null, 'not with objects');
    $boot()->get(ObjectRepository::class)->delete($event);
    $deleted = $send('POST', '/admin/system/blueprints/esemeny/delete', ['_csrf' => $token]);
    check($deleted->status === 303 && $boot()->get(BlueprintRegistry::class)->find('esemeny') === null && !(new \Campanella\Database\Schema\SchemaReader($db))->tableExists('bp_esemeny'), 'deleted');

    // A definition changed by hand that does not pass is not loaded, and the overview says so.
    $boot()->get(\Campanella\Model\BlueprintStore::class)->save('kezi', ['label' => 'Kézi', 'capabilities' => ['authenticatable']]);
    check(str_contains($send('GET', '/admin/system/blueprints')->body, 'A(z) „kezi”') || str_contains($send('GET', '/admin/system/blueprints')->body, '„kezi”'), 'reported');
    $boot()->get(\Campanella\Model\BlueprintStore::class)->delete('kezi');

    foreach ($made as $file) {
        @unlink($file);
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nChanging fields (0.2.4)\n";

test('SchemaSync: a field\'s type, column and number of values change, its values follow', function () use ($schemaDb, $dropSchema): void {
    $dropSchema();
    $capabilities = new CapabilityRegistry([Titled::class]);
    $setup = static function (array $fields, array $queryable) use ($schemaDb, $capabilities): array {
        $registry = new BlueprintRegistry($capabilities, ['item' => ['capabilities' => [Titled::class], 'fields' => $fields, 'queryable' => $queryable]]);
        $installer = new Installer($schemaDb, $capabilities, null, $registry);

        return [new \Campanella\Database\Sync\SchemaSync($schemaDb, $installer, $capabilities, $registry), new ObjectRepository($schemaDb, $capabilities, $registry), $installer];
    };
    $kinds = static function (\Campanella\Database\Sync\SyncPlan $plan): array {
        $kinds = array_map(static fn ($s): string => $s->kind->value . ':' . $s->message->key, $plan->steps);
        sort($kinds);

        return $kinds;
    };

    $v1 = [
        new Field('score', FieldType::Integer), new Field('code', FieldType::String, length: 20), new Field('memo', FieldType::String),
        new Field('day', FieldType::Date), new Field('tags', FieldType::String, cardinality: 5), new Field('size', FieldType::Integer),
        new Field('nums', FieldType::Integer, cardinality: Field::UNLIMITED), new Field('level', FieldType::Integer),
        new Field('words', FieldType::String, cardinality: 3),
    ];
    [, $repository, $installer] = $setup($v1, ['score', 'code', 'memo', 'day', 'tags', 'size', 'nums', 'level']);
    $installer->install();
    $first = $repository->create('item', ['title' => 'Első', 'score' => 7, 'code' => 'ABC', 'memo' => 'jegyzet', 'day' => '2026-10-24', 'tags' => ['a', 'b'], 'size' => 3, 'nums' => [1, 2], 'level' => 4, 'words' => ['x', 'y']]);
    $repository->save($first);
    $second = $repository->create('item', ['title' => 'Második', 'code' => 'HOSSZABB-KOD']);
    $repository->save($second);
    $id = (int) $first->id();

    // Widened types, a longer text, one value ↔ several, decimals for several values, required with a default.
    $v2 = [
        new Field('score', FieldType::Decimal), new Field('code', FieldType::String, length: 100), new Field('memo', FieldType::Text),
        new Field('day', FieldType::DateTime), new Field('tags', FieldType::String), new Field('size', FieldType::Integer, cardinality: 4),
        new Field('nums', FieldType::Decimal, cardinality: Field::UNLIMITED), new Field('level', FieldType::Integer, required: true, default: 1),
        new Field('words', FieldType::String),
    ];
    [$sync, $repository, $installer] = $setup($v2, ['score', 'code', 'memo', 'day', 'tags', 'size', 'nums', 'level']);
    $plan = $sync->plan();
    check($kinds($plan) === [
        'add_column:sync.add_column', 'change_column:sync.change_column', 'change_column:sync.change_column', 'change_column:sync.change_column',
        'change_column:sync.change_column', 'change_column:sync.change_column', 'move_data:sync.convert_values', 'move_data:sync.move_to_table',
    ], implode(', ', $kinds($plan)));
    check($plan->changesData(), 'with a backup');
    $sync->apply();
    check(!$sync->plan()->hasWork(), 'done once: ' . implode(', ', $kinds($sync->plan())));
    check($installer->differences() !== [] && array_map(static fn ($d) => $d->kind->value . ' ' . $d->name, $installer->differences()) === ['extra_column size'], 'only the old column of size stays: ' . implode(', ', array_map(static fn ($d) => $d->kind->value . ' ' . $d->name, $installer->differences())));
    $loaded = $repository->find($id);
    check($loaded?->get('score') === '7.00' && $loaded->get('code') === 'ABC' && $loaded->get('memo') === 'jegyzet', json_encode($loaded?->values()));
    check($loaded->get('day') instanceof DateTimeImmutable && $loaded->get('day')->format('Y-m-d H:i') === '2026-10-24 00:00', 'a date became a date and time');
    check($loaded->get('tags') === 'a' && $loaded->get('size') === [3] && $loaded->get('nums') === ['1.00', '2.00'] && $loaded->get('level') === 4 && $loaded->get('words') === 'x', json_encode($loaded->values()));
    check($repository->find((int) $second->id())?->get('level') === 1, 'the empty value got the default');
    check((int) $schemaDb->fetchValue("SELECT COUNT(*) FROM {field_values} WHERE field = 'tags'") === 0 && $schemaDb->fetchValue('SELECT size FROM {bp_item} WHERE object_id = :id', ['id' => $id]) === null, 'the sources were emptied');

    // And back: several values to one column; a shorter text that does not fit; an unknown change.
    [$sync] = $setup([...$v2, new Field('extra', FieldType::String)], ['score', 'code', 'memo', 'day', 'tags', 'size', 'nums', 'level']);
    check(!$sync->plan()->hasWork(), 'nothing new');
    $v3 = array_map(static fn (Field $f): Field => match ($f->name) {
        'code' => new Field('code', FieldType::String, length: 5),
        'memo' => new Field('memo', FieldType::Integer),
        default => $f,
    }, $v2);
    [$sync] = $setup($v3, ['score', 'code', 'memo', 'day', 'tags', 'size', 'nums', 'level']);
    check($kinds($sync->plan()) === ['blocked:sync.blocked_change', 'blocked:sync.blocked_shorter'], implode(', ', $kinds($sync->plan())));

    $back = array_map(static fn (Field $f): Field => $f->name === 'size' ? new Field('size', FieldType::Integer) : $f, $v2);
    [$sync, $repository] = $setup($back, ['score', 'code', 'memo', 'day', 'tags', 'size', 'nums', 'level']);
    check($kinds($sync->plan()) === ['move_data:sync.move_to_table'], implode(', ', $kinds($sync->plan())));
    $sync->apply();
    check($repository->find($id)?->get('size') === 3 && (int) $schemaDb->fetchValue("SELECT COUNT(*) FROM {field_values} WHERE field = 'size'") === 0, 'several values back to one column');
    $dropSchema();
});

test('Kernel: changing a field of a Blueprint made in the admin (0.2.4)', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $backups = dirname(__DIR__) . '/var/backups';
    $before = glob($backups . '/campanella-*') ?: [];
    $send = function (string $method, string $path, array $post = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('bp-valtozas@example.hu', 'blueprint-jelszo-1', ['administrator']));
    $token = preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $send('GET', '/admin/system/blueprints/new')->body, $m) === 1 ? $m[1] : '';
    check($send('POST', '/admin/system/blueprints/new', ['_csrf' => $token, 'name' => 'program', 'label' => 'Program', 'capabilities' => []])->status === 303);
    $base = ['_csrf' => $token, 'required' => '', 'queryable' => '', 'multiple' => '', 'max' => '', 'length' => '255', 'scale' => '2', 'choices' => '', 'default' => ''];
    $field = static fn (string $path, array $post) => $send('POST', '/admin/system/blueprints/program/fields/' . $path, $post + $base);
    check($field('new', ['name' => 'letszam', 'label' => 'Létszám', 'type' => 'integer', 'queryable' => '1'])->status === 303);
    check($field('new', ['name' => 'cimkek', 'label' => 'Címkék', 'type' => 'string', 'queryable' => '1', 'multiple' => '1'])->status === 303);
    check($field('new', ['name' => 'leiras', 'label' => 'Leírás', 'type' => 'string'])->status === 303);
    $repository = $boot()->get(ObjectRepository::class);
    $first = $repository->create('program', ['title' => 'Első', 'letszam' => 12, 'cimkek' => ['a', 'b'], 'leiras' => 'rövid']);
    $repository->save($first);
    $repository->save($repository->create('program', ['title' => 'Második']));

    // A wider type: whole number → decimal; the column changes, with a backup first.
    check($field('letszam', ['label' => 'Létszám', 'type' => 'decimal', 'queryable' => '1', 'scale' => '1'])->status === 303);
    check($boot()->get(ObjectRepository::class)->find((int) $first->id())?->get('letszam') === '12.0', 'the value follows');
    check((new \Campanella\Database\Schema\SchemaReader($db))->read('bp_program')?->column('letszam')?->type === \Campanella\Database\Schema\ColumnType::Decimal);
    // Required: the second object has no value, and there is no default.
    $refused = $field('letszam', ['label' => 'Létszám', 'type' => 'decimal', 'queryable' => '1', 'scale' => '1', 'required' => '1']);
    check($refused->status === 422 && str_contains($refused->body, 'nem tehető kötelezővé'), (string) $refused->status);
    check($field('letszam', ['label' => 'Létszám', 'type' => 'decimal', 'queryable' => '1', 'scale' => '1', 'required' => '1', 'default' => '10'])->status === 303, 'with a default');
    check((string) $db->fetchValue("SELECT MIN(letszam) FROM {bp_program}") === '10.000000', 'the empty value got the default');

    // A text made queryable: its values move into a column; and long text, not queryable again.
    check($field('leiras', ['label' => 'Leírás', 'type' => 'string', 'queryable' => '1'])->status === 303);
    check((string) $db->fetchValue('SELECT leiras FROM {bp_program} WHERE object_id = :id', ['id' => $first->id()]) === 'rövid', 'moved into the column');
    check($field('leiras', ['label' => 'Leírás', 'type' => 'text'])->status === 303);
    $loaded = $boot()->get(ObjectRepository::class)->find((int) $first->id());
    check($loaded?->get('leiras') === 'rövid' && $loaded->get('letszam') === '12.0', json_encode($loaded?->values()));

    // Several values to one: only after confirming it.
    $unconfirmed = $field('cimkek', ['label' => 'Címkék', 'type' => 'string', 'queryable' => '1']);
    check($unconfirmed->status === 422 && str_contains($unconfirmed->body, 'Erősítsd meg'), (string) $unconfirmed->status);
    check($field('cimkek', ['label' => 'Címkék', 'type' => 'string', 'queryable' => '1', 'confirm' => '1'])->status === 303);
    check($boot()->get(ObjectRepository::class)->find((int) $first->id())?->get('cimkek') === 'a', 'the first value stays');

    // Deleting a relation and fields: confirmed, with their links and values.
    check($send('POST', '/admin/system/blueprints/program/relations/new', ['_csrf' => $token, 'name' => 'kapcsolodo', 'label' => 'Kapcsolódó', 'target' => 'program', 'many' => '1', 'required' => ''])->status === 303);
    $second = $boot()->get(\Campanella\Query\QueryEngine::class)->first(Query::objects()->blueprint('program')->where('title', '=', 'Második'), Actor::system());
    $linked = $boot()->get(ObjectRepository::class)->find((int) $first->id());
    $linked->setRelated('kapcsolodo', [(int) $second?->id()]);
    $boot()->get(ObjectRepository::class)->save($linked);
    $page = $send('GET', '/admin/system/blueprints/program/relations/kapcsolodo');
    check(str_contains($page->body, 'A hivatkozásai is törlődnek: 1 darab'), 'the links are counted');
    $send('POST', '/admin/system/blueprints/program/relations/kapcsolodo/delete', ['_csrf' => $token]);
    check($boot()->get(BlueprintRegistry::class)->get('program')->relations !== [], 'not without confirming');
    check($send('POST', '/admin/system/blueprints/program/relations/kapcsolodo/delete', ['_csrf' => $token, 'confirm' => '1'])->status === 303);
    check($boot()->get(BlueprintRegistry::class)->get('program')->relations === [] && (int) $db->fetchValue("SELECT COUNT(*) FROM {relationships} WHERE type = 'kapcsolodo'") === 0, 'the relation and its links are gone');

    check(str_contains($send('GET', '/admin/system/blueprints/program/fields/leiras')->body, 'Az értékei is törlődnek: 1 objektumnak'), 'the values are counted');
    check($send('POST', '/admin/system/blueprints/program/fields/leiras/delete', ['_csrf' => $token, 'confirm' => '1'])->status === 303);
    $info = (new \Campanella\Database\Schema\SchemaReader($db))->read('bp_program');
    check($info !== null && $info->column('leiras') === null && $info->column('letszam') !== null, 'its column is dropped');
    check(!array_key_exists('leiras', $boot()->get(ObjectRepository::class)->find((int) $first->id())?->values() ?? ['leiras' => 1]), 'the field is gone');
    foreach (['cimkek', 'letszam'] as $name) {
        check($send('POST', '/admin/system/blueprints/program/fields/' . $name . '/delete', ['_csrf' => $token, 'confirm' => '1'])->status === 303);
    }
    check(!(new \Campanella\Database\Schema\SchemaReader($db))->tableExists('bp_program') && $boot()->get(BlueprintRegistry::class)->get('program')->fields === [], 'the last column: the table goes too');
    check($boot()->get(ObjectRepository::class)->find((int) $first->id())?->get('title') === 'Első', 'the object stays');

    $made = array_diff(glob($backups . '/campanella-*') ?: [], $before);
    check(count($made) >= 7, 'a backup before each change: ' . count($made));
    foreach ($made as $file) {
        @unlink($file);
    }
    foreach ($boot()->get(\Campanella\Query\QueryEngine::class)->execute(Query::objects()->blueprint('program'), Actor::system())->items as $object) {
        $boot()->get(ObjectRepository::class)->delete($object);
    }
    check($send('POST', '/admin/system/blueprints/program/delete', ['_csrf' => $token])->status === 303, 'cleaned up');
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nLists made in the admin (0.2.5)\n";

test('ListDefinition: the stored form, checked, and its Query', function (): void {
    $capabilities = new CapabilityRegistry([Titled::class, Publishable::class, Routable::class, \Campanella\Capability\Identifiable::class, Authenticatable::class]);
    $blueprints = new BlueprintRegistry($capabilities, [
        'esemeny' => [
            'capabilities' => [Titled::class, Publishable::class, Routable::class],
            'fields' => [
                new Field('nap', FieldType::Date), new Field('fajta', FieldType::Choice, choices: ['eloadas' => 'Előadás', 'seta' => 'Séta']),
                new Field('megjegyzes', FieldType::Text), new Field('cimkek', FieldType::String, cardinality: 3),
            ],
            'queryable' => ['nap', 'fajta', 'cimkek'],
            'relations' => [new Relation('helyszin', Cardinality::One, targetBlueprints: ['esemeny'])],
        ],
        'user' => ['capabilities' => [Titled::class, Authenticatable::class]],
    ]);
    $stored = [
        'title' => 'Közelgő események', 'blueprint' => 'esemeny',
        'conditions' => [['field' => 'nap', 'op' => '>=', 'relative' => 'today'], ['field' => 'fajta', 'op' => '=', 'value' => 'seta'], ['relation' => 'helyszin', 'targets' => [5]]],
        'sort' => [['field' => 'nap', 'dir' => 'asc']], 'per_page' => 20, 'display' => 'table', 'columns' => ['nap', 'fajta', 'megjegyzes'], 'path' => '/Esemenyek/',
    ];
    $list = \Campanella\Lists\ListDefinition::fromArray('kozelgo', $stored, $blueprints);
    check($list->path === '/esemenyek' && $list->columns === ['nap', 'fajta', 'megjegyzes'] && $list->toArray()['conditions'][1] === ['field' => 'fajta', 'op' => '=', 'value' => 'seta'], json_encode($list->toArray()));

    // today: the day in the site's time zone (23:30 UTC is already the next day in Budapest).
    $now = new DateTimeImmutable('2026-10-15 23:30:00', new DateTimeZone('UTC'));
    $compiled = (new QueryCompiler($capabilities, $blueprints))->compile($list->query($blueprints, new DateTimeZone('Europe/Budapest'), $now));
    check(in_array('2026-10-16', $compiled->params, true) && in_array('seta', $compiled->params, true) && in_array(5, $compiled->params, true), json_encode($compiled->params));
    check(str_contains($compiled->sql, 'bp_esemeny') && str_contains($compiled->sql, 'LIMIT 20'), $compiled->sql);

    $bad = static function (array $change) use ($stored, $blueprints): bool {
        try {
            \Campanella\Lists\ListDefinition::fromArray('x', array_replace($stored, $change), $blueprints);
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    };
    check($bad(['blueprint' => 'user']) && $bad(['blueprint' => 'nincs']), 'users cannot be listed; an unknown Blueprint');
    check($bad(['conditions' => [['field' => 'megjegyzes', 'op' => '=', 'value' => 'x']]]), 'not by a field without a column');
    check($bad(['conditions' => [['field' => 'fajta', 'op' => '=', 'value' => 'nincs']]]) && $bad(['conditions' => [['field' => 'fajta', 'op' => '>', 'value' => 'seta']]]), 'a choice not offered; > on a text');
    check($bad(['conditions' => [['field' => 'fajta', 'op' => '=', 'relative' => 'today']]]) && $bad(['conditions' => [['field' => 'nap', 'op' => 'LIKE', 'value' => 'x']]]), 'today only for dates; unknown operators');
    check($bad(['sort' => [['field' => 'cimkek']]]) && $bad(['per_page' => 101]) && $bad(['display' => 'grid']) && $bad(['path' => '/a b']) && $bad(['path' => '/']), 'sorting, per page, display, path');
    check(!$bad(['conditions' => [['field' => 'cimkek', 'op' => '=', 'value' => 'x'], ['field' => 'created', 'op' => '<', 'relative' => 'now'], ['field' => 'title', 'op' => 'contains', 'value' => '50%']]]), 'several values, created, contains');

    $registry = new \Campanella\Lists\ListRegistry(['kozelgo' => $stored, 'rossz' => ['title' => 'Rossz', 'blueprint' => 'nincs']], $blueprints);
    check($registry->find('kozelgo') !== null && $registry->find('rossz') === null && array_keys($registry->errors()) === ['rossz']);
});

test('Kernel: a list\'s page at its path, as a table, with pages; list() in templates', function () use ($repository, $service, $admin): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $made = [];
    foreach (['Lista-0225 első', 'Lista-0225 második', 'Lista-0225 piszkozat'] as $i => $title) {
        $article = $repository->create('article', ['title' => $title, 'lead' => 'Bevezető ' . $i]);
        if ($i < 2) {
            $article->as(Publishable::class)->publish(new DateTimeImmutable('2026-01-0' . ($i + 1) . ' 10:00:00', new DateTimeZone('UTC')));
        }
        $repository->save($article);
        $made[] = $article;
    }
    $store = $container->get(\Campanella\Lists\ListStore::class);
    $store->save('listas', ['version' => 1, 'title' => 'Listás cikkek', 'blueprint' => 'article', 'conditions' => [['field' => 'title', 'op' => 'contains', 'value' => 'Lista-0225']],
        'sort' => [['field' => 'published_at', 'dir' => 'desc']], 'per_page' => 1, 'display' => 'table', 'columns' => ['published_at', 'lead'], 'path' => '/listas-cikkek']);
    $store->save('utkozo', ['version' => 1, 'title' => 'Ütköző', 'blueprint' => 'article', 'path' => '/hirek']);

    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $page = $kernel->handle(new Request('GET', '/listas-cikkek'));
    check($page->status === 200 && str_contains($page->body, '<h1 class="page-title mb-4">Listás cikkek</h1>') && str_contains($page->body, 'Lista-0225 második') && !str_contains($page->body, 'Lista-0225 első'), (string) $page->status);
    check(str_contains($page->body, '<th scope="col">Bevezető</th>') && str_contains($page->body, 'Bevezető 1') && str_contains($page->body, '2026. 01. 02.') && str_contains($page->body, 'page=2'), 'the table and the pager');
    $second = $kernel->handle(new Request('GET', '/listas-cikkek', ['page' => '2']));
    check($second->status === 200 && str_contains($second->body, 'Lista-0225 első') && !str_contains($second->body, 'piszkozat'), 'page 2, without the draft');
    check($kernel->handle(new Request('GET', '/listas-cikkek', ['page' => '3']))->status === 404, 'no page 3');
    check(str_contains($kernel->handle(new Request('GET', '/hirek'))->body, 'Hírek'), 'a route of the file keeps its path');

    $twig = $kernel->container()->get(\Twig\Environment::class);
    $html = $twig->createTemplate("{% for item in list('listas') %}[{{ item.title }}]{% endfor %}{% for item in list('nincs') %}x{% endfor %}")->render([]);
    check($html === '[Lista-0225 második]', $html);

    $store->delete('listas');
    $store->delete('utkozo');
    foreach ($made as $article) {
        $service->delete($admin, $article);
    }
    check((new \Campanella\Core\Kernel(dirname(__DIR__)))->handle(new Request('GET', '/listas-cikkek'))->status === 404, 'gone with its definition');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: lists made in the admin (the editor)', function () use ($newUser, $repository, $service, $admin): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $send = function (string $method, string $path, array $post = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('lista-szerk@example.hu', 'lista-jelszo-12', ['editor']));
    check($send('GET', '/admin/system/lists')->status === 403, 'not for editors');
    $boot()->get(AuthService::class)->login(new Request('GET', '/'), $newUser('lista-admin@example.hu', 'lista-jelszo-12', ['administrator']));
    $overview = $send('GET', '/admin/system/lists');
    check($overview->status === 200 && str_contains($overview->body, 'Új lista') && str_contains($overview->body, 'href="/admin/system/lists"'), 'the overview, in the menu');
    $token = preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $overview->body, $m) === 1 ? $m[1] : '';

    check($send('POST', '/admin/system/lists/new', ['_csrf' => $token, 'name' => 'Rossz név', 'title' => 'X', 'blueprint' => 'article'])->status === 422);
    check($send('POST', '/admin/system/lists/new', ['_csrf' => $token, 'name' => 'felhasznalok', 'title' => 'X', 'blueprint' => 'user'])->status === 422, 'users cannot be listed');
    $created = $send('POST', '/admin/system/lists/new', ['_csrf' => $token, 'name' => 'lista_teszt', 'title' => 'Teszt lista', 'blueprint' => 'article']);
    check($created->status === 303 && ($created->headers['Location'] ?? '') === '/admin/system/lists/lista_teszt', (string) $created->status);
    $edit = $send('GET', '/admin/system/lists/lista_teszt');
    check($edit->status === 200 && str_contains($edit->body, '<option value="published_at"') && str_contains($edit->body, 'name="relations[categories][]"') && !str_contains($edit->body, '<option value="lead"'), 'only queryable fields in the conditions');

    $article = $repository->create('article', ['title' => 'Foglalt út', 'path' => '/foglalt-ut']);
    $repository->save($article);
    $form = ['_csrf' => $token, 'title' => 'Teszt lista', 'per_page' => '5', 'display' => 'table', 'columns' => ['lead', 'published_at'],
        'conditions' => [['field' => 'title', 'op' => 'contains', 'value' => 'Lista', 'relative' => ''], ['field' => 'published_at', 'op' => '<=', 'value' => '', 'relative' => 'now'], ['field' => '', 'op' => '=', 'value' => '', 'relative' => '']],
        'sort' => [['field' => 'published_at', 'dir' => 'desc'], ['field' => '', 'dir' => 'asc']]];
    foreach (['/hirek', '/admin/x', '/foglalt-ut', '/assets/x'] as $taken) {
        $r = $send('POST', '/admin/system/lists/lista_teszt', ['path' => $taken] + $form);
        check($r->status === 422, 'taken: ' . $taken . ' ' . $r->status . ' ' . (preg_match('/alert-danger[^>]*>([^<]*)/', $r->body, $mm) ? $mm[1] : ''));
    }
    $bad = $send('POST', '/admin/system/lists/lista_teszt', ['path' => '/lista-teszt', 'conditions' => [['field' => 'title', 'op' => '>', 'value' => 'x', 'relative' => '']]] + $form);
    check($bad->status === 422 && str_contains($bad->body, 'Érvénytelen'), 'a condition that does not pass');
    check($send('POST', '/admin/system/lists/lista_teszt', ['path' => '/Lista-Teszt'] + $form)->status === 303, 'saved');
    $stored = $boot()->get(\Campanella\Lists\ListStore::class)->find('lista_teszt');
    check(($stored['path'] ?? null) === '/lista-teszt' && count($stored['conditions'] ?? []) === 2 && ($stored['conditions'][1]['relative'] ?? null) === 'now' && ($stored['sort'] ?? null) == [['field' => 'published_at', 'dir' => 'desc']] /* == : MySQL sorts a JSON object's keys */, json_encode($stored));
    check($send('POST', '/admin/system/lists/lista_teszt', ['path' => '/lista-teszt'] + $form)->status === 303, 'its own path is not taken');
    $page = $send('GET', '/lista-teszt');
    check($page->status === 200 && str_contains($page->body, 'Teszt lista') && str_contains($page->body, '<table'), (string) $page->status);
    check(str_contains($send('GET', '/admin/system/lists')->body, 'href="/lista-teszt"'), 'listed, with its page');

    check($send('POST', '/admin/system/lists/lista_teszt/delete', ['_csrf' => $token])->status === 303);
    check($send('GET', '/lista-teszt')->status === 404 && $boot()->get(\Campanella\Lists\ListStore::class)->find('lista_teszt') === null, 'deleted');
    $service->delete($admin, $article);
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nThe content model's export and import (0.2.6)\n";

test('ModelFile: only a content model file of a known version is read', function (): void {
    $bad = static function (string $json): bool {
        try {
            \Campanella\Transfer\ModelFile::decode($json);
        } catch (\InvalidArgumentException) {
            return true;
        }

        return false;
    };
    check($bad('{') && $bad('[]') && $bad('{"format":"x","version":1}'), 'not JSON, not the format');
    check($bad('{"format":"campanella.content-model","version":2}') && $bad('{"format":"campanella.content-model"}'), 'a newer or no version');
    check($bad('{"format":"campanella.content-model","version":1,"blueprints":[{"label":"x"}]}'), 'the Blueprints must be named');
    check($bad('{"format":"campanella.content-model","version":1,"lists":{"a":"x"}}'), 'a definition must be an object');
    check($bad(str_repeat(' ', \Campanella\Transfer\ModelFile::MAX_BYTES + 1)), 'too large');
    check($bad('{"format":"campanella.content-model","version":1,"blueprints":' . str_repeat('{"a":', 20) . '1' . str_repeat('}', 20) . '}'), 'too deep');
    $model = \Campanella\Transfer\ModelFile::decode("\xEF\xBB\xBF" . '{"format":"campanella.content-model","version":1,"blueprints":{},"lists":{}}');
    check($model['blueprints'] === [] && $model['lists'] === [], 'empty, with a byte order mark');
    $json = \Campanella\Transfer\ModelFile::encode(['blueprints' => [], 'lists' => [], 'campanella' => '0.2.6', 'exported_at' => 'x']);
    check(str_contains($json, '"blueprints": {}') && \Campanella\Transfer\ModelFile::decode($json)['campanella'] === '0.2.6', 'written as objects');
});

test('Kernel: the content model exported and imported, with the editor\'s rules', function (): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $boot = static fn () => (new \Campanella\Core\Kernel(dirname(__DIR__)))->container();
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_musor')); // left by a run that failed
    $backups = dirname(__DIR__) . '/var/backups';
    $before = glob($backups . '/campanella-*') ?: [];
    $import = static fn () => $boot()->get(\Campanella\Transfer\ModelImport::class);
    $model = static fn (array $blueprints, array $lists = []): array => ['blueprints' => $blueprints, 'lists' => $lists, 'campanella' => '0.2.6', 'exported_at' => ''];
    $field = static fn (string $name, string $type, array $more = []): array => ['name' => $name, 'type' => $type, 'label' => ucfirst($name)] + $more;
    $item = static function (\Campanella\Transfer\ImportPlan $plan, string $kind, string $name): ?\Campanella\Transfer\ImportItem {
        foreach ($plan->items as $item) {
            if ($item->kind === $kind && $item->name === $name) {
                return $item;
            }
        }

        return null;
    };
    $keys = static fn (?\Campanella\Transfer\ImportItem $i): array => array_map(static fn ($m): string => $m->key, [...($i->changes ?? []), ...($i->warnings ?? []), ...($i?->error !== null ? [$i->error] : [])]);

    // A new Blueprint, from a file.
    $esemeny = ['version' => 1, 'label' => 'Program', 'capabilities' => ['titled', 'routable', 'publishable'],
        'fields' => [$field('nap', 'date', ['queryable' => true]), $field('db', 'integer', ['queryable' => true]), $field('fajta', 'choice', ['choices' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']]])],
        'relations' => [['name' => 'helyszin', 'label' => 'Helyszín', 'targets' => ['category'], 'many' => false]]];
    $plan = $import()->plan($model(['musor' => $esemeny, 'article' => ['label' => 'Cikk'], 'Rossz' => ['label' => 'x']]));
    check($item($plan, 'blueprint', 'musor')?->action === 'create', 'new: ' . json_encode($keys($item($plan, 'blueprint', 'musor'))) . (string) $item($plan, 'blueprint', 'musor')?->error);
    check($item($plan, 'blueprint', 'article')?->action === 'error' && $keys($item($plan, 'blueprint', 'article')) === ['transfer.error.code_blueprint'], 'not a Blueprint of the code');
    check($item($plan, 'blueprint', 'Rossz')?->action === 'error' && $plan->hasErrors(), 'not an invalid name');
    $threw = false;
    try {
        $import()->apply($plan);
    } catch (\RuntimeException) {
        $threw = true;
    }
    check($threw && $boot()->get(\Campanella\Model\BlueprintStore::class)->find('musor') === null, 'with an error, nothing is done');

    $plan = $import()->plan($model(['musor' => $esemeny]));
    check(!$plan->hasErrors() && $plan->hasChanges() && !$plan->backup && $plan->database !== [], 'a plan, without a backup (no objects)');
    check($import()->apply($plan) === null, 'applied');
    $registry = $boot()->get(BlueprintRegistry::class);
    check($registry->isEditable('musor') && isset($registry->get('musor')->fields['nap']) && (new \Campanella\Database\Schema\SchemaReader($db))->read('bp_musor')?->column('db') !== null, 'the Blueprint and its table');
    check(!$import()->plan($model(['musor' => $esemeny]))->hasChanges(), 'again: unchanged');

    // With an object: only the safe changes.
    $repository = $boot()->get(ObjectRepository::class);
    $kat = $repository->create('category', ['title' => 'Kat-0226', 'path' => '/kat-0226']);
    $repository->save($kat);
    $obj = $repository->create('musor', ['title' => 'Program-0226', 'path' => '/program-0226', 'nap' => '2026-11-01', 'db' => 3, 'fajta' => 'a', 'status' => PublishStatus::Published]);
    $repository->save($obj);
    $changed = $esemeny;
    $changed['label'] = 'Programok';
    $changed['fields'][1] = $field('db', 'decimal', ['queryable' => true, 'scale' => 1]);
    $changed['fields'][] = $field('hely', 'string', ['queryable' => true, 'required' => true, 'default' => 'Pécs']);
    unset($changed['fields'][0]);
    $changed['fields'] = array_values($changed['fields']);
    $plan = $import()->plan($model(['musor' => $changed]));
    $line = $item($plan, 'blueprint', 'musor');
    check(!$plan->hasErrors() && $line?->action === 'update' && $plan->backup, json_encode($keys($line)));
    check(in_array('transfer.change.label', $keys($line), true) && in_array('transfer.change.field.type', $keys($line), true) && in_array('transfer.change.field_added', $keys($line), true) && in_array('transfer.warning.field_kept', $keys($line), true), json_encode($keys($line)));
    $file = $import()->apply($plan);
    check($file !== null && is_file($file), 'a backup first');
    $fields = $boot()->get(BlueprintRegistry::class)->get('musor')->fields;
    check(array_keys($fields) === ['db', 'fajta', 'hely', 'nap'] && $fields['db']->type === FieldType::Decimal, 'the field not in the file is kept, at the end: ' . json_encode(array_keys($fields)));
    check((string) $db->fetchValue('SELECT hely FROM {bp_musor} WHERE object_id = :id', ['id' => $obj->id()]) === 'Pécs', 'the new column has the default');

    $narrow = $changed;
    $narrow['fields'][0] = $field('db', 'integer', ['queryable' => true]);
    check($keys($item($import()->plan($model(['musor' => $narrow])), 'blueprint', 'musor')) !== [] && $item($import()->plan($model(['musor' => $narrow])), 'blueprint', 'musor')?->error?->key === 'transfer.error.type_narrower', 'not to a narrower type');
    $choices = $changed;
    $choices['fields'][1] = $field('fajta', 'choice', ['choices' => [['key' => 'b', 'label' => 'B']]]);
    check($item($import()->plan($model(['musor' => $choices])), 'blueprint', 'musor')?->error?->key === 'transfer.error.choices_removed', 'a choice stays');
    $target = $changed;
    $target['relations'][0]['many'] = true;
    check($item($import()->plan($model(['musor' => $target])), 'blueprint', 'musor')?->error?->key === 'transfer.error.relation_changed', 'a relation\'s number stays');
    $required = $changed;
    $required['fields'][] = $field('uj', 'string', ['queryable' => true, 'required' => true]);
    check($item($import()->plan($model(['musor' => $required])), 'blueprint', 'musor')?->error?->key === 'transfer.error.needs_default', 'a new required column needs a default');
    $other = $import()->plan($model(['kezi_0226' => ['label' => 'Kézi', 'capabilities' => ['authenticatable']]]));
    check($item($other, 'blueprint', 'kezi_0226')?->error?->key === 'transfer.error.invalid', 'checked like a stored one');
    check($item($other, 'blueprint', 'musor')?->action === 'kept', 'what the file does not have stays');

    // Lists: the objects of a relation condition by reference.
    $list = ['version' => 1, 'title' => 'Programok', 'blueprint' => 'musor', 'conditions' => [['field' => 'nap', 'op' => '>=', 'relative' => 'today'], ['relation' => 'helyszin', 'targets' => [['blueprint' => 'category', 'path' => '/kat-0226']]]],
        'sort' => [['field' => 'nap', 'dir' => 'asc']], 'per_page' => 10, 'display' => 'table', 'columns' => ['nap'], 'path' => '/programok-0226'];
    $plan = $import()->plan($model([], ['programok' => $list]));
    check(!$plan->hasErrors() && $item($plan, 'list', 'programok')?->action === 'create' && $plan->lists['programok']['conditions'][1]['targets'] === [(int) $kat->id()], json_encode($plan->lists));
    $import()->apply($plan);
    $export = $boot()->get(\Campanella\Transfer\ModelExport::class)->model();
    check(isset($export['blueprints']['musor']) && !isset($export['blueprints']['article']), 'the export: the Blueprints made in the admin');
    $refs = $export['lists']['programok']['conditions'][1]['targets'] ?? null;
    check(($refs[0]['blueprint'] ?? null) === 'category' && ($refs[0]['path'] ?? null) === '/kat-0226' && ($refs[0]['title'] ?? null) === 'Kat-0226', 'by reference: ' . json_encode($refs, JSON_UNESCAPED_UNICODE));
    $round = \Campanella\Transfer\ModelFile::decode(\Campanella\Transfer\ModelFile::encode($export));
    $again = $import()->plan($round);
    check(!$again->hasErrors() && !$again->hasChanges(), 'its own export changes nothing: ' . json_encode(array_map(static fn ($i) => [$i->name, $i->action, array_map('strval', $i->changes)], $again->items), JSON_UNESCAPED_UNICODE));

    $ids = $list;
    $ids['conditions'][1]['targets'] = [(int) $kat->id()];
    check($item($import()->plan($model([], ['programok' => $ids])), 'list', 'programok')?->error?->key === 'transfer.error.list_ids', 'not by ID');
    $missing = $list;
    $missing['conditions'][1]['targets'] = [['blueprint' => 'category', 'title' => 'Nincs ilyen']];
    check($item($import()->plan($model([], ['programok' => $missing])), 'list', 'programok')?->error?->key === 'transfer.error.no_targets', 'objects not on the site');
    $half = $list;
    $half['conditions'][1]['targets'][] = ['blueprint' => 'category', 'title' => 'Nincs ilyen'];
    check(in_array('transfer.warning.target_missing', $keys($item($import()->plan($model([], ['programok' => $half])), 'list', 'programok')), true), 'one missing: a warning');
    $taken = $list;
    $taken['path'] = '/kat-0226';
    check($item($import()->plan($model([], ['programok' => $taken])), 'list', 'programok')?->error?->key === 'transfer.error.path_taken', 'a path taken');
    $two = $import()->plan($model([], ['masik' => ['path' => '/egyforma-0226'] + $list, 'harmadik' => ['path' => '/egyforma-0226'] + $list]));
    check($item($two, 'list', 'harmadik')?->error?->key === 'transfer.error.path_taken', 'two lists of the file at one path');
    $retitled = ['title' => 'Programjaink'] + $list;
    $line = $item($import()->plan($model([], ['programok' => $retitled])), 'list', 'programok');
    check($line?->action === 'update' && $keys($line) === ['transfer.change.list.title'], json_encode($keys($line)));

    // The command line.
    $run = static function (\Campanella\Cli\Command $command, array $args) use ($boot): array {
        $stream = fopen('php://memory', 'w+');
        $code = $command->run($boot(), $args, new \Campanella\Cli\Output($stream, $stream));
        rewind($stream);

        return [$code, (string) stream_get_contents($stream)];
    };
    $path = sys_get_temp_dir() . '/campanella-model-' . bin2hex(random_bytes(4)) . '.json';
    [$code, $out] = $run(new \Campanella\Cli\ModelExportCommand(), [$path]);
    check($code === 0 && is_file($path) && str_contains($out, '1 Blueprint'), $out);
    [$code] = $run(new \Campanella\Cli\ModelExportCommand(), [$path]);
    check($code === 1, 'not overwritten without --force');
    [$code, $out] = $run(new \Campanella\Cli\ModelExportCommand(), []);
    check($code === 0 && str_contains($out, '"format": "campanella.content-model"'), 'printed');
    $data = json_decode((string) file_get_contents($path), true);
    $data['lists']['programok']['title'] = 'Programjaink';
    file_put_contents($path, json_encode($data));
    [$code, $out] = $run(new \Campanella\Cli\ModelImportCommand(), [$path]);
    check($code === 0 && str_contains($out, 'A címe') && str_contains($out, '--yes') && $boot()->get(\Campanella\Lists\ListStore::class)->find('programok')['title'] === 'Programok', $out);
    [$code, $out] = $run(new \Campanella\Cli\ModelImportCommand(), [$path, '--yes']);
    check($code === 0 && str_contains($out, 'Importálva') && $boot()->get(\Campanella\Lists\ListStore::class)->find('programok')['title'] === 'Programjaink', $out);
    file_put_contents($path, '{"format":"x"}');
    [$code, $out] = $run(new \Campanella\Cli\ModelImportCommand(), [$path]);
    check($code === 1 && str_contains($out, 'nem olvasható be'), $out);
    @unlink($path);

    // Cleaning up.
    $boot()->get(\Campanella\Lists\ListStore::class)->delete('programok');
    $repository = $boot()->get(ObjectRepository::class);
    $repository->delete($obj);
    $repository->delete($kat);
    $boot()->get(\Campanella\Model\BlueprintStore::class)->delete('musor');
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_musor'));
    foreach (array_diff(glob($backups . '/campanella-*') ?: [], $before) as $made) {
        @unlink($made);
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: the content model page in the admin: download, plan, confirm', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_hirlevel')); // left by a run that failed
    $send = function (string $method, string $path, array $post = [], array $files = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, post: $post, files: $files));
    };
    $upload = static function (string $json): array {
        $path = tempnam(sys_get_temp_dir(), 'cm');
        file_put_contents($path, $json);

        return ['file' => new \Campanella\Http\UploadedFile('campanella-model.json', $path, strlen($json))];
    };
    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('modell-szerk@example.hu', 'modell-jelszo-12', ['editor']));
    check($send('GET', '/admin/system/model')->status === 403 && $send('GET', '/admin/system/model/export')->status === 403, 'not for editors');
    $boot()->get(AuthService::class)->login(new Request('GET', '/'), $newUser('modell-admin@example.hu', 'modell-jelszo-12', ['administrator']));

    $page = $send('GET', '/admin/system/model');
    check($page->status === 200 && str_contains($page->body, 'href="/admin/system/model"') && str_contains($page->body, 'enctype="multipart/form-data"'), 'the page, in the menu');
    $token = preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $page->body, $m) === 1 ? $m[1] : '';
    $download = $send('GET', '/admin/system/model/export');
    check($download->status === 200 && str_starts_with($download->headers['Content-Type'] ?? '', 'application/json') && str_contains($download->headers['Content-Disposition'] ?? '', 'campanella-model.json')
        && \Campanella\Transfer\ModelFile::decode($download->body)['blueprints'] === $boot()->get(\Campanella\Model\BlueprintStore::class)->load(), 'the download');

    $model = static fn (array $blueprints): string => json_encode(['format' => 'campanella.content-model', 'version' => 1, 'blueprints' => $blueprints, 'lists' => new \stdClass()]);
    $hirlevel = ['label' => 'Hírlevél', 'capabilities' => ['titled'], 'fields' => [['name' => 'szam', 'type' => 'integer', 'label' => 'Szám', 'queryable' => true]], 'relations' => []];
    check($send('POST', '/admin/system/model/import', ['_csrf' => 'x'], $upload($model(['hirlevel' => $hirlevel])))->status === 400, 'CSRF');
    check($send('POST', '/admin/system/model/import', ['_csrf' => $token])->status === 422, 'no file');
    $bad = $send('POST', '/admin/system/model/import', ['_csrf' => $token], $upload('{"format":"valami"}'));
    check($bad->status === 422 && str_contains($bad->body, 'nem olvasható be'), 'not a content model');
    $error = $send('POST', '/admin/system/model/import', ['_csrf' => $token], $upload($model(['article' => ['label' => 'Cikk']])));
    check($error->status === 422 && str_contains($error->body, 'kódban van megadva') && !str_contains($error->body, 'system/model/apply'), 'an error: no confirming');

    $plan = $send('POST', '/admin/system/model/import', ['_csrf' => $token], $upload($model(['hirlevel' => $hirlevel])));
    check($plan->status === 200 && str_contains($plan->body, 'Hírlevél') && str_contains($plan->body, 'action="/admin/system/model/apply"'), 'the plan');
    check($boot()->get(\Campanella\Model\BlueprintStore::class)->find('hirlevel') === null, 'nothing changed yet');
    preg_match('/name="model" value="([^"]*)"/', $plan->body, $mm);
    preg_match('/name="fingerprint" value="([0-9a-f]{64})"/', $plan->body, $fm);
    $changed = $send('POST', '/admin/system/model/apply', ['_csrf' => $token, 'model' => $mm[1] ?? '', 'fingerprint' => str_repeat('0', 64)]);
    check($changed->status === 409 && str_contains($changed->body, 'változott') && $boot()->get(\Campanella\Model\BlueprintStore::class)->find('hirlevel') === null, 'the plan changed: shown again');
    check($send('POST', '/admin/system/model/apply', ['_csrf' => $token, 'model' => '!!!', 'fingerprint' => $fm[1] ?? ''])->status === 422, 'not a file');
    $done = $send('POST', '/admin/system/model/apply', ['_csrf' => $token, 'model' => $mm[1] ?? '', 'fingerprint' => $fm[1] ?? '']);
    check($done->status === 303 && ($done->headers['Location'] ?? '') === '/admin/system/model', (string) $done->status);
    check($boot()->get(BlueprintRegistry::class)->isEditable('hirlevel') && (new \Campanella\Database\Schema\SchemaReader($db))->tableExists('bp_hirlevel'), 'imported');
    check(str_contains($send('GET', '/admin/system/model')->body, 'Importálva: 1 Blueprint'), 'said so');
    $again = $send('POST', '/admin/system/model/import', ['_csrf' => $token], $upload($model(['hirlevel' => $hirlevel])));
    check($again->status === 200 && str_contains($again->body, 'nincs mit változtatni') && !str_contains($again->body, 'system/model/apply'), 'again: nothing to do');

    $boot()->get(\Campanella\Model\BlueprintStore::class)->delete('hirlevel');
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_hirlevel'));
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nSearch, redirects, the image picker (0.2.7)\n";

test('Schema: FULLTEXT indexes are made, read and compared', function () use ($db): void {
    $table = \Campanella\Database\Schema\CoreSchema::tables();
    $search = array_values(array_filter($table, static fn ($t): bool => $t->name === 'search_index'))[0];
    $sql = (new \Campanella\Database\Schema\SchemaBuilder($db))->createSql($search);
    check(str_contains($sql, 'FULLTEXT KEY `ft_text` (`title`, `content`)'), $sql);
    check(str_contains((new \Campanella\Database\Schema\SchemaBuilder($db))->addIndexSql($search, 'ft_title'), 'ADD FULLTEXT INDEX `ft_title`'), 'added later');
    $info = (new \Campanella\Database\Schema\SchemaReader($db))->read('search_index');
    check($info !== null && $info->fulltexts === ['ft_text' => ['title', 'content'], 'ft_title' => ['title']] && !isset($info->indexes['ft_text']), json_encode($info?->fulltexts));
    $comparator = new \Campanella\Database\Schema\SchemaComparator(new \Campanella\Database\Schema\SchemaReader($db), new \Campanella\Database\Schema\SchemaBuilder($db));
    check($comparator->compareTable($search, $info) === [], 'the installed table matches');
    $plain = new \Campanella\Database\Schema\Table($search->name, $search->columns, $search->primaryKey, $search->indexes + ['ft_text' => ['title', 'content']], foreignKeys: $search->foreignKeys, fulltexts: ['ft_title' => ['title']]);
    $kinds = array_map(static fn ($d): string => $d->kind->name . ':' . $d->name, $comparator->compareTable($plain, $info));
    check($kinds === ['IndexColumns:ft_text'], 'a FULLTEXT index is not an ordinary one: ' . json_encode($kinds));
});

test('Search: the words, the text of an object, the excerpt', function (): void {
    $S = \Campanella\Search\Search::class;
    check($S::words('A kert, és a KERTÉSZ-ház; kert 2026') === ['kert', 'kertesz', 'haz', '2026'], json_encode($S::words('A kert, és a KERTÉSZ-ház; kert 2026'), JSON_UNESCAPED_UNICODE));
    check($S::words('the kert with www') === ['kert'] && \Campanella\Search\SearchIndex::fold('Árvíztűrő TÜKÖRFÚRÓGÉP') === 'arvizturo tukorfurogep', 'stop words left out; accents folded');
    check($S::words('+"x"* -- ()') === [] && count($S::words(str_repeat('szó ', 3) . 'egy kettő három négy öt hat hét nyolc kilenc tíz')) === 8, 'no operators; at most 8');
    check(\Campanella\Search\SearchIndex::fromHtml('<h2>Cím</h2><p>Első&nbsp;bekezdés &amp; <b>vastag</b></p><script>x()</script><p>Második</p>') === "Cím Első\u{a0}bekezdés & vastag Második" || \Campanella\Search\SearchIndex::fromHtml('<h2>Cím</h2><p>Első bekezdés &amp; <b>vastag</b></p><script>x()</script><p>Második</p>') === 'Cím Első bekezdés & vastag Második', json_encode(\Campanella\Search\SearchIndex::fromHtml('<h2>Cím</h2><p>Első bekezdés &amp; <b>vastag</b></p><script>x()</script><p>Második</p>'), JSON_UNESCAPED_UNICODE));
    $text = str_repeat('Előtte sok szöveg van itt. ', 10) . 'A kertben virágok nyílnak, a Kert szép.' . str_repeat(' Utána is.', 30);
    $pieces = $S::excerpt($text, ['kert']);
    $marked = array_values(array_map(static fn ($p) => $p['text'], array_filter($pieces, static fn ($p) => $p['mark'])));
    check($marked === ['kertben', 'Kert'] && str_starts_with($pieces[0]['text'], '…') && str_ends_with(end($pieces)['text'], '…'), json_encode($pieces, JSON_UNESCAPED_UNICODE));
    check(implode('', array_column($pieces, 'text')) !== '' && mb_strlen(implode('', array_column($pieces, 'text'))) <= $S::EXCERPT + 2, 'short');
});

test('Kernel: the search page finds the published pages, the title counts more; the index is built for old pages', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $send = function (string $method, string $path, array $query = [], array $post = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, $query, $post));
    };
    $repository = $container->get(ObjectRepository::class);
    $made = [];
    foreach ([
        ['title' => 'Zöldségeskert-0227 nyáron', 'body' => '<p>Paradicsom és <b>paprika</b> a kertben.</p>', 'status' => PublishStatus::Published],
        ['title' => 'Virágok-0227', 'body' => '<p>A zöldségeskert-0227 mellett a virágágyás.</p>', 'status' => PublishStatus::Published],
        ['title' => 'Piszkozat-0227 zöldségeskert-0227', 'body' => '<p>Titkos.</p>', 'status' => PublishStatus::Draft],
    ] as $i => $values) {
        $article = $repository->create('article', $values + ['path' => '/kereses-teszt-' . $i, 'format' => 'html', 'published_at' => new DateTimeImmutable('-1 hour')]);
        $repository->save($article);
        $made[] = $article;
    }
    $row = $db->fetchOne('SELECT title, content, blueprint FROM {search_index} WHERE object_id = :id', ['id' => $made[0]->id()]);
    check(($row['title'] ?? '') === 'zoldsegeskert-0227 nyaron' && str_contains((string) ($row['content'] ?? ''), 'paradicsom es paprika a kertben.') && ($row['blueprint'] ?? '') === 'article', json_encode($row, JSON_UNESCAPED_UNICODE));

    $page = $send('GET', '/search', ['q' => 'zöldségeskert 0227']);
    check($page->status === 200 && str_contains($page->body, 'name="robots" content="noindex"'), (string) $page->status);
    $first = strpos($page->body, 'href="/kereses-teszt-0"');
    $second = strpos($page->body, 'href="/kereses-teszt-1"');
    check($first !== false && $second !== false && $first < $second, 'both, the one with it in its title first');
    check(!str_contains($page->body, '/kereses-teszt-2') && !str_contains($page->body, 'Titkos'), 'not the draft');
    check(str_contains($page->body, '<mark>Zöldségeskert</mark>-<mark>0227</mark>') || str_contains($page->body, '<mark>zöldségeskert</mark>-<mark>0227</mark>'), 'the words marked');
    check(str_contains($send('GET', '/search', ['q' => 'paprik'])->body, '/kereses-teszt-0'), 'the start of a word');
    check(str_contains($send('GET', '/search', ['q' => 'zoldsegeskert'])->body, '/kereses-teszt-1') && str_contains($send('GET', '/search', ['q' => 'VIRÁGÁGY'])->body, '/kereses-teszt-1'), 'with or without accents, in any case');
    check(str_contains($send('GET', '/search', ['q' => 'ab'])->body, 'legalább egy, 3'), 'too short');
    check(str_contains($send('GET', '/search', ['q' => 'nincsilyenszo0227'])->body, 'Nincs találat'), 'nothing');
    check(str_contains($send('GET', '/search', ['q' => '<script>'])->body, '&lt;script&gt;') || !str_contains($send('GET', '/search', ['q' => '<script>'])->body, '<script>x'), 'escaped');
    check($send('GET', '/search', ['q' => 'zöldségeskert', 'page' => '9'])->status === 404, 'a page beyond the last');
    check(str_contains($send('GET', '/')->body, 'action="/search"'), 'the form in the header');

    // An editor finds only the published ones too; a page deleted leaves the index.
    $boot()->get(AuthService::class)->login(new Request('GET', '/'), $newUser('kereso-admin@example.hu', 'kereso-jelszo-12', ['administrator']));
    check(!str_contains($send('GET', '/search', ['q' => 'piszkozat 0227'])->body, '/kereses-teszt-2'), 'not even for an administrator');

    // Pages saved before the index: the System page, the button, the command line.
    $db->execute('DELETE FROM {search_index} WHERE object_id IN (:a, :b)', ['a' => $made[0]->id(), 'b' => $made[1]->id()]);
    $indexer = $boot()->get(\Campanella\Search\SearchIndexer::class);
    check($indexer->missing() >= 2, (string) $indexer->missing());
    $system = $send('GET', '/admin/system');
    check(str_contains($system->body, 'még nincs a keresésben') && str_contains($system->body, 'system/search-index'), 'the System page offers it');
    $token = preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $system->body, $m) === 1 ? $m[1] : '';
    check($send('POST', '/admin/system/search-index', [], ['_csrf' => $token])->status === 303 && $boot()->get(\Campanella\Search\SearchIndexer::class)->missing() === 0, 'built');
    check(str_contains($send('GET', '/search', ['q' => 'zöldségeskert'])->body, '/kereses-teszt-1'), 'found again');
    $stream = fopen('php://memory', 'w+');
    $code = (new \Campanella\Cli\SearchIndexCommand())->run($boot(), ['--all'], new \Campanella\Cli\Output($stream, $stream));
    rewind($stream);
    check($code === 0 && str_contains((string) stream_get_contents($stream), 'oldal indexelve'), 'search:index --all');

    $id = $made[1]->id();
    $repository = $boot()->get(ObjectRepository::class);
    foreach ($made as $article) {
        $repository->delete($article);
    }
    check($db->fetchValue('SELECT 1 FROM {search_index} WHERE object_id = :id', ['id' => $id]) === null, 'deleted with its object');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: a changed path leads on (301), to the object\'s current path; redirects made in the admin', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $send = function (string $method, string $path, array $query = [], array $post = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, $query, $post));
    };
    $repository = $container->get(ObjectRepository::class);
    $article = $repository->create('article', ['title' => 'Átirányítás-0227', 'path' => '/atiranyitas-a', 'status' => PublishStatus::Published, 'published_at' => new DateTimeImmutable('-1 hour')]);
    $repository->save($article);
    check((int) $db->fetchValue('SELECT COUNT(*) FROM {redirects} WHERE object_id = :id', ['id' => $article->id()]) === 0, 'a new object: none');
    $article->set('path', '/atiranyitas-b');
    $repository->save($article);
    $moved = $send('GET', '/atiranyitas-a', ['utm' => 'x']);
    check($moved->status === 301 && ($moved->headers['Location'] ?? '') === '/atiranyitas-b?utm=x', $moved->status . ' ' . ($moved->headers['Location'] ?? ''));
    $article->set('path', '/atiranyitas-c');
    $repository->save($article);
    check(($send('GET', '/atiranyitas-a')->headers['Location'] ?? '') === '/atiranyitas-c' && ($send('GET', '/Atiranyitas-B/')->headers['Location'] ?? '') === '/atiranyitas-c', 'every old path to the current one');
    check($send('GET', '/atiranyitas-c')->status === 200 && $send('POST', '/atiranyitas-a')->status !== 301, 'the page itself; only GET is redirected');
    check((int) $db->fetchValue('SELECT hits FROM {redirects} WHERE source = :s', ['s' => '/atiranyitas-a']) === 2, 'counted');

    // A new page at an old path takes it.
    $other = $repository->create('article', ['title' => 'Másik-0227', 'path' => '/atiranyitas-a', 'status' => PublishStatus::Published, 'published_at' => new DateTimeImmutable('-1 hour')]);
    $repository->save($other);
    check($send('GET', '/atiranyitas-a')->status === 200 && !$db->fetchValue('SELECT 1 FROM {redirects} WHERE source = :s', ['s' => '/atiranyitas-a']), 'the redirect goes');

    // In the admin.
    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('atiranyitas-szerk@example.hu', 'atiranyitas-jelszo-1', ['editor']));
    check($send('GET', '/admin/system/redirects')->status === 403, 'not for editors');
    $boot()->get(AuthService::class)->login(new Request('GET', '/'), $newUser('atiranyitas-admin@example.hu', 'atiranyitas-jelszo-1', ['administrator']));
    $list = $send('GET', '/admin/system/redirects');
    check($list->status === 200 && str_contains($list->body, '/atiranyitas-b') && str_contains($list->body, 'automatikus') && str_contains($list->body, 'href="/admin/system/redirects"'), 'the list, in the menu');
    $token = preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $list->body, $m) === 1 ? $m[1] : '';
    $add = static fn (string $source, string $target) => $send('POST', '/admin/system/redirects/new', [], ['_csrf' => $token, 'source' => $source, 'target' => $target]);
    check($send('POST', '/admin/system/redirects/new', [], ['_csrf' => 'x', 'source' => '/regi-0227', 'target' => '/atiranyitas-c'])->status === 400, 'CSRF');
    foreach ([['regi', '/x'], ['/re gi', '/x'], ['//pelda.hu', '/x'], ['/regi?x', '/x'], ['/hirek', '/x'], ['/atiranyitas-c', '/x'], ['/admin/valami', '/x'], ['/atiranyitas-b', '/x'], ['/regi-0227', '/regi-0227'], ['/regi-0227', 'https://pelda.hu']] as [$source, $target]) {
        check($add($source, $target)->status === 422, "refused: {$source} → {$target}");
    }
    check($add('/Regi-0227/', '/atiranyitas-c')->status === 303, 'made');
    check(($send('GET', '/regi-0227')->headers['Location'] ?? '') === '/atiranyitas-c', 'it leads on');
    check($add('/hurok-a', '/hurok-b')->status === 303 && $add('/hurok-b', '/hurok-a')->status === 303 && $send('GET', '/hurok-a')->status === 404, 'a loop leads nowhere');
    check($add('/lanc-0227', '/regi-0227')->status === 303 && ($send('GET', '/lanc-0227')->headers['Location'] ?? '') === '/atiranyitas-c', 'one after the other');
    $id = (int) $db->fetchValue('SELECT id FROM {redirects} WHERE source = :s', ['s' => '/regi-0227']);
    check($send('POST', '/admin/system/redirects/' . $id . '/delete', [], ['_csrf' => $token])->status === 303 && $send('GET', '/regi-0227')->status === 404, 'deleted');

    // Deleting the object deletes its redirects.
    $repository = $boot()->get(ObjectRepository::class);
    $repository->delete($article);
    $repository->delete($other);
    check(!$db->fetchValue('SELECT 1 FROM {redirects} WHERE source = :s', ['s' => '/atiranyitas-b']), 'gone with the object');
    $db->execute('DELETE FROM {redirects}');
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: the editor\'s image picker lists the uploaded images as JSON', function () use ($newUser, $fakeImage, $repository): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $send = function (string $path, array $query = [], array $headers = ['accept' => 'application/json']) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request('GET', $path, $query, headers: $headers));
    };
    $images = [$fakeImage('Választó-0227 alma'), $fakeImage('Választó-0227 körte'), $fakeImage('Választó-0227 50%_x')];

    $anonymous = $send('/admin/media/browse');
    check($anonymous->status === 401 && str_contains($anonymous->body, '"success":false'), 'an expired session: JSON');
    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('valaszto-szerk@example.hu', 'valaszto-jelszo-1', ['editor']));
    $all = $send('/admin/media/browse', ['q' => 'Választó-0227']);
    $data = json_decode($all->body, true);
    check($all->status === 200 && str_starts_with($all->headers['Content-Type'] ?? '', 'application/json') && ($data['total'] ?? 0) === 3, $all->status . ' ' . substr($all->body, 0, 200));
    $first = $data['items'][0] ?? [];
    check(($first['title'] ?? '') === 'Választó-0227 50%_x' && str_starts_with((string) ($first['url'] ?? ''), '/media/2026/10/') && ($first['alt'] ?? '') === 'Választó-0227 50%_x leírása' && ($first['width'] ?? 0) === 1200, 'the newest first: ' . json_encode($first, JSON_UNESCAPED_UNICODE));
    check(count(json_decode($send('/admin/media/browse', ['q' => 'körte'])->body, true)['items'] ?? []) === 1, 'by title');
    check(count(json_decode($send('/admin/media/browse', ['q' => '0227 50%'])->body, true)['items'] ?? []) === 1 && (json_decode($send('/admin/media/browse', ['q' => '%'])->body, true)['total'] ?? 0) === 1, '% is not a wildcard');
    check((json_decode($send('/admin/media/browse', ['q' => 'Választó-0227', 'page' => '2'])->body, true)['items'] ?? null) === [], 'a page beyond the last: none');
    $form = $send('/admin/article/new', [], []);
    check(str_contains($form->body, 'data-browse-url="/admin/media/browse"') && str_contains($form->body, 'Feltöltött kép választása'), 'the form offers it');
    foreach ($images as $image) {
        $repository->delete($image);
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nThe admin's list with own columns (0.2.8)\n";

test('Blueprint: admin_columns checked; a stored one leaves out the fields gone', function (): void {
    $capabilities = new CapabilityRegistry([Titled::class]);
    $define = static fn (mixed $columns) => (new BlueprintRegistry($capabilities))->define('proba', [
        'capabilities' => [Titled::class],
        'fields' => [new Field('nap', FieldType::Date), new Field('titok', FieldType::String, hidden: true)],
        'relations' => [new \Campanella\Relation\Relation('kapcsolat', \Campanella\Relation\Cardinality::Many, targetBlueprints: ['proba'])],
        'admin_columns' => $columns,
    ]);
    check($define(['kapcsolat', 'nap'])->adminColumns === ['kapcsolat', 'nap'] && $define([])->adminColumns === [], 'fields and relations, in order');
    foreach ([['title'], ['nincs'], ['titok'], ['nap', 'nap'], ['nap', 'kapcsolat', 'nap', 'nap', 'nap'], 'nap'] as $bad) {
        throws(\Campanella\Capability\CapabilityException::class, fn () => $define($bad));
    }
    $stored = \Campanella\Model\BlueprintDefinition::toRegistry(['label' => 'P', 'capabilities' => ['titled'], 'fields' => [['name' => 'nap', 'type' => 'date']], 'admin_columns' => ['torolt', 'nap', 'nap']]);
    check($stored['admin_columns'] === ['nap'], json_encode($stored['admin_columns']));
});

test('Kernel: the admin\'s list of a Blueprint shows its columns, sorts and filters by them', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_rendezveny')); // left by a run that failed
    $send = function (string $method, string $path, array $query = [], array $post = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, $query, $post));
    };
    // A Blueprint with fields of every kind of filter, made as the content model's import makes it.
    $import = $container->get(\Campanella\Transfer\ModelImport::class);
    $plan = $import->plan(['blueprints' => ['rendezveny' => ['label' => 'Rendezvény', 'capabilities' => ['titled'],
        'fields' => [
            ['name' => 'nap', 'type' => 'date', 'label' => 'Nap', 'queryable' => true],
            ['name' => 'fajta', 'type' => 'choice', 'label' => 'Fajta', 'queryable' => true, 'choices' => [['key' => 'eloadas', 'label' => 'Előadás'], ['key' => 'seta', 'label' => 'Séta']]],
            ['name' => 'ar', 'type' => 'decimal', 'label' => 'Ár', 'queryable' => true, 'scale' => 0],
            ['name' => 'ingyenes', 'type' => 'boolean', 'label' => 'Ingyenes', 'queryable' => true],
            ['name' => 'kezdes', 'type' => 'datetime', 'label' => 'Kezdés', 'queryable' => true],
            ['name' => 'leiras', 'type' => 'html', 'label' => 'Leírás'],
        ],
        'relations' => [['name' => 'helyszin', 'label' => 'Helyszín', 'targets' => ['category'], 'many' => false]]]], 'lists' => []]);
    check(!$plan->hasErrors(), json_encode(array_map(static fn ($i) => (string) $i->error, $plan->items)));
    $import->apply($plan);
    $repository = $boot()->get(ObjectRepository::class);
    $hely = $repository->create('category', ['title' => 'Helyszín-0228', 'path' => '/helyszin-0228']);
    $repository->save($hely);
    $tz = new DateTimeZone('Europe/Budapest');
    $make = static function (array $values) use ($repository) {
        $object = $repository->create('rendezveny', $values);
        $repository->save($object);

        return $object;
    };
    $a = $make(['title' => 'Első-0228', 'nap' => '2026-11-05', 'fajta' => 'eloadas', 'ar' => '1500', 'ingyenes' => false, 'kezdes' => new DateTimeImmutable('2026-11-05 18:00', $tz), 'leiras' => '<p>' . str_repeat('Hosszú leírás szövege. ', 10) . '</p>']);
    $b = $make(['title' => 'Második-0228', 'nap' => '2026-11-20', 'fajta' => 'seta', 'ar' => '0', 'ingyenes' => true, 'kezdes' => new DateTimeImmutable('2026-11-21 00:30', $tz)]); // 23:30 the day before in UTC
    $b->relate('helyszin', $hely);
    $repository->save($b);

    $boot()->get(AuthService::class)->login(new Request('GET', '/'), $newUser('oszlop-admin@example.hu', 'oszlop-jelszo-12', ['administrator']));
    $edit = $send('GET', '/admin/system/blueprints/rendezveny');
    check($edit->status === 200 && str_contains($edit->body, 'A lista oszlopai') && str_contains($edit->body, 'name="columns[]"'), 'the editor offers them');
    $token = preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $edit->body, $m) === 1 ? $m[1] : '';
    $saved = $send('POST', '/admin/system/blueprints/rendezveny/columns', [], ['_csrf' => $token, 'columns' => ['nap', 'fajta', '', 'nap', 'nincs', 'ingyenes', 'helyszin', 'ar']]);
    check($saved->status === 303 && $boot()->get(BlueprintRegistry::class)->get('rendezveny')->adminColumns === ['nap', 'fajta', 'ingyenes', 'helyszin'], json_encode($boot()->get(BlueprintRegistry::class)->get('rendezveny')->adminColumns));

    $list = $send('GET', '/admin/rendezveny');
    check($list->status === 200 && str_contains($list->body, 'Nap') && str_contains($list->body, 'Séta') && str_contains($list->body, 'Helyszín-0228') && str_contains($list->body, 'name="f[fajta]"') && str_contains($list->body, 'name="f[nap][from]"') && str_contains($list->body, 'name="f[helyszin]"'), 'columns and filters');
    $count = static fn ($r): int => substr_count($r->body, 'id="row-');
    check($count($send('GET', '/admin/rendezveny', ['f' => ['fajta' => 'seta']])) === 1 && str_contains($send('GET', '/admin/rendezveny', ['f' => ['fajta' => 'seta']])->body, 'Második-0228'), 'a choice');
    check($count($send('GET', '/admin/rendezveny', ['f' => ['ingyenes' => '1']])) === 1 && $count($send('GET', '/admin/rendezveny', ['f' => ['ingyenes' => '0']])) === 1, 'yes / no');
    check($count($send('GET', '/admin/rendezveny', ['f' => ['nap' => ['from' => '2026-11-10']]])) === 1 && $count($send('GET', '/admin/rendezveny', ['f' => ['nap' => ['from' => '2026-11-05', 'to' => '2026-11-05']]])) === 1, 'a date range, its ends included');
    check($count($send('GET', '/admin/rendezveny', ['f' => ['helyszin' => (string) $hely->id()]])) === 1, 'a relation');
    check($count($send('GET', '/admin/rendezveny', ['f' => ['fajta' => 'nincs', 'nap' => ['from' => '2026-13-45'], 'ingyenes' => 'x', 'helyszin' => '999999', 'leiras' => 'x']])) === 2, 'invalid values are ignored');
    $sorted = $send('GET', '/admin/rendezveny', ['sort' => 'nap', 'dir' => 'desc'])->body;
    check(strpos($sorted, 'Második-0228') < strpos($sorted, 'Első-0228') && str_contains($sorted, 'aria-sort="descending"'), 'sorted by a column');

    // The columns of the file's Blueprints: a decimal and a moment, a text cut short.
    $send('POST', '/admin/system/blueprints/rendezveny/columns', [], ['_csrf' => $token, 'columns' => ['ar', 'kezdes', 'leiras']]);
    check($count($send('GET', '/admin/rendezveny', ['f' => ['ar' => ['min' => '1 000']]])) === 1 && $count($send('GET', '/admin/rendezveny', ['f' => ['ar' => ['max' => '0']]])) === 1, 'a number range');
    check($count($send('GET', '/admin/rendezveny', ['f' => ['kezdes' => ['to' => '2026-11-20']]])) === 1 && $count($send('GET', '/admin/rendezveny', ['f' => ['kezdes' => ['from' => '2026-11-21']]])) === 1, 'a moment\'s day in the site\'s time zone');
    $texts = $send('GET', '/admin/rendezveny')->body;
    check(str_contains($texts, 'Hosszú leírás szövege.') && str_contains($texts, '…') && !str_contains($texts, '<p>Hosszú'), 'a text, short and plain');
    check(!str_contains($texts, 'name="f[leiras]"') && !str_contains($texts, 'name="f[nap]'), 'no filter for a text; only the columns\'');

    // Deleting a column's field leaves it out of the columns.
    $send('POST', '/admin/system/blueprints/rendezveny/fields/leiras/delete', [], ['_csrf' => $token, 'confirm' => '1']);
    check($boot()->get(BlueprintRegistry::class)->get('rendezveny')->adminColumns === ['ar', 'kezdes'], json_encode($boot()->get(BlueprintRegistry::class)->get('rendezveny')->adminColumns));

    // The articles: their categories (config/blueprints.php).
    check(str_contains($send('GET', '/admin/article')->body, 'name="f[categories]"'), 'the articles\' list has the categories');

    $repository = $boot()->get(ObjectRepository::class);
    foreach ([$a, $b, $hely] as $object) {
        $repository->delete($object);
    }
    $boot()->get(\Campanella\Model\BlueprintStore::class)->delete('rendezveny');
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_rendezveny'));
    foreach (glob(dirname(__DIR__) . '/var/backups/campanella-*') ?: [] as $file) {
        if (filemtime($file) >= time() - 120) {
            @unlink($file);
        }
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nVisitors' filters (0.2.9)\n";

test('Kernel: a list\'s filters for visitors: the form, the results, the pages, noindex', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_eloadas_0229')); // left by a run that failed
    $send = function (string $method, string $path, array $query = [], array $post = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, $query, $post));
    };
    $import = $container->get(\Campanella\Transfer\ModelImport::class);
    $list = ['title' => 'Előadások-0229', 'blueprint' => 'eloadas_0229', 'conditions' => [], 'sort' => [['field' => 'nap', 'dir' => 'asc']], 'per_page' => 1, 'display' => 'table',
        'columns' => ['fajta'], 'path' => '/eloadasok-0229', 'filters' => ['fajta', 'nap', 'ingyenes', 'helyszin']];
    $plan = $import->plan(['blueprints' => ['eloadas_0229' => ['label' => 'Előadás', 'capabilities' => ['titled', 'routable', 'publishable'],
        'fields' => [
            ['name' => 'nap', 'type' => 'date', 'label' => 'Nap', 'queryable' => true],
            ['name' => 'fajta', 'type' => 'choice', 'label' => 'Fajta', 'queryable' => true, 'choices' => [['key' => 'eloadas', 'label' => 'Előadás'], ['key' => 'seta', 'label' => 'Séta']]],
            ['name' => 'ingyenes', 'type' => 'boolean', 'label' => 'Ingyenes', 'queryable' => true],
            ['name' => 'leiras', 'type' => 'text', 'label' => 'Leírás'],
        ],
        'relations' => [['name' => 'helyszin', 'label' => 'Helyszín', 'targets' => ['category'], 'many' => false]]]],
        'lists' => ['eloadasok_0229' => $list]]);
    check(!$plan->hasErrors(), json_encode(array_map(static fn ($i) => $i->name . ':' . $i->error, $plan->items), JSON_UNESCAPED_UNICODE));
    $import->apply($plan);

    // Checked like the rest of a list.
    $registry = $boot()->get(BlueprintRegistry::class);
    foreach ([['leiras'], ['status'], ['nincs'], ['created'], ['fajta', 'fajta'], ['fajta', 'nap', 'ingyenes', 'helyszin', 'published_at'], 'fajta'] as $bad) {
        throws(\InvalidArgumentException::class, fn () => \Campanella\Lists\ListDefinition::fromArray('rossz', ['filters' => $bad] + $list, $registry));
    }
    check(\Campanella\Lists\ListDefinition::fromArray('jo', ['filters' => ['published_at', 'helyszin']] + $list, $registry)->toArray()['filters'] === ['published_at', 'helyszin'], 'a capability\'s field, a relation');

    $repository = $boot()->get(ObjectRepository::class);
    $hely = $repository->create('category', ['title' => 'Terem-0229', 'path' => '/terem-0229', 'status' => PublishStatus::Published, 'published_at' => new DateTimeImmutable('-1 hour')]);
    $repository->save($hely);
    $made = [$hely];
    foreach ([
        ['Első-0229', '2026-11-05', 'eloadas', false, PublishStatus::Published],
        ['Második-0229', '2026-11-12', 'seta', true, PublishStatus::Published],
        ['Harmadik-0229', '2026-11-19', 'seta', true, PublishStatus::Published],
        ['Rejtett-0229', '2026-11-20', 'seta', true, PublishStatus::Draft],
    ] as [$title, $day, $kind, $free, $status]) {
        $object = $repository->create('eloadas_0229', ['title' => $title, 'path' => '/' . strtolower(str_replace(['ő', 'á'], ['o', 'a'], $title)), 'nap' => $day, 'fajta' => $kind, 'ingyenes' => $free, 'status' => $status, 'published_at' => new DateTimeImmutable('-1 hour')]);
        if ($title === 'Harmadik-0229' || $title === 'Rejtett-0229') {
            $object->relate('helyszin', $hely);
        }
        $repository->save($object);
        $made[] = $object;
    }

    $page = $send('GET', '/eloadasok-0229');
    $indexed = !str_contains($page->body, 'name="robots"'); // the site's own setting (another test may have turned it off)
    check($page->status === 200 && str_contains($page->body, 'name="f[fajta]"') && str_contains($page->body, 'name="f[nap][from]"') && str_contains($page->body, 'name="f[ingyenes]"') && str_contains($page->body, 'Terem-0229'), 'the form');
    $titles = static fn ($r): array => array_values(array_filter(['Első-0229', 'Második-0229', 'Harmadik-0229', 'Rejtett-0229'], static fn (string $t): bool => str_contains($r->body, $t . '<')));
    $seta = $send('GET', '/eloadasok-0229', ['f' => ['fajta' => 'seta']]);
    check($titles($seta) === ['Második-0229'] && str_contains($seta->body, 'name="robots" content="noindex"') && str_contains($seta->body, '<option value="seta" selected'), 'a choice, the first page; noindex');
    check(str_contains($seta->body, 'href="/eloadasok-0229?f%5Bfajta%5D=seta&amp;page=2"'), 'the pages keep the filter');
    check($titles($send('GET', '/eloadasok-0229', ['f' => ['fajta' => 'seta'], 'page' => '2'])) === ['Harmadik-0229'] && $send('GET', '/eloadasok-0229', ['f' => ['fajta' => 'seta'], 'page' => '3'])->status === 404, 'never the draft');
    check($titles($send('GET', '/eloadasok-0229', ['f' => ['helyszin' => (string) $hely->id()]])) === ['Harmadik-0229'], 'a relation');
    check($titles($send('GET', '/eloadasok-0229', ['f' => ['nap' => ['from' => '2026-11-10', 'to' => '2026-11-12']]])) === ['Második-0229'], 'a date range');
    check($titles($send('GET', '/eloadasok-0229', ['f' => ['ingyenes' => '0']])) === ['Első-0229'], 'yes / no');
    $bad = $send('GET', '/eloadasok-0229', ['f' => ['fajta' => '<script>', 'leiras' => 'x', 'nap' => ['from' => 'tegnap']]]);
    check($bad->status === 200 && $titles($bad) === ['Első-0229'] && $indexed === !str_contains($bad->body, 'name="robots"') && !str_contains($bad->body, '&lt;script&gt;'), 'invalid values are ignored, not shown back');

    // The list editor.
    $boot()->get(AuthService::class)->login(new Request('GET', '/'), $newUser('szuro-admin@example.hu', 'szuro-jelszo-12', ['administrator']));
    $edit = $send('GET', '/admin/system/lists/eloadasok_0229');
    check($edit->status === 200 && str_contains($edit->body, 'Szűrők a látogatóknak') && preg_match('/name="filters\[\]" value="fajta"[^>]*checked/', $edit->body) === 1 && !str_contains($edit->body, 'name="filters[]" value="leiras"') && !str_contains($edit->body, 'name="filters[]" value="status"'), 'the editor offers them');
    $token = preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $edit->body, $m) === 1 ? $m[1] : '';
    $saved = $send('POST', '/admin/system/lists/eloadasok_0229', [], ['_csrf' => $token, 'title' => 'Előadások-0229', 'path' => '/eloadasok-0229', 'per_page' => '1', 'display' => 'table', 'columns' => ['fajta'], 'filters' => ['ingyenes'], 'sort' => [['field' => 'nap', 'dir' => 'asc']]]);
    check($saved->status === 303 && ($boot()->get(\Campanella\Lists\ListStore::class)->find('eloadasok_0229')['filters'] ?? null) === ['ingyenes'], (string) $saved->status);
    check(!str_contains($send('GET', '/eloadasok-0229')->body, 'name="f[fajta]"'), 'only those chosen');

    $repository = $boot()->get(ObjectRepository::class);
    foreach (array_reverse($made) as $object) {
        $repository->delete($object);
    }
    $boot()->get(\Campanella\Lists\ListStore::class)->delete('eloadasok_0229');
    $boot()->get(\Campanella\Model\BlueprintStore::class)->delete('eloadas_0229');
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_eloadas_0229'));
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nSecurity review (0.3.0)\n";

test('Review (0.3.0): the search is throttled, takes no operators; redirects send ASCII; imported choices and targets are checked', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $get = function (string $path, array $query = [], string $ip = '198.51.100.7') use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request('GET', $path, $query, ip: $ip));
    };

    // The search: operators and odd input never reach the database as such; a flood is throttled.
    foreach (['+alma -körte "x y" @3 ~z <a> (b) *', str_repeat('á', 500), "\0\x01", '%_\\'] as $q) {
        check($get('/search', ['q' => $q])->status === 200, 'odd input: ' . json_encode(mb_substr($q, 0, 20)));
    }
    $db->execute('DELETE FROM {throttle}');
    $statuses = [];
    for ($i = 0; $i < \Campanella\Controller\SearchController::MAX_SEARCHES + 1; $i++) {
        $statuses[] = $get('/search', ['q' => 'alma' . $i], '203.0.113.50')->status;
    }
    $last = $get('/search', ['q' => 'alma'], '203.0.113.50');
    check(count(array_filter($statuses, static fn (int $s): bool => $s === 200)) === \Campanella\Controller\SearchController::MAX_SEARCHES && $last->status === 429 && (int) ($last->headers['Retry-After'] ?? 0) > 0 && str_contains($last->body, 'Túl sok keresés'), json_encode(array_count_values($statuses)));
    check($get('/search', ['q' => 'alma'], '192.0.2.99')->status === 200 && $get('/search', [], '203.0.113.50')->status === 200, 'another network, and the empty form, are not affected');
    check($get('/search', ['q' => 'alma', 'page' => '99999999999999999999'])->status === 404, 'a page number too large: 404, not an overflow');
    $db->execute('DELETE FROM {throttle}');

    // Redirects: the Location header is ASCII; nothing leaves the site.
    $repository = $container->get(ObjectRepository::class);
    $page = $repository->create('article', ['title' => 'Ékezetes út', 'path' => '/regi-ekezetes', 'status' => PublishStatus::Published, 'published_at' => new DateTimeImmutable('-1 hour')]);
    $repository->save($page);
    $page->set('path', '/új-út');
    $repository->save($page);
    $moved = $get('/regi-ekezetes');
    check($moved->status === 301 && ($moved->headers['Location'] ?? '') === '/%C3%BAj-%C3%BAt', (string) ($moved->headers['Location'] ?? ''));
    $redirects = $container->get(\Campanella\Site\Redirects::class);
    $redirects->add('/kezzel-0300', '/a%0d%0aSet-Cookie:x=1');
    check(($get('/kezzel-0300')->headers['Location'] ?? '') === '/a%0d%0aSet-Cookie:x=1', 'percent signs stay as they are: no header is split');
    check(array_slice($redirects->page(PHP_INT_MAX, 50)['items'], 0, 1) === [], 'the admin\'s pages too');
    $repository->delete($page);
    $db->execute('DELETE FROM {redirects}');

    // The content model's import: choice keys as in the editor, relations not to users or messages.
    $import = $container->get(\Campanella\Transfer\ModelImport::class);
    $choice = static fn (string $key): array => ['label' => 'Próba', 'capabilities' => ['titled'], 'fields' => [['name' => 'fajta', 'type' => 'choice', 'label' => 'Fajta', 'choices' => [['key' => $key, 'label' => 'X']]]]];
    $error = static function ($plan, string $name): ?string {
        foreach ($plan->items as $item) {
            if ($item->name === $name) {
                return $item->error?->key;
            }
        }

        return null;
    };
    check($error($import->plan(['blueprints' => ['proba_0300' => $choice('<script>')], 'lists' => []]), 'proba_0300') === 'transfer.error.invalid', 'a choice key out of the pattern');
    check($error($import->plan(['blueprints' => ['proba_0300' => $choice(str_repeat('a', 65))], 'lists' => []]), 'proba_0300') === 'transfer.error.invalid', 'a choice key too long');
    check($error($import->plan(['blueprints' => ['proba_0300' => $choice('jo.kulcs-1_A')], 'lists' => []]), 'proba_0300') === null, 'a good key');
    $many = ['label' => 'Sok', 'capabilities' => ['titled'], 'fields' => [['name' => 'fajta', 'type' => 'choice', 'label' => 'F', 'choices' => array_map(static fn (int $i): array => ['key' => 'k' . $i, 'label' => 'L' . $i], range(1, \Campanella\Model\BlueprintDefinition::MAX_CHOICES + 1))]]];
    check($error($import->plan(['blueprints' => ['proba_0300' => $many], 'lists' => []]), 'proba_0300') === 'transfer.error.invalid', 'too many choices');
    foreach (['user', 'submission', 'nincs_ilyen'] as $target) {
        $definition = ['label' => 'Kapcsolat', 'capabilities' => ['titled'], 'relations' => [['name' => 'kinek', 'label' => 'Kinek', 'targets' => [$target]]]];
        check($error($import->plan(['blueprints' => ['proba_0300' => $definition], 'lists' => []]), 'proba_0300') === 'transfer.error.relation_target', 'a relation to ' . $target);
    }
    check($error($import->plan(['blueprints' => ['proba_0300' => ['label' => 'K', 'capabilities' => ['titled'], 'relations' => [['name' => 'kat', 'label' => 'Kat', 'targets' => ['category']]]]], 'lists' => []]), 'proba_0300') === null, 'a relation to categories');

    // A list's filters: an unpublished target is neither offered nor taken.
    $hidden = $repository->create('category', ['title' => 'Rejtett kategória-0300', 'path' => '/rejtett-kategoria-0300']);
    $repository->save($hidden);
    $filters = new \Campanella\Query\FieldFilters($container->get(\Campanella\Query\QueryEngine::class), new DateTimeZone('UTC'));
    $relation = $container->get(BlueprintRegistry::class)->get('article')->relations['categories'];
    [, $values, $options] = $filters->apply(\Campanella\Query\Query::objects()->blueprint('article'), [$relation], ['categories' => (string) $hidden->id()], Actor::anonymous());
    check($values === [] && !in_array((string) $hidden->id(), array_column($options['categories'] ?? [], 'value'), true), 'not for a visitor');
    [, $values] = $filters->apply(\Campanella\Query\Query::objects()->blueprint('article'), [$relation], ['categories' => ['x' => ['y' => 1]]], Actor::anonymous());
    check($values === [], 'an array where a value is expected');
    $repository->delete($hidden);
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nThe sample events, and lists kept whole (0.3.0)\n";

test('Seed (0.3.0): the events Blueprint, its list and events, made once; the main menu links the list', function (): void {
    $root = dirname(__DIR__);
    $env = 'CAMPANELLA_DB_PREFIX=seedt_ CAMPANELLA_LOCALE=hu';
    $drop = static function (): void {
        putenv('CAMPANELLA_DB_PREFIX=seedt_');
        $db = (new \Campanella\Core\Kernel(dirname(__DIR__)))->container()->get(Connection::class);
        $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->fetchColumn("SHOW TABLES LIKE 'seedt\\_%'") as $table) {
            $db->execute('DROP TABLE `' . $table . '`');
        }
        $db->execute('SET FOREIGN_KEY_CHECKS = 1');
        putenv('CAMPANELLA_DB_PREFIX');
    };
    $drop();
    exec($env . ' php ' . escapeshellarg($root . '/bin/campanella') . ' install --yes 2>&1', $out, $code);
    check($code === 0, implode("\n", $out));
    $out = [];
    exec($env . ' php ' . escapeshellarg($root . '/bin/campanella') . ' seed 2>&1', $out, $code);
    check($code === 0 && str_contains(implode("\n", $out), 'esemeny'), implode("\n", $out));

    putenv('CAMPANELLA_DB_PREFIX=seedt_');
    $kernel = new \Campanella\Core\Kernel($root);
    $c = $kernel->container();
    $blueprints = $c->get(BlueprintRegistry::class);
    $event = $blueprints->find('esemeny');
    check($event !== null && $blueprints->isEditable('esemeny') && $event->adminColumns === ['kezdes', 'fajta', 'belepo'], 'the Blueprint, made as in the admin');
    $queries = $c->get(QueryEngine::class);
    check($queries->count(Query::objects()->blueprint('esemeny'), Actor::system()) === 7, 'seven events');
    $list = $c->get(\Campanella\Lists\ListRegistry::class)->find('esemenyek');
    check($list !== null && $list->path === '/esemenyek' && $list->filters === ['fajta', 'kezdes', 'belepo'], 'the list');
    $page = $kernel->handle(new Request('GET', '/esemenyek', ip: '198.51.100.40'));
    check($page->status === 200 && str_contains($page->body, 'Barokk est') && !str_contains($page->body, 'Őszi könyvbörze') && str_contains($page->body, 'name="f[fajta]"'), 'the upcoming ones, with filters; not the past one');
    $filtered = (new \Campanella\Core\Kernel($root))->handle(new Request('GET', '/esemenyek', ['f' => ['fajta' => 'koncert']], ip: '198.51.100.41'));
    check(str_contains($filtered->body, 'Jazz a pincében') && !str_contains($filtered->body, 'Történelmi séta'), 'filtered');
    check(str_contains((new \Campanella\Core\Kernel($root))->handle(new Request('GET', '/', ip: '198.51.100.42'))->body, 'href="/esemenyek"'), 'in the main menu');

    // Again: nothing made twice.
    putenv('CAMPANELLA_DB_PREFIX');
    $out = [];
    exec($env . ' php ' . escapeshellarg($root . '/bin/campanella') . ' seed 2>&1', $out, $code);
    putenv('CAMPANELLA_DB_PREFIX=seedt_');
    $c = (new \Campanella\Core\Kernel($root))->container();
    check($code === 0 && $c->get(QueryEngine::class)->count(Query::objects()->blueprint('esemeny'), Actor::system()) === 7 && !str_contains(implode("\n", $out), 'esemeny Blueprint'), implode("\n", $out));
    putenv('CAMPANELLA_DB_PREFIX');
    $drop();
});

test('Blueprint editor (0.3.0): a Blueprint, field or queryable flag a list uses is kept; a zero is shown in a column', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $container->get(Connection::class);
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_kitart'));
    $container->get(\Campanella\Model\BlueprintStore::class)->delete('kitart');
    $send = function (string $method, string $path, array $post = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, post: $post));
    };
    $boot()->get(AuthService::class)->login(new Request('GET', '/'), $newUser('kitart-admin@example.hu', 'kitart-jelszo-12', ['administrator']));
    $token = preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $send('GET', '/admin/system/blueprints/new')->body, $m) === 1 ? $m[1] : '';
    check($send('POST', '/admin/system/blueprints/new', ['_csrf' => $token, 'name' => 'kitart', 'label' => 'Kitartó'])->status === 303, 'made');
    $field = static fn (array $post) => $send('POST', '/admin/system/blueprints/kitart/fields/new', ['_csrf' => $token] + $post + ['required' => '', 'multiple' => '', 'max' => '', 'length' => '255', 'scale' => '2', 'choices' => '', 'default' => '']);
    check($field(['name' => 'nap', 'label' => 'Nap', 'type' => 'date', 'queryable' => '1'])->status === 303 && $field(['name' => 'szam', 'label' => 'Szám', 'type' => 'integer', 'queryable' => '1'])->status === 303, 'its fields');
    $send('POST', '/admin/system/blueprints/kitart/columns', ['_csrf' => $token, 'columns' => ['szam']]);
    $boot()->get(\Campanella\Lists\ListStore::class)->save('kitart_lista', ['version' => 1, 'title' => 'Kitartó lista', 'blueprint' => 'kitart',
        'conditions' => [['field' => 'nap', 'op' => '>=', 'relative' => 'today']], 'sort' => [], 'per_page' => 10, 'display' => 'teaser', 'columns' => [], 'filters' => []]);
    $object = $boot()->get(\Campanella\Model\ObjectRepository::class)->create('kitart', ['title' => 'Nulla-0300', 'nap' => '2026-11-01', 'szam' => 0]);
    $boot()->get(\Campanella\Model\ObjectRepository::class)->save($object);
    $row = preg_match('/id="row-' . $object->id() . '".*?<\/tr>/s', $send('GET', '/admin/kitart')->body, $mm) === 1 ? $mm[0] : '';
    check(preg_match('/>\s*0\s*</', $row) === 1, 'a zero, not a dash: ' . $row);
    $boot()->get(\Campanella\Model\ObjectRepository::class)->delete($object);

    $send('POST', '/admin/system/blueprints/kitart/fields/nap/delete', ['_csrf' => $token, 'confirm' => '1']);
    check(isset($boot()->get(BlueprintRegistry::class)->get('kitart')->fields['nap']), 'a field a list uses is not deleted');
    check(str_contains($send('GET', '/admin/system/blueprints/kitart/fields/nap')->body, 'Kitartó lista'), 'the list is named (a flash)');
    $unqueryable = $send('POST', '/admin/system/blueprints/kitart/fields/nap', ['_csrf' => $token, 'label' => 'Nap', 'type' => 'date', 'required' => '', 'queryable' => '', 'multiple' => '', 'max' => '', 'length' => '255', 'scale' => '2', 'choices' => '', 'default' => '']);
    check(str_contains($unqueryable->body . $send('GET', '/admin/system/blueprints/kitart/fields/nap')->body, 'Kitartó lista') && $boot()->get(BlueprintRegistry::class)->get('kitart')->fields['nap']->storage === \Campanella\Model\FieldStorage::Table, 'nor made unqueryable: ' . $unqueryable->status);
    $send('POST', '/admin/system/blueprints/kitart/delete', ['_csrf' => $token]);
    check($boot()->get(BlueprintRegistry::class)->find('kitart') !== null, 'nor the Blueprint deleted');
    check($send('POST', '/admin/system/blueprints/kitart/fields/szam/delete', ['_csrf' => $token, 'confirm' => '1'])->status === 303 && !isset($boot()->get(BlueprintRegistry::class)->get('kitart')->fields['szam']), 'a field no list uses is');

    $boot()->get(\Campanella\Lists\ListStore::class)->delete('kitart_lista');
    $send('POST', '/admin/system/blueprints/kitart/delete', ['_csrf' => $token]);
    check($boot()->get(BlueprintRegistry::class)->find('kitart') === null, 'without the list, it is deleted');
    $boot()->get(\Campanella\Model\BlueprintStore::class)->delete('kitart');
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('bp_kitart'));
    foreach (glob(dirname(__DIR__) . '/var/backups/campanella-*') ?: [] as $file) {
        @unlink($file);
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nImporters (0.3.1)\n";

test('ImporterDefinition: the stored form, checked against the Blueprints', function () use ($config): void {
    $registry = (new \Campanella\Core\Kernel(dirname(__DIR__)))->container()->get(BlueprintRegistry::class);
    $good = ['label' => 'Cikkek', 'blueprint' => 'article', 'format' => 'csv', 'key' => 'Azonosító', 'user' => 1,
        'mapping' => [['source' => 'Cím', 'target' => 'title'], ['source' => 'Kategória', 'target' => 'categories', 'separator' => '|']]];
    $d = \Campanella\Import\ImporterDefinition::fromArray('cikkek', $good, $registry);
    check($d->blueprint === 'article' && $d->delimiter === 'auto' && $d->missingTarget === 'error' && $d->missingItems === 'keep' && !$d->publish && count($d->mapping) === 2);
    check(\Campanella\Import\ImporterDefinition::fromArray('cikkek', $d->toArray(), $registry)->toArray() === $d->toArray(), 'the stored form round-trips');
    foreach ([
        'a user Blueprint' => ['blueprint' => 'user'],
        'an unknown Blueprint' => ['blueprint' => 'nincs'],
        'an unknown target' => ['mapping' => [['source' => 'Cím', 'target' => 'title'], ['source' => 'X', 'target' => 'nincs']]],
        'a target twice' => ['mapping' => [['source' => 'Cím', 'target' => 'title'], ['source' => 'X', 'target' => 'title']]],
        'no title' => ['mapping' => [['source' => 'Bevezető', 'target' => 'lead']]],
        'a status as a target' => ['mapping' => [['source' => 'Cím', 'target' => 'title'], ['source' => 'S', 'target' => 'status']]],
        'a format' => ['format' => 'xml'],
        'an empty key' => ['key' => ' '],
        'a control character' => ['key' => "a\nb"],
        'no user' => ['user' => 0],
        'a missing target policy' => ['missing_target' => 'delete'],
    ] as $what => $change) {
        throws(InvalidArgumentException::class, static fn () => \Campanella\Import\ImporterDefinition::fromArray('cikkek', $change + $good, $registry));
    }
    throws(InvalidArgumentException::class, static fn () => \Campanella\Import\ImporterDefinition::fromArray('Rossz Név', $good, $registry));
    check(array_keys(\Campanella\Import\ImporterDefinition::targets($registry->get('article'))) === ['title', 'body', 'path', 'published_at', 'lead', 'categories'], 'the targets');
});

test('ImportSource: a CSV (BOM, delimiter, quotes, line breaks, empty rows, bad bytes) and JSON (a path, nested values)', function (): void {
    $dir = sys_get_temp_dir() . '/cmp-import-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $csv = $dir . '/a.csv';
    file_put_contents($csv, "\xEF\xBB\xBFAzonosító;Cím;Szöveg\r\n1;Első;\"Két sor\r\nés; pontosvessző\"\r\n\r\n2;\"Idéző \"\"jel\"\"\";\r\n3;\xC3\x28;x\r\n");
    $source = \Campanella\Import\ImportSource::file($csv, 'csv');
    check($source->columns() === ['Azonosító', 'Cím', 'Szöveg'], json_encode($source->columns(), JSON_UNESCAPED_UNICODE));
    $items = iterator_to_array($source->items());
    check(array_keys($items) === [1, 2, 3] && $items[1]['Szöveg'] === "Két sor\r\nés; pontosvessző" && $items[2]['Cím'] === 'Idéző "jel"' && $items[2]['Szöveg'] === '', json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    check($items[3] instanceof \Campanella\Import\ImportException && $items[3]->reason->key === 'import.error.not_utf8', 'a row that is not UTF-8');
    check($source->count() === 3 && array_keys(iterator_to_array($source->items(2))) === [3], 'counted; from an item on');
    file_put_contents($csv, "a,b,a\n1,2,3\n");
    throws(\Campanella\Import\ImportException::class, static fn () => \Campanella\Import\ImportSource::file($csv, 'csv'));
    file_put_contents($csv, "a\tb\n1\t2\n");
    check(\Campanella\Import\ImportSource::file($csv, 'csv')->columns() === ['a', 'b'] && \Campanella\Import\ImportSource::file($csv, 'csv', ';')->columns() === ["a\tb"], 'a tab found; a given delimiter kept');
    file_put_contents($csv, "\xE1rv\xEDz\n1\n");
    throws(\Campanella\Import\ImportException::class, static fn () => \Campanella\Import\ImportSource::file($csv, 'csv'));
    $json = $dir . '/a.json';
    file_put_contents($json, json_encode(['data' => ['items' => [['id' => 7, 'cim' => 'Hét', 'hely' => ['nev' => 'Tér']], 'nem objektum']]]));
    $j = \Campanella\Import\ImportSource::file($json, 'json', 'auto', 'data.items');
    $items = iterator_to_array($j->items());
    check($j->columns() === ['id', 'cim', 'hely.nev'] && \Campanella\Import\ImportSource::value($items[1], 'hely.nev') === 'Tér' && \Campanella\Import\ImportSource::value($items[1], 'nincs') === null, json_encode($j->columns()));
    check($items[2] instanceof \Campanella\Import\ImportException, 'an item that is not an object');
    throws(\Campanella\Import\ImportException::class, static fn () => \Campanella\Import\ImportSource::file($json, 'json', 'auto', 'data.nincs'));
    file_put_contents($json, '{"nem": json');
    throws(\Campanella\Import\ImportException::class, static fn () => \Campanella\Import\ImportSource::file($json, 'json'));
    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});

test('ImportValues: a value of every type, from a text or JSON', function (): void {
    $v = new \Campanella\Import\ImportValues(new DateTimeZone('Europe/Budapest'));
    $f = static fn (FieldType $type, array $more = []) => new Field('x', $type, ...(['label' => 'Mező'] + $more));
    check($v->convert($f(FieldType::String), '  szöveg ', '|') === 'szöveg' && $v->convert($f(FieldType::String), '', '|') === null);
    check($v->convert($f(FieldType::Html), "Egy\nkét", '|') === \Campanella\Html\PlainText::toHtml("Egy\nkét") && $v->convert($f(FieldType::Html), '<p>Már <b>HTML</b></p>', '|') === '<p>Már <b>HTML</b></p>', 'plain text becomes paragraphs');
    check($v->convert($f(FieldType::Integer), ' 1 234 ', '|') === 1234 && $v->convert($f(FieldType::Integer), 7, '|') === 7);
    check($v->convert($f(FieldType::Decimal, ['scale' => 2]), '1 234,567', '|') === '1234.57' && $v->convert($f(FieldType::Decimal, ['scale' => 0]), 1500, '|') === '1500', 'a decimal comma, rounded');
    check($v->convert($f(FieldType::Boolean), 'Igen', '|') === true && $v->convert($f(FieldType::Boolean), 'no', '|') === false && $v->convert($f(FieldType::Boolean), false, '|') === false && $v->convert($f(FieldType::Boolean), '', '|') === null);
    foreach (['2026-11-05', '2026.11.05.', '2026. 11. 5.'] as $day) {
        check((string) $v->convert($f(FieldType::Date), $day, '|')?->format('Y-m-d') === '2026-11-05', $day);
    }
    check($v->convert($f(FieldType::DateTime), '2026. 11. 05. 18:00', '|')?->format('Y-m-d H:i e') === '2026-11-05 17:00 UTC', 'a moment in the site\'s time zone');
    check($v->convert($f(FieldType::DateTime), '2026-07-01T08:30:00+02:00', '|')?->format('Y-m-d H:i') === '2026-07-01 06:30', 'with an offset');
    $choice = $f(FieldType::Choice, ['choices' => ['koncert' => 'Koncert', 'seta' => 'Séta']]);
    check($v->convert($choice, 'koncert', '|') === 'koncert' && $v->convert($choice, 'SÉTA', '|') === 'seta', 'a choice by key or label');
    $tags = new Field('cimkek', FieldType::String, cardinality: Field::UNLIMITED);
    check($v->convert($tags, 'zene| tánc ||', '|') === ['zene', 'tánc'] && $v->convert($tags, ['a', 'b'], '|') === ['a', 'b'], 'several values');
    foreach ([[FieldType::Integer, '1,5'], [FieldType::Decimal, 'sok'], [FieldType::Boolean, 'talán'], [FieldType::Date, '2026-02-30'], [FieldType::DateTime, 'holnap'], [FieldType::String, ['a', 'b']]] as [$type, $raw]) {
        throws(\Campanella\Import\ImportException::class, static fn () => $v->convert($f($type), $raw, '|'));
    }
    throws(\Campanella\Import\ImportException::class, static fn () => $v->convert($choice, 'mozi', '|'));
});

test('Importer: trial, a run in batches, unchanged, changed, missing items, new targets, errors, undo', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $c = $kernel->container();
    if ($c->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $db = $c->get(Connection::class);
    $repository = $c->get(ObjectRepository::class);
    $service = $c->get(ObjectService::class);
    $admin = $newUser('import-admin@example.hu', 'import-jelszo-12', ['administrator']);
    $category = $service->create(Actor::system(), 'category', ['title' => 'Import-meglévő-0301'], publish: true);
    $store = $c->get(\Campanella\Import\ImporterStore::class);
    $store->save('teszt_cikkek', ['version' => 1, 'label' => 'Teszt cikkek', 'blueprint' => 'article', 'format' => 'csv', 'delimiter' => 'auto', 'items' => '',
        'key' => 'Azonosító', 'publish' => true, 'missing_target' => 'create', 'missing_items' => 'draft', 'user' => (int) $admin->id(),
        'mapping' => [['source' => 'Cím', 'target' => 'title'], ['source' => 'Bevezető', 'target' => 'lead'], ['source' => 'Szöveg', 'target' => 'body'],
            ['source' => 'Kategória', 'target' => 'categories', 'separator' => '|'], ['source' => 'Megjelenés', 'target' => 'published_at']]]);
    $importers = $c->get(\Campanella\Import\Importer::class);
    $importer = $importers->find('teszt_cikkek');
    check($importer !== null, 'loaded');
    $file = tempnam(sys_get_temp_dir(), 'imp');
    $write = static fn (array $rows) => file_put_contents($file, implode("\n", array_map(static fn (array $r): string => implode(';', $r), [['Azonosító', 'Cím', 'Bevezető', 'Szöveg', 'Kategória', 'Megjelenés'], ...$rows])) . "\n");
    $write([
        ['A1', 'Import első-0301', 'Bevezető egy', 'Első sor', 'Import-meglévő-0301', ''],
        ['A2', 'Import második-0301', 'Bevezető kettő', '<p>Már <script>x</script>HTML</p>', 'Import-új-0301|Import-meglévő-0301', '2026. 10. 01. 12:00'],
        ['A3', 'Import hibás-0301', '', '', '', 'tegnapelőtt'],
        ['', 'Kulcs nélkül', '', '', '', ''],
    ]);
    $trial = $importers->trial($importer, $file);
    check($trial->total === 4 && $trial->missingSources === [] && array_column($trial->rows, 'action') === ['create', 'create', 'error', 'error'], json_encode(array_column($trial->rows, 'action')));
    check($trial->rows[1]['creates'] === ['Import-új-0301'] && $trial->rows[2]['error']?->key === 'import.error.date', 'the target to make; the error');
    check($db->fetchValue("SELECT COUNT(*) FROM {cap_titled} WHERE title LIKE 'Import%-0301'") == 1, 'a trial changes nothing');

    // A run in batches: at least one item a step.
    $run = $importers->start($importer, $file, 'cikkek.csv', (int) $admin->id());
    check($run->isRunning() && $run->total === 4 && $run->position === 0);
    throws(\Campanella\Import\ImportException::class, static fn () => $importers->start($importer, $file, 'megint.csv'));
    $steps = 0;
    while ($run->isRunning() && $steps < 10) {
        $run = $importers->step($run->id, 0.0);
        $steps++;
    }
    check($run->status === 'done' && $steps === 4 && $run->created === 2 && $run->failed === 2 && count($run->errors) === 2, json_encode([$run->status, $steps, $run->created, $run->failed]));
    check($run->errors[0]['row'] === 3 && $run->errors[0]['key'] === 'A3' && $run->errors[1]['message']->key === 'import.error.no_key', 'the errors by row: ' . json_encode($run->errors));
    $first = $c->get(QueryEngine::class)->first(Query::objects()->blueprint('article')->where('title', '=', 'Import első-0301'), Actor::system());
    $second = $c->get(QueryEngine::class)->first(Query::objects()->blueprint('article')->where('title', '=', 'Import második-0301'), Actor::system());
    check($first !== null && $second !== null && $first->as(Publishable::class)->isPublished() && (string) $first->get('lead') === 'Bevezető egy', 'made and published');
    check(!str_contains((string) $second->get('body'), 'script') && $second->as(Publishable::class)->publishedAt()?->format('Y-m-d H:i') === '2026-10-01 10:00', 'filtered; published at its own time');
    $new = $c->get(QueryEngine::class)->first(Query::objects()->blueprint('category')->where('title', '=', 'Import-új-0301'), Actor::system());
    check($new !== null && count($second->relatedIds('categories')) === 2 && in_array((int) $category->id(), $first->relatedIds('categories'), true), 'a target made, an existing one found');
    check((int) $first->as(\Campanella\Capability\Authorable::class)->authorId() === (int) $admin->id(), 'in the name of the importer\'s user');

    // Again: unchanged.
    $again = $importers->start($importer, $file, 'cikkek.csv');
    $again = $importers->step($again->id, 30.0);
    check($again->status === 'done' && $again->unchanged === 2 && $again->created === 0 && $again->failed === 2 && $again->missing === 0, json_encode([$again->unchanged, $again->created]));

    // Changed, gone, new; a key twice.
    $write([
        ['A1', 'Import első-0301', 'Bevezető egy, másképp', 'Első sor', 'Import-meglévő-0301', ''],
        ['A4', 'Import negyedik-0301', '', '', '', ''],
        ['A4', 'Import negyedik megint-0301', '', '', '', ''],
    ]);
    $third = $importers->step($importers->start($importer, $file, 'masodik.csv')->id, 30.0);
    check($third->updated === 1 && $third->created === 1 && $third->failed === 1 && $third->missing === 1 && $third->errors[0]['message']->key === 'import.error.duplicate', json_encode([$third->updated, $third->created, $third->failed, $third->missing]));
    check((string) $repository->find((int) $first->id())?->get('lead') === 'Bevezető egy, másképp', 'updated');
    check(!$repository->find((int) $second->id())?->as(Publishable::class)->isPublished(), 'an item gone: a draft');
    check(count($importers->runs('teszt_cikkek')) === 3 && $importers->objectCount('teszt_cikkek') === 3, 'the log');

    // Undoing the third run deletes what it made only.
    $fourth = $c->get(QueryEngine::class)->first(Query::objects()->blueprint('article')->where('title', '=', 'Import negyedik-0301'), Actor::system());
    check($importers->undo($third->id) === 1 && $repository->find((int) $fourth?->id()) === null && $repository->find((int) $first->id()) !== null, 'undone');
    check($importers->run($third->id)?->status === 'undone');
    throws(\Campanella\Import\ImportException::class, static fn () => $importers->undo($third->id));

    // A blocked user cannot run it.
    $admin->as(Authenticatable::class)->block();
    $repository->save($admin);
    throws(\Campanella\Import\ImportException::class, static fn () => $importers->start($importer, $file, 'x.csv'));
    check(glob(dirname(__DIR__) . '/var/imports/*.csv') === [], 'no file left behind');

    $importers->delete('teszt_cikkek');
    check($store->find('teszt_cikkek') === null && $importers->runs('teszt_cikkek') === [], 'deleted with its log');
    foreach ([$first, $second, $new, $category] as $object) {
        if ($object !== null && $repository->find((int) $object->id()) !== null) {
            $repository->delete($object);
        }
    }
    $repository->delete($admin);
    @unlink($file);
    foreach (glob(dirname(__DIR__) . '/var/backups/campanella-*') ?: [] as $backup) {
        @unlink($backup);
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: the importers in the admin: made, mapped, a trial, a run step by step, its log, undone', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $send = function (string $method, string $path, array $post = [], array $files = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, post: $post, files: $files));
    };
    $container->get(AuthService::class)->login(new Request('GET', '/'), $newUser('imp-szerk@example.hu', 'imp-jelszo-123', ['editor']));
    check($send('GET', '/admin/system/importers')->status === 403, 'not for editors');
    $admin = $newUser('imp-admin@example.hu', 'imp-jelszo-123', ['administrator']);
    $boot()->get(AuthService::class)->login(new Request('GET', '/'), $admin);
    $overview = $send('GET', '/admin/system/importers');
    check($overview->status === 200 && str_contains($overview->body, 'Új importáló') && str_contains($overview->body, 'href="/admin/system/importers"'), 'the overview, in the menu');
    $token = preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $overview->body, $m) === 1 ? $m[1] : '';
    $new = $send('GET', '/admin/system/importers/new');
    check(str_contains($new->body, '<option value="article"') && !str_contains($new->body, '<option value="user"') && !str_contains($new->body, '<option value="menu_item"'), 'only content Blueprints');
    check($send('POST', '/admin/system/importers/new', ['_csrf' => $token, 'name' => 'Rossz', 'label' => 'X', 'blueprint' => 'article', 'format' => 'csv', 'key' => 'id', 'title' => 'cim'])->status === 422, 'an invalid name');
    $made = $send('POST', '/admin/system/importers/new', ['_csrf' => $token, 'name' => 'admin_cikkek', 'label' => 'Admin cikkek', 'blueprint' => 'article', 'format' => 'csv', 'key' => 'id', 'title' => 'cim']);
    check($made->status === 303 && ($made->headers['Location'] ?? '') === '/admin/system/importers/admin_cikkek', (string) $made->status);
    $edit = $send('GET', '/admin/system/importers/admin_cikkek');
    check($edit->status === 200 && str_contains($edit->body, 'name="mapping[0][source]" value="cim"') && str_contains($edit->body, '<option value="categories"') && str_contains($edit->body, '<option value="' . $admin->id() . '"') && str_contains($edit->body, 'imp-szerk@example.hu (editor)'), 'the form; the users with a role');
    $form = ['_csrf' => $token, 'label' => 'Admin cikkek', 'format' => 'csv', 'delimiter' => 'auto', 'items' => '', 'key' => 'id', 'publish' => '1',
        'missing_target' => 'skip', 'missing_items' => 'keep', 'user' => (string) $admin->id(),
        'mapping' => [['source' => 'cim', 'target' => 'title', 'separator' => '|'], ['source' => 'bevezeto', 'target' => 'lead', 'separator' => '|'], ['source' => '', 'target' => '', 'separator' => '|']]];
    check($send('POST', '/admin/system/importers/admin_cikkek', ['mapping' => [['source' => 'x', 'target' => 'nincs']]] + $form)->status === 422, 'an unknown target');
    check($send('POST', '/admin/system/importers/admin_cikkek', $form)->status === 303, 'saved');
    $stored = $boot()->get(\Campanella\Import\ImporterStore::class)->find('admin_cikkek');
    check(count($stored['mapping'] ?? []) === 2 && ($stored['publish'] ?? null) === true && ($stored['missing_target'] ?? null) === 'skip', json_encode($stored, JSON_UNESCAPED_UNICODE));

    // A trial of an uploaded file, then the run, step by step, its log, undone.
    $csv = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($csv, "id;cim;bevezeto\n1;Admin import egy-0301;Első\n2;Admin import kettő-0301;Második\n3;;Cím nélkül\n");
    $file = ['file' => new \Campanella\Http\UploadedFile('cikkek.csv', $csv, (int) filesize($csv))];
    check($send('POST', '/admin/system/importers/admin_cikkek/run', ['_csrf' => 'x'], $file)->status === 400, 'CSRF');
    $trial = $send('POST', '/admin/system/importers/admin_cikkek/run', ['_csrf' => $token], $file);
    check($trial->status === 200 && str_contains($trial->body, 'Admin import egy-0301') && str_contains($trial->body, 'name="token"'), (string) $trial->status);
    check($boot()->get(QueryEngine::class)->count(Query::objects()->where('title', '=', 'Admin import egy-0301'), Actor::system()) === 0, 'a trial changes nothing');
    $pending = preg_match('/name="token" value="([0-9a-f]{48})"/', $trial->body, $mm) === 1 ? $mm[1] : '';
    check($send('POST', '/admin/system/importers/admin_cikkek/start', ['_csrf' => $token, 'token' => str_repeat('0', 48), 'file_name' => 'x.csv'])->status === 303 && !is_file(dirname(__DIR__) . '/var/imports/pending-' . str_repeat('0', 48) . '.csv'), 'an unknown token');
    $started = $send('POST', '/admin/system/importers/admin_cikkek/start', ['_csrf' => $token, 'token' => $pending, 'file_name' => 'cikkek.csv']);
    check($started->status === 303 && preg_match('#/admin/system/importers/admin_cikkek/runs/(\d+)$#', $started->headers['Location'] ?? '', $run) === 1, (string) ($started->headers['Location'] ?? ''));
    check(!is_file(dirname(__DIR__) . '/var/imports/pending-' . $pending . '.csv'), 'the waiting file went');
    $page = $send('GET', '/admin/system/importers/admin_cikkek/runs/' . $run[1]);
    check($page->status === 200 && str_contains($page->body, 'data-import-continue') && str_contains($page->body, 'admin-import.js'), 'running: it goes on by itself');
    check($send('GET', '/admin/system/importers/admin_cikkek/runs/' . $run[1] . '/step')->status === 405, 'a step is a POST');
    check($send('POST', '/admin/system/importers/admin_cikkek/runs/' . $run[1] . '/step', ['_csrf' => $token])->status === 303);
    $log = $send('GET', '/admin/system/importers/admin_cikkek/runs/' . $run[1]);
    check(!str_contains($log->body, 'data-import-continue') && str_contains($log->body, '2 új, 0 módosult') && str_contains($log->body, 'imp-undo-confirm'), 'done, with its log');
    check($send('GET', '/admin/system/importers/masik/runs/' . $run[1])->status === 404, 'a run of another importer');
    check(str_contains($send('GET', '/admin/system/importers')->body, '/runs/' . $run[1]), 'the last run in the overview');
    $send('POST', '/admin/system/importers/admin_cikkek/runs/' . $run[1] . '/undo', ['_csrf' => $token]);
    check($boot()->get(QueryEngine::class)->count(Query::objects()->where('title', '=', 'Admin import egy-0301'), Actor::system()) === 1, 'undoing needs the box ticked');
    $send('POST', '/admin/system/importers/admin_cikkek/runs/' . $run[1] . '/undo', ['_csrf' => $token, 'confirm' => '1']);
    check($boot()->get(QueryEngine::class)->count(Query::objects()->where('title', '=', 'Admin import egy-0301'), Actor::system()) === 0, 'undone');

    // A file too large, one that cannot be read.
    $big = ['file' => new \Campanella\Http\UploadedFile('nagy.csv', $csv, \Campanella\Admin\ImporterPages::MAX_UPLOAD + 1)];
    check($send('POST', '/admin/system/importers/admin_cikkek/run', ['_csrf' => $token], $big)->status === 422, 'too large');
    file_put_contents($csv, "\xE1rv\n1\n");
    check($send('POST', '/admin/system/importers/admin_cikkek/run', ['_csrf' => $token], $file)->status === 422, 'not UTF-8');

    check($send('POST', '/admin/system/importers/admin_cikkek/delete', ['_csrf' => $token, 'confirm' => '1'])->status === 303 && $boot()->get(\Campanella\Import\ImporterStore::class)->find('admin_cikkek') === null, 'deleted');
    @unlink($csv);
    foreach (glob(dirname(__DIR__) . '/var/backups/campanella-*') ?: [] as $backup) {
        @unlink($backup);
    }
    putenv('CAMPANELLA_DB_PREFIX');
});

test('Kernel: the admin\'s list as CSV: its filters, the importer\'s form, no formulas; it imports again', function () use ($newUser): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = null;
    $storage = new ArraySessionStorage();
    $boot = static function () use (&$kernel, $storage) {
        $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
        $kernel->container()->set(Session::class, static fn () => new Session($storage));

        return $kernel->container();
    };
    $container = $boot();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        putenv('CAMPANELLA_DB_PREFIX');

        return;
    }
    $send = function (string $method, string $path, array $query = []) use (&$kernel, $storage, $boot) {
        $storage->endRequest();
        $boot();

        return $kernel->handle(new Request($method, $path, $query));
    };
    $service = $container->get(ObjectService::class);
    $repository = $container->get(ObjectRepository::class);
    $category = $service->create(Actor::system(), 'category', ['title' => 'Export-kategória-0301'], publish: true);
    $a = $service->create(Actor::system(), 'article', ['title' => 'Export egy-0301', 'lead' => '=HYPERLINK("http://example.com")', 'body' => '<p>Szöveg; "idézőjellel"</p>', 'format' => 'html'], publish: true, relations: ['categories' => [(int) $category->id()]]);
    $b = $service->create(Actor::system(), 'article', ['title' => 'Export kettő-0301', 'lead' => "Két\nsor"]);
    $editor = $newUser('export-szerk@example.hu', 'export-jelszo-12', ['editor']);
    $container->get(AuthService::class)->login(new Request('GET', '/'), $editor);
    $list = $send('GET', '/admin/article', ['q' => 'Export', 'status' => 'published']);
    check(str_contains($list->body, 'href="/admin/article/export?q=Export&amp;status=published"'), 'the button carries the filters');
    $export = $send('GET', '/admin/article/export', ['q' => 'Export']);
    check($export->status === 200 && str_starts_with($export->headers['Content-Type'] ?? '', 'text/csv') && str_contains($export->headers['Content-Disposition'] ?? '', 'article-'), 'for an editor too, as a file');
    check(str_starts_with($export->body, "\xEF\xBB\xBFid;title;body;path;published_at;lead;categories;status;created;updated\r\n"), substr($export->body, 0, 120));
    $file = tempnam(sys_get_temp_dir(), 'exp');
    file_put_contents($file, $export->body);
    $source = \Campanella\Import\ImportSource::file($file, 'csv');
    $rows = [];
    foreach ($source->items() as $item) {
        $rows[$item['title']] = $item;
    }
    check(count($rows) === 2 && $rows['Export egy-0301']['categories'] === 'Export-kategória-0301' && $rows['Export egy-0301']['status'] === 'published' && $rows['Export kettő-0301']['status'] === 'draft', json_encode(array_keys($rows), JSON_UNESCAPED_UNICODE));
    check($rows['Export egy-0301']['lead'] === "'=HYPERLINK(\"http://example.com\")" && str_starts_with($rows['Export egy-0301']['body'], '<p>Szöveg; ') && $rows['Export kettő-0301']['lead'] === "Két\nsor", 'no formula; quotes and line breaks kept: ' . json_encode([$rows['Export egy-0301']['lead'], $rows['Export egy-0301']['body'], $rows['Export kettő-0301']['lead']], JSON_UNESCAPED_UNICODE));
    check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rows['Export egy-0301']['published_at']) === 1, 'a moment in the site\'s time zone');
    check(count(iterator_to_array(\Campanella\Import\ImportSource::file($file, 'csv')->items())) === 2 && substr_count($send('GET', '/admin/article/export', ['q' => 'Export', 'status' => 'draft'])->body, "\r\n") === 2, 'the filters apply');
    // The file imports into an importer of the same columns.
    $container->get(\Campanella\Import\ImporterStore::class)->save('export_vissza', ['version' => 1, 'label' => 'Vissza', 'blueprint' => 'article', 'format' => 'csv', 'delimiter' => 'auto', 'items' => '',
        'key' => 'id', 'publish' => false, 'missing_target' => 'error', 'missing_items' => 'keep', 'user' => (int) $editor->id(),
        'mapping' => [['source' => 'title', 'target' => 'title'], ['source' => 'lead', 'target' => 'lead'], ['source' => 'categories', 'target' => 'categories', 'separator' => '|']]]);
    $importers = $boot()->get(\Campanella\Import\Importer::class);
    $trial = $importers->trial($importers->find('export_vissza') ?? throw new RuntimeException('no importer'), $file);
    check($trial->missingSources === [] && array_column($trial->rows, 'action') === ['create', 'create'], json_encode($trial->rows, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    check($send('GET', '/admin/image/export')->status === 404 || !str_contains($send('GET', '/admin/image')->body, '/admin/image/export'), 'no export of files');
    $importers->delete('export_vissza');
    foreach ([$a, $b, $category, $editor] as $object) {
        $repository->delete($object);
    }
    @unlink($file);
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
