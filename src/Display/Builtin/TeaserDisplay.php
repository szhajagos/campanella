<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Capability\Titled;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Subject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * An object in short, as in lists: its title as a link, the date, the lead (since 0.3.3).
 * Its templates are the object's: `object/<blueprint>--teaser.html.twig`, then
 * `object/teaser.html.twig`, as before 0.3.3.
 */
#[AsDisplay('teaser', requires: [Titled::class], label: 'display.teaser')]
final class TeaserDisplay extends Display
{
    #[\Override]
    public static function settings(): array
    {
        return [
            new Field('show_date', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_date'),
            new Field('show_lead', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_lead'),
            // The title's heading level (since 0.4.0): 3 under a heading of its own, e.g. a list's in cards.
            new Field('heading', FieldType::Integer, required: true, default: 2, label: 'display.setting.heading'),
        ];
    }

    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): array
    {
        return ['mode' => 'teaser', 'heading' => max(2, min(4, (int) ($settings['heading'] ?? 2)))];
    }

    #[\Override]
    public function templates(string $name, Subject $subject): array
    {
        return [sprintf('object/%s--teaser.html.twig', $subject->object?->blueprint()), 'object/teaser.html.twig'];
    }
}
