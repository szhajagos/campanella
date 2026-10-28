<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\Source\VisitorSource;

/** The login button for an anonymous visitor (back to this page after logging in), the logout button for a logged-in one (since 0.3.3). */
#[AsDisplay('login_button', sources: [VisitorSource::NAME], label: 'display.login_button')]
final class LoginButtonDisplay extends Display
{
}
