<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Core\Socket\Client\ChatWebSocketClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * ChatWebSocketServer - WebSocket server for chat demo.
 *
 * Extends base WebSocketServer with chat-specific functionality.
 * Queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<ChatWebSocketClient>
 */
final class ChatWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new chat WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return ChatWebSocketClient Client instance
     */
    protected function onCreateClient($socket): ChatWebSocketClient
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
