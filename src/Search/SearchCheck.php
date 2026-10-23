<?php

declare(strict_types=1);

namespace Campanella\Search;

use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\System\CheckResult;
use Campanella\System\CheckStatus;
use Closure;

/**
 * The system check's line about the search (since 0.2.7): how many objects are in
 * its index, and whether some are missing (e.g. saved before the upgrade to 0.2.7),
 * with a button that indexes them.
 */
final class SearchCheck
{
    /** @return Closure(?Request): list<CheckResult> For SystemCheck::add() */
    public static function checks(SearchIndexer $indexer): Closure
    {
        return static function (?Request $request) use ($indexer): array {
            try {
                $missing = $indexer->missing();
                $indexed = $indexer->indexed();
            } catch (\PDOException) {
                return []; // the database cannot be reached: its own line says so
            }
            if ($missing > 0) {
                return [new CheckResult('admin.system.group.site', 'admin.system.search', CheckStatus::Warning, (string) $indexed,
                    new Message('admin.system.search_missing', ['count' => $missing]),
                    'system/search-index', 'admin.system.search_build')];
            }

            return [new CheckResult('admin.system.group.site', 'admin.system.search', CheckStatus::Ok, (string) $indexed)];
        };
    }
}
