<?php

declare(strict_types=1);

namespace Hilos\Utils\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Exception thrown when log rotation fails.
 *
 * This exception is used when operations related to log rotation fail,
 * such as creating archive directories or moving log files.
 */
class LogRotationException extends HilosException
{
    /**
     * Creates log rotation exception.
     *
     * @param string $message Error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
