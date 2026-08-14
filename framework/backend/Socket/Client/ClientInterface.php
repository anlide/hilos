<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\HilosException;
use Hilos\Socket\SocketException;
use Random\RandomException;

/**
 * ClientInterface - Interface for all client implementations.
 *
 * Defines common interface for client connections managed by servers.
 */
interface ClientInterface
{
    /**
     * Returns client socket.
     *
     * @return resource|object|null Socket resource (may be null after close)
     */
    public function getSocket();

    /**
     * Read data from client socket.
     *
     * @throws SocketException If read fails
     * @throws HilosException When buffered wire input refuses to become a DTO
     * @throws RandomException When the secure random source refuses a handshake secret
     */
    public function read(): void;

    /**
     * Write buffered data to socket.
     *
     * @throws SocketException If write fails
     * @throws HilosException When buffered wire input refuses to become a DTO
     */
    public function write(): void;

    /**
     * Check if client should be closed.
     *
     * @return bool True if should close
     */
    public function shouldClose(): bool;

    /**
     * Mark socket for closing.
     */
    public function markShouldClose(): void;

    /**
     * Close client connection.
     *
     * @throws SocketException If close fails
     * @throws HilosException When the implementation fails to announce the close
     */
    public function close(): void;

    /**
     * Called on each server tick before read() and write().
     *
     * Allows clients to perform periodic operations (e.g., timeout checks).
     */
    public function onTick(): void;
}
