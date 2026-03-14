<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Exception;

use Hilos\Socket\WebSocket\WebSocketException;
use Throwable;

/**
 * Exception thrown when WebSocket frame sequence is invalid.
 *
 * E.g., continuation frame received without initial fragmented frame.
 */
class InvalidFrameSequenceException extends WebSocketException
{
    /**
     * Creates exception with message and optional previous exception.
     *
     * @param string $message Error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = "Invalid WebSocket frame sequence", ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
