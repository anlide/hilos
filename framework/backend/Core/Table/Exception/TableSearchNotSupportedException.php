<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: a window asked to search a table that declares no searchable fields.
 *
 * An empty declaration is a table saying it has no search, and a search reaching it is a mistake
 * of the code that offered one - so it is said out loud. The two silent answers were both refused:
 * the same window back looks like an input nothing happened to, and an empty set is what an honest
 * "nothing matched" looks like.
 */
class TableSearchNotSupportedException extends HilosException
{
    /**
     * Creates exception for a search that reached a table with nothing declared to search by.
     *
     * @param string $context Boundary that refused the search: the table class, or the entity
     *     class when the refusal is caught in the database layer instead
     */
    public function __construct(string $context)
    {
        parent::__construct("Search of [{$context}] was asked for, and no searchable fields are declared for it");
    }
}
