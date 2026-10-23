<?php

declare(strict_types=1);

namespace Campanella\Transfer;

use Campanella\I18n\Message;

/**
 * One line of an import's plan (since 0.2.6): a Blueprint or a list, and what the
 * import would do with it, with the changes, the warnings, or why it cannot.
 */
final readonly class ImportItem
{
    public const string BLUEPRINT = 'blueprint';
    public const string LIST = 'list';

    /** New on this site. */
    public const string CREATE = 'create';
    /** On this site, changed by the file. */
    public const string UPDATE = 'update';
    /** On this site, the same as in the file. */
    public const string UNCHANGED = 'unchanged';
    /** On this site, not in the file: it stays as it is. */
    public const string KEPT = 'kept';
    /** It cannot be imported; nothing is, until this is solved. */
    public const string ERROR = 'error';

    /**
     * @param list<Message> $changes
     * @param list<Message> $warnings
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $label,
        public string $action,
        public array $changes = [],
        public array $warnings = [],
        public ?Message $error = null,
    ) {
    }
}
