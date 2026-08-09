<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: a row that has to be addressable produced no row key.
 */
class TableRowKeyMissingException extends HilosException
{
    /**
     * Creates exception for a row that cannot be addressed.
     *
     * @param string $rowClass Row class that produced no key
     */
    public function __construct(string $rowClass)
    {
        parent::__construct("Row [{$rowClass}] produced no row key and cannot be addressed");
    }
}
