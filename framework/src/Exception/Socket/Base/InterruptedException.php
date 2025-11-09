<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when operation was interrupted (EINTR)
 */
class InterruptedException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Socket operation was interrupted";
        parent::__construct($message, 4, $previous); // EINTR
    }
}
