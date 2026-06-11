<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Core\Socket\Server;

use Demo\SimpleTodo\Core\Socket\Client\TodoWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * TodoWebSocketServer - WebSocket server for the simple-todo demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<TodoWebSocketClient>
 */
final class TodoWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new todo WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return TodoWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): TodoWebSocketClient
    {
        return new TodoWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // Todo WebSocket server has no specific startup logic
    }
}
