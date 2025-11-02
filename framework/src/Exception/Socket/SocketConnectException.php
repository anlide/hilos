<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * SocketConnectException - Exception thrown when socket connect operation fails
 *
 * Thrown when socket_connect() fails and error is not handled by specific error handlers.
 */
class SocketConnectException extends SocketException
{
    public function __construct(int $errorCode, string $errorMessage, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Socket connect failed (error %d): %s",
            $errorCode,
            $errorMessage
        );
        parent::__construct($message, $errorCode, $previous);
    }
}

