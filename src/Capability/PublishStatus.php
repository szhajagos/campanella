<?php

declare(strict_types=1);

namespace Campanella\Capability;

/**
 * A 0.0.1-ben két állapot van. Később ebből lesz a workflow
 * (draft → review → published → archived).
 */
enum PublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
