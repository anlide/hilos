<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when message is too long (EMSGSIZE).
 */
class MessageTooLongException extends SocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "Message too long";
        // Code 90 on Linux, 10040 on Windows
        parent::__construct($message, 90, $previous);
    }
}
