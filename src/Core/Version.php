<?php

declare(strict_types=1);

namespace Campanella\Core;

final class Version
{
    public const string CAMPANELLA = '0.0.7';

    /** The database schema version; incremented with migrations. */
    public const string SCHEMA = '6';

    /**
     * The oldest schema an upgrade can start from (0.0.6). An older installation is
     * upgraded through 0.0.7 first: the code that read its old data is gone. Since 0.1.0.
     */
    public const string MIN_UPGRADE_SCHEMA = '6';
}
