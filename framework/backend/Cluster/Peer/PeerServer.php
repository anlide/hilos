<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\ClusterRegistry;
use Hilos\Cluster\LocalNodeAnnouncer;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;

/**
 * Inter-daemon peer transport: listens for peer links and dials the seeds.
 *
 * Runs beside the worker and command servers on the daemon master loop. It
 * accepts inbound peer links from other nodes and, in the same non-blocking
 * onTick, dials the configured seeds to join an existing cluster; both
 * directions become framed {@see PeerLink} connections that exchange a
 * hello/welcome handshake, then gossip membership. Every socket operation here
 * is non-blocking, so the master loop is never stalled. The server owns the
 * membership side effects: it merges peers into the master registry and fans
 * out roster/announce gossip so every node's registry converges.
 *
 * @extends AbstractServer<PeerLink>
 */
final class PeerServer extends AbstractServer implements LocalNodeAnnouncer
{
    /** @var float Seconds to wait before retrying a failed or dropped seed dial */
    private const float DIAL_RETRY_INTERVAL_SEC = 5.0;

    /** @var float Seconds a non-blocking connect may stay pending before it is abandoned */
    private const float CONNECT_TIMEOUT_SEC = 5.0;

    /** @var NodeIdentity Local node identity announced to peers */
    private NodeIdentity $localIdentity;

    /** @var list<PeerAddress> Seed peers to dial on join */
    private array $seeds;

    /** @var array<int, PeerDial> Per-seed dial state, indexed by seed list position */
    private array $dials = [];

    /**
     * @param string $host Host to bind the peer listener
     * @param int $port Port to bind the peer listener
     * @param NodeIdentity $localIdentity Local node identity to announce to peers
     * @param list<PeerAddress> $seeds Seed peers to dial on join (empty for a bootstrap node)
     */
    public function __construct(string $host, int $port, NodeIdentity $localIdentity, array $seeds)
    {
        parent::__construct($host, $port);

        $this->localIdentity = $localIdentity;
        $this->seeds = $seeds;
    }

    /**
     * Registers as the local-node announcer and logs that the listener is open.
     */
    protected function onStart(): void
    {
        Hilos::$cluster?->registerLocalAnnouncer($this);
        Logger::info("Peer server listening as node {$this->localIdentity->nodeId}");
    }

    /**
     * Creates the accepting side of an inbound peer link.
     *
     * @param resource $socket Accepted peer socket
     * @return ClientInterface Peer link awaiting a hello
     * @throws EnvException When the socket read buffer env value is missing or invalid
     */
    protected function onCreateClient($socket): ClientInterface
    {
        return new PeerLink($socket, $this, $this->localIdentity, dialer: false);
    }

    /**
     * Get server name for logging.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return 'Peer Server';
    }

    /**
     * Drives the seed dials, then services all established links.
     */
    public function onTick(): void
    {
        $this->driveDials();

        parent::onTick();
    }

    /**
     * Closes in-flight connecting sockets, then stops the server.
     *
     * @throws SocketException When the server socket close fails
     */
    public function stop(): void
    {
        foreach ($this->dials as $dial) {
            if ($dial->socket !== null) {
                socket_close($dial->socket);
                $dial->socket = null;
                $dial->connecting = false;
            }
        }

        parent::stop();
    }

    /**
     * Advances the connect state machine for every configured seed.
     */
    private function driveDials(): void
    {
        if ($this->preparingShutdown) {
            return;
        }

        $now = microtime(true);
        foreach ($this->seeds as $index => $seed) {
            $dial = $this->dials[$index] ??= new PeerDial($seed);
            $this->driveDial($dial, $now);
        }
    }

    /**
     * Advances one seed dial: detect drops, poll a pending connect, or redial.
     *
     * @param PeerDial $dial Seed dial state
     * @param float $now Current microtime
     */
    private function driveDial(PeerDial $dial, float $now): void
    {
        // Linked: only act once the link has dropped out of the client set, then back off.
        if ($dial->link !== null) {
            if (!in_array($dial->link, $this->clients, true)) {
                $dial->link = null;
                $dial->nextAttemptAt = $now + self::DIAL_RETRY_INTERVAL_SEC;
            }
            return;
        }

        if ($dial->connecting) {
            $this->pollConnectingDial($dial, $now);
            return;
        }

        if ($now >= $dial->nextAttemptAt) {
            $this->beginDial($dial, $now);
        }
    }

    /**
     * Opens a non-blocking connect to a seed, promoting immediately if it lands.
     *
     * @param PeerDial $dial Seed dial state
     * @param float $now Current microtime
     */
    private function beginDial(PeerDial $dial, float $now): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $dial->nextAttemptAt = $now + self::DIAL_RETRY_INTERVAL_SEC;
            return;
        }

        socket_set_nonblock($socket);

        // A non-blocking connect returns false with EINPROGRESS; completion is polled next tick.
        if (@socket_connect($socket, $dial->seed->host, $dial->seed->port)) {
            $this->promoteDial($dial, $socket, $now);
            return;
        }

        $error = socket_last_error($socket);
        if (in_array($error, [SOCKET_EINPROGRESS, SOCKET_EALREADY, SOCKET_EWOULDBLOCK], true)) {
            socket_clear_error($socket);
            $dial->socket = $socket;
            $dial->connecting = true;
            $dial->connectStartedAt = $now;
            return;
        }

        socket_close($socket);
        $dial->nextAttemptAt = $now + self::DIAL_RETRY_INTERVAL_SEC;
    }

    /**
     * Polls a pending connect for completion, timeout, or failure.
     *
     * @param PeerDial $dial Seed dial state
     * @param float $now Current microtime
     */
    private function pollConnectingDial(PeerDial $dial, float $now): void
    {
        $socket = $dial->socket;
        if ($socket === null) {
            $dial->connecting = false;
            return;
        }

        if (($now - $dial->connectStartedAt) >= self::CONNECT_TIMEOUT_SEC) {
            $this->abortDial($dial, $now);
            return;
        }

        $read = null;
        $write = [$socket];
        $except = null;
        $ready = @socket_select($read, $write, $except, 0);
        if ($ready === false || $ready === 0) {
            // Still connecting; re-check on a later tick.
            return;
        }

        if (socket_get_option($socket, SOL_SOCKET, SO_ERROR) !== 0) {
            $this->abortDial($dial, $now);
            return;
        }

        $this->promoteDial($dial, $socket, $now);
    }

    /**
     * Wraps a connected socket in a dialing peer link and starts the handshake.
     *
     * @param PeerDial $dial Seed dial state
     * @param resource|object $socket Connected socket
     * @param float $now Current microtime
     */
    private function promoteDial(PeerDial $dial, $socket, float $now): void
    {
        socket_set_nonblock($socket);

        try {
            $link = new PeerLink($socket, $this, $this->localIdentity, dialer: true);
        } catch (EnvException $e) {
            Logger::error("Failed to open dialed peer link: {$e->getMessage()}");
            socket_close($socket);
            $this->abortDial($dial, $now);
            return;
        }

        $link->startHandshake();
        $this->clients[] = $link;

        $dial->socket = null;
        $dial->connecting = false;
        $dial->link = $link;
        Logger::info("Dialed seed {$dial->seed->host}:{$dial->seed->port}");
    }

    /**
     * Closes a failed connect socket and schedules a retry.
     *
     * @param PeerDial $dial Seed dial state
     * @param float $now Current microtime
     */
    private function abortDial(PeerDial $dial, float $now): void
    {
        if ($dial->socket !== null) {
            socket_close($dial->socket);
        }

        $dial->socket = null;
        $dial->connecting = false;
        $dial->nextAttemptAt = $now + self::DIAL_RETRY_INTERVAL_SEC;
    }

    /**
     * Records a freshly handshaked peer, bootstraps it with the local roster, and
     * announces it to the other links.
     *
     * @param PeerLink $link Link that just completed its handshake
     * @param NodeIdentity $remote Remote node identity learned from the handshake
     */
    public function onHandshakeComplete(PeerLink $link, NodeIdentity $remote): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        $changed = $registry->merge($remote, true, microtime(true));
        $this->sendRoster($link, $registry);

        if ($changed) {
            $this->broadcastAnnounce(PeerNodeEntry::fromIdentity($remote, true), $link);
        }
    }

    /**
     * Merges a received roster and re-announces every entry that was new to us.
     *
     * @param PeerLink $link Link the roster arrived on
     * @param PeerRosterDTO $roster Received roster
     */
    public function onRosterReceived(PeerLink $link, PeerRosterDTO $roster): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        $now = microtime(true);
        foreach ($roster->nodes as $entry) {
            if ($this->mergeEntry($registry, $entry, $now)) {
                $this->broadcastAnnounce($entry, $link);
            }
        }
    }

    /**
     * Merges a received announcement and re-announces it only when it changed us.
     *
     * @param PeerLink $link Link the announcement arrived on
     * @param PeerAnnounceDTO $announce Received announcement
     */
    public function onAnnounceReceived(PeerLink $link, PeerAnnounceDTO $announce): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        if ($this->mergeEntry($registry, $announce->node, microtime(true))) {
            $this->broadcastAnnounce($announce->node, $link);
        }
    }

    /**
     * Marks the closed link's peer offline and announces the leave to the others.
     *
     * @param PeerLink $link Link that closed
     */
    public function onLinkClosed(PeerLink $link): void
    {
        $remote = $link->remoteIdentity();
        if ($remote === null) {
            return;
        }

        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        if ($registry->markOffline($remote->nodeId, microtime(true))) {
            $this->broadcastAnnounce(PeerNodeEntry::fromIdentity($remote, false), $link);
        }
    }

    /**
     * Merges one gossip entry, ignoring any entry that describes the local node.
     *
     * The local node is authoritative about itself, so its own record is never
     * overwritten from a peer's view of it.
     *
     * @param ClusterRegistry $registry Master registry
     * @param PeerNodeEntry $entry Received node entry
     * @param float $now Current microtime
     * @return bool True when the entry changed the membership meaningfully
     */
    private function mergeEntry(ClusterRegistry $registry, PeerNodeEntry $entry, float $now): bool
    {
        if ($entry->nodeId === $this->localIdentity->nodeId) {
            return false;
        }

        return $registry->merge($entry->toIdentity(), $entry->online, $now);
    }

    /**
     * Sends the local membership roster to one link.
     *
     * @param PeerLink $link Link to bootstrap
     * @param ClusterRegistry $registry Master registry
     */
    private function sendRoster(PeerLink $link, ClusterRegistry $registry): void
    {
        $entries = array_map(
            static fn(ClusterNode $node): PeerNodeEntry => PeerNodeEntry::fromNode($node),
            $registry->snapshot(),
        );

        $link->sendFrame(new PeerRosterDTO($entries));
    }

    /**
     * Re-announces the local node's current registry record to every peer.
     *
     * Invoked by the control-plane reload after the local role, capabilities, or
     * address were refreshed: it reads the freshly-merged local record from the
     * master registry and gossips it to all handshaked links so peers converge on
     * the new identity. A no-op when the registry or the local record is absent.
     */
    public function announceLocalNode(): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        foreach ($registry->snapshot() as $node) {
            if ($node->nodeId === $this->localIdentity->nodeId) {
                $this->broadcastAnnounce(PeerNodeEntry::fromNode($node));
                return;
            }
        }
    }

    /**
     * Announces one node entry to every handshaked link, optionally skipping its source.
     *
     * @param PeerNodeEntry $entry Node entry to announce
     * @param ?PeerLink $source Link the change came from, excluded from the fan-out; null fans out to all
     */
    private function broadcastAnnounce(PeerNodeEntry $entry, ?PeerLink $source = null): void
    {
        $announce = new PeerAnnounceDTO($entry);
        foreach ($this->clients as $client) {
            if ($client === $source || $client->remoteIdentity() === null) {
                continue;
            }

            $client->sendFrame($announce);
        }
    }

    /**
     * Returns the master cluster registry, or null when it is unavailable.
     *
     * A registry hiccup must not tear down the daemon loop, so any failure is
     * logged and swallowed rather than propagated.
     *
     * @return ?ClusterRegistry Master registry, or null on failure
     */
    private function registry(): ?ClusterRegistry
    {
        try {
            return Hilos::$cluster?->registry();
        } catch (\Throwable $e) {
            Logger::warning("Cluster registry unavailable: {$e->getMessage()}");
            return null;
        }
    }
}
