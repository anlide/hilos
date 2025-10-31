<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when address family is not supported (EAFNOSUPPORT)
 */
class AddressFamilyNotSupportedException extends SocketException implements Throwable
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Address family not supported";
        parent::__construct($message, 97, $previous); // EAFNOSUPPORT
    }
}

