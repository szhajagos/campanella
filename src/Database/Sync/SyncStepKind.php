<?php

declare(strict_types=1);

namespace Campanella\Database\Sync;

/** What a step of the SchemaSync does (or why it cannot). */
enum SyncStepKind: string
{
    case CreateTable = 'create_table';
    case AddColumn = 'add_column';
    case AddIndex = 'add_index';
    /** A column of a Blueprint's own table changed: a widened type, required or not (since 0.2.4). */
    case ChangeColumn = 'change_column';
    case AddCapability = 'add_capability';
    case PruneCapability = 'prune_capability';
    /** A Blueprint's own field became queryable, or stopped being: its values are moved (since 0.2.2). */
    case MoveData = 'move_data';
    /** Cannot be done automatically: a migration is needed. */
    case Blocked = 'blocked';
    /** Only reported (e.g. data kept for a capability removed from a Blueprint). */
    case Note = 'note';
}
