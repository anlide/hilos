<?php

declare(strict_types=1);

namespace Hilos\Socket\Exception\WebSocket;

use Hilos\Socket\Exception\WebSocketException;
use Throwable;

/**
 * Exception thrown when WebSocket frame requires masking key but it's missing
 */
class MaskKeyMissingException extends WebSocketException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "WebSocket frame is marked as masked but masking key is missing or incomplete";
        parent::__construct($message, 0, $previous);
    }
}
