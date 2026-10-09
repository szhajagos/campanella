<?php

declare(strict_types=1);

namespace Campanella\Site;

use Closure;

/**
 * The `site` variable of templates (`{{ site.name }}`, `{{ site.slogan }}`): the site's
 * settings, read only when a template first uses them (since 0.1.1). Read-only: a
 * template cannot change a setting.
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class SiteValues implements \ArrayAccess, \IteratorAggregate
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /** @param Closure(): array<string, mixed> $load SiteSettings::values() (or a fixed array, e.g. in tests) */
    public function __construct(private readonly Closure $load)
    {
    }

    /** @param array<string, mixed> $values */
    public static function of(array $values): self
    {
        return new self(static fn (): array => $values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->values === null) {
            try {
                $this->values = ($this->load)();
            } catch (\Throwable $e) {
                // The page must still render (e.g. the error page with the database down).
                error_log('Campanella: the site settings could not be read: ' . $e->getMessage());
                $this->values = ['name' => 'Campanella'];
            }
        }

        return $this->values;
    }

    /** Forgets the values read, so the next use reads them again (e.g. after saving them). */
    public function reset(): void
    {
        $this->values = null;
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('The site settings are read-only in templates.');
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('The site settings are read-only in templates.');
    }

    /** @return \ArrayIterator<string, mixed> */
    #[\Override]
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->toArray());
    }
}
