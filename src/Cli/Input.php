<?php

declare(strict_types=1);

namespace Campanella\Cli;

/**
 * Parancssori bemenet: kérdés és rejtett (jelszó) bekérés.
 *
 * Ha a bemenet nem terminál (pl. `echo … | php bin/campanella …`), a
 * sorokat egyszerűen beolvassa, így szkriptből is használható.
 */
final class Input
{
    /** @param resource $stream */
    public function __construct(private $stream = STDIN)
    {
    }

    public function ask(string $label): string
    {
        fwrite(STDOUT, $label);

        return $this->readLine();
    }

    public function secret(string $label): string
    {
        fwrite(STDOUT, $label);
        $interactive = $this->isInteractive() && DIRECTORY_SEPARATOR === '/';
        if ($interactive) {
            shell_exec('stty -echo');
        }
        try {
            return $this->readLine();
        } finally {
            if ($interactive) {
                shell_exec('stty echo');
                fwrite(STDOUT, PHP_EOL);
            }
        }
    }

    public function isInteractive(): bool
    {
        return function_exists('stream_isatty') && stream_isatty($this->stream);
    }

    private function readLine(): string
    {
        $line = fgets($this->stream);

        return $line === false ? '' : rtrim($line, "\r\n");
    }
}
