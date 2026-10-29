<?php

declare(strict_types=1);

namespace Campanella\Token;

use DateTimeImmutable;
use DateTimeZone;

/** `[date:year]`: the current year, in the site's time zone (since 0.3.4). */
final class DateTokens implements TokenProvider
{
    public function __construct(private readonly DateTimeZone $timezone = new DateTimeZone('UTC'))
    {
    }

    #[\Override]
    public function prefix(): string
    {
        return 'date';
    }

    #[\Override]
    public function tokens(): array
    {
        return ['year' => 'token.date.year'];
    }

    #[\Override]
    public function value(string $name): ?string
    {
        return $name === 'year' ? (new DateTimeImmutable('now', $this->timezone))->format('Y') : null;
    }
}
