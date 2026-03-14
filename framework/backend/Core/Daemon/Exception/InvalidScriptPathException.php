<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Exception thrown when script path is invalid.
 *
 * This exception is used when a script path fails validation,
 * such as when the path doesn't exist, is not a file, or has wrong extension.
 */
class InvalidScriptPathException extends HilosException
{
    /**
     * Creates invalid script path exception.
     *
     * @param string $message Exception message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
