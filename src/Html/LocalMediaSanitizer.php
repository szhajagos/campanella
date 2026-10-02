<?php

declare(strict_types=1);

namespace Campanella\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps an image address only if it points to this site: a relative address
 * such as /media/2026/10/photo.jpg. A protocol-relative address (//host/…, and
 * /\host/…, which browsers read the same way) points to another server, so it
 * is removed. Used when external images are not allowed (the default).
 */
final class LocalMediaSanitizer implements AttributeSanitizerInterface
{
    #[\Override]
    public function getSupportedElements(): array
    {
        return ['img'];
    }

    #[\Override]
    public function getSupportedAttributes(): array
    {
        return ['src'];
    }

    #[\Override]
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        // Browsers ignore leading whitespace and control characters, and treat \ as /.
        $normalized = str_replace('\\', '/', ltrim($value, "\x00..\x20"));

        return str_starts_with($normalized, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized) === 1 ? null : $value;
    }
}
