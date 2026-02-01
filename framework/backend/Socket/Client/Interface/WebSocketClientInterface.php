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
     * Get accept key
     *
     * @return string Accept key identifier
     */
    public function getAcceptKey(): string;

    /**
     * Send WebSocket text frame
     *
     * @param string $data Text data to send (UTF-8)
     */
    public function sendFrame(string $data): void;

    /**
     * Send WebSocket binary frame
     *
     * @param string $data Binary data to send
     */
    public function sendFrameBinary(string $data): void;
}
