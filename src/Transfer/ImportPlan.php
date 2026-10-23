<?php

declare(strict_types=1);

namespace Campanella\Transfer;

use Campanella\Database\Sync\SchemaSync;
use Campanella\I18n\Message;

/**
 * What an import of the content model would do (since 0.2.6): a line for each
 * Blueprint and list of the file and of the site, the database's changes, whether
 * a backup comes first. Only a plan without errors can be applied
 * (ModelImport::apply()), and only all of it.
 */
final readonly class ImportPlan
{
    /**
     * @param list<ImportItem> $items
     * @param list<Message> $errors Errors of the whole import (e.g. the database needs an upgrade)
     * @param list<Message> $database The database's changes, in order
     * @param array<string, array<string, mixed>> $blueprints The Blueprint definitions to save
     * @param array<string, array<string, mixed>> $lists The list definitions to save
     * @param string $fingerprint Of the site's model and the changes: an import confirmed later applies only if it is the same
     * @internal $sync The SchemaSync with the new definitions
     */
    public function __construct(
        public array $items,
        public array $errors,
        public array $database,
        public bool $backup,
        public array $blueprints,
        public array $lists,
        public string $fingerprint,
        public ?SchemaSync $sync = null,
    ) {
    }

    public function hasErrors(): bool
    {
        if ($this->errors !== []) {
            return true;
        }
        foreach ($this->items as $item) {
            if ($item->action === ImportItem::ERROR) {
                return true;
            }
        }

        return false;
    }

    /** Whether applying it would change anything. */
    public function hasChanges(): bool
    {
        return $this->blueprints !== [] || $this->lists !== [];
    }

    /**
     * @return array<string, int> How many items per action
     */
    public function counts(): array
    {
        $counts = array_fill_keys([ImportItem::CREATE, ImportItem::UPDATE, ImportItem::UNCHANGED, ImportItem::KEPT, ImportItem::ERROR], 0);
        foreach ($this->items as $item) {
            $counts[$item->action]++;
        }

        return $counts;
    }
}
