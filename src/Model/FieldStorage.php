<?php

declare(strict_types=1);

namespace Campanella\Model;

/**
 * Hol él egy mező értéke?
 *
 *  - Table: a capability saját táblájában, indexelhető oszlopként.
 *           Csak ilyen mezőre lehet szűrni és rendezni.
 *  - Data:  az objektum `data` JSON oszlopában. Csak tárolás, lekérdezés nem.
 */
enum FieldStorage
{
    case Table;
    case Data;
}
