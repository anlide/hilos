<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Exception\PlacementCapabilityException;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\HilosException;
use Hilos\Utils\Logger;
use Throwable;

/**
 * The mechanism to launch and track an agent-of-type-X on a named node over the peer
 * channel — the leader placement side and the node execution side in one flat unit.
 *
 * Every clustered node builds one (the peer transport does so at start, wiring the
 * transport as its {@see PlacementMesh} and the worker server as its
 * {@see PlacementExecutor}). Two sides share it:
 *
 * - Leader side: {@see placeAgentOnNode()} / {@see stopAgentOnNode()} are the permanent
 *   remote-placement primitive, and {@see placeAgentOnBestNode()} the automatic entry that
 *   picks the target itself. A placement routed at `self` runs the local start path; any
 *   other node id sends a {@see PeerPlaceAgentDTO} over the mesh. Every placement passes the
 *   hard gate first — the target must advertise the required capability tags and meet the
 *   required capacity minimums — before anything is sent. Outcomes are tracked in the
 *   soft-state {@see PlacementRegistry}, which a fresh leader rebuilds from node reports on
 *   {@see onBecameLeader()}.
 * - Node side: an inbound place/stop frame runs the local execute/revoke and replies with
 *   a {@see PeerAgentStatusDTO}; a rebuild query is answered from the node's own hosted
 *   set. This is what a data-plane slave does with the placements a leader hands it.
 *
 * Automatic node-choosing is HIL-182's {@see PlacementPolicy}, which this coordinator
 * delegates the "which node" question to for both {@see placeAgentOnBestNode()} and failover.
 * It also serves as the read side of {@see WorkerPlacement}: the signal router asks
 * {@see nodeFor()} where a placed agent lives so HIL-180 can forward work signals cross-node.
 *
 * Crash-failover (HIL-183) hangs off the same two sides. Driven by node up/down transitions
 * ({@see noteNodeOffline()} / {@see noteNodeOnline()}) and a grace-timer {@see tick()}: the
 * leader re-places a dead node's agents onto another capable node after
 * `CLUSTER_FAILOVER_GRACE_MS`, degrading an agent to {@see PlacementState::Unplaced} (and
 * notifying the {@see PlacementObserver}) when no capable node is online; a node isolated
 * from the leader that placed its work self-fences those agents after
 * `CLUSTER_SLAVE_WORK_GRACE_MS` (held at or below the failover grace, so the old copy stops
 * before the leader starts a new one). On rejoin a node reports what it still hosts
 * ({@see onPeerHandshaked()}) and the leader reconciles against its view (leader = truth),
 * stopping anything already re-placed elsewhere.
 */
final class ClusterPlacement implements WorkerPlacement
{
    /** @var int Default leader failover grace in ms when none is configured */
    private const int DEFAULT_FAILOVER_GRACE_MS = 8000;

    /** @var int Default slave self-fence grace in ms when none is configured */
    private const int DEFAULT_SLAVE_WORK_GRACE_MS = 6000;

    /** @var string Id of the node this coordinator runs on */
    private string $selfNodeId;

    /** @var PlacementMesh Outbound port to reach nodes and read their advertised capabilities */
    private PlacementMesh $mesh;

    /** @var PlacementExecutor Local port to launch, stop, and describe agents on this node */
    private PlacementExecutor $executor;

    /** @var PlacementObserver Seam that receives placement-degradation events */
    private PlacementObserver $observer;

    /** @var PlacementPolicy Node-selection policy that ranks capable nodes for best-fit placement */
    private PlacementPolicy $policy;

    /** @var float Leader failover grace in seconds */
    private float $failoverGraceSec;

    /** @var float Slave self-fence grace in seconds */
    private float $slaveWorkGraceSec;

    /** @var PlacementRegistry Leader-side soft-state view of every placement, cluster-wide */
    private PlacementRegistry $registry;

    /** @var array<string, PlacementRecord> Agents this node currently hosts, keyed by agent id */
    private array $hosted = [];

    /** @var bool True while this node holds leadership and owns the placement view */
    private bool $isLeader = false;

    /** @var array<string, float> Failover deadline (microtime) per orphaned agent id awaiting re-placement */
    private array $failoverDeadlines = [];

    /** @var array<string, string> Hosting node id per agent id, as the leader last published it; empty on the leader */
    private array $placementView = [];

    /** @var ?string Fingerprint of the view this leader last published, or null when it has published none */
    private ?string $publishedViewFingerprint = null;

    /** @var ?string Node id of the leader that placed this node's hosted agents, for self-fence detection */
    private ?string $placingLeaderId = null;

    /** @var ?float Self-fence deadline (microtime) after the placing leader was lost, or null when not isolated */
    private ?float $selfFenceDeadline = null;

    /**
     * @param string $selfNodeId Id of the node this coordinator runs on
     * @param PlacementMesh $mesh Outbound port to reach nodes and read capabilities
     * @param PlacementExecutor $executor Local port to launch and stop agents on this node
     * @param ?PlacementObserver $observer Degradation seam; a no-op observer when null
     * @param int $failoverGraceMs Leader failover grace in ms
     * @param int $slaveWorkGraceMs Slave self-fence grace in ms
     * @param ?PlacementPolicy $policy Node-selection policy; the best-fit policy when null
     */
    public function __construct(
        string $selfNodeId,
        PlacementMesh $mesh,
        PlacementExecutor $executor,
        ?PlacementObserver $observer = null,
        int $failoverGraceMs = self::DEFAULT_FAILOVER_GRACE_MS,
        int $slaveWorkGraceMs = self::DEFAULT_SLAVE_WORK_GRACE_MS,
        ?PlacementPolicy $policy = null,
    ) {
        $this->selfNodeId = $selfNodeId;
        $this->mesh = $mesh;
        $this->executor = $executor;
        $this->observer = $observer ?? new NullPlacementObserver();
        $this->failoverGraceSec = $failoverGraceMs / TimeConstants::MS_PER_SECOND;
        $this->slaveWorkGraceSec = $slaveWorkGraceMs / TimeConstants::MS_PER_SECOND;
        $this->policy = $policy ?? new BestFitPlacementPolicy();
        $this->registry = new PlacementRegistry();
    }

    /**
     * Returns the leader-side placement view for inspection.
     *
     * @return PlacementRegistry Placement registry
     */
    public function registry(): PlacementRegistry
    {
        return $this->registry;
    }

    /**
     * Reports which node hosts an agent so the signal router can forward cross-node.
     *
     * An agent placed on another node returns that node's id; one placed on this node, or one
     * nobody has placed, returns null so the router keeps delivering it locally. The leader
     * answers from the placement view it owns, every other node from the copy that leader
     * publishes to it (HIL-668); {@see hostingNode()} is where the two are reconciled. Before
     * the first copy arrives a node answers null for everything, which is the behaviour it had
     * when there was no copy at all.
     *
     * @param string $agentType Agent type to look up
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?string Hosting node id when remote, or null for local / unknown / unplaced
     */
    public function nodeFor(string $agentType, ?string $agentIndex): ?string
    {
        $nodeId = $this->hostingNode($this->agentId($agentType, $agentIndex));

        return $nodeId === $this->selfNodeId ? null : $nodeId;
    }

    /**
     * Places an agent of the given type on a named node: the permanent remote-placement
     * primitive.
     *
     * Passes the target node through the hard gate (required capability tags and capacity
     * minimums) first and rejects before anything is sent when it does not fit. A placement at
     * this node runs the local start path synchronously; any other node id sends a place frame
     * and records the placement as pending until the node's status reply lands. To let the
     * policy choose the node instead of naming one, use {@see placeAgentOnBestNode()}.
     *
     * @param string $agentType Agent type to launch
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Id of the node to place the agent on
     * @throws PlacementCapabilityException When the node lacks a required tag or capacity minimum
     * @throws AgentDaemonCreationFailedException When a local placement's daemon cannot be built
     * @throws NoSuitableWorkerException When a local placement has no worker to host it
     * @throws AgentNotLinkedToWorkerException When a local placement did not link to a worker
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function placeAgentOnNode(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        $this->requirePlacementFit($agentType, $agentIndex, $nodeId);

        if ($nodeId === $this->selfNodeId) {
            $this->placeLocally($agentType, $agentIndex);
            return;
        }

        $delivered = $this->mesh->sendToNode($nodeId, new PeerPlaceAgentDTO($agentType, $agentIndex));
        $state = $delivered ? PlacementState::Placing : PlacementState::Failed;
        $this->registry->put(new PlacementRecord($agentType, $agentIndex, $nodeId, $state));

        Logger::info("Placing agent '{$this->agentId($agentType, $agentIndex)}' on node '{$nodeId}'"
            . ($delivered ? '' : ' failed: node is not linked'));
    }

    /**
     * Places an agent on the node the policy picks as the best fit: the automatic
     * node-selection entry (HIL-182) layered on the named-node primitive.
     *
     * Reads the agent's required tags and resource profile, asks the {@see PlacementPolicy} to
     * rank the online nodes by fit, and places on the winner via {@see placeAgentOnNode()}.
     * When no online node clears the hard gate nothing is placed and null is returned, so the
     * caller can retry on the next capable join rather than fail. A heavy worker thus lands on
     * a strong node, a light one anywhere it fits.
     *
     * @param string $agentType Agent type to launch
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?string Chosen node id the agent was placed on, or null when no node is a fit
     * @throws PlacementCapabilityException When the chosen node no longer meets the hard gate
     * @throws AgentDaemonCreationFailedException When a local placement's daemon cannot be built
     * @throws NoSuitableWorkerException When a local placement has no worker to host it
     * @throws AgentNotLinkedToWorkerException When a local placement did not link to a worker
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function placeAgentOnBestNode(string $agentType, ?string $agentIndex): ?string
    {
        $required = $this->executor->requiredCapabilities($agentType, $agentIndex);
        $profile = $this->executor->placementProfile($agentType, $agentIndex);
        $target = $this->pickBestNode($required, $profile, '');
        if ($target === null) {
            Logger::info("No capable node to place agent '{$this->agentId($agentType, $agentIndex)}'");
            return null;
        }

        $this->placeAgentOnNode($agentType, $agentIndex, $target);

        return $target;
    }

    /**
     * Stops a placed agent on a named node.
     *
     * A stop at this node runs the local revoke synchronously; any other node id sends a
     * stop frame. Either way the agent is dropped from the placement view. A move or
     * rebalance is a stop followed by a place; the rebalance policy is HIL-182 / HIL-183.
     *
     * @param string $agentType Agent type to stop
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Id of the node the agent is placed on
     */
    public function stopAgentOnNode(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        if ($nodeId === $this->selfNodeId) {
            $this->executor->revokePlacement($agentType, $agentIndex);
            unset($this->hosted[$this->agentId($agentType, $agentIndex)]);
        } else {
            $this->mesh->sendToNode($nodeId, new PeerStopAgentDTO($agentType, $agentIndex));
        }

        $this->registry->forget($this->agentId($agentType, $agentIndex));
    }

    /**
     * Node side: launches a leader-requested agent locally and reports the outcome.
     *
     * Reuses the local start path — no new spawn logic — and answers the leader with a
     * started status carrying the worker id, or a failed status carrying the reason. A
     * failure is caught and reported rather than propagated, so a bad placement never
     * tears down the daemon loop.
     *
     * @param string $fromNodeId Id of the leader node that requested the placement
     * @param PeerPlaceAgentDTO $frame Received place-agent frame
     */
    public function onPlaceAgent(string $fromNodeId, PeerPlaceAgentDTO $frame): void
    {
        $agentType = $frame->agentType;
        $agentIndex = $frame->agentIndex;

        try {
            $workerId = $this->executor->executePlacement($agentType, $agentIndex);
        } catch (Throwable $e) {
            Logger::warning("Placement of '{$this->agentId($agentType, $agentIndex)}' failed: {$e->getMessage()}");
            $this->mesh->sendToNode($fromNodeId, PeerAgentStatusDTO::failed($agentType, $agentIndex, $e->getMessage()));
            return;
        }

        $this->hosted[$this->agentId($agentType, $agentIndex)] = new PlacementRecord(
            $agentType,
            $agentIndex,
            $this->selfNodeId,
            PlacementState::Started,
        );
        // Remember which leader placed our work so its loss triggers the self-fence.
        $this->placingLeaderId = $fromNodeId;
        $this->mesh->sendToNode($fromNodeId, PeerAgentStatusDTO::started($agentType, $agentIndex, $workerId));
    }

    /**
     * Node side: stops a leader-requested agent locally and confirms with a stopped status.
     *
     * @param string $fromNodeId Id of the leader node that requested the stop
     * @param PeerStopAgentDTO $frame Received stop-agent frame
     */
    public function onStopAgent(string $fromNodeId, PeerStopAgentDTO $frame): void
    {
        $this->executor->revokePlacement($frame->agentType, $frame->agentIndex);
        unset($this->hosted[$this->agentId($frame->agentType, $frame->agentIndex)]);
        $this->mesh->sendToNode($fromNodeId, PeerAgentStatusDTO::stopped($frame->agentType, $frame->agentIndex));
    }

    /**
     * Leader side: folds a node's placement status into the placement view.
     *
     * A stopped status forgets the placement; a started or failed status records it
     * against the reporting node so the view reflects where each agent actually landed.
     *
     * @param string $fromNodeId Id of the node that reported the status
     * @param PeerAgentStatusDTO $frame Received agent-status frame
     */
    public function onAgentStatus(string $fromNodeId, PeerAgentStatusDTO $frame): void
    {
        if ($frame->state === PlacementState::Stopped) {
            $this->registry->forget($this->agentId($frame->agentType, $frame->agentIndex));
            return;
        }

        $this->registry->put(new PlacementRecord($frame->agentType, $frame->agentIndex, $fromNodeId, $frame->state));
    }

    /**
     * Node side: answers a leader's rebuild query with this node's hosted-agent set.
     *
     * @param string $fromNodeId Id of the leader node that asked
     */
    public function onPlacementQuery(string $fromNodeId): void
    {
        $this->mesh->sendToNode($fromNodeId, new PeerPlacementReportDTO($this->hostedEntries()));
    }

    /**
     * Leader side: folds a node's hosted-agent report into the placement view, reconciling
     * against the leader-owned truth.
     *
     * Two callers land here: a fresh leader's rebuild broadcast ({@see onBecameLeader()}) and
     * a node's rejoin report ({@see onPeerHandshaked()}). For each reported agent the leader
     * is the arbiter — if it already tracks that agent on a different node (it was re-placed
     * there while this node was gone, or another node hosts it), the reporting node is told to
     * stop its stale copy so a moved agent is never resurrected; otherwise the report is
     * accepted, which both rebuilds the view and lets a returning node re-adopt an agent that
     * failover had left {@see PlacementState::Unplaced}. Ignored on a non-leader, whose
     * placement view is inert.
     *
     * @param string $fromNodeId Id of the node that reported
     * @param PeerPlacementReportDTO $frame Received placement report
     */
    public function onPlacementReport(string $fromNodeId, PeerPlacementReportDTO $frame): void
    {
        if (!$this->isLeader) {
            return;
        }

        foreach ($frame->agents as $entry) {
            $agentId = $this->agentId($entry->agentType, $entry->agentIndex);
            $existing = $this->registry->get($agentId);
            if ($existing !== null && $existing->nodeId !== $fromNodeId && $existing->state !== PlacementState::Unplaced) {
                $this->mesh->sendToNode($fromNodeId, new PeerStopAgentDTO($entry->agentType, $entry->agentIndex));
                Logger::info("Reconcile: telling node '{$fromNodeId}' to stop '{$agentId}' already placed on '{$existing->nodeId}'");
                continue;
            }

            $this->registry->put(new PlacementRecord($entry->agentType, $entry->agentIndex, $fromNodeId, PlacementState::Started));
        }
    }

    /**
     * Node side: takes the leader's picture of where every agent runs (HIL-668).
     *
     * Replaces the held copy whole rather than merging: the frame is the leader's complete
     * answer, so a merge would keep an agent this leader no longer places. Ignored on the
     * leader, whose own view is the original this one is a copy of — the same rule
     * {@see onPlacementReport()} applies in the other direction.
     *
     * A frame whose own leader id is not the node it arrived from is dropped: the only sender
     * of a view is the leader stamping itself, so the two disagreeing means it was relayed, and
     * a relayed picture is one hop older than whatever its sender has. What is NOT checked is
     * that the sender is the CURRENT leader, because this coordinator cannot know — leadership
     * is the consensus layer's fact. The copy is soft state a deposed leader could briefly keep
     * alive, and it self-corrects: a fresh leader clears its registry and reseeds it on winning
     * the term, so its own first publish follows within a tick.
     *
     * @param string $fromNodeId Id of the node the view arrived from
     * @param PeerPlacementViewDTO $frame Received placement-view frame
     */
    public function onPlacementView(string $fromNodeId, PeerPlacementViewDTO $frame): void
    {
        if ($this->isLeader) {
            return;
        }

        if ($frame->leaderNodeId !== $fromNodeId) {
            Logger::warning(
                "Dropping placement view of leader '{$frame->leaderNodeId}' relayed by node '{$fromNodeId}'",
            );
            return;
        }

        $view = [];
        foreach ($frame->agents as $nodeId => $entries) {
            // Back to a string, because on the wire a node id spends a leg as an array KEY and
            // PHP has no string key that reads as a decimal integer: a node named "2" arrives
            // as int 2. What comes out of here is answered to {@see nodeFor()} callers, whose
            // contract is a node id or null.
            $nodeId = (string)$nodeId;
            foreach ($entries as $entry) {
                $view[$this->agentId($entry->agentType, $entry->agentIndex)] = $nodeId;
            }
        }

        $this->placementView = $view;
    }

    /**
     * Leader side: rebuilds the placement view on winning a term.
     *
     * Placement tracking is soft-state, so a fresh leader starts from nothing: it seeds
     * the view with its own hosted agents, then broadcasts a rebuild query so every other
     * node reports the placements it is running. Called from the leadership transition.
     */
    public function onBecameLeader(): void
    {
        $this->isLeader = true;
        $this->registry->clear();
        // The copy this node held as a follower is somebody else's answer to the question it
        // now owns; from here the registry above is the original.
        $this->placementView = [];
        $this->publishedViewFingerprint = null;
        foreach ($this->hosted as $record) {
            $this->registry->put($record);
        }

        $this->mesh->broadcastToNodes(new PeerPlacementQueryDTO());
    }

    /**
     * Leader side: drops the placement view on losing leadership.
     *
     * The node keeps hosting the agents it was placed with — they are data-plane and run
     * on regardless of who leads — but it no longer owns the cluster-wide view, which the
     * next leader rebuilds from the mesh. Any pending failover timers drop with the view;
     * the next leader re-derives them from its own rebuilt placements.
     */
    public function onLostLeadership(): void
    {
        $this->isLeader = false;
        $this->registry->clear();
        $this->failoverDeadlines = [];
        // Publishing is the leader's duty, so this node stops; what it published stays true
        // until the next leader publishes its own, which it does within a tick of winning.
        $this->publishedViewFingerprint = null;
    }

    /**
     * Reacts to a node going offline: schedules failover and, if isolated, arms self-fence.
     *
     * Leader side — for every placed agent the offline node hosted, arms a failover deadline
     * one grace period out, absorbing a brief flap before {@see tick()} re-places it. Node
     * side — if the offline node is the leader that placed this node's work, arms the
     * self-fence deadline so those agents stop before the leader could start copies elsewhere.
     * Both are idempotent: a deadline already armed is left as it stands.
     *
     * @param string $nodeId Node id the transport just marked offline
     * @param float $now Current microtime
     */
    public function noteNodeOffline(string $nodeId, float $now): void
    {
        if ($this->isLeader) {
            foreach ($this->registry->all() as $record) {
                if ($record->nodeId === $nodeId
                    && $record->state !== PlacementState::Unplaced
                    && !isset($this->failoverDeadlines[$record->agentId()])) {
                    $this->failoverDeadlines[$record->agentId()] = $now + $this->failoverGraceSec;
                }
            }
        }

        if ($nodeId === $this->placingLeaderId && $this->hosted !== [] && $this->selfFenceDeadline === null) {
            $this->selfFenceDeadline = $now + $this->slaveWorkGraceSec;
        }
    }

    /**
     * Reacts to a node coming online: cancels a stale failover/self-fence, retries degraded.
     *
     * Node side — if the returning node is the placing leader, the isolation is over, so the
     * self-fence is disarmed. Leader side — a flapped node back before its grace keeps its
     * agents, so its pending failover is cancelled; and since a capable node may now be
     * available, every agent failover had to leave {@see PlacementState::Unplaced} is retried.
     *
     * @param string $nodeId Node id the transport just marked online
     * @param float $now Current microtime
     */
    public function noteNodeOnline(string $nodeId, float $now): void
    {
        if ($nodeId === $this->placingLeaderId) {
            $this->selfFenceDeadline = null;
        }

        if (!$this->isLeader) {
            return;
        }

        foreach ($this->registry->all() as $record) {
            if ($record->nodeId === $nodeId) {
                unset($this->failoverDeadlines[$record->agentId()]);
            }
        }

        $this->retryUnplaced();
    }

    /**
     * Fires any failover or self-fence whose grace has elapsed. Driven each daemon tick.
     *
     * @param float $now Current microtime
     */
    public function tick(float $now): void
    {
        foreach ($this->failoverDeadlines as $agentId => $deadline) {
            if ($now >= $deadline) {
                unset($this->failoverDeadlines[$agentId]);
                $this->failOver($agentId);
            }
        }

        if ($this->selfFenceDeadline !== null && $now >= $this->selfFenceDeadline) {
            $this->selfFenceDeadline = null;
            $this->selfFence();
        }

        $this->publishPlacementView();
    }

    /**
     * Trades pictures with a freshly-linked peer: what this node hosts, and — if it leads —
     * where everything runs.
     *
     * Node side is the reconcile-on-rejoin safety net: after a partition a node may still host
     * agents the leader re-placed elsewhere, so on every new link it sends what it hosts and
     * lets the leader ({@see onPlacementReport()}) stop the stale copies. A no-op when this node
     * hosts nothing; a non-leader peer that receives the report simply ignores it.
     *
     * Leader side hands the newcomer the whole placement view (HIL-668), because that is the one
     * thing the per-tick publish cannot do for it: the publish speaks only on CHANGE, so a node
     * that linked into a quiet cluster would learn nothing until something moved.
     *
     * @param string $nodeId Node id of the peer that just handshaked
     */
    public function onPeerHandshaked(string $nodeId): void
    {
        if ($this->isLeader) {
            $this->mesh->sendToNode($nodeId, new PeerPlacementViewDTO($this->selfNodeId, $this->placementViewAgents()));
        }

        if ($this->hosted === []) {
            return;
        }

        $this->mesh->sendToNode($nodeId, new PeerPlacementReportDTO($this->hostedEntries()));
    }

    /**
     * Leader side: broadcasts the placement view whenever it has changed (HIL-668).
     *
     * Driven by a per-tick comparison rather than by a call at each place the registry is
     * written, and for the reason the connection index is: the registry is written from eight
     * places — a status reply, a rejoin report, a placement, a stop, a failover, a degrade, a
     * retry, a fresh term — and the one that got no call would leave every other node holding a
     * picture that is wrong forever, with nothing to correct it. A comparison cannot miss a
     * path because it never looks at them, and it turns a failover storm into one frame.
     *
     * Silent when nothing moved, which is nearly every tick, and silent on a node that does not
     * lead. The whole step costs one pass over a few dozen records.
     */
    private function publishPlacementView(): void
    {
        if (!$this->isLeader) {
            return;
        }

        $agents = $this->placementViewAgents();
        $fingerprint = $this->viewFingerprint($agents);
        if ($fingerprint === $this->publishedViewFingerprint) {
            return;
        }

        $this->publishedViewFingerprint = $fingerprint;
        $this->mesh->broadcastToNodes(new PeerPlacementViewDTO($this->selfNodeId, $agents));
    }

    /**
     * Groups the placements worth forwarding to by the node hosting them.
     *
     * Degraded (unplaced) agents are left out under the same rule {@see hostingNode()} applies
     * on this node: an agent that runs nowhere has no node to forward to. Because both sides
     * read this one rule, a copy answers exactly what the original would.
     *
     * @return array<string|int, list<PeerPlacedAgentEntry>> Hosted agent entries, by node id
     */
    private function placementViewAgents(): array
    {
        $agents = [];
        foreach ($this->registry->all() as $record) {
            if ($record->state === PlacementState::Unplaced) {
                continue;
            }

            $agents[$record->nodeId][] = new PeerPlacedAgentEntry($record->agentType, $record->agentIndex);
        }

        return $agents;
    }

    /**
     * Renders a view into a value that changes when, and only when, the view does.
     *
     * Sorted, so that the same placements read out in a different order — which the registry is
     * free to do after any forget — are recognized as the same view and cost no frame.
     *
     * @param array<string|int, list<PeerPlacedAgentEntry>> $agents Hosted agent entries, by node id
     * @return string Comparable rendering of the view
     */
    private function viewFingerprint(array $agents): string
    {
        $lines = [];
        foreach ($agents as $nodeId => $entries) {
            foreach ($entries as $entry) {
                $lines[] = $nodeId . '/' . $this->agentId($entry->agentType, $entry->agentIndex);
            }
        }

        sort($lines);

        return implode(',', $lines);
    }

    /**
     * Answers which node hosts an agent: from what this node placed itself, else from what the
     * leader told it.
     *
     * The registry comes first because on the leader it IS the truth — the copy is derived from
     * it — and asked first it also keeps a node that never took a term answering exactly as it
     * did before this frame existed. The published copy answers everywhere else, which is the
     * whole point: a non-leader used to answer null for every agent in the cluster and deliver
     * the signal into its own empty floor.
     *
     * The two cannot disagree in a way that matters, because the copy is built from these very
     * records under the rule applied here — a degraded agent runs nowhere, so it is left out of
     * both.
     *
     * @param string $agentId Agent id to look up
     * @return ?string Hosting node id, or null when nothing places it
     */
    private function hostingNode(string $agentId): ?string
    {
        $record = $this->registry->get($agentId);
        if ($record !== null && $record->state !== PlacementState::Unplaced) {
            return $record->nodeId;
        }

        return $this->placementView[$agentId] ?? null;
    }

    /**
     * Re-places one orphaned agent onto another capable+online node, degrading it when none.
     *
     * Skips an agent that was stopped, moved, or already degraded in the meantime. Otherwise
     * it asks the policy for the best-fit node in the online set (excluding the lost node) and
     * re-runs the ordinary placement primitive onto the pick; when no node is a fit, or the
     * re-placement fails, the agent is degraded to {@see PlacementState::Unplaced}. Any error
     * is caught so a bad failover never tears down the daemon loop.
     *
     * @param string $agentId Agent id whose failover grace has elapsed
     */
    private function failOver(string $agentId): void
    {
        $record = $this->registry->get($agentId);
        if ($record === null || $record->state === PlacementState::Unplaced) {
            return;
        }

        try {
            $required = $this->executor->requiredCapabilities($record->agentType, $record->agentIndex);
            $profile = $this->executor->placementProfile($record->agentType, $record->agentIndex);
            $target = $this->pickBestNode($required, $profile, $record->nodeId);
            if ($target !== null) {
                Logger::info("Failover: re-placing '{$agentId}' from lost node '{$record->nodeId}' onto '{$target}'");
                $this->placeAgentOnNode($record->agentType, $record->agentIndex, $target);
                return;
            }
        } catch (Throwable $e) {
            Logger::warning("Failover of '{$agentId}' could not re-place: {$e->getMessage()}");
        }

        $this->degrade($record);
    }

    /**
     * Retries every degraded agent, placing it if a capable node is now online.
     *
     * Called when a node comes online (a new capability may have appeared). A still-uncoverable
     * agent stays {@see PlacementState::Unplaced}; an error on one agent is caught so the rest
     * are still tried.
     */
    private function retryUnplaced(): void
    {
        foreach ($this->registry->all() as $record) {
            if ($record->state !== PlacementState::Unplaced) {
                continue;
            }

            try {
                $required = $this->executor->requiredCapabilities($record->agentType, $record->agentIndex);
                $profile = $this->executor->placementProfile($record->agentType, $record->agentIndex);
                $target = $this->pickBestNode($required, $profile, '');
                if ($target !== null) {
                    Logger::info("Failover retry: placing unplaced '{$record->agentId()}' onto '{$target}'");
                    $this->placeAgentOnNode($record->agentType, $record->agentIndex, $target);
                }
            } catch (Throwable $e) {
                Logger::warning("Failover retry of '{$record->agentId()}' failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Marks an agent degraded and notifies the observer.
     *
     * @param PlacementRecord $record Record of the agent that could not be placed
     */
    private function degrade(PlacementRecord $record): void
    {
        $this->registry->put($record->withState(PlacementState::Unplaced));
        Logger::warning("Failover: no capable+online node for '{$record->agentId()}'; marked unplaced");
        $this->observer->onPlacementDegraded($record->agentType, $record->agentIndex);
    }

    /**
     * Picks the best-fit online node other than the excluded one, or null when none is a fit.
     *
     * Builds the candidate set from the online nodes' advertised capacities, counts what each
     * one already runs, and hands the ranking to the {@see PlacementPolicy}: the hard gate
     * (required tags plus capacity minimums) and the soft best-fit preference both live in the
     * policy, so failover and the automatic entry choose identically. Occupancy comes from this
     * leader's own placement view, which is the only cluster-wide record of who runs what.
     *
     * @param list<string> $required Capability tags the agent needs
     * @param ResourceProfile $profile Numeric hard minimums and soft preferences of the agent
     * @param string $excludeNodeId Node id to skip (the lost host, or '' to exclude none)
     * @return ?string Chosen node id, or null when no online node is a fit
     */
    private function pickBestNode(array $required, ResourceProfile $profile, string $excludeNodeId): ?string
    {
        $candidates = [];
        $hosted = [];
        foreach ($this->mesh->onlineNodeIds() as $nodeId) {
            if ($nodeId === $excludeNodeId) {
                continue;
            }

            $candidates[$nodeId] = NodeCapacities::fromTags($this->mesh->nodeCapabilities($nodeId) ?? []);
            $hosted[$nodeId] = 0;
        }

        // Only a live placement occupies a node: an unplaced agent runs nowhere, and a
        // stopped or failed one has already released whatever it held.
        foreach ($this->registry->all() as $record) {
            if (isset($hosted[$record->nodeId])
                && ($record->state === PlacementState::Placing || $record->state === PlacementState::Started)) {
                $hosted[$record->nodeId]++;
            }
        }

        return $this->policy->selectNode($required, $profile, $candidates, $hosted);
    }

    /**
     * Node side: stops every agent this node hosts when it is isolated from its placing leader.
     *
     * Prevents a double-run: an isolated node stops its (possibly truth-source) agents before
     * the leader's failover could start copies elsewhere. Reconnect is left to the existing
     * peer dial retry; on rejoin the node re-adopts nothing on its own.
     */
    private function selfFence(): void
    {
        if ($this->hosted === []) {
            return;
        }

        Logger::warning(
            "Self-fence: isolated from placing leader '{$this->placingLeaderId}', stopping " . count($this->hosted) . ' placed agent(s)',
        );
        foreach ($this->hosted as $record) {
            $this->executor->revokePlacement($record->agentType, $record->agentIndex);
        }

        $this->hosted = [];
        $this->placingLeaderId = null;
    }

    /**
     * Builds the wire entries for the agents this node currently hosts.
     *
     * @return list<PeerPlacedAgentEntry> Hosted-agent entries
     */
    private function hostedEntries(): array
    {
        return array_map(
            static fn(PlacementRecord $record): PeerPlacedAgentEntry => new PeerPlacedAgentEntry(
                $record->agentType,
                $record->agentIndex,
            ),
            array_values($this->hosted),
        );
    }

    /**
     * Rejects a placement the target node cannot satisfy: a missing required capability tag or
     * a declared capacity below a required minimum.
     *
     * The hard gate both the named-node and best-fit paths pass through, so a placement never
     * launches an agent on an unfit node. Ranking among fit nodes is the policy's job and never
     * lands here.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Target node id
     * @throws PlacementCapabilityException When a required tag is missing or a capacity minimum is unmet
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built to read its requirements
     */
    private function requirePlacementFit(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        $advertised = $this->mesh->nodeCapabilities($nodeId) ?? [];
        $missing = array_values(array_diff($this->executor->requiredCapabilities($agentType, $agentIndex), $advertised));
        if ($missing !== []) {
            throw PlacementCapabilityException::unmetCapabilities($nodeId, $this->agentId($agentType, $agentIndex), $missing);
        }

        $capacities = NodeCapacities::fromTags($advertised);
        $shortfalls = [];
        foreach ($this->executor->placementProfile($agentType, $agentIndex)->minimums as $key => $minimum) {
            if ($capacities->capacity($key) < $minimum) {
                $shortfalls[$key] = $minimum;
            }
        }

        if ($shortfalls !== []) {
            throw PlacementCapabilityException::unmetResources($nodeId, $this->agentId($agentType, $agentIndex), $shortfalls);
        }
    }

    /**
     * Runs a placement on this node synchronously, tracking the outcome in the view.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built
     * @throws NoSuitableWorkerException When no worker is available to host it
     * @throws AgentNotLinkedToWorkerException When the agent did not link to a worker
     */
    private function placeLocally(string $agentType, ?string $agentIndex): void
    {
        $record = new PlacementRecord($agentType, $agentIndex, $this->selfNodeId, PlacementState::Started);

        try {
            $this->executor->executePlacement($agentType, $agentIndex);
        } catch (AgentDaemonCreationFailedException | NoSuitableWorkerException | AgentNotLinkedToWorkerException $e) {
            $this->registry->put($record->withState(PlacementState::Failed));
            throw $e;
        }

        $this->hosted[$record->agentId()] = $record;
        $this->registry->put($record);
    }

    /**
     * Builds the agent id ("type" or "type:index") a placement keys on.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return string Agent id
     */
    private function agentId(string $agentType, ?string $agentIndex): string
    {
        return $agentIndex !== null ? $agentType . AgentConstants::ID_SEPARATOR . $agentIndex : $agentType;
    }
}
