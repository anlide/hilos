<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: a table declared a searchable field its row source has nothing to search in.
 *
 * A field nobody can reach is refused rather than passed over, which is where this parts company
 * with the sort vocabulary next to it: an order that lost a field shows itself, the rows standing
 * in an order nobody asked for, while a search that lost one just returns less and says nothing.
 *
 * The refusing boundary names itself, the way the sort gate's rejection line does - the table class
 * where the rows are searched in memory, the entity class whose columns the name failed against
 * where they are searched in the database. Both point at the one place to fix it, the table's own
 * declaration, from the side that could see the mistake.
 */
class TableSearchFieldUnknownException extends HilosException
{
    /**
     * Creates exception for a declared searchable field the row source cannot answer for.
     *
     * @param string $context Boundary that refused the field: the table or the entity class
     * @param string $field Row field the declaration is written under
     * @param string $column Column or payload key that declaration names, and the source has none of
     */
    public function __construct(string $context, string $field, string $column)
    {
        parent::__construct(
            "Search of [{$context}] declares field [{$field}], which its row source has no [{$column}] for",
        );
    }
}
