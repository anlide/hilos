<?php

declare(strict_types=1);

namespace Hilos\Socket\Client\Interface;

use Hilos\Socket\Client\ClientInterface;

/**
 * WebSocketClientInterface - Interface for WebSocket client implementations
 *
 * Extends ClientInterface with WebSocket-specific methods.
 */
interface WebSocketClientInterface extends ClientInterface
{
    /**
     * Send WebSocket frame
     *
     * @param string $data Data to send
     * @param bool $text Whether to send as text (true) or binary (false)
     */
    public function sendFrame(string $data, bool $text = true): void;
}

