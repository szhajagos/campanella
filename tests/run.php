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
use Campanella\Http\Request;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\ObjectRepository;
use Campanella\Model\ValidationException;
use Campanella\Query\Query;
use Campanella\Query\QueryCompiler;
use Campanella\Query\QueryEngine;
use Campanella\Query\QueryException;
use Campanella\Service\ObjectService;
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
    $object = $service->create($admin, 'article', ['title' => 'Első cikk', 'lead' => 'Bevezető', 'body' => "A\n\nB"]);
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
        check(isset($e->errors['categories']) && str_contains($e->errors['categories'], 'page'), json_encode($e->errors, JSON_UNESCAPED_UNICODE));
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
        check(($e->errors['owner_node'] ?? '') === 'kötelező kapcsolat', json_encode($e->errors, JSON_UNESCAPED_UNICODE));
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
        'relations' => [new Relation('categories', Cardinality::Many, targetBlueprints: ['category'], label: 'Kategóriák')],
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
        check(($e->errors['email'] ?? '') === 'érvénytelen e-mail-cím', json_encode($e->errors, JSON_UNESCAPED_UNICODE));
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
    check(!$blocked->success && str_contains($blocked->error, 'Túl sok'), $blocked->error);
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
    check(!$again->success && $again->error === 'A fiók le van tiltva.');
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

// --- Documentation examples -------------------------------------------------

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
