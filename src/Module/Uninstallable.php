<?php

declare(strict_types=1);

namespace Campanella\Module;

use Campanella\Database\Migration\MigrationContext;

/**
 * A module whose data can be removed for good (since 0.4.7): its entry point implements this.
 * uninstall() drops its tables and deletes what else it wrote (its settings), through the
 * MigrationContext, as a migration would. It runs only while the module is off, after a
 * backup, when an administrator asks for it (*System → Modules*, `module:uninstall`); then
 * its migrations count as not run, so turning it on again makes its tables anew.
 */
interface Uninstallable
{
    public function uninstall(MigrationContext $m): void;
}
