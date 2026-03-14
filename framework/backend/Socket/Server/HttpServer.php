<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Client\Interface\HttpClientInterface;
use Hilos\Socket\SocketException;

/**
 * HttpServer - HTTP server implementation.
 *
 * Manages HTTP server socket and accepts incoming connections.
 * Works with epoll in daemon main loop.
 */
class HttpServer extends AbstractServer
{
    /**
     * Accept new connection
     *
     * @return ?HttpClientInterface New client or null
     * @throws SocketException
     */
    public function acceptConnection(): ?HttpClientInterface
    {
        /** @var ?HttpClientInterface */
        return parent::acceptConnection();
    }

    /**
     * Called when a new HTTP client connection is accepted
     *
     * @param resource $socket Client socket
     * @return HttpClientInterface Client instance
     */
    protected function onCreateClient($socket): HttpClientInterface
    {
        return new HttpClient($socket);
    }

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "HTTP Server";
    }

    /**
     * Prepare server for shutdown
     *
     * Stops accepting new connections.
     */
    public function prepareShutdown(): void
    {
        parent::prepareShutdown();
    }

    /**
     * Check if server is ready to shutdown
     *
     * HTTP server is ready when all clients have disconnected.
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
     * Clients should complete their requests and disconnect themselves.
     *
     * @throws SocketException
     */
    public function stop(): void
    {
        // Close server socket only, don't close client connections
        if ($this->socket !== null) {
            socket_close($this->socket);
            // Check for errors during close
            $this->handleSocketError(\Hilos\Socket\SocketOperation::CLOSE);
            $this->socket = null;
        }

        $this->isRunning = false;
    }

    /**
     * Called when server is started
     */
    protected function onStart(): void
    {
        // HTTP server has no specific startup logic
    }
}
