<?php

declare(strict_types=1);

namespace Campanella\Import;

/** Rolls back a trial's checking save (since 0.3.1); never leaves the Importer. Internal. */
final class TrialRollback extends \RuntimeException
{
}
