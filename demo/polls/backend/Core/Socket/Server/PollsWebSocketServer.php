<?php

declare(strict_types=1);

namespace Demo\Polls\Core\Socket\Server;

use Demo\Polls\Core\Socket\Client\PollsWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * PollsWebSocketServer - WebSocket server for the polls demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<PollsWebSocketClient>
 */
final class PollsWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new polls WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return PollsWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): PollsWebSocketClient
    {
        return new PollsWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // Polls WebSocket server has no specific startup logic
    }
}
