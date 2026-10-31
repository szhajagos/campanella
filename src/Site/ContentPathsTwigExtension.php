<?php

declare(strict_types=1);

namespace Campanella\Site;

use Closure;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `content_path('news')` in templates (since 0.3.7): a core content path in the site's
 * language (ContentPaths: `/news`, `/categories`, `/about-us` on an English site), e.g.
 * `{{ url(content_path('news')) }}`.
 */
final class ContentPathsTwigExtension extends AbstractExtension
{
    /** @param Closure(): ContentPaths $paths */
    public function __construct(private readonly Closure $paths)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('content_path', fn (string $name): string => ($this->paths)()->get($name))];
    }
}
