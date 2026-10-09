<?php

declare(strict_types=1);

namespace Campanella\Database\Migration\Core;

use Campanella\Database\Migration\Migration;
use Campanella\Database\Migration\MigrationContext;
use Campanella\Database\Schema\Column;
use Campanella\Database\Schema\ColumnType;
use Campanella\Database\Schema\ForeignKey;
use Campanella\Database\Schema\Table;

/**
 * 0.1.2: which text shows which uploaded image. The `media_usage` table is filled
 * from the HTML texts saved before 0.1.2 (later saves keep it up to date), so the
 * delete page of an image can list the texts that show it.
 *
 * Repeatable: each text's rows are written again.
 */
final class MediaUsageIndex implements Migration
{
    /** A stored file's path in an address (a smaller copy stands for its original). */
    private const string PATH = '#(\d{4}/\d{2}/[0-9a-f]{24})(?:-[1-9]\d{1,4})?\.(jpg|png|webp|gif)(?![0-9a-z])#';

    #[\Override]
    public function id(): string
    {
        return 'core:0008_media_usage';
    }

    #[\Override]
    public function description(): string
    {
        return 'Which texts show which uploaded images (media_usage), from the existing texts';
    }

    #[\Override]
    public function up(MigrationContext $m): void
    {
        if (!$m->tableExists('media_usage')) {
            $m->createTable(new Table(
                name: 'media_usage',
                columns: [new Column('object_id', ColumnType::Id), new Column('media_id', ColumnType::Id)],
                primaryKey: ['object_id', 'media_id'],
                indexes: ['idx_media' => ['media_id', 'object_id']],
                foreignKeys: [new ForeignKey('object_id', 'objects'), new ForeignKey('media_id', 'objects')],
            ));
        }
        if (!$m->tableExists('cap_media_file')) {
            return; // no images
        }
        $found = 0;
        $texts = $m->eachRow('objects', 'id', static function (array $row) use ($m, &$found): void {
            $id = (int) $row['id'];
            $m->sql('DELETE FROM {media_usage} WHERE object_id = :id', ['id' => $id]);
            $data = json_decode((string) $row['data'], true);
            if (!is_array($data) || ($data['format'] ?? null) !== 'html' || !is_string($data['body'] ?? null)) {
                return;
            }
            foreach (self::paths($data['body']) as $path) {
                $media = $m->db()->fetchValue('SELECT object_id FROM {cap_media_file} WHERE file_path = :path', ['path' => $path]);
                if ($media !== null && (int) $media !== $id) {
                    $m->sql('INSERT INTO {media_usage} (object_id, media_id) VALUES (:id, :media)', ['id' => $id, 'media' => (int) $media]);
                    $found++;
                }
            }
        }, "id IN (SELECT object_id FROM {object_capabilities} WHERE capability = 'textual')", batchSize: 200);
        $m->log("{$texts} texts, {$found} uses of images");
    }

    /** @return list<string> */
    private static function paths(string $html): array
    {
        preg_match_all('#\s(?:src|href)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $html, $attributes);
        $paths = [];
        foreach ($attributes[1] as $i => $value) {
            if (preg_match(self::PATH, $value !== '' ? $value : $attributes[2][$i], $m) === 1) {
                $paths[$m[1] . '.' . $m[2]] = true;
            }
        }

        return array_keys($paths);
    }
}
