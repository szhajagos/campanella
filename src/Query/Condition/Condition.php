<?php

declare(strict_types=1);

namespace Campanella\Query\Condition;

/**
 * Egy lekérdezési feltétel. Deklaratív adatszerkezet (kis AST), nem
 * PHP-closure, ezért a QueryCompiler SQL-re tudja fordítani, a
 * jogosultsági szabályok pedig ugyanígy fűzhetők a lekérdezéshez.
 */
interface Condition
{
}
