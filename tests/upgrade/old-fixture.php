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
 * Only APIs that 0.1.0 already had are used here. The passwords are test values.
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
    'users' => [
        'admin' => ['upgrade-admin@example.hu', 'upgrade-admin-jelszo-1'],
        'editor' => ['upgrade-editor@example.hu', 'upgrade-editor-jelszo-1'],
        'blocked' => ['upgrade-blocked@example.hu', 'upgrade-blocked-jelszo-1'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$count = array_sum(array_map('count', $tables));
echo "Snapshot of {$count} rows in " . count($tables) . " tables, " . count($media) . " media files (Campanella " . \Campanella\Core\Version::CAMPANELLA . ").\n";
