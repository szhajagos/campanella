<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Controller\ListController;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Subject;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * A list's objects as a table: the title (a link), and the columns the list made in the
 * admin chose (since 0.3.3). The template gets `columns` (name, label).
 */
#[AsDisplay('table', many: true, label: 'display.table')]
final class TableDisplay extends Display
{
    public function __construct(private readonly ?BlueprintRegistry $blueprints = null)
    {
    }

    #[\Override]
    public static function settings(): array
    {
        return [
            new Field('count', FieldType::Integer, required: true, default: 10, label: 'display.setting.count'),
            new Field('show_more', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_more'),
        ];
    }

    #[\Override]
    public function validate(array $settings): array
    {
        return CardsDisplay::countProblem($settings);
    }

    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): array
    {
        return [
            'columns' => $subject->list !== null && $this->blueprints?->find($subject->list->blueprint) !== null
                ? ListController::columns($subject->list, $this->blueprints)
                : [],
        ];
    }
}
