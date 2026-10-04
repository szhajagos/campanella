<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\System\CheckResult;
use Campanella\System\CheckStatus;
use Closure;

/** The system check's lines about images: the media folder, and which types are re-encoded. */
final class MediaCheck
{
    /** @return Closure(?Request): list<CheckResult> For SystemCheck::add() */
    public static function checks(ImageProcessor $processor, MediaStorage $storage): Closure
    {
        return static function (?Request $request) use ($processor, $storage): array {
            $group = 'admin.system.group.media';
            $results = [$storage->isWritable()
                ? new CheckResult($group, 'admin.system.media_folder', CheckStatus::Ok, $storage->directory())
                : new CheckResult($group, 'admin.system.media_folder', CheckStatus::Error, $storage->directory(), new Message('admin.system.media_folder_not_writable'))];

            $all = array_column(ImageProcessor::TYPES, 0);
            $reencoded = $processor->reencodedTypes();
            $missing = array_values(array_diff($all, $reencoded));
            $results[] = $missing === []
                ? new CheckResult($group, 'admin.system.media_reencode', CheckStatus::Ok, implode(', ', $reencoded), new Message('admin.system.media_reencode_ok'))
                : new CheckResult($group, 'admin.system.media_reencode', CheckStatus::Warning, implode(', ', $reencoded) ?: '–', new Message('admin.system.media_reencode_missing', ['types' => implode(', ', $missing)]));

            return $results;
        };
    }
}
