<?php

declare(strict_types=1);

namespace Campanella\Cli;

/**
 * Reads a sample content file's values by their place (`articles.2.lead`), each checked
 * for its kind; a wrong one is an \InvalidArgumentException naming its place (since 0.3.7,
 * for SeedContent).
 */
final readonly class SeedReader
{
    /** The longest text of a sample (a body). */
    public const int MAX_TEXT = 4000;

    /** @param array<mixed> $data */
    public function __construct(private string $locale, private array $data)
    {
    }

    public function problem(string $at, string $what): \InvalidArgumentException
    {
        return new \InvalidArgumentException("Sample content {$this->locale}: {$at} {$what}.");
    }

    public function text(string $at): string
    {
        $value = $this->value($at);
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > self::MAX_TEXT) {
            throw $this->problem($at, 'must be a text of at most ' . self::MAX_TEXT . ' characters');
        }

        return $value;
    }

    /** A machine name: small letters, digits, _. */
    public function name(string $at): string
    {
        $value = $this->value($at);
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
            throw $this->problem($at, 'must be a machine name (small letters, digits, _)');
        }

        return $value;
    }

    /** A key of a map, as a machine name. */
    public function key(string $at, int|string $key): string
    {
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1) {
            throw $this->problem($at, 'has a key that is not a machine name (' . $key . ')');
        }

        return $key;
    }

    public function int(string $at): int
    {
        $value = $this->value($at);
        if (!is_int($value)) {
            throw $this->problem($at, 'must be a whole number');
        }

        return $value;
    }

    /** A whole number written as a text ("3500"), as a decimal field takes it. */
    public function number(string $at): string
    {
        $value = $this->value($at);
        if (!is_string($value) || preg_match('/^\d{1,9}$/D', $value) !== 1) {
            throw $this->problem($at, 'must be a whole number written as a text, e.g. "1500"');
        }

        return $value;
    }

    /** A time of day: 18:30. */
    public function time(string $at): string
    {
        $value = $this->value($at);
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value) !== 1) {
            throw $this->problem($at, 'must be a time such as 18:30');
        }

        return $value;
    }

    /** A time relative to now, as PHP reads it: "-5 days", "+2 hours". */
    public function when(string $at): string
    {
        $value = $this->value($at);
        if (!is_string($value) || preg_match('/^[+-]\d{1,3} (minute|hour|day|week|month)s?$/D', $value) !== 1) {
            throw $this->problem($at, 'must be a time from now, e.g. "-5 days"');
        }

        return $value;
    }

    /** @return array{title: string, body: string} */
    public function titled(string $at): array
    {
        return ['title' => $this->text($at . '.title'), 'body' => $this->text($at . '.body')];
    }

    /** @return list<string> */
    public function strings(string $at): array
    {
        $value = $this->value($at);
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->problem($at, 'must be a list');
        }

        return array_map(fn (int $i): string => $this->name($at . '.' . $i), array_keys($value));
    }

    /** @return array<string, mixed> */
    public function map(string $at): array
    {
        $value = $this->value($at);
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->problem($at, 'must be an object');
        }

        return $value;
    }

    /** @return list<mixed> */
    public function items(string $at): array
    {
        $value = $this->value($at);
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw $this->problem($at, 'must be a list of at least one');
        }

        return $value;
    }

    private function value(string $at): mixed
    {
        $value = $this->data;
        foreach (explode('.', $at) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                throw $this->problem($at, 'is missing');
            }
            $value = $value[$part];
        }

        return $value;
    }
}
