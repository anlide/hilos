<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\Daemon\ClientSocketDetacher;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\ContainedFailureSink;
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
     * @throws HilosException Whatever the concrete server's start hook raises
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
     * The one way a client leaves: off the master's watch, closed, then forgotten.
     *
     * Every exit path calls this instead of assembling the three steps again, because
     * the order between them is not a local detail - a socket closed while still watched
     * leaves the master holding a dead descriptor and the client behind it. Idempotent:
     * entering twice for the same client is normal on a path that is already failing.
     *
     * @param ClientInterface $client Client to drop
     * @throws SocketException When closing the client's socket fails
     * @throws HilosException When the client fails to announce its close
     */
    public function dropClient(ClientInterface $client): void;

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
     * Wires the seam a contained failure of this server is reported through.
     *
     * Every server is handed it at registration, whatever it descends from: a server
     * that contains failures without reporting them is silent in the one way that
     * cannot be told apart from having none. A server with nothing to contain still
     * implements this - an empty body says so out loud.
     *
     * @param ContainedFailureSink $sink Master seam a contained failure is handed to
     */
    public function setContainedFailureSink(ContainedFailureSink $sink): void;

    /**
     * Wires the seam a departing client's socket is taken off the master's watch through.
     *
     * Every server is handed it at registration, whatever it descends from, for the
     * reason the sink above is: a server that closes watched sockets without saying so
     * leaves the master holding them, and holding them looks exactly like being idle.
     * A server whose clients never reach the master's loop still implements this - an
     * empty body says so out loud.
     *
     * @param ClientSocketDetacher $detacher Master seam a departing client is announced to
     */
    public function setClientSocketDetacher(ClientSocketDetacher $detacher): void;

    /**
     * Tick method - called regularly to process clients.
     *
     * Should process read/write operations for all connected clients, containing a
     * failure that belongs to a single client instead of leaving the loop with it, and
     * reporting what it contained as a {@see ContainedFailure} through the wired sink.
     *
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws HilosException Whatever the concrete server's own tick raises
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
