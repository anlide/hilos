<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Exception thrown when worker ID is missing or invalid.
 */
class InvalidWorkerIdException extends HilosException
{
    /**
     * Creates invalid worker ID exception.
     *
     * @param string $reason Failure reason
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        $message = "Invalid worker ID: {$reason}. " .
            "Worker ID must be provided via --worker-id=N argument and must be a positive integer.";
        parent::__construct($message, 0, $previous);
    }
}
