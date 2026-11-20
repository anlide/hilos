<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Core\Socket\Server;

use Demo\HilosWebsite\Core\Socket\Client\HilosWebsiteWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * HilosWebsiteWebSocketServer - WebSocket server for the hilos-website demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<HilosWebsiteWebSocketClient>
 */
final class HilosWebsiteWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new hilos-website WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return HilosWebsiteWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): HilosWebsiteWebSocketClient
    {
        return new HilosWebsiteWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // HilosWebsite WebSocket server has no specific startup logic
    }
}
