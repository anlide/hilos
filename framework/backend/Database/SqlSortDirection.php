<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Core\Table\TableConstants;

/**
 * SQL sort direction keywords for `ORDER BY` clauses and ORM order maps.
 *
 * Distinct from {@see TableConstants::ORDER_ASC} / {@see TableConstants::ORDER_DESC},
 * which are the lowercase wire values a client sends for a table's sort state: those
 * describe what the browser asked for, these are the SQL keywords a query is built
 * from. A table that sorts by a client-chosen direction maps the former onto the
 * latter rather than reusing one for both.
 */
final class SqlSortDirection
{
    /** Ascending order (`ORDER BY ... ASC`). */
    public const string ASC = 'ASC';

    /** Descending order (`ORDER BY ... DESC`). */
    public const string DESC = 'DESC';
}
