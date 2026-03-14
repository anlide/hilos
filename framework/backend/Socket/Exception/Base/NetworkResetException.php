<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when network dropped connection on reset (ENETRESET).
 */
class NetworkResetException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "Network dropped connection on reset";
        // Code 102 on Linux, 10052 on Windows
        parent::__construct($message, 102, $previous);
    }
}
