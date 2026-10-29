<?php

declare(strict_types=1);

namespace Campanella\Token;

use Campanella\Site\SiteValues;

/** `[site:name]`, `[site:slogan]`, `[site:url]`: the site's settings (since 0.3.4). */
final class SiteTokens implements TokenProvider
{
    public function __construct(private readonly ?SiteValues $site = null)
    {
    }

    #[\Override]
    public function prefix(): string
    {
        return 'site';
    }

    #[\Override]
    public function tokens(): array
    {
        return ['name' => 'token.site.name', 'slogan' => 'token.site.slogan', 'url' => 'token.site.url'];
    }

    #[\Override]
    public function value(string $name): ?string
    {
        if (!in_array($name, ['name', 'slogan', 'url'], true)) {
            return null;
        }
        $value = $this->site?->offsetGet($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
