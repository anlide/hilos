<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when connection was aborted (ECONNABORTED)
 */
class ConnectionAbortedException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Connection aborted";
        // Code 103 on Linux, 10053 on Windows
        parent::__construct($message, 103, $previous);
    }
}
