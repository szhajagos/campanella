<?php

declare(strict_types=1);

namespace Campanella\Webform;

use Campanella\I18n\Message;
use Campanella\Model\CampanellaObject;

/**
 * What became of a contact form's sending (since 0.1.5). `SENT` and `IGNORED`
 * (a bot, stopped by a guard) look the same to the sender.
 */
final readonly class ContactResult
{
    public const string SENT = 'sent';
    public const string IGNORED = 'ignored';
    public const string INVALID = 'invalid';
    public const string TOO_FAST = 'too_fast';
    public const string TOO_MANY = 'too_many';
    public const string EXPIRED = 'expired';

    /**
     * @param array<string, string> $values What was entered (for showing the form again)
     * @param array<string, Message|string> $errors Field => message
     */
    public function __construct(
        public string $status,
        public array $values = [],
        public array $errors = [],
        public ?Message $message = null,
        public ?CampanellaObject $submission = null,
    ) {
    }

    /** Whether the sender is told "sent" (also when a bot was ignored). */
    public function looksSent(): bool
    {
        return $this->status === self::SENT || $this->status === self::IGNORED;
    }
}
