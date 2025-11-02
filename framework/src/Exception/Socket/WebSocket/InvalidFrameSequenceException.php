<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\WebSocket;

use Hilos\Exception\Socket\WebSocketException;
use Throwable;

/**
 * Exception thrown when WebSocket frame sequence is invalid
 * (e.g., continuation frame received without initial fragmented frame)
 */
class InvalidFrameSequenceException extends WebSocketException
{
    public function __construct(string $message = "Invalid WebSocket frame sequence", ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

