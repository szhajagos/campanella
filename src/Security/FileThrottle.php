<?php

declare(strict_types=1);

namespace Campanella\Security;

/**
 * Limits attempts per key within a time window, like Throttle, but in a file:
 * for where the database cannot be used yet (the installer, before the tables
 * exist). Since 0.1.0.
 *
 * One JSON file, locked while it is read and written; only SHA-256 hashes of the
 * keys are stored. If the file cannot be written, attempts are not limited (the
 * caller's own rules, e.g. a long key, still apply).
 */
final class FileThrottle
{
    public function __construct(private readonly string $file)
    {
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return ($this->read()[hash('sha256', $key)]['hits'] ?? 0) >= $maxAttempts;
    }

    /** Seconds until the key's window ends (0 if it has none). */
    public function availableIn(string $key): int
    {
        return max(0, ($this->read()[hash('sha256', $key)]['until'] ?? 0) - time());
    }

    /** Records a failed attempt; returns the number of attempts in the window. */
    public function hit(string $key, int $decaySeconds): int
    {
        $hash = hash('sha256', $key);
        $hits = 1;
        $this->update(static function (array $entries) use ($hash, $decaySeconds, &$hits): array {
            $entry = $entries[$hash] ?? null;
            $hits = $entry === null ? 1 : $entry['hits'] + 1;
            $entries[$hash] = ['hits' => $hits, 'until' => $entry['until'] ?? time() + $decaySeconds];

            return $entries;
        });

        return $hits;
    }

    public function clear(string $key): void
    {
        $hash = hash('sha256', $key);
        $this->update(static function (array $entries) use ($hash): array {
            unset($entries[$hash]);

            return $entries;
        });
    }

    /** @return array<string, array{hits: int, until: int}> The entries still in their window */
    private function read(): array
    {
        $json = is_file($this->file) ? @file_get_contents($this->file) : false;

        return $json === false ? [] : self::current($json);
    }

    /** @param \Closure(array<string, array{hits: int, until: int}>): array<string, array{hits: int, until: int}> $change */
    private function update(\Closure $change): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return;
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $entries = $change(self::current((string) stream_get_contents($handle)));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($entries, JSON_THROW_ON_ERROR));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, array{hits: int, until: int}> */
    private static function current(string $json): array
    {
        $data = json_validate($json) ? json_decode($json, true) : [];
        $entries = [];
        foreach (is_array($data) ? $data : [] as $hash => $entry) {
            if (is_array($entry) && is_int($entry['hits'] ?? null) && is_int($entry['until'] ?? null) && $entry['until'] > time()) {
                $entries[(string) $hash] = ['hits' => $entry['hits'], 'until' => $entry['until']];
            }
        }

        return $entries;
    }
}
