<?php

declare(strict_types=1);

/*
 * The performance check, step 2 (since 0.3.0): requests the busiest pages of a site
 * filled by fixture.php, and checks them against the limits.
 *
 *   php tests/performance/measure.php <site folder> [--no-limits]
 *
 * Each page is requested once to warm up (the templates' cache), then RUNS times, each
 * as a new request would be: a new Kernel, i.e. a new container and database
 * connection. Measured:
 *
 * - the time of the request, from the Kernel's start to its response; the median of
 *   the runs is checked against the page's limit;
 * - the number of SQL statements the request sent (the server's own count, the
 *   session's `Questions`), which does not depend on the machine: a page that sends
 *   one more query per object shown fails at once.
 *
 * --no-limits: only shows the results (e.g. with fewer objects than 10 000).
 */

use Campanella\Auth\AuthService;
use Campanella\Core\Kernel;
use Campanella\Database\Connection;
use Campanella\Http\ArraySessionStorage;
use Campanella\Http\Request;
use Campanella\Http\Response;
use Campanella\Http\Session;

$site = $argv[1] ?? '';
$limits = !in_array('--no-limits', $argv, true);
if ($site === '' || !is_file($site . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php measure.php <site folder> [--no-limits]\n");
    exit(2);
}
require $site . '/vendor/autoload.php';

const RUNS = 7;

/*
 * The pages: [what, path, as the administrator, the most milliseconds (median), the most
 * SQL statements, a text the page must have]. The limits leave room for a slow shared
 * host or CI machine; a page over them has become slower than it should be. The site's pages
 * have 75 statements since 0.3.4 (60 before): their header and footer are components, about
 * 15 statements (the regions' components, the footer's snippet, the menu); the admin, 60.
 */
$pages = [
    // A list made in the admin, with visitors' filters (0.2.9).
    ['A list, first page', '/perf-events', false, 500, 75, 'Mért események'],
    ['A list, page 100', '/perf-events?page=100', false, 500, 75, 'Mért események'],
    ['A list, one filter', '/perf-events?f[fajta]=koncert', false, 500, 75, 'Mért események'],
    ['A list, all 4 filters', '/perf-events?f[fajta]=koncert&f[nap][from]=2026-03-01&f[nap][to]=2026-09-30&f[ar][min]=1000&f[ar][max]=6000&f[ingyenes]=0', false, 500, 75, 'Mért események'],
    ['A list as a table, 50 a page', '/perf-table?f[fajta]=eloadas', false, 500, 75, 'Mért események táblázata'],
    // The admin's list of a Blueprint with its own columns and filters (0.2.8).
    ['Admin list', '/admin/perf_event', true, 500, 60, 'Mért esemény'],
    ['Admin list, sorted by a column', '/admin/perf_event?sort=ar&dir=asc', true, 500, 60, 'Mért esemény'],
    ['Admin list, filters', '/admin/perf_event?f[fajta]=mozi&f[nap][from]=2026-06-01&f[ar][max]=5000&f[helyszin]=@place', true, 500, 60, 'Mért esemény'],
    ['Admin list, title search, page 5', '/admin/perf_event?q=hold&page=5', true, 500, 60, 'Mért esemény'],
    // The search (0.2.7).
    ['Search, a common word', '/search?q=kert', false, 500, 75, 'kert'],
    ['Search, two words', '/search?q=kert+zene', false, 500, 75, 'kert'],
    ['Search, a rare word', '/search?q=csillagvizsg%C3%A1l%C3%B3', false, 500, 75, 'csillagvizsgáló'],
    ['Search, a word prefix, page 20', '/search?q=keny&page=20', false, 500, 75, 'keny'],
    // An event's own page, for comparison.
    ['An event\'s page', '@event', false, 300, 75, 'Helyszín'],
];

/** One request, as a new one: its response, time (ms) and number of SQL statements. */
$request = static function (string $path, bool $admin) use ($site): array {
    // Each from a network of its own: the search's limit per network (0.3.0) is not measured here.
    static $n = 0;
    $n++;
    $ip = '10.' . ($n >> 8 & 255) . '.' . ($n & 255) . '.1';
    $start = hrtime(true);
    $kernel = new Kernel($site);
    $storage = new ArraySessionStorage();
    $kernel->container()->set(Session::class, static fn () => new Session($storage));
    if ($admin) {
        $auth = $kernel->container()->get(AuthService::class);
        $auth->login(new Request('GET', '/'), $auth->findUserByEmail('perf-admin@example.hu') ?? throw new RuntimeException('no administrator'));
        $storage->endRequest();
    }
    $db = $kernel->container()->get(Connection::class);
    $questions = static fn (): int => (int) $db->fetchOne("SHOW SESSION STATUS LIKE 'Questions'")['Value'];
    $before = $questions();
    $parsed = parse_url($path);
    parse_str($parsed['query'] ?? '', $query);
    $response = $kernel->handle(new Request('GET', $parsed['path'] ?? '/', query: $query, ip: $ip));
    $queries = $questions() - $before - 1; // the SHOW itself counts too
    $ms = (hrtime(true) - $start) / 1e6;

    return [$response, $ms, $queries];
};

// The placeholders: a place (category) and an event with a path.
$c = (new Kernel($site))->container();
$db = $c->get(Connection::class);
$objects = (int) $db->fetchValue("SELECT COUNT(*) FROM {objects} WHERE blueprint = 'perf_event'");
$place = (string) $db->fetchValue("SELECT o.id FROM {objects} o JOIN {cap_titled} t ON t.object_id = o.id WHERE o.blueprint = 'category' AND t.title = 'Helyszín 7'");
$event = (string) $db->fetchValue("SELECT r.path FROM {objects} o JOIN {cap_routable} r ON r.object_id = o.id JOIN {cap_publishable} p ON p.object_id = o.id WHERE o.blueprint = 'perf_event' AND p.status = 'published' ORDER BY o.id LIMIT 1 OFFSET :half", ['half' => intdiv($objects, 2)]);
printf("%d objects of perf_event; %s, PHP %s; %d runs a page, the median\n\n", $objects, $db->fetchValue('SELECT VERSION()'), PHP_VERSION, RUNS);

$failures = [];
printf("%-34s %8s %8s %8s %8s  %s\n", 'Page', 'median', 'max', 'limit', 'queries', '');
foreach ($pages as [$what, $path, $admin, $maxMs, $maxQueries, $text]) {
    $path = $path === '@event' ? $event : str_replace('@place', $place, $path);
    $request($path, $admin); // warm-up
    $times = [];
    $queries = 0;
    $response = null;
    for ($run = 0; $run < RUNS; $run++) {
        /** @var Response $response */
        [$response, $ms, $queries] = $request($path, $admin);
        $times[] = $ms;
    }
    sort($times);
    $median = $times[intdiv(RUNS, 2)];
    $problems = [];
    if ($response->status !== 200) {
        $problems[] = "status {$response->status}";
    } elseif (!str_contains($response->body, $text)) {
        $problems[] = "no \"{$text}\" on the page";
    }
    if ($limits && $median > $maxMs) {
        $problems[] = sprintf('%.0f ms over %d ms', $median, $maxMs);
    }
    if ($limits && $queries > $maxQueries) {
        $problems[] = "{$queries} queries over {$maxQueries}";
    }
    printf("%-34s %5.0f ms %5.0f ms %5d ms %8d  %s\n", $what, $median, $times[RUNS - 1], $maxMs, $queries, $problems === [] ? '✔' : '✘ ' . implode('; ', $problems));
    if ($problems !== []) {
        $failures[] = $what . ': ' . implode('; ', $problems);
    }
}

echo "\n";
if ($failures !== []) {
    echo count($failures) . " page(s) failed:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}
echo $limits ? "All " . count($pages) . " pages within their limits.\n" : "Measured (no limits checked).\n";
