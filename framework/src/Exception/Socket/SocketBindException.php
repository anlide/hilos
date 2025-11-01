<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when socket_bind fails
 */
class SocketBindException extends SocketException implements Throwable
{
    public function __construct(int $errorCode, string $errorMessage, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Socket bind failed (error %d): %s",
            $errorCode,
            $errorMessage
        );
        parent::__construct($message, $errorCode, $previous);
    }
}

