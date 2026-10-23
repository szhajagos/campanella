<?php

declare(strict_types=1);

namespace Campanella\Transfer;

use Campanella\Database\Connection;
use Campanella\Database\Schema\SchemaReader;

/**
 * The objects of a list's relation condition in the content model's file (since
 * 0.2.6): an ID means another object on another site, so the file names each by
 * its Blueprint and what it has of a machine name, a path and a title; the
 * import finds them by these, in this order (a title only if one object of the
 * Blueprint has it).
 */
final class ObjectReferences
{
    /** @var array<string, bool> */
    private array $tables = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The references of the objects, in their order; an object that is not there is left out.
     *
     * @param list<int> $ids
     * @return list<array{blueprint: string, machine_name?: string, path?: string, title?: string}>
     */
    public function describe(array $ids): array
    {
        $references = [];
        foreach ($ids as $id) {
            $blueprint = $this->db->fetchValue('SELECT blueprint FROM {objects} WHERE id = :id', ['id' => $id]);
            if ($blueprint === null) {
                continue;
            }
            $reference = ['blueprint' => (string) $blueprint];
            foreach (['machine_name' => 'cap_keyed', 'path' => 'cap_routable', 'title' => 'cap_titled'] as $column => $table) {
                if ($this->hasTable($table)) {
                    $value = $this->db->fetchValue(sprintf('SELECT %s FROM {%s} WHERE object_id = :id', $column, $table), ['id' => $id]);
                    if ($value !== null && (string) $value !== '') {
                        $reference[$column] = (string) $value;
                    }
                }
            }
            $references[] = $reference;
        }

        return $references;
    }

    /**
     * The ID of the object a reference names, among the objects of the given
     * Blueprints (all if none are given); null if there is none, or the reference
     * is not valid.
     *
     * @param list<string> $blueprints
     */
    public function resolve(mixed $reference, array $blueprints): ?int
    {
        if (!is_array($reference) || !is_string($reference['blueprint'] ?? null)) {
            return null;
        }
        $blueprint = $reference['blueprint'];
        if ($blueprints !== [] && !in_array($blueprint, $blueprints, true)) {
            return null;
        }
        foreach (['machine_name' => 'cap_keyed', 'path' => 'cap_routable', 'title' => 'cap_titled'] as $column => $table) {
            $value = $reference[$column] ?? null;
            if (!is_string($value) || $value === '' || mb_strlen($value, 'UTF-8') > 255 || !$this->hasTable($table)) {
                continue;
            }
            $ids = $this->db->fetchColumn(
                sprintf('SELECT o.id FROM {objects} o JOIN {%s} c ON c.object_id = o.id WHERE o.blueprint = :b AND c.%s = :v ORDER BY o.id LIMIT 2', $table, $column),
                ['b' => $blueprint, 'v' => $value],
            );
            if (count($ids) === 1) {
                return (int) $ids[0];
            }
        }

        return null;
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= (new SchemaReader($this->db))->tableExists($table);
    }
}
