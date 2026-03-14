<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when network is unreachable (ENETUNREACH).
 */
class NetworkUnreachableException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "Network unreachable";
        // Code 101 on Linux, 10051 on Windows
        parent::__construct($message, 101, $previous);
    }
}
