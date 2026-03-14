<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when socket connect operation fails.
 *
 * Thrown when socket_connect() fails and error is not handled by specific error handlers.
 */
class SocketConnectException extends SocketException
{
    /**
     * Creates socket connect exception.
     *
     * @param int $errorCode Socket error code
     * @param string $errorMessage Error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(int $errorCode, string $errorMessage, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Socket connect failed (error %d): %s",
            $errorCode,
            $errorMessage,
        );
        parent::__construct($message, $errorCode, $previous);
    }
}
