<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\ConnectionDropper;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\DaemonStatusSource;
use Hilos\Core\Daemon\ProtectedModeSnapshotSource;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Client\Interface\CommandClientInterface;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;

/**
 * CommandServer - socket server for the CLI command channel.
 *
 * Accepts CLI connections that speak newline-delimited JSON
 * {@see CommandRequestDTO} /
 * {@see CommandReplyDTO}. A dedicated control-plane transport, separate from the
 * HTTP status endpoint and the WebSocket server, so CLI traffic never rides a web
 * protocol. Registered like any other server via
 * {@see DaemonManager::registerServer()}.
 *
 * Owns the held-request registry: a command routed to an agent parks its
 * {@see CommandClient} here by correlation id until the agent's reply arrives,
 * which DaemonManager delivers through {@see deliver()}.
 *
 * @extends AbstractServer<CommandClientInterface>
 */
class CommandServer extends AbstractServer
{
    /** @var array<string, CommandClient> Held command clients awaiting an agent reply, keyed by correlation id */
    private array $heldRequests = [];

    /** @var ?ConnectionDropper Master seam that force-closes a WebSocket connection, wired at registration */
    private ?ConnectionDropper $connectionDropper = null;

    /** @var ?ProtectedModeSnapshotSource Master seam that reports the protected-mode state, wired at registration */
    private ?ProtectedModeSnapshotSource $protectedModeSnapshotSource = null;

    /** @var ?DaemonStatusSource Master seam that samples this daemon's runtime status, wired at registration */
    private ?DaemonStatusSource $daemonStatusSource = null;

    /**
     * Called when a new command client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return CommandClientInterface Client instance
     * @throws EnvException When socket read buffer env value is missing or invalid
     */
    protected function onCreateClient($socket): CommandClientInterface
    {
        return new CommandClient($socket, $this);
    }

    /**
     * Get server name for logging.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return 'Command Server';
    }

    /**
     * Parks a command client awaiting an agent reply, keyed by correlation id.
     *
     * @param string $correlationId Correlation id of the pending request
     * @param CommandClient $client Held client
     */
    public function hold(string $correlationId, CommandClient $client): void
    {
        $this->heldRequests[$correlationId] = $client;
    }

    /**
     * Drops a held command client without delivering (timeout or disconnect).
     *
     * @param string $correlationId Correlation id to drop
     */
    public function forget(string $correlationId): void
    {
        unset($this->heldRequests[$correlationId]);
    }

    /**
     * Delivers an agent reply to the held command client and drops it.
     *
     * A reply for a correlation id nobody holds is written down rather than dropped in silence
     * (HIL-1000). It is not an error here - the request was dropped by {@see forget()} when its
     * caller gave up or its window ran out - but it is the far end of exactly the trip that
     * ended in silence for whoever asked, and without this line that trip has no last entry at
     * all: the answer arrived, and the account of it stopped where the holder used to be.
     *
     * @param string $correlationId Correlation id of the originating request
     * @param CommandReplyDTO $reply Agent reply to write
     */
    public function deliver(string $correlationId, CommandReplyDTO $reply): void
    {
        $client = $this->heldRequests[$correlationId] ?? null;
        if ($client === null) {
            Logger::warning("Command channel: reply to #{$correlationId} arrived with nobody holding it");

            return;
        }

        unset($this->heldRequests[$correlationId]);
        $client->writeReply($reply);
    }

    /**
     * Wires the master seam used to force-close a WebSocket connection.
     *
     * Set by {@see DaemonManager::registerServer()} so the test-only drop command can reach
     * the master-owned WebSocket clients through the command channel.
     *
     * @param ConnectionDropper $connectionDropper Master seam that force-closes a connection
     */
    public function setConnectionDropper(ConnectionDropper $connectionDropper): void
    {
        $this->connectionDropper = $connectionDropper;
    }

    /**
     * Force-closes the live WebSocket connection with the given acceptKey through the master seam.
     *
     * Returns false when no dropper is wired, so a command handler can report the request
     * as a no-op rather than fail when the daemon exposes no WebSocket server.
     *
     * @param string $acceptKey Daemon-minted identifier of the connection to close
     * @return bool True when a matching live connection was found and closed, false otherwise
     * @throws SocketException When closing the matched connection's socket fails
     * @throws HilosException When the matched connection fails to announce its close
     */
    public function dropWebSocketConnection(string $acceptKey): bool
    {
        return $this->connectionDropper?->dropWebSocketConnection($acceptKey) ?? false;
    }

    /**
     * Wires the master seam used to report this node's protected-mode state.
     *
     * Set by {@see DaemonManager::registerServer()} so the test-only inspect command can read
     * the master-owned freeze state through the command channel.
     *
     * @param ProtectedModeSnapshotSource $protectedModeSnapshotSource Master seam reporting the freeze state
     */
    public function setProtectedModeSnapshotSource(ProtectedModeSnapshotSource $protectedModeSnapshotSource): void
    {
        $this->protectedModeSnapshotSource = $protectedModeSnapshotSource;
    }

    /**
     * Reports this node's protected-mode state through the master seam.
     *
     * Answers an empty snapshot when no source is wired, so a daemon that exposes no such
     * seam reads as "nothing to report" rather than failing the command: the caller then
     * sees the absent {@see ProtectedModeCommandConstants::FIELD_RT_MOUNTED} flag and knows
     * the subsystem is not there, which is the same distinction the flag draws inside a
     * wired snapshot.
     *
     * @return array<string, mixed> Snapshot keyed by {@see ProtectedModeCommandConstants} fields
     */
    public function protectedModeSnapshot(): array
    {
        return $this->protectedModeSnapshotSource?->protectedModeSnapshot() ?? [];
    }

    /**
     * Wires the master seam used to sample this daemon's runtime status.
     *
     * Set by {@see DaemonManager::registerServer()} so the `daemon:status` command can read the
     * master-owned uptime, memory, CPU and worker counts through the command channel.
     *
     * @param DaemonStatusSource $daemonStatusSource Master seam sampling the daemon status
     */
    public function setDaemonStatusSource(DaemonStatusSource $daemonStatusSource): void
    {
        $this->daemonStatusSource = $daemonStatusSource;
    }

    /**
     * Samples this daemon's runtime status through the master seam.
     *
     * Answers null when no source is wired, so the command branch can refuse in words instead of
     * inventing a status. This is where it parts from {@see protectedModeSnapshot()}, whose empty
     * array reads as "this installation has no such subsystem": a daemon always has an uptime and
     * a memory figure, so an empty status would not be a verdict but a lie.
     *
     * @return ?DaemonStatusDTO Fresh status sample, or null when no source is wired
     */
    public function daemonStatusSnapshot(): ?DaemonStatusDTO
    {
        return $this->daemonStatusSource?->daemonStatusSnapshot();
    }

    /**
     * Check if server is ready to shutdown.
     *
     * Ready once all command clients have disconnected.
     *
     * @return bool True when no clients are connected
     */
    public function isReadyToShutdown(): bool
    {
        return empty($this->clients);
    }

    /**
     * Called when server is started.
     */
    protected function onStart(): void
    {
        // Command server has no specific startup logic
    }
}
