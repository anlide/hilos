<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Closure;
use Hilos\Constants\TimeConstants;
use Generator;
use Hilos\Constants\SocketConstants;
use Hilos\Core\Daemon\ClientSocketDetacher;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\ContainedFailureSink;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Daemon\SignalHoldIndex;
use Hilos\HilosException;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;
use Hilos\Utils\ClientReadFailureLog;
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

    /** @var ?ContainedFailureSink Master seam a contained failure is reported through, wired at registration */
    private ?ContainedFailureSink $containedFailureSink = null;

    /** @var ?ClientSocketDetacher Master seam a departing client is announced to, wired at registration */
    private ?ClientSocketDetacher $clientSocketDetacher = null;

    /** @var ?Closure(ClientInterface): void One loop binding for accepted and dialed clients */
    private ?Closure $clientSocketAttacher = null;

    /** @var array<int, int> Transport identity to its stable local roster slot */
    private array $clientSlots = [];

    private int $clientSequence = 0;

    private ?SignalHoldIndex $clientIndex = null;

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
     * Wires the seam a contained failure of this server is reported through.
     *
     * Held by the server rather than reached through the manager, for the reason
     * {@see WebSocketServer::setConnectionDropper()} is: a server knows the narrow door
     * it needs and nothing about who is behind it.
     *
     * @param ContainedFailureSink $sink Master seam a contained failure is handed to
     */
    public function setContainedFailureSink(ContainedFailureSink $sink): void
    {
        $this->containedFailureSink = $sink;
    }

    /**
     * Wires the seam a departing client's socket is taken off the master's watch through.
     *
     * Held by the server for the same reason the sink above is: the server knows it must
     * announce a departure before closing, and nothing about who watches on the far side.
     * A server that was never handed one ticks exactly as it did before there was a seam,
     * and leaks nothing by it - registration is the only way its sockets reach the watch.
     *
     * @param ClientSocketDetacher $detacher Master seam a departing client is announced to
     */
    public function setClientSocketDetacher(ClientSocketDetacher $detacher): void
    {
        $this->clientSocketDetacher = $detacher;
    }

    /**
     * @param Closure(ClientInterface): void $attacher Bind accepted and dialed clients before their first handshake
     */
    public function setClientSocketAttacher(Closure $attacher): void
    {
        $this->clientSocketAttacher = $attacher;
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
     * Captures a finite scalar tail before returning, without retaining transport snapshots.
     * Departed slots are skipped; later transports cannot enter this original cohort.
     *
     * @return Generator<int, ?TClient> Still-live original slots in connection order
     */
    public function clientCandidates(): Generator
    {
        return $this->walkClients(($this->clientIndex ??= new SignalHoldIndex())->candidates());
    }

    /**
     * @param int $slot Original captured roster slot
     * @param ClientInterface $client Original captured connection
     * @return bool Whether that exact transport still occupies its original live slot
     */
    public function isCurrentClient(int $slot, ClientInterface $client): bool
    {
        return ($this->clients[$slot] ?? null) === $client && !$client->shouldClose();
    }

    /** @return string Host the server was configured to bind */
    public function getHost(): string
    {
        return $this->host;
    }

    /** @return int Port the server was configured to bind */
    public function getPort(): int
    {
        return $this->port;
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
            $this->dropClient($client);
        }
        $this->clients = [];

        $this->closeListener();
    }

    /**
     * Releases only the entrance; client disposal and worker stop waves have separate owners.
     * The master removes the exact listener watch before entering this door.
     */
    public function closeListener(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
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
        $identity = spl_object_id($client);
        $slot = $this->clientSlots[$identity] ?? null;
        if ($slot !== null && ($this->clients[$slot] ?? null) === $client) {
            $this->clientIndex?->remove($slot);
            unset($this->clients[$slot], $this->clientSlots[$identity]);
        }
    }

    /**
     * The one way a client leaves this server: off the watch, closed, then forgotten.
     *
     * The detach is deliberately outside the try - if it fails, the close must not
     * happen, because a closed socket under a live event is the very thing this door
     * exists to prevent. The removal is in finally instead, so a client whose close
     * throws does not stay in the list and keep being ticked.
     *
     * @param ClientInterface $client Client to drop
     * @throws SocketException When closing the client's socket fails
     * @throws HilosException When the client fails to announce its close
     */
    public function dropClient(ClientInterface $client): void
    {
        $client->detachEventLoop();
        $this->clientSocketDetacher?->detachClientSocket($client);

        try {
            $client->close();
        } finally {
            $this->removeClient($client);
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
        $this->addClient($client);
        $this->attachClient($client);

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

    /** @param TClient $client Newly accepted transport, registered before its first handshake or I/O binding */
    protected function addClient(ClientInterface $client): void
    {
        $slot = $this->clientSequence++;
        $this->clients[$slot] = $client;
        $this->clientSlots[spl_object_id($client)] = $slot;
        ($this->clientIndex ??= new SignalHoldIndex())->add($slot);
    }

    /**
     * @param ClientInterface $client Newly accepted or dialed client
     */
    protected function attachClient(ClientInterface $client): void
    {
        if ($this->clientSocketAttacher !== null) {
            ($this->clientSocketAttacher)($client);
        }
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
     * Hands a failure this server contained to the master's seam.
     *
     * The one door a server subclass reports through, so the sink stays private to this class
     * and a subclass containing a failure of its own - an agent start, say - tells the project
     * the same way the tick does. A server that was never wired behaves as if nobody listened.
     *
     * @param ContainedFailure $failure Failure, the unit it belongs to and where it happened
     */
    protected function reportContainedFailure(ContainedFailure $failure): void
    {
        $this->containedFailureSink?->reportContainedFailure($failure);
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
     * client is contained here - logged and the client dropped - so the rest of the
     * connections keep ticking. Only a failure that means the node itself cannot
     * continue leaves this loop. Either way the client leaves through the one door,
     * {@see dropClient()}, and leaves at once rather than after the walk.
     * Should be called regularly in main loop.
     *
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws HilosException Whatever the concrete server's own tick raises
     */
    public function onTick(): void
    {
        if ($this->clientSocketAttacher !== null) {
            return;
        }
        foreach ($this->clients as $client) {
            try {
                $client->read();

                // Call client tick method
                $client->onTick();

                // Write to client
                $client->write();

                // Check if client should be closed
                if ($client->shouldClose()) {
                    $this->dropClient($client);
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
                // The line, the level it goes in at and the limit on repeats belong to
                // the other reader of the same client as much as to this one, so they
                // live in one place and this path only says which reader it is.
                ClientReadFailureLog::write(
                    $this->getServerName(),
                    ClientReadFailureLog::READER_TICK,
                    $exception,
                    hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND
                );

                // Told after the line and before the close: the project answers a
                // connection that is still one, and a sink that was never wired leaves
                // the tick behaving exactly as it did before there was one.
                $this->reportContainedFailure(new ContainedFailure(
                    MasterFailureUnit::CONNECTION,
                    ClientReadFailureLog::connectionAddress($this->getServerName(), $client),
                    $exception
                ));

                // If client has error, drop it
                try {
                    $this->dropClient($client);
                } catch (Throwable) {
                    // Ignore errors during close
                }
            }
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

    /**
     * @param Generator<int, int> $slots Finite scalar roster identities captured by clientCandidates()
     * @return Generator<int, ?TClient> Still-live original connections, or null while closing
     */
    protected function walkClients(Generator $slots): Generator
    {
        foreach ($slots as $slot) {
            $client = $this->clients[$slot] ?? null;
            yield $slot => $client !== null && $this->isCurrentClient($slot, $client) ? $client : null;
            unset($client);
        }
    }
}
