<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Core\Socket\Server;

use Demo\SimplePoll\Core\Socket\Client\PollWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * PollWebSocketServer - WebSocket server for the simple-poll demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<PollWebSocketClient>
 */
final class PollWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new poll WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return PollWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): PollWebSocketClient
    {
        return new PollWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // Poll WebSocket server has no specific startup logic
    }
}
