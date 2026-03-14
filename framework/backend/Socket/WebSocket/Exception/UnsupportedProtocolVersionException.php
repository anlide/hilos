<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Exception;

use Hilos\Socket\WebSocket\WebSocketException;
use Throwable;

/**
 * Exception thrown when client uses unsupported WebSocket protocol version.
 */
class UnsupportedProtocolVersionException extends WebSocketException
{
    /**
     * Creates exception with version and optional previous exception.
     *
     * @param string $version Unsupported protocol version
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $version, ?Throwable $previous = null)
    {
        $message = sprintf(
            "Unsupported WebSocket protocol version: %s. Only version 13 (RFC 6455) is supported.",
            $version,
        );
        parent::__construct($message, 0, $previous);
    }
}
