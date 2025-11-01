<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Exception\SocketException;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;

/**
 * WebSocketServer - WebSocket server implementation
 *
 * Manages WebSocket server socket and accepts incoming connections.
 * Works with epoll in daemon main loop.
 *
 * This is an abstract class - child classes must implement onCreateClient()
 * to create concrete WebSocketClient instances.
 */
abstract class WebSocketServer extends AbstractServer
{
    /**
     * Accept new connection
     *
     * @return ?WebSocketClientInterface New client or null
     * @throws SocketException
     */
    public function acceptConnection(): ?WebSocketClientInterface
    {
        /** @var ?WebSocketClientInterface */
        return parent::acceptConnection();
    }

    /**
     * Called when a new WebSocket client connection is accepted
     *
     * Must be implemented by child classes to create concrete WebSocketClient.
     *
     * @param resource $socket Client socket
     * @return WebSocketClientInterface Client instance
     */
    abstract protected function onCreateClient($socket): WebSocketClientInterface;

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

