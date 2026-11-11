<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Core\Socket\Server;

use Demo\RobloxGameServer\Core\Socket\Client\RobloxGameServerWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * RobloxGameServerWebSocketServer - WebSocket server for the roblox-game-server demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<RobloxGameServerWebSocketClient>
 */
final class RobloxGameServerWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new roblox-game-server WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return RobloxGameServerWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): RobloxGameServerWebSocketClient
    {
        return new RobloxGameServerWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // RobloxGameServer WebSocket server has no specific startup logic
    }
}
