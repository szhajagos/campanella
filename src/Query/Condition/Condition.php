<?php

declare(strict_types=1);

namespace Campanella\Query\Condition;

/**
 * A query condition. A declarative data structure (a small AST), not a
 * PHP closure, so the QueryCompiler can compile it to SQL, and access
 * control policies can be appended to the query the same way.
 */
interface Condition
{
}
