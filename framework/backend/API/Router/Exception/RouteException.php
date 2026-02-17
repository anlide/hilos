<?php

declare(strict_types=1);

namespace Hilos\API\Router\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Base exception for router-related errors
 */
class RouteException extends HilosException
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
