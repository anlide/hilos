<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Socket\SocketException;

/**
 * ClientInterface - Interface for all client implementations
 *
 * Defines common interface for client connections managed by servers.
 */
interface ClientInterface
{
    /**
     * Get client socket
     *
     * @return resource|object|null Socket resource (may be null after close)
     */
    public function getSocket();

    /**
     * Read data from client socket
     *
     * @throws SocketException
     */
    public function read(): void;

    /**
     * Write buffered data to socket
     *
     * @throws SocketException
     */
    public function write(): void;

    /**
     * Check if client should be closed
     *
     * @return bool True if should close
     */
    public function shouldClose(): bool;

    /**
     * Mark socket for closing
     */
    public function markShouldClose(): void;

    /**
     * Close client connection
     *
     * @throws SocketException
     */
    public function close(): void;

    /**
     * Tick method - called on each server tick
     *
     * Allows clients to perform periodic operations (e.g., timeout checks).
     * Called before read() and write() in server's onTick().
     */
    public function onTick(): void;
}
