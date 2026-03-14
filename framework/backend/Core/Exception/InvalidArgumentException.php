<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

use Hilos\HilosException;

/**
 * Exception thrown when an invalid argument is passed.
 *
 * Hilos wrapper for invalid argument errors. Use instead of PHP \InvalidArgumentException.
 */
class InvalidArgumentException extends HilosException
{
}
