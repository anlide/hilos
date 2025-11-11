<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when connection was refused (ECONNREFUSED)
 */
class ConnectionRefusedException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Connection refused";
        // Code 111 on Linux, 10061 on Windows
        parent::__construct($message, 111, $previous);
    }
}
