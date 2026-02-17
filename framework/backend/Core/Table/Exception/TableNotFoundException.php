<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Exception;

use Hilos\HilosException;

/**
 * Exception: table not found in Hilos::$table (TableHub).
 */
class TableNotFoundException extends HilosException
{
}
