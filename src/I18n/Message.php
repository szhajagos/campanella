<?php

declare(strict_types=1);

namespace Campanella\I18n;

/**
 * A user-facing message that is not yet translated: a key and its parameters.
 * Code that produces a message (e.g. validation) returns it as such; the text
 * is looked up where it is displayed.
 *
 *     new Message('validation.too_many_values', ['max' => 3])
 */
final readonly class Message implements \Stringable
{
    /** @param array<string, string|int|float> $params */
    public function __construct(
        public string $key,
        public array $params = [],
    ) {
    }

    public function translate(Translator $translator): string
    {
        return $translator->translate($this->key, $this->params);
    }

    /** The key with its parameters, for logs and developer-facing messages (not for display). */
    #[\Override]
    public function __toString(): string
    {
        if ($this->params === []) {
            return $this->key;
        }
        $params = [];
        foreach ($this->params as $name => $value) {
            $params[] = "{$name}={$value}";
        }

        return $this->key . ' (' . implode(', ', $params) . ')';
    }
}
