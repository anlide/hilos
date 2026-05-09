<?php

declare(strict_types=1);

namespace Hilos\Environment\Exception;

use Throwable;

/**
 * Thrown when a required environment variable is missing.
 */
final class MissingEnvironmentVariableException extends EnvException
{
    /**
     * Creates a missing environment variable exception.
     *
     * @param string $variableName Environment variable name
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $variableName, ?Throwable $previous = null)
    {
        parent::__construct(
            "Required environment variable '{$variableName}' is not defined. "
            . 'Please check your .env file or environment configuration.',
            0,
            $previous,
        );
    }
}
