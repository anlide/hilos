<?php

declare(strict_types=1);

namespace Hilos\Exception;

use Throwable;

/**
 * Exception thrown when script path is invalid
 *
 * This exception is used when a script path fails validation,
 * such as when the path doesn't exist, is not a file, or has wrong extension.
 */
class InvalidScriptPathException extends HilosException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
