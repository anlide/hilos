<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when socket is not connected (ENOTCONN)
 */
class NotConnectedException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Socket is not connected";
        parent::__construct($message, 107, $previous); // ENOTCONN
    }
}
