<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Exception;

use Hilos\Socket\WebSocket\WebSocketException;
use Throwable;

/**
 * Exception thrown when WebSocket frame requires masking key but it's missing.
 */
class MaskKeyMissingException extends WebSocketException
{
    /**
     * Creates exception with optional previous exception.
     *
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(?Throwable $previous = null)
    {
        $message = "WebSocket frame is marked as masked but masking key is missing or incomplete";
        parent::__construct($message, 0, $previous);
    }
}
