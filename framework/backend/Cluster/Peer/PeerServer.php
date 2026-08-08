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
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeDisableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeEnableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeLiftDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiesceDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiescedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\ProtectedModeCoordinator;
use Hilos\ProtectedMode\ProtectedModeMesh;
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
final class PeerServer extends AbstractServer implements LocalNodeAnnouncer, ConsensusMesh, PlacementMesh, ProtectedModeMesh
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

    /** @var ?ClusterPlacement Agent-placement coordinator, built at start on any clustered node; null when no worker executor is registered */
    private ?ClusterPlacement $placement = null;

    /** @var ?ProtectedModeCoordinator Protected-mode freeze handler, registered by the leader-orchestration slice; null until then */
    private ?ProtectedModeCoordinator $protectedMode = null;

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
     * @throws EnvException When a consensus or failover env value cannot be read
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

        // Agent placement runs on every clustered node: a master issues placements and
        // tracks them, a data-plane node executes the ones it is handed. The worker server
        // registers itself as the executor before the loop, so it is available here.
        $executor = Hilos::$cluster?->placementExecutor();
        if ($executor !== null) {
            $this->placement = new ClusterPlacement(
                $this->localIdentity->nodeId,
                $this,
                $executor,
                Hilos::$cluster->placementObserver(),
                Hilos::$env->int(EnvConstants::CLUSTER_FAILOVER_GRACE_MS),
                Hilos::$env->int(EnvConstants::CLUSTER_SLAVE_WORK_GRACE_MS),
            );
            Hilos::$cluster->registerPlacement($this->placement);
            // The placement coordinator doubles as the router's read-only placement lookup, so
            // cross-node signal routing (HIL-180) can ask where a placed agent lives.
            Hilos::$cluster->registerWorkerPlacement($this->placement);
        }

        // Every clustered node builds one protected-mode coordinator: the leader orchestrates the
        // two-phase freeze, a follower reacts to it, and this server is its outbound peer port. It
        // routes the freeze frames here and drives its leader-side role from the daemon's leadership
        // hooks; a node that never mounted the runtime item simply writes nothing.
        if (Hilos::$cluster !== null) {
            $protectedMode = new ClusterProtectedMode(
                $this->localIdentity->nodeId,
                $this,
                new DaemonProtectedModeExecutor(),
            );
            $this->registerProtectedMode($protectedMode);
            // The same object fills both facade slots: it is what an initiator asks for a freeze
            // and what the daemon drives through its leadership transitions.
            Hilos::$cluster->registerProtectedMode($protectedMode);
            Hilos::$cluster->registerProtectedModeLeadership($protectedMode);
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

        $now = microtime(true);
        $this->coordinator?->tick($now);
        // Placement failover and slave self-fence run off their grace timers on the same loop.
        $this->placement?->tick($now);
    }

    /**
     * Announces a planned graceful-leave before the transport begins shutting down.
     *
     * Broadcasts a {@see PeerNodeLeavingDTO} so peers can tell an orderly departure
     * (announced) from a crash (silence) and skip the failover panic. A leaving leader
     * names its most-recently-heard follower as the successor, which campaigns at once
     * on receipt (raft TimeoutNow-style); the frame is fire-and-forget and the ordinary
     * HIL-339 election remains the fallback. Then defers to the base to stop accepting.
     */
    public function prepareShutdown(): void
    {
        $this->broadcastGracefulLeave();

        parent::prepareShutdown();
    }

    /**
     * Broadcasts the graceful-leave frame to every handshaked peer.
     *
     * A leaving leader designates a successor so leadership transfers without an
     * election-timeout wait; a leaving non-leader names none and peers simply update
     * membership.
     */
    private function broadcastGracefulLeave(): void
    {
        $wasLeader = $this->coordinator?->amLeader() ?? false;
        $successor = $wasLeader ? $this->pickDesignatedSuccessor() : null;

        $this->broadcastToAllPeers(new PeerNodeLeavingDTO($this->localIdentity->nodeId, $wasLeader, $successor));

        Logger::info(
            "Announced graceful leave for node {$this->localIdentity->nodeId}"
            . ($successor !== null ? " with designated successor {$successor}" : ''),
        );
    }

    /**
     * Picks the successor a leaving leader designates: the most-recently-heard online
     * master peer, excluding self.
     *
     * Naming the freshest peer makes it the most likely to be reachable and current.
     * Returns null when no other online master is known, leaving the ordinary election
     * as the sole path to a new leader.
     *
     * @return ?string Successor node id, or null when no online master peer is known
     */
    private function pickDesignatedSuccessor(): ?string
    {
        $registry = $this->registry();
        if ($registry === null) {
            return null;
        }

        $best = null;
        foreach ($registry->snapshot() as $node) {
            if (!$node->online || $node->role !== NodeRole::Master || $node->nodeId === $this->localIdentity->nodeId) {
                continue;
            }

            if ($best === null || $node->lastSeen > $best->lastSeen) {
                $best = $node;
            }
        }

        return $best?->nodeId;
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
     * advertised address is left to reach us inbound. When a known peer re-handshakes
     * on a changed address (a restart on a new port, a NAT/DHCP change), its dial is
     * refreshed in place through {@see refreshPeerDial()} so the stale endpoint is not
     * dialed forever.
     */
    private function reconcilePeerDials(): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        foreach ($registry->snapshot() as $node) {
            if ($node->address === null) {
                continue;
            }

            $existing = $this->peerDials[$node->nodeId] ?? null;
            if ($existing !== null) {
                if ($existing->address->toString() !== $node->address->toString()) {
                    $this->refreshPeerDial($existing, $node->address);
                }
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
     * Re-points an existing dial at a peer's new advertised address.
     *
     * An address change is not a failure: any in-flight connect to the old endpoint is
     * torn down and the backoff is cleared so the new address is dialed promptly, while
     * a live established link is left untouched — only its future re-dial target moves.
     *
     * @param PeerDial $dial Dial to re-point
     * @param PeerAddress $address New advertised address to dial
     */
    private function refreshPeerDial(PeerDial $dial, PeerAddress $address): void
    {
        $dial->address = $address;

        if ($dial->connecting && $dial->socket !== null) {
            socket_close($dial->socket);
            $dial->socket = null;
            $dial->connecting = false;
        }

        $dial->nextAttemptAt = 0.0;
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
        // warning-suppressed: the result is the branch condition, socket_last_error is read below
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
        // warning-suppressed: a false return is told apart from 0 below, by socket_last_error
        $ready = @socket_select($read, $write, $except, 0);
        if ($ready === false) {
            // The error of a failed select lands in the global slot, not on any one socket:
            // socket_last_error($socket) reads 0 here and would abort every dial.
            $error = socket_last_error();
            // An interrupted or would-block select says nothing about the connect, so it waits
            // for the next tick like a plain 0. Any other code means the poll itself is broken,
            // and waiting on it would cost the connect timeout on top of the retry interval.
            if (!in_array($error, [SOCKET_EINTR, SOCKET_EAGAIN, SOCKET_EWOULDBLOCK], true)) {
                $this->abortDial($dial, $now);
                return;
            }

            socket_clear_error();
            return;
        }

        if ($ready === 0) {
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

        // Reconcile-on-rejoin: report the agents this node still hosts so a leader on the
        // other end can stop any that it has already re-placed elsewhere (a no-op otherwise).
        $this->placement?->onPeerHandshaked($remote->nodeId);
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
     * Merges a received announcement about a node this one does not observe itself.
     *
     * Liveness is observed, not relayed. The mesh is full (every node dials every
     * other), so a peer speaks authoritatively only about itself: an announcement
     * describing a third node this node already holds a handshaked link to is
     * dropped, because the local link is the better evidence. The merged entry is
     * also never re-announced onward — the observer already broadcast the change to
     * every peer, so relaying only re-amplifies it, and two nodes holding opposite
     * liveness views would echo the flip between them without ever converging.
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

        if ($announce->node->nodeId !== $link->remoteIdentity()?->nodeId
            && $this->hasHandshakedLinkToNode($announce->node->nodeId)) {
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
     * Reacts to a peer's announced graceful-leave.
     *
     * Marks the leaving node offline ahead of its link closing — a planned departure,
     * so membership converges at once rather than waiting for the socket to drop — and
     * fans the leave out to the other peers. When this node is the designated successor,
     * triggers an immediate election so leadership transfers without the election-timeout
     * wait; the other followers keep waiting theirs, avoiding a split vote. The ordinary
     * HIL-339 election (driven by the link close's fast path) remains the fallback.
     *
     * @param PeerNodeLeavingDTO $frame Received graceful-leave frame
     */
    public function onNodeLeaving(PeerNodeLeavingDTO $frame): void
    {
        $registry = $this->registry();
        if ($registry !== null) {
            $leaving = $this->onlineNodeById($registry, $frame->nodeId);
            $now = microtime(true);
            if ($leaving !== null && $registry->markOffline($frame->nodeId, $now)) {
                $identity = NodeIdentity::of($leaving->nodeId, $leaving->role, $leaving->capabilities, $leaving->address);
                $this->notifyLeft($identity, $now);
                $this->broadcastAnnounce(PeerNodeEntry::fromIdentity($identity, false));
            }
        }

        if ($frame->designatedSuccessor === $this->localIdentity->nodeId) {
            $this->coordinator?->triggerDesignatedElection();
        }
    }

    /**
     * Routes a received place-agent request to the placement coordinator to launch locally.
     *
     * A no-op before the coordinator is built; the coordinator answers the requesting
     * leader with a status frame through this server's mesh.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerPlaceAgentDTO $frame Received place-agent frame
     */
    public function onPlaceAgentReceived(PeerLink $link, PeerPlaceAgentDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlaceAgent($from, $frame);
        }
    }

    /**
     * Routes a received stop-agent request to the placement coordinator to stop locally.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerStopAgentDTO $frame Received stop-agent frame
     */
    public function onStopAgentReceived(PeerLink $link, PeerStopAgentDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onStopAgent($from, $frame);
        }
    }

    /**
     * Routes a received agent-status reply to the placement coordinator to track.
     *
     * @param PeerLink $link Link the reply arrived on
     * @param PeerAgentStatusDTO $frame Received agent-status frame
     */
    public function onAgentStatusReceived(PeerLink $link, PeerAgentStatusDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onAgentStatus($from, $frame);
        }
    }

    /**
     * Routes a received placement query to the placement coordinator to answer.
     *
     * @param PeerLink $link Link the query arrived on
     */
    public function onPlacementQueryReceived(PeerLink $link): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlacementQuery($from);
        }
    }

    /**
     * Routes a received placement report to the placement coordinator to rebuild its view.
     *
     * @param PeerLink $link Link the report arrived on
     * @param PeerPlacementReportDTO $frame Received placement-report frame
     */
    public function onPlacementReportReceived(PeerLink $link, PeerPlacementReportDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlacementReport($from, $frame);
        }
    }

    /**
     * Registers the node-local protected-mode handler the freeze frames route to.
     *
     * The leader-orchestration slice builds the handler and installs it here; until it does, the
     * seam stays null and the arriving frames route to a no-op, exactly like the placement seam
     * before a worker executor is registered.
     *
     * @param ProtectedModeCoordinator $coordinator Handler for the protected-mode freeze frames
     */
    public function registerProtectedMode(ProtectedModeCoordinator $coordinator): void
    {
        $this->protectedMode = $coordinator;
    }

    /**
     * Routes a received protected-mode enable request to the local handler for the leader to act on.
     *
     * A no-op before the handler is registered; the envelope is unwrapped so the domain handler
     * receives the contract payload, never the wire frame.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerProtectedModeEnableDTO $frame Received protected-mode enable frame
     */
    public function onProtectedModeEnableReceived(PeerLink $link, PeerProtectedModeEnableDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onEnable($from, $frame->data);
        }
    }

    /**
     * Routes a received protected-mode ready confirmation to the local handler for the initiator to act on.
     *
     * @param PeerLink $link Link the confirmation arrived on
     * @param PeerProtectedModeReadyDTO $frame Received protected-mode ready frame
     */
    public function onProtectedModeReadyReceived(PeerLink $link, PeerProtectedModeReadyDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onReady($from);
        }
    }

    /**
     * Routes a received protected-mode disable request to the local handler for the leader to act on.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerProtectedModeDisableDTO $frame Received protected-mode disable frame
     */
    public function onProtectedModeDisableReceived(PeerLink $link, PeerProtectedModeDisableDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onDisable($from);
        }
    }

    /**
     * Routes a received protected-mode quiesce order to the local handler for this follower to freeze.
     *
     * A no-op before the handler is registered; the envelope is unwrapped so the domain handler
     * receives the freeze descriptor, never the wire frame.
     *
     * @param PeerLink $link Link the order arrived on
     * @param PeerProtectedModeQuiesceDTO $frame Received protected-mode quiesce frame
     */
    public function onProtectedModeQuiesceReceived(PeerLink $link, PeerProtectedModeQuiesceDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onQuiesce($from, $frame->data);
        }
    }

    /**
     * Routes a received protected-mode quiesced report to the local handler for the leader to track.
     *
     * @param PeerLink $link Link the report arrived on
     * @param PeerProtectedModeQuiescedDTO $frame Received protected-mode quiesced frame
     */
    public function onProtectedModeQuiescedReceived(PeerLink $link, PeerProtectedModeQuiescedDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onQuiesced($from);
        }
    }

    /**
     * Routes a received protected-mode lift order to the local handler for this follower to release.
     *
     * @param PeerLink $link Link the order arrived on
     * @param PeerProtectedModeLiftDTO $frame Received protected-mode lift frame
     */
    public function onProtectedModeLiftReceived(PeerLink $link, PeerProtectedModeLiftDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onLift($from);
        }
    }

    /**
     * Returns the online master node ids other than self — the followers the leader freezes.
     *
     * Backs {@see ProtectedModeMesh}: the leader broadcasts quiesce to these and awaits a quiesced
     * report from each. A registry hiccup collapses {@see onlineMasterIds()} to empty, so the leader
     * simply sees no followers and activates on its own node alone.
     *
     * @return array<string> Online master node ids excluding the local node
     */
    public function followerMasterNodeIds(): array
    {
        $followers = [];
        foreach ($this->onlineMasterIds() as $nodeId) {
            if ($nodeId !== $this->localIdentity->nodeId) {
                $followers[] = $nodeId;
            }
        }

        return $followers;
    }

    /**
     * Returns the node id of the current cluster leader for an initiator to address its request.
     *
     * @return ?string Leader node id, or null when leadership is unknown or the cluster is absent
     */
    public function leaderNodeId(): ?string
    {
        return Hilos::$cluster?->leadership()->leaderId();
    }

    /**
     * Forwards this initiator node's freeze request to the leader over the peer channel.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     */
    public function sendEnable(string $leaderNodeId, ProtectedModeEnableSignalData $data): void
    {
        $this->sendToMaster($leaderNodeId, new PeerProtectedModeEnableDTO($data));
    }

    /**
     * Forwards this initiator node's release request to the leader over the peer channel.
     *
     * @param string $leaderNodeId Node id of the current leader
     */
    public function sendDisable(string $leaderNodeId): void
    {
        $this->sendToMaster($leaderNodeId, new PeerProtectedModeDisableDTO());
    }

    /**
     * Broadcasts the freeze order to every follower master.
     *
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the freeze protects
     */
    public function broadcastQuiesce(ProtectedModeQuiesceData $data): void
    {
        $this->broadcastToMasters(new PeerProtectedModeQuiesceDTO($data));
    }

    /**
     * Signals the initiator that every node has quiesced and its operation may proceed.
     *
     * @param string $initiatorNodeId Node id that hosts the initiator agent
     */
    public function sendReady(string $initiatorNodeId): void
    {
        $this->sendToMaster($initiatorNodeId, new PeerProtectedModeReadyDTO());
    }

    /**
     * Broadcasts the release order to every follower master.
     */
    public function broadcastLift(): void
    {
        $this->broadcastToMasters(new PeerProtectedModeLiftDTO());
    }

    /**
     * Reports this follower's quiesced state back to the leader that ordered the freeze.
     *
     * @param string $leaderNodeId Node id of the leader that ordered the freeze
     */
    public function sendQuiesced(string $leaderNodeId): void
    {
        $this->sendToMaster($leaderNodeId, new PeerProtectedModeQuiescedDTO());
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
     * the daemon's {@see DaemonManager::onNodeJoined} hook via
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
     * out to the daemon's {@see DaemonManager::onNodeLeft} hook
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
     * Sends a frame to every handshaked peer, master or slave alike.
     *
     * Used for the graceful-leave announcement, which every peer needs (not just the
     * master set) so their membership converges on the planned departure.
     *
     * @param PeerDTO $frame Frame to broadcast
     */
    private function broadcastToAllPeers(PeerDTO $frame): void
    {
        foreach ($this->clients as $client) {
            if ($client->remoteIdentity() !== null) {
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
     * Delivers a placement frame to one node by id, over any handshaked link.
     *
     * Unlike {@see sendToMaster()}, placement addresses any node — a data-plane slave
     * hosts placed agents — so no role filter applies. Returns whether a link carried it.
     *
     * @param string $nodeId Target node id
     * @param PeerDTO $frame Placement frame to send
     * @return bool True when a handshaked link carried the frame
     */
    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        foreach ($this->clients as $client) {
            if ($client->remoteIdentity()?->nodeId === $nodeId) {
                $client->sendFrame($frame);
                return true;
            }
        }

        return false;
    }

    /**
     * Forwards one resolved signal to an agent on another node over the peer channel.
     *
     * The router already resolved the final target on this node; this wraps it in a
     * {@see PeerSignalDTO} stamped with the local node as origin and hands it to the target
     * over a live link. Delivery is best-effort: a false return (no handshaked link to the
     * node) is the caller's cue to drop and log, matching the local path that skips when no
     * worker hosts the agent. Buffering and retry on an offline node are out of scope.
     *
     * @param string $targetNodeId Id of the node hosting the target agent
     * @param string $agentType Resolved target agent type
     * @param ?string $agentIndex Resolved target agent index, or null for a singleton agent
     * @param SignalDTO $signal Signal to deliver on the target node
     * @return bool True when a live link carried the frame, false when the node is unlinked
     */
    public function sendSignalToNode(string $targetNodeId, string $agentType, ?string $agentIndex, SignalDTO $signal): bool
    {
        return $this->sendToNode($targetNodeId, new PeerSignalDTO(
            $this->localIdentity->nodeId,
            $targetNodeId,
            $agentType,
            $agentIndex,
            $signal,
        ));
    }

    /**
     * Delivers a received cross-node signal to the resolved agent on this node.
     *
     * Verifies the frame is addressed to this node, then hands the already-resolved target
     * straight to the local delivery sink — no re-routing, which structurally rules out a
     * forward loop or a second fan-out (F2). A mismatched target, an unregistered sink, or a
     * delivery failure is dropped and logged, keeping a bad forward from tearing down the
     * daemon loop (F1).
     *
     * @param PeerLink $link Link the signal arrived on
     * @param PeerSignalDTO $frame Received signal-forward frame
     */
    public function onSignalReceived(PeerLink $link, PeerSignalDTO $frame): void
    {
        if ($frame->targetNodeId !== $this->localIdentity->nodeId) {
            Logger::warning(
                "Dropping peer signal addressed to node '{$frame->targetNodeId}' received on node '{$this->localIdentity->nodeId}'",
            );
            return;
        }

        $sink = Hilos::$cluster?->agentSignalSink();
        if ($sink === null) {
            Logger::warning("Dropping peer signal for agent '{$frame->agentType}': no local agent signal sink registered");
            return;
        }

        try {
            $sink->deliverSignalToAgent($frame->agentType, $frame->agentIndex, $frame->signal);
        } catch (\Throwable $e) {
            Logger::warning("Failed to deliver peer signal to agent '{$frame->agentType}': {$e->getMessage()}");
        }
    }

    /**
     * Delivers a placement frame to every handshaked peer, master or slave alike.
     *
     * Backs the leader-change rebuild query, which every node must answer.
     *
     * @param PeerDTO $frame Placement frame to broadcast
     */
    public function broadcastToNodes(PeerDTO $frame): void
    {
        foreach ($this->clients as $client) {
            if ($client->remoteIdentity() !== null) {
                $client->sendFrame($frame);
            }
        }
    }

    /**
     * Returns the advertised capabilities of an online node from the registry, or null
     * when the node is unknown or offline.
     *
     * @param string $nodeId Node id to look up
     * @return ?list<string> Advertised capability tags, or null when unknown or offline
     */
    public function nodeCapabilities(string $nodeId): ?array
    {
        $registry = $this->registry();
        if ($registry === null) {
            return null;
        }

        foreach ($registry->snapshot() as $node) {
            if ($node->nodeId === $nodeId && $node->online) {
                return $node->capabilities;
            }
        }

        return null;
    }

    /**
     * Returns the ids of every currently-online node, including the local node.
     *
     * Reads the master registry so failover can scan the online set for a capable host. A
     * registry hiccup yields an empty set — the safe side, which simply degrades a re-placement
     * to unplaced rather than risking a bad target.
     *
     * @return list<string> Online node ids
     */
    public function onlineNodeIds(): array
    {
        $registry = $this->registry();
        if ($registry === null) {
            return [];
        }

        $ids = [];
        foreach ($registry->snapshot() as $node) {
            if ($node->online) {
                $ids[] = $node->nodeId;
            }
        }

        return $ids;
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
     * Finds an online node in the registry by id, or null when absent or offline.
     *
     * @param ClusterRegistry $registry Master registry
     * @param string $nodeId Node id to look up
     * @return ?ClusterNode Online node record, or null
     */
    private function onlineNodeById(ClusterRegistry $registry, string $nodeId): ?ClusterNode
    {
        foreach ($registry->snapshot() as $node) {
            if ($node->nodeId === $nodeId && $node->online) {
                return $node;
            }
        }

        return null;
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
