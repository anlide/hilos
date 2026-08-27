<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusInspection;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\NullPlacementObserver;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementObserver;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\ProtectedMode\ProtectedModeAgentFreezer;
use Hilos\ProtectedMode\ProtectedModeClientNotifier;
use Hilos\ProtectedMode\ProtectedModeLeadership;
use Hilos\ProtectedMode\ProtectedModeReadyRelay;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\ProtectedMode\StandaloneProtectedMode;

/**
 * Facade context for cluster mode and the local node identity.
 *
 * This is the single seam through which the rest of the framework asks "are we
 * clustered, and who am I". It is always present on the facade; when cluster
 * mode is off it simply reports disabled and holds no identity, so a single-node
 * daemon carries this context at zero behavioural cost. Later cluster slices
 * (the live node registry, the peer channel, the coordinator) hang off this same
 * context rather than adding further facade globals.
 *
 * Configuration is read lazily from Hilos::$env, not captured at construction:
 * the daemon overlays a test env after the facade is bootstrapped, so eager
 * reads would miss it.
 */
final class ClusterContext
{
    /** @var ?NodeIdentity Resolved local node identity, memoized on first access. */
    private ?NodeIdentity $identity = null;

    /** @var ?ClusterRegistry Master-owned live membership registry, built on first access. */
    private ?ClusterRegistry $registry = null;

    /** @var ?LocalNodeAnnouncer Peer-mesh announcer for local node changes, registered by the transport at start. */
    private ?LocalNodeAnnouncer $localAnnouncer = null;

    /** @var ?Leadership Local node's leadership seam, built on first access from the current mode. */
    private ?Leadership $leadership = null;

    /** @var ?MembershipObserver Observer notified of membership transitions, registered by the daemon at start. */
    private ?MembershipObserver $membershipObserver = null;

    /** @var ?LeadershipObserver Observer notified of leadership/quorum transitions, registered by the daemon at start. */
    private ?LeadershipObserver $leadershipObserver = null;

    /** @var ?PlacementObserver Observer notified of placement-degradation events, registered by the daemon at start. */
    private ?PlacementObserver $placementObserver = null;

    /** @var ?PlacementExecutor Local worker executor for placed agents, registered by the daemon at start. */
    private ?PlacementExecutor $placementExecutor = null;

    /** @var ?ClusterPlacement Agent-placement coordinator, registered by the peer transport at start. */
    private ?ClusterPlacement $placement = null;

    /** @var ?ProtectedModeSwitch Protected-mode request seam, registered at start by whichever topology this node runs. */
    private ?ProtectedModeSwitch $protectedMode = null;

    /** @var ?ProtectedModeLeadership Leader-side hooks of the freeze, registered by the peer transport at start; null off-cluster. */
    private ?ProtectedModeLeadership $protectedModeLeadership = null;

    /** @var ?ProtectedModeReadyRelay Local relay of the leader's ready to the initiator agent, registered by the daemon at start. */
    private ?ProtectedModeReadyRelay $protectedModeReadyRelay = null;

    /** @var ?ProtectedModeAgentFreezer Local port that stops this node's agents during the freeze, registered by the daemon at start. */
    private ?ProtectedModeAgentFreezer $protectedModeAgentFreezer = null;

    /**
     * @var ?ProtectedModeClientNotifier Local port that tells this node's browser connections about
     *     the freeze, registered by the daemon at start.
     */
    private ?ProtectedModeClientNotifier $protectedModeClientNotifier = null;

    /** @var ?WorkerPlacement Read-only placement lookup the signal router consults, registered by the peer transport at start. */
    private ?WorkerPlacement $workerPlacement = null;

    /** @var ?AgentSignalSink Local delivery port for cross-node signals, registered by the daemon at start. */
    private ?AgentSignalSink $agentSignalSink = null;

    /** @var ?RtSyncSink Local apply port for cross-node RT replicas, registered by the daemon at start. */
    private ?RtSyncSink $rtSyncSink = null;

    /** @var ?RtClaimSink Local port for the cluster-wide arbitration of RT ownership, registered by the daemon at start. */
    private ?RtClaimSink $rtClaimSink = null;

    /** Port the inspect command reads this node's RT replication state through */
    private ?RtReplicaInspector $rtReplicaInspector = null;

    /** @var ?DbSyncSink Local apply port for cross-node DB replicas, registered by the daemon at start. */
    private ?DbSyncSink $dbSyncSink = null;

    /** @var int DB replicas from other nodes this one has accepted, for the acceptance inspector */
    private int $dbReplicas = 0;

    /** @var ?string Collection the last accepted DB replica named, or null when none has arrived */
    private ?string $lastDbReplicaCollection = null;

    /** @var ?ClusterClientLocation Cluster-wide index of which node each browser connection is attached to, registered by the daemon at start. */
    private ?ClusterClientLocation $clientConnections = null;

    /** @var ?ClientLocation Read-only connection lookup the signal router consults, registered by the daemon at start. */
    private ?ClientLocation $clientLocation = null;

    /** @var ?ClientSignalSink Local delivery port for cross-node client signals, registered by the daemon at start. */
    private ?ClientSignalSink $clientSignalSink = null;

    /**
     * @return bool True when cluster mode is enabled
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function isEnabled(): bool
    {
        return Hilos::$env->bool(EnvConstants::CLUSTER_ENABLED);
    }

    /**
     * @return NodeIdentity Local node identity
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     */
    public function identity(): NodeIdentity
    {
        if (!$this->isEnabled()) {
            throw new ClusterDisabledException('Node identity is unavailable while cluster mode is disabled');
        }

        return $this->identity ??= NodeIdentity::fromEnv();
    }

    /**
     * Returns the master-owned live membership registry, seeded with the local node.
     *
     * The registry is the single source of truth for cluster membership and lives
     * on the daemon master; it is built lazily and self-seeds the local node on
     * first access. The peer transport records and removes peers through it.
     *
     * @return ClusterRegistry Live membership registry
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     */
    public function registry(): ClusterRegistry
    {
        if (!$this->isEnabled()) {
            throw new ClusterDisabledException('The cluster registry is unavailable while cluster mode is disabled');
        }

        if ($this->registry === null) {
            $this->registry = new ClusterRegistry();
            $this->registry->seedLocal($this->identity(), microtime(true));
        }

        return $this->registry;
    }

    /**
     * Registers the peer-mesh announcer used to gossip local node changes.
     *
     * The peer transport calls this once at start so a later {@see reload()} can
     * re-announce the local node without the context depending on the transport.
     *
     * @param LocalNodeAnnouncer $announcer Peer-mesh announcer for the local node
     */
    public function registerLocalAnnouncer(LocalNodeAnnouncer $announcer): void
    {
        $this->localAnnouncer = $announcer;
    }

    /**
     * Returns the local node's leadership seam.
     *
     * Unlike {@see identity()} and {@see registry()}, this is available even when
     * cluster mode is off: a standalone daemon gets a {@see StandaloneLeadership}
     * (its own leader, trivial quorum), and a clustered node gets a
     * {@see PendingLeadership} (no leader, no quorum) until the peer transport
     * registers the consensus coordinator through {@see registerLeadership()}. The
     * instance is memoized so callers keep observing one seam across the process
     * lifetime.
     *
     * @return Leadership Local node's leadership seam
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function leadership(): Leadership
    {
        return $this->leadership ??= $this->isEnabled()
            ? new PendingLeadership()
            : new StandaloneLeadership();
    }

    /**
     * Convenience over {@see leadership()}: whether the local node currently holds
     * cluster leadership.
     *
     * A standalone daemon ({@see StandaloneLeadership}) is always its own leader, so
     * this reports true when cluster mode is off. Singleton duties key off this seam
     * to run on exactly one node cluster-wide.
     *
     * @return bool True when the local node is the leader (or cluster mode is off)
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function amLeader(): bool
    {
        return $this->leadership()->amLeader();
    }

    /**
     * Installs the consensus coordinator as the local node's leadership seam.
     *
     * The peer transport builds the {@see ClusterCoordinator}
     * for a clustered master at start and registers it here, replacing the inert
     * {@see PendingLeadership} so {@see leadership()} — and thus
     * {@see lifecycleState()} — start reflecting real election and quorum results.
     *
     * @param Leadership $leadership Leadership seam to install
     */
    public function registerLeadership(Leadership $leadership): void
    {
        $this->leadership = $leadership;
    }

    /**
     * Resolves the local node's coarse lifecycle phase.
     *
     * Disabled mode is Standalone; otherwise the phase is derived from the
     * self-declared role and the leadership seam (see
     * {@see NodeLifecycleState::forEnabledNode()}). In this slice a clustered
     * master is always MasterNoQuorum because {@see PendingLeadership} reports no
     * quorum, so MasterLeader and MasterFollowerOrCandidate are unreachable until
     * HIL-339.
     *
     * @return NodeLifecycleState Current lifecycle phase of the local node
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     * @throws ClusterDisabledException When cluster mode is disabled
     */
    public function lifecycleState(): NodeLifecycleState
    {
        if (!$this->isEnabled()) {
            return NodeLifecycleState::Standalone;
        }

        return NodeLifecycleState::forEnabledNode($this->identity()->role, $this->leadership());
    }

    /**
     * Registers the observer notified of cluster membership transitions.
     *
     * The peer transport reports joins and leaves through {@see notifyNodeJoined()}
     * / {@see notifyNodeLeft()}; the daemon registers itself here at start so those
     * transitions reach its project-overridable hooks. Symmetric to
     * {@see registerLocalAnnouncer()}.
     *
     * @param MembershipObserver $observer Observer to receive membership transitions
     */
    public function registerMembershipObserver(MembershipObserver $observer): void
    {
        $this->membershipObserver = $observer;
    }

    /**
     * Registers the observer notified of leadership and quorum transitions.
     *
     * The daemon registers itself here at start so the four transitions the
     * consensus coordinator fires reach its project-overridable hooks. Symmetric to
     * {@see registerMembershipObserver()}; the coordinator resolves the observer via
     * {@see leadershipObserver()} when the transport builds it.
     *
     * @param LeadershipObserver $observer Observer to receive leadership transitions
     */
    public function registerLeadershipObserver(LeadershipObserver $observer): void
    {
        $this->leadershipObserver = $observer;
    }

    /**
     * Returns the registered leadership observer, or an inert no-op when none is set.
     *
     * @return LeadershipObserver Registered observer, or a {@see NullLeadershipObserver}
     */
    public function leadershipObserver(): LeadershipObserver
    {
        return $this->leadershipObserver ??= new NullLeadershipObserver();
    }

    /**
     * Registers the observer notified when failover degrades an agent to unplaced.
     *
     * The daemon registers itself here at start so the degradation event reaches its
     * project-overridable {@see DaemonManager::onPlacementDegraded()} hook. Symmetric to
     * {@see registerLeadershipObserver()}; the placement coordinator resolves the observer via
     * {@see placementObserver()} when the transport builds it.
     *
     * @param PlacementObserver $observer Observer to receive placement-degradation events
     */
    public function registerPlacementObserver(PlacementObserver $observer): void
    {
        $this->placementObserver = $observer;
    }

    /**
     * Returns the registered placement observer, or an inert no-op when none is set.
     *
     * @return PlacementObserver Registered observer, or a {@see NullPlacementObserver}
     */
    public function placementObserver(): PlacementObserver
    {
        return $this->placementObserver ??= new NullPlacementObserver();
    }

    /**
     * Registers the local worker executor used to launch and stop placed agents.
     *
     * The daemon registers its worker server here at start so the peer transport can build
     * the {@see ClusterPlacement} coordinator against it. Symmetric to
     * {@see registerLocalAnnouncer()}.
     *
     * @param PlacementExecutor $executor Local worker executor for placed agents
     */
    public function registerPlacementExecutor(PlacementExecutor $executor): void
    {
        $this->placementExecutor = $executor;
    }

    /**
     * Returns the registered local worker executor, or null when none is set.
     *
     * @return ?PlacementExecutor Local worker executor, or null
     */
    public function placementExecutor(): ?PlacementExecutor
    {
        return $this->placementExecutor;
    }

    /**
     * Installs the agent-placement coordinator built by the peer transport.
     *
     * The transport builds the {@see ClusterPlacement} for a clustered node at start and
     * registers it here so the daemon's leadership hooks can drive its leader-side rebuild.
     *
     * @param ClusterPlacement $placement Placement coordinator to install
     */
    public function registerPlacement(ClusterPlacement $placement): void
    {
        $this->placement = $placement;
    }

    /**
     * Returns the agent-placement coordinator, or null on a node without one.
     *
     * Null off-cluster (no peer transport) and on a clustered node whose worker executor
     * was never registered.
     *
     * @return ?ClusterPlacement Placement coordinator, or null
     */
    public function placement(): ?ClusterPlacement
    {
        return $this->placement;
    }

    /**
     * Installs the protected-mode request seam for this node's topology.
     *
     * A clustered node registers the {@see ClusterProtectedMode} its peer transport builds; a
     * single-node installation registers the {@see StandaloneProtectedMode} its daemon builds.
     * The callers above this slot ask for a freeze the same way either way.
     *
     * @param ProtectedModeSwitch $protectedMode Freeze request seam to install
     */
    public function registerProtectedMode(ProtectedModeSwitch $protectedMode): void
    {
        $this->protectedMode = $protectedMode;
    }

    /**
     * Returns the protected-mode request seam, or null on a node without one.
     *
     * @return ?ProtectedModeSwitch Freeze request seam, or null
     */
    public function protectedMode(): ?ProtectedModeSwitch
    {
        return $this->protectedMode;
    }

    /**
     * Installs the leader-side hooks of the freeze, built by the peer transport.
     *
     * Separate from {@see registerProtectedMode()} because leadership exists only in a cluster:
     * the peer transport registers the same coordinator object in both slots, while a single-node
     * daemon fills only the request seam and leaves this one null.
     *
     * @param ProtectedModeLeadership $leadership Leader-side hooks to install
     */
    public function registerProtectedModeLeadership(ProtectedModeLeadership $leadership): void
    {
        $this->protectedModeLeadership = $leadership;
    }

    /**
     * Returns the leader-side hooks of the freeze, or null on a node that leads nothing.
     *
     * @return ?ProtectedModeLeadership Leader-side hooks, or null
     */
    public function protectedModeLeadership(): ?ProtectedModeLeadership
    {
        return $this->protectedModeLeadership;
    }

    /**
     * Registers the local relay used to deliver the leader's ready to the initiator agent.
     *
     * The daemon registers its worker server here at start so the protected-mode executor can
     * address the worker hosting the initiator agent. Symmetric to {@see registerAgentSignalSink()}.
     *
     * @param ProtectedModeReadyRelay $relay Local ready relay for the initiator agent
     */
    public function registerProtectedModeReadyRelay(ProtectedModeReadyRelay $relay): void
    {
        $this->protectedModeReadyRelay = $relay;
    }

    /**
     * Returns the registered protected-mode ready relay, or null when none is set.
     *
     * @return ?ProtectedModeReadyRelay Local ready relay, or null
     */
    public function protectedModeReadyRelay(): ?ProtectedModeReadyRelay
    {
        return $this->protectedModeReadyRelay;
    }

    /**
     * Registers the local port used to stop this node's agents while protected mode holds.
     *
     * The daemon registers its worker server here at start so the protected-mode executor can
     * stop every hosted agent except the initiator. Symmetric to {@see registerProtectedModeReadyRelay()}.
     *
     * @param ProtectedModeAgentFreezer $freezer Local agent-freezer for the protected-mode freeze
     */
    public function registerProtectedModeAgentFreezer(ProtectedModeAgentFreezer $freezer): void
    {
        $this->protectedModeAgentFreezer = $freezer;
    }

    /**
     * Returns the registered protected-mode agent freezer, or null when none is set.
     *
     * @return ?ProtectedModeAgentFreezer Local agent freezer, or null
     */
    public function protectedModeAgentFreezer(): ?ProtectedModeAgentFreezer
    {
        return $this->protectedModeAgentFreezer;
    }

    /**
     * Registers the local port used to tell this node's browser connections about the freeze.
     *
     * The daemon registers itself here at start, because the WebSocket server it broadcasts
     * through is its own. Symmetric to {@see registerProtectedModeAgentFreezer()}: that seam
     * stops the work, this one tells the people watching it.
     *
     * @param ProtectedModeClientNotifier $notifier Local client notifier for the protected-mode frame
     */
    public function registerProtectedModeClientNotifier(ProtectedModeClientNotifier $notifier): void
    {
        $this->protectedModeClientNotifier = $notifier;
    }

    /**
     * Returns the registered protected-mode client notifier, or null when none is set.
     *
     * @return ?ProtectedModeClientNotifier Local client notifier, or null
     */
    public function protectedModeClientNotifier(): ?ProtectedModeClientNotifier
    {
        return $this->protectedModeClientNotifier;
    }

    /**
     * Installs the read-only placement lookup the signal router consults.
     *
     * The peer transport registers the placement coordinator here as its lookup so the
     * router can ask which node hosts an agent without depending on the concrete
     * coordinator. Separate from {@see registerPlacement()}: that is the write/track side,
     * this is the narrow read seam the router binds to (and a test can supply a fake for).
     *
     * @param WorkerPlacement $workerPlacement Placement lookup to install
     */
    public function registerWorkerPlacement(WorkerPlacement $workerPlacement): void
    {
        $this->workerPlacement = $workerPlacement;
    }

    /**
     * Returns the placement lookup the signal router reads, or null when none is set.
     *
     * Null off-cluster and on a clustered node before the transport registers one, so the
     * router's cross-node post-pass is inert and every signal stays local (opt-in).
     *
     * @return ?WorkerPlacement Placement lookup, or null
     */
    public function workerPlacement(): ?WorkerPlacement
    {
        return $this->workerPlacement;
    }

    /**
     * Registers the local delivery port for signals forwarded from other nodes.
     *
     * The daemon registers its worker server here at start so the peer transport can hand a
     * received cross-node signal straight to the target agent. Symmetric to
     * {@see registerPlacementExecutor()}.
     *
     * @param AgentSignalSink $sink Local delivery port for cross-node signals
     */
    public function registerAgentSignalSink(AgentSignalSink $sink): void
    {
        $this->agentSignalSink = $sink;
    }

    /**
     * Returns the local delivery port for cross-node signals, or null when none is set.
     *
     * @return ?AgentSignalSink Delivery port, or null
     */
    public function agentSignalSink(): ?AgentSignalSink
    {
        return $this->agentSignalSink;
    }

    /**
     * Registers the local apply port for RT replicas broadcast from other nodes.
     *
     * The daemon registers itself here at start: unlike a forwarded signal, which belongs to
     * an agent and travels on to a worker, an RT replica is applied by the master to the copy
     * it holds. Symmetric to {@see registerAgentSignalSink()}.
     *
     * @param RtSyncSink $sink Local apply port for cross-node RT replicas
     */
    public function registerRtSyncSink(RtSyncSink $sink): void
    {
        $this->rtSyncSink = $sink;
    }

    /**
     * Returns the local apply port for cross-node RT replicas, or null when none is set.
     *
     * @return ?RtSyncSink Apply port, or null
     */
    public function rtSyncSink(): ?RtSyncSink
    {
        return $this->rtSyncSink;
    }

    /**
     * Registers the local port the RT ownership claims of the whole mesh are arbitrated through.
     *
     * The daemon registers itself here at start, beside {@see registerRtSyncSink()} and apart
     * from it: that seam carries what a node WROTE, this one carries the right it holds to write
     * it, and the two are judged by different rules — a replica is refused by its receiver, while
     * a claim is judged by the leader against what every other node claims (HIL-696).
     *
     * @param RtClaimSink $sink Local port for RT ownership claims and the leader's verdicts
     */
    public function registerRtClaimSink(RtClaimSink $sink): void
    {
        $this->rtClaimSink = $sink;
    }

    /**
     * Returns the local port for RT ownership claims, or null when none is set.
     *
     * @return ?RtClaimSink Claim port, or null
     */
    public function rtClaimSink(): ?RtClaimSink
    {
        return $this->rtClaimSink;
    }

    /**
     * Registers the port the inspect command reads this node's RT replication state through.
     *
     * The daemon registers itself here at start, beside {@see registerRtSyncSink()} and apart
     * from it: what a peer frame crosses and what a command asks about are two questions, and
     * the transport has no business in the second.
     *
     * @param RtReplicaInspector $inspector Port answering for this node's RT replicas
     */
    public function registerRtReplicaInspector(RtReplicaInspector $inspector): void
    {
        $this->rtReplicaInspector = $inspector;
    }

    /**
     * Returns the port answering for this node's RT replicas, or null when none is set.
     *
     * @return ?RtReplicaInspector Inspection port, or null
     */
    public function rtReplicaInspector(): ?RtReplicaInspector
    {
        return $this->rtReplicaInspector;
    }

    /**
     * Registers the local apply port for DB replicas broadcast from other nodes.
     *
     * The daemon registers itself here at start, on the same terms as
     * {@see registerRtSyncSink()}: the master applies the fact to the rows it holds and fans it
     * out to its own workers. It is a separate seam from the RT one rather than a second method
     * on it because the two answer to different rules - an RT collection has one owner and a
     * replica from a second one is refused, while a database row has already been written and
     * refusing the news of it would only leave this node disagreeing with the disk.
     *
     * @param DbSyncSink $sink Local apply port for cross-node DB replicas
     */
    public function registerDbSyncSink(DbSyncSink $sink): void
    {
        $this->dbSyncSink = $sink;
    }

    /**
     * Returns the local apply port for cross-node DB replicas, or null when none is set.
     *
     * @return ?DbSyncSink Apply port, or null
     */
    public function dbSyncSink(): ?DbSyncSink
    {
        return $this->dbSyncSink;
    }

    /**
     * Records that a DB replica from another node was accepted here.
     *
     * Counted for the acceptance inspector and for nothing else: on a stand where each node has
     * a schema of its own, whether the ROW landed is not a question the stand can ask, so what
     * it checks is that the frame arrived and named the collection it was sent about. Whether
     * the row lands is settled by unit tests, against a collection whose fullness is known.
     *
     * @param ?string $collectionKey Collection the accepted replica named, or null when it named none
     */
    public function noteDbReplica(?string $collectionKey): void
    {
        $this->dbReplicas++;
        $this->lastDbReplicaCollection = $collectionKey;
    }

    /**
     * Installs the cluster connection index.
     *
     * The write/track side, separate from {@see registerClientLocation()} the way
     * {@see registerPlacement()} is separate from {@see registerWorkerPlacement()}: this is what
     * the peer transport applies announcements to, that is the narrow seam the router reads. One
     * object normally fills both, and the daemon registers it in both — it is the daemon's
     * because the sockets are, and because the diff keeping the local half true is a line of its
     * loop.
     *
     * @param ClusterClientLocation $connections Connection index to install
     */
    public function registerClientConnections(ClusterClientLocation $connections): void
    {
        $this->clientConnections = $connections;
    }

    /**
     * Installs the read-only connection lookup the signal router consults.
     *
     * Separate from {@see registerClientConnections()} so the router binds to the narrow
     * contract and a test can supply a fake for it, exactly as {@see registerWorkerPlacement()}
     * does for placement.
     *
     * @param ClientLocation $clientLocation Connection lookup to install
     */
    public function registerClientLocation(ClientLocation $clientLocation): void
    {
        $this->clientLocation = $clientLocation;
    }

    /**
     * Returns the connection index the peer transport applies announcements to, or null when
     * none is set.
     *
     * @return ?ClusterClientLocation Connection index, or null
     */
    public function clientConnections(): ?ClusterClientLocation
    {
        return $this->clientConnections;
    }

    /**
     * Returns the connection lookup the signal router reads, or null when none is set.
     *
     * Null off-cluster, so the router's cross-node post-pass is inert and every signal to a
     * browser stays local — the same opt-in shape {@see workerPlacement()} has.
     *
     * @return ?ClientLocation Connection lookup, or null
     */
    public function clientLocation(): ?ClientLocation
    {
        return $this->clientLocation;
    }

    /**
     * Registers the local port through which this node's own browser connections are reached.
     *
     * The daemon registers itself here at start, because the WebSocket server the sockets live
     * on is its own. The client-side twin of {@see registerAgentSignalSink()}, which ends at an
     * agent instead.
     *
     * @param ClientSignalSink $sink Local delivery port for cross-node client signals
     */
    public function registerClientSignalSink(ClientSignalSink $sink): void
    {
        $this->clientSignalSink = $sink;
    }

    /**
     * Returns the local delivery port for cross-node client signals, or null when none is set.
     *
     * @return ?ClientSignalSink Delivery port, or null
     */
    public function clientSignalSink(): ?ClientSignalSink
    {
        return $this->clientSignalSink;
    }

    /**
     * Forwards a node-joined transition to the registered observer, if any.
     *
     * @param ClusterNode $node Node that joined (or came back online)
     */
    public function notifyNodeJoined(ClusterNode $node): void
    {
        $this->membershipObserver?->onNodeJoined($node);
    }

    /**
     * Forwards a node-left transition to the registered observer, if any.
     *
     * @param ClusterNode $node Node that left (went offline)
     */
    public function notifyNodeLeft(ClusterNode $node): void
    {
        $this->membershipObserver?->onNodeLeft($node);
    }

    /**
     * Re-reads cluster configuration and refreshes the local node in the registry.
     *
     * Reloads the environment source, rebuilds the local identity from it (so an
     * operator's role/capability/address edits are picked up without a restart),
     * and merges the new record into the master registry. When the record changed
     * meaningfully, the local node is re-announced to the peer mesh so peers
     * converge on it. The node id is treated as stable: changing it requires a
     * restart rather than a reload.
     *
     * @return bool True when the local node record changed meaningfully
     * @throws ClusterDisabledException When cluster mode is disabled
     * @throws ClusterConfigurationException When the reloaded node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     */
    public function reload(): bool
    {
        if (!$this->isEnabled()) {
            throw new ClusterDisabledException('The cluster registry cannot be reloaded while cluster mode is disabled');
        }

        Hilos::$env->reload();
        $this->identity = NodeIdentity::fromEnv();

        $changed = $this->registry()->merge($this->identity, true, microtime(true));
        if ($changed) {
            $this->localAnnouncer?->announceLocalNode();
        }

        return $changed;
    }

    /**
     * Builds the cluster node snapshot answered by the `cluster:nodes` command.
     *
     * Reads the live membership registry (local node plus any connected peers)
     * when enabled, and reports an empty set when cluster mode is off.
     *
     * @return array{enabled: bool, nodes: list<array<string, mixed>>} Node snapshot payload
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     * @throws ClusterDisabledException When cluster mode is disabled
     */
    public function snapshot(): array
    {
        if (!$this->isEnabled()) {
            return [
                ClusterCommandConstants::FIELD_ENABLED => false,
                ClusterCommandConstants::FIELD_NODES => [],
            ];
        }

        $nodes = [];
        foreach ($this->registry()->snapshot() as $node) {
            $nodes[] = [
                ClusterCommandConstants::FIELD_NODE_ID => $node->nodeId,
                ClusterCommandConstants::FIELD_NODE_ROLE => $node->role->value,
                ClusterCommandConstants::FIELD_NODE_CAPABILITIES => $node->capabilities,
                ClusterCommandConstants::FIELD_NODE_ONLINE => $node->online,
            ];
        }

        return [
            ClusterCommandConstants::FIELD_ENABLED => true,
            ClusterCommandConstants::FIELD_NODES => $nodes,
        ];
    }

    /**
     * Builds the rich, machine-readable cluster snapshot answered by the
     * `test:cluster:inspect` command (HIL-325).
     *
     * Reports this daemon's own view — membership, the local node's consensus
     * verdicts (leader / term / role / quorum) and lifecycle phase, and the
     * leader-tracked agent placements — so a multi-node test harness can assert on
     * each node deterministically. A node whose leadership seam is not a consensus
     * coordinator (a slave, or a master before the transport builds one) reports null
     * term and role; placements are empty on any non-leader, since that view is
     * leader-owned soft-state. Reports only `enabled: false` when cluster mode is off.
     *
     * It also reports the browser side of the mesh (HIL-668): how many connections this node's
     * index holds for each node, and how many cross-node deliveries it has accepted for its own
     * browsers. Those two are what a scenario asserts on, because the delivery itself ends at a
     * socket — and `demo/cluster` runs headless, with no socket to watch.
     *
     * @return array<string, mixed> Inspection snapshot payload
     * @throws ClusterConfigurationException When enabled but node config is missing or invalid
     * @throws EnvException When a cluster env value cannot be read
     * @throws ClusterDisabledException When cluster mode is disabled
     */
    public function inspect(): array
    {
        if (!$this->isEnabled()) {
            return [ClusterCommandConstants::FIELD_ENABLED => false];
        }

        $leadership = $this->leadership();
        $consensus = $leadership instanceof ConsensusInspection ? $leadership : null;
        $replicas = $this->rtReplicaInspector?->inspectRtReplicas() ?? [];

        $nodes = [];
        foreach ($this->registry()->snapshot() as $node) {
            $nodes[] = [
                ClusterCommandConstants::FIELD_NODE_ID => $node->nodeId,
                ClusterCommandConstants::FIELD_NODE_ROLE => $node->role->value,
                ClusterCommandConstants::FIELD_NODE_CAPABILITIES => $node->capabilities,
                ClusterCommandConstants::FIELD_NODE_ONLINE => $node->online,
                ClusterCommandConstants::FIELD_NODE_LAST_SEEN => $node->lastSeen,
            ];
        }

        return [
            ClusterCommandConstants::FIELD_ENABLED => true,
            ClusterCommandConstants::FIELD_LOCAL_NODE_ID => $this->identity()->nodeId,
            ClusterCommandConstants::FIELD_LIFECYCLE_STATE => $this->lifecycleState()->name,
            ClusterCommandConstants::FIELD_LEADER_ID => $leadership->leaderId(),
            ClusterCommandConstants::FIELD_TERM => $consensus?->term(),
            ClusterCommandConstants::FIELD_CONSENSUS_ROLE => $consensus?->consensusRole()->value,
            ClusterCommandConstants::FIELD_HAS_QUORUM => $leadership->hasQuorum(),
            ClusterCommandConstants::FIELD_NODES => $nodes,
            ClusterCommandConstants::FIELD_PLACEMENTS => $this->inspectPlacements(),
            ClusterCommandConstants::FIELD_CLIENT_INDEX => $this->clientConnections?->countsByNode() ?? [],
            ClusterCommandConstants::FIELD_CLIENT_DELIVERIES => $this->clientConnections?->deliveries() ?? 0,
            ClusterCommandConstants::FIELD_LAST_CLIENT_ACCEPT_KEY => $this->clientConnections?->lastAcceptKey(),
            ClusterCommandConstants::FIELD_RT_COLLECTIONS => $replicas[ClusterCommandConstants::FIELD_RT_COLLECTIONS]
                ?? [],
            ClusterCommandConstants::FIELD_RT_APPLIED => $replicas[ClusterCommandConstants::FIELD_RT_APPLIED] ?? 0,
            ClusterCommandConstants::FIELD_RT_REFUSED => $replicas[ClusterCommandConstants::FIELD_RT_REFUSED] ?? 0,
            ClusterCommandConstants::FIELD_RT_CLAIM_CONFLICTS =>
                $replicas[ClusterCommandConstants::FIELD_RT_CLAIM_CONFLICTS] ?? 0,
            ClusterCommandConstants::FIELD_RT_CLAIM_REFUSALS =>
                $replicas[ClusterCommandConstants::FIELD_RT_CLAIM_REFUSALS] ?? 0,
            ClusterCommandConstants::FIELD_DB_REPLICAS => $this->dbReplicas,
            ClusterCommandConstants::FIELD_LAST_DB_REPLICA_COLLECTION => $this->lastDbReplicaCollection,
        ];
    }

    /**
     * Builds the placement rows from the leader-side placement view, if present.
     *
     * Empty when this node has no placement coordinator (off-cluster) or is not the
     * leader, since the placement view is leader-owned soft-state.
     *
     * @return list<array<string, mixed>> Placement rows
     */
    private function inspectPlacements(): array
    {
        $placement = $this->placement();
        if ($placement === null) {
            return [];
        }

        $rows = [];
        foreach ($placement->registry()->all() as $record) {
            $rows[] = [
                ClusterCommandConstants::FIELD_PLACEMENT_AGENT_TYPE => $record->agentType,
                ClusterCommandConstants::FIELD_PLACEMENT_AGENT_INDEX => $record->agentIndex,
                ClusterCommandConstants::FIELD_PLACEMENT_AGENT_ID => $record->agentId(),
                ClusterCommandConstants::FIELD_NODE_ID => $record->nodeId,
                ClusterCommandConstants::FIELD_PLACEMENT_STATE => $record->state->value,
            ];
        }

        return $rows;
    }
}
