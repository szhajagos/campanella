<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Capability\MediaFile;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\I18n\Message;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/** An image, with its smaller copies (srcset), at a width of its own or the column's, with its title as a caption (since 0.3.3). */
#[AsDisplay('image', requires: [MediaFile::class], label: 'display.image')]
final class ImageDisplay extends Display
{
    public const int MIN_WIDTH = 16;
    public const int MAX_WIDTH = 4000;

    #[\Override]
    public static function settings(): array
    {
        return [
            new Field('width', FieldType::Integer, label: 'display.setting.width'),
            new Field('caption', FieldType::Boolean, required: true, default: false, label: 'display.setting.caption'),
        ];
    }

    #[\Override]
    public function validate(array $settings): array
    {
        $width = $settings['width'] ?? null;

        return $width !== null && ($width < self::MIN_WIDTH || $width > self::MAX_WIDTH)
            ? ['width' => new Message('display.setting.width_range', ['min' => self::MIN_WIDTH, 'max' => self::MAX_WIDTH])]
            : [];
    }
}
