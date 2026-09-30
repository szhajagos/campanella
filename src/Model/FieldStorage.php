<?php

declare(strict_types=1);

namespace Campanella\Model;

/**
 * Where does a field's value live?
 *
 *  - Table: in the capability's own table, as an indexable column.
 *           Only such fields can be filtered and sorted on.
 *  - Data:  in the object's `data` JSON column. Storage only, no querying.
 */
enum FieldStorage
{
    case Table;
    case Data;
}
