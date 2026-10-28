<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Capability\Titled;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * An object in a box: its title and text (a snippet: opening hours, a contact) in a
 * frame of one of the Bootstrap colours, until the colour schemes of 0.3.6 (since 0.3.3).
 */
#[AsDisplay('info_box', requires: [Titled::class], label: 'display.info_box')]
final class InfoBoxDisplay extends Display
{
    #[\Override]
    public static function settings(): array
    {
        return [
            new Field('show_title', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_title'),
            new Field('style', FieldType::Choice, required: true, default: 'light', label: 'display.setting.colour', choices: [
                'light' => 'display.choice.light',
                'primary' => 'display.choice.primary',
                'info' => 'display.choice.info',
                'warning' => 'display.choice.warning',
            ]),
        ];
    }
}
