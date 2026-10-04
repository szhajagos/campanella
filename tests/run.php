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

/** The example from docs/php-api/03-capabilities.md, unchanged. */
#[\Campanella\Capability\AsCapability('weighted', label: 'Súlyozott')]
final class Weighted extends \Campanella\Capability\Capability
{
    #[\Override]
    public static function fields(): array
    {
        return [
            new \Campanella\Model\Field('weight', \Campanella\Model\FieldType::Integer, required: true, default: 0, indexed: true, label: 'Súly'),
        ];
    }

    #[\Override]
    public static function scopes(): array
    {
        return [
            'by_weight' => static fn (Query $q): Query => $q->orderBy('weight', 'ASC'),
        ];
    }

    public function weight(): int
    {
        return (int) $this->object->get('weight');
    }

    public function setWeight(int $weight): void
    {
        $this->object->set('weight', $weight);
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
        throw new RuntimeException($message);
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
$installer = new Installer($db, $capabilities);

$admin = Actor::system();
$anon = Actor::anonymous();

$dropAll = static function () use ($db, $installer): void {
    $db->execute('SET FOREIGN_KEY_CHECKS = 0');
    $db->execute('DROP TABLE IF EXISTS ' . $db->table('cap_weighted'));
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
$req = static fn (array $post = [], string $ip = '10.0.0.1') => new Request($post === [] ? 'GET' : 'POST', '/belepes', post: $post, ip: $ip);

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

    $form = $kernel->handle(new Request('GET', '/belepes'));
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $form->body, $m);
    check(isset($m[1]) && str_contains($form->body, 'name="website"'), 'missing CSRF or honeypot field');
    check(($form->headers['Cache-Control'] ?? '') === 'private, no-store');
    check(str_contains($form->body, 'class="hp" aria-hidden="true" style="position:absolute'), 'the honeypot is not hidden without CSS');
    check(str_contains($form->body, 'campanella.css?v=' . Version::CAMPANELLA), 'asset() does not append the version');
    $storage->endRequest();

    $login = $kernel->handle(new Request('POST', '/belepes', post: [
        '_csrf' => $m[1], 'email' => 'szerkeszto@example.hu', 'password' => 'szerkeszto-jelszo',
        'website' => '', 'vissza' => '/szerkesztoi-piszkozat',
    ], ip: '10.7.7.7'));
    check($login->status === 303 && ($login->headers['Location'] ?? '') === '/szerkesztoi-piszkozat', (string) $login->status);
    $storage->endRequest();

    $page = $kernel->handle(new Request('GET', '/szerkesztoi-piszkozat'));
    check($page->status === 200 && str_contains($page->body, 'Kilépés'), 'the draft is not visible even when logged in');
    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $page->body, $m2);
    $storage->endRequest();

    $kernel->handle(new Request('POST', '/kilepes', post: ['_csrf' => $m2[1] ?? '']));
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
    throws(InvalidArgumentException::class, fn () => new Field('x', FieldType::StringList, cardinality: 3));
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
    $page = $kernel->handle(new Request('GET', '/belepes'));
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
    check($anonymous->status === 302 && ($anonymous->headers['Location'] ?? '') === '/belepes?vissza=%2Fadmin', 'anonymous: to the login page');
    $deep = $kernel->handle(new Request('GET', '/admin/article', query: ['status' => 'draft']));
    check(($deep->headers['Location'] ?? '') === '/belepes?vissza=' . rawurlencode('/admin/article?status=draft'), 'back to the requested page');

    $as($newUser('tag@example.hu', 'tag-jelszava-1', ['member']));
    check($kernel->handle(new Request('GET', '/admin'))->status === 403, 'a member may not enter');
    $storage->endRequest();

    $as($editorUser);
    $dashboard = $kernel->handle(new Request('GET', '/admin'));
    check($dashboard->status === 200, (string) $dashboard->status);
    check(str_contains($dashboard->body, 'Irányítópult') && str_contains($dashboard->body, 'Cikk'), 'dashboard with the translated Blueprint labels');
    check(($dashboard->headers['X-Robots-Tag'] ?? '') === 'noindex, nofollow' && ($dashboard->headers['Cache-Control'] ?? '') === 'private, no-store');
    check(str_contains($dashboard->body, 'admin.css?v='), 'the admin has its own stylesheet');
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

    check($get('/admin/user')->status === 404, 'no user list (users are managed from the command line)');
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
    $reserved = $send('POST', $location, ['_csrf' => $token($edit->body), '_version' => $stamp, 'f' => ['title' => 'Belépés', 'path' => '/belepes']]);
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

    // Without GD: the checked original is stored as it is.
    $original = testImage('png', 30, 30);
    $plain = (new \Campanella\Media\ImageProcessor(useGd: false))->process($original);
    check(!$plain->reencoded && $plain->bytes === file_get_contents($original) && $plain->width === 30);
    check(mediaError(fn () => (new \Campanella\Media\ImageProcessor(useGd: false))->process($temp('<?php ?>'))) === 'media.not_image', 'checked without GD too');

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

test('Kernel: images in the admin are listed, edited, but not created from a form', function () use ($editorUser, $admin): void {
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
    check(str_contains($checks['admin.system.media_reencode']->value, 'image/jpeg'), 'reencoded types listed');
    $list = $send('GET', '/admin/image');
    check($list->status === 200 && str_contains($list->body, 'logo') && !str_contains($list->body, 'href="/admin/image/new"'), 'listed, no New button');
    check($send('GET', '/admin/image/new')->status === 404, 'no empty form for a file');
    $form = $send('GET', '/admin/image/' . $image->id());
    check($form->status === 200 && str_contains($form->body, 'name="f[alt]"') && !str_contains($form->body, 'f[file_path]') && !str_contains($form->body, 'f[file_hash]'), 'file data is not in the form');

    preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $form->body, $c);
    preg_match('/name="_version" value="([0-9a-f]{64})"/', $form->body, $v);
    $send('POST', '/admin/image/' . $image->id(), ['_csrf' => $c[1] ?? '', '_version' => $v[1] ?? '', 'f' => [
        'title' => 'Logó', 'alt' => 'A Campanella logója', 'file_path' => '../../config/local.php',
    ]]);
    $saved = $container->get(ObjectRepository::class)->find((int) $image->id());
    check($saved?->get('alt') === 'A Campanella logója' && $saved->get('file_path') === $image->get('file_path'), 'a posted file path is ignored');
    putenv('CAMPANELLA_DB_PREFIX');
});

echo "\nDocumentation examples\n";

test('New capability as in the docs example (Weighted)', function () use ($db, $admin): void {
    $registry = new CapabilityRegistry([Titled::class, Textual::class, Routable::class, Publishable::class, Weighted::class]);
    $blueprints = new BlueprintRegistry($registry, [
        'page' => ['capabilities' => [Textual::class, Routable::class, Publishable::class, Weighted::class]],
    ]);
    (new Installer($db, $registry))->install();
    $repository = new ObjectRepository($db, $registry, $blueprints);
    $engine = new QueryEngine($db, new QueryCompiler($registry), $repository, $registry, new DefaultPolicy());
    $service = new ObjectService($repository, new DefaultPolicy());

    foreach (['Harmadik' => 30, 'Első' => 10, 'Második' => 20] as $title => $weight) {
        $page = $service->create($admin, 'page', ['title' => "Súly {$title}"]);
        $page->as(Weighted::class)->setWeight($weight);
        $repository->save($page);
    }
    $titles = array_map(
        fn ($o) => $o->get('title'),
        $engine->execute(Query::objects()->having('weighted')->scope('by_weight'), $admin)->items,
    );
    check($titles === ['Súly Első', 'Súly Második', 'Súly Harmadik'], implode(', ', $titles));
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
