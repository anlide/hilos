<?php

declare(strict_types=1);

namespace Hilos\Core\Http\Exception;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Http\RequestQueryParams;
use Throwable;

/**
 * Required request query param is missing.
 *
 * Thrown by {@see RequestQueryParams::requireString()} when the key is absent;
 * a present-but-empty value is reported as an empty-value error instead.
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
