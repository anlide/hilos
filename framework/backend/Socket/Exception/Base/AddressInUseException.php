<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when address is already in use (EADDRINUSE).
 */
class AddressInUseException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "Address already in use";
        // Code 98 on Linux, 10048 on Windows
        parent::__construct($message, 98, $previous);
    }
}
