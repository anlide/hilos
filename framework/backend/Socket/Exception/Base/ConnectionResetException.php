<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when connection is reset by peer (ECONNRESET)
 */
class ConnectionResetException extends SocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Connection reset by peer";
        parent::__construct($message, 104, $previous); // ECONNRESET
    }
}
