<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

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
     * @return resource Socket resource
     */
    public function getSocket();

    /**
     * Read data from client socket
     */
    public function read(): void;

    /**
     * Write buffered data to socket
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
     */
    public function close(): void;
}

