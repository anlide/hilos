<?php

declare(strict_types=1);

namespace Hilos;

use Exception;
use Throwable;

/**
 * Base exception for all framework exceptions.
 *
 * All Hilos-specific exceptions (Database, Idea, Runtime, Page, Router, Socket, etc.)
 * extend this class so that callers can document a single throws for the public API.
 */
class HilosException extends Exception
{
    /**
     * Creates exception instance.
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
