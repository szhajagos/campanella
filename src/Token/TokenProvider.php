<?php

declare(strict_types=1);

namespace Campanella\Token;

/**
 * A group of tokens (since 0.3.4): `[site:name]` is the `name` token of the `site`
 * provider. Registered in config/app.php (`tokens.providers`); a module adds its own.
 */
interface TokenProvider
{
    /** The tokens' first part, lowercase: `site`. */
    public function prefix(): string;

    /**
     * Its tokens, for the list shown under the editor.
     *
     * @return array<string, string> name => label (a message key or text)
     */
    public function tokens(): array;

    /** A token's value as text (it is escaped where it is put); null for one it does not know. */
    public function value(string $name): ?string;
}
