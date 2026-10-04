<?php

declare(strict_types=1);

namespace Campanella\Media;

/** A checked image, ready to be stored (ImageProcessor::process()). */
final readonly class ProcessedImage
{
    /**
     * @param string $bytes The file content to store
     * @param string $extension Given by the recognised type (jpg, png, webp, gif), never by the upload's name
     * @param bool $reencoded Whether GD encoded it again (false: the checked original, metadata included)
     */
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $extension,
        public int $width,
        public int $height,
        public bool $reencoded,
    ) {
    }
}
