<?php

declare(strict_types=1);

namespace Campanella\Capability;

enum TextFormat: string
{
    /** Plain text: escaped when rendered, split into paragraphs. */
    case Plain = 'plain';

    /**
     * HTML. Filtered with the allowlist on every save (Campanella\Html\HtmlSanitizer,
     * since 0.0.5), so it is rendered as it is stored.
     */
    case Html = 'html';
}
