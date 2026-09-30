<?php

declare(strict_types=1);

namespace Campanella\Auth;

use Campanella\Model\CampanellaObject;

/** The result of a login attempt. */
final readonly class LoginResult
{
    private function __construct(
        public bool $success,
        public ?CampanellaObject $user = null,
        public string $error = '',
    ) {
    }

    public static function success(CampanellaObject $user): self
    {
        return new self(true, $user);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
