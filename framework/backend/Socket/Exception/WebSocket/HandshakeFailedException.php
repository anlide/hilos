<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\WebSocket;

use Hilos\Socket\Exception\WebSocketException;
use Throwable;

/**
 * Exception thrown when WebSocket handshake fails
 */
class HandshakeFailedException extends WebSocketException
{
    public function __construct(string $reason = "Handshake failed", ?Throwable $previous = null)
    {
        $message = "WebSocket handshake failed: {$reason}";
        parent::__construct($message, 0, $previous);
    }
}
