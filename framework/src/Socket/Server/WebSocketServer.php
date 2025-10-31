<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;

/**
 * WebSocketServer - WebSocket server implementation
 *
 * Manages WebSocket server socket and accepts incoming connections.
 * Works with epoll in daemon main loop.
 *
 * This is an abstract class - child classes must implement createClient()
 * to create concrete WebSocketClient instances.
 */
abstract class WebSocketServer extends AbstractServer
{
    /**
     * Accept new connection
     *
     * @return ?WebSocketClientInterface New client or null
     */
    public function acceptConnection(): ?WebSocketClientInterface
    {
        return parent::acceptConnection();
    }

    /**
     * Create WebSocket client instance
     *
     * Must be implemented by child classes to create concrete WebSocketClient.
     *
     * @param resource $socket Client socket
     * @return WebSocketClientInterface Client instance
     */
    abstract protected function createClient($socket): WebSocketClientInterface;

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

