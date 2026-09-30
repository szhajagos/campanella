<?php

declare(strict_types=1);

namespace Campanella\Capability;

enum TextFormat: string
{
    /** Plain text: escaped when rendered, split into paragraphs. */
    case Plain = 'plain';

    /**
     * HTML. In 0.0.1 it may only come from a trusted source (seed, CLI).
     * Before editors can write with WYSIWYG, an HTML filter is needed here.
     */
    case Html = 'html';
}
