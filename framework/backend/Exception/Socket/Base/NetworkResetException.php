<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when network dropped connection on reset (ENETRESET)
 */
class NetworkResetException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Network dropped connection on reset";
        // Code 102 on Linux, 10052 on Windows
        parent::__construct($message, 102, $previous);
    }
}
