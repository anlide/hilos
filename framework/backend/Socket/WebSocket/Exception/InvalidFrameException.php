<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Exception;

use Hilos\Socket\WebSocket\WebSocketException;
use Throwable;

/**
 * Exception thrown when WebSocket frame is invalid or malformed
 */
class InvalidFrameException extends WebSocketException
{
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        $message = "Invalid WebSocket frame: {$reason}";
        parent::__construct($message, 0, $previous);
    }
}
