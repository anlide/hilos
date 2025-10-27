<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

/**
 * ServerInterface - Interface for all server implementations
 *
 * Defines common interface for server components that work with epoll.
 */
interface ServerInterface
{
    /**
     * Start server - create and bind socket
     *
     * @return bool True on success
     */
    public function start(): bool;

    /**
     * Stop server
     */
    public function stop(): void;

    /**
     * Check if server is running
     *
     * @return bool Running state
     */
    public function isRunning(): bool;

    /**
     * Get server socket for select
     *
     * @return ?resource Server socket
     */
    public function getSocket();

    /**
     * Remove client from server
     *
     * @param mixed $client Client to remove
     */
    public function removeClient($client): void;

    /**
     * Accept new connection
     *
     * @return mixed New client or null
     */
    public function acceptConnection();

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    public function getServerName(): string;
}

