<?php

declare(strict_types=1);

namespace Hilos\Exception;

use Throwable;

/**
 * Base exception for socket-related errors
 */
class SocketException extends HilosException
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
