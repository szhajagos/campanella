<?php

declare(strict_types=1);

namespace Utvonal;

use Campanella\Module\ModuleContext;

/** A test module (0.4.7): its page is where the search is. */
final class Module implements \Campanella\Module\Module
{
    public function register(ModuleContext $context): void
    {
        $context->route('/search', Oldal::class);
    }
}
