<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Capability\Downloadable;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Subject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * A file to download: a link with its title, its type and size (since 0.3.3). Who may
 * download it is checked when it is downloaded; read from a source, a file the visitor
 * may not download is not shown at all.
 */
#[AsDisplay('download', requires: [Downloadable::class], label: 'display.download')]
final class DownloadDisplay extends Display
{
    #[\Override]
    public static function settings(): array
    {
        return [new Field('show_details', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_details')];
    }

    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): array
    {
        $download = $subject->object?->as(Downloadable::class);

        return [
            'extension' => strtoupper($download?->extension() ?? ''),
            'size' => $download?->size() ?? 0,
        ];
    }
}
