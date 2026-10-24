<?php

declare(strict_types=1);

/*
 * The performance check, step 1 (since 0.3.0): fills a freshly installed site with a
 * content model and many objects of it, as a large site has them.
 *
 *   php tests/performance/fixture.php <site folder> <number of objects>
 *
 * - the content model, imported as the admin's System → Content model does: a Blueprint
 *   `perf_event` with queryable fields of every filter kind (a date, a choice, a decimal,
 *   a yes/no), a multi-valued one and a relation to a category, its 4 admin columns;
 *   two lists of it, one with visitors' filters (4, the most) and one as a table;
 * - 30 categories (the places);
 * - the objects, saved one by one through the ObjectService as the admin saves them
 *   (with their search index, relation and path). Their texts are made of a fixed
 *   vocabulary with a fixed seed, so every run has the same data: a few words are in
 *   most of them, one (`csillagvizsgáló`) is in every 1000th only.
 */

use Campanella\Access\Actor;
use Campanella\Core\Kernel;
use Campanella\Model\ObjectRepository;
use Campanella\Service\ObjectService;
use Campanella\Transfer\ModelImport;

[$site, $count] = [$argv[1] ?? '', (int) ($argv[2] ?? 0)];
if ($site === '' || $count < 1 || !is_file($site . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php fixture.php <site folder> <number of objects>\n");
    exit(2);
}
require $site . '/vendor/autoload.php';

$system = Actor::system();
$kernel = new Kernel($site);
$import = $kernel->container()->get(ModelImport::class);
$plan = $import->plan([
    'blueprints' => ['perf_event' => [
        'version' => 1, 'label' => 'Mért esemény', 'capabilities' => ['titled', 'textual', 'routable', 'publishable'],
        'fields' => [
            ['name' => 'nap', 'type' => 'date', 'label' => 'Nap', 'queryable' => true, 'required' => true],
            ['name' => 'fajta', 'type' => 'choice', 'label' => 'Fajta', 'queryable' => true, 'choices' => [
                ['key' => 'eloadas', 'label' => 'Előadás'], ['key' => 'seta', 'label' => 'Séta'],
                ['key' => 'koncert', 'label' => 'Koncert'], ['key' => 'kiallitas', 'label' => 'Kiállítás'],
                ['key' => 'mozi', 'label' => 'Mozi'], ['key' => 'vasar', 'label' => 'Vásár'],
            ]],
            ['name' => 'ar', 'type' => 'decimal', 'label' => 'Ár', 'queryable' => true, 'scale' => 0],
            ['name' => 'ingyenes', 'type' => 'boolean', 'label' => 'Ingyenes', 'queryable' => true],
            ['name' => 'cimkek', 'type' => 'string', 'label' => 'Címkék', 'queryable' => true, 'cardinality' => 5, 'length' => 40],
        ],
        'relations' => [['name' => 'helyszin', 'label' => 'Helyszín', 'targets' => ['category'], 'many' => false]],
        'admin_columns' => ['nap', 'fajta', 'ar', 'helyszin'],
    ]],
    'lists' => [
        'perf_events' => [
            'version' => 1, 'title' => 'Mért események', 'blueprint' => 'perf_event',
            'conditions' => [['field' => 'nap', 'op' => '>=', 'value' => '2026-01-01']],
            'sort' => [['field' => 'nap', 'dir' => 'asc']], 'per_page' => 20, 'display' => 'teaser',
            'columns' => [], 'path' => '/perf-events', 'filters' => ['fajta', 'nap', 'ar', 'ingyenes'],
        ],
        'perf_table' => [
            'version' => 1, 'title' => 'Mért események táblázata', 'blueprint' => 'perf_event',
            'conditions' => [['field' => 'fajta', 'op' => '!=', 'value' => 'vasar']],
            'sort' => [['field' => 'ar', 'dir' => 'desc'], ['field' => 'nap', 'dir' => 'asc']], 'per_page' => 50, 'display' => 'table',
            'columns' => ['nap', 'fajta', 'ar', 'cimkek'], 'path' => '/perf-table', 'filters' => ['fajta'],
        ],
    ],
    'campanella' => '', 'exported_at' => '',
]);
if ($plan->hasErrors()) {
    fwrite(STDERR, "The content model could not be planned.\n");
    exit(1);
}
$import->apply($plan);

// A new Kernel: the Blueprint is defined when the container is built.
$kernel = new Kernel($site);
$c = $kernel->container();
$objects = $c->get(ObjectService::class);
$repository = $c->get(ObjectRepository::class);

mt_srand(20261024);
$places = [];
for ($i = 1; $i <= 30; $i++) {
    $places[] = $objects->create($system, 'category', ['title' => 'Helyszín ' . $i, 'path' => '/helyszin-' . $i], publish: true);
}

$words = explode(' ', 'alma hold kert tó híd vár zene tánc könyv kép fény árnyék erdő mező folyó város falu utca tér ház '
    . 'kapu torony templom iskola piac malom hegy völgy domb sziget part hajó vonat busz kerék út ösvény kő fa '
    . 'virág levél ág gyökér mag gyümölcs kenyér bor sajt méz tej víz tűz szél eső hó jég nyár ősz tél tavasz '
    . 'reggel este éjszaka hajnal alkony vasárnap hétfő ünnep vásár játék mese dal vers regény színház mozi '
    . 'előadás kiállítás koncert séta túra verseny futás úszás kerékpár festmény szobor fotó térkép levéltár '
    . 'múzeum könyvtár egyetem kollégium műhely kemence kút fürdő park liget sétány rakpart kikötő állomás');
$kinds = ['eloadas', 'seta', 'koncert', 'kiallitas', 'mozi', 'vasar'];
$tags = ['zene', 'gyerek', 'család', 'szabadtér', 'ingyenes', 'éjszakai', 'kiállítás', 'irodalom', 'tánc', 'film'];
$text = static function (int $n) use ($words): string {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $words[mt_rand(0, count($words) - 1)];
    }

    return implode(' ', $out);
};

$start = microtime(true);
$day = new DateTimeImmutable('2026-01-01');
for ($i = 1; $i <= $count; $i++) {
    $price = mt_rand(0, 4) === 0 ? 0 : mt_rand(5, 120) * 100;
    $body = '';
    for ($p = 0; $p < 3; $p++) {
        $body .= '<p>' . ucfirst($text(40)) . ($p === 1 && $i % 1000 === 0 ? ' csillagvizsgáló' : '') . '.</p>';
    }
    $tagged = [];
    foreach ((array) array_rand(array_flip($tags), mt_rand(1, 3)) as $tag) {
        $tagged[] = (string) $tag;
    }
    $event = $objects->create($system, 'perf_event', [
        'title' => ucfirst($text(4)) . ' ' . $i,
        'nap' => $day->modify('+' . mt_rand(0, 729) . ' days')->format('Y-m-d'),
        'fajta' => $kinds[mt_rand(0, count($kinds) - 1)],
        'ar' => (string) $price,
        'ingyenes' => $price === 0,
        'cimkek' => $tagged,
        'body' => $body,
        'format' => 'html',
    ], publish: mt_rand(0, 9) !== 0); // every 10th or so a draft
    $event->relate('helyszin', $places[mt_rand(0, count($places) - 1)]);
    $repository->save($event);
    if ($i % 1000 === 0) {
        printf("  %d objects, %.0f s\n", $i, microtime(true) - $start);
    }
}

// An administrator for the admin's pages (measure.php logs in as it, without a password).
$c->get(\Campanella\Service\UserService::class)->create($system, 'Mérő Admin', 'perf-admin@example.hu', bin2hex(random_bytes(16)), ['administrator']);
