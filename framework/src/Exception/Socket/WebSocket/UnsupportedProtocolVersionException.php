<?php

declare(strict_types=1);

namespace Hilos\Exception\Socket\WebSocket;

use Hilos\Exception\Socket\WebSocketException;
use Throwable;

/**
 * Exception thrown when client uses unsupported WebSocket protocol version
 */
class UnsupportedProtocolVersionException extends WebSocketException
{
    public function __construct(string $version, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Unsupported WebSocket protocol version: %s. Only version 13 (RFC 6455) is supported.",
            $version
        );
        parent::__construct($message, 0, $previous);
    }
}

