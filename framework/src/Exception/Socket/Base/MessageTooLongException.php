<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\Base;

use Hilos\Exception\SocketException;
use Throwable;

/**
 * Exception thrown when message is too long (EMSGSIZE)
 */
class MessageTooLongException extends SocketException implements Throwable
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Message too long";
        // Code 90 on Linux, 10040 on Windows
        parent::__construct($message, 90, $previous);
    }
}

