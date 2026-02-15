<?php

declare(strict_types=1);

namespace Hilos\Exception\Database\Actions;

use Hilos\Exception\HilosException;

/**
 * Exception: table name cannot be determined for DbActions operation.
 */
class TableNameUndeterminedException extends HilosException
{
}
