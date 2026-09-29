<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Core\Socket\Server;

use Demo\BinanceBtcTracker\Core\Socket\Client\BinanceBtcTrackerWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * BinanceBtcTrackerWebSocketServer - WebSocket server for the binance-btc-tracker demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<BinanceBtcTrackerWebSocketClient>
 */
final class BinanceBtcTrackerWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new binance-btc-tracker WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return BinanceBtcTrackerWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): BinanceBtcTrackerWebSocketClient
    {
        return new BinanceBtcTrackerWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // BinanceBtcTracker WebSocket server has no specific startup logic
    }
}
