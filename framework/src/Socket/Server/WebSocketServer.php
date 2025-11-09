<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\SocketException;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\SocketOperation;

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
     * @param SignalRouter $signalRouter Signal router instance
     * @return WebSocketClientInterface Client instance
     */
    abstract protected function onCreateClient($socket, SignalRouter $signalRouter): WebSocketClientInterface;

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "WebSocket Server";
    }

    /**
     * Prepare server for shutdown
     *
     * Stops accepting new connections.
     * Closing all connected clients.
     */
    public function prepareShutdown(): void
    {
        parent::prepareShutdown();

        foreach ($this->clients as $client) {
            $client->markShouldClose();
        }
    }

    /**
     * Check if server is ready to shutdown
     *
     * WebSocket server is ready when all clients have disconnected.
     *
     * @return bool True if ready to shutdown
     */
    public function isReadyToShutdown(): bool
    {
        // Ready when no clients are connected
        return empty($this->clients);
    }

    /**
     * Stop server
     *
     * Closes server socket only. Does NOT close client connections.
     * Clients should complete their sessions and disconnect themselves.
     *
     * @throws SocketException
     */
    public function stop(): void
    {
        // Close server socket only, don't close client connections
        if ($this->socket !== null) {
            socket_close($this->socket);
            // Check for errors during close
            $this->handleSocketError(SocketOperation::CLOSE);
            $this->socket = null;
        }

        $this->isRunning = false;
    }

    /**
     * Called when server is started
     *
     * Must be implemented by child classes to perform actions when server starts.
     */
    abstract protected function onStart(): void;
}
