<?php

declare(strict_types=1);

/*
 * Egyszerű, függőség nélküli tesztfuttató a 0.0.1 maghoz.
 *
 *   php tests/run.php
 *
 * Valódi adatbázison fut (a config/local.php beállításaival), de külön
 * „test_” táblaprefixszel, és a végén eltakarít maga után.
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

require dirname(__DIR__) . '/vendor/autoload.php';

/** Teszthez: egy nem regisztrált capability. */
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

/** A docs/php-api/03-capability.md példája, változtatás nélkül. */
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

function check(bool $condition, string $message = 'feltétel nem teljesült'): void
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
        check($e instanceof $class, "{$class} helyett " . get_class($e) . ': ' . $e->getMessage());

        return;
    }
    throw new RuntimeException("{$class} kivétel várt, de nem keletkezett.");
}

// --- Összerakás külön táblaprefixszel ---------------------------------------

$config = Config::load(dirname(__DIR__) . '/config');
$db = Connection::fromConfig(['prefix' => 'test_'] + $config->get('database'));
$capabilities = new CapabilityRegistry([Titled::class, Textual::class, Routable::class, Publishable::class]);
$blueprints = new BlueprintRegistry($capabilities, require dirname(__DIR__) . '/config/blueprints.php');
$repository = new ObjectRepository($db, $capabilities, $blueprints);
$policy = new DefaultPolicy();
$engine = new QueryEngine($db, new QueryCompiler($capabilities), $repository, $capabilities, $policy);
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

echo "Campanella tesztek (PHP " . PHP_VERSION . ", " . $db->serverVersion() . ")\n\n";

// --- Segédosztályok ---------------------------------------------------------

echo "Support\n";

test('Slugger: magyar ékezetek', function (): void {
    check(Slugger::slugify('Árvíztűrő tükörfúrógép') === 'arvizturo-tukorfurogep');
    check(Slugger::slugify('  Megjelent a 0.0.1!  ') === 'megjelent-a-0-0-1');
});

test('UUID v7: formátum és időrend', function (): void {
    $a = Uuid::v7();
    usleep(2000);
    $b = Uuid::v7();
    check(Uuid::isValid($a) && $a[14] === '7', $a);
    check(strcmp($a, $b) < 0, 'a későbbi UUID nem nagyobb');
});

test('Request: alkönyvtárba telepítés', function (): void {
    $_SERVER = ['REQUEST_URI' => '/oldal/hirek/?page=2', 'SCRIPT_NAME' => '/oldal/public/index.php', 'REQUEST_METHOD' => 'GET'];
    $request = Request::fromGlobals();
    check($request->basePath === '/oldal' && $request->path === '/hirek', $request->basePath . ' ' . $request->path);
});

// --- Capability-szerződés ---------------------------------------------------

echo "\nCapability\n";

test('A függőségek feloldódnak (Routable → Titled)', function () use ($capabilities): void {
    check(array_keys($capabilities->resolve([Routable::class])) === ['titled', 'routable']);
});

test('A page Blueprint a Titled-et függőségként kapja meg', function () use ($blueprints): void {
    check(isset($blueprints->get('page')->capabilities['titled']));
});

test('Ütköző mezőnév regisztrálása hibát ad', function (): void {
    throws(CapabilityException::class, fn () => new CapabilityRegistry([Titled::class, Titled::class]));
});

test('Hiányzó capability esetén as() hibát ad', function () use ($repository): void {
    $object = $repository->create('article', ['title' => 'x']);
    check($object->has('publishable') && $object->has(Publishable::class));
    throws(CapabilityException::class, fn () => $object->as(FakeCapability::class));
});

test('Ismeretlen mező a létrehozáskor hibát ad', function () use ($repository): void {
    throws(OutOfBoundsException::class, fn () => $repository->create('article', ['titel' => 'elgépelve']));
});

// --- Tárolás ----------------------------------------------------------------

echo "\nRepository\n";

test('Mentés és visszatöltés: táblás és data mezők', function () use ($service, $repository, $admin): void {
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

test('Kötelező mező hiánya: ValidationException', function () use ($service, $admin): void {
    throws(ValidationException::class, fn () => $service->create($admin, 'article', ['body' => 'cím nélkül']));
});

test('Foglalt útvonal: ValidationException, a tranzakció visszagördül', function () use ($service, $admin, $engine): void {
    $before = $engine->count(Query::objects(), $admin);
    throws(ValidationException::class, fn () => $service->create($admin, 'article', ['title' => 'Első cikk']));
    check($engine->count(Query::objects(), $admin) === $before, 'félkész objektum maradt az adatbázisban');
});

test('Módosítás és törlés (a capability-sorok is törlődnek)', function () use ($service, $repository, $admin, $db): void {
    $object = $service->create($admin, 'page', ['title' => 'Ideiglenes']);
    $service->update($admin, $object, ['title' => 'Átnevezett']);
    check($repository->find((int) $object->id())?->get('title') === 'Átnevezett');

    $service->delete($admin, $object);
    check($repository->find((int) $object->id()) === null);
    check((int) $db->fetchValue('SELECT COUNT(*) FROM {cap_titled} WHERE object_id = :id', ['id' => $object->id()]) === 0);
});

// --- Query és jogosultság ---------------------------------------------------

echo "\nQuery + Access\n";

$past = new DateTimeImmutable('-1 hour');
$future = new DateTimeImmutable('+1 day');
$published = $service->create($admin, 'article', ['title' => 'Publikált']);
$service->publish($admin, $published, $past);
$scheduled = $service->create($admin, 'article', ['title' => 'Időzített']);
$service->publish($admin, $scheduled, $future);

test('Anonymous csak a publikáltat látja (SQL-szintű szűrés)', function () use ($engine, $anon): void {
    $titles = array_map(fn ($o) => $o->get('title'), $engine->execute(Query::objects(), $anon)->items);
    check($titles === ['Publikált'], implode(', ', $titles));
});

test('Az administrator mindent lát', function () use ($engine, $admin): void {
    check($engine->count(Query::objects()->blueprint('article'), $admin) === 3);
});

test('Időzített publikálás: a jövőbeli még nem látszik', function () use ($scheduled, $anon, $policy): void {
    check(!$policy->allows($anon, \Campanella\Access\Operation::View, $scheduled));
    check($scheduled->as(Publishable::class)->isPublished(new DateTimeImmutable('+2 days')));
});

test('Anonymous nem hozhat létre objektumot', function () use ($service, $anon): void {
    throws(\Campanella\Access\AccessDeniedException::class, fn () => $service->create($anon, 'article', ['title' => 'Nem']));
});

test('Scope, rendezés, lapozás, összes találat', function () use ($engine, $admin): void {
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

test('Data (JSON) mezőre szűrni nem lehet', function () use ($engine, $admin): void {
    throws(QueryException::class, fn () => $engine->execute(Query::objects()->where('lead', '=', 'x'), $admin));
});

test('Ismeretlen mező és operátor: QueryException', function () use ($engine, $admin): void {
    throws(QueryException::class, fn () => $engine->execute(Query::objects()->where('nincs', '=', 1), $admin));
    throws(QueryException::class, fn () => Query::objects()->where('title', 'LIKEE', 'x'));
});

test('A Query megváltoztathatatlan', function (): void {
    $base = Query::objects()->limit(5);
    $derived = $base->limit(10)->where('title', '=', 'x');
    check($base->getLimit() === 5 && $base->conditions()->conditions === []);
    check($derived->getLimit() === 10);
});

// --- A dokumentáció példái ---------------------------------------------------

echo "\nDokumentációs példák\n";

test('Új capability a docs példája szerint (Weighted)', function () use ($db, $admin): void {
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

test('Kernel: a felülírt szolgáltatás több kérésen át megmarad', function (): void {
    putenv('CAMPANELLA_DB_PREFIX=test_');
    $kernel = new \Campanella\Core\Kernel(dirname(__DIR__));
    $container = $kernel->container();
    if ($container->get(Connection::class)->prefix() !== 'test_') {
        echo "      (kihagyva: a config/local.php saját prefixet ad meg)\n";

        return;
    }
    // A docs/php-api/05-jogosultsag.md mintájára: minden látható.
    $container->set(\Campanella\Access\AccessPolicy::class, static fn () => new class implements \Campanella\Access\AccessPolicy {
        public function constrain(Query $query, Actor $actor): Query { return $query; }
        public function allows(Actor $actor, \Campanella\Access\Operation $operation, \Campanella\Model\CampanellaObject $object): bool { return true; }
    });

    $first = $kernel->handle(new Request('GET', '/idozitett', basePath: '/alkonyvtar'));
    $second = $kernel->handle(new Request('GET', '/idozitett'));
    check($first->status === 200 && $second->status === 200, "{$first->status} / {$second->status}");
    check(str_contains($first->body, 'href="/alkonyvtar/hirek"'), 'az első kérés URL-előtagja hiányzik');
    check(str_contains($second->body, 'href="/hirek"') && !str_contains($second->body, '/alkonyvtar'), 'a második kérés URL-előtagja rossz');
    putenv('CAMPANELLA_DB_PREFIX');
});

// --- Eltakarítás ------------------------------------------------------------

$dropAll();

echo "\n{$passed} sikeres, {$failed} sikertelen\n";
exit($failed === 0 ? 0 : 1);
