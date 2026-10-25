<?php

declare(strict_types=1);

namespace Campanella\Import;

use Campanella\I18n\Message;
use RuntimeException;

/**
 * Something an import cannot go on with (since 0.3.1): a file that cannot be read, or
 * an item's value that does not fit its field. Carries a translatable reason.
 */
final class ImportException extends RuntimeException
{
    public function __construct(public readonly Message $reason)
    {
        parent::__construct($reason->key);
    }
}
