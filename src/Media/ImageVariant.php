<?php

declare(strict_types=1);

namespace Campanella\Media;

/** A smaller copy of an image, for `srcset` (since 0.1.2; ImageProcessor). */
final readonly class ImageVariant
{
    /** @param string $bytes The file content, encoded in the image's own type */
    public function __construct(
        public int $width,
        public int $height,
        public string $bytes,
    ) {
    }
}
