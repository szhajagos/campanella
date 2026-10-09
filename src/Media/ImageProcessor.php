<?php

declare(strict_types=1);

namespace Campanella\Media;

use Campanella\I18n\Message;
use Campanella\Model\ValidationException;

/**
 * Checks an uploaded image and makes it safe to store.
 *
 * - The type is recognised from the file's content (getimagesize(), and
 *   fileinfo if available), never from its name: JPEG, PNG, WebP, GIF. SVG is
 *   not accepted (it can carry scripts).
 * - Size limits: the file (max_bytes) and the image (max_pixels, and what
 *   memory_limit allows for decoding it), checked before decoding.
 * - With GD the image is decoded and encoded again: only the pixels remain, so
 *   metadata (e.g. the GPS position of a phone photo) and anything hidden in the
 *   file are gone. A JPEG is turned upright by its EXIF orientation first (with
 *   the exif extension), and an image larger than max_dimension is scaled down.
 *   An animated GIF keeps only its first frame.
 * - Smaller copies (variants) are made for `srcset` (since 0.1.2): one for each
 *   configured width that is at most 90% of the image's, encoded the same way.
 * - A type this server cannot re-encode (no GD, or a GD built without support for
 *   it, e.g. WebP) is refused (media.type_not_processable), so no file is ever
 *   stored with its metadata. With the store_unprocessed setting the checked
 *   original is stored instead, metadata included; the system page warns.
 *
 * Problems are reported as a ValidationException on the `file` field.
 */
final class ImageProcessor
{
    /** The accepted types: IMAGETYPE_* => [MIME type, extension]. */
    public const array TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
    ];

    /** The types' names for messages. */
    public const array NAMES = ['image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WebP', 'image/gif' => 'GIF'];

    /**
     * Memory needed for one decoded pixel: measured 4.5 bytes for a JPEG and about
     * 8 for an RGBA PNG, a WebP or a rotated photo; with a margin.
     */
    private const int BYTES_PER_PIXEL = 9;

    /**
     * The most scans a JPEG may have. A progressive photo has about ten; a crafted
     * file repeating its scans would keep the decoder busy for minutes.
     */
    public const int MAX_JPEG_SCANS = 100;

    private readonly bool $gd;

    /** The default widths of the smaller copies (since 0.1.2; setting: media.variants). */
    public const array VARIANT_WIDTHS = [320, 640, 1024, 1600];

    /** A copy is made only for a width at most this part of the image's own. */
    public const float VARIANT_RATIO = 0.9;

    /** The most copy widths (their list must fit into the `variants` field). */
    public const int MAX_VARIANTS = 8;

    /** @var list<int> */
    private readonly array $variantWidths;

    /**
     * @param bool|null $useGd null: if the gd extension is loaded (false: e.g. to test the fallback)
     * @param string $memoryLimit memory_limit is raised to this while processing images, if the server
     *        allows it (decoding a 12-megapixel photo needs about 100 MB); '' or a lower value: not raised
     * @param bool $storeUnprocessed Store a type that cannot be re-encoded as it is (metadata included)
     *        instead of refusing it
     * @param array<mixed> $variantWidths The widths of the smaller copies (whole numbers, 16–10000; [] for none)
     */
    public function __construct(
        private readonly int $maxBytes = 10 * 1024 * 1024,
        private readonly int $maxPixels = 25_000_000,
        private readonly int $maxDimension = 2560,
        private readonly int $quality = 85,
        ?bool $useGd = null,
        private readonly string $memoryLimit = '320M',
        private readonly bool $storeUnprocessed = false,
        array $variantWidths = self::VARIANT_WIDTHS,
    ) {
        if ($maxBytes < 1 || $maxPixels < 1 || $maxDimension < 16 || $quality < 1 || $quality > 100) {
            throw new \InvalidArgumentException('Invalid image limits (max_bytes, max_pixels, max_dimension ≥ 16, quality 1–100).');
        }
        foreach ($variantWidths as $width) {
            if (!is_int($width) || $width < 16 || $width > 10000) {
                throw new \InvalidArgumentException('Invalid variant width (media.variants: whole numbers, 16–10000).');
            }
        }
        if (count(array_unique($variantWidths)) > self::MAX_VARIANTS) {
            throw new \InvalidArgumentException('Too many variant widths (media.variants: at most ' . self::MAX_VARIANTS . ').');
        }
        $widths = array_values(array_unique($variantWidths));
        sort($widths);
        $this->variantWidths = $widths;
        $this->gd = $useGd ?? extension_loaded('gd');
    }

    /**
     * The widths of the smaller copies (the media.variants setting), smallest first.
     *
     * @return list<int>
     */
    public function variantWidths(): array
    {
        return $this->variantWidths;
    }

    /**
     * The widths an image of this width gets copies for.
     *
     * @return list<int>
     */
    public function widthsFor(int $width): array
    {
        return array_values(array_filter($this->variantWidths, static fn (int $w): bool => $w <= $width * self::VARIANT_RATIO));
    }

    /**
     * The smaller copies of an image already stored (e.g. one uploaded before 0.1.2):
     * the file is decoded and each copy encoded in its type. Empty if the server cannot
     * re-encode its type, or the image is too small for any. The checks of an upload
     * apply (size, pixels, JPEG scans; a JPEG stored unprocessed is turned upright).
     *
     * @return list<ImageVariant>
     * @throws ValidationException if the file is not an accepted image or is too large to decode
     */
    public function variantsOf(string $file): array
    {
        $info = is_file($file) ? @getimagesize($file) : false;
        if ($info === false || !isset(self::TYPES[$info[2]])) {
            throw self::error('media.not_image');
        }
        $type = $info[2];
        // The longer side: a JPEG turned upright may swap them.
        if (!$this->gd || !self::canDecode($type) || $this->widthsFor(max((int) $info[0], (int) $info[1])) === []) {
            return [];
        }
        $size = (int) filesize($file);
        if ($size > $this->maxBytes) {
            throw self::error('media.too_large', ['max' => self::formatMegabytes($this->maxBytes)]);
        }
        if ((int) $info[0] * (int) $info[1] > $this->maxPixels()) {
            throw self::error('media.too_many_pixels', ['max' => round($this->maxPixels() / 1_000_000, 1)]);
        }
        if ($type === IMAGETYPE_JPEG && substr_count((string) file_get_contents($file), "\xFF\xDA") > self::MAX_JPEG_SCANS) {
            throw self::error('media.too_complex');
        }
        $image = self::decode($file, $type);
        if ($image === false) {
            throw self::error('media.processing_failed');
        }
        if ($type === IMAGETYPE_JPEG) {
            $image = self::orient($image, $file);
        }

        return $this->variants($image, $type, $size);
    }

    /** Whether a type that cannot be re-encoded is stored as it is (true) or refused (false, the default). */
    public function storesUnprocessed(): bool
    {
        return $this->storeUnprocessed;
    }

    /** Whether images are re-encoded (GD is available). */
    public function reencodes(): bool
    {
        return $this->gd;
    }

    /**
     * The accepted types this server can re-encode (e.g. a GD without WebP support
     * cannot re-encode WebP: such images are stored as they are).
     *
     * @return list<string> MIME types
     */
    public function reencodedTypes(): array
    {
        $types = [];
        foreach (self::TYPES as $type => [$mime]) {
            if ($this->gd && self::canDecode($type)) {
                $types[] = $mime;
            }
        }

        return $types;
    }

    /** The largest file accepted, in bytes (the max_bytes setting). */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** The max_pixels setting (maxPixels() may be lower, limited by memory_limit). */
    public function maxPixelsSetting(): int
    {
        return $this->maxPixels;
    }

    /**
     * The largest image (width × height) accepted now: the max_pixels setting, or less
     * if memory_limit would not allow decoding a larger one. Raises memory_limit to the
     * memoryLimit setting first, if the server allows it (for this request only).
     */
    public function maxPixels(): int
    {
        if (!$this->gd) {
            return $this->maxPixels;
        }
        $this->raiseMemoryLimit();
        $limit = self::iniBytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return $this->maxPixels;
        }
        // The decoded image (and a rotated copy), plus the scaled-down copy (at most
        // max_dimension², 4 bytes a pixel), with a margin.
        $available = (int) (($limit - memory_get_usage()) * 0.85) - $this->maxDimension ** 2 * 4;

        return max(0, min($this->maxPixels, intdiv($available, self::BYTES_PER_PIXEL)));
    }

    private function raiseMemoryLimit(): void
    {
        $target = self::iniBytes($this->memoryLimit);
        $current = self::iniBytes((string) ini_get('memory_limit'));
        if ($target > 0 && $current > 0 && $target > $current) {
            @ini_set('memory_limit', $this->memoryLimit);
        }
    }

    /**
     * @throws ValidationException with a message on the `file` field
     */
    public function process(string $file): ProcessedImage
    {
        $size = is_file($file) ? (int) filesize($file) : 0;
        if ($size === 0) {
            throw self::error('media.empty');
        }
        if ($size > $this->maxBytes) {
            throw self::error('media.too_large', ['max' => self::formatMegabytes($this->maxBytes)]);
        }

        $info = @getimagesize($file);
        if ($info === false) {
            throw self::error('media.not_image');
        }
        $type = $info[2];
        if (!isset(self::TYPES[$type])) {
            throw self::error('media.type_not_allowed');
        }
        [$mime, $extension] = self::TYPES[$type];
        // A second opinion on the content, if the fileinfo extension is available.
        if (class_exists(\finfo::class) && (new \finfo(FILEINFO_MIME_TYPE))->file($file) !== $mime) {
            throw self::error('media.not_image');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 1 || $height < 1) {
            throw self::error('media.not_image');
        }
        $maxPixels = $this->maxPixels();
        if ($width * $height > $maxPixels) {
            throw self::error('media.too_many_pixels', ['max' => round($maxPixels / 1_000_000, 1)]);
        }
        // Start-of-scan markers (FF DA): inside the compressed data an FF is always
        // followed by 00 or a restart marker, so this count is not fooled by the pixels.
        if ($type === IMAGETYPE_JPEG && substr_count((string) file_get_contents($file), "\xFF\xDA") > self::MAX_JPEG_SCANS) {
            throw self::error('media.too_complex');
        }

        if ($this->gd && self::canDecode($type)) {
            return $this->reencode($file, $type, $mime, $extension);
        }
        if (!$this->storeUnprocessed) {
            throw self::error('media.type_not_processable', ['type' => self::NAMES[$mime]]);
        }
        $bytes = (string) file_get_contents($file);

        return new ProcessedImage($bytes, $mime, $extension, $width, $height, false);
    }

    private function reencode(string $file, int $type, string $mime, string $extension): ProcessedImage
    {
        $image = self::decode($file, $type);
        if ($image === false) {
            throw self::error('media.processing_failed');
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = self::orient($image, $file);
        }
        $image = $this->scaleDown($image, $type !== IMAGETYPE_JPEG);
        $bytes = $this->encode($image, $type);

        return new ProcessedImage($bytes, $mime, $extension, imagesx($image), imagesy($image), true, $this->variants($image, $type, strlen($bytes)));
    }

    /**
     * The smaller copies of a decoded image, smallest first. A copy that would not be
     * smaller than the image's file ($size bytes) is left out: a drawing with few colours
     * can grow when scaled (smoothing adds colours); a palette PNG's copy keeps a palette.
     *
     * @return list<ImageVariant>
     */
    private function variants(\GdImage $image, int $type, int $size): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $palette = !imageistruecolor($image);
        $variants = [];
        foreach ($this->widthsFor($width) as $target) {
            $targetHeight = max(1, (int) round($height * $target / $width));
            $copy = $this->resized($image, $target, $targetHeight, $type !== IMAGETYPE_JPEG);
            if ($palette && $type === IMAGETYPE_PNG) {
                imagetruecolortopalette($copy, false, 256);
            }
            $bytes = $this->encode($copy, $type);
            if (strlen($bytes) < $size) {
                $variants[] = new ImageVariant($target, $targetHeight, $bytes);
            }
        }

        return $variants;
    }

    /** The image in its type's encoding (JPEG: progressive). */
    private function encode(\GdImage $image, int $type): string
    {
        ob_start();
        $written = match ($type) {
            IMAGETYPE_JPEG => imageinterlace($image, true) !== false && imagejpeg($image, null, $this->quality),
            IMAGETYPE_PNG => imagepng($image, null, 6),
            IMAGETYPE_WEBP => imagewebp($image, null, $this->quality),
            default => imagegif($image),
        };
        $bytes = (string) ob_get_clean();
        if (!$written || $bytes === '') {
            throw self::error('media.processing_failed');
        }

        return $bytes;
    }

    private static function decode(string $file, int $type): \GdImage|false
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            default => @imagecreatefromgif($file),
        };
    }

    /** Turns a photo upright according to its EXIF orientation (which is lost on re-encoding). */
    private static function orient(\GdImage $image, string $file): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($file);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        // imagerotate() turns counter-clockwise; after the flip, 5 and 7 are 6 and 8 mirrored.
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }

        return $rotated;
    }

    private function scaleDown(\GdImage $image, bool $alpha): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= $this->maxDimension) {
            return $image;
        }
        $ratio = $this->maxDimension / $longest;

        return $this->resized($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), $alpha);
    }

    private function resized(\GdImage $image, int $newWidth, int $newHeight, bool $alpha): \GdImage
    {
        $scaled = imagecreatetruecolor(max(1, $newWidth), max(1, $newHeight));
        if ($alpha) {
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagefill($scaled, 0, 0, (int) imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        }
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, imagesx($image), imagesy($image));

        return $scaled;
    }

    private static function canDecode(int $type): bool
    {
        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') && function_exists('imagepng'),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            default => function_exists('imagecreatefromgif') && function_exists('imagegif'),
        };
    }

    /** A php.ini size (`128M`, `1G`, `-1`) in bytes; -1 or 0: no limit. */
    public static function iniBytes(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1') {
            return -1;
        }
        $number = (int) $size;

        return match (strtoupper(substr($size, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private static function formatMegabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, '.', ''), '0'), '.');
    }

    /** @param array<string, string|int|float> $params */
    private static function error(string $key, array $params = []): ValidationException
    {
        return new ValidationException(['file' => new Message($key, $params)]);
    }
}
