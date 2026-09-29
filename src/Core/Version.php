<?php

declare(strict_types=1);

namespace Campanella\Core;

final class Version
{
    public const string CAMPANELLA = '0.0.3';

    /** Az adatbázisséma verziója; migrációnál nő. */
    public const string SCHEMA = '3';
}
