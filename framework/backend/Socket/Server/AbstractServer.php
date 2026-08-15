<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Constants\SocketConstants;
use Hilos\Core\Exception\MalformedInput;
use Hilos\HilosException;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;
use Hilos\Utils\Logger;
use Random\RandomException;
use Throwable;

/**
 * AbstractServer - Abstract base class for server implementations.
 *
 * Provides common functionality for all server types.
 *
 * @template TClient of ClientInterface
 */
abstract class AbstractServer extends AbstractSocket implements ServerInterface
{
    /** @var list<TClient> active client connections */
    protected array $clients = [];

    /** @var string Server host */
    protected string $host;

    /** @var int Server port */
    protected int $port;

    /** @var bool Server running state */
    protected bool $isRunning = false;

    /** @var bool Whether server is preparing for shutdown (should not accept new connections) */
    protected bool $preparingShutdown = false;

    /**
     * Create server with host and port.
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
     * Get all active client connections.
     *
     * @return list<TClient> Array of client connections
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    /**
     * Start server - create and bind socket.
     *
     * @return bool True on success
     * @throws SocketException When socket create, bind or listen fails
     * @throws HilosException Whatever the concrete server's start hook raises
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
     *
     * @throws HilosException Whatever the concrete server's start hook raises
     */
    abstract protected function onStart(): void;

    /**
     * Stop server and close all client connections.
     *
     * @throws SocketException When socket close fails
     * @throws HilosException When a client fails to announce its close
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
     * Accept new connection - common implementation.
     *
     * Handles socket_accept and socket_set_nonblock.
     * Child classes implement onCreateClient() to create the concrete client type.
     *
     * @return ?TClient New client or null when no connection is pending (EWOULDBLOCK)
     * @throws SocketException When socket operations fail
     * @throws HilosException When the concrete client refuses to be constructed
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
     * Default value is SocketConstants::DEFAULT_LISTEN_BACKLOG.
     * Override this method in child classes to set custom backlog size.
     *
     * Note: The actual backlog may be capped by system SOMAXCONN value.
     * On Linux, you can check/modify it via: /proc/sys/net/core/somaxconn
     *
     * Common values:
     * - 5-10: For low-traffic servers (HTTP)
     * - 50-100: For high-traffic servers (WebSocket)
     *
     * @return int Backlog size (maximum pending connections)
     */
    protected function getBacklogSize(): int
    {
        return SocketConstants::DEFAULT_LISTEN_BACKLOG;
    }

    /**
     * Called when a new client connection is accepted.
     *
     * Must be implemented by child classes to create specific client type.
     *
     * @param resource $socket Client socket
     * @return TClient Client instance
     * @throws HilosException When the concrete client refuses to be constructed
     */
    abstract protected function onCreateClient($socket): ClientInterface;

    /**
     * Tick method - process all clients
     *
     * Reads from and writes to all connected clients. A failure that belongs to one
     * client is contained here - logged, the client closed and dropped - so the rest
     * of the connections keep ticking. Only a failure that means the node itself
     * cannot continue leaves this loop.
     * Should be called regularly in main loop.
     *
     * @throws RandomException When the secure random source refuses a handshake secret
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
            } catch (RandomException $refusal) {
                // The connection asked for a secret and the secure source refused. The
                // node cannot mint one for the next connection either, so this is its
                // business and not the connection's, and the decision to stop belongs to
                // the manager, which a server knows nothing about: rethrown past the
                // guard below, which would otherwise contain it and leave the node
                // serving handshakes whose secrets are guessable.
                throw $refusal;
            } catch (Throwable $exception) {
                // Everything else belongs to one client and ends here - logged, closed
                // and dropped - so the remaining connections keep ticking and a line
                // that does not parse cannot take the master process with it.
                //
                // The level tells the routine from the alarming. A transport that broke
                // and input that could not be read are the daily work of an open port:
                // the peer mesh drops links as a matter of course, and a port scanner
                // speaks HTTP at a WebSocket. An error line for each of those would
                // spend the level that is supposed to stand out when the node itself is
                // broken. The fields are the ones DaemonManager::onClientRead() writes,
                // in its order - the two readers of one client must be read as one thing
                // in the journal - while the wording names which of them wrote the line,
                // and the level split is this path's own: the epoll path still writes
                // every failure as an error.
                $entry = sprintf(
                    'Error in client tick for %s: %s in %s:%d - %s',
                    $this->getServerName(),
                    get_class($exception),
                    basename($exception->getFile()),
                    $exception->getLine(),
                    $exception->getMessage()
                );
                if ($exception instanceof SocketException || $exception instanceof MalformedInput) {
                    Logger::warning($entry);
                } else {
                    Logger::error($entry);
                }

                // If client has error, close it
                try {
                    $client->close();
                } catch (Throwable) {
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
