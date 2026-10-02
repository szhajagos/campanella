<?php

declare(strict_types=1);

namespace Campanella\Cli;

final class Output
{
    /**
     * @param resource $stream Normal output
     * @param resource $errors Error messages (since 0.0.5; e.g. the same stream in tests)
     */
    public function __construct(private $stream = STDOUT, private $errors = STDERR)
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
        fwrite($this->errors, '✘ ' . $text . PHP_EOL);
    }
}
