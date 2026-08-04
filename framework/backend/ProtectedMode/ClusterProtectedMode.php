<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\Utils\Logger;

/**
 * The two-phase cluster freeze orchestration — the leader side and the follower side in one flat
 * unit, mirroring {@see ClusterPlacement}.
 *
 * Every clustered node builds one; the leader-orchestration slice wires it as the peer transport's
 * {@see ProtectedModeCoordinator} and hands it a {@see ProtectedModeMesh} (outbound peer) and a
 * {@see ProtectedModeExecutor} (local RT write and agent stop). One coordinator serves both roles
 * of a given freeze, and a node is only ever one role at a time:
 *
 * - Initiator side: the initiator's own node calls {@see requestEnable()} / {@see requestDisable()},
 *   which handle the request locally when this node leads or forward it to the current leader over
 *   the peer channel otherwise. The worker→daemon trigger that reaches these entries is its own slice.
 * - Leader side: an initiator's {@see onEnable()} records the freeze, freezes the leader's own
 *   node, broadcasts quiesce to the followers, and tracks whom it still awaits. Each
 *   {@see onQuiesced()} clears one follower; when none remain the leader marks the mode active and
 *   signals the initiator ready. The initiator's {@see onDisable()} deactivates, broadcasts lift,
 *   and releases the leader's own node. The leader role is gated on holding leadership, driven by
 *   {@see onBecameLeader()} / {@see onLostLeadership()}.
 * - Follower side: {@see onQuiesce()} freezes this node and reports quiesced; {@see onLift()}
 *   releases it. The initiator's own node relays the leader's {@see onReady()} to its agent.
 *
 * A single-node cluster has no followers, so the leader activates the moment it enables. An
 * installation with cluster mode off has no coordinator at all and freezes through
 * {@see StandaloneProtectedMode} instead; the three interfaces here mark which half of this class
 * each caller uses - the request path ({@see ProtectedModeSwitch}) is shared with that standalone
 * sibling, the leadership hooks ({@see ProtectedModeLeadership}) and the peer frames
 * ({@see ProtectedModeCoordinator}) exist only in a cluster. Watchdog policy for a stalled
 * initiator or follower (timeouts, escalation) is HIL-266, not here.
 */
final class ClusterProtectedMode implements ProtectedModeCoordinator, ProtectedModeSwitch, ProtectedModeLeadership
{
    /** @var string Id of the node this coordinator runs on, for log context */
    private string $selfNodeId;

    /** @var ProtectedModeMesh Outbound peer port for the freeze frames */
    private ProtectedModeMesh $mesh;

    /** @var ProtectedModeExecutor Local-node port that writes the phase and stops or resumes agents */
    private ProtectedModeExecutor $executor;

    /** @var bool True while this node holds leadership and owns the freeze orchestration */
    private bool $isLeader = false;

    /** @var ?ProtectedModeQuiesceData Freeze the leader is driving, or null when the leader is idle */
    private ?ProtectedModeQuiesceData $activeFreeze = null;

    /** @var array<string, true> Follower node ids the leader still awaits a quiesced report from */
    private array $pendingNodes = [];

    /** @var bool True once every follower has quiesced and the leader has signalled ready */
    private bool $active = false;

    /** @var ?string Node id of the leader that ordered this node's freeze, or null when not frozen */
    private ?string $freezingLeaderId = null;

    /** @var bool True once this node has relayed the current freeze's ready to its initiator agent */
    private bool $readyRelayed = false;

    /**
     * @param string $selfNodeId Id of the node this coordinator runs on
     * @param ProtectedModeMesh $mesh Outbound peer port for the freeze frames
     * @param ProtectedModeExecutor $executor Local-node port that writes the phase and stops agents
     */
    public function __construct(string $selfNodeId, ProtectedModeMesh $mesh, ProtectedModeExecutor $executor)
    {
        $this->selfNodeId = $selfNodeId;
        $this->mesh = $mesh;
        $this->executor = $executor;
    }

    /**
     * Marks this node the leader; it now owns the freeze orchestration.
     *
     * A fresh leader inherits no freeze — a freeze mid-flight across a leader change is watchdog
     * territory (HIL-266), so the leader-side state starts clean.
     */
    public function onBecameLeader(): void
    {
        $this->isLeader = true;
    }

    /**
     * Marks this node no longer the leader and drops any orchestration it was driving.
     *
     * The follower-side state is left untouched: a demoted leader still honours a freeze the new
     * leader ordered against it.
     */
    public function onLostLeadership(): void
    {
        $this->isLeader = false;
        $this->resetLeaderState();
    }

    /**
     * Entry point on the initiator's own node: routes this node's freeze request to the leader.
     *
     * When this node is itself the leader the request is handled locally through {@see onEnable()};
     * otherwise it rides the peer channel to whichever node currently holds leadership. A request
     * raised while no leader is known is dropped — driving a stalled hand-off is watchdog territory
     * (HIL-266), not this path.
     *
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     */
    public function requestEnable(ProtectedModeEnableSignalData $data): void
    {
        if ($this->isLeader) {
            $this->onEnable($this->selfNodeId, $data);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping enable request on '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendEnable($leaderNodeId, $data);
    }

    /**
     * Entry point on the initiator's own node: routes this node's release request to the leader.
     *
     * Mirrors {@see requestEnable()}: handled locally through {@see onDisable()} when this node
     * leads, otherwise sent to the current leader over the peer channel and dropped when no leader
     * is known.
     *
     * @param ProtectedModeDisableSignalData $data Identity of the agent asking for the release,
     *                                             unused here: a cluster authorizes the release by
     *                                             the initiator node id it recorded, which is what
     *                                             the peer frame carries
     */
    public function requestDisable(ProtectedModeDisableSignalData $data): void
    {
        if ($this->isLeader) {
            $this->onDisable($this->selfNodeId);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping disable request on '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendDisable($leaderNodeId);
    }

    /**
     * @param string $fromNodeId Node id of the initiator that sent the request
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     */
    public function onEnable(string $fromNodeId, ProtectedModeEnableSignalData $data): void
    {
        if (!$this->isLeader) {
            Logger::warning("Protected mode: dropping enable from '{$fromNodeId}' — node '{$this->selfNodeId}' is not the leader");
            return;
        }
        // A nameless initiator is a single-node payload that reached a cluster: the leader would
        // have no node to send ready to and no node id to authorize the later disable against,
        // so the freeze is refused instead of entered and never lifted.
        if ($data->initiatorNodeId === null) {
            Logger::warning("Protected mode: dropping enable from '{$fromNodeId}' — the request names no initiator node");
            return;
        }
        if ($this->activeFreeze !== null) {
            Logger::warning("Protected mode: dropping enable from '{$fromNodeId}' — a '{$this->activeFreeze->operation}' freeze is already in flight");
            return;
        }

        $this->activeFreeze = new ProtectedModeQuiesceData(
            $data->operation,
            $data->initiatorAgentType,
            $data->initiatorAgentIndex,
            $data->initiatorNodeId,
        );
        $this->pendingNodes = array_fill_keys($this->mesh->followerMasterNodeIds(), true);
        $this->active = false;

        $this->executor->enterActivating($this->activeFreeze, $data->initiatorAcceptKey);
        $this->mesh->broadcastQuiesce($this->activeFreeze);
        $this->activateWhenAllQuiesced();
    }

    /**
     * @param string $fromNodeId Node id of the follower that quiesced
     */
    public function onQuiesced(string $fromNodeId): void
    {
        if (!$this->isLeader || $this->activeFreeze === null) {
            Logger::warning("Protected mode: dropping quiesced from '{$fromNodeId}' — no freeze is being led here");
            return;
        }

        unset($this->pendingNodes[$fromNodeId]);
        $this->activateWhenAllQuiesced();
    }

    /**
     * @param string $fromNodeId Node id of the initiator that released the freeze
     */
    public function onDisable(string $fromNodeId): void
    {
        if (!$this->isLeader || $this->activeFreeze === null) {
            Logger::warning("Protected mode: dropping disable from '{$fromNodeId}' — no freeze is being led here");
            return;
        }
        if ($fromNodeId !== $this->activeFreeze->initiatorNodeId) {
            Logger::warning("Protected mode: dropping disable from '{$fromNodeId}' — freeze was initiated by '{$this->activeFreeze->initiatorNodeId}'");
            return;
        }

        $this->executor->enterDeactivating();
        $this->mesh->broadcastLift();
        $this->executor->enterInactive();
        $this->resetLeaderState();
    }

    /**
     * Freezes this follower node and reports back, ignoring a repeat while already frozen.
     *
     * A duplicate quiesce is dropped rather than re-run: re-entering {@see ProtectedModeExecutor::enterActivating}
     * re-rolls the stopped-agent set the lift will resume against an already-emptied roster, so the second
     * pass would shrink it and strand agents. Mirrors the in-flight guard on {@see onEnable()}.
     *
     * @param string $fromNodeId Node id of the leader that ordered the freeze
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the freeze protects
     */
    public function onQuiesce(string $fromNodeId, ProtectedModeQuiesceData $data): void
    {
        if ($this->freezingLeaderId !== null) {
            Logger::warning("Protected mode: dropping quiesce from '{$fromNodeId}' — node '{$this->selfNodeId}' is already frozen by '{$this->freezingLeaderId}'");
            return;
        }

        $this->freezingLeaderId = $fromNodeId;
        $this->readyRelayed = false;
        $this->executor->enterActivating($data, null);
        $this->mesh->sendQuiesced($fromNodeId);
    }

    /**
     * Relays the leader's ready to this node's initiator agent, exactly once per freeze.
     *
     * Only the leader that froze this node may confirm it, and only the first confirmation runs:
     * {@see ProtectedModeExecutor::notifyInitiatorReady} lets the initiator start its destructive
     * operation, so a stray or duplicate ready must not re-fire it.
     *
     * @param string $fromNodeId Node id of the leader that confirmed the freeze
     */
    public function onReady(string $fromNodeId): void
    {
        if ($this->freezingLeaderId === null || $fromNodeId !== $this->freezingLeaderId) {
            Logger::warning("Protected mode: dropping ready from '{$fromNodeId}' — node '{$this->selfNodeId}' is not frozen by it");
            return;
        }
        if ($this->readyRelayed) {
            return;
        }

        $this->readyRelayed = true;
        $this->executor->notifyInitiatorReady();
    }

    /**
     * Releases this follower node, but only when the leader that froze it orders the lift.
     *
     * Symmetric with the initiator check on {@see onDisable()}: a stray or stale lift from any other
     * handshaked peer must not thaw the node mid-operation.
     *
     * @param string $fromNodeId Node id of the leader that lifted the freeze
     */
    public function onLift(string $fromNodeId): void
    {
        if ($this->freezingLeaderId === null) {
            Logger::warning("Protected mode: dropping lift from '{$fromNodeId}' — node '{$this->selfNodeId}' is not frozen");
            return;
        }
        if ($fromNodeId !== $this->freezingLeaderId) {
            Logger::warning("Protected mode: dropping lift from '{$fromNodeId}' — freeze was ordered by '{$this->freezingLeaderId}'");
            return;
        }

        $this->executor->enterInactive();
        $this->freezingLeaderId = null;
    }

    /**
     * Marks the freeze active and signals the initiator once no follower is still pending.
     *
     * When the leader is itself the initiator the ready has no peer to travel over — a self send
     * would go nowhere — so it is relayed to the local agent directly; otherwise it rides the peer
     * channel to the initiator's node.
     */
    private function activateWhenAllQuiesced(): void
    {
        if ($this->activeFreeze === null || $this->active || $this->pendingNodes !== []) {
            return;
        }

        $this->active = true;
        $this->executor->enterActive();

        // A nameless initiator never gets past onEnable, so null cannot reach here; relaying
        // locally is nonetheless the safe reading of it, because a ready sent to no node would
        // leave the initiator waiting forever under a freeze nobody can lift.
        $initiatorNodeId = $this->activeFreeze->initiatorNodeId;
        if ($initiatorNodeId === null || $initiatorNodeId === $this->selfNodeId) {
            $this->executor->notifyInitiatorReady();
            return;
        }

        $this->mesh->sendReady($initiatorNodeId);
    }

    /**
     * Clears the leader-side orchestration back to idle.
     */
    private function resetLeaderState(): void
    {
        $this->activeFreeze = null;
        $this->pendingNodes = [];
        $this->active = false;
    }
}
