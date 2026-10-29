<?php

declare(strict_types=1);

namespace Campanella\Token;

use Campanella\Core\Version;

/** `[campanella:version]`: the version of Campanella (since 0.3.4). */
final class CampanellaTokens implements TokenProvider
{
    #[\Override]
    public function prefix(): string
    {
        return 'campanella';
    }

    #[\Override]
    public function tokens(): array
    {
        return ['version' => 'token.campanella.version'];
    }

    #[\Override]
    public function value(string $name): ?string
    {
        return $name === 'version' ? Version::CAMPANELLA : null;
    }
}
