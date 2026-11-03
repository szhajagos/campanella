<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\Token\TokenProvider;

/** Tokens of a module (a test): [hirlevel:feliratkozok]. */
final class Tokenek implements TokenProvider
{
    public function prefix(): string
    {
        return 'hirlevel';
    }

    public function tokens(): array
    {
        return ['feliratkozok' => 'hirlevel.token'];
    }

    public function value(string $name): ?string
    {
        return $name === 'feliratkozok' ? '42' : null;
    }
}
