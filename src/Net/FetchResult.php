<?php

declare(strict_types=1);

namespace Campanella\Net;

/**
 * What a fetch gave (since 0.4.9): the body (empty if not modified), the address it came from
 * after the redirects, and what a later fetch may send back to ask whether it changed.
 */
final readonly class FetchResult
{
    public function __construct(
        public string $url,
        public string $body,
        public ?string $contentType = null,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public bool $notModified = false,
    ) {
    }
}
