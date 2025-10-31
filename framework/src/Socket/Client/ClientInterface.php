<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Exception\SocketException;

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
     * Close client connection
     * 
     * @throws SocketException
     */
    public function close(): void;
}

