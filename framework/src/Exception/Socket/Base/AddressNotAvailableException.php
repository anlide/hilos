<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when address is not available (EADDRNOTAVAIL)
 */
class AddressNotAvailableException extends SocketException implements Throwable
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Address not available";
        // Code 99 on Linux, 10049 on Windows
        parent::__construct($message, 99, $previous);
    }
}

