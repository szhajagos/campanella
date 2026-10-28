<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Admin\AdminAccess;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Source\VisitorSource;
use Campanella\Display\Subject;

/** A button to the admin, only for those who may enter it (since 0.3.3). */
#[AsDisplay('admin_button', sources: [VisitorSource::NAME], label: 'display.admin_button')]
final class AdminButtonDisplay extends Display
{
    public function __construct(private readonly ?AdminAccess $admin = null)
    {
    }

    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): ?array
    {
        return $this->admin !== null && $this->admin->allows($context->actor) ? [] : null;
    }
}
