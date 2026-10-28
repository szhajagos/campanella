<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Capability\Routable;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/** A link to an object's page, with its title or a text of its own (since 0.3.3). */
#[AsDisplay('link', requires: [Routable::class], label: 'display.link')]
final class LinkDisplay extends Display
{
    #[\Override]
    public static function settings(): array
    {
        return [new Field('text', FieldType::String, length: 100, label: 'display.setting.link_text')];
    }
}
