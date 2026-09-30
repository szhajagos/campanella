<?php

declare(strict_types=1);

namespace Campanella\Core;

final class Version
{
    public const string CAMPANELLA = '0.0.3';

    /** The database schema version; incremented with migrations. */
    public const string SCHEMA = '4';
}
