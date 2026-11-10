<?php

declare(strict_types=1);

namespace Demo\Pmd\Core\Socket\Server;

use Demo\Pmd\Core\Socket\Client\PmdWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * PmdWebSocketServer - WebSocket server for the PMD demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<PmdWebSocketClient>
 */
final class PmdWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new pmd WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return PmdWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): PmdWebSocketClient
    {
        return new PmdWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // Pmd WebSocket server has no specific startup logic
    }
}
