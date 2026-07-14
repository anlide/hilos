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
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Constants\AgentConstants;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Utils\Logger;

/**
 * The mechanism to launch and track an agent-of-type-X on a named node over the peer
 * channel — the leader placement side and the node execution side in one flat unit.
 *
 * Every clustered node builds one (the peer transport does so at start, wiring the
 * transport as its {@see PlacementMesh} and the worker server as its
 * {@see PlacementExecutor}). Two sides share it:
 *
 * - Leader side: {@see placeAgentOnNode()} / {@see stopAgentOnNode()} are the permanent
 *   remote-placement primitive. A placement routed at `self` runs the local start path;
 *   any other node id sends a {@see PeerPlaceAgentDTO} over the mesh. Every placement is
 *   hard-checked against the target node's advertised capabilities first (a binary gate;
 *   the soft-preference selection among many nodes is HIL-182). Outcomes are tracked in
 *   the soft-state {@see PlacementRegistry}, which a fresh leader rebuilds from node
 *   reports on {@see onBecameLeader()}.
 * - Node side: an inbound place/stop frame runs the local execute/revoke and replies with
 *   a {@see PeerAgentStatusDTO}; a rebuild query is answered from the node's own hosted
 *   set. This is what a data-plane slave does with the placements a leader hands it.
 *
 * There is no automatic node-choosing here — that is HIL-182's policy layered on top of
 * this primitive. It also serves as the read side of {@see WorkerPlacement}: the signal
 * router asks {@see nodeFor()} where a placed agent lives so HIL-180 can forward work
 * signals cross-node. Crash-failover of a placed agent is HIL-183.
 */
final class ClusterPlacement implements WorkerPlacement
{
    /** @var string Id of the node this coordinator runs on */
    private string $selfNodeId;

    /** @var PlacementMesh Outbound port to reach nodes and read their advertised capabilities */
    private PlacementMesh $mesh;

    /** @var PlacementExecutor Local port to launch, stop, and describe agents on this node */
    private PlacementExecutor $executor;

    /** @var PlacementRegistry Leader-side soft-state view of every placement, cluster-wide */
    private PlacementRegistry $registry;

    /** @var array<string, PlacementRecord> Agents this node currently hosts, keyed by agent id */
    private array $hosted = [];

    /**
     * @param string $selfNodeId Id of the node this coordinator runs on
     * @param PlacementMesh $mesh Outbound port to reach nodes and read capabilities
     * @param PlacementExecutor $executor Local port to launch and stop agents on this node
     */
    public function __construct(string $selfNodeId, PlacementMesh $mesh, PlacementExecutor $executor)
    {
        $this->selfNodeId = $selfNodeId;
        $this->mesh = $mesh;
        $this->executor = $executor;
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
     * Reads the leader-side placement view: an agent placed on another node returns that
     * node's id; one placed on this node, or absent from the view, returns null so the
     * router keeps delivering it locally. Because the view is leader-owned soft-state, a
     * non-leader answers null for everything — cluster-wide placement knowledge on every
     * node is a later slice; this gives the leader a working forward path today.
     *
     * @param string $agentType Agent type to look up
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ?string Hosting node id when remote, or null for local / unknown
     */
    public function nodeFor(string $agentType, ?string $agentIndex): ?string
    {
        $record = $this->registry->get($this->agentId($agentType, $agentIndex));
        if ($record === null || $record->nodeId === $this->selfNodeId) {
            return null;
        }

        return $record->nodeId;
    }

    /**
     * Places an agent of the given type on a named node: the permanent remote-placement
     * primitive.
     *
     * Hard-checks the target node's advertised capabilities against the agent's required
     * set first and rejects before anything is sent when they are not all present. A
     * placement at this node runs the local start path synchronously; any other node id
     * sends a place frame and records the placement as pending until the node's status
     * reply lands. Routing which node an agent belongs on is HIL-182; this is the
     * delivery primitive it drives.
     *
     * @param string $agentType Agent type to launch
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Id of the node to place the agent on
     * @throws PlacementCapabilityException When the node lacks a required capability
     * @throws AgentDaemonCreationFailedException When a local placement's daemon cannot be built
     * @throws NoSuitableWorkerException When a local placement has no worker to host it
     * @throws AgentNotLinkedToWorkerException When a local placement did not link to a worker
     */
    public function placeAgentOnNode(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        $this->requireCapabilities($agentType, $agentIndex, $nodeId);

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
        } catch (\Throwable $e) {
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
        $entries = array_map(
            static fn(PlacementRecord $record): PeerPlacedAgentEntry => new PeerPlacedAgentEntry(
                $record->agentType,
                $record->agentIndex,
            ),
            array_values($this->hosted),
        );

        $this->mesh->sendToNode($fromNodeId, new PeerPlacementReportDTO($entries));
    }

    /**
     * Leader side: folds a node's hosted-agent report into the placement view.
     *
     * Each reported agent is recorded as started on the reporting node; this is how a
     * fresh leader rebuilds its view from the mesh after {@see onBecameLeader()}.
     *
     * @param string $fromNodeId Id of the node that reported
     * @param PeerPlacementReportDTO $frame Received placement report
     */
    public function onPlacementReport(string $fromNodeId, PeerPlacementReportDTO $frame): void
    {
        foreach ($frame->agents as $entry) {
            $this->registry->put(new PlacementRecord($entry->agentType, $entry->agentIndex, $fromNodeId, PlacementState::Started));
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
        $this->registry->clear();
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
     * next leader rebuilds from the mesh.
     */
    public function onLostLeadership(): void
    {
        $this->registry->clear();
    }

    /**
     * Rejects a placement whose required capabilities the target node does not advertise.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Target node id
     * @throws PlacementCapabilityException When a required capability is not advertised
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be built to read its requirements
     */
    private function requireCapabilities(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        $required = $this->executor->requiredCapabilities($agentType, $agentIndex);
        $advertised = $this->mesh->nodeCapabilities($nodeId) ?? [];
        $missing = array_values(array_diff($required, $advertised));

        if ($missing !== []) {
            throw PlacementCapabilityException::unmetCapabilities($nodeId, $this->agentId($agentType, $agentIndex), $missing);
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
