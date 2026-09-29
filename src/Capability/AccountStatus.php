<?php

declare(strict_types=1);

namespace Campanella\Capability;

enum AccountStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
