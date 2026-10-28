<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\System\CheckResult;
use Campanella\System\CheckStatus;
use Closure;

/**
 * The system check's lines about the files to download (since 0.3.2): whether files
 * are available (their Blueprint), their folder, the accepted types, and whether
 * PHP's limits allow the configured largest file.
 */
final class FileCheck
{
    /** @return Closure(?Request): list<CheckResult> For SystemCheck::add() */
    public static function checks(FileService $files, FileStorage $storage): Closure
    {
        return static function (?Request $request) use ($files, $storage): array {
            $group = 'admin.system.group.media';
            if (!$files->isAvailable()) {
                return [new CheckResult($group, 'admin.system.files', CheckStatus::Warning, 'admin.system.off',
                    new Message('admin.system.files_' . $files->unavailableReason(), ['blueprint' => $files->blueprint()]))];
            }
            $results = [$storage->isWritable()
                ? new CheckResult($group, 'admin.system.files_folder', CheckStatus::Ok, $storage->directory())
                : new CheckResult($group, 'admin.system.files_folder', CheckStatus::Error, $storage->directory(), new Message('admin.system.files_folder_not_writable'))];
            $types = $files->types();
            $results[] = in_array('zip', $types, true)
                ? new CheckResult($group, 'admin.system.files_types', CheckStatus::Warning, implode(', ', $types), new Message('admin.system.files_zip'))
                : new CheckResult($group, 'admin.system.files_types', $types === [] ? CheckStatus::Warning : CheckStatus::Ok, $types === [] ? '–' : implode(', ', $types));
            $max = $files->maxUploadBytes();
            $configured = $files->maxBytesSetting();
            $results[] = $max >= $configured
                ? new CheckResult($group, 'admin.system.files_max', CheckStatus::Ok, self::megabytes($max))
                : new CheckResult($group, 'admin.system.files_max', CheckStatus::Warning, self::megabytes($max), new Message('admin.system.files_max_limited', ['max' => self::megabytes($configured)]));

            return $results;
        };
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, '.', ''), '0'), '.') . ' MB';
    }
}
