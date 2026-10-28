<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\Source\SiteSource;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/** The site's name, a link to the front page, with its slogan (since 0.3.3; the logo comes with the appearance). */
#[AsDisplay('branding', sources: [SiteSource::NAME], label: 'display.branding')]
final class BrandingDisplay extends Display
{
    #[\Override]
    public static function settings(): array
    {
        return [new Field('show_slogan', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_slogan')];
    }
}
