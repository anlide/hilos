<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Exception;

use Hilos\HilosException;

/**
 * Exception thrown when CLI command fails (e.g. DB not connected, invalid config).
 */
class CommandException extends HilosException
{
}
