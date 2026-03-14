<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: offsetSet is not supported on TableDefinition.
 */
class TableOffsetSetNotSupportedException extends HilosException
{
    /**
     * Creates exception for unsupported offsetSet.
     */
    public function __construct()
    {
        parent::__construct('TableDefinition does not support offsetSet');
    }
}
