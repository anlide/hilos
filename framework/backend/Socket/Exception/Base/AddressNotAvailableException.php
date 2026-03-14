<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when address is not available (EADDRNOTAVAIL).
 */
class AddressNotAvailableException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "Address not available";
        // Code 99 on Linux, 10049 on Windows
        parent::__construct($message, 99, $previous);
    }
}
