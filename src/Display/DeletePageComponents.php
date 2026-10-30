<?php

declare(strict_types=1);

namespace Campanella\Display;

use Campanella\Capability\Composed;
use Campanella\Database\Connection;
use Campanella\Model\CampanellaObject;
use Campanella\Model\ObjectRepository;
use Campanella\Service\ObjectListener;

/**
 * Deletes a deleted page's own components with it (since 0.3.5): they were its content,
 * and without it they would show nowhere. (Their `component_page` would still name it, so
 * they never become the whole site's.)
 */
final class DeletePageComponents implements ObjectListener
{
    /** The table of the Component capability's fields. */
    private const string TABLE = 'cap_component';

    public function __construct(
        private readonly Connection $db,
        private readonly ObjectRepository $repository,
    ) {
    }

    #[\Override]
    public function afterDelete(CampanellaObject $object): void
    {
        if (!$object->has(Composed::class) || $object->id() === null || !$this->db->tableExists(self::TABLE)) {
            return;
        }
        $ids = $this->db->fetchColumn('SELECT object_id FROM {' . self::TABLE . '} WHERE component_page = :page', ['page' => (int) $object->id()]);
        foreach ($ids as $id) {
            $component = $this->repository->find((int) $id);
            if ($component !== null) {
                $this->repository->delete($component);
            }
        }
    }
}
