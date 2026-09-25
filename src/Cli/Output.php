<?php

declare(strict_types=1);

namespace Campanella\Cli;

final class Output
{
    /** @param resource $stream */
    public function __construct(private $stream = STDOUT)
    {
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . PHP_EOL);
    }

    public function success(string $text): void
    {
        $this->line('✔ ' . $text);
    }

    public function error(string $text): void
    {
        fwrite(STDERR, '✘ ' . $text . PHP_EOL);
    }
}
