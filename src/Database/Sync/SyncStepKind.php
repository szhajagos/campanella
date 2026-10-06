<?php

declare(strict_types=1);

namespace Campanella\Database\Sync;

/** What a step of the SchemaSync does (or why it cannot). */
enum SyncStepKind: string
{
    case CreateTable = 'create_table';
    case AddColumn = 'add_column';
    case AddIndex = 'add_index';
    case AddCapability = 'add_capability';
    case PruneCapability = 'prune_capability';
    /** Cannot be done automatically: a migration is needed. */
    case Blocked = 'blocked';
    /** Only reported (e.g. data kept for a capability removed from a Blueprint). */
    case Note = 'note';
}
