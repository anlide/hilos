<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when socket_close fails.
 */
class SocketCloseException extends SocketException
{
    /**
     * Creates exception with error code and message.
     *
     * @param int $errorCode Socket error code
     * @param string $errorMessage Error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(int $errorCode, string $errorMessage, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Socket close failed (error %d): %s",
            $errorCode,
            $errorMessage,
        );
        parent::__construct($message, $errorCode, $previous);
    }
}
