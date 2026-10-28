<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Source\SearchSource;
use Campanella\Display\Subject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/** The search box, with the words searched for on the search page (since 0.3.3). */
#[AsDisplay('search_box', sources: [SearchSource::NAME], label: 'display.search_box')]
final class SearchBoxDisplay extends Display
{
    #[\Override]
    public static function settings(): array
    {
        return [new Field('placeholder', FieldType::String, length: 100, label: 'display.setting.placeholder')];
    }

    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): array
    {
        return ['query' => is_string($subject->value) ? $subject->value : ''];
    }
}
