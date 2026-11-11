<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Core\Socket\Server;

use Demo\SolarSystemModel\Core\Socket\Client\SolarSystemModelWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * SolarSystemModelWebSocketServer - WebSocket server for the Solar System Model demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<SolarSystemModelWebSocketClient>
 */
final class SolarSystemModelWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new Solar System Model WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return SolarSystemModelWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): SolarSystemModelWebSocketClient
    {
        return new SolarSystemModelWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // SolarSystemModel WebSocket server has no specific startup logic
    }
}
