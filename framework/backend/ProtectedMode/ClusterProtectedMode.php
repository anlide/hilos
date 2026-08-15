<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
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
 *
 * Entering is fail-closed on both sides, the same way {@see StandaloneProtectedMode} enters: a node
 * whose process carries no runtime state refuses the freeze loudly - the leader before it records
 * anything, a follower before it reports quiesced - instead of standing inert. The initiator waits
 * for ready before it destroys anything, so a refusal leaves it waiting safely, while a node that
 * confirmed a freeze it never entered would run the operation over live clients.
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
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
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

        // This node's own initiator is asking, so the ready that comes back answers this request
        // rather than repeating an old one. Re-arming matters when the freeze already stands: the
        // leader sends no second quiesce then, and that is the other place the guard is cleared.
        $this->readyRelayed = false;

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
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
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
     * Entry point on the initiator's own node: routes the request for the verification window.
     *
     * Mirrors {@see requestDisable()} in routing and in authorization; the phase check that makes
     * it fail-closed lives on the leader ({@see onVerify()}), where the freeze being driven is.
     *
     * @param ProtectedModeVerifySignalData $data Identity of the agent asking for the window,
     *                                            unused here for the same reason the disable
     *                                            payload is: a cluster authorizes by node id
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestVerify(ProtectedModeVerifySignalData $data): void
    {
        if ($this->isLeader) {
            $this->onVerify($this->selfNodeId);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping verify request on '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendVerify($leaderNodeId);
    }

    /**
     * Entry point on the initiator's own node: routes one minted pass to the leader.
     *
     * @param ProtectedModePassSignalData $data Minting agent identity and the hash of the pass
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function requestPass(ProtectedModePassSignalData $data): void
    {
        if ($this->isLeader) {
            $this->onPass($this->selfNodeId, $data->passHash);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping pass request on '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendPass($leaderNodeId, $data->passHash);
    }

    /**
     * Entry point on the initiator's own node: routes the request to close back out of the window.
     *
     * @param ProtectedModeRefreezeSignalData $data Identity of the agent asking to close back,
     *                                              unused here: a cluster authorizes by node id
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestRefreeze(ProtectedModeRefreezeSignalData $data): void
    {
        if ($this->isLeader) {
            $this->onRefreeze($this->selfNodeId);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping refreeze request on '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendRefreeze($leaderNodeId);
    }

    /**
     * @param string $fromNodeId Node id of the initiator that sent the request
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
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
            $this->answerFreezeAlreadyLed($fromNodeId, $this->activeFreeze, $data);
            return;
        }
        // Last of the entry checks, and deliberately above every trace of entry: a leader that
        // recorded the freeze before refusing would stay half-frozen forever, dropping each later
        // attempt as already in flight, and a disable cannot clear that - it needs a live initiator.
        if ($this->runtimeView() === null) {
            Logger::error(
                "Protected mode: cannot enter for '{$data->operation}' requested by agent "
                . "'{$data->initiatorAgentType}' — node '{$this->selfNodeId}' holds no protected mode runtime state"
            );
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
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
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
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onDisable(string $fromNodeId): void
    {
        if (!$this->isLeader || $this->activeFreeze === null) {
            Logger::warning("Protected mode: dropping disable from '{$fromNodeId}' — no freeze is being led here");
            return;
        }
        if ($fromNodeId !== $this->activeFreeze->initiatorNodeId) {
            Logger::warning("Protected mode: dropping disable from '{$fromNodeId}'"
                . " — freeze was initiated by '{$this->activeFreeze->initiatorNodeId}'");
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
     * A node that holds no runtime state refuses the quiesce and answers nothing, mirroring the
     * leader's entry guard: silence keeps the leader in activating, which is the safe half of the
     * trade, while a quiesced report would unfreeze the whole operation's premise.
     *
     * @param string $fromNodeId Node id of the leader that ordered the freeze
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the freeze protects
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onQuiesce(string $fromNodeId, ProtectedModeQuiesceData $data): void
    {
        if ($this->freezingLeaderId !== null) {
            Logger::warning("Protected mode: dropping quiesce from '{$fromNodeId}'"
                . " — node '{$this->selfNodeId}' is already frozen by '{$this->freezingLeaderId}'");
            return;
        }
        // The leader's guard, from the follower side, and the refusal stays off the wire: reporting
        // quiesced for a freeze this node never entered would let the leader hand ready to the
        // initiator and run the destructive operation across a node still serving its clients.
        if ($this->runtimeView() === null) {
            Logger::error(
                "Protected mode: cannot freeze for '{$data->operation}' ordered by '{$fromNodeId}' — "
                . "node '{$this->selfNodeId}' holds no protected mode runtime state"
            );
            return;
        }

        $this->freezingLeaderId = $fromNodeId;
        $this->readyRelayed = false;
        $this->executor->enterActivating($data, null);
        $this->mesh->sendQuiesced($fromNodeId);
    }

    /**
     * Relays the leader's ready to this node's initiator agent, exactly once per request.
     *
     * Only the leader that froze this node may confirm it, and only the first confirmation runs:
     * {@see ProtectedModeExecutor::notifyInitiatorReady} lets the initiator start its destructive
     * operation, so a stray or duplicate ready must not re-fire it. What re-arms the guard is this
     * node's own initiator asking again ({@see requestEnable()}) or a fresh freeze being ordered
     * against this node ({@see onQuiesce()}) - the two moments a ready is owed.
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
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
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
     * @param string $fromNodeId Node id the frame came from
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onVerify(string $fromNodeId): void
    {
        if ($this->frozenByThisLeader($fromNodeId)) {
            // Follower half: the leader has already decided, so what is left to refuse is a repeat
            // - reapplying the window would re-roll the stopped-agent roster the lift resumes.
            if ($this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
                Logger::warning("Protected mode: dropping verify from '{$fromNodeId}' — node '{$this->selfNodeId}' is already verifying");
                return;
            }

            $this->executor->enterVerifying();
            return;
        }

        if (!$this->leadsFreezeFor($fromNodeId, 'verify')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_ACTIVE)) {
            Logger::warning("Protected mode: dropping verify from '{$fromNodeId}' — the freeze has not reached active");
            return;
        }

        $this->executor->enterVerifying();
        $this->mesh->broadcastVerify();
    }

    /**
     * @param string $fromNodeId Node id the frame came from
     * @param string $passHash SHA-256 of the minted pass
     */
    public function onPass(string $fromNodeId, string $passHash): void
    {
        if ($this->frozenByThisLeader($fromNodeId)) {
            if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
                Logger::warning("Protected mode: dropping pass from '{$fromNodeId}' — node '{$this->selfNodeId}' is not verifying");
                return;
            }

            $this->runtimeView()?->actions->issuePass($passHash);
            return;
        }

        if (!$this->leadsFreezeFor($fromNodeId, 'pass')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
            Logger::warning("Protected mode: dropping pass from '{$fromNodeId}' — the mode is not verifying");
            return;
        }

        $this->runtimeView()?->actions->issuePass($passHash);
        $this->mesh->broadcastPass($passHash);
    }

    /**
     * @param string $fromNodeId Node id the frame came from
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onRefreeze(string $fromNodeId): void
    {
        if ($this->frozenByThisLeader($fromNodeId)) {
            if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
                Logger::warning("Protected mode: dropping refreeze from '{$fromNodeId}' — node '{$this->selfNodeId}' is not verifying");
                return;
            }

            $this->executor->reenterActive();
            return;
        }

        if (!$this->leadsFreezeFor($fromNodeId, 'refreeze')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
            Logger::warning("Protected mode: dropping refreeze from '{$fromNodeId}' — the mode is not verifying");
            return;
        }

        $this->executor->reenterActive();
        $this->mesh->broadcastRefreeze();
    }

    /**
     * Answers an enable raised against the freeze this leader is already driving.
     *
     * The leader half of {@see StandaloneProtectedMode::answerFreezeAlreadyHeld()}, and it exists
     * for the same operator: closing the verification window leaves the whole cluster frozen on
     * active so another attempt can run, and that attempt's enable would otherwise be refused as a
     * freeze in flight, leaving its initiator waiting for a ready nobody was going to send. The
     * quiesce round is not replayed - the followers are still frozen, and re-ordering it would
     * re-roll the stopped-agent roster each of them resumes against - so what the initiator gets
     * is the ready the settled freeze already earns it.
     *
     * Authorized by initiator node id AND by the agent identity the freeze records, which is one
     * check more than this class asks anywhere else. The node id keeps a second node from freezing
     * a cluster already frozen for somebody else, the case the refusal was written for. The agent
     * identity is what the node id cannot cover here: a project holds two initiators - the one that
     * runs real operations and the test driver's carrier - and on a cluster they sit on the same
     * node, so a node-id match alone would answer ready under a freeze the asking agent does not
     * own. The ready would not even reach it: {@see ProtectedModeExecutor::notifyInitiatorReady()}
     * delivers to the identity written on the row, so the agent that asked would wait out its
     * timeout while the other one was told a second time that it may start destroying things.
     *
     * @param string $fromNodeId Node id of the initiator that sent the request
     * @param ProtectedModeQuiesceData $freeze Freeze this leader is driving
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the enable names
     */
    private function answerFreezeAlreadyLed(
        string $fromNodeId,
        ProtectedModeQuiesceData $freeze,
        ProtectedModeEnableSignalData $data,
    ): void {
        if (
            !$this->active
            || $data->initiatorNodeId !== $freeze->initiatorNodeId
            || $data->initiatorAgentType !== $freeze->initiatorAgentType
            || $data->initiatorAgentIndex !== $freeze->initiatorAgentIndex
            || !$this->phaseIs(StateProtectedModeRuntime::PHASE_ACTIVE)
        ) {
            Logger::warning("Protected mode: dropping enable from '{$fromNodeId}'"
                . " — a '{$freeze->operation}' freeze is already in flight");
            return;
        }

        $this->signalInitiatorReady($freeze);
    }

    /**
     * Whether this node is a follower frozen by the peer that sent the frame.
     *
     * The three verification frames travel in both directions under one name, so the receiving
     * node decides which half it is playing from what it already knows about itself. Being frozen
     * by the sender is checked first and settles it: a leader never records a freezing leader for
     * itself, so the two halves cannot both match.
     *
     * @param string $fromNodeId Node id the frame came from
     * @return bool Whether this node should apply the frame as a follower
     */
    private function frozenByThisLeader(string $fromNodeId): bool
    {
        return $this->freezingLeaderId !== null && $fromNodeId === $this->freezingLeaderId;
    }

    /**
     * Whether this node leads the freeze the sending node initiated.
     *
     * The authorization the leader half of every verification frame shares with
     * {@see onDisable()}: only the node that asked for the freeze may drive what happens to it.
     *
     * @param string $fromNodeId Node id the frame came from
     * @param string $frame Frame name for the refusal log line
     * @return bool Whether this node may drive the freeze on the sender's behalf
     */
    private function leadsFreezeFor(string $fromNodeId, string $frame): bool
    {
        if (!$this->isLeader || $this->activeFreeze === null) {
            Logger::warning("Protected mode: dropping {$frame} from '{$fromNodeId}' — no freeze is being led here");
            return false;
        }
        if ($fromNodeId !== $this->activeFreeze->initiatorNodeId) {
            Logger::warning("Protected mode: dropping {$frame} from '{$fromNodeId}'"
                . " — freeze was initiated by '{$this->activeFreeze->initiatorNodeId}'");
            return false;
        }

        return true;
    }

    /**
     * Whether this node's freeze row stands on a given phase.
     *
     * @param string $expected Phase to test for
     * @return bool Whether the row reads that phase right now
     */
    private function phaseIs(string $expected): bool
    {
        return $this->runtimeView()?->phase === $expected;
    }

    /**
     * Marks the freeze active and signals the initiator once no follower is still pending.
     */
    private function activateWhenAllQuiesced(): void
    {
        if ($this->activeFreeze === null || $this->active || $this->pendingNodes !== []) {
            return;
        }

        $this->active = true;
        $this->executor->enterActive();
        $this->signalInitiatorReady($this->activeFreeze);
    }

    /**
     * Tells the node that asked for the freeze that the cluster has quiesced and it may run.
     *
     * When the leader is itself the initiator the ready has no peer to travel over — a self send
     * would go nowhere — so it is relayed to the local agent directly; otherwise it rides the peer
     * channel to the initiator's node.
     *
     * @param ProtectedModeQuiesceData $freeze Freeze whose initiator is being signalled
     */
    private function signalInitiatorReady(ProtectedModeQuiesceData $freeze): void
    {
        // A nameless initiator never gets past onEnable, so null cannot reach here; relaying
        // locally is nonetheless the safe reading of it, because a ready sent to no node would
        // leave the initiator waiting forever under a freeze nobody can lift.
        $initiatorNodeId = $freeze->initiatorNodeId;
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

    /**
     * Resolves the protected-mode runtime singleton, or null when this process holds no runtime state.
     *
     * Mirrors {@see StandaloneProtectedMode::runtimeView()} instead of sharing a helper with it: the
     * two read the same row to gate different entries - a single-node freeze that completes in one
     * tick against a leader round that commits followers - so what matches is the value, not the
     * context. Null is never a project opting out: the framework mounts this row for every project
     * that has an RT context at all.
     *
     * @return ?ProtectedModeRuntime Runtime singleton view, or null when runtime state is unavailable
     */
    private function runtimeView(): ?ProtectedModeRuntime
    {
        return Hilos::$rt?->hilosProtectedModeRuntime;
    }
}
