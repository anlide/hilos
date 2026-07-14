<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

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

    /** @var ?PlacementExecutor Local worker executor for placed agents, registered by the daemon at start. */
    private ?PlacementExecutor $placementExecutor = null;

    /** @var ?ClusterPlacement Agent-placement coordinator, registered by the peer transport at start. */
    private ?ClusterPlacement $placement = null;

    /** @var ?WorkerPlacement Read-only placement lookup the signal router consults, registered by the peer transport at start. */
    private ?WorkerPlacement $workerPlacement = null;

    /** @var ?AgentSignalSink Local delivery port for cross-node signals, registered by the daemon at start. */
    private ?AgentSignalSink $agentSignalSink = null;

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
     * The peer transport builds the {@see \Hilos\Cluster\Consensus\ClusterCoordinator}
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
}
