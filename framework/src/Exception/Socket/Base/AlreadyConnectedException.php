<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when socket is already connected (EISCONN)
 */
class AlreadyConnectedException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Socket is already connected";
        parent::__construct($message, 106, $previous); // EISCONN
    }
}

