<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\Daemon\AbandonedCommandSink;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Client\Interface\HttpClientInterface;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;
use Hilos\Utils\Logger;

/**
 * HttpServer - HTTP server implementation.
 *
 * Manages HTTP server socket and accepts incoming connections.
 * Works with epoll in daemon main loop.
 *
 * Owns the held-request registry of the addresses agents answer
 * (docs/agents/architecture/agent-http-routes.md): a request routed to an agent parks its
 * {@see HttpClient} here by correlation id until the agent's reply arrives, which DaemonManager
 * delivers through {@see deliver()}. The command channel's twin ({@see CommandServer}).
 *
 * @extends AbstractServer<HttpClientInterface>
 */
class HttpServer extends AbstractServer
{
    /** @var array<string, HttpClient> Held HTTP clients awaiting an agent reply, keyed by correlation id */
    private array $heldRequests = [];

    /** @var ?AbandonedCommandSink Master seam told of a request nobody waits on any more, wired at registration */
    private ?AbandonedCommandSink $abandonedCommandSink = null;

    /**
     * Called when a new HTTP client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return HttpClientInterface Client instance
     * @throws EnvException When socket buffer or keep-alive env values are missing or invalid
     */
    protected function onCreateClient($socket): HttpClientInterface
    {
        return new HttpClient($socket, server: $this);
    }

    /**
     * Parks an HTTP client awaiting an agent reply, keyed by correlation id.
     *
     * @param string $correlationId Correlation id of the parked request
     * @param HttpClient $client Held client
     */
    public function hold(string $correlationId, HttpClient $client): void
    {
        $this->heldRequests[$correlationId] = $client;
    }

    /**
     * Drops a held HTTP client without delivering.
     *
     * @param string $correlationId Correlation id to drop
     */
    public function forget(string $correlationId): void
    {
        unset($this->heldRequests[$correlationId]);
    }

    /**
     * Drops a held HTTP client and tells the master nobody is waiting on it any more.
     *
     * The door for the browser closing the connection while its request is parked, as opposed
     * to {@see forget()}: only this lets the master drop a frame it holds for a starting agent
     * on the request's behalf (HIL-1040). There is no clock to give up on - a request that no
     * agent answers is ended by the client's own timeout, which is this same close.
     *
     * @param string $correlationId Correlation id nobody is waiting on any more
     */
    public function abandon(string $correlationId): void
    {
        $this->forget($correlationId);
        $this->abandonedCommandSink?->onCommandAbandoned($correlationId);
    }

    /**
     * Delivers an agent reply to the held HTTP client and drops it.
     *
     * A reply for a correlation id nobody holds - the browser left first - is written down
     * rather than dropped in silence, as {@see CommandServer::deliver()} does: it is the far end
     * of a trip that ended in silence for whoever asked.
     *
     * @param string $correlationId Correlation id of the originating request
     * @param HttpReplyDTO $reply Agent reply to write
     */
    public function deliver(string $correlationId, HttpReplyDTO $reply): void
    {
        $client = $this->heldRequests[$correlationId] ?? null;
        if ($client === null) {
            Logger::warning("HTTP: reply to #{$correlationId} arrived with nobody holding it");

            return;
        }

        unset($this->heldRequests[$correlationId]);
        $client->writeReply($reply);
    }

    /**
     * Wires the master seam told of a request whose browser stopped waiting.
     *
     * Set by {@see DaemonManager::registerServer()}; the same seam the command channel reports
     * its departed callers through, because the frame the master holds for a starting agent is
     * found by correlation id whichever channel parked it.
     *
     * @param AbandonedCommandSink $sink Master seam told of an abandoned correlation id
     */
    public function setAbandonedCommandSink(AbandonedCommandSink $sink): void
    {
        $this->abandonedCommandSink = $sink;
    }

    /**
     * Get server name for logging.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "HTTP Server";
    }

    /**
     * Prepare server for shutdown.
     *
     * Stops accepting new connections.
     */
    public function prepareShutdown(): void
    {
        parent::prepareShutdown();
    }

    /**
     * Check if server is ready to shutdown.
     *
     * HTTP server is ready when all clients have disconnected.
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
     * Clients should complete their requests and disconnect themselves.
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
     */
    protected function onStart(): void
    {
        // HTTP server has no specific startup logic
    }
}
