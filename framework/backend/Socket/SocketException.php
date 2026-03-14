<?php

declare(strict_types=1);

namespace Hilos\Socket;

use Hilos\HilosException;
use Throwable;

/**
 * Base exception for socket-related errors.
 */
class SocketException extends HilosException
{
    /**
     * Creates socket exception.
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
