<?php

declare(strict_types=1);

namespace Campanella\Net;

use Campanella\I18n\Message;

/**
 * A fetch refused or failed (since 0.4.9); `reason` says why, for the admin (`fetch.*`).
 */
final class FetchException extends \RuntimeException
{
    public function __construct(public readonly Message $reason, string $detail = '')
    {
        parent::__construct($reason->key . ($detail !== '' ? ': ' . $detail : ''));
    }
}
