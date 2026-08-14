<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\HilosException;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\SocketException;
use Random\RandomException;

/**
 * ServerInterface - Interface for all server implementations.
 *
 * Defines common interface for server components that work with epoll.
 *
 * Concrete servers extend AbstractServer with a client template type; callers that
 * need a narrowed client type should depend on the concrete server, not this interface.
 */
interface ServerInterface
{
    /**
     * Start server - create and bind socket.
     *
     * @return bool True on success
     * @throws SocketException When socket create, bind or listen fails
     */
    public function start(): bool;

    /**
     * Stop server and close all connections.
     *
     * @throws SocketException When socket close fails
     * @throws HilosException When a client fails to announce its close
     */
    public function stop(): void;

    /**
     * Check if server is running.
     *
     * @return bool Running state
     */
    public function isRunning(): bool;

    /**
     * Get server socket for select.
     *
     * @return resource|object|null Server socket
     */
    public function getSocket();

    /**
     * Remove client from server.
     *
     * @param ClientInterface $client Client to remove
     */
    public function removeClient(ClientInterface $client): void;

    /**
     * Accept new connection.
     *
     * @return ?ClientInterface New client instance or null if no connection available (EWOULDBLOCK in non-blocking mode)
     * @throws SocketException When socket operations fail
     * @throws HilosException When the concrete client refuses to be constructed
     */
    public function acceptConnection(): ?ClientInterface;

    /**
     * Get server name for logging.
     *
     * @return string Server name
     */
    public function getServerName(): string;

    /**
     * Tick method - called regularly to process clients.
     *
     * Should process read/write operations for all connected clients, containing a
     * failure that belongs to a single client instead of leaving the loop with it.
     *
     * @throws RandomException When the secure random source refuses a handshake secret
     */
    public function onTick(): void;

    /**
     * Prepare server for shutdown.
     *
     * Called when daemon receives shutdown signal.
     * Server should stop accepting new connections and prepare for graceful shutdown.
     */
    public function prepareShutdown(): void;

    /**
     * Check if server is ready to shutdown.
     *
     * @return bool True if server is ready to shutdown (all clients disconnected, all workers stopped, etc.)
     */
    public function isReadyToShutdown(): bool;
}
