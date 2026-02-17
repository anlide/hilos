<?php

declare(strict_types=1);

namespace Hilos\Utils\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Exception thrown when log rotation fails
 *
 * This exception is used when operations related to log rotation fail,
 * such as creating archive directories or moving log files.
 */
class LogRotationException extends HilosException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
