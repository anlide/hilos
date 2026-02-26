<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Socket\AbstractSocket;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;

/**
 * AbstractServer - Abstract base class for server implementations
 *
 * Provides common functionality for all server types.
 */
abstract class AbstractServer extends AbstractSocket implements ServerInterface
{
    /** @var ClientInterface[] Active client connections */
    protected array $clients = [];

    /**
     * Get all active client connections
     *
     * @return ClientInterface[] Array of client connections
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    /** @var string Server host */
    protected string $host;

    /** @var int Server port */
    protected int $port;

    /** @var bool Server running state */
    protected bool $isRunning = false;

    /** @var bool Whether server is preparing for shutdown (should not accept new connections) */
    protected bool $preparingShutdown = false;

    /**
     * AbstractServer constructor
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Start server - create and bind socket
     *
     * @return bool True on success
     * @throws SocketException
     */
    public function start(): bool
    {
        if ($this->isRunning) {
            return true;
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $this->handleSocketError(SocketOperation::CREATE);
            return false;
        }
        $this->socket = $socket;

        if (!socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->handleSocketError(SocketOperation::SET_OPTION);
            return false;
        }

        if (!socket_set_nonblock($this->socket)) {
            $this->handleSocketError(SocketOperation::SET_NONBLOCK);
            return false;
        }

        if (!socket_bind($this->socket, $this->host, $this->port)) {
            $this->handleSocketError(SocketOperation::BIND);
            return false;
        }

        if (!socket_listen($this->socket, $this->getBacklogSize())) {
            $this->handleSocketError(SocketOperation::LISTEN);
            return false;
        }

        $this->isRunning = true;

        // Call onStart hook after server is started
        $this->onStart();

        return true;
    }

    /**
     * Called when server is started
     *
     * Must be implemented in child classes to perform actions when server starts.
     * Called once after server socket is bound and listening.
     */
    abstract protected function onStart(): void;

    /**
     * Stop server
     *
     * @throws SocketException
     */
    public function stop(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];

        if ($this->socket !== null) {
            socket_close($this->socket);
            // Check for errors during close
            $this->handleSocketError(SocketOperation::CLOSE);
            $this->socket = null;
        }

        $this->isRunning = false;
    }

    /**
     * Check if server is running
     *
     * @return bool Running state
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Remove client from server
     *
     * @param ClientInterface $client Client to remove
     */
    public function removeClient(ClientInterface $client): void
    {
        $key = array_search($client, $this->clients, true);
        if ($key !== false) {
            unset($this->clients[$key]);
        }
    }

    /**
     * Accept new connection - common implementation
     *
     * Handles socket_accept and socket_set_nonblock.
     * Child classes should implement onCreateClient() to create specific client type.
     *
     * @return ?ClientInterface New client instance or null if no connection available (EWOULDBLOCK in non-blocking mode)
     * @throws SocketException When socket operations fail
     */
    public function acceptConnection(): ?ClientInterface
    {
        if (!$this->isRunning) {
            return null;
        }

        // socket_accept
        $clientSocket = socket_accept($this->socket);
        if ($clientSocket === false) {
            // handleSocketError will handle ERR_WOULDBLOCK/WSA_WOULDBLOCK (returns silently)
            // For other errors, it will throw appropriate exceptions
            $this->handleSocketError(SocketOperation::ACCEPT);
            return null;
        }

        // socket_set_nonblock for accepted client socket
        if (!socket_set_nonblock($clientSocket)) {
            $this->handleSocketError(SocketOperation::SET_NONBLOCK);
            return null;
        }

        $client = $this->onCreateClient($clientSocket);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Mark socket for closing (abstract implementation from AbstractSocket)
     *
     * Servers don't auto-close on errors - this is a no-op for servers.
     */
    public function markShouldClose(): void
    {
        // Servers don't auto-close on socket errors
        // They continue running or are explicitly stopped via stop()
    }

    /**
     * Get backlog size for socket_listen
     *
     * Backlog specifies the maximum length of the queue of pending connections.
     * When the queue is full, new connection attempts are refused.
     *
     * Return 0 or less to use system SOMAXCONN value (obtained via getSystemMaxBacklog()).
     * Override this method in child classes to set custom backlog size.
     *
     * Note: The actual backlog may be capped by system SOMAXCONN value.
     * On Linux, you can check/modify it via: /proc/sys/net/core/somaxconn
     *
     * Common values:
     * - 5-10: For low-traffic servers (HTTP)
     * - 50-100: For high-traffic servers (WebSocket)
     * - 0: Use system default (SOMAXCONN, usually 128)
     *
     * @return int Backlog size (maximum pending connections, 0 for system default)
     */
    protected function getBacklogSize(): int
    {
        return 128; // Use system SOMAXCONN by default
    }

    /**
     * Called when a new client connection is accepted.
     *
     * Must be implemented by child classes to create specific client type.
     *
     * @param resource $socket Client socket
     * @return ClientInterface Client instance
     */
    abstract protected function onCreateClient($socket): ClientInterface;

    /**
     * Tick method - process all clients
     *
     * Reads from and writes to all connected clients.
     * Should be called regularly in main loop.
     */
    public function onTick(): void
    {
        $clientsToRemove = [];

        foreach ($this->clients as $client) {
            try {
                // Read from client
                $client->read();

                // Call client tick method
                $client->onTick();

                // Write to client
                $client->write();

                // Check if client should be closed
                if ($client->shouldClose()) {
                    $client->close();
                    $clientsToRemove[] = $client;
                }
            } catch (SocketException $e) {
                // If client has error, close it
                try {
                    $client->close();
                } catch (SocketException) {
                    // Ignore errors during close
                }
                $clientsToRemove[] = $client;
            }
        }

        // Remove closed clients after iteration
        foreach ($clientsToRemove as $client) {
            $this->removeClient($client);
        }
    }

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    abstract public function getServerName(): string;

    /**
     * Prepare server for shutdown
     *
     * Default implementation - stops accepting new connections.
     * Child classes should override to implement additional shutdown preparation logic.
     */
    public function prepareShutdown(): void
    {
        // Stop accepting new connections
        $this->preparingShutdown = true;
    }

    /**
     * Check if server is ready to shutdown
     *
     * Default implementation - by default servers are ready immediately.
     * Child classes should override to check client connections, worker processes, etc.
     *
     * @return bool True if server is ready to shutdown
     */
    public function isReadyToShutdown(): bool
    {
        // Default implementation - ready immediately
        return true;
    }
}
