<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Exception\PlacementCapabilityException;
use Hilos\Cluster\PlacementVerdictSink;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\PendingLeadership;
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
use LogicException;
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
 *   hard gate first — the target must advertise the required capability tags, declare a
 *   capacity, and have free room for the agent's cost — before anything is sent. Outcomes are
 *   tracked in the soft-state {@see PlacementRegistry}, which a fresh leader rebuilds from node reports on
 *   {@see onBecameLeader()}.
 * - Node side: an inbound place/stop frame runs the local execute/revoke and replies with
 *   a {@see PeerAgentStatusDTO}; a rebuild query is answered from the node's own hosted
 *   set. This is what a data-plane slave does with the placements a leader hands it. A place
 *   frame passes the same hard gate once more before anything starts, against the tags the node
 *   advertises now and what it runs itself (HIL-447), so a leader working from a stale picture
 *   cannot overfill it.
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
 * Capacity accounting (HIL-448) has no state of its own. What a node's placements hold is derived
 * from the registry every time a node is chosen ({@see placementCandidates()}): the cost of every
 * Placing or Started record on it, read from the executor at that moment. With no counter to keep
 * in step, a stop, a move or a node loss frees the capacity simply by moving the record, and a
 * fresh leader has it back the moment it rebuilds the registry ({@see onBecameLeader()}). Every
 * cost is read as placement charges it ({@see placementCost()}, HIL-447): the agent's own cost
 * plus one mandatory `slots`, so the slots a node declares cap how many placed agents it runs,
 * the ones that declare no cost of their own included. Neither a slot count nor a remembered
 * cost is kept anywhere.
 *
 * Crash-failover (HIL-183) hangs off the same two sides. Driven by node up/down transitions
 * ({@see noteNodeOffline()} / {@see noteNodeOnline()}) and a grace-timer {@see tick()}: the
 * leader re-places a dead node's agents onto another capable node after
 * `CLUSTER_FAILOVER_GRACE_MS`, degrading an agent to {@see PlacementState::Unplaced} (and
 * notifying the {@see PlacementObserver}) when no capable node is online; a node isolated
 * from the leader it answers to — the one that placed its work or took it over with a rebuild
 * query (HIL-440) — and a master that loses its quorum while hosting placed work, whatever it
 * answered to (HIL-1217), and a node whose leader tells it that it stopped leading (HIL-1287),
 * self-fence those agents after `CLUSTER_SLAVE_WORK_GRACE_MS` (held at or below the failover
 * grace, so the old copy stops before the leader starts a new one). A fresh leader places
 * nothing it has no record of until its rebuild is settled — every node has reported or been
 * away for the failover grace — so a copy still running on a node it has not heard from is
 * adopted rather than started twice (HIL-1217). On rejoin a node reports what it still hosts
 * ({@see onPeerHandshaked()}) and the leader reconciles against its view (leader = truth),
 * stopping anything already re-placed elsewhere. A node that hosts an agent the published view
 * gives to another node reports the same snapshot at once, without waiting for a relink
 * ({@see reportAgentsPlacedElsewhere()}, HIL-976). The wait for a placement to be acknowledged is
 * bounded by the same kind of timer (HIL-930): after `CLUSTER_PLACEMENT_ACK_TIMEOUT_MS` the
 * leader ASKS the node what it hosts ({@see sweepPlacementAcks()}) rather than re-placing the
 * agent blind, and a status arriving from a node the record no longer names is refused instead
 * of written ({@see onAgentStatus()}).
 *
 * Why work is not running is kept in two shapes (HIL-447), both the leader's soft state and both
 * gone with the term. A failed or unplaced record carries the reason and the gate's refusals per
 * node ({@see PlacementRefusal}); and every agent keeps its last failed attempt as a
 * {@see PlacementRefusalSnapshot} ({@see placementRefusals()}), because a new agent no node could
 * take never gets a record at all. The refusals travel between nodes inside the failed status and
 * the not-placed verdict, so the leader holds the node's own words, never a parse of its message.
 */
final class ClusterPlacement implements WorkerPlacement
{
    /** @var int Default leader failover grace in ms when none is configured */
    private const int DEFAULT_FAILOVER_GRACE_MS = 8000;

    /** @var int Default slave self-fence grace in ms when none is configured */
    private const int DEFAULT_SLAVE_WORK_GRACE_MS = 6000;

    /** @var int Default placement-ack timeout in ms when none is configured */
    private const int DEFAULT_PLACEMENT_ACK_TIMEOUT_MS = 16000;

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

    /** @var PlacementRestartSink Master waiting for POLICY restart outcomes */
    private PlacementRestartSink $restartSink;

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
     * tells the leader whether that deadline still speaks about where the agent is. A placement
     * the leader sends over the mesh calls it off at once ({@see callOffFailoverOf()}).
     *
     * @var array<string, array{nodeId: string, deadline: float}>
     */
    private array $failoverDeadlines = [];

    /**
     * The nodes a fresh leader's rebuild still waits for, by node id: null while it waits for the
     * node's placement report, a microtime while it waits out the failover grace of a node that
     * went away. Empty when nothing is awaited, which is when the rebuild is settled and new work
     * may be placed (HIL-1217).
     *
     * @var array<string, ?float>
     */
    private array $rebuildAwaited = [];

    /** @var bool True from a term's first awaited node until the rebuild of that term settles */
    private bool $rebuildPending = false;

    /**
     * When each node this node sees offline went away, by node id: kept on every node, not only
     * on the leader, because a node that wins a term has to know which of its neighbours left
     * less than a failover grace ago.
     *
     * @var array<string, float>
     */
    private array $offlineSince = [];

    /**
     * Addressed agents a leader held while its rebuild was not settled, by agent id: placed, and
     * the nodes that asked answered, once it settles.
     *
     * @var array<string, array{agentType: string, agentIndex: ?string}>
     */
    private array $heldOnDemand = [];

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

    /** @var array<string, array{host: string, target: string, placing: bool}> Pending POLICY moves */
    private array $restartsElsewhere = [];

    /** @var array<string, true> POLICY restarts retaining their host across the old stop report */
    private array $restartsInPlace = [];

    /** @var array<string, string> Hosting node id per agent id, as the leader last published it; empty on the leader */
    private array $placementView = [];

    /** @var ?string Fingerprint of the view this leader last published, or null when it has published none */
    private ?string $publishedViewFingerprint = null;

    /**
     * Node id of the leader this node answers to: the one that placed its hosted agents or took
     * them over with a rebuild query; null on a leader, after a self-fence, after a lost quorum
     * and after its leader's release. Stop reports go to it, the self-fence is armed against it,
     * and a slave uses it to address leader-hosted agents because it does not run consensus
     * (HIL-1304).
     *
     * @var ?string
     */
    private ?string $placingLeaderId = null;

    /**
     * @var ?float Self-fence deadline (microtime) after the placing leader was lost, released this
     * node or the quorum was, or null when not isolated
     */
    private ?float $selfFenceDeadline = null;

    /** @var ?string Why the armed self-fence was armed, as its firing line names it; null when none is armed */
    private ?string $selfFenceCause = null;

    /** @var array<string, float> Deadline (microtime) an agent's placement counts as already asked for until */
    private array $placementAsks = [];

    /**
     * @var array<string, list<string>> Node ids owed a verdict for an agent, by agent id
     */
    private array $placementVerdictWaiters = [];

    /**
     * Placements this node accepted while the agent waits for a monopolistic worker raised for it
     * (HIL-998), keyed by agent id. The answer is owed once the agent is seated or the start
     * fails: to the placing leader when `nodeId` names one, and to nobody but this node's own view
     * when it is null - a placement the leader made on itself.
     *
     * @var array<string, array{nodeId: ?string, agentType: string, agentIndex: ?string}>
     */
    private array $deferredPlacementAnswers = [];

    /**
     * The last failed placement attempt of each agent, by agent id (HIL-447): one per agent,
     * replaced by its next failure and dropped by an accepted attempt, a start or a re-adoption,
     * an explicit stop, and a change of leadership. A diagnosis for inspection, not a queue:
     * nothing is retried from it, and a refused new agent it names is not retried at all (HIL-446).
     *
     * @var array<string, PlacementRefusalSnapshot>
     */
    private array $lastPlacementRefusals = [];

    /**
     * @param string $selfNodeId Id of the node this coordinator runs on
     * @param PlacementMesh $mesh Outbound port to reach nodes and read capabilities
     * @param PlacementExecutor $executor Local port to launch and stop agents on this node
     * @param ?PlacementObserver $observer Degradation seam; a no-op observer when null
     * @param int $failoverGraceMs Leader failover grace in ms
     * @param int $slaveWorkGraceMs Slave self-fence grace in ms
     * @param int $placementAckTimeoutMs Placement-ack timeout in ms
     * @param ?PlacementPolicy $policy Node-selection policy; the best-fit policy when null
     * @param ?PlacementRestartSink $restartSink Master restart callback; inert when null
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
        ?PlacementRestartSink $restartSink = null,
    ) {
        $this->selfNodeId = $selfNodeId;
        $this->mesh = $mesh;
        $this->executor = $executor;
        $this->observer = $observer ?? new NullPlacementObserver();
        $this->restartSink = $restartSink ?? new NullPlacementRestartSink();
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
     * Returns the last failed placement attempt of every agent that has one, for inspection
     * (HIL-447).
     *
     * The leader's own diagnosis: a node that does not lead answers an empty list, whatever it
     * may have noted while it ran a placement itself.
     *
     * @return list<PlacementRefusalSnapshot> Snapshots ordered by agent id
     */
    public function placementRefusals(): array
    {
        if (!$this->isLeader) {
            return [];
        }

        $snapshots = $this->lastPlacementRefusals;
        ksort($snapshots, SORT_STRING);

        return array_values($snapshots);
    }

    /**
     * @return ?string Leader this node knows, including the placing leader on a slave
     * @throws EnvException When cluster mode cannot be read
     */
    public function knownLeaderId(): ?string
    {
        $leadership = Hilos::$cluster?->leadership();

        return $leadership instanceof PendingLeadership ? $this->placingLeaderId : $leadership?->leaderId();
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
     *   start through placement. Masters ask consensus directly; a slave has no coordinator and
     *   uses the leader that placed its own work. Before one is known the answer is unknown;
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
            $leadership = Hilos::$cluster?->leadership();
            $leaderId = $leadership instanceof PendingLeadership
                ? $this->placingLeaderId
                : $leadership?->leaderId();

            return $this->locationOfNode($leaderId);
        }

        return $this->locationOfNode($this->hostingNode($this->agentId($agentType, $agentIndex)));
    }

    /**
     * Places an agent of the given type on a named node: the permanent remote-placement
     * primitive.
     *
     * Passes the target node through the hard gate (required capability tags, a declared
     * capacity, free room for the agent's cost) first and rejects before anything is sent when it
     * does not fit. A placement at this node runs the local start path synchronously; any other node id sends a place frame
     * and records the placement as pending until the node's status reply lands. To let the
     * policy choose the node instead of naming one, use {@see placeAgentOnBestNode()}.
     *
     * A refusal of the gate is kept as the agent's last refusal and leaves its record as it was
     * (HIL-447): a move the target refused does not turn a running placement into a failure. An
     * accepted attempt drops the last refusal and calls off the failover the agent's loss armed;
     * one the mesh could not carry is a failure of its own.
     *
     * @param string $agentType Agent type to launch
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Id of the node to place the agent on
     * @throws PlacementCapabilityException When the node lacks a required tag, declares no capacity or has no room for the cost
     * @throws LogicException When the agent, or one already placed, declares a negative cost or the reserved slot
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

        $agentId = $this->agentId($agentType, $agentIndex);
        $delivered = $this->mesh->sendToNode($nodeId, new PeerPlaceAgentDTO($agentType, $agentIndex));
        if ($delivered) {
            $this->registry->put(new PlacementRecord($agentType, $agentIndex, $nodeId, PlacementState::Placing));
            unset($this->lastPlacementRefusals[$agentId]);
            $this->callOffFailoverOf($agentId, $nodeId);
        } else {
            $this->recordFailure(
                $nodeId,
                new PlacementRefusalSnapshot($agentType, $agentIndex, "node '{$nodeId}' is not linked", []),
            );
        }
        // A re-placement routinely lands on the very node the agent left — best-fit reads the
        // emptied node as the least loaded one — so the fresh wait would inherit the old one's
        // `asked` flag and never send its own query. Drop the entry and let the sweep re-arm it.
        unset($this->placementAckDeadlines[$agentId]);

        Logger::info("Placing agent '{$agentId}' on node '{$nodeId}'" . ($delivered ? '' : ' failed: node is not linked'));
    }

    /**
     * Places an agent on the node the policy picks as the best fit: the automatic
     * node-selection entry (HIL-182) layered on the named-node primitive.
     *
     * Reads the agent's required tags and resource cost, asks the {@see PlacementPolicy} to
     * rank the online nodes by fit, and places on the winner via {@see placeAgentOnNode()}.
     * When no online node clears the hard gate nothing is placed and null is returned, so the
     * caller can retry on the next capable join rather than fail. A heavy worker thus lands where
     * it is the smaller share of the free capacity, a free one wherever fewest agents run.
     *
     * A fresh leader whose rebuild is not settled yet places nothing it has no record of, and
     * answers null as if no node were a fit (HIL-1217): such an agent may still run on a node that
     * has not reported, and the policy pass, the fleet supervisor or a project's pool asks again
     * on its next tick. An agent the leader does know - a record in any state - is not held.
     *
     * @param string $agentType Agent type to launch
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?string Chosen node id the agent was placed on, or null when no node is a fit or the rebuild holds it
     * @throws PlacementCapabilityException When the chosen node no longer clears the hard gate
     * @throws LogicException When the agent, or one already placed, declares a negative cost or the reserved slot
     * @throws AgentDaemonCreationFailedException When a local placement's daemon cannot be built
     * @throws NoSuitableWorkerException When a local placement has no worker to host it
     * @throws AgentNotLinkedToWorkerException When a local placement did not link to a worker
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function placeAgentOnBestNode(string $agentType, ?string $agentIndex): ?string
    {
        if ($this->rebuildHolds($agentType, $agentIndex)) {
            return null;
        }

        return $this->placeOnBestFitNode($agentType, $agentIndex);
    }

    /**
     * Places an agent on the best-fit node, past the rebuild a fresh leader may still wait for.
     *
     * The body of {@see placeAgentOnBestNode()}, called on its own where the leader knew the agent
     * a line earlier: {@see reconcileMissingAgents()} forgets the record of an agent its node no
     * longer hosts and places it again at once, and holding it there would leave it unplaced
     * until that node's next report.
     *
     * When no node is a fit, why is kept as the agent's last refusal (HIL-447): every online
     * node's own refusal, judged on the same set and at the same cost the policy saw
     * ({@see noFittingNode()}). Nothing else is recorded, so a new agent no node takes still gets
     * no record and is not retried.
     *
     * @param string $agentType Agent type to launch
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?string Chosen node id the agent was placed on, or null when no node is a fit
     * @throws PlacementCapabilityException When the chosen node no longer clears the hard gate
     * @throws LogicException When the agent, or one already placed, declares a negative cost or the reserved slot
     * @throws AgentDaemonCreationFailedException When a local placement's daemon cannot be built
     * @throws NoSuitableWorkerException When a local placement has no worker to host it
     * @throws AgentNotLinkedToWorkerException When a local placement did not link to a worker
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    private function placeOnBestFitNode(string $agentType, ?string $agentIndex): ?string
    {
        $required = $this->executor->requiredCapabilities($agentType, $agentIndex);
        $cost = $this->placementCost($agentType, $agentIndex);
        $target = $this->pickBestNode($agentType, $agentIndex, $required, $cost, '');
        if ($target === null) {
            $refusal = $this->noFittingNode($agentType, $agentIndex, $required, $cost, '');
            $this->rememberRefusal($refusal);
            Logger::info("No capable node to place agent '{$this->agentId($agentType, $agentIndex)}': {$refusal->reason}");
            return null;
        }

        $this->placeAgentOnNode($agentType, $agentIndex, $target);

        return $target;
    }

    /**
     * Chooses where an agent would fit now without changing the placement view.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return ?string Best capable online node, or null
     * @throws AgentDaemonCreationFailedException When an agent's placement profile cannot be built
     * @throws HilosException When the project factory cannot describe an agent
     * @throws LogicException When an agent declares a negative cost or the reserved slot
     */
    public function bestNodeFor(string $agentType, ?string $agentIndex): ?string
    {
        return $this->pickBestNode(
            $agentType,
            $agentIndex,
            $this->executor->requiredCapabilities($agentType, $agentIndex),
            $this->placementCost($agentType, $agentIndex),
            '',
        );
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
     * otherwise repeat the ask before the first placement could have landed. The leader answers
     * each ask with a {@see PeerPlacementVerdictDTO}: placed, or not, with the record's state.
     * The interval is also the retry: a holder that is still waiting asks again, so an ask that
     * met no leader is not silent forever.
     *
     * The frame that provoked this is held by its caller until the agent is up - here, or on the
     * node the placement names - and answered by the placement verdict when nobody could host it
     * (HIL-1041); there is no clock. On the leader that verdict comes back inside this very call,
     * so the caller holds the frame before it asks.
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
            $this->rememberPlacementAsker($agentId, $this->selfNodeId);
            $this->placeOnDemand($agentType, $agentIndex);

            return;
        }

        // Slaves have no consensus coordinator. Like locate(), they follow the leader that
        // placed their work; a master's real consensus verdict must never fall back to it.
        $leadership = Hilos::$cluster?->leadership();
        $leaderId = $leadership instanceof PendingLeadership
            ? $this->placingLeaderId
            : $leadership?->leaderId();
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
     * stop frame. Either way the agent is dropped from the placement view, its last refusal with
     * it. A move or rebalance is a stop followed by a place; the rebalance policy is HIL-182 /
     * HIL-183.
     *
     * @param string $agentType Agent type to stop
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Id of the node the agent is placed on
     */
    public function stopAgentOnNode(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        $agentId = $this->agentId($agentType, $agentIndex);
        $this->revokeOnNode($agentType, $agentIndex, $nodeId);
        $this->registry->forget($agentId);
        unset($this->lastPlacementRefusals[$agentId]);
    }

    /**
     * Revokes a POLICY agent but retains its old record until the host confirms the stop.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param string $hostNodeId Current host
     * @param string $targetNodeId Policy's selected target
     */
    public function restartElsewhere(
        string $agentType,
        ?string $agentIndex,
        string $hostNodeId,
        string $targetNodeId,
    ): void {
        $agentId = $this->agentId($agentType, $agentIndex);
        $this->restartsElsewhere[$agentId] = ['host' => $hostNodeId, 'target' => $targetNodeId, 'placing' => false];
        try {
            $sent = $this->revokeOnNode($agentType, $agentIndex, $hostNodeId);
        } catch (Throwable $failure) {
            unset($this->restartsElsewhere[$agentId]);
            throw $failure;
        }
        if (!$sent) {
            unset($this->restartsElsewhere[$agentId]);
            $this->restartSink->onRestartNotPlaced($agentType, $agentIndex, "node {$hostNodeId} is silent");
            return;
        }
        if ($hostNodeId === $this->selfNodeId) {
            $this->onAgentStatus($hostNodeId, PeerAgentStatusDTO::stopped($agentType, $agentIndex));
        }
    }

    /**
     * Retains a POLICY agent's placement while its local old worker reports stopped.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     */
    public function beginInPlaceRestart(string $agentType, ?string $agentIndex): void
    {
        $this->restartsInPlace[$this->agentId($agentType, $agentIndex)] = true;
    }

    /**
     * Releases an in-place hold and reports a failed restart to the placing leader.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param bool $running Whether an instance is running after the attempt
     * @param string $reason Failure reason when no instance runs
     */
    public function endInPlaceRestart(string $agentType, ?string $agentIndex, bool $running, string $reason): void
    {
        $agentId = $this->agentId($agentType, $agentIndex);
        if (!isset($this->restartsInPlace[$agentId])) {
            return;
        }
        unset($this->restartsInPlace[$agentId]);
        if ($running) {
            return;
        }

        unset($this->hosted[$agentId]);
        if ($this->isLeader) {
            $this->recordFailure($this->selfNodeId, new PlacementRefusalSnapshot($agentType, $agentIndex, $reason, []));
        } elseif ($this->placingLeaderId !== null) {
            $this->mesh->sendToNode($this->placingLeaderId, PeerAgentStatusDTO::failed($agentType, $agentIndex, $reason));
        }
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
     * @return bool Whether the local stop ran or a peer link carried it
     */
    private function revokeOnNode(string $agentType, ?string $agentIndex, string $nodeId): bool
    {
        if ($nodeId === $this->selfNodeId) {
            $agentId = $this->agentId($agentType, $agentIndex);
            // Drop the deferred answer before the revoke: stopping a waiting agent reports the
            // start failed on this same stack, and that callback must not rewrite this record.
            unset($this->deferredPlacementAnswers[$agentId]);
            $this->executor->revokePlacement($agentType, $agentIndex);
            unset($this->hosted[$agentId]);

            return true;
        }

        return $this->mesh->sendToNode($nodeId, new PeerStopAgentDTO($agentType, $agentIndex));
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
     * The report is addressed to the leader this node answers to — the one that placed this
     * node's work or took it over with a rebuild query ({@see onPlacementQuery()}) — exactly as
     * {@see onStopAgent()} answers the leader that asked, and not to whoever leads at this
     * instant, which would make this class read a global to say something about its own state.
     * A term change moves the addressee with it: a fresh leader queries every node on winning,
     * so the report reaches the leader that holds the record (HIL-440). What is left is a query
     * from a deposed leader overtaking the new one's on another link; the next query of the new
     * leader corrects it.
     *
     * @param string $agentType Agent type that stopped
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function noteAgentStopped(string $agentType, ?string $agentIndex): void
    {
        $agentId = $this->agentId($agentType, $agentIndex);
        if (isset($this->restartsInPlace[$agentId])) {
            return;
        }
        if (!isset($this->hosted[$agentId])) {
            return;
        }

        unset($this->hosted[$agentId]);

        if ($this->isLeader) {
            $this->registry->forget($agentId);
            unset($this->lastPlacementRefusals[$agentId]);

            return;
        }

        if ($this->placingLeaderId === null) {
            return;
        }

        $this->mesh->sendToNode($this->placingLeaderId, PeerAgentStatusDTO::stopped($agentType, $agentIndex));
    }

    /**
     * Answers a placement this node accepted while the agent waited for a worker, now that it is seated.
     *
     * The start report is the fact the wait was sitting on: the placing leader is told started, or
     * a placement the leader made on itself is marked Started. A start of an agent this node did
     * not defer is not news here.
     *
     * @param string $agentType Agent type that started
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function noteAgentStarted(string $agentType, ?string $agentIndex): void
    {
        $agentId = $this->agentId($agentType, $agentIndex);
        $restart = $this->restartsElsewhere[$agentId] ?? null;
        $restartHere = $restart !== null && $restart['placing'] && $restart['target'] === $this->selfNodeId;
        $deferred = $this->deferredPlacementAnswers[$agentId] ?? null;
        if ($deferred === null) {
            if ($restartHere) {
                unset($this->restartsElsewhere[$agentId]);
                $this->restartSink->onRestartPlaced($agentType, $agentIndex, $this->selfNodeId);
            }
            return;
        }

        unset($this->deferredPlacementAnswers[$agentId]);
        $nodeId = $deferred['nodeId'];
        if ($nodeId !== null) {
            $workerId = $this->executor->placedWorkerId($agentType, $agentIndex);
            if ($workerId !== null) {
                $this->answerPlacementStarted($nodeId, $agentType, $agentIndex, $workerId);
            }

            return;
        }

        $record = new PlacementRecord($agentType, $agentIndex, $this->selfNodeId, PlacementState::Started);
        $this->hosted[$agentId] = $record;
        $this->registry->put($record);
        unset($this->lastPlacementRefusals[$agentId]);
        unset($this->placementVerdictWaiters[$agentId]);
        if ($restartHere) {
            unset($this->restartsElsewhere[$agentId]);
            $this->restartSink->onRestartPlaced($agentType, $agentIndex, $this->selfNodeId);
        }
    }

    /**
     * Answers a placement this node accepted while the agent waited for a worker, now that the start
     * did not finish.
     *
     * The reason is the one the start failed with, not a clock: the placing leader is told failed,
     * or a placement the leader made on itself is marked Failed and the nodes that asked are
     * answered. A failure of an agent this node did not defer is not news here. It is not the
     * gate's: the record, the last refusal and the answers carry the reason with no refusals.
     *
     * @param string $agentType Agent type whose start failed
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $reason Why the start did not finish
     */
    public function noteAgentStartFailed(string $agentType, ?string $agentIndex, string $reason): void
    {
        $agentId = $this->agentId($agentType, $agentIndex);
        $restart = $this->restartsElsewhere[$agentId] ?? null;
        if ($restart !== null && $restart['placing'] && $restart['target'] === $this->selfNodeId) {
            unset($this->restartsElsewhere[$agentId]);
            unset($this->hosted[$agentId]);
            $this->recordFailure($this->selfNodeId, new PlacementRefusalSnapshot($agentType, $agentIndex, $reason, []));
            $this->restartSink->onRestartNotPlaced($agentType, $agentIndex, $reason);
        }
        $deferred = $this->deferredPlacementAnswers[$agentId] ?? null;
        if ($deferred === null) {
            return;
        }

        unset($this->deferredPlacementAnswers[$agentId]);
        Logger::warning("Placement of '{$agentId}' failed: {$reason}");
        $nodeId = $deferred['nodeId'];
        if ($nodeId !== null) {
            $this->mesh->sendToNode($nodeId, PeerAgentStatusDTO::failed($agentType, $agentIndex, $reason));

            return;
        }

        $this->recordFailure($this->selfNodeId, new PlacementRefusalSnapshot($agentType, $agentIndex, $reason, []));
        $this->answerPlacementAskers(
            $agentId,
            PeerPlacementVerdictDTO::notPlaced($agentType, $agentIndex, PlacementState::Failed, $reason),
        );
    }

    /**
     * Node side: launches a leader-requested agent locally and reports the outcome.
     *
     * Reuses the local start path — no new spawn logic — and answers the leader with a
     * started status carrying the worker id, or a failed status carrying the reason. A
     * failure is caught and reported rather than propagated, so a bad placement never
     * tears down the daemon loop.
     *
     * Nothing starts, and nothing is deferred, before the frame passes the node's own end of the
     * hard gate ({@see requireLocalFit()}, HIL-447): the leader judged this node by its own
     * registry, which can lag what the node runs, and the node is the one that knows. A refusal
     * is answered with the same failed status as any other failure, carrying the gate's text and
     * the gate's refusal for this node; any other failure carries its text and no refusal, rather
     * than a capacity code nobody judged.
     *
     * A placement accepted while a monopolistic worker is raised for the agent is answered later,
     * by {@see noteAgentStarted()} or {@see noteAgentStartFailed()}, and nothing is sent now
     * (HIL-998): refusing a node a second away from ready would send the agent looking elsewhere,
     * and the leader already waits {@see DEFAULT_PLACEMENT_ACK_TIMEOUT_MS} for the answer, with
     * the record in {@see PlacementState::Placing} read as ordinary travel time.
     *
     * @param string $fromNodeId Id of the leader node that requested the placement
     * @param PeerPlaceAgentDTO $frame Received place-agent frame
     */
    public function onPlaceAgent(string $fromNodeId, PeerPlaceAgentDTO $frame): void
    {
        $agentType = $frame->agentType;
        $agentIndex = $frame->agentIndex;

        try {
            $this->requireLocalFit($agentType, $agentIndex);
            $workerId = $this->executor->executePlacement($agentType, $agentIndex);
        } catch (PlacementCapabilityException $e) {
            Logger::warning("Placement of '{$this->agentId($agentType, $agentIndex)}' refused: {$e->getMessage()}");
            $this->mesh->sendToNode(
                $fromNodeId,
                PeerAgentStatusDTO::failed($agentType, $agentIndex, $e->getMessage(), [$e->refusal]),
            );
            return;
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
        $this->answerTo($fromNodeId);
        $this->mesh->sendToNode($fromNodeId, PeerAgentStatusDTO::started($agentType, $agentIndex, $workerId));
    }

    /**
     * Remembers a placement accepted while the agent waits for a worker raised for it (HIL-998).
     *
     * A remote leader is remembered as the placing leader here, not only when the agent is later
     * seated: until then the agent is not in the hosted set, and losing that leader must still
     * arm the self-fence.
     *
     * @param ?string $fromNodeId Leader owed the answer, or null for a placement the leader made on itself
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    private function deferPlacementAnswer(?string $fromNodeId, string $agentType, ?string $agentIndex): void
    {
        if ($fromNodeId !== null) {
            $this->answerTo($fromNodeId);
        }

        $this->deferredPlacementAnswers[$this->agentId($agentType, $agentIndex)] = [
            'nodeId' => $fromNodeId,
            'agentType' => $agentType,
            'agentIndex' => $agentIndex,
        ];
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
        // Drop the deferred answer before the revoke: stopping a waiting agent reports the start
        // failed on this same stack, and `stopped` below is the only answer that stop owes.
        unset($this->deferredPlacementAnswers[$agentId]);
        $this->executor->revokePlacement($frame->agentType, $frame->agentIndex);
        unset($this->hosted[$agentId]);
        $this->mesh->sendToNode($fromNodeId, PeerAgentStatusDTO::stopped($frame->agentType, $frame->agentIndex));
    }

    /**
     * Leader side: folds a node's placement status into the placement view.
     *
     * A stopped status forgets the placement; a started or failed status records it
     * against the reporting node so the view reflects where each agent actually landed.
     *
     * A failed status is recorded with its error and the node's refusals, kept as the agent's last
     * refusal, and handed on unchanged to the nodes waiting for a verdict (HIL-447); a started one
     * drops the last refusal, and so does a stopped one with the record.
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
        $restart = $this->restartsElsewhere[$agentId] ?? null;
        if ($existing?->state === PlacementState::Refused) {
            // A truth-source refusal must not be undone by the stop of a restart in flight.
            if ($restart !== null) {
                unset($this->restartsElsewhere[$agentId]);
                $this->restartSink->onRestartNotPlaced(
                    $frame->agentType,
                    $frame->agentIndex,
                    'placement refused by the truth-source guard',
                );
            }
            return;
        }
        if ($restart !== null && !$restart['placing']
            && $frame->state === PlacementState::Stopped && $fromNodeId === $restart['host']) {
            $this->registry->forget($agentId);
            $this->restartsElsewhere[$agentId]['placing'] = true;
            $target = $restart['target'];
            try {
                $this->placeAgentOnNode($frame->agentType, $frame->agentIndex, $target);
                if ($this->registry->get($agentId)?->state === PlacementState::Failed) {
                    throw new LogicException("Node {$target} could not receive the placement");
                }
            } catch (Throwable) {
                try {
                    $target = $this->placeOnBestFitNode($frame->agentType, $frame->agentIndex);
                } catch (Throwable) {
                    $target = null;
                }
            }
            if ($this->registry->get($agentId)?->state === PlacementState::Failed) {
                $target = null;
            }
            if ($target === null) {
                unset($this->restartsElsewhere[$agentId]);
                $this->restartSink->onRestartNotPlaced($frame->agentType, $frame->agentIndex, 'no capable node is available');
            } else {
                $this->restartsElsewhere[$agentId]['target'] = $target;
            }
            return;
        }
        if ($restart !== null && !$restart['placing']
            && $frame->state === PlacementState::Failed && $fromNodeId === $restart['host']) {
            unset($this->restartsElsewhere[$agentId]);
            $this->restartSink->onRestartNotPlaced(
                $frame->agentType,
                $frame->agentIndex,
                $frame->error ?? 'the old host failed before stopping',
            );
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
            unset($this->lastPlacementRefusals[$agentId]);
            unset($this->placementVerdictWaiters[$agentId]);
            return;
        }

        $failure = null;
        if ($frame->state === PlacementState::Failed) {
            $reason = $frame->error;
            if ($reason === null || $reason === '') {
                $reason = 'the node reported a failed placement';
            }
            $failure = new PlacementRefusalSnapshot($frame->agentType, $frame->agentIndex, $reason, $frame->refusals);
            $this->recordFailure($fromNodeId, $failure);
        } else {
            $this->registry->put(new PlacementRecord($frame->agentType, $frame->agentIndex, $fromNodeId, $frame->state));
            if ($frame->state === PlacementState::Started) {
                unset($this->lastPlacementRefusals[$agentId]);
            }
        }

        if ($restart !== null && $restart['placing'] && $fromNodeId === $restart['target']) {
            unset($this->restartsElsewhere[$agentId]);
            if ($frame->state === PlacementState::Started) {
                $this->restartSink->onRestartPlaced($frame->agentType, $frame->agentIndex, $fromNodeId);
            } elseif ($frame->state === PlacementState::Failed) {
                $this->restartSink->onRestartNotPlaced(
                    $frame->agentType,
                    $frame->agentIndex,
                    $frame->error ?? 'the node reported a failed placement',
                );
            }
        }

        if ($failure !== null) {
            $this->answerPlacementAskers(
                $agentId,
                PeerPlacementVerdictDTO::notPlaced(
                    $frame->agentType,
                    $frame->agentIndex,
                    PlacementState::Failed,
                    $failure->reason,
                    $failure->refusals,
                ),
            );

            return;
        }

        unset($this->placementVerdictWaiters[$agentId]);
    }

    /**
     * Node side: answers a leader's rebuild query with this node's hosted-agent set.
     *
     * A node that does not lead answers to the asker from this point: the query is when a fresh
     * leader takes this node's agents into its registry after winning, or when a current leader
     * rebuilds a placement whose acknowledgement timed out. A leader answers the query without
     * answering to its asker, because it owns its own placement view.
     *
     * @param string $fromNodeId Id of the leader node that asked
     */
    public function onPlacementQuery(string $fromNodeId): void
    {
        if (!$this->isLeader) {
            $this->answerTo($fromNodeId);
        }

        $this->mesh->sendToNode($fromNodeId, new PeerPlacementReportDTO($this->hostedEntries()));
    }

    /**
     * Node side: a release from the leader this node answers to.
     *
     * That leader leads no more, so it is forgotten - its return says nothing, as after a lost
     * quorum - and placed work is fenced unless a leader takes it over ({@see answerTo()}) or this
     * node leads ({@see onBecameLeader()}); from any other node it changes nothing. The frame is
     * lost only with its link, and a lost link to the leader arms the isolation fence anyway
     * (HIL-1287).
     *
     * A node hosting nothing only forgets the leader, and a deadline already armed is not moved,
     * nor is what armed it.
     *
     * @param string $fromNodeId Id of the node that stopped leading
     * @param float $now Current microtime
     */
    public function onPlacementRelease(string $fromNodeId, float $now): void
    {
        if ($fromNodeId !== $this->placingLeaderId) {
            return;
        }

        $this->placingLeaderId = null;
        if (!$this->hostsPlacedWork() || $this->selfFenceDeadline !== null) {
            return;
        }

        $this->selfFenceDeadline = $now + $this->slaveWorkGraceSec;
        $this->selfFenceCause = "placing leader '{$fromNodeId}' stopped leading";
        Logger::info("Self-fence armed: {$this->selfFenceCause}, {$this->placedCount()} placed agent(s) stop in "
            . sprintf('%.1f', $this->slaveWorkGraceSec) . 's unless a leader takes them over');
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

        $this->rememberPlacementAsker($this->agentId($frame->agentType, $frame->agentIndex), $fromNodeId);
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
     * Any report, whichever of those sources sent it, also accounts for the node in a rebuild
     * the leader still waits on (HIL-1217): from here on what the node runs is in the registry.
     * An agent it adopts is running, so its last refusal is dropped (HIL-447).
     *
     * @param string $fromNodeId Id of the node that reported
     * @param PeerPlacementReportDTO $frame Received placement report
     */
    public function onPlacementReport(string $fromNodeId, PeerPlacementReportDTO $frame): void
    {
        if (!$this->isLeader) {
            return;
        }

        unset($this->rebuildAwaited[$fromNodeId]);

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
            unset($this->lastPlacementRefusals[$agentId]);
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
     * Follower side: applies a leader's verdict on a placement this node asked for.
     *
     * Ignored on the leader: it already applied the outcome to the registry. A started
     * verdict writes the node into this node's copy of the view so a parked frame can
     * leave before the next published view arrives. Any other state is a not-placed
     * verdict and goes to the master through {@see PlacementVerdictSink}.
     *
     * @param string $fromNodeId Id of the node the verdict arrived from
     * @param PeerPlacementVerdictDTO $frame Received placement-verdict frame
     */
    public function onPlacementVerdict(string $fromNodeId, PeerPlacementVerdictDTO $frame): void
    {
        if ($this->isLeader) {
            return;
        }

        if ($fromNodeId === '') {
            Logger::warning('Dropping placement verdict with a blank sender node id');

            return;
        }

        $agentId = $this->agentId($frame->agentType, $frame->agentIndex);
        if ($frame->state === PlacementState::Started) {
            $nodeId = $frame->nodeId;
            if ($nodeId !== null) {
                $this->placementView[$agentId] = $nodeId;
            }

            return;
        }

        $reason = $frame->reason;
        if ($reason === null) {
            return;
        }

        Hilos::$cluster?->placementVerdictSink()?->onAgentNotPlaced(
            $frame->agentType,
            $frame->agentIndex,
            "{$frame->state->value}: {$reason}",
        );
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
     *
     * A winning node answers to nobody. Any self-fence armed against its former leader, or by a
     * quorum it has since regained, is canceled before this method records the hosted agents as
     * started in the new registry.
     *
     * Until the rebuild is settled the leader places nothing it has no record of (HIL-1217): it
     * waits for the report of every node online to it, and out the failover grace of every node
     * that went away less than that grace ago - a leader cut off from the majority stops its work
     * only that long after it lost its quorum, so a copy placed sooner would run beside the one it
     * still runs. Failover and every other record the leader knows are not held
     * ({@see placeAgentOnBestNode()}, {@see placeOnDemand()}).
     *
     * The last refusals start empty, whatever this node noted before: they are a term's diagnosis,
     * and nothing rebuilds them from the reports (HIL-447).
     *
     * @param float $now Current microtime
     */
    public function onBecameLeader(float $now): void
    {
        $this->isLeader = true;
        if ($this->selfFenceDeadline !== null) {
            Logger::info('Self-fence called off: this node leads now');
            $this->selfFenceDeadline = null;
            $this->selfFenceCause = null;
        }
        $this->placingLeaderId = null;
        $this->registry->clear();
        // Refusals are this term's diagnosis; the new term notes its own (HIL-446 keeps them across).
        $this->lastPlacementRefusals = [];
        // The copy this node held as a follower is somebody else's answer to the question it
        // now owns; from here the registry above is the original.
        $this->placementView = [];
        $this->publishedViewFingerprint = null;
        foreach ($this->hosted as $record) {
            $this->registry->put($record);
        }

        $this->mesh->broadcastToNodes(new PeerPlacementQueryDTO());
        $this->awaitRebuild($now);
    }

    /**
     * Lists what a fresh leader's rebuild waits for: the report of every node online to it, and
     * the failover grace of every node that went away less than that grace ago.
     *
     * @param float $now Current microtime
     */
    private function awaitRebuild(float $now): void
    {
        $this->rebuildAwaited = [];
        foreach ($this->mesh->onlineNodeIds() as $nodeId) {
            if ($nodeId !== $this->selfNodeId) {
                $this->rebuildAwaited[$nodeId] = null;
            }
        }

        foreach ($this->offlineSince as $nodeId => $since) {
            if ($nodeId !== $this->selfNodeId
                && !array_key_exists($nodeId, $this->rebuildAwaited)
                && $now < $since + $this->failoverGraceSec) {
                $this->rebuildAwaited[$nodeId] = $since + $this->failoverGraceSec;
            }
        }

        $this->rebuildPending = $this->rebuildAwaited !== [];
        if ($this->rebuildPending) {
            Logger::info('Placement rebuild: ' . count($this->rebuildAwaited) . ' node(s) to account for their agents'
                . ' before new work is placed: ' . implode(', ', array_keys($this->rebuildAwaited)));
        }
    }

    /**
     * Leader side: drops the placement view on losing leadership.
     *
     * The node keeps hosting the agents it was placed with — they are data-plane and run
     * on regardless of who leads — but it no longer owns the cluster-wide view, which the
     * next leader rebuilds from the mesh. Any pending failover timers, placement-ack waits,
     * placement-ask waiters, the last refusals and the rebuild with what it held drop with the
     * view; the next leader
     * re-derives them from its own rebuilt placements. When each node went away is kept: it is
     * what this node needs should it win the next term.
     *
     * A node that led tells every linked node it leads no more, so a node that answered to it - a
     * slave cut off with it from the majority knows nothing of a quorum - fences its placed work
     * instead of running it beside the copy the majority's leader starts (HIL-1287).
     */
    public function onLostLeadership(): void
    {
        $wasLeader = $this->isLeader;
        $this->isLeader = false;
        $this->registry->clear();
        $this->restartsElsewhere = [];
        $this->restartsInPlace = [];
        $this->failoverDeadlines = [];
        $this->placementAckDeadlines = [];
        $this->placementVerdictWaiters = [];
        $this->lastPlacementRefusals = [];
        // The rebuild was this term's; the next leader lists its own.
        $this->rebuildAwaited = [];
        $this->rebuildPending = false;
        $this->heldOnDemand = [];
        // Publishing is the leader's duty, so this node stops; what it published stays true
        // until the next leader publishes its own, which it does within a tick of winning.
        $this->publishedViewFingerprint = null;
        if ($wasLeader) {
            Logger::info('Placement release: this node no longer leads, telling every linked node');
            $this->mesh->broadcastToNodes(new PeerPlacementReleaseDTO());
        }
    }

    /**
     * Reacts to a node going offline: schedules failover and, if isolated, arms self-fence.
     *
     * Leader side — for every placed agent the offline node hosted, arms a failover deadline
     * one grace period out, absorbing a brief flap before {@see tick()} re-places it. Node
     * side — if this node does not lead and the offline node is the leader it answers to (the
     * one that placed its work or took it over with a rebuild query), arms the self-fence
     * deadline so those agents stop before the leader could start copies elsewhere.
     * Both are idempotent: a deadline already armed is left as it stands.
     *
     * Every node remembers when the node went away, so that winning a term later it knows whose
     * failover grace its rebuild has to wait out; a leader whose rebuild waits for this node's
     * report waits out that grace instead, from now (HIL-1217).
     *
     * @param string $nodeId Node id the transport just marked offline
     * @param float $now Current microtime
     */
    public function noteNodeOffline(string $nodeId, float $now): void
    {
        foreach ($this->restartsElsewhere as $agentId => $restart) {
            if ((!$restart['placing'] && $restart['host'] !== $nodeId)
                || ($restart['placing'] && $restart['target'] !== $nodeId)) {
                continue;
            }
            unset($this->restartsElsewhere[$agentId]);
            $record = $this->registry->get($agentId);
            if ($record !== null) {
                $this->restartSink->onRestartNotPlaced(
                    $record->agentType,
                    $record->agentIndex,
                    "node {$nodeId} went silent during the restart",
                );
            }
        }
        $this->offlineSince[$nodeId] ??= $now;
        if ($this->isLeader && array_key_exists($nodeId, $this->rebuildAwaited) && $this->rebuildAwaited[$nodeId] === null) {
            $this->rebuildAwaited[$nodeId] = $now + $this->failoverGraceSec;
        }

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

        if (!$this->isLeader
            && $nodeId === $this->placingLeaderId
            && $this->hostsPlacedWork()
            && $this->selfFenceDeadline === null) {
            $this->selfFenceDeadline = $now + $this->slaveWorkGraceSec;
            $this->selfFenceCause = "isolated from placing leader '{$nodeId}'";
            Logger::info("Self-fence armed: placing leader '{$nodeId}' went offline, {$this->placedCount()}"
                . ' placed agent(s) stop in ' . sprintf('%.1f', $this->slaveWorkGraceSec) . 's unless it returns');
        }
    }

    /**
     * Reacts to this node losing its quorum: arms the self-fence over the placed work it hosts.
     *
     * In a minority nobody leads, so nobody can take this node's placements over, whatever it
     * answered to - the leader that placed its work, or nobody when it led itself. A former
     * leader's placed work used to be left running here, and the majority started copies of it
     * (HIL-1217). So the leader it answered to is forgotten - its return says nothing about a
     * quorum - and the fence is called off only by a leader that takes the placements over
     * ({@see answerTo()}) or by this node leading again ({@see onBecameLeader()}); otherwise the
     * work stops after `CLUSTER_SLAVE_WORK_GRACE_MS`, which the majority's fresh leader outwaits
     * before it starts any of it again.
     *
     * A node hosting nothing arms nothing, and a deadline already armed is not moved.
     *
     * @param float $now Current microtime
     */
    public function noteQuorumLost(float $now): void
    {
        if (!$this->hostsPlacedWork()) {
            return;
        }

        $this->placingLeaderId = null;
        $this->selfFenceCause = 'quorum lost';
        if ($this->selfFenceDeadline !== null) {
            return;
        }

        $this->selfFenceDeadline = $now + $this->slaveWorkGraceSec;
        Logger::info("Self-fence armed: quorum lost, {$this->placedCount()} placed agent(s) stop in "
            . sprintf('%.1f', $this->slaveWorkGraceSec) . 's unless a leader takes them over');
    }

    /**
     * Tells whether this node hosts placed work, seated or still waiting for a worker.
     *
     * @return bool True when anything placed here would have to stop on a self-fence
     */
    private function hostsPlacedWork(): bool
    {
        return $this->hosted !== [] || $this->deferredPlacementAnswers !== [];
    }

    /**
     * Counts the placed agents this node hosts, seated or still waiting for a worker.
     *
     * @return int Placed agents a self-fence would stop
     */
    private function placedCount(): int
    {
        return count($this->hosted) + count($this->deferredPlacementAnswers);
    }

    /**
     * Reacts to a node coming online: cancels a stale failover/self-fence, retries degraded.
     *
     * Node side — if the returning node is the placing leader, the isolation is over, so the
     * self-fence is disarmed. Leader side — a flapped node back before its grace keeps its
     * agents, so its pending failover is canceled; and since a capable node may now be
     * available, every agent failover had to leave {@see PlacementState::Unplaced} is retried.
     * A node a fresh leader's rebuild waited out the grace of is waited for by its report again,
     * which it sends on the link that brought it back (HIL-1217).
     *
     * @param string $nodeId Node id the transport just marked online
     * @param float $now Current microtime
     */
    public function noteNodeOnline(string $nodeId, float $now): void
    {
        unset($this->offlineSince[$nodeId]);
        if (array_key_exists($nodeId, $this->rebuildAwaited)) {
            $this->rebuildAwaited[$nodeId] = null;
        }

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
            $this->selfFenceCause = null;
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
     * Calls off the failover of one agent, now that the leader has sent it a placement of its own
     * (HIL-447).
     *
     * The placement is the leader's answer to the loss that armed the deadline, so the deadline
     * speaks about it no more, whichever node the agent went to. Back on the very node it was lost
     * from - a recreated node stops its agents on the way out, their records go with the stops,
     * and its return finds nothing of theirs to call off - the deadline would fire at the fresh
     * record, take the running agent for lost and, with no other node to take it, mark it
     * unplaced, after which the leader counts that node's room short of what it holds. On another
     * node it would stand in the way of the deadline that node's own loss arms, and then drop
     * itself as stale. Nothing armed, nothing done.
     *
     * @param string $agentId Agent id just placed
     * @param string $nodeId Node id the placement was sent to
     */
    private function callOffFailoverOf(string $agentId, string $nodeId): void
    {
        $lostNodeId = $this->failoverDeadlines[$agentId]['nodeId'] ?? null;
        if ($lostNodeId === null) {
            return;
        }

        unset($this->failoverDeadlines[$agentId]);
        Logger::info("Failover of '{$agentId}' armed by the loss of '{$lostNodeId}' called off: placed afresh on '{$nodeId}'");
    }

    /**
     * Makes this node answer to the leader that owns the picture of its agents.
     *
     * A leader owns that picture by placing work here or rebuilding it from this node. Taking
     * over from another leader ends the isolation the old leader's loss armed, because this node
     * is no longer cut off from the leader responsible for its placements (HIL-440); taking over
     * from nobody ends the fence a lost quorum armed, because a leader holds the work again
     * (HIL-1217).
     *
     * @param string $leaderNodeId Id of the leader that owns this node's placement picture
     */
    private function answerTo(string $leaderNodeId): void
    {
        if ($leaderNodeId === $this->placingLeaderId) {
            return;
        }

        if ($this->selfFenceDeadline !== null) {
            Logger::info("Self-fence called off: leader '{$leaderNodeId}' took over this node's placements"
                . ($this->placingLeaderId === null ? '' : " from '{$this->placingLeaderId}'"));
            $this->selfFenceDeadline = null;
            $this->selfFenceCause = null;
        }

        $this->placingLeaderId = $leaderNodeId;
    }

    /**
     * Fires any failover, placement-ack timeout or self-fence whose grace has elapsed, and settles
     * a fresh leader's rebuild once nothing is left to wait for. Driven each daemon tick.
     *
     * @param float $now Current microtime
     */
    public function tick(float $now): void
    {
        foreach ($this->failoverDeadlines as $agentId => ['nodeId' => $lostNodeId, 'deadline' => $deadline]) {
            if ($now >= $deadline) {
                unset($this->failoverDeadlines[$agentId]);
                $this->failOver($agentId, $lostNodeId);
            }
        }

        // After failover, which the rebuild never held, and before the ack sweep, so a placement
        // the settling makes is given its deadline in this same tick.
        $this->settleRebuild($now);

        // After failover, not before: a failover re-places a record out of `Placing` onto
        // another node, and arming on the far side of it means arming for the node the record
        // actually names now.
        $this->sweepPlacementAcks($now);

        if ($this->selfFenceDeadline !== null && $now >= $this->selfFenceDeadline) {
            $this->selfFenceDeadline = null;
            $this->selfFence();
            $this->selfFenceCause = null;
        }

        $this->publishPlacementView();
    }

    /**
     * Strikes the failover graces a fresh leader's rebuild has waited out, and once nothing is
     * left to wait for, says so and places the addressed agents it held meanwhile (HIL-1217).
     *
     * The held agents go through {@see placeOnDemand()}, so the nodes that asked for them are
     * answered from there, exactly as they would have been had the rebuild not held them.
     *
     * @param float $now Current microtime
     */
    private function settleRebuild(float $now): void
    {
        foreach ($this->rebuildAwaited as $nodeId => $deadline) {
            if ($deadline !== null && $now >= $deadline) {
                unset($this->rebuildAwaited[$nodeId]);
            }
        }

        if (!$this->rebuildPending || $this->rebuildAwaited !== []) {
            return;
        }

        $this->rebuildPending = false;
        Logger::info('Placement rebuild settled: every node accounted for its agents');
        $held = $this->heldOnDemand;
        $this->heldOnDemand = [];
        foreach ($held as ['agentType' => $agentType, 'agentIndex' => $agentIndex]) {
            $this->placeOnDemand($agentType, $agentIndex);
        }
    }

    /**
     * Tells whether a fresh leader's unsettled rebuild holds back the placement of an agent.
     *
     * It holds only an agent the leader has no record of: one with a record in any state is
     * work the leader knows where it stands with, and failover, reconciliation and the retry of
     * an unplaced agent act on exactly those (HIL-1217).
     *
     * @param string $agentType Agent type to place
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return bool True when the agent has to wait for the rebuild to settle
     */
    private function rebuildHolds(string $agentType, ?string $agentIndex): bool
    {
        return $this->rebuildAwaited !== [] && $this->registry->get($this->agentId($agentType, $agentIndex)) === null;
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
     * What the peer's loss armed here - the self-fence, the failover - is called off by
     * {@see noteNodeOnline()}, not here: a handshake after a lost link is always a return the
     * registry reports, because nothing but this node's own handshake puts a node back online
     * (HIL-1059).
     *
     * @param string $nodeId Node id of the peer that just handshaked
     */
    public function onPeerHandshaked(string $nodeId): void
    {
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
     * An agent no node hosts — failed to start, stopped, degraded for want of a node, or
     * refused an RT claim — is left out under the same rule {@see hostingNode()} applies on
     * this node: it has no node to forward to. Because both sides read this one rule, a copy
     * answers exactly what the original would.
     *
     * @return array<string|int, list<PeerPlacedAgentEntry>> Hosted agent entries, by node id
     */
    private function placementViewAgents(): array
    {
        $agents = [];
        foreach ($this->registry->all() as $record) {
            if (!$record->state->hostsAgent()) {
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
     * records under the rule applied here — an agent no node hosts is left out of both,
     * including Failed, which used to read as an address (HIL-1041).
     *
     * @param string $agentId Agent id to look up
     * @return ?string Hosting node id, or null when nothing places it
     */
    private function hostingNode(string $agentId): ?string
    {
        $record = $this->registry->get($agentId);
        if ($record !== null && $record->state->hostsAgent()) {
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
     * re-placement fails, the agent is degraded to {@see PlacementState::Unplaced}, with why
     * (HIL-447): every node's refusal when none fit, the gate's when the pick refused, the text
     * alone for any other error. Any error is caught so a bad failover never tears down the
     * daemon loop.
     *
     * "Moved" is judged against the node the deadline was armed for, not against the registry
     * alone: within one grace period the agent may have been re-placed onto a neighbour — by
     * the project that supervises the fleet, or by a report the returning node sent — and a
     * deadline that outlived that move speaks about a node the agent no longer sits on (one the
     * leader sent over the mesh has called the deadline off already, HIL-447). Firing
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
            $cost = $this->placementCost($record->agentType, $record->agentIndex);
            $target = $this->pickBestNode($record->agentType, $record->agentIndex, $required, $cost, $record->nodeId);
            if ($target !== null) {
                Logger::info("Failover: re-placing '{$agentId}' from lost node '{$record->nodeId}' onto '{$target}'");
                $this->placeAgentOnNode($record->agentType, $record->agentIndex, $target);
                return;
            }
            $failure = $this->noFittingNode($record->agentType, $record->agentIndex, $required, $cost, $record->nodeId);
        } catch (Throwable $e) {
            Logger::warning("Failover of '{$agentId}' could not re-place: {$e->getMessage()}");
            $failure = $this->failedAttempt($record->agentType, $record->agentIndex, $e);
        }

        $this->degrade($record, $failure);
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
     * ends run(). An agent nothing takes is degraded with why, exactly as {@see failOver()}
     * degrades one (HIL-447).
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
                if ($this->placeOnBestFitNode($record->agentType, $record->agentIndex) !== null) {
                    continue;
                }
                // The refusal of every node, which the attempt has just kept as this agent's last.
                $failure = $this->lastPlacementRefusals[$agentId];
            } catch (Throwable $e) {
                Logger::warning("Reconcile of '{$agentId}' could not re-place: {$e->getMessage()}");
                $failure = $this->failedAttempt($record->agentType, $record->agentIndex, $e);
            }

            $this->degrade($record, $failure);
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
     * agent stays {@see PlacementState::Unplaced}, and its record says why as of this retry
     * (HIL-447): the node that came online may refuse for a reason of its own. An error on one
     * agent is caught so the rest are still tried; one the gate did not raise is kept as the last
     * refusal and leaves the record to whatever the failed attempt wrote into it.
     */
    private function retryUnplaced(): void
    {
        foreach ($this->registry->all() as $record) {
            if ($record->state !== PlacementState::Unplaced) {
                continue;
            }

            try {
                $required = $this->executor->requiredCapabilities($record->agentType, $record->agentIndex);
                $cost = $this->placementCost($record->agentType, $record->agentIndex);
                $target = $this->pickBestNode($record->agentType, $record->agentIndex, $required, $cost, '');
                if ($target === null) {
                    $this->keepUnplaced(
                        $record,
                        $this->noFittingNode($record->agentType, $record->agentIndex, $required, $cost, ''),
                    );
                    continue;
                }
                Logger::info("Failover retry: placing unplaced '{$record->agentId()}' onto '{$target}'");
                $this->placeAgentOnNode($record->agentType, $record->agentIndex, $target);
            } catch (PlacementCapabilityException $e) {
                Logger::warning("Failover retry of '{$record->agentId()}' failed: {$e->getMessage()}");
                $this->keepUnplaced($record, $this->failedAttempt($record->agentType, $record->agentIndex, $e));
            } catch (Throwable $e) {
                Logger::warning("Failover retry of '{$record->agentId()}' failed: {$e->getMessage()}");
                $this->rememberRefusal($this->failedAttempt($record->agentType, $record->agentIndex, $e));
            }
        }
    }

    /**
     * Leaves an agent a retry could not place {@see PlacementState::Unplaced}, with why as of now.
     *
     * Not a degrade: the agent was unplaced already and the observer has been told so.
     *
     * @param PlacementRecord $record Unplaced record of the agent
     * @param PlacementRefusalSnapshot $failure Why the retry placed it nowhere
     */
    private function keepUnplaced(PlacementRecord $record, PlacementRefusalSnapshot $failure): void
    {
        $this->registry->put(new PlacementRecord(
            $record->agentType,
            $record->agentIndex,
            $record->nodeId,
            PlacementState::Unplaced,
            $failure->reason,
            $failure->refusals,
        ));
        $this->rememberRefusal($failure);
    }

    /**
     * Marks an agent degraded, with why it could not be placed, and notifies the observer.
     *
     * The record names the node it was lost from, as before, and carries the failed attempt's
     * reason and refusals, which are also kept as the agent's last refusal (HIL-447).
     *
     * @param PlacementRecord $record Record of the agent that could not be placed
     * @param PlacementRefusalSnapshot $failure Why the attempt to place it again failed
     */
    private function degrade(PlacementRecord $record, PlacementRefusalSnapshot $failure): void
    {
        $this->registry->put(new PlacementRecord(
            $record->agentType,
            $record->agentIndex,
            $record->nodeId,
            PlacementState::Unplaced,
            $failure->reason,
            $failure->refusals,
        ));
        $this->rememberRefusal($failure);
        Logger::warning("Failover: no capable+online node for '{$record->agentId()}'; marked unplaced: {$failure->reason}");
        $this->observer->onPlacementDegraded($record->agentType, $record->agentIndex);
    }

    /**
     * Picks the best-fit online node other than the excluded one, or null when none is a fit.
     *
     * Builds a candidate per online node — what it declares, what its live placements hold, how
     * many they are — and hands the gate and the ranking to the {@see PlacementPolicy}, so
     * failover and the automatic entry choose identically. Occupancy comes from this leader's own
     * placement view, which is the only cluster-wide record of who runs what.
     *
     * A question, not an attempt: a null answer writes nothing (HIL-447). The caller that acts on
     * it asks {@see noFittingNode()} why, on the same nodes.
     *
     * @param string $agentType Agent type being placed, whose own record holds nothing against it
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param list<string> $required Capability tags the agent needs
     * @param ResourceProfile $cost Resource cost of the agent
     * @param string $excludeNodeId Node id to skip (the lost host, or '' to exclude none)
     * @return ?string Chosen node id, or null when no online node is a fit
     * @throws AgentDaemonCreationFailedException When a live placement's daemon cannot be built to read its cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     * @throws LogicException When the agent, or one already placed, declares a negative cost or the reserved slot
     */
    private function pickBestNode(
        string $agentType,
        ?string $agentIndex,
        array $required,
        ResourceProfile $cost,
        string $excludeNodeId,
    ): ?string {
        return $this->policy->selectNode(
            $required,
            $cost,
            $this->placementCandidates($this->agentId($agentType, $agentIndex), $this->onlineNodeIdsExcept($excludeNodeId)),
        );
    }

    /**
     * Returns the online nodes a placement may choose from: every one but the excluded node.
     *
     * @param string $excludeNodeId Node id to skip (the lost host, or '' to exclude none)
     * @return list<string> Online node ids
     */
    private function onlineNodeIdsExcept(string $excludeNodeId): array
    {
        return array_values(array_filter(
            $this->mesh->onlineNodeIds(),
            static fn(string $nodeId): bool => $nodeId !== $excludeNodeId,
        ));
    }

    /**
     * Collects why no online node takes an agent: each node's first failed gate, on the same nodes
     * and at the same cost {@see pickBestNode()} judged (HIL-447).
     *
     * One refusal per node, by node id, from the one gate the policy applies
     * ({@see PlacementCandidate::refusal()}). With no online node to judge the answer is the
     * refusal that names none. When some node DOES clear the gate, the policy turned it down by a
     * rule of its own, and the answer is empty rather than a capacity refusal invented for it.
     *
     * @param string $agentType Agent type being placed
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param list<string> $required Capability tags the agent needs
     * @param ResourceProfile $cost Resource cost of the agent
     * @param string $excludeNodeId Node id to skip (the lost host, or '' to exclude none)
     * @return list<PlacementRefusal> Refusals ordered by node id; empty when the policy declined a node that fits
     * @throws AgentDaemonCreationFailedException When a live placement's daemon cannot be built to read its cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     * @throws LogicException When an agent already placed declares a negative cost or the reserved slot
     */
    private function collectPlacementRefusals(
        string $agentType,
        ?string $agentIndex,
        array $required,
        ResourceProfile $cost,
        string $excludeNodeId,
    ): array {
        $online = $this->onlineNodeIdsExcept($excludeNodeId);
        if ($online === []) {
            return [PlacementRefusal::noOnlineNodes()];
        }

        $refusals = [];
        foreach ($this->placementCandidates($this->agentId($agentType, $agentIndex), $online) as $nodeId => $candidate) {
            $refusal = $candidate->refusal($required, $cost);
            if ($refusal === null) {
                return [];
            }

            $refusals[(string)$nodeId] = $refusal;
        }
        ksort($refusals, SORT_STRING);

        return array_values($refusals);
    }

    /**
     * Says why an attempt found no node for an agent: the refusals of every online node, and the
     * text a person reads beside them (HIL-447).
     *
     * The text names which of the three it was — nothing online, no node that fits, or a policy
     * that declined the nodes that do — so the three are not mistaken for one another.
     *
     * @param string $agentType Agent type being placed
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param list<string> $required Capability tags the agent needs
     * @param ResourceProfile $cost Resource cost of the agent
     * @param string $excludeNodeId Node id to skip (the lost host, or '' to exclude none)
     * @return PlacementRefusalSnapshot Why no node took the agent
     * @throws AgentDaemonCreationFailedException When a live placement's daemon cannot be built to read its cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     * @throws LogicException When an agent already placed declares a negative cost or the reserved slot
     */
    private function noFittingNode(
        string $agentType,
        ?string $agentIndex,
        array $required,
        ResourceProfile $cost,
        string $excludeNodeId,
    ): PlacementRefusalSnapshot {
        $refusals = $this->collectPlacementRefusals($agentType, $agentIndex, $required, $cost, $excludeNodeId);
        if ($refusals === []) {
            $reason = 'the placement policy declined every node that fits the agent';
        } elseif ($refusals[0]->reasonCode === PlacementRefusalCode::NoOnlineNodes) {
            $reason = 'no online node to place the agent on';
        } else {
            $reason = 'no capable and online node fits the agent';
        }

        return new PlacementRefusalSnapshot($agentType, $agentIndex, $reason, $refusals);
    }

    /**
     * Says why one placement attempt failed with an exception: its text, and the gate's refusal
     * when the gate raised it — never a code read out of the text (HIL-447).
     *
     * @param string $agentType Agent type being placed
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param Throwable $failure What the attempt failed with
     * @return PlacementRefusalSnapshot Why the attempt failed
     */
    private function failedAttempt(string $agentType, ?string $agentIndex, Throwable $failure): PlacementRefusalSnapshot
    {
        return new PlacementRefusalSnapshot(
            $agentType,
            $agentIndex,
            $failure->getMessage(),
            $failure instanceof PlacementCapabilityException ? [$failure->refusal] : [],
        );
    }

    /**
     * Keeps a failed attempt as its agent's last refusal, replacing the one before (HIL-447).
     *
     * @param PlacementRefusalSnapshot $failure Why the attempt failed
     */
    private function rememberRefusal(PlacementRefusalSnapshot $failure): void
    {
        $this->lastPlacementRefusals[$failure->agentId()] = $failure;
    }

    /**
     * Records a placement that failed on a node: a {@see PlacementState::Failed} record that says
     * why, kept as the agent's last refusal too (HIL-447).
     *
     * @param string $nodeId Node the placement failed on
     * @param PlacementRefusalSnapshot $failure Why it failed
     */
    private function recordFailure(string $nodeId, PlacementRefusalSnapshot $failure): void
    {
        $this->registry->put(new PlacementRecord(
            $failure->agentType,
            $failure->agentIndex,
            $nodeId,
            PlacementState::Failed,
            $failure->reason,
            $failure->refusals,
        ));
        $this->rememberRefusal($failure);
    }

    /**
     * Builds the placement candidate of each named node from this leader's registry.
     *
     * Only a live placement holds capacity: a Placing or Started record counts its cost, slot
     * included, and one head against its node, while an unplaced agent runs nowhere and a stopped
     * or failed one has already released whatever it held. The record of the agent being placed
     * is left out, so its old reservation never stands in the way of its own re-placement.
     *
     * @param string $placedAgentId Id of the agent being placed
     * @param list<string> $nodeIds Node ids to build candidates for
     * @return array<string, PlacementCandidate> Candidates keyed by node id
     * @throws AgentDaemonCreationFailedException When a live placement's daemon cannot be built to read its cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     * @throws LogicException When the agent, or one already placed, declares a negative cost or the reserved slot
     */
    private function placementCandidates(string $placedAgentId, array $nodeIds): array
    {
        $live = array_fill_keys($nodeIds, []);
        foreach ($this->registry->all() as $record) {
            if (!isset($live[$record->nodeId])
                || $record->agentId() === $placedAgentId
                || ($record->state !== PlacementState::Placing && $record->state !== PlacementState::Started)) {
                continue;
            }

            $live[$record->nodeId][] = $record;
        }

        $candidates = [];
        foreach ($nodeIds as $nodeId) {
            $candidates[$nodeId] = new PlacementCandidate(
                $nodeId,
                NodeCapacities::fromTags($this->mesh->nodeCapabilities($nodeId) ?? []),
                $this->heldBy($live[$nodeId]),
                count($live[$nodeId]),
                $this->isLeader && $nodeId === $this->selfNodeId,
            );
        }

        return $candidates;
    }

    /**
     * Builds this node's own placement candidate from what it runs itself: the gate a place frame
     * passes on the node before anything starts (HIL-447).
     *
     * A node that does not lead has no picture of the cluster — its registry holds what a leader
     * once published to it, not a complete account — so it is not read. What the node holds is
     * what it hosts plus the starts it accepted while a worker is raised for them, each agent once;
     * a waiting start holds its cost until it is seated or let go, exactly as a Placing record does
     * on the leader. The agent being placed is left out, as the leader leaves out its record. Its
     * tags are the ones this node advertises now.
     *
     * @param string $placedAgentId Id of the agent being placed
     * @return PlacementCandidate This node as a candidate for the agent
     * @throws AgentDaemonCreationFailedException When a held agent's daemon cannot be built to read its cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     * @throws LogicException When an agent this node holds declares a negative cost or the reserved slot
     */
    private function localCandidate(string $placedAgentId): PlacementCandidate
    {
        $held = $this->hosted;
        foreach ($this->deferredPlacementAnswers as $agentId => $deferred) {
            $held[$agentId] ??= new PlacementRecord(
                $deferred['agentType'],
                $deferred['agentIndex'],
                $this->selfNodeId,
                PlacementState::Placing,
            );
        }
        unset($held[$placedAgentId]);

        return new PlacementCandidate(
            $this->selfNodeId,
            NodeCapacities::fromTags($this->mesh->nodeCapabilities($this->selfNodeId) ?? []),
            $this->heldBy(array_values($held)),
            count($held),
            $this->isLeader,
        );
    }

    /**
     * Sums what a node's live placements hold of each resource, each at the cost placement
     * charges it.
     *
     * @param list<PlacementRecord> $records Live placements on one node
     * @return array<string, float> Held capacity keyed by resource name
     * @throws AgentDaemonCreationFailedException When a live placement's daemon cannot be built to read its cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     * @throws LogicException When an agent declares a negative cost or the reserved slot
     */
    private function heldBy(array $records): array
    {
        $held = [];
        foreach ($records as $record) {
            foreach ($this->placementCost($record->agentType, $record->agentIndex)->costs as $key => $amount) {
                $held[$key] = ($held[$key] ?? 0.0) + $amount;
            }
        }

        return $held;
    }

    /**
     * Returns an agent's cost as placement charges it: what the agent declares plus its one
     * mandatory slot ({@see ResourceProfile::forPlacement()}, HIL-447). Every read of a cost in
     * this coordinator goes through here, so no path can place, rank or count an agent at its
     * bare project cost.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ResourceProfile Effective placement cost
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built to read its cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     * @throws LogicException When the agent declares a negative cost or the reserved slot
     */
    private function placementCost(string $agentType, ?string $agentIndex): ResourceProfile
    {
        return $this->executor->placementProfile($agentType, $agentIndex)->forPlacement();
    }

    /**
     * Node side: stops every agent this node hosts when isolated from the leader it answers to,
     * left without a quorum, or told by the leader it answers to that it leads no more (HIL-1287).
     *
     * Prevents a double-run: an isolated node stops its (possibly truth-source) agents before
     * the leader's failover could start copies elsewhere, and a node in a minority before the
     * majority's leader could (HIL-1217). Reconnect is left to the existing peer dial retry; on
     * rejoin the node re-adopts nothing on its own.
     *
     * The emptied set is then reported to every node still linked, the same complete snapshot a
     * new link carries. Nobody hears it from a node still cut off; it matters to a node whose
     * links came back while the grace ran - a quorum fence is no longer called off by the return
     * of the leader it answered to, and that leader may have adopted the work from this node's
     * report on the new link. Without the snapshot it would keep calling the stopped agents
     * started; with it, it places them again ({@see reconcileMissingAgents()}).
     */
    private function selfFence(): void
    {
        if (!$this->hostsPlacedWork()) {
            return;
        }

        Logger::warning("Self-fence: {$this->selfFenceCause}, stopping {$this->placedCount()} placed agent(s)");
        foreach ($this->hosted as $record) {
            $this->executor->revokePlacement($record->agentType, $record->agentIndex);
        }

        $this->hosted = [];
        $deferredAnswers = $this->deferredPlacementAnswers;
        $this->deferredPlacementAnswers = [];
        $this->placingLeaderId = null;
        foreach ($deferredAnswers as $deferred) {
            $this->executor->revokePlacement($deferred['agentType'], $deferred['agentIndex']);
        }

        $this->mesh->broadcastToNodes(new PeerPlacementReportDTO([]));
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
     * Rejects a placement the target node cannot satisfy: a missing required capability tag, no
     * declared capacity at all, or too little free capacity for the agent's cost.
     *
     * The hard gate both the named-node and best-fit paths pass through, so a placement never
     * launches an agent on an unfit node. The room check is {@see PlacementCandidate} against the
     * same occupancy the policy saw, so a placement by name cannot overfill a node past the
     * accounting. The node need not be online: an undeliverable placement still ends Failed.
     *
     * A refusal is kept as the agent's last refusal before it is thrown (HIL-447), and nothing
     * else: the agent's record, placing, started or refused, is not the attempt's to change.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Target node id
     * @throws PlacementCapabilityException When a required tag is missing, no capacity is declared, or the cost does not fit
     * @throws LogicException When the agent, or one already placed, declares a negative cost or the reserved slot
     * @throws AgentDaemonCreationFailedException When an agent daemon cannot be built to read its requirements or cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    private function requirePlacementFit(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        $candidates = $this->placementCandidates($this->agentId($agentType, $agentIndex), [$nodeId]);
        try {
            $this->requireCandidateFit($candidates[$nodeId], $agentType, $agentIndex);
        } catch (PlacementCapabilityException $e) {
            $this->rememberRefusal($this->failedAttempt($agentType, $agentIndex, $e));
            throw $e;
        }
    }

    /**
     * Rejects a place frame this node cannot satisfy, before anything starts here (HIL-447): the
     * node end of the gate the leader passed before it sent the frame, judged by what this node
     * advertises and runs itself ({@see localCandidate()}).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @throws PlacementCapabilityException When a required tag is missing, no capacity is declared, or the cost does not fit
     * @throws LogicException When the agent, or one this node holds, declares a negative cost or the reserved slot
     * @throws AgentDaemonCreationFailedException When an agent daemon cannot be built to read its requirements or cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    private function requireLocalFit(string $agentType, ?string $agentIndex): void
    {
        $this->requireCandidateFit($this->localCandidate($this->agentId($agentType, $agentIndex)), $agentType, $agentIndex);
    }

    /**
     * The hard gate itself, one body for the leader and the node (HIL-448, HIL-447), checked in
     * order by {@see PlacementCandidate::refusal()}: every required capability tag advertised, some
     * capacity declared at all, then free room for the agent's cost as placement charges it — its
     * mandatory slot included, so a node that declares capacity but no `slots` is short of one.
     * The exception carries the refusal's structure, rebuilt by its factory from the same data.
     *
     * @param PlacementCandidate $candidate Node judged, with what its live placements already hold
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @throws PlacementCapabilityException When a required tag is missing, no capacity is declared, or the cost does not fit
     * @throws LogicException When the agent declares a negative cost or the reserved slot, or the candidate refuses for want of online nodes
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built to read its requirements or cost
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    private function requireCandidateFit(PlacementCandidate $candidate, string $agentType, ?string $agentIndex): void
    {
        $refusal = $candidate->refusal(
            $this->executor->requiredCapabilities($agentType, $agentIndex),
            $this->placementCost($agentType, $agentIndex),
        );
        if ($refusal === null) {
            return;
        }

        $agentId = $this->agentId($agentType, $agentIndex);
        throw match ($refusal->reasonCode) {
            PlacementRefusalCode::MissingCapabilities => PlacementCapabilityException::unmetCapabilities(
                $candidate->nodeId,
                $agentId,
                $refusal->missingCapabilities,
            ),
            PlacementRefusalCode::NoDeclaredCapacity => PlacementCapabilityException::noDeclaredCapacity($candidate->nodeId, $agentId),
            PlacementRefusalCode::InsufficientCapacity => PlacementCapabilityException::insufficientCapacity(
                $candidate->nodeId,
                $agentId,
                $refusal->shortfalls,
            ),
            PlacementRefusalCode::NoOnlineNodes => new LogicException("Node '{$candidate->nodeId}' refused '{$agentId}' as if no node were online"),
        };
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
     * run is the same non-event as one no capable node fits. Either way the nodes that asked are
     * answered with a {@see PeerPlacementVerdictDTO} — immediately when the record is already
     * started or refused, when no node fits, or when the attempt throws; later when a placing
     * record fails, or when a fresh leader's rebuild that held an agent it had no record of
     * settles ({@see settleRebuild()}, HIL-1217).
     *
     * A not-placed verdict carries the refusals behind it (HIL-447): the gate's own when the node
     * tried refused, every online node's — the last refusal the attempt kept — when none fit, none
     * for any other failure. What this node's own sink is told stays the text.
     *
     * @param string $agentType Agent type to place
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    private function placeOnDemand(string $agentType, ?string $agentIndex): void
    {
        $agentId = $this->agentId($agentType, $agentIndex);
        $record = $this->registry->get($agentId);
        if ($record !== null && $record->state === PlacementState::Started) {
            $this->answerPlacementAskers(
                $agentId,
                PeerPlacementVerdictDTO::placed($agentType, $agentIndex, $record->nodeId),
            );

            return;
        }
        if ($record !== null && $record->state === PlacementState::Refused) {
            $this->answerPlacementAskers(
                $agentId,
                PeerPlacementVerdictDTO::notPlaced(
                    $agentType,
                    $agentIndex,
                    PlacementState::Refused,
                    'the agent was refused an RT claim another node already holds',
                ),
            );

            return;
        }
        if ($record !== null && $record->state === PlacementState::Placing) {
            return;
        }
        if ($this->rebuildHolds($agentType, $agentIndex)) {
            // Held, not refused: the agent may still run on a node that has not reported, and a
            // not-placed verdict now would fail the frame that addressed it (HIL-1217).
            $this->heldOnDemand[$agentId] = ['agentType' => $agentType, 'agentIndex' => $agentIndex];

            return;
        }

        try {
            $target = $this->placeAgentOnBestNode($agentType, $agentIndex);
        } catch (Throwable $e) {
            Logger::warning("On-demand placement of '{$agentId}' failed: {$e->getMessage()}");
            $failure = $this->failedAttempt($agentType, $agentIndex, $e);
            $this->rememberRefusal($failure);
            $this->answerPlacementAskers(
                $agentId,
                PeerPlacementVerdictDTO::notPlaced(
                    $agentType,
                    $agentIndex,
                    PlacementState::Failed,
                    $failure->reason,
                    $failure->refusals,
                ),
            );

            return;
        }

        if ($target === null) {
            // The rebuild is settled (checked above), so a null target is a placement no node took,
            // and the attempt has kept why as this agent's last refusal.
            $failure = $this->lastPlacementRefusals[$agentId];
            $this->answerPlacementAskers(
                $agentId,
                PeerPlacementVerdictDTO::notPlaced(
                    $agentType,
                    $agentIndex,
                    PlacementState::Unplaced,
                    $failure->reason,
                    $failure->refusals,
                ),
            );

            return;
        }

        $placed = $this->registry->get($agentId);
        if ($placed?->state === PlacementState::Started) {
            $this->answerPlacementAskers(
                $agentId,
                PeerPlacementVerdictDTO::placed($agentType, $agentIndex, $placed->nodeId),
            );
        }
    }

    /**
     * Remembers a node that is owed a verdict for this agent's placement ask.
     *
     * A repeated ask from the same node does not double the entry: the verdict is one event.
     *
     * @param string $agentId Agent id the ask is for
     * @param string $nodeId Node id owed the verdict
     */
    private function rememberPlacementAsker(string $agentId, string $nodeId): void
    {
        $waiters = $this->placementVerdictWaiters[$agentId] ?? [];
        if (in_array($nodeId, $waiters, true)) {
            return;
        }

        $waiters[] = $nodeId;
        $this->placementVerdictWaiters[$agentId] = $waiters;
    }

    /**
     * Sends the verdict to every node that asked for this agent's placement, then forgets them.
     *
     * A remote waiter gets the frame. This node itself is told through
     * {@see PlacementVerdictSink} on a not-placed verdict; a placed one is already in the
     * registry this leader reads.
     *
     * @param string $agentId Agent id the verdict is for
     * @param PeerPlacementVerdictDTO $verdict Verdict to send
     */
    private function answerPlacementAskers(string $agentId, PeerPlacementVerdictDTO $verdict): void
    {
        $waiters = $this->placementVerdictWaiters[$agentId] ?? [];
        unset($this->placementVerdictWaiters[$agentId]);
        if ($waiters === []) {
            return;
        }

        $reason = $verdict->reason;
        $suffix = $reason !== null && $reason !== '' ? " - {$reason}" : '';
        Logger::info("Answering the placement ask of '{$agentId}': {$verdict->state->value}{$suffix}");

        foreach ($waiters as $nodeId) {
            if ($nodeId === $this->selfNodeId) {
                if ($verdict->state !== PlacementState::Started && $reason !== null) {
                    Hilos::$cluster?->placementVerdictSink()?->onAgentNotPlaced(
                        $verdict->agentType,
                        $verdict->agentIndex,
                        "{$verdict->state->value}: {$reason}",
                    );
                }

                continue;
            }

            $this->mesh->sendToNode($nodeId, $verdict);
        }
    }

    /**
     * Runs a placement on this node synchronously, tracking the outcome in the view.
     *
     * A start that fails is recorded Failed with its text and no refusals, since the gate has
     * already passed (HIL-447); one that starts, or is accepted to wait for its worker, drops the
     * agent's last refusal.
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
            $this->recordFailure($this->selfNodeId, $this->failedAttempt($agentType, $agentIndex, $e));
            throw $e;
        }

        unset($this->lastPlacementRefusals[$record->agentId()]);
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
