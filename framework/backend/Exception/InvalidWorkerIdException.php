<?php

declare(strict_types=1);

namespace Hilos\Exception;

use Throwable;

/**
 * Exception thrown when worker ID is missing or invalid
 */
class InvalidWorkerIdException extends HilosException
{
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        $message = "Invalid worker ID: {$reason}. " .
            "Worker ID must be provided via --worker-id=N argument and must be a positive integer.";
        parent::__construct($message, 0, $previous);
    }
}
