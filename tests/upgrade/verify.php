<?php

declare(strict_types=1);

/*
 * The upgrade test, step 3 (since 0.2.0): runs against the UPGRADED installation, with
 * the new code. Checks that nothing was lost (every row and column of the snapshot,
 * every media file), and that the site works as the new version: logins, pages, the
 * admin, the new features on the old data.
 *
 *   php tests/upgrade/verify.php <site folder> <snapshot.json>
 *
 * Exits with 1 if anything is wrong, listing every problem.
 */

use Campanella\Access\Actor;
use Campanella\Auth\AuthService;
use Campanella\Capability\MediaFile;
use Campanella\Core\Kernel;
use Campanella\Core\Version;
use Campanella\Database\Connection;
use Campanella\Database\Installer;
use Campanella\Database\Sync\SchemaSync;
use Campanella\Http\ArraySessionStorage;
use Campanella\Http\Request;
use Campanella\Http\Session;
use Campanella\Media\MediaService;

[$site, $snapshotFile] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($site === '' || !is_file($site . '/vendor/autoload.php') || !is_file($snapshotFile)) {
    fwrite(STDERR, "Usage: php verify.php <site folder> <snapshot.json>\n");
    exit(2);
}
require $site . '/vendor/autoload.php';

/** @var array{version: string, schema: string, tables: array<string, list<array<string, ?string>>>, media: array<string, string>, image: array{id: int, src: string}, article: array{id: int, path: string}, users: array<string, array{string, string}>} $snapshot */
$snapshot = json_decode((string) file_get_contents($snapshotFile), true, flags: JSON_THROW_ON_ERROR);
$problems = [];
$checks = 0;
$check = static function (bool $ok, string $what) use (&$problems, &$checks): void {
    $checks++;
    if (!$ok) {
        $problems[] = $what;
    }
};

$kernel = new Kernel($site);
$c = $kernel->container();
$db = $c->get(Connection::class);
$installer = $c->get(Installer::class);

// 1. The upgrade finished.
$check($installer->systemValue('schema_version') === Version::SCHEMA, 'schema version: ' . $installer->systemValue('schema_version') . ', expected ' . Version::SCHEMA);
$check(!$installer->needsUpgrade(), 'an upgrade is still needed');
$check(!$c->get(SchemaSync::class)->plan()->hasWork(), 'the tables differ from the definitions');
$backups = glob($site . '/var/backups/*.sql*') ?: [];
if ($snapshot['schema'] !== Version::SCHEMA) {
    $check($backups !== [], 'no backup was made before the upgrade');
    // The backup holds the old version's database (since 0.2.0), so going back to it works.
    $first = $backups === [] ? '' : (string) (str_ends_with($backups[0], '.gz') ? gzdecode((string) file_get_contents($backups[0])) : file_get_contents($backups[0]));
    $check(str_contains($first, "('schema_version', '{$snapshot['schema']}')"), 'the backup does not hold the old schema version (' . $snapshot['schema'] . ')');
} else {
    // The same schema (e.g. from 0.1.5 to 0.2.0): nothing in the database changes, so nothing to back up.
    $check($backups === [], 'a backup was made although the database did not change');
}

// 2. Nothing was lost: every row of every table, with its old columns.
$prefix = $db->prefix();
foreach ($snapshot['tables'] as $table => $rows) {
    if (!$db->tableExists($table)) {
        $check(false, "table {$table} is gone");
        continue;
    }
    $now = $db->fetchAll('SELECT * FROM ' . Connection::quoteIdentifier($prefix . $table));
    $check(count($now) === count($rows), "{$table}: " . count($now) . ' rows, ' . count($rows) . ' before');
    $key = static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR);
    $index = [];
    foreach ($now as $row) {
        $index[] = $row;
    }
    foreach ($rows as $old) {
        $found = false;
        foreach ($index as $new) {
            $same = true;
            foreach ($old as $column => $value) {
                if (!array_key_exists($column, $new) || ($new[$column] === null ? null : (string) $new[$column]) !== $value) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                $found = true;
                break;
            }
        }
        $check($found, "{$table}: a row changed or is missing: " . mb_substr($key($old), 0, 300));
    }
}
foreach ($snapshot['media'] as $path => $hash) {
    $file = $site . '/public/media/' . $path;
    $check(is_file($file) && hash_file('sha256', $file) === $hash, "media file changed or missing: {$path}");
}

// 3. The users can log in (the blocked one cannot); the passwords' hashes were kept.
$attempt = static function (string $email, string $password) use ($site): bool {
    $kernel = new Kernel($site);
    $kernel->container()->set(Session::class, static fn () => new Session(new ArraySessionStorage()));

    return $kernel->container()->get(AuthService::class)->attempt(new Request('POST', '/login', post: ['x' => '1'], ip: '192.0.2.10'), $email, $password)->success;
};
$check($attempt(...$snapshot['users']['admin']), 'the administrator cannot log in');
$check($attempt(...$snapshot['users']['editor']), 'the editor cannot log in');
$check(!$attempt(...$snapshot['users']['blocked']), 'the blocked user can log in');

// 4. The site's pages, as a visitor.
$get = static function (string $path, ?callable $as = null) use ($site): \Campanella\Http\Response {
    $kernel = new Kernel($site);
    $storage = new ArraySessionStorage();
    $kernel->container()->set(Session::class, static fn () => new Session($storage));
    if ($as !== null) {
        $as($kernel);
        $storage->endRequest();
    }

    return $kernel->handle(new Request('GET', $path));
};
$front = $get('/');
$check($front->status === 200, "the front page: {$front->status}");
$article = $get($snapshot['article']['path']);
$check($article->status === 200 && str_contains($article->body, 'Frissítési próbacikk'), "the article: {$article->status}");
$check(str_contains($article->body, $snapshot['image']['src']), 'the article lost its image');
foreach (['/contact' => 200, '/login' => 200, '/password-reset' => 404, '/robots.txt' => 200, '/nincs-ilyen-oldal' => 404] as $path => $status) {
    $response = $get($path);
    $check($response->status === $status, "{$path}: {$response->status}, expected {$status}");
}

// 5. The admin, as the administrator; the new pages work on the old data.
$asAdmin = static function (Kernel $kernel) use ($snapshot): void {
    $auth = $kernel->container()->get(AuthService::class);
    $auth->login(new Request('GET', '/'), $auth->findUserByEmail($snapshot['users']['admin'][0]) ?? throw new RuntimeException('no administrator'));
};
foreach (['/admin', '/admin/article', '/admin/article/' . $snapshot['article']['id'], '/admin/image', '/admin/user', '/admin/profile', '/admin/submission', '/admin/system', '/admin/system/settings', '/admin/system/mail', '/admin/menu'] as $path) {
    $response = $get($path, $asAdmin);
    $check($response->status === 200, "{$path} as the administrator: {$response->status}");
}
$check(str_contains($get('/admin/profile', $asAdmin)->body, 'id="sessions"'), 'the profile has no session list');

// 6. What the migrations and the new code make of the old data.
$check((int) $db->fetchValue('SELECT COUNT(*) FROM {media_usage} WHERE object_id = :a AND media_id = :i', ['a' => $snapshot['article']['id'], 'i' => $snapshot['image']['id']]) === 1, 'the image\'s use in the article was not indexed (core:0008_media_usage)');
$media = $c->get(MediaService::class);
$media->makeMissingVariants(30);   // as the System page's button does (none yet for an image of 0.1.0)
$image = $c->get(\Campanella\Model\ObjectRepository::class)->find($snapshot['image']['id']);
$widths = $image?->as(MediaFile::class)->variantWidths() ?? [];
$check($widths !== [], 'the old image has no smaller copies');
foreach ($widths as $width) {
    $path = MediaFile::variantPath((string) $image?->get('file_path'), $width);
    $check(is_file($site . '/public/media/' . $path), "a smaller copy is missing: {$path}");
}
// The search (0.2.7): the pages saved before it are indexed as the System page's button does, then found.
$indexer = $c->get(\Campanella\Search\SearchIndexer::class);
$check($indexer->missing() > 0 || (int) $snapshot['schema'] >= 16, 'the old pages are not reported as missing from the search');
$indexer->build(30);
$check($indexer->missing() === 0, 'the search index was not built: ' . $indexer->missing() . ' missing');
$found = $c->get(\Campanella\Search\Search::class)->find('frissitesi probacikk', Actor::anonymous());
$check(in_array($snapshot['article']['id'], array_map(static fn (array $i): int => (int) $i['object']->id(), $found->items), true), 'the old article is not found by the search');
// The redirects (0.2.7): an old article's changed path leads on.
$repository = $c->get(\Campanella\Model\ObjectRepository::class);
$old = $repository->find($snapshot['article']['id']) ?? throw new RuntimeException('no article');
$old->set('path', $snapshot['article']['path'] . '-uj');
$repository->save($old);
$moved = $get($snapshot['article']['path']);
$check($moved->status === 301 && ($moved->headers['Location'] ?? '') === $snapshot['article']['path'] . '-uj', "the old article's old path: {$moved->status}");
$old->set('path', $snapshot['article']['path']);
$repository->save($old);   // back, so verify.php can run again on the same site
$submissions = $c->get(\Campanella\Service\SubmissionService::class);
$message = $submissions->submit('Frissítés után', 'utana@example.hu', '', 'Az új funkció a régi adatokon.');
$check($submissions->find(Actor::system(), (int) $message->id()) !== null && $submissions->find(\Campanella\Auth\AuthService::actorFor($c->get(AuthService::class)->findUserByEmail($snapshot['users']['editor'][0]) ?? throw new RuntimeException('no editor')), (int) $message->id()) === null, 'a contact message is not for administrators only');
$submissions->delete(Actor::system(), $message);   // so verify.php can run again on the same site

// 7. The content model (since 0.3.0; an old version of 0.2.6 and later made one): the
// Blueprint made in the admin, its objects' values, its list, the redirect, the search.
$model = $snapshot['model'] ?? null;
if (is_array($model)) {
    $c = (new Kernel($site))->container();
    $registry = $c->get(\Campanella\Model\BlueprintRegistry::class);
    $blueprint = $registry->find($model['blueprint']);
    $check($blueprint !== null && $registry->isEditable($model['blueprint']), 'the Blueprint made in the admin is not loaded: ' . json_encode($registry->storedErrors()));
    if (version_compare($snapshot['version'], '0.2.8', '>=')) {
        $check($blueprint?->adminColumns === ['nap', 'fajta'], 'the admin\'s columns were lost: ' . json_encode($blueprint?->adminColumns));
    }
    $events = $c->get(\Campanella\Model\ObjectRepository::class)->loadMany($model['events']);
    $first = $events[$model['events'][0]] ?? null;
    $second = $events[$model['events'][1]] ?? null;
    $check($first !== null && (string) $first->get('nap') === '2027-01-15' && $first->get('fajta') === 'eloadas' && (string) $first->get('ar') === '1500'
        && $first->get('cimkek') === ['tél', 'zene'] && str_contains((string) $first->get('megjegyzes'), '<b>formázott</b>') && $first->relatedIds('helyszin') !== [],
        'an event\'s values changed: ' . json_encode($first === null ? null : ['nap' => (string) $first->get('nap'), 'fajta' => $first->get('fajta'), 'ar' => (string) $first->get('ar'), 'cimkek' => $first->get('cimkek'), 'helyszin' => $first->relatedIds('helyszin')], JSON_UNESCAPED_UNICODE));
    $check($second !== null && $second->get('cimkek') === ['tavasz'], 'the second event\'s values changed');
    $events = $c->get(\Campanella\Query\QueryEngine::class)->execute(\Campanella\Query\Query::objects()->blueprint($model['blueprint'])->where('cimkek', '=', 'zene'), Actor::anonymous());
    $check(count($events) === 1, 'a multi-valued queryable field cannot be queried');

    $list = $get($model['list_path']);
    $check($list->status === 200 && str_contains($list->body, 'Frissítési előadás') && !str_contains($list->body, 'Frissítési séta'), "the list's page: {$list->status}");
    if ($model['filters']) {
        $check(str_contains($list->body, 'name="f[nap][from]"'), 'the list\'s filters for visitors were lost');
    }
    $check($get('/admin/' . $model['blueprint'], $asAdmin)->status === 200 && $get('/admin/system/blueprints/' . $model['blueprint'], $asAdmin)->status === 200 && $get('/admin/system/lists/' . $model['list'], $asAdmin)->status === 200, 'the admin\'s pages of the content model');
    if (is_array($model['redirect'])) {
        $moved = $get($model['redirect']['from']);
        $check($moved->status === 301 && ($moved->headers['Location'] ?? '') === $model['redirect']['to'], 'the redirect: ' . $moved->status . ' ' . ($moved->headers['Location'] ?? ''));
    }
    $c->get(\Campanella\Search\SearchIndexer::class)->build(30);
    $found = $c->get(\Campanella\Search\Search::class)->find('frissitesi eloadas', Actor::anonymous());
    $check(in_array($model['events'][0], array_map(static fn (array $i): int => (int) $i['object']->id(), $found->items), true), 'the event is not found by the search');
    // Its own export imports into it without a change.
    $again = $c->get(\Campanella\Transfer\ModelImport::class)->plan(\Campanella\Transfer\ModelFile::decode($c->get(\Campanella\Transfer\ModelExport::class)->json()));
    $check(!$again->hasErrors() && !$again->hasChanges(), 'the content model\'s own export would change it: ' . json_encode(array_map(static fn ($i) => [$i->name, $i->action, (string) $i->error], $again->items), JSON_UNESCAPED_UNICODE));
}

// 8. The importers (since 0.3.1) work on the upgraded site: a CSV of two articles, in the
// administrator's name, to an old category; run again, nothing changes.
$adminUser = $c->get(\Campanella\Auth\AuthService::class)->findUserByEmail($snapshot['users']['admin'][0]);
$oldCategory = $c->get(\Campanella\Query\QueryEngine::class)->first(\Campanella\Query\Query::objects()->blueprint('category')->orderBy('id'), Actor::system());
if ($adminUser !== null && $oldCategory !== null) {
    $c->get(\Campanella\Import\ImporterStore::class)->save('frissitesi_import', ['version' => 1, 'label' => 'Frissítési import', 'blueprint' => 'article', 'format' => 'csv',
        'delimiter' => 'auto', 'items' => '', 'key' => 'id', 'publish' => true, 'missing_target' => 'error', 'missing_items' => 'keep', 'user' => (int) $adminUser->id(),
        'mapping' => [['source' => 'cim', 'target' => 'title'], ['source' => 'kategoria', 'target' => 'categories']]]);
    $csv = tempnam(sys_get_temp_dir(), 'upg');
    file_put_contents($csv, "id;cim;kategoria\n1;Frissítési import egy;" . $oldCategory->get('title') . "\n2;Frissítési import kettő;\n");
    $importers = $c->get(\Campanella\Import\Importer::class);
    $importer = $importers->find('frissitesi_import');
    $check($importer !== null, 'an importer does not load');
    if ($importer !== null) {
        $run = $importers->step($importers->start($importer, $csv, 'frissites.csv')->id, 30.0);
        $check($run->status === 'done' && $run->created === 2 && $run->failed === 0, 'the import: ' . json_encode([$run->status, $run->created, $run->failed, $run->errors]));
        $again = $importers->step($importers->start($importer, $csv, 'frissites.csv')->id, 30.0);
        $check($again->unchanged === 2, 'the import again changed something');
        $check($importers->undo($run->id) === 2, 'the import could not be undone');
    }
    @unlink($csv);
}

// 9. Files to download (since 0.3.2). A site that made a Blueprint called `file` in the admin
// keeps it (and files are off); any other gets files: uploaded, downloaded, for whom they are meant.
$registry = $c->get(\Campanella\Model\BlueprintRegistry::class);
$files = $c->get(\Campanella\Media\FileService::class);
if (is_array($model) && isset($model['own_file'])) {
    $check($registry->isEditable('file') && in_array('file', $registry->yielded(), true) && !$files->isAvailable(), 'the site\'s own `file` Blueprint did not keep its name');
    $own = $c->get(\Campanella\Model\ObjectRepository::class)->find($model['own_file']);
    $check($own?->get('leiras') === 'A webhely saját fájl-Blueprintje.', 'the object of the site\'s own `file` Blueprint is lost');
    $check($get('/admin/file/' . $model['own_file'], $asAdmin)->status === 200 && $get('/admin/system/blueprints/file', $asAdmin)->status === 200, 'the admin\'s pages of the site\'s own `file` Blueprint');
    $lines = array_filter($c->get(\Campanella\System\SystemCheck::class)->run(), static fn ($r): bool => $r->label === 'admin.system.files');
    $check(count($lines) === 1, 'the system page does not tell that files are off');
} else {
    $check($files->isAvailable() && !in_array('file', $registry->yielded(), true), 'files are not available');
    $pdf = tempnam(sys_get_temp_dir(), 'upg');
    file_put_contents($pdf, "%PDF-1.7\nfrissítés\n%%EOF");
    $public = $files->upload(Actor::system(), $pdf, 'Frissítési műsor.pdf');
    $private = $files->upload(Actor::system(), $pdf, 'Belső.pdf', \Campanella\Capability\Restricted::USERS);
    $download = $get($files->url($public));
    $check($download->status === 200 && $download->file !== null && is_file($download->file) && str_contains((string) ($download->headers['Content-Disposition'] ?? ''), 'frissitesi-musor.pdf'), 'a file cannot be downloaded: ' . $download->status);
    $check($get($files->url($private))->status === 404, 'a file for logged-in users is downloaded anonymously');
    $check($get($files->url($private), $asAdmin)->status === 200, 'the administrator cannot download a file for logged-in users');
    $c->get(\Campanella\Service\ObjectService::class)->delete(Actor::system(), $public);
    $c->get(\Campanella\Service\ObjectService::class)->delete(Actor::system(), $private);
    @unlink($pdf);
}

// 10. Displays and components (since 0.3.3). A site's own `snippet` Blueprint keeps its name;
// components are made, shown by their machine name, and only for those they are meant for.
if (is_array($model) && isset($model['own_snippet'])) {
    $check($registry->isEditable('snippet') && in_array('snippet', $registry->yielded(), true), 'the site\'s own `snippet` Blueprint did not keep its name');
    $check($c->get(\Campanella\Model\ObjectRepository::class)->find($model['own_snippet'])?->get('reszlet') === 'A webhely saját részlete.', 'the object of the site\'s own `snippet` Blueprint is lost');
} else {
    $check(!$registry->isEditable('snippet') && $registry->find('snippet') !== null, 'the snippet Blueprint is missing');
}
$components = $c->get(\Campanella\Display\ComponentService::class);
$check($components->isAvailable(), 'components are not available');
$button = $components->create(Actor::system(), ['title' => 'Frissítési gomb', 'machine_name' => 'frissitesi_gomb'], 'system:visitor', 'login_button');
$members = $components->create(Actor::system(), ['title' => 'Frissítési tagoknak', 'machine_name' => 'frissitesi_tagoknak', 'visible_to' => \Campanella\Capability\Restricted::USERS], 'system:visitor', 'login_button');
$anonymous = new \Campanella\Display\DisplayContext(Actor::anonymous());
$check(str_contains($components->show('frissitesi_gomb', $anonymous), 'display-login-button') && $components->show('frissitesi_tagoknak', $anonymous) === '', 'a component is not shown, or shown to whom it is not meant for');
$check($get('/admin/component', $asAdmin)->status === 200 && str_contains($get('/admin/component', $asAdmin)->body, 'Frissítési gomb'), 'the admin\'s components');
$check(str_contains($get('/', $asAdmin)->body, '<html'), 'the front page after the components');
$c->get(\Campanella\Service\ObjectService::class)->delete(Actor::system(), $button);
$c->get(\Campanella\Service\ObjectService::class)->delete(Actor::system(), $members);

echo "{$checks} checks, upgraded from {$snapshot['version']} (schema {$snapshot['schema']}) to " . Version::CAMPANELLA . ' (schema ' . Version::SCHEMA . ").\n";
if ($problems !== []) {
    echo count($problems) . " problems:\n";
    foreach ($problems as $problem) {
        echo "  ✘ {$problem}\n";
    }
    exit(1);
}
echo "✔ Everything survived the upgrade.\n";
