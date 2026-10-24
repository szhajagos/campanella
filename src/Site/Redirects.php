<?php

declare(strict_types=1);

namespace Campanella\Site;

use Campanella\Capability\Routable;
use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Database\Schema\SchemaReader;
use Campanella\Model\FieldType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Redirects (since 0.2.7), in the `redirects` table: an old address that leads on
 * with `301 Moved Permanently`, so a link or a search engine's entry to a page keeps
 * working after its path changes.
 *
 * - **Automatic:** when an object's path changes, its old path leads to the object
 *   (by its ID: to its current path, however often it changes again; deleted with
 *   the object). Made by the ObjectRepository on save.
 * - **Made in the admin** (System → Redirects): from a path to a path of the site.
 *
 * A redirect is used only where nothing else answers (a page, a list, an object):
 * an object that takes a redirect's path deletes the redirect.
 */
final class Redirects
{
    /** The most redirects followed one after the other (a manual one to another's source). */
    private const int MAX_HOPS = 5;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * An object's path changed: its old path leads to it from now on; a redirect from its new
     * path goes (the object has that path now).
     */
    public static function moved(Connection $db, int $objectId, ?string $old, string $new): void
    {
        $db->delete(CoreSchema::REDIRECTS, ['source' => $new]);
        if ($old === null || $old === '' || $old === $new || $old === '/') {
            return;
        }
        $db->delete(CoreSchema::REDIRECTS, ['source' => $old]);
        $db->insert(CoreSchema::REDIRECTS, ['source' => $old, 'object_id' => $objectId, 'target' => null, 'hits' => 0, 'created_at' => self::now()]);
    }

    /**
     * Where a request's path leads (a path of the site), or null if it has no redirect.
     * Counts the use.
     */
    public function target(string $path): ?string
    {
        if (!$this->ready()) {
            return null;
        }
        $path = Routable::normalize($path);
        $seen = [];
        $first = null;
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $row = $this->db->fetchOne('SELECT id, object_id, target FROM {redirects} WHERE source = :s', ['s' => $path]);
            if ($row === null) {
                break;
            }
            $first ??= (int) $row['id'];
            $seen[$path] = true;
            $next = $row['object_id'] !== null
                ? $this->db->fetchValue('SELECT path FROM {cap_routable} WHERE object_id = :id', ['id' => (int) $row['object_id']])
                : $row['target'];
            if (!is_string($next) || $next === '' || isset($seen[$next])) {
                return null; // an object without a path, or a loop
            }
            $path = $next;
        }
        if ($first === null) {
            return null;
        }
        $this->db->execute('UPDATE {redirects} SET hits = hits + 1, last_hit_at = :now WHERE id = :id', ['now' => self::now(), 'id' => $first]);

        return $path;
    }

    /**
     * A page of the redirects, the newest first, with the object's title and current path.
     *
     * @return array{items: list<array{id: int, source: string, target: ?string, object_id: ?int, title: ?string, path: ?string, hits: int, last_hit_at: ?string, created_at: string}>, total: int}
     */
    public function page(int $page, int $perPage): array
    {
        if (!$this->ready()) {
            return ['items' => [], 'total' => 0];
        }
        $titles = (new SchemaReader($this->db))->tableExists('cap_titled');
        $rows = $this->db->fetchAll(
            'SELECT r.id, r.source, r.target, r.object_id, r.hits, r.last_hit_at, r.created_at, p.path'
            . ($titles ? ', t.title' : ', NULL AS title')
            . ' FROM {redirects} r LEFT JOIN {cap_routable} p ON p.object_id = r.object_id'
            . ($titles ? ' LEFT JOIN {cap_titled} t ON t.object_id = r.object_id' : '')
            . ' ORDER BY r.id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . (min(max(1, $page), intdiv(PHP_INT_MAX, max(1, $perPage))) - 1) * max(1, $perPage),
        );
        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'source' => (string) $r['source'],
            'target' => $r['target'] === null ? null : (string) $r['target'],
            'object_id' => $r['object_id'] === null ? null : (int) $r['object_id'],
            'title' => $r['title'] === null ? null : (string) $r['title'],
            'path' => $r['path'] === null ? null : (string) $r['path'],
            'hits' => (int) $r['hits'],
            'last_hit_at' => $r['last_hit_at'] === null ? null : (string) $r['last_hit_at'],
            'created_at' => (string) $r['created_at'],
        ], $rows);

        return ['items' => $items, 'total' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM {redirects}')];
    }

    public function exists(string $source): bool
    {
        return $this->ready() && $this->db->fetchValue('SELECT 1 FROM {redirects} WHERE source = :s', ['s' => $source]) !== null;
    }

    /** A redirect made in the admin; the paths are checked by the caller. */
    public function add(string $source, string $target): void
    {
        $this->db->insert(CoreSchema::REDIRECTS, ['source' => $source, 'object_id' => null, 'target' => $target, 'hits' => 0, 'created_at' => self::now()]);
    }

    public function delete(int $id): bool
    {
        return $this->ready() && $this->db->delete(CoreSchema::REDIRECTS, ['id' => $id]) > 0;
    }

    private function ready(): bool
    {
        return (new SchemaReader($this->db))->tableExists(CoreSchema::REDIRECTS);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(FieldType::STORAGE_DATE_FORMAT);
    }
}
