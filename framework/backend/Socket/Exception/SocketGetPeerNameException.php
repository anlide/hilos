<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when socket_getpeername fails.
 */
class SocketGetPeerNameException extends SocketException
{
    /**
     * Creates socket get peer name exception.
     *
     * @param int $errorCode Socket error code
     * @param string $errorMessage Socket error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(int $errorCode, string $errorMessage, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Failed to get peer name of socket. Error code: %d, Message: %s",
            $errorCode,
            $errorMessage,
        );
        parent::__construct($message, $errorCode, $previous);
    }
}
