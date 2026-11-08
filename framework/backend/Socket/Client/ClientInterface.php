<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Closure;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\HilosException;
use Hilos\Socket\SocketException;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Random\RandomException;

/**
 * ClientInterface - Interface for all client implementations.
 *
 * Defines common interface for client connections managed by servers.
 */
interface ClientInterface
{
    /**
     * Replaces this client's local readiness owner, canceling the previous binding.
     *
     * @param EventLoop $loop Owning master's loop
     * @param Closure(): void $wake One bounded client portion under the owner's failure guard
     * @throws SocketTlsHandshakeException When a live TLS session is rebound to another native base
     * @throws EventLoopException When a client protocol deadline cannot bind to the native loop
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void;

    /** Cancels queued work and native watches before close or transfer to another owner. */
    public function detachEventLoop(): void;

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
     * Allows clients to perform periodic operations (e.g., timeout checks). A failure belongs to
     * this one client: the server contains it, logs it and drops the client.
     *
     * @throws HilosException When the implementation's periodic work fails
     */
    public function onTick(): void;
}
