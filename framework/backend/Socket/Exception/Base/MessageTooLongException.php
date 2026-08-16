<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\Base;

use Hilos\Core\Exception\MalformedInput;
use Hilos\Socket\SocketException;
use Throwable;

/**
 * Exception thrown when message is too long (EMSGSIZE).
 *
 * Carries {@see MalformedInput} because a message longer than the reader accepts is
 * input refused for its shape, not a node that stopped working. The marker is stated
 * rather than left to the socket family it belongs to: a reader asking about the
 * nature of the failure gets the same answer here as from any other parsing refusal,
 * and does not have to know that this one happens to arrive as a socket error.
 */
class MessageTooLongException extends SocketException implements MalformedInput
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
