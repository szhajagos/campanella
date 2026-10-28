<?php

declare(strict_types=1);

namespace Campanella\Core;

final class Version
{
    public const string CAMPANELLA = '0.3.3';

    /**
     * The database schema version; incremented with migrations and new core tables
     * (7: the settings table, 0.1.1; 8: the media_usage table and the images'
     * `variants` column, 0.1.2; 9: the mail_log table, 0.1.3; 10: the sessions table, 11: the password_resets table, 0.1.4; 12: the submitted capability's table, 0.1.5;
     * 0.2.0 adds none; 13: the value_decimal column of field_values, 0.2.2; 14: the
     * blueprints table, 0.2.3; 0.2.4 adds none; 15: the lists table, 0.2.5; 0.2.6 adds none; 16: the search_index and redirects tables, 0.2.7; 0.2.8, 0.2.9 and 0.3.0 add none; 17: the importers, import_runs and import_items tables, 0.3.1; 18: the downloadable and restricted capabilities' tables, 0.3.2; 19: the component capability's table, 0.3.3).
     */
    public const string SCHEMA = '19';

    /**
     * The oldest schema an upgrade can start from (0.0.6). An older installation is
     * upgraded through 0.0.7 first: the code that read its old data is gone. Since 0.1.0.
     */
    public const string MIN_UPGRADE_SCHEMA = '6';
}
