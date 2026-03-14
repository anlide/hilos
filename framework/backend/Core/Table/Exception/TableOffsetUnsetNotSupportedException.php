<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception thrown when offsetUnset is not supported on TableDefinition.
 */
class TableOffsetUnsetNotSupportedException extends HilosException
{
    /**
     * Creates exception for unsupported offsetUnset.
     */
    public function __construct()
    {
        parent::__construct('TableDefinition does not support offsetUnset');
    }
}
