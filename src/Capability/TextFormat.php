<?php

declare(strict_types=1);

namespace Campanella\Capability;

enum TextFormat: string
{
    /** Sima szöveg: megjelenítéskor escape-elve, bekezdésekre bontva. */
    case Plain = 'plain';

    /**
     * HTML. A 0.0.1-ben csak megbízható forrásból (seed, CLI) kerülhet be.
     * Mielőtt a szerkesztők WYSIWYG-gel írhatnak, HTML-szűrő kell ide.
     */
    case Html = 'html';
}
