<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\Http\Request;
use Campanella\I18n\Message;
use Campanella\System\CheckResult;
use Campanella\System\CheckStatus;
use Closure;

/**
 * The system check's lines about images: the media folder, which types are
 * re-encoded, and whether PHP's limits allow the configured largest file and image.
 */
final class MediaCheck
{
    /** Below this (2 megapixels) even an ordinary phone photo cannot be uploaded. */
    private const int SMALLEST_USEFUL = 2_000_000;

    /** @return Closure(?Request): list<CheckResult> For SystemCheck::add() */
    public static function checks(ImageProcessor $processor, MediaStorage $storage, ?MediaService $media = null): Closure
    {
        return static function (?Request $request) use ($processor, $storage, $media): array {
            $group = 'admin.system.group.media';
            $results = [$storage->isWritable()
                ? new CheckResult($group, 'admin.system.media_folder', CheckStatus::Ok, $storage->directory())
                : new CheckResult($group, 'admin.system.media_folder', CheckStatus::Error, $storage->directory(), new Message('admin.system.media_folder_not_writable'))];

            $names = static fn (array $mimes): string => implode(', ', array_map(static fn (string $m): string => ImageProcessor::NAMES[$m], $mimes));
            $reencoded = $processor->reencodedTypes();
            $missing = array_values(array_diff(array_column(ImageProcessor::TYPES, 0), $reencoded));
            $results[] = match (true) {
                $missing === [] => new CheckResult($group, 'admin.system.media_reencode', CheckStatus::Ok, $names($reencoded), new Message('admin.system.media_reencode_ok')),
                $processor->storesUnprocessed() => new CheckResult($group, 'admin.system.media_reencode', CheckStatus::Warning, $names($reencoded) ?: '–', new Message('admin.system.media_unprocessed', ['types' => $names($missing)])),
                // Strict (the default): those types cannot be uploaded at all.
                $reencoded === [] => new CheckResult($group, 'admin.system.media_reencode', CheckStatus::Error, '–', new Message('admin.system.media_no_gd')),
                default => new CheckResult($group, 'admin.system.media_reencode', CheckStatus::Warning, $names($reencoded), new Message('admin.system.media_refused', ['types' => $names($missing)])),
            };

            if ($media !== null) {
                $results[] = self::variants($group, $processor, $media);
            }
            $results[] = self::fileLimit($group, $processor->maxBytes());
            $results[] = self::imageLimit($group, $processor);

            return $results;
        };
    }

    /** The smaller copies: their widths, and whether some images have none yet (since 0.1.2). */
    private static function variants(string $group, ImageProcessor $processor, MediaService $media): CheckResult
    {
        $widths = $processor->variantWidths();
        $value = $widths === [] ? 'admin.system.off' : implode(', ', $widths) . ' px';
        try {
            $missing = $media->countWithoutVariants();
        } catch (\PDOException) {
            $missing = 0; // before the upgrade that adds the column
        }
        if ($missing > 0 && $widths !== [] && $processor->reencodes()) {
            return new CheckResult($group, 'admin.system.media_variants', CheckStatus::Warning, $value,
                new Message('admin.system.media_variants_missing', ['count' => $missing]),
                'system/media-variants', 'admin.system.media_variants_make');
        }

        return new CheckResult($group, 'admin.system.media_variants', $widths === [] ? CheckStatus::Info : CheckStatus::Ok, $value);
    }

    /** Whether upload_max_filesize and post_max_size allow media.max_bytes. */
    private static function fileLimit(string $group, int $maxBytes): CheckResult
    {
        $limited = [];
        $allowed = $maxBytes;
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ImageProcessor::iniBytes((string) ini_get($setting));
            // post_max_size also carries the rest of the form: it must be a little larger.
            $needed = $setting === 'post_max_size' ? $maxBytes + MediaService::FORM_MARGIN : $maxBytes;
            if ($bytes > 0 && $bytes < $needed) {
                $limited[] = $setting;
                $allowed = min($allowed, $setting === 'post_max_size' ? max(0, $bytes - MediaService::FORM_MARGIN) : $bytes);
            }
        }
        $configured = self::megabytes($maxBytes);
        if ($limited === []) {
            return new CheckResult($group, 'admin.system.media_max_file', CheckStatus::Ok, $configured);
        }

        return new CheckResult($group, 'admin.system.media_max_file', CheckStatus::Warning, self::megabytes($allowed), new Message(
            'admin.system.media_max_file_limited',
            ['settings' => implode(', ', $limited), 'max' => $configured],
        ));
    }

    /** Whether memory_limit allows decoding an image of media.max_pixels. */
    private static function imageLimit(string $group, ImageProcessor $processor): CheckResult
    {
        if (!$processor->reencodes()) {
            return new CheckResult($group, 'admin.system.media_max_image', CheckStatus::Info, self::megapixels($processor->maxPixelsSetting()));
        }
        $now = $processor->maxPixels();
        $setting = $processor->maxPixelsSetting();

        return match (true) {
            $now >= $setting => new CheckResult($group, 'admin.system.media_max_image', CheckStatus::Ok, self::megapixels($setting)),
            $now < self::SMALLEST_USEFUL => new CheckResult($group, 'admin.system.media_max_image', CheckStatus::Error, self::megapixels($now), new Message('admin.system.media_max_image_limited', ['max' => self::megapixels($setting)])),
            default => new CheckResult($group, 'admin.system.media_max_image', CheckStatus::Warning, self::megapixels($now), new Message('admin.system.media_max_image_limited', ['max' => self::megapixels($setting)])),
        };
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, '.', ''), '0'), '.') . ' MB';
    }

    private static function megapixels(int $pixels): string
    {
        return rtrim(rtrim(number_format($pixels / 1_000_000, 1, '.', ''), '0'), '.') . ' MP';
    }
}
