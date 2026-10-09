<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Access\Actor;
use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Model\CampanellaObject;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use Campanella\Site\SiteSettings;

/**
 * Where an uploaded file is used (since 0.1.2): the texts that show an image or link
 * to it, in the `media_usage` table, so an image's delete page can list them.
 *
 * The table is filled when a text is saved (ObjectRepository calls record() for
 * every Textual object, in the same transaction): the files found in its HTML
 * (`src` and `href` of this site's media, a smaller copy counting as its image).
 * A row disappears with either object. The site's share image is a use too
 * (sharedBySite()).
 */
final class MediaUsage
{
    /** A stored file's path in an address; a copy (`-640`) stands for its original. */
    public const string PATH = '#(\d{4}/\d{2}/[0-9a-f]{24})(?:-[1-9]\d{1,4})?\.(jpg|png|webp|gif)(?![0-9a-z])#';

    public function __construct(
        private readonly Connection $db,
        private readonly QueryEngine $queries,
        private readonly ?SiteSettings $site = null,
    ) {
    }

    /**
     * The stored files an HTML text refers to (`src`, `href`): their originals' paths,
     * each once, in order.
     *
     * @return list<string>
     */
    public static function pathsIn(string $html): array
    {
        if (preg_match_all('#\s(?:src|href)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $html, $attributes) < 1) {
            return [];
        }
        $paths = [];
        foreach ($attributes[1] as $i => $value) {
            $value = $value !== '' ? $value : $attributes[2][$i];
            if (preg_match(self::PATH, $value, $m) === 1) {
                $paths[$m[1] . '.' . $m[2]] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Records which files an object's text uses (replacing what was recorded). Called
     * inside the save's transaction; `$table` is the MediaFile capability's table.
     */
    public static function record(Connection $db, int $objectId, string $html, string $table = 'cap_media_file'): void
    {
        $db->delete(CoreSchema::MEDIA_USAGE, ['object_id' => $objectId]);
        $paths = self::pathsIn($html);
        if ($paths === []) {
            return;
        }
        $params = [];
        foreach ($paths as $i => $path) {
            $params['p' . $i] = $path;
        }
        $in = implode(', ', array_map(static fn (string $key): string => ':' . $key, array_keys($params)));
        foreach ($db->fetchColumn("SELECT object_id FROM {{$table}} WHERE file_path IN ({$in})", $params) as $mediaId) {
            if ((int) $mediaId !== $objectId) {
                $db->insert(CoreSchema::MEDIA_USAGE, ['object_id' => $objectId, 'media_id' => (int) $mediaId]);
            }
        }
    }

    /**
     * The objects that use a file (those the actor may see), newest change first, at most
     * $limit; `total` counts every one of them.
     *
     * @return array{items: list<CampanellaObject>, total: int}
     */
    public function usedBy(CampanellaObject $media, Actor $actor, int $limit = 20): array
    {
        $ids = array_map(intval(...), $this->db->fetchColumn(
            'SELECT object_id FROM {media_usage} WHERE media_id = :id',
            ['id' => (int) $media->id()],
        ));
        if ($ids === []) {
            return ['items' => [], 'total' => 0];
        }
        $query = Query::objects()->where('id', 'IN', $ids);

        return [
            'items' => $this->queries->execute($query->orderBy('updated', 'DESC')->limit(max(1, $limit)), $actor)->items,
            'total' => $this->queries->count($query, $actor),
        ];
    }

    /** Whether the file is the site's share image (Site settings). */
    public function sharedBySite(CampanellaObject $media): bool
    {
        return $this->site !== null && $media->id() !== null && $this->site->shareImage() === $media->id();
    }
}
