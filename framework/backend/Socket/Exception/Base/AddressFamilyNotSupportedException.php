<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when address family is not supported (EAFNOSUPPORT).
 */
class AddressFamilyNotSupportedException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "Address family not supported";
        parent::__construct($message, 97, $previous); // EAFNOSUPPORT
    }
}
