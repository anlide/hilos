<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Socket\Server;

use Demo\Tasks\Core\Socket\Client\TasksWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * TasksWebSocketServer - WebSocket server for the tasks demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<TasksWebSocketClient>
 */
final class TasksWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new tasks WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return TasksWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): TasksWebSocketClient
    {
        return new TasksWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // Tasks WebSocket server has no specific startup logic
    }
}
