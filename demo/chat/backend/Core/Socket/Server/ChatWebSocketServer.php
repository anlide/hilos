<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Core\Socket\Client\ChatWebSocketClient;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\SocketException;

/**
 * ChatWebSocketServer - WebSocket server for chat demo.
 *
 * Extends base WebSocketServer with chat-specific functionality.
 * Queues signals for dispatch through Hilos::$sr.
 */
class ChatWebSocketServer extends WebSocketServer
{
    /**
     * Accept new connection
     *
     * @return ?ChatWebSocketClient New client or null
     * @throws SocketException If accepting connection fails
     */
    public function acceptConnection(): ?ChatWebSocketClient
    {
        return parent::acceptConnection();
    }

    /**
     * Called when a new chat WebSocket client connection is accepted
     *
     * @param resource $socket Client socket
     * @return WebSocketClientInterface Client instance
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        return new ChatWebSocketClient($socket);
    }

    /**
     * Called when server is started. No chat-specific startup logic.
     */
    protected function onStart(): void
    {
        // Chat WebSocket server has no specific startup logic
    }
}
