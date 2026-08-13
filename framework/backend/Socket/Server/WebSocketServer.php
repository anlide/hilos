<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\Daemon\ConnectionDropper;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;

/**
 * WebSocketServer - WebSocket server implementation.
 *
 * Manages WebSocket server socket and accepts incoming connections.
 * Works with epoll in daemon main loop.
 *
 * This is an abstract class - child classes must implement onCreateClient()
 * to create concrete WebSocketClient instances.
 *
 * @extends AbstractServer<WebSocketClientInterface>
 */
abstract class WebSocketServer extends AbstractServer
{
    /** @var ?ConnectionDropper Master seam that force-closes a connection, wired at registration */
    private ?ConnectionDropper $connectionDropper = null;

    /**
     * Called when a new WebSocket client connection is accepted.
     *
     * Must be implemented by child classes to create concrete WebSocketClient.
     *
     * @param resource $socket Client socket
     * @return WebSocketClientInterface Client instance
     */
    abstract protected function onCreateClient($socket): WebSocketClientInterface;

    /**
     * Wires the seam that force-closes a connection, for the clients this server accepts.
     *
     * Held by the server rather than passed to {@see onCreateClient()} because that method
     * is the project's: a project builds its own client subclass, and none of them should
     * have to carry a daemon seam through their constructor to get framework behaviour.
     *
     * @param ConnectionDropper $connectionDropper Master seam that force-closes a connection
     */
    public function setConnectionDropper(ConnectionDropper $connectionDropper): void
    {
        $this->connectionDropper = $connectionDropper;
    }

    /**
     * Accepts a connection and hands the new client the connection-drop seam.
     *
     * A client needs it for exactly one thing: dropping the other connections of a session
     * whose token it just rotated (HIL-582). It is given here, at accept, rather than looked
     * up at the moment of use, so the client never reaches back into the daemon.
     *
     * @return ?ClientInterface New client or null when no connection is pending
     * @throws SocketException When socket operations fail
     */
    public function acceptConnection(): ?ClientInterface
    {
        $client = parent::acceptConnection();
        if ($client instanceof WebSocketClient && $this->connectionDropper !== null) {
            $client->setConnectionDropper($this->connectionDropper);
        }

        return $client;
    }

    /**
     * Get server name for logging.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "WebSocket Server";
    }

    /**
     * Prepare server for shutdown.
     *
     * Stops accepting new connections.
     * Closing all connected clients.
     */
    public function prepareShutdown(): void
    {
        parent::prepareShutdown();

        foreach ($this->clients as $client) {
            $client->markShouldClose();
        }
    }

    /**
     * Check if server is ready to shutdown.
     *
     * WebSocket server is ready when all clients have disconnected.
     *
     * @return bool True if ready to shutdown
     */
    public function isReadyToShutdown(): bool
    {
        // Ready when no clients are connected
        return empty($this->clients);
    }

    /**
     * Stop server.
     *
     * Closes server socket only. Does NOT close client connections.
     * Clients should complete their sessions and disconnect themselves.
     *
     * @throws SocketException If socket close fails
     */
    public function stop(): void
    {
        // Close server socket only, don't close client connections
        if ($this->socket !== null) {
            socket_close($this->socket);
            // Check for errors during close
            $this->handleSocketError(SocketOperation::CLOSE);
            $this->socket = null;
        }

        $this->isRunning = false;
    }

    /**
     * Called when server is started.
     *
     * Must be implemented by child classes to perform actions when server starts.
     */
    abstract protected function onStart(): void;
}
