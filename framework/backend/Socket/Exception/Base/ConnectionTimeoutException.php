<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when connection timed out (ETIMEDOUT).
 */
class ConnectionTimeoutException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "Connection timed out";
        // Code 110 on Linux, 10060 on Windows
        parent::__construct($message, 110, $previous);
    }
}
