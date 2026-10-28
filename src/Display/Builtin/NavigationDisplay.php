<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Capability\Keyed;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Subject;
use Campanella\I18n\Message;
use Campanella\Menu\MenuBuilder;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * A menu as navigation (since 0.3.3): its items the visitor may see, as a bar with
 * dropdowns (as the default theme's header) or as a list, at most `depth` levels deep.
 * The template gets `entries` (MenuEntry); a menu with no items shows nothing.
 */
#[AsDisplay('navigation', requires: [Keyed::class], label: 'display.navigation')]
final class NavigationDisplay extends Display
{
    public const int MAX_DEPTH = 3;

    public function __construct(private readonly ?MenuBuilder $menus = null)
    {
    }

    #[\Override]
    public static function settings(): array
    {
        return [
            new Field('style', FieldType::Choice, required: true, default: 'horizontal', label: 'display.setting.style', choices: [
                'horizontal' => 'display.choice.horizontal',
                'vertical' => 'display.choice.vertical',
            ]),
            new Field('depth', FieldType::Integer, required: true, default: 2, label: 'display.setting.depth'),
        ];
    }

    #[\Override]
    public function validate(array $settings): array
    {
        $depth = $settings['depth'] ?? 1;

        return is_int($depth) && ($depth < 1 || $depth > self::MAX_DEPTH)
            ? ['depth' => new Message('display.setting.depth_range', ['max' => self::MAX_DEPTH])]
            : [];
    }

    /** Only a menu: the object of the menu Blueprint. */
    #[\Override]
    public function accepts(Subject $subject): bool
    {
        return $subject->object?->blueprint() === MenuBuilder::MENU_BLUEPRINT;
    }

    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): ?array
    {
        if ($this->menus === null || $subject->object === null) {
            return null;
        }
        $entries = $this->menus->buildFor($subject->object, $context->actor, $context->path, (int) $settings['depth'], $context->basePath);

        return $entries === [] ? null : ['entries' => $entries];
    }
}
