<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Core\Socket\Server;

use Demo\ConcertTickets\Core\Socket\Client\ConcertTicketsWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * ConcertTicketsWebSocketServer - WebSocket server for the concert-tickets demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<ConcertTicketsWebSocketClient>
 */
final class ConcertTicketsWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new concert-tickets WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return ConcertTicketsWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): ConcertTicketsWebSocketClient
    {
        return new ConcertTicketsWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // ConcertTickets WebSocket server has no specific startup logic
    }
}
