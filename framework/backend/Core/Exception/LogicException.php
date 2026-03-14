<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

use Hilos\HilosException;

/**
 * Exception thrown when logic error is detected (e.g. missing required constants).
 *
 * Hilos wrapper for logic errors. Use instead of PHP \LogicException.
 */
class LogicException extends HilosException
{
}
