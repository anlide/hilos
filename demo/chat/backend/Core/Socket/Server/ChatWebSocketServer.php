<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Core\Socket\Client\ChatWebSocketClient;
use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\SocketException;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;

/**
 * ChatWebSocketServer - WebSocket server for chat demo
 *
 * Extends base WebSocketServer with chat-specific functionality.
 * Queues signals for dispatch through SignalRouter.
 */
class ChatWebSocketServer extends WebSocketServer
{
    /**
     * Accept new connection
     *
     * @return ?ChatWebSocketClient New client or null
     * @throws SocketException
     */
    public function acceptConnection(): ?ChatWebSocketClient
    {
        return parent::acceptConnection();
    }

    /**
     * Called when a new chat WebSocket client connection is accepted
     *
     * @param resource $socket Client socket
     * @param SignalRouter $signalRouter Signal router instance
     * @return WebSocketClientInterface Client instance
     */
    protected function onCreateClient($socket, SignalRouter $signalRouter): WebSocketClientInterface
    {
        return new ChatWebSocketClient($socket, $signalRouter);
    }

    /**
     * Called when server is started
     */
    protected function onStart(): void
    {
        // Chat WebSocket server has no specific startup logic
    }
}
