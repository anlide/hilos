<?php

declare(strict_types=1);

namespace Hilos\Core\Http\Exception;

use Hilos\Core\Exception\ValidationException;
use Throwable;

/**
 * Exception thrown when a required request query param is missing.
 */
class MissingRequestQueryParamException extends ValidationException
{
    /**
     * Creates missing request query param exception.
     *
     * @param string $paramKey Name of the missing query param
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(public readonly string $paramKey, ?Throwable $previous = null)
    {
        parent::__construct("{$paramKey} is required", 0, $previous);
    }
}
