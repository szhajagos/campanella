<?php

declare(strict_types=1);

namespace Campanella\Mail;

/** What happened to an e-mail (Mailer::send(); since 0.1.3). */
final readonly class MailResult
{
    public const string SENT = 'sent';
    public const string FAILED = 'failed';
    /** Sending is not set up (mail.dsn, mail.from): nothing was tried. */
    public const string NOT_CONFIGURED = 'not_configured';

    public function __construct(
        public string $status,
        public ?string $error = null,
    ) {
    }

    public function sent(): bool
    {
        return $this->status === self::SENT;
    }
}
