<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when network is unreachable (ENETUNREACH)
 */
class NetworkUnreachableException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Network unreachable";
        // Code 101 on Linux, 10051 on Windows
        parent::__construct($message, 101, $previous);
    }
}

