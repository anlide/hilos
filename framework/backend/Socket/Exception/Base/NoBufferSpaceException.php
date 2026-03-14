<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when no buffer space available (ENOBUFS).
 */
class NoBufferSpaceException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "No buffer space available";
        parent::__construct($message, 105, $previous); // ENOBUFS
    }
}
