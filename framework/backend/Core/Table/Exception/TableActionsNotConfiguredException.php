<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: trying to access actions on a table with no actions class registered.
 */
class TableActionsNotConfiguredException extends HilosException
{
    /**
     * Creates exception for unconfigured actions class.
     */
    public function __construct()
    {
        parent::__construct('No actions class configured for this table');
    }
}
