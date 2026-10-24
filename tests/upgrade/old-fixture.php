<?php

declare(strict_types=1);

/*
 * The upgrade test, step 1 (since 0.2.0): runs against the OLD installation (0.1.0),
 * with its own code. Adds what a real site has besides the sample content (users of
 * every kind, an uploaded image shown in an article), then writes a snapshot of every
 * table for verify.php.
 *
 *   php tests/upgrade/old-fixture.php <old site folder> <snapshot.json>
 *
 * Only APIs that 0.1.0 already had are used here, but for the content model (since
 * 0.3.0): if the old version has the content model's import (0.2.6 and later), a
 * Blueprint made "in the admin" with its own fields, events of it, a list of them, and
 * (0.2.7 and later) a changed path, i.e. a redirect, are added as well, with that
 * version's own code. The passwords are test values.
 */

use Campanella\Access\Actor;
use Campanella\Core\Kernel;
use Campanella\Database\Connection;
use Campanella\Media\MediaService;
use Campanella\Service\ObjectService;
use Campanella\Service\UserService;

[$site, $snapshot] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($site === '' || $snapshot === '' || !is_file($site . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php old-fixture.php <old site folder> <snapshot.json>\n");
    exit(2);
}
require $site . '/vendor/autoload.php';

$kernel = new Kernel($site);
$c = $kernel->container();
$system = Actor::system();
$users = $c->get(UserService::class);
$objects = $c->get(ObjectService::class);

// Users: an administrator, an editor, a blocked one.
$admin = $users->create($system, 'Frissítő Admin', 'upgrade-admin@example.hu', 'upgrade-admin-jelszo-1', ['administrator']);
$editor = $users->create($system, 'Frissítő Szerkesztő', 'upgrade-editor@example.hu', 'upgrade-editor-jelszo-1', ['editor']);
$blocked = $users->create($system, 'Letiltott Lajos', 'upgrade-blocked@example.hu', 'upgrade-blocked-jelszo-1', ['editor']);
$users->update($system, $blocked, 'Letiltott Lajos', 'upgrade-blocked@example.hu', ['editor'], false);

// An uploaded image (large enough for the smaller copies of 0.1.2), shown in an article.
$file = tempnam(sys_get_temp_dir(), 'upg');
$gd = imagecreatetruecolor(1400, 900);
imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 30, 120, 90));
imagefilledellipse($gd, 700, 450, 600, 600, (int) imagecolorallocate($gd, 240, 200, 40));
imagejpeg($gd, $file, 90);
$image = $c->get(MediaService::class)->uploadImage($system, $file, 'Frissítési próbakép.jpg', 'Egy kör');
@unlink($file);
$src = $c->get(MediaService::class)->url($image);
$article = $objects->create($system, 'article', [
    'title' => 'Frissítési próbacikk',
    'lead' => 'Egy kép a szövegben.',
    'body' => '<p>Előtte.</p><p><img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="Egy kör"></p><p>Utána.</p>',
    'path' => '/frissitesi-probacikk',
], publish: true);

// The content model (old versions of 0.2.6 and later): a Blueprint made in the admin with
// queryable, multi-valued and formatted fields and a relation, two events, a list of them
// (with filters from 0.2.9; an older version ignores the key), and a changed path.
$model = null;
if (class_exists(\Campanella\Transfer\ModelImport::class)) {
    $import = $c->get(\Campanella\Transfer\ModelImport::class);
    $plan = $import->plan([
        'blueprints' => ['frissitesi_esemeny' => [
            'version' => 1, 'label' => 'Frissítési esemény', 'capabilities' => ['titled', 'textual', 'routable', 'publishable'],
            'fields' => [
                ['name' => 'nap', 'type' => 'date', 'label' => 'Nap', 'queryable' => true, 'required' => true],
                ['name' => 'fajta', 'type' => 'choice', 'label' => 'Fajta', 'queryable' => true, 'choices' => [['key' => 'eloadas', 'label' => 'Előadás'], ['key' => 'seta', 'label' => 'Séta']]],
                ['name' => 'ar', 'type' => 'decimal', 'label' => 'Ár', 'queryable' => true, 'scale' => 0],
                ['name' => 'cimkek', 'type' => 'string', 'label' => 'Címkék', 'queryable' => true, 'cardinality' => 5, 'length' => 40],
                ['name' => 'megjegyzes', 'type' => 'html', 'label' => 'Megjegyzés'],
            ],
            'relations' => [['name' => 'helyszin', 'label' => 'Helyszín', 'targets' => ['category'], 'many' => false]],
            'admin_columns' => ['nap', 'fajta'],
        ]],
        'lists' => ['frissitesi_esemenyek' => [
            'version' => 1, 'title' => 'Frissítési események', 'blueprint' => 'frissitesi_esemeny',
            'conditions' => [['field' => 'fajta', 'op' => '=', 'value' => 'eloadas']],
            'sort' => [['field' => 'nap', 'dir' => 'asc']], 'per_page' => 10, 'display' => 'table',
            'columns' => ['nap', 'ar'], 'path' => '/frissitesi-esemenyek', 'filters' => ['nap'],
        ]],
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
    $category = $c->get(\Campanella\Query\QueryEngine::class)->first(\Campanella\Query\Query::objects()->blueprint('category')->orderBy('id'), $system);
    $events = [];
    foreach ([['Frissítési előadás', '2027-01-15', 'eloadas', '1500', ['tél', 'zene']], ['Frissítési séta', '2027-02-01', 'seta', '0', ['tavasz']]] as [$title, $day, $kind, $price, $tags]) {
        $event = $objects->create($system, 'frissitesi_esemeny', [
            'title' => $title, 'nap' => $day, 'fajta' => $kind, 'ar' => $price, 'cimkek' => $tags,
            'megjegyzes' => '<p>Egy <b>formázott</b> megjegyzés.</p>', 'body' => '<p>' . $title . ' leírása.</p>', 'format' => 'html',
        ], publish: true);
        if ($category !== null) {
            $event->relate('helyszin', $category);
            $c->get(\Campanella\Model\ObjectRepository::class)->save($event);
        }
        $events[] = (int) $event->id();
    }
    $redirect = null;
    if (class_exists(\Campanella\Site\Redirects::class)) {
        // 0.2.7 and later: a changed path leads on from the old one.
        $first = $c->get(\Campanella\Model\ObjectRepository::class)->find($events[0]);
        $old = (string) $first?->get('path');
        $first?->set('path', '/frissitesi-eloadas-uj');
        if ($first !== null) {
            $c->get(\Campanella\Model\ObjectRepository::class)->save($first);
        }
        $redirect = ['from' => $old, 'to' => '/frissitesi-eloadas-uj'];
    }
    $model = ['blueprint' => 'frissitesi_esemeny', 'list' => 'frissitesi_esemenyek', 'list_path' => '/frissitesi-esemenyek', 'events' => $events, 'redirect' => $redirect,
        'filters' => class_exists(\Campanella\Query\FieldFilters::class)];
}

// The snapshot: every row of every table (but the system's own bookkeeping), so
// verify.php can check that the upgrade kept them (its new columns aside).
$db = $c->get(Connection::class);
$prefix = $db->prefix();
$tables = [];
foreach ($db->fetchColumn('SHOW TABLES LIKE ' . $db->pdo()->quote(addcslashes($prefix, '_%\\') . '%')) as $name) {
    $table = substr((string) $name, strlen($prefix));
    if (in_array($table, ['system', 'migrations', 'throttle'], true)) {
        continue;
    }
    $rows = $db->fetchAll('SELECT * FROM ' . Connection::quoteIdentifier((string) $name));
    $tables[$table] = array_map(static fn (array $row): array => array_map(static fn ($v) => $v === null ? null : (string) $v, $row), $rows);
}
$media = [];
foreach (glob($site . '/public/media/*/*/*') ?: [] as $path) {
    $media[substr($path, strlen($site . '/public/media/'))] = hash_file('sha256', $path);
}

file_put_contents($snapshot, json_encode([
    'version' => \Campanella\Core\Version::CAMPANELLA,
    'schema' => \Campanella\Core\Version::SCHEMA,
    'tables' => $tables,
    'media' => $media,
    'image' => ['id' => (int) $image->id(), 'src' => $src],
    'article' => ['id' => (int) $article->id(), 'path' => '/frissitesi-probacikk'],
    'model' => $model,
    'users' => [
        'admin' => ['upgrade-admin@example.hu', 'upgrade-admin-jelszo-1'],
        'editor' => ['upgrade-editor@example.hu', 'upgrade-editor-jelszo-1'],
        'blocked' => ['upgrade-blocked@example.hu', 'upgrade-blocked-jelszo-1'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$count = array_sum(array_map('count', $tables));
echo "Snapshot of {$count} rows in " . count($tables) . " tables, " . count($media) . " media files (Campanella " . \Campanella\Core\Version::CAMPANELLA . ").\n";
