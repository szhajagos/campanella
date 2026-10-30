<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\Source\VisitorSource;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * The visitor's colour mode switch (since 0.3.6): as the system, light, dark. A form, so it
 * works without JavaScript (POST /color-mode, back to this page); `color-mode.js` changes
 * the mode at once on top of it. The visitor's choice is a cookie, never a session.
 */
#[AsDisplay('color_mode_switch', sources: [VisitorSource::NAME], label: 'display.color_mode_switch')]
final class ColorModeSwitchDisplay extends Display
{
    #[\Override]
    public static function settings(): array
    {
        return [new Field('show_labels', FieldType::Boolean, required: true, default: false, label: 'display.setting.show_labels')];
    }
}
