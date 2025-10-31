<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Socket\Client\WebSocketClient;

/**
 * WebSocketServer - WebSocket server implementation
 *
 * Manages WebSocket server socket and accepts incoming connections.
 * Works with epoll in daemon main loop.
 */
class WebSocketServer extends AbstractServer
{
    /**
     * Accept new connection
     *
     * @return ?WebSocketClient New client or null
     */
    public function acceptConnection(): ?WebSocketClient
    {
        if (!$this->isRunning) {
            return null;
        }

        $clientSocket = @socket_accept($this->serverSocket);
        if ($clientSocket === false) {
            return null;
        }

        socket_set_nonblock($clientSocket);
        $client = new WebSocketClient($clientSocket);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Get backlog size for listen
     *
     * @return int Backlog size
     */
    protected function getBacklogSize(): int
    {
        return 100; // WebSocket servers typically handle more connections
    }

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "WebSocket Server";
    }
}

