<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayRenderer;
use Campanella\I18n\Message;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * A list's objects as short cards (each with the teaser display), one under the other or
 * in 2–4 columns, with a link to the list's page (since 0.3.3).
 */
#[AsDisplay('cards', many: true, label: 'display.cards')]
final class CardsDisplay extends Display
{
    public const int MAX_COLUMNS = 4;

    #[\Override]
    public static function settings(): array
    {
        return [
            new Field('count', FieldType::Integer, required: true, default: 5, label: 'display.setting.count'),
            new Field('columns', FieldType::Integer, required: true, default: 1, label: 'display.setting.columns'),
            new Field('show_more', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_more'),
            // Since 0.3.5: the list's title above the cards.
            new Field('show_title', FieldType::Boolean, required: true, default: false, label: 'display.setting.show_list_title'),
        ];
    }

    #[\Override]
    public function validate(array $settings): array
    {
        $columns = $settings['columns'] ?? 1;

        return self::countProblem($settings) + (is_int($columns) && ($columns < 1 || $columns > self::MAX_COLUMNS)
            ? ['columns' => new Message('display.setting.columns_range', ['max' => self::MAX_COLUMNS])]
            : []);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, Message>
     */
    public static function countProblem(array $settings): array
    {
        $count = $settings['count'] ?? 1;

        return is_int($count) && ($count < 1 || $count > DisplayRenderer::MAX_ITEMS)
            ? ['count' => new Message('display.setting.count_range', ['max' => DisplayRenderer::MAX_ITEMS])]
            : [];
    }
}
