<?php

declare(strict_types=1);

namespace Campanella\Auth;

use Campanella\Model\CampanellaObject;

/** The result of a login attempt. */
final readonly class LoginResult
{
    /** @param array<string, string|int|float> $errorParams */
    private function __construct(
        public bool $success,
        public ?CampanellaObject $user = null,
        public string $error = '',
        public array $errorParams = [],
    ) {
    }

    public static function success(CampanellaObject $user): self
    {
        return new self(true, $user);
    }

    /**
     * @param string $error A message key (see lang/), or a ready-made text.
     * @param array<string, string|int|float> $params The key's {name} parameters.
     */
    public static function failure(string $error, array $params = []): self
    {
        return new self(false, null, $error, $params);
    }
}
