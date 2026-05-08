<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when socket select operation fails.
 */
class SocketSelectException extends SocketException
{
    /**
     * Creates socket select exception.
     *
     * @param int $errorCode Socket error code
     * @param string $errorMessage Error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(int $errorCode, string $errorMessage, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf("Socket select failed (error %d): %s", $errorCode, $errorMessage),
            $errorCode,
            $previous,
        );
    }
}
