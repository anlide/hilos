<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Core\Socket\Server;

use Demo\OnlineTesting\Core\Socket\Client\OnlineTestingWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * OnlineTestingWebSocketServer - WebSocket server for the online-testing demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<OnlineTestingWebSocketClient>
 */
final class OnlineTestingWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new online-testing WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return OnlineTestingWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): OnlineTestingWebSocketClient
    {
        return new OnlineTestingWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // OnlineTesting WebSocket server has no specific startup logic
    }
}
