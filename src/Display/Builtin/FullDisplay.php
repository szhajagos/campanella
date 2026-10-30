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
 * An object in full, as on its own page (since 0.3.3). Its templates are the object's:
 * `object/<blueprint>--full.html.twig`, then `object/full.html.twig`, as before 0.3.3.
 */
#[AsDisplay('full', requires: [Titled::class], label: 'display.full')]
final class FullDisplay extends Display
{
    #[\Override]
    public static function settings(): array
    {
        return [
            new Field('show_title', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_title'),
            // Since 0.3.5 (the front page leaves it out).
            new Field('show_date', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_date'),
        ];
    }

    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): array
    {
        return ['mode' => 'full'];
    }

    #[\Override]
    public function templates(string $name, Subject $subject): array
    {
        return [sprintf('object/%s--full.html.twig', $subject->object?->blueprint()), 'object/full.html.twig'];
    }
}
