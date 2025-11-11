<?php

declare(strict_types=1);

namespace Hilos\Exception;

use Throwable;

/**
 * Exception thrown when a required environment variable is missing
 */
class MissingEnvironmentVariableException extends \Exception
{
    public function __construct(string $variableName, ?Throwable $previous = null)
    {
        $message = "Required environment variable '{$variableName}' is not defined. " .
            "Please check your .env file or environment configuration.";
        parent::__construct($message, 0, $previous);
    }
}
