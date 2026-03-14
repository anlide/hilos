<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when socket is invalid (EBADF).
 */
class InvalidSocketException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Invalid socket file descriptor";
        parent::__construct($message, 9, $previous); // EBADF
    }
}
