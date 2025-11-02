<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when socket_set_nonblock fails
 */
class SocketSetNonBlockException extends SocketException
{
    public function __construct(int $errorCode, string $errorMessage, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Socket set nonblock failed (error %d): %s",
            $errorCode,
            $errorMessage
        );
        parent::__construct($message, $errorCode, $previous);
    }
}

