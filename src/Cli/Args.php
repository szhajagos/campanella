<?php

declare(strict_types=1);

namespace Campanella\Cli;

/**
 * Simple argument parsing: positional values and --key=value
 * (or --flag) options.
 */
final readonly class Args
{
    /**
     * @param list<string> $positional
     * @param array<string, string|true> $options
     */
    private function __construct(
        public array $positional,
        public array $options,
    ) {
    }

    /** @param list<string> $args */
    public static function parse(array $args): self
    {
        $positional = [];
        $options = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                $options[(string) $key] = $value;
            } else {
                $positional[] = $arg;
            }
        }

        return new self($positional, $options);
    }

    public function argument(int $index): ?string
    {
        return $this->positional[$index] ?? null;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function flag(string $name): bool
    {
        return isset($this->options[$name]);
    }
}
