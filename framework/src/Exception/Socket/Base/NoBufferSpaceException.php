<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when no buffer space available (ENOBUFS)
 */
class NoBufferSpaceException extends SocketException implements Throwable
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "No buffer space available";
        parent::__construct($message, 105, $previous); // ENOBUFS
    }
}

