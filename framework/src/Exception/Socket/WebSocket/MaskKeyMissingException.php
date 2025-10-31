<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\WebSocket;

use Hilos\Exception\Socket\WebSocketException;
use Throwable;

/**
 * Exception thrown when WebSocket frame requires masking key but it's missing
 */
class MaskKeyMissingException extends WebSocketException implements Throwable
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "WebSocket frame is marked as masked but masking key is missing or incomplete";
        parent::__construct($message, 0, $previous);
    }
}

