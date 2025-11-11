<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when socket_read fails
 */
class SocketReadException extends SocketException
{
    public function __construct(int $errorCode, string $errorMessage, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Socket read failed (error %d): %s",
            $errorCode,
            $errorMessage,
        );
        parent::__construct($message, $errorCode, $previous);
    }
}
