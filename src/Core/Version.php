<?php

declare(strict_types=1);

namespace Campanella\Core;

final class Version
{
    public const string CAMPANELLA = '0.1.3';

    /**
     * The database schema version; incremented with migrations and new core tables
     * (7: the settings table, 0.1.1; 8: the media_usage table and the images'
     * `variants` column, 0.1.2; 9: the mail_log table, 0.1.3; 10: the sessions table, 11: the password_resets table, 0.1.4).
     */
    public const string SCHEMA = '11';

    /**
     * The oldest schema an upgrade can start from (0.0.6). An older installation is
     * upgraded through 0.0.7 first: the code that read its old data is gone. Since 0.1.0.
     */
    public const string MIN_UPGRADE_SCHEMA = '6';
}
