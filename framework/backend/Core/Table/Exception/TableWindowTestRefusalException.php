<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: the test lever `test:table:refuse` refused a window of the table it names (HIL-1131).
 *
 * Thrown only by the lever, and only inside the trap of the window build, which catches it the way
 * it catches a real failure: one error line in the worker log and no window. It never leaves that
 * trap. Its text names the lever, so a person who dropped a table by hand and opens the log does
 * not go looking for a bug that is not there.
 */
class TableWindowTestRefusalException extends HilosException
{
    /**
     * Creates exception for a window the test lever refused to build.
     *
     * @param string $tableKey Wire key of the table whose window was refused
     */
    public function __construct(string $tableKey)
    {
        parent::__construct("Window of table [{$tableKey}] was refused by the test lever test:table:refuse");
    }
}
