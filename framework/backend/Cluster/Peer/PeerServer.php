<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\ClusterRegistry;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\LocalNodeAnnouncer;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;

/**
 * Inter-daemon peer transport: accepts peer links and dials out to form the mesh.
 *
 * Runs beside the worker and command servers on the daemon master loop. It
 * accepts inbound peer links from other nodes and, in the same non-blocking
 * onTick, dials out to form direct links: the configured seeds to join an
 * existing cluster, and — driven by a {@see ConnectionPolicy}, full mesh by
 * default — every peer learned through gossip, so two nodes that only know each
 * other transitively still raise a direct link. Both directions become framed
 * {@see PeerLink} connections that exchange a hello/welcome handshake, then
 * gossip membership. Every socket operation here is non-blocking, so the master
 * loop is never stalled. The server owns the membership side effects: it merges
 * peers into the master registry and fans out roster/announce gossip so every
 * node's registry converges. Knowing a peer (registry + gossip) stays separate
 * from dialing one (the policy): a later partial-mesh is a policy swap alone.
 *
 * On a clustered master the server also hosts the {@see ClusterCoordinator}: it
 * builds it at start, drives its tick each onTick, routes the consensus frames
 * (request-vote, vote-reply, heartbeat) into it, and serves as its
 * {@see ConsensusMesh} — turning the master registry into a liveness view and the
 * live links into an outbound channel. A slave keeps no coordinator.
 *
 * @extends AbstractServer<PeerLink>
 */
final class PeerServer extends AbstractServer implements LocalNodeAnnouncer, ConsensusMesh
{
    /** @var float Seconds to wait before retrying a failed or dropped seed dial */
    private const float DIAL_RETRY_INTERVAL_SEC = 5.0;

    /** @var float Seconds a non-blocking connect may stay pending before it is abandoned */
    private const float CONNECT_TIMEOUT_SEC = 5.0;

    /** @var NodeIdentity Local node identity announced to peers */
    private NodeIdentity $localIdentity;

    /** @var list<PeerAddress> Seed peers to dial on join */
    private array $seeds;

    /** @var ConnectionPolicy Decides which known peers to dial a direct link to */
    private ConnectionPolicy $connectionPolicy;

    /** @var array<int, PeerDial> Per-seed dial state, indexed by seed list position */
    private array $seedDials = [];

    /** @var array<string, PeerDial> Dial-on-learn state for gossip-learned peers, keyed by node id */
    private array $peerDials = [];

    /** @var ?ClusterCoordinator Consensus coordinator, built at start for a master node; null for a slave */
    private ?ClusterCoordinator $coordinator = null;

    /**
     * @param string $host Host to bind the peer listener
     * @param int $port Port to bind the peer listener
     * @param NodeIdentity $localIdentity Local node identity to announce to peers
     * @param list<PeerAddress> $seeds Seed peers to dial on join (empty for a bootstrap node)
     * @param ?ConnectionPolicy $connectionPolicy Policy choosing which known peers to dial; full mesh when null
     */
    public function __construct(string $host, int $port, NodeIdentity $localIdentity, array $seeds, ?ConnectionPolicy $connectionPolicy = null)
    {
        parent::__construct($host, $port);

        $this->localIdentity = $localIdentity;
        $this->seeds = $seeds;
        $this->connectionPolicy = $connectionPolicy ?? new FullMeshConnectionPolicy();
    }

    /**
     * Registers as the local-node announcer and, on a master, starts consensus.
     *
     * A clustered master builds its {@see ClusterCoordinator} here and installs it as
     * the leadership seam, so election and quorum results begin flowing the moment
     * the listener is open; a slave takes no part in consensus and keeps none.
     *
     * @throws ClusterConfigurationException When the master consensus config is missing or invalid
     * @throws EnvException When a consensus env value cannot be read
     */
    protected function onStart(): void
    {
        Hilos::$cluster?->registerLocalAnnouncer($this);

        if ($this->localIdentity->role === NodeRole::Master && Hilos::$cluster !== null) {
            $this->coordinator = new ClusterCoordinator(
                ClusterConsensusConfig::fromEnv($this->localIdentity),
                $this,
                Hilos::$cluster->leadershipObserver(),
            );
            Hilos::$cluster->registerLeadership($this->coordinator);
        }

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
     * Drives the seed dials, services all links, then advances consensus.
     *
     * The coordinator ticks after the links are serviced so any request-vote,
     * vote-reply, or heartbeat that arrived this iteration is already folded in
     * before it recomputes deadlines, quorum, and leadership.
     */
    public function onTick(): void
    {
        $this->driveDials();

        parent::onTick();

        $this->coordinator?->tick(microtime(true));
    }

    /**
     * Closes in-flight connecting sockets, then stops the server.
     *
     * @throws SocketException When the server socket close fails
     */
    public function stop(): void
    {
        foreach ($this->allDials() as $dial) {
            if ($dial->socket !== null) {
                socket_close($dial->socket);
                $dial->socket = null;
                $dial->connecting = false;
            }
        }

        parent::stop();
    }

    /**
     * Ensures a dial for every seed and every policy-selected peer, then advances
     * each one through the connect state machine.
     */
    private function driveDials(): void
    {
        if ($this->preparingShutdown) {
            return;
        }

        foreach ($this->seeds as $index => $seed) {
            $this->seedDials[$index] ??= new PeerDial($seed);
        }
        $this->reconcilePeerDials();

        $now = microtime(true);
        foreach ($this->allDials() as $dial) {
            $this->driveDial($dial, $now);
        }
    }

    /**
     * Turns membership knowledge into dial intent for the mesh.
     *
     * Reads the master registry — the source of truth for who is in the cluster —
     * and, for every known node the {@see ConnectionPolicy} selects, lazily opens a
     * dial toward its advertised address keyed by node id. This is the single point
     * where knowing a peer becomes dialing it, so a partial-mesh topology is a
     * policy swap here and nothing else; the registry and gossip stay untouched.
     * The node id is stamped on the dial up front so an inbound link to the same
     * peer suppresses the outbound dial through {@see driveDial()}. A peer with no
     * advertised address is left to reach us inbound; a peer's address is captured
     * once (address churn is HIL-183).
     */
    private function reconcilePeerDials(): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        foreach ($registry->snapshot() as $node) {
            if ($node->address === null || isset($this->peerDials[$node->nodeId])) {
                continue;
            }

            if (!$this->connectionPolicy->shouldDial($this->localIdentity, $node)) {
                continue;
            }

            $dial = new PeerDial($node->address);
            $dial->remoteNodeId = $node->nodeId;
            $this->peerDials[$node->nodeId] = $dial;
        }
    }

    /**
     * Returns every active dial, seed and dial-on-learn alike.
     *
     * @return list<PeerDial> All dials the server is driving
     */
    private function allDials(): array
    {
        return array_merge(array_values($this->seedDials), array_values($this->peerDials));
    }

    /**
     * Advances one seed dial: detect drops, poll a pending connect, or redial.
     *
     * @param PeerDial $dial Seed dial state
     * @param float $now Current microtime
     */
    private function driveDial(PeerDial $dial, float $now): void
    {
        // Our own dialed link is still alive (handshaking or established): leave it be.
        // A link discarded by the duplicate collapse is marked to close, so it counts as dropped.
        if ($dial->link !== null) {
            if (in_array($dial->link, $this->clients, true) && !$dial->link->shouldClose()) {
                return;
            }
            $dial->link = null;
            $dial->nextAttemptAt = $now + self::DIAL_RETRY_INTERVAL_SEC;
        }

        // The peer at this seed already reaches us over another link (an inbound dial, or the
        // link that won a duplicate collapse): do not open a second connection to the same node.
        if ($dial->remoteNodeId !== null && $this->hasHandshakedLinkToNode($dial->remoteNodeId)) {
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
        if (@socket_connect($socket, $dial->address->host, $dial->address->port)) {
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
        Logger::info($dial->remoteNodeId !== null
            ? "Dialed peer {$dial->remoteNodeId}"
            : "Dialed seed {$dial->address->host}:{$dial->address->port}");
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

        $this->stampDialRemote($link, $remote->nodeId);

        if ($this->collapseDuplicateLink($link, $remote->nodeId)) {
            // This link lost the tie-break; the surviving link already owns the peer.
            return;
        }

        $now = microtime(true);
        $changed = $registry->merge($remote, true, $now);
        $this->sendRoster($link, $registry);

        if ($changed) {
            $this->notifyJoined($remote, $now);
            $this->broadcastAnnounce(PeerNodeEntry::fromIdentity($remote, true), $link);
        }
    }

    /**
     * Records the peer's node id on the seed dial that owns this link, if any.
     *
     * Inbound links have no dial; for a dialed link this lets the seed recognise
     * the peer later and stop re-dialing it once it is reachable another way.
     *
     * @param PeerLink $link Link that just handshaked
     * @param string $nodeId Remote node id learned from the handshake
     */
    private function stampDialRemote(PeerLink $link, string $nodeId): void
    {
        $dial = $this->dialForLink($link);
        if ($dial !== null) {
            $dial->remoteNodeId = $nodeId;
        }
    }

    /**
     * Collapses a second connection to an already-linked peer down to one link.
     *
     * A simultaneous bootstrap leaves each node with a dialed and an accepted link
     * to the same peer. Both nodes apply the shared tie-break
     * ({@see PeerProtocol::dialedLinkWinsTieBreak()}) and drop the same connection,
     * so exactly one survives on each end. The loser is discarded silently, leaving
     * the peer online over the survivor. Returns true when the just-handshaked link
     * is the one discarded.
     *
     * @param PeerLink $link Link that just handshaked
     * @param string $remoteNodeId Remote node id learned from the handshake
     * @return bool True when this link lost the tie-break and was discarded
     */
    private function collapseDuplicateLink(PeerLink $link, string $remoteNodeId): bool
    {
        $existing = $this->findHandshakedLinkToNode($remoteNodeId, $link);
        if ($existing === null) {
            return false;
        }

        $dialedWins = PeerProtocol::dialedLinkWinsTieBreak($this->localIdentity->nodeId, $remoteNodeId);
        $keep = $link->isDialer() === $dialedWins ? $link : $existing;
        $drop = $keep === $link ? $existing : $link;

        $drop->discardAsDuplicate();
        Logger::info("Collapsed duplicate peer link to {$remoteNodeId}");

        return $drop === $link;
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

        $now = microtime(true);
        if ($this->mergeEntry($registry, $announce->node, $now)) {
            $identity = $announce->node->toIdentity();
            if ($announce->node->online) {
                $this->notifyJoined($identity, $now);
            } else {
                $this->notifyLeft($identity, $now);
            }
            $this->broadcastAnnounce($announce->node, $link);
        }
    }

    /**
     * Routes a received request-vote to the consensus coordinator, if one runs.
     *
     * A no-op on a slave (no coordinator) or before the coordinator is built; the
     * coordinator answers by sending a vote-reply back through this server's mesh.
     *
     * @param PeerRequestVoteDTO $frame Received request-vote frame
     */
    public function onRequestVote(PeerRequestVoteDTO $frame): void
    {
        $this->coordinator?->onRequestVote($frame);
    }

    /**
     * Routes a received vote-reply to the consensus coordinator, if one runs.
     *
     * @param PeerVoteReplyDTO $frame Received vote-reply frame
     */
    public function onVoteReply(PeerVoteReplyDTO $frame): void
    {
        $this->coordinator?->onVoteReply($frame);
    }

    /**
     * Routes a received heartbeat to the consensus coordinator, if one runs.
     *
     * @param PeerHeartbeatDTO $frame Received heartbeat frame
     */
    public function onHeartbeat(PeerHeartbeatDTO $frame): void
    {
        $this->coordinator?->onHeartbeat($frame);
    }

    /**
     * Marks the closed link's peer offline and announces the leave to the others.
     *
     * A peer can briefly hold two links to us — during a simultaneous-bootstrap
     * collapse, or any transient reconnect overlap — so a close only means the peer
     * departed when it was the last link to that node. While another handshaked link
     * still reaches it, the close is one duplicate dropping and the node stays online.
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

        if ($this->findHandshakedLinkToNode($remote->nodeId, $link) !== null) {
            // The peer is still reachable over another link; this was a duplicate, not a departure.
            return;
        }

        $now = microtime(true);
        if ($registry->markOffline($remote->nodeId, $now)) {
            $this->notifyLeft($remote, $now);
            $this->broadcastAnnounce(PeerNodeEntry::fromIdentity($remote, false), $link);
        }

        // Fast-path leader-loss: a dropped link marks the peer offline instantly, so
        // a follower can react at once instead of waiting out the election timeout.
        $this->coordinator?->noteNodeOffline($remote->nodeId);
    }

    /**
     * Reports a node coming online to the membership observer.
     *
     * The registry already merged the record; this only fans the transition out to
     * the daemon's {@see \Hilos\Core\Daemon\DaemonManager::onNodeJoined} hook via
     * the cluster context. A no-op when the context is absent (non-daemon process).
     *
     * @param NodeIdentity $identity Node that came online
     * @param float $now Microtime of the transition
     */
    private function notifyJoined(NodeIdentity $identity, float $now): void
    {
        Hilos::$cluster?->notifyNodeJoined(ClusterNode::fromIdentity($identity, true, $now));
    }

    /**
     * Reports a node going offline to the membership observer.
     *
     * The registry already marked the node offline; this only fans the transition
     * out to the daemon's {@see \Hilos\Core\Daemon\DaemonManager::onNodeLeft} hook
     * via the cluster context. A no-op when the context is absent.
     *
     * @param NodeIdentity $identity Node that went offline
     * @param float $now Microtime of the transition
     */
    private function notifyLeft(NodeIdentity $identity, float $now): void
    {
        Hilos::$cluster?->notifyNodeLeft(ClusterNode::fromIdentity($identity, false, $now));
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
     * Returns the online master-role node ids from the registry, including self.
     *
     * Backs the coordinator's quorum check. A registry hiccup yields an empty set,
     * which the coordinator reads as no quorum — the safe, leadership-dropping side.
     *
     * @return list<string> Online master node ids
     */
    public function onlineMasterIds(): array
    {
        $registry = $this->registry();
        if ($registry === null) {
            return [];
        }

        $ids = [];
        foreach ($registry->snapshot() as $node) {
            if ($node->online && $node->role === NodeRole::Master) {
                $ids[] = $node->nodeId;
            }
        }

        return $ids;
    }

    /**
     * Sends a consensus frame to every handshaked master peer.
     *
     * @param PeerDTO $frame Consensus frame to broadcast
     */
    public function broadcastToMasters(PeerDTO $frame): void
    {
        foreach ($this->clients as $client) {
            if ($client->remoteIdentity()?->role === NodeRole::Master) {
                $client->sendFrame($frame);
            }
        }
    }

    /**
     * Sends a consensus frame to one master peer by node id, if it is linked.
     *
     * @param string $nodeId Target master node id
     * @param PeerDTO $frame Consensus frame to send
     */
    public function sendToMaster(string $nodeId, PeerDTO $frame): void
    {
        foreach ($this->clients as $client) {
            if ($client->remoteIdentity()?->nodeId === $nodeId) {
                $client->sendFrame($frame);
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
     * Finds the seed dial that owns a link, or null for an inbound link.
     *
     * @param PeerLink $link Link to match
     * @return ?PeerDial Owning dial, or null when the link was accepted
     */
    private function dialForLink(PeerLink $link): ?PeerDial
    {
        foreach ($this->allDials() as $dial) {
            if ($dial->link === $link) {
                return $dial;
            }
        }

        return null;
    }

    /**
     * Finds another handshaked link to the given node, excluding one link.
     *
     * @param string $nodeId Remote node id to match
     * @param PeerLink $exclude Link to skip (the one that just handshaked)
     * @return ?PeerLink Other handshaked link to the node, or null when none
     */
    private function findHandshakedLinkToNode(string $nodeId, PeerLink $exclude): ?PeerLink
    {
        foreach ($this->clients as $client) {
            if ($client !== $exclude && $client->remoteIdentity()?->nodeId === $nodeId) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Reports whether any handshaked link currently reaches the given node.
     *
     * @param string $nodeId Remote node id to match
     * @return bool True when a link to that node is established
     */
    private function hasHandshakedLinkToNode(string $nodeId): bool
    {
        foreach ($this->clients as $client) {
            if ($client->remoteIdentity()?->nodeId === $nodeId) {
                return true;
            }
        }

        return false;
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
