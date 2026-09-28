<?php

declare(strict_types=1);

namespace Campanella\Relation;

/** Hány célobjektum tartozhat egy kapcsolathoz. */
enum Cardinality: string
{
    /** Legfeljebb egy (pl. szerző, szülő). */
    case One = 'one';

    /** Tetszőleges számú, sorrendben (pl. kategóriák, képek). */
    case Many = 'many';
}
