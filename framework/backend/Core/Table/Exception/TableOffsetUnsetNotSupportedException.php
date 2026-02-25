<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: offsetUnset is not supported on TableDefinition.
 */
class TableOffsetUnsetNotSupportedException extends HilosException
{
    public function __construct()
    {
        parent::__construct('TableDefinition does not support offsetUnset');
    }
}
