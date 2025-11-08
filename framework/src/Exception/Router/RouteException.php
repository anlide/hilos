<?php

declare(strict_types=1);

namespace Hilos\Exception\Router;

use Exception;
use Throwable;

/**
 * Base exception for router-related errors
 */
class RouteException extends Exception
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

