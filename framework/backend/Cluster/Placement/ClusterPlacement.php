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
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
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
 * On-demand placement (HIL-628) is a third way in, and it spans both sides: an instance agent is
 * started by being ADDRESSED, so a node that finds no address for one asks through
 * {@see requirePlacement()} and the leader answers through {@see onPlacementRequest()}; when that
 * agent later stops itself after its declared silence, the node it ran on reports it back through
 * {@see noteAgentStopped()} so the view stops naming a host that has none.
 *
 * Automatic node-choosing is HIL-182's {@see PlacementPolicy}, which this coordinator
 * delegates the "which node" question to for both {@see placeAgentOnBestNode()} and failover.
 * It also serves as the read side of {@see WorkerPlacement}: the signal router asks
 * {@see locate()} where an agent lives so HIL-180 can forward work signals cross-node.
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
 * stopping anything already re-placed elsewhere. A node that hosts an agent the published view
 * gives to another node reports the same snapshot at once, without waiting for a relink
 * ({@see reportAgentsPlacedElsewhere()}, HIL-976). The wait for a placement to be acknowledged is
 * bounded by the same kind of timer (HIL-930): after `CLUSTER_PLACEMENT_ACK_TIMEOUT_MS` the
 * leader ASKS the node what it hosts ({@see sweepPlacementAcks()}) rather than re-placing the
 * agent blind, and a status arriving from a node the record no longer names is refused instead
 * of written ({@see onAgentStatus()}).
 */
final class ClusterPlacement implements WorkerPlacement
{
    /** @var int Default leader failover grace in ms when none is configured */
    private const int DEFAULT_FAILOVER_GRACE_MS = 8000;

    /** @var int Default slave self-fence grace in ms when none is configured */
    private const int DEFAULT_SLAVE_WORK_GRACE_MS = 6000;

    /** @var int Default placement-ack timeout in ms when none is configured */
    private const int DEFAULT_PLACEMENT_ACK_TIMEOUT_MS = 16000;

    /**
     * @var float Seconds a placement accepted without a worker waits for one before it is answered
     *     failed (HIL-998): the agent's own wait plus a second, so the answer meets the agent's
     *     verdict rather than racing it - the same arithmetic the master's frame hold uses. Well
     *     inside {@see DEFAULT_PLACEMENT_ACK_TIMEOUT_MS}, so the leader never gives up first.
     */
    private const float DEFERRED_PLACEMENT_WAIT_SEC = AgentConstants::START_DEADLINE_SECONDS + 1.0;

    /** @var string Reason a deferred placement is answered failed with when no worker came up */
    private const string DEFERRED_PLACEMENT_FAILED_REASON = 'no monopolistic worker came up within the start deadline';

    /** @var float Seconds one agent's placement ask silences the next one for */
    private const float PLACEMENT_ASK_INTERVAL_SEC = 5.0;

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

    /** @var float Placement-ack timeout in seconds */
    private float $placementAckTimeoutSec;

    /** @var PlacementRegistry Leader-side soft-state view of every placement, cluster-wide */
    private PlacementRegistry $registry;

    /** @var array<string, PlacementRecord> Agents this node currently hosts, keyed by agent id */
    private array $hosted = [];

    /** @var bool True while this node holds leadership and owns the placement view */
    private bool $isLeader = false;

    /**
     * Failover deadline per orphaned agent id awaiting re-placement, with the node it was armed
     * for: by the time it elapses the agent may sit on another node, and only the armed node
     * tells the leader whether that deadline still speaks about where the agent is.
     *
     * @var array<string, array{nodeId: string, deadline: float}>
     */
    private array $failoverDeadlines = [];

    /**
     * Deadline per agent id awaiting a placement acknowledgement, with the node it was armed
     * for and whether that node has already been asked: by the time it elapses the record may
     * name another node, and only the armed node tells the leader whether the deadline still
     * speaks about the placement it was armed for. The ask flag is what keeps the query at one
     * per record instead of one per tick.
     *
     * @var array<string, array{nodeId: string, deadline: float, asked: bool}>
     */
    private array $placementAckDeadlines = [];

    /** @var array<string, string> Hosting node id per agent id, as the leader last published it; empty on the leader */
    private array $placementView = [];

    /** @var ?string Fingerprint of the view this leader last published, or null when it has published none */
    private ?string $publishedViewFingerprint = null;

    /** @var ?string Node id of the leader that placed this node's hosted agents, for self-fence detection */
    private ?string $placingLeaderId = null;

    /** @var ?float Self-fence deadline (microtime) after the placing leader was lost, or null when not isolated */
    private ?float $selfFenceDeadline = null;

    /** @var array<string, float> Deadline (microtime) an agent's placement counts as already asked for until */
    private array $placementAsks = [];

    /**
     * Placements this node accepted while the agent waits for a monopolistic worker raised for it
     * (HIL-998), keyed by agent id. The answer is owed once the agent is seated or the wait is
     * over: to the placing leader when `nodeId` names one, and to nobody but this node's own view
     * when it is null - a placement the leader made on itself.
     *
     * @var array<string, array{nodeId: ?string, agentType: string, agentIndex: ?string, deadline: float}>
     */
    private array $deferredPlacementAnswers = [];

    /**
     * @param string $selfNodeId Id of the node this coordinator runs on
     * @param PlacementMesh $mesh Outbound port to reach nodes and read capabilities
     * @param PlacementExecutor $executor Local port to launch and stop agents on this node
     * @param ?PlacementObserver $observer Degradation seam; a no-op observer when null
     * @param int $failoverGraceMs Leader failover grace in ms
     * @param int $slaveWorkGraceMs Slave self-fence grace in ms
     * @param int $placementAckTimeoutMs Placement-ack timeout in ms
     * @param ?PlacementPolicy $policy Node-selection policy; the best-fit policy when null
     */
    public function __construct(
        string $selfNodeId,
        PlacementMesh $mesh,
        PlacementExecutor $executor,
        ?PlacementObserver $observer = null,
        int $failoverGraceMs = self::DEFAULT_FAILOVER_GRACE_MS,
        int $slaveWorkGraceMs = self::DEFAULT_SLAVE_WORK_GRACE_MS,
        int $placementAckTimeoutMs = self::DEFAULT_PLACEMENT_ACK_TIMEOUT_MS,
        ?PlacementPolicy $policy = null,
    ) {
        $this->selfNodeId = $selfNodeId;
        $this->mesh = $mesh;
        $this->executor = $executor;
        $this->observer = $observer ?? new NullPlacementObserver();
        $this->failoverGraceSec = $failoverGraceMs / TimeConstants::MS_PER_SECOND;
        $this->slaveWorkGraceSec = $slaveWorkGraceMs / TimeConstants::MS_PER_SECOND;
        $this->placementAckTimeoutSec = $placementAckTimeoutMs / TimeConstants::MS_PER_SECOND;
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
     * Answers where an agent runs, so the signal router can forward cross-node or refuse.
     *
     * The answer is derived from the agent's own declaration ({@see AgentScope} and
     * {@see AgentPlacement}, HIL-667) rather than guessed from whether a placement record
     * happens to exist, because the absence of a record means something different in each cell:
     *
     * - {@see AgentScope::NODE} — a replica runs on every node, so it always runs here, and no
     *   record is expected for it at all;
     * - {@see AgentScope::CLUSTER} + {@see AgentPlacement::LEADER} — it runs wherever leadership
     *   sits, which the placement view never carries because a leader-hosted singleton does not
     *   start through placement. Leadership is asked directly; a cluster mid-election knows no
     *   leader and the answer is unknown;
     * - {@see AgentScope::CLUSTER} + {@see AgentPlacement::POLICY} — the placement view answers,
     *   and a view with no entry for it is the honest "nobody has placed it, or nobody has told
     *   me yet".
     *
     * The leader reads the view it owns, every other node the copy that leader publishes to it
     * (HIL-668); {@see hostingNode()} is where the two are reconciled.
     *
     * @param string $agentType Agent type to look up
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return AgentLocation Location of that agent as this node currently knows it
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function locate(string $agentType, ?string $agentIndex): AgentLocation
    {
        $registryEntry = Hilos::appClass()::AGENTS[$agentType] ?? null;
        if (AgentRegistry::scope($registryEntry) === AgentScope::NODE) {
            return AgentLocation::here();
        }

        if (AgentRegistry::placement($registryEntry) === AgentPlacement::LEADER) {
            $leaderId = Hilos::$cluster?->leadership()->leaderId();

            return $this->locationOfNode($leaderId);
        }

        return $this->locationOfNode($this->hostingNode($this->agentId($agentType, $agentIndex)));
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
        // A re-placement routinely lands on the very node the agent left — best-fit reads the
        // emptied node as the least loaded one — so the fresh wait would inherit the old one's
        // `asked` flag and never send its own query. Drop the entry and let the sweep re-arm it.
        unset($this->placementAckDeadlines[$this->agentId($agentType, $agentIndex)]);

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
     * Makes sure an agent somebody just addressed is placed somewhere, on whichever node the
     * frame that addressed it happened to land (HIL-628).
     *
     * An instance agent is started by being addressed, and on a cluster the address is answered
     * before the agent exists: {@see locate()} says {@see AgentLocationKind::Unknown} and there is
     * nothing to forward to. This is what the caller does about it — the leader places the agent
     * itself, any other node asks the leader to, because the placement view is leader-owned and a
     * node that placed on its own initiative would be a second placer.
     *
     * Asking is remembered for {@see self::PLACEMENT_ASK_INTERVAL_SEC} per agent, because the
     * address is asked once per FRAME: a page opening sends several in a row, and each one would
     * otherwise repeat the ask before the first placement could have landed. The memory is the whole of the
     * bookkeeping — an ask is never confirmed or retried on a schedule, since the next frame to
     * the same agent is the retry, and a placement that succeeded is answered by {@see locate()}
     * from then on.
     *
     * The frame that provoked this is held by its caller until the agent is up - here, or on the
     * node the placement names - and answered as undelivered if it is not up in time (HIL-629).
     *
     * @param string $agentType Agent type that was addressed
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function requirePlacement(string $agentType, ?string $agentIndex): void
    {
        $now = microtime(true);
        // Expiry is swept here rather than on a timer, which keeps the map to the asks of the
        // last few seconds: an instance agent per user would otherwise leave a row per user id
        // this node ever addressed.
        foreach ($this->placementAsks as $askedAgentId => $deadline) {
            if ($now >= $deadline) {
                unset($this->placementAsks[$askedAgentId]);
            }
        }

        $agentId = $this->agentId($agentType, $agentIndex);
        if (isset($this->placementAsks[$agentId])) {
            return;
        }

        $this->placementAsks[$agentId] = $now + self::PLACEMENT_ASK_INTERVAL_SEC;

        if ($this->isLeader) {
            $this->placeOnDemand($agentType, $agentIndex);

            return;
        }

        $leaderId = Hilos::$cluster?->leadership()->leaderId();
        if ($leaderId === null) {
            Logger::info("No leader to ask for the placement of agent '{$agentId}'");

            return;
        }

        $this->mesh->sendToNode($leaderId, new PeerPlacementRequestDTO($agentType, $agentIndex));
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
        $this->revokeOnNode($agentType, $agentIndex, $nodeId);
        $this->registry->forget($this->agentId($agentType, $agentIndex));
    }

    /**
     * Stops an agent whose claim over an RT collection lost, and never puts it back (HIL-696).
     *
     * {@see stopAgentOnNode()} with the opposite ending, and the ending is the whole point: a
     * forgotten placement is put back by the very next reconciliation pass, so an agent stopped
     * for a two-owner split would come up again seconds later — on the same node or another one,
     * moving the split rather than ending it. The record stays behind in {@see
     * PlacementState::Refused} so every path that could re-place it can see why it must not.
     *
     * The stop itself is the ordinary revoke, over the frame the leader already uses to take a
     * placement back. Nothing new brings an agent down.
     *
     * Terminal only for as long as this term lasts, deliberately: the mark is soft state like the
     * rest of the view, and a fresh leader re-derives the whole conflict from the reports. The
     * price is one start-stop per election, paid instead of a persisted block that a person would
     * have to lift by hand.
     *
     * @param string $agentType Agent type whose claim lost
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Node the agent runs on
     */
    public function refusePlacement(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        $this->revokeOnNode($agentType, $agentIndex, $nodeId);
        $this->registry->put(new PlacementRecord($agentType, $agentIndex, $nodeId, PlacementState::Refused));
    }

    /**
     * Takes one agent off the node running it, locally or over the stop frame.
     *
     * @param string $agentType Agent type to stop
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Id of the node the agent is placed on
     */
    private function revokeOnNode(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        if ($nodeId === $this->selfNodeId) {
            $this->executor->revokePlacement($agentType, $agentIndex);
            unset($this->hosted[$this->agentId($agentType, $agentIndex)]);

            return;
        }

        $this->mesh->sendToNode($nodeId, new PeerStopAgentDTO($agentType, $agentIndex));
    }

    /**
     * Node side: takes note that an agent this node hosts has stopped on its own (HIL-628).
     *
     * The mirror of a leader-driven stop, arriving from the other end: {@see stopAgentOnNode()}
     * is the leader asking, this is the agent having already gone — an instance agent stopping
     * itself after its declared silence. The node drops it from what it hosts and tells the
     * leader with the same stopped status a revoke would send, which the leader folds into its
     * view through {@see onAgentStatus()}.
     *
     * Nothing is revoked here, because there is nothing left to revoke: the worker ran the whole
     * stop path before this fact travelled. An agent this node does not host is not news to
     * anybody, which is what filters out the node stops that reach here for a replica, a
     * leader-hosted singleton, or anything else placement never put here.
     *
     * The report is addressed to the leader that PLACED this node's work, exactly as
     * {@see onStopAgent()} answers the leader that asked, and not to whoever leads at this
     * instant — that would make this class read a global to say something about its own state.
     * The difference only shows after a term change, and it corrects itself: the agent is gone
     * from what this node hosts, so the next rebuild query answers without it, and a frame
     * addressed to the agent in the meantime restarts it on the node the stale view still names.
     *
     * @param string $agentType Agent type that stopped
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function noteAgentStopped(string $agentType, ?string $agentIndex): void
    {
        $agentId = $this->agentId($agentType, $agentIndex);
        if (!isset($this->hosted[$agentId])) {
            return;
        }

        unset($this->hosted[$agentId]);

        if ($this->isLeader) {
            $this->registry->forget($agentId);

            return;
        }

        if ($this->placingLeaderId === null) {
            return;
        }

        $this->mesh->sendToNode($this->placingLeaderId, PeerAgentStatusDTO::stopped($agentType, $agentIndex));
    }

    /**
     * Node side: launches a leader-requested agent locally and reports the outcome.
     *
     * Reuses the local start path — no new spawn logic — and answers the leader with a
     * started status carrying the worker id, or a failed status carrying the reason. A
     * failure is caught and reported rather than propagated, so a bad placement never
     * tears down the daemon loop.
     *
     * A placement accepted while a monopolistic worker is raised for the agent is answered later,
     * by {@see answerDeferredPlacements()}, and nothing is sent now (HIL-998): refusing a node a
     * second away from ready would send the agent looking elsewhere, and the leader already waits
     * {@see DEFAULT_PLACEMENT_ACK_TIMEOUT_MS} for the answer, with the record in
     * {@see PlacementState::Placing} read as ordinary travel time.
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

        if ($workerId === null) {
            $this->deferPlacementAnswer($fromNodeId, $agentType, $agentIndex);

            return;
        }

        $this->answerPlacementStarted($fromNodeId, $agentType, $agentIndex, $workerId);
    }

    /**
     * Records this node as hosting a placed agent and tells the leader that placed it.
     *
     * @param string $fromNodeId Id of the leader node that requested the placement
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param int $workerId Worker the agent landed on
     */
    private function answerPlacementStarted(string $fromNodeId, string $agentType, ?string $agentIndex, int $workerId): void
    {
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
     * Remembers a placement accepted while the agent waits for a worker raised for it (HIL-998).
     *
     * @param ?string $fromNodeId Leader owed the answer, or null for a placement the leader made on itself
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    private function deferPlacementAnswer(?string $fromNodeId, string $agentType, ?string $agentIndex): void
    {
        $this->deferredPlacementAnswers[$this->agentId($agentType, $agentIndex)] = [
            'nodeId' => $fromNodeId,
            'agentType' => $agentType,
            'agentIndex' => $agentIndex,
            'deadline' => microtime(true) + self::DEFERRED_PLACEMENT_WAIT_SEC,
        ];
    }

    /**
     * Answers the placements accepted while their agent waited for a worker, once there is an
     * answer to give (HIL-998).
     *
     * Seated - the agent is hosted here and the placement is started; the wait is over without a
     * worker - the placement failed, with the reason the agent gave up for. A placement the leader
     * made on itself has nobody to wire the answer to and only finishes its record.
     *
     * @param float $now Current microtime
     */
    private function answerDeferredPlacements(float $now): void
    {
        foreach ($this->deferredPlacementAnswers as $agentId => $deferred) {
            ['nodeId' => $nodeId, 'agentType' => $agentType, 'agentIndex' => $agentIndex] = $deferred;
            $workerId = $this->executor->placedWorkerId($agentType, $agentIndex);
            if ($workerId === null && $now < $deferred['deadline']) {
                continue;
            }

            unset($this->deferredPlacementAnswers[$agentId]);

            if ($workerId !== null) {
                if ($nodeId !== null) {
                    $this->answerPlacementStarted($nodeId, $agentType, $agentIndex, $workerId);
                    continue;
                }

                $record = new PlacementRecord($agentType, $agentIndex, $this->selfNodeId, PlacementState::Started);
                $this->hosted[$agentId] = $record;
                $this->registry->put($record);
                continue;
            }

            Logger::warning("Placement of '{$agentId}' failed: " . self::DEFERRED_PLACEMENT_FAILED_REASON);
            if ($nodeId !== null) {
                $this->mesh->sendToNode(
                    $nodeId,
                    PeerAgentStatusDTO::failed($agentType, $agentIndex, self::DEFERRED_PLACEMENT_FAILED_REASON),
                );
                continue;
            }

            $this->registry->put(new PlacementRecord($agentType, $agentIndex, $this->selfNodeId, PlacementState::Failed));
        }
    }

    /**
     * Node side: stops a leader-requested agent locally and confirms with a stopped status.
     *
     * @param string $fromNodeId Id of the leader node that requested the stop
     * @param PeerStopAgentDTO $frame Received stop-agent frame
     */
    public function onStopAgent(string $fromNodeId, PeerStopAgentDTO $frame): void
    {
        $agentId = $this->agentId($frame->agentType, $frame->agentIndex);
        $this->executor->revokePlacement($frame->agentType, $frame->agentIndex);
        // A stop answers a placement still waiting for its worker too: `stopped` below is its answer
        unset($this->hosted[$agentId], $this->deferredPlacementAnswers[$agentId]);
        $this->mesh->sendToNode($fromNodeId, PeerAgentStatusDTO::stopped($frame->agentType, $frame->agentIndex));
    }

    /**
     * Leader side: folds a node's placement status into the placement view.
     *
     * A stopped status forgets the placement; a started or failed status records it
     * against the reporting node so the view reflects where each agent actually landed.
     *
     * A status from a node the record no longer names moves nothing: the record was re-placed
     * while this frame was in flight, so writing it by sender would point the agent back at the
     * node it left while the new copy runs unnamed. A late `started` earns the same stop
     * {@see onPlacementReport()} sends a second copy; a late `stopped` or `failed` is dropped in
     * silence, because {@see onStopAgent()} answers `stopped` UNCONDITIONALLY and a stop sent
     * back at one would loop the pair of nodes, while a `failed` says nothing runs there anyway.
     *
     * @param string $fromNodeId Id of the node that reported the status
     * @param PeerAgentStatusDTO $frame Received agent-status frame
     */
    public function onAgentStatus(string $fromNodeId, PeerAgentStatusDTO $frame): void
    {
        $agentId = $this->agentId($frame->agentType, $frame->agentIndex);
        $existing = $this->registry->get($agentId);
        if ($existing?->state === PlacementState::Refused) {
            // The node is confirming the stop this leader ordered for a two-owner split, and the
            // record is the only thing keeping the agent down (HIL-696). Forgetting it here would
            // undo the refusal with the very frame that carried it out.
            return;
        }

        // Read exactly as in onPlacementReport(), runsNowhere() and all: an Unplaced record is
        // meant to be adopted by whichever node reports the agent up.
        if ($existing !== null && $existing->nodeId !== $fromNodeId && !$existing->state->runsNowhere()) {
            if ($frame->state === PlacementState::Started) {
                $this->mesh->sendToNode($fromNodeId, new PeerStopAgentDTO($frame->agentType, $frame->agentIndex));
                Logger::info("Telling node '{$fromNodeId}' to stop '{$agentId}': the leader places it on '{$existing->nodeId}'");
            }

            return;
        }

        if ($frame->state === PlacementState::Stopped) {
            $this->registry->forget($agentId);
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
     * Leader side: places an agent another node asked for, having been unable to address it.
     *
     * The receiving end of {@see requirePlacement()}. Ignored on a node that does not lead: the
     * placement view is somebody else's, and placing against it would be a second placer.
     *
     * @param string $fromNodeId Id of the node that asked
     * @param PeerPlacementRequestDTO $frame Received placement-request frame
     */
    public function onPlacementRequest(string $fromNodeId, PeerPlacementRequestDTO $frame): void
    {
        if (!$this->isLeader) {
            $agentId = $this->agentId($frame->agentType, $frame->agentIndex);
            Logger::info("Ignoring placement request for '{$agentId}' from node '{$fromNodeId}':"
                . ' this node does not lead');

            return;
        }

        $this->placeOnDemand($frame->agentType, $frame->agentIndex);
    }

    /**
     * Leader side: folds a node's hosted-agent report into the placement view, reconciling
     * against the leader-owned truth.
     *
     * Four sources land here: the answers to a fresh leader's rebuild broadcast
     * ({@see onBecameLeader()}), a node's rejoin report ({@see onPeerHandshaked()}), a node's
     * answer to the ack-timeout query ({@see sweepPlacementAcks()}, HIL-930), and a node's report
     * on a view that gives one of its agents elsewhere ({@see reportAgentsPlacedElsewhere()},
     * HIL-976). The frame is a COMPLETE snapshot of
     * what the reporting node hosts, so it is read in both directions. For each agent it NAMES
     * the leader is the arbiter — if it already tracks that agent on a different node (it was
     * re-placed there while this node was gone, or another node hosts it), the reporting node is
     * told to stop its stale copy so a moved agent is never resurrected; otherwise the report is
     * accepted, which both rebuilds the view and lets a returning node re-adopt an agent that
     * failover had left {@see PlacementState::Unplaced}. What the frame does NOT name is then
     * settled by {@see reconcileMissingAgents()}, in that order: an accepted report can move an
     * agent ONTO this node, and the pass that follows must not take away what the pass before it
     * just granted. Ignored on a non-leader, whose placement view is inert.
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
            if ($existing?->state === PlacementState::Refused) {
                // Still running where its RT claim was refused (HIL-696), so the stop is repeated
                // rather than the refusal forgotten: accepting the report would re-adopt the agent
                // the leader took down, and the split would be back with it.
                $this->mesh->sendToNode($fromNodeId, new PeerStopAgentDTO($entry->agentType, $entry->agentIndex));
                Logger::info("Reconcile: telling node '{$fromNodeId}' to stop '{$agentId}', refused an RT claim");
                continue;
            }
            if ($existing !== null && $existing->nodeId !== $fromNodeId && !$existing->state->runsNowhere()) {
                $this->mesh->sendToNode($fromNodeId, new PeerStopAgentDTO($entry->agentType, $entry->agentIndex));
                Logger::info("Reconcile: telling node '{$fromNodeId}' to stop '{$agentId}' already placed on '{$existing->nodeId}'");
                continue;
            }

            $this->registry->put(new PlacementRecord($entry->agentType, $entry->agentIndex, $fromNodeId, PlacementState::Started));
        }

        $this->reconcileMissingAgents($fromNodeId, array_map(
            fn(PeerPlacedAgentEntry $entry): string => $this->agentId($entry->agentType, $entry->agentIndex),
            $frame->agents,
        ));
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
     * A taken view is then checked against what this node hosts (HIL-976): a view that gives one
     * of its agents to ANOTHER node makes it report its complete hosted set to the leader
     * ({@see reportAgentsPlacedElsewhere()}). It reports rather than stops, because the view may
     * be older than the registry, and the one who holds the fresh registry is the one to judge.
     * An agent the view does not name at all is no cause: a fresh leader publishes before the
     * reports have come in, and an agent that runs nowhere is never in the view.
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
            // as int 2. What comes out of here is answered to {@see locate()} callers, whose
            // contract names a node id or none.
            $nodeId = (string)$nodeId;
            foreach ($entries as $entry) {
                $view[$this->agentId($entry->agentType, $entry->agentIndex)] = $nodeId;
            }
        }

        $this->placementView = $view;
        $this->reportAgentsPlacedElsewhere($fromNodeId);
    }

    /**
     * Node side: reports what this node hosts when the leader's view gives one of its agents to
     * another node (HIL-976).
     *
     * The leader's registry moved an agent this node still runs, and nobody told this node to
     * stop it: two live copies write the same collection, and since HIL-913 the RT owner guard
     * reads the shared agent id as a move rather than a conflict. The node does not judge — the
     * view may be older than the registry it was drawn from — it hands the leader the same
     * report it sends on a new link, and {@see onPlacementReport()} stops whichever copy the
     * registry does not name.
     *
     * The snapshot is COMPLETE, never just the disputed agents: the leader reads a report in
     * both directions, and {@see reconcileMissingAgents()} would re-place every started agent
     * of this node the frame left out. Repeats are not remembered: the view is published only on
     * change and the stop removes the agent from the hosted set, so the disagreement lives for
     * one exchange; the one repeat — a relinking node, whose handshake report and view both
     * speak — costs the leader a second stop, which is a no-op on a stopped agent.
     *
     * @param string $leaderNodeId Id of the leader whose view was just taken
     */
    private function reportAgentsPlacedElsewhere(string $leaderNodeId): void
    {
        $placedElsewhere = false;
        foreach (array_keys($this->hosted) as $agentId) {
            $viewNodeId = $this->placementView[$agentId] ?? null;
            if ($viewNodeId === null || $viewNodeId === $this->selfNodeId) {
                continue;
            }

            Logger::info(
                "Placement view of leader '{$leaderNodeId}' puts '{$agentId}' on node '{$viewNodeId}' while this node hosts it;"
                . ' reporting what this node hosts',
            );
            $placedElsewhere = true;
        }

        if ($placedElsewhere) {
            $this->mesh->sendToNode($leaderNodeId, new PeerPlacementReportDTO($this->hostedEntries()));
        }
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
     * next leader rebuilds from the mesh. Any pending failover timers and placement-ack waits
     * drop with the view; the next leader re-derives them from its own rebuilt placements.
     */
    public function onLostLeadership(): void
    {
        $this->isLeader = false;
        $this->registry->clear();
        $this->failoverDeadlines = [];
        $this->placementAckDeadlines = [];
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
                    $this->failoverDeadlines[$record->agentId()] = [
                        'nodeId' => $nodeId,
                        'deadline' => $now + $this->failoverGraceSec,
                    ];
                }
            }
        }

        if ($nodeId === $this->placingLeaderId && $this->hosted !== [] && $this->selfFenceDeadline === null) {
            $this->selfFenceDeadline = $now + $this->slaveWorkGraceSec;
            Logger::info("Self-fence armed: placing leader '{$nodeId}' went offline, " . count($this->hosted)
                . ' placed agent(s) stop in ' . sprintf('%.1f', $this->slaveWorkGraceSec) . 's unless it returns');
        }
    }

    /**
     * Reacts to a node coming online: cancels a stale failover/self-fence, retries degraded.
     *
     * Node side — if the returning node is the placing leader, the isolation is over, so the
     * self-fence is disarmed. Leader side — a flapped node back before its grace keeps its
     * agents, so its pending failover is canceled; and since a capable node may now be
     * available, every agent failover had to leave {@see PlacementState::Unplaced} is retried.
     *
     * @param string $nodeId Node id the transport just marked online
     * @param float $now Current microtime
     */
    public function noteNodeOnline(string $nodeId, float $now): void
    {
        $this->callOffLossOf($nodeId);

        if ($this->isLeader) {
            $this->retryUnplaced();
        }
    }

    /**
     * Calls off what the loss of a node armed here, now that the node is back.
     *
     * Node side, the self-fence armed against the placing leader; leader side, the failover of
     * every agent the node hosts. Both are left alone when nothing was armed.
     *
     * @param string $nodeId Node id that is back
     */
    private function callOffLossOf(string $nodeId): void
    {
        if ($nodeId === $this->placingLeaderId) {
            if ($this->selfFenceDeadline !== null) {
                Logger::info("Self-fence disarmed: placing leader '{$nodeId}' is back before the grace elapsed");
            }
            $this->selfFenceDeadline = null;
        }

        if (!$this->isLeader) {
            return;
        }

        $calledOff = 0;
        foreach ($this->registry->all() as $record) {
            if ($record->nodeId === $nodeId && isset($this->failoverDeadlines[$record->agentId()])) {
                unset($this->failoverDeadlines[$record->agentId()]);
                $calledOff++;
            }
        }

        if ($calledOff > 0) {
            Logger::info("Failover of {$calledOff} agent(s) on '{$nodeId}' called off: the node is back before the grace elapsed");
        }
    }

    /**
     * Fires any failover, placement-ack timeout or self-fence whose grace has elapsed. Driven
     * each daemon tick.
     *
     * @param float $now Current microtime
     */
    public function tick(float $now): void
    {
        $this->answerDeferredPlacements($now);

        foreach ($this->failoverDeadlines as $agentId => ['nodeId' => $lostNodeId, 'deadline' => $deadline]) {
            if ($now >= $deadline) {
                unset($this->failoverDeadlines[$agentId]);
                $this->failOver($agentId, $lostNodeId);
            }
        }

        // After failover, not before: a failover re-places a record out of `Placing` onto
        // another node, and arming on the far side of it means arming for the node the record
        // actually names now.
        $this->sweepPlacementAcks($now);

        if ($this->selfFenceDeadline !== null && $now >= $this->selfFenceDeadline) {
            $this->selfFenceDeadline = null;
            $this->selfFence();
        }

        $this->publishPlacementView();
    }

    /**
     * Leader side: gives every {@see PlacementState::Placing} record a deadline and, when one
     * elapses, asks the node what it actually hosts instead of re-placing the agent blind.
     *
     * A placement frame that never came back leaves the record waiting for a status that has no
     * other way out: the node may have been recreated before it answered, and a rejoin inside
     * `CLUSTER_FAILOVER_GRACE_MS` clears the failover deadline without ever judging the
     * `Placing`. So the wait gets `CLUSTER_PLACEMENT_ACK_TIMEOUT_MS`, and what the timeout
     * fires is a {@see PeerPlacementQueryDTO} — a question, not an action. The answer travels
     * the path that already exists ({@see onPlacementReport()} and
     * {@see reconcileMissingAgents()}): the node names the agent and the record becomes
     * `Started`; it does not and the record is forgotten and re-placed. Re-placing on the
     * timeout itself was rejected — {@see onAgentStatus()} writes the record by SENDER, so a
     * late `started` from the old node would point the record back at it while the new copy
     * runs unnamed, and two copies is what placement exists to prevent.
     *
     * Arming is DERIVED from the registry rather than done where the registry is written: eight
     * paths write it, and one that forgot to arm would leave its record waiting forever. Only
     * one query goes out per record — a node that answers nothing has a dead link, and that is
     * `CLUSTER_LINK_TIMEOUT_MS` and failover's case, not this one.
     *
     * @param float $now Current microtime
     */
    private function sweepPlacementAcks(float $now): void
    {
        if (!$this->isLeader) {
            return;
        }

        $live = [];
        foreach ($this->registry->all() as $record) {
            if ($record->state !== PlacementState::Placing) {
                continue;
            }

            $agentId = $record->agentId();
            $live[$agentId] = true;
            $armed = $this->placementAckDeadlines[$agentId] ?? null;
            if ($armed === null || $armed['nodeId'] !== $record->nodeId) {
                $this->placementAckDeadlines[$agentId] = [
                    'nodeId' => $record->nodeId,
                    'deadline' => $now + $this->placementAckTimeoutSec,
                    'asked' => false,
                ];
                continue;
            }

            if ($armed['asked'] || $now < $armed['deadline']) {
                continue;
            }

            $delivered = $this->mesh->sendToNode($record->nodeId, new PeerPlacementQueryDTO());
            // Asked either way: an undelivered query means the link is gone, and a gone link is
            // closed by `CLUSTER_LINK_TIMEOUT_MS` into the failover that owns that case.
            $this->placementAckDeadlines[$agentId]['asked'] = true;
            Logger::info("Placement ack of '{$agentId}' timed out on node '{$record->nodeId}'; asking what it hosts"
                . ($delivered ? '' : ' failed: node is not linked'));
        }

        $this->placementAckDeadlines = array_intersect_key($this->placementAckDeadlines, $live);
    }

    /**
     * Trades pictures with a freshly-linked peer: what this node hosts, and — if it leads —
     * where everything runs.
     *
     * Node side is the reconcile-on-rejoin safety net: after a partition a node may still host
     * agents the leader re-placed elsewhere, so on every new link it sends what it hosts and
     * lets the leader ({@see onPlacementReport()}) stop the stale copies. What it sends is a
     * COMPLETE snapshot of its hosted set, which is why the frame goes out even when that set is
     * EMPTY (HIL-719): a node whose container was recreated inside the failover grace hosts
     * nothing, and the silence it used to answer with is exactly what left the leader calling a
     * dead fleet started. Handing over the whole of what one side holds on this hook is what the
     * connection index, the RT claims and the RT snapshots already do. A non-leader peer that
     * receives the report simply ignores it.
     *
     * Leader side hands the newcomer the whole placement view (HIL-668), because that is the one
     * thing the per-tick publish cannot do for it: the publish speaks only on CHANGE, so a node
     * that linked into a quiet cluster would learn nothing until something moved.
     *
     * Both sides first call off what the peer's loss armed here - the self-fence, the failover
     * ({@see noteNodeOnline()} does the same on a return the registry reports). A handshake is
     * this node seeing the peer alive with its own eyes, and it can be the only sign of the
     * return: when gossip put the peer back online a moment before the handshake completed, the
     * registry takes the handshake for no change and reports nothing, and a failover armed by the
     * link that dropped would move a live node's agents (HIL-1034).
     *
     * @param string $nodeId Node id of the peer that just handshaked
     */
    public function onPeerHandshaked(string $nodeId): void
    {
        $this->callOffLossOf($nodeId);

        if ($this->isLeader) {
            $this->mesh->sendToNode($nodeId, new PeerPlacementViewDTO($this->selfNodeId, $this->placementViewAgents()));
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
     * An agent that runs nowhere — degraded for want of a node, or refused an RT claim — is left
     * out under the same rule {@see hostingNode()} applies on this node: it has no node to
     * forward to. Because both sides read this one rule, a copy answers exactly what the
     * original would.
     *
     * @return array<string|int, list<PeerPlacedAgentEntry>> Hosted agent entries, by node id
     */
    private function placementViewAgents(): array
    {
        $agents = [];
        foreach ($this->registry->all() as $record) {
            if ($record->state->runsNowhere()) {
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
     * records under the rule applied here — an agent that runs nowhere is left out of both.
     *
     * @param string $agentId Agent id to look up
     * @return ?string Hosting node id, or null when nothing places it
     */
    private function hostingNode(string $agentId): ?string
    {
        $record = $this->registry->get($agentId);
        if ($record !== null && !$record->state->runsNowhere()) {
            return $record->nodeId;
        }

        return $this->placementView[$agentId] ?? null;
    }

    /**
     * Turns a hosting node id — or the absence of one — into the location its holder means.
     *
     * The one place the self-node comparison lives, so that "the node hosting it is this node"
     * and "no node hosts it" cannot be conflated again: both arrive here as a node id or null,
     * and leave as two different cases.
     *
     * @param ?string $nodeId Node id hosting the agent, or null when none is known
     * @return AgentLocation Here for this node, on that node for another, unknown for null
     */
    private function locationOfNode(?string $nodeId): AgentLocation
    {
        if ($nodeId === null) {
            return AgentLocation::unknown();
        }

        return $nodeId === $this->selfNodeId ? AgentLocation::here() : AgentLocation::onNode($nodeId);
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
     * "Moved" is judged against the node the deadline was armed for, not against the registry
     * alone: within one grace period the agent may have been re-placed onto a neighbour — by
     * the project that supervises the fleet, or by a report the returning node sent — and a
     * deadline that outlived that move speaks about a node the agent no longer sits on. Firing
     * it would start a second copy on the node it names, which the guard from HIL-696 refuses
     * for good, leaving the agent unable to run anywhere for the rest of the term.
     *
     * @param string $agentId Agent id whose failover grace has elapsed
     * @param string $lostNodeId Node id whose loss armed the deadline
     */
    private function failOver(string $agentId, string $lostNodeId): void
    {
        $record = $this->registry->get($agentId);
        if ($record === null || $record->state->runsNowhere()) {
            return;
        }

        if ($record->nodeId !== $lostNodeId) {
            Logger::info("Failover: '{$agentId}' already moved from '{$lostNodeId}' onto '{$record->nodeId}'; stale deadline dropped");
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
     * Re-places every agent the leader still tracks on a node whose report did not name it
     * (HIL-719).
     *
     * The other half of {@see failOver()}: both answer "the agent is not where it is written
     * down", one because the node went silent, this one because the node itself said so. A
     * placement report is a complete snapshot, so an agent this leader calls
     * {@see PlacementState::Started} on the reporting node and the snapshot leaves out is not
     * running anywhere — the container came back as a fresh process, faster than the grace that
     * would have noticed it gone. This is the case that used to end in a fleet running nowhere
     * while the leader answered started for the rest of the term.
     *
     * A Started record of the reporting node is judged, and so is a Placing one the leader
     * ALREADY ASKED about ({@see sweepPlacementAcks()}, HIL-930) — that snapshot is the answer
     * to the question, and an answer that does not name the agent says the place frame is not
     * in flight, it is lost. An unasked {@see PlacementState::Placing} is spared exactly as
     * before, because its frame may still be travelling and dropping the record would make the
     * copy that lands a second source of truth; {@see PlacementState::Refused} is
     * spared because the leader took that agent down on purpose (HIL-696);
     * {@see PlacementState::Failed} is retried by whoever placed it; {@see PlacementState::Unplaced}
     * is not about a node at all and belongs to {@see retryUnplaced()}.
     *
     * The record is forgotten BEFORE the re-placement, because {@see pickBestNode()} counts
     * occupancy from the registry: a record left standing would credit the emptied node with a
     * load it does not carry and push its own agent onto a neighbour. Each agent is guarded on
     * its own, since the frame is dispatched on the master loop, where an escaping exception
     * ends run().
     *
     * @param string $fromNodeId Id of the node that reported
     * @param list<string> $reportedIds Agent ids the report named
     */
    private function reconcileMissingAgents(string $fromNodeId, array $reportedIds): void
    {
        foreach ($this->registry->all() as $record) {
            $agentId = $record->agentId();
            if ($record->nodeId !== $fromNodeId
                || !$this->isJudgedByReport($record, $fromNodeId)
                || in_array($agentId, $reportedIds, true)
            ) {
                continue;
            }

            Logger::warning("Reconcile: node '{$fromNodeId}' no longer hosts '{$agentId}'; re-placing");
            $this->registry->forget($agentId);

            try {
                if ($this->placeAgentOnBestNode($record->agentType, $record->agentIndex) !== null) {
                    continue;
                }
            } catch (Throwable $e) {
                Logger::warning("Reconcile of '{$agentId}' could not re-place: {$e->getMessage()}");
            }

            $this->degrade($record);
        }
    }

    /**
     * Tells whether a placement report from a node is allowed to judge one of its records.
     *
     * A {@see PlacementState::Started} record always is — the leader believes the agent runs
     * there, and a complete snapshot that leaves it out contradicts that belief. A
     * {@see PlacementState::Placing} one only once the leader has ASKED this very node what it
     * hosts ({@see sweepPlacementAcks()}): before the question the silence is ordinary travel
     * time, after it the snapshot is the answer.
     *
     * @param PlacementRecord $record Record the report is being read against
     * @param string $fromNodeId Id of the node that reported
     * @return bool True when the report decides this record's fate
     */
    private function isJudgedByReport(PlacementRecord $record, string $fromNodeId): bool
    {
        if ($record->state === PlacementState::Started) {
            return true;
        }

        $armed = $this->placementAckDeadlines[$record->agentId()] ?? null;

        return $record->state === PlacementState::Placing
            && $armed !== null
            && $armed['asked']
            && $armed['nodeId'] === $fromNodeId;
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
     * Leader side: places an agent that was addressed, unless the view already places it.
     *
     * The one body behind both on-demand entries — this node's own {@see requirePlacement()} and
     * another node's {@see onPlacementRequest()} — because the guard belongs to neither of them
     * separately. The asking node's ignorance is not the leader's: a published view lags by a
     * tick, so an agent that has been placed for a while is still Unknown to a node that has not
     * been handed the new picture, and placing it again would start a SECOND copy of it — the one
     * outcome placement exists to prevent. What counts as already placed is read exactly as
     * {@see pickBestNode()} reads occupancy: a failed or stopped record has released whatever it
     * held, so it is not a placement.
     *
     * Errors are caught and written rather than raised: both callers are on the master loop,
     * where an escaping exception ends run() and takes the node down, and a placement that cannot
     * run is the same non-event as one no capable node fits.
     *
     * @param string $agentType Agent type to place
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    private function placeOnDemand(string $agentType, ?string $agentIndex): void
    {
        $agentId = $this->agentId($agentType, $agentIndex);
        $record = $this->registry->get($agentId);
        if ($record !== null
            && ($record->state === PlacementState::Placing
                || $record->state === PlacementState::Started
                || $record->state === PlacementState::Refused)) {
            return;
        }

        try {
            $this->placeAgentOnBestNode($agentType, $agentIndex);
        } catch (Throwable $e) {
            Logger::warning("On-demand placement of '{$agentId}' failed: {$e->getMessage()}");
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
            $workerId = $this->executor->executePlacement($agentType, $agentIndex);
        } catch (AgentDaemonCreationFailedException | NoSuitableWorkerException | AgentNotLinkedToWorkerException $e) {
            $this->registry->put($record->withState(PlacementState::Failed));
            throw $e;
        }

        // Accepted while a worker is raised for it (HIL-998): placing until the answer is known
        if ($workerId === null) {
            $this->registry->put($record->withState(PlacementState::Placing));
            $this->deferPlacementAnswer(null, $agentType, $agentIndex);

            return;
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
