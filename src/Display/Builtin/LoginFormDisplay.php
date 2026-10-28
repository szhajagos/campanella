<?php

declare(strict_types=1);

namespace Campanella\Display\Builtin;

use Campanella\Auth\AuthService;
use Campanella\Auth\PasswordReset;
use Campanella\Display\AsDisplay;
use Campanella\Display\Display;
use Campanella\Display\DisplayContext;
use Campanella\Display\Source\VisitorSource;
use Campanella\Display\Subject;
use Campanella\Model\Field;
use Campanella\Model\FieldType;

/**
 * The login form, for an anonymous visitor (nothing for a logged-in one; since 0.3.3).
 * It is sent to the login page, which shows any error and returns to this page. Like the
 * login page, a page with it starts a session (a cookie) for its CSRF token.
 */
#[AsDisplay('login_form', sources: [VisitorSource::NAME], label: 'display.login_form')]
final class LoginFormDisplay extends Display
{
    public function __construct(
        private readonly ?AuthService $auth = null,
        private readonly ?PasswordReset $reset = null,
    ) {
    }

    #[\Override]
    public static function settings(): array
    {
        return [new Field('show_reset', FieldType::Boolean, required: true, default: true, label: 'display.setting.show_reset')];
    }

    #[\Override]
    public function variables(Subject $subject, array $settings, DisplayContext $context): ?array
    {
        if (!$context->actor->isAnonymous()) {
            return null;
        }

        return [
            'guard_fields' => implode('', array_map(static fn ($guard): string => $guard->fields(), $this->auth?->guards() ?? [])),
            'reset_available' => $this->reset?->isAvailable() ?? false,
            'target' => $context->path,
        ];
    }
}
