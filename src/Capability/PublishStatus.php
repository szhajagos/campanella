<?php

declare(strict_types=1);

namespace Campanella\Capability;

/**
 * 0.0.1 has two states. Later this becomes the workflow
 * (draft → review → published → archived).
 */
enum PublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
