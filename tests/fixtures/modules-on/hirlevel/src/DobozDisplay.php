<?php

declare(strict_types=1);

namespace Hirlevel;

use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\Source\SiteSource;
use Campanella\Display\Subject;

/** A display of a module, with its own template (a test). */
#[AsDisplay('hirlevel_doboz', sources: [SiteSource::NAME], label: 'hirlevel.display')]
final class DobozDisplay extends Display
{
    #[\Override]
    public function templates(string $name, Subject $subject): array
    {
        return ['@hirlevel/doboz.html.twig'];
    }
}
