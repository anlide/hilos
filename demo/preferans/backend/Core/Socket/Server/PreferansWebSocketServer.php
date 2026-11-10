<?php

declare(strict_types=1);

namespace Demo\Preferans\Core\Socket\Server;

use Demo\Preferans\Core\Socket\Client\PreferansWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * PreferansWebSocketServer - WebSocket server for the preferans demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<PreferansWebSocketClient>
 */
final class PreferansWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new preferans WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return PreferansWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): PreferansWebSocketClient
    {
        return new PreferansWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // Preferans WebSocket server has no specific startup logic
    }
}
