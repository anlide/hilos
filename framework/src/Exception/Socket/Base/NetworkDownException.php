<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when network is down (ENETDOWN)
 */
class NetworkDownException extends SocketException implements Throwable
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Network is down";
        // Code 100 on Linux, 10050 on Windows
        parent::__construct($message, 100, $previous);
    }
}

