<?php

declare(strict_types=1);

namespace Campanella\Http;

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Az oldal nem található.'): self
    {
        return new self(404, $message);
    }
}
