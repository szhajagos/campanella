<?php

declare(strict_types=1);

namespace Campanella\Access;

enum ActorKind: string
{
    case Anonymous = 'anonymous';
    case User = 'user';
    case Service = 'service';   // CLI, cron, API-token
}
