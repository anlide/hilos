<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when host is unreachable (EHOSTUNREACH)
 */
class HostUnreachableException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Host unreachable";
        // Code 113 on Linux, 10065 on Windows
        parent::__construct($message, 113, $previous);
    }
}
