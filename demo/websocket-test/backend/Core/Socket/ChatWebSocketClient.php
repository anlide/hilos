<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket;

use Hilos\Socket\Client\WebSocketClient;

/**
 * ChatWebSocketClient - WebSocket client for chat demo
 *
 * Handles chat-specific WebSocket frame processing.
 */
class ChatWebSocketClient extends WebSocketClient
{
    /** @var ?ChatWebSocketServer Server instance for broadcasting */
    private $server = null;

    /**
     * Set server instance for broadcasting messages
     *
     * @param ChatWebSocketServer $server Server instance
     */
    public function setServer(ChatWebSocketServer $server): void
    {
        $this->server = $server;
    }

    /**
     * Handle received WebSocket text frame
     *
     * @param string $payload Frame payload (UTF-8 text)
     */
    protected function onFrame(string $payload): void
    {
        // Broadcast message to all other clients via server
        if ($this->server !== null) {
            $this->server->broadcastMessage($this, $payload);
        }
    }

    /**
     * Handle received WebSocket binary frame
     *
     * @param string $payload Frame payload (binary data)
     */
    protected function onFrameBinary(string $payload): void
    {
        // Chat only handles text frames, ignore binary frames
        // Could log or handle binary data if needed
    }

    /**
     * Send chat message
     *
     * @param string $message Message to send
     */
    public function sendMessage(string $message): void
    {
        $this->sendFrame($message);
    }

    /**
     * Called when WebSocket handshake is completed
     *
     * @param array $headers All HTTP headers from handshake request
     * @param string $acceptKey Sec-WebSocket-Accept value (can be used as connection identifier)
     * @param array $cookies Parsed cookies
     * @param string $clientIp Client IP address
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        string $clientIp,
    ): void
    {
        // Chat client handshake handling if needed
        // Can log connection info, validate cookies, etc.
    }

    /**
     * Called when socket connection is successfully closed
     */
    protected function onClose(): void
    {
        // Remove client from server's client list when connection closes
        if ($this->server !== null) {
            $this->server->removeClient($this);
        }
    }
}

