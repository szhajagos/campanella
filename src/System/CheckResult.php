<?php

declare(strict_types=1);

namespace Campanella\System;

use Campanella\I18n\Message;

/**
 * One line of the system check: what was checked, the value found, the
 * verdict, and optionally what to do about it.
 *
 * `$group`, `$label` and `$value` are shown through the translator: a key
 * (`admin.system.php`, `admin.system.on`) is translated, a plain text (an
 * extension name, a version, a size) passes through unchanged.
 */
final readonly class CheckResult
{
    public function __construct(
        public string $group,
        public string $label,
        public CheckStatus $status,
        public string $value = '',
        public ?Message $hint = null,
    ) {
    }
}
