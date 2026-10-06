<?php

declare(strict_types=1);

namespace Campanella\Database\Migration\Core;

use Campanella\Database\Migration\Migration;
use Campanella\Database\Migration\MigrationContext;

/**
 * 0.0.6: the users' roles become a multi-valued field. Until now they were a
 * StringList, a JSON list in the `roles` column of `cap_authenticatable`; from
 * now on one row per role in `field_values`, so users can be queried by role.
 *
 * Repeatable: the values of each user are written again (the old rows of the
 * field first deleted), and the column is dropped only after all are copied.
 */
final class RolesMultiValue implements Migration
{
    public const string TABLE = 'cap_authenticatable';
    public const string COLUMN = 'roles';

    #[\Override]
    public function id(): string
    {
        return 'core:0006_roles_multi_value';
    }

    #[\Override]
    public function description(): string
    {
        return 'The users\' roles become a multi-valued field (field_values)';
    }

    #[\Override]
    public function up(MigrationContext $m): void
    {
        if (!$m->tableExists(self::TABLE) || !$m->columnExists(self::TABLE, self::COLUMN)) {
            return; // a fresh installation, or done already
        }
        $users = $m->eachRow(self::TABLE, 'object_id', static function (array $row) use ($m): void {
            $id = (int) $row['object_id'];
            $m->sql('DELETE FROM {field_values} WHERE object_id = :id AND field = :field', ['id' => $id, 'field' => self::COLUMN]);
            foreach (self::decode($row[self::COLUMN] ?? null) as $delta => $role) {
                $m->sql(
                    'INSERT INTO {field_values} (object_id, field, delta, value_string) VALUES (:id, :field, :delta, :value)',
                    ['id' => $id, 'field' => self::COLUMN, 'delta' => $delta, 'value' => $role],
                );
            }
        });
        $m->log("{$users} users");
        $m->dropColumn(self::TABLE, self::COLUMN);
    }

    /**
     * A stored StringList: a JSON list (or, from very early versions, a comma-separated
     * text); trimmed, without empty and duplicate items.
     *
     * @return list<string>
     */
    public static function decode(mixed $stored): array
    {
        if (!is_string($stored) || trim($stored) === '') {
            return [];
        }
        $decoded = json_validate($stored) ? json_decode($stored, true) : explode(',', $stored);
        $roles = [];
        foreach (is_array($decoded) ? $decoded : [] as $role) {
            $role = is_scalar($role) ? trim((string) $role) : '';
            if ($role !== '' && !in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }
}
