<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Source\BreadcrumbsSource;
use Campanella\Display\Subject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/** Where the page is: the front page, the ancestors of a tree node, the page itself (since 0.3.3). */
#[AsDisplay('breadcrumbs', sources: [BreadcrumbsSource::NAME], label: 'display.breadcrumbs')]
final class BreadcrumbsDisplay extends Display
{
    #[\Override]
    public static function settings(): array
    {
        return [new Field('show_home', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_home')];
    }

    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): array
    {
        $value = is_array($subject->value) ? $subject->value : [];

        return ['ancestors' => $value['ancestors'] ?? [], 'current' => $value['current'] ?? $subject->object];
    }
}
