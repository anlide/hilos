<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when connection timed out (ETIMEDOUT)
 */
class ConnectionTimeoutException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Connection timed out";
        // Code 110 on Linux, 10060 on Windows
        parent::__construct($message, 110, $previous);
    }
}
