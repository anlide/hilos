<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\ProtectedMode\ProtectedModeRefusalCopy;
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
 *   node, broadcasts quiesce to the followers, and tracks whom it still awaits - itself included,
 *   until its own roster has stopped. Each {@see onQuiesced()} clears one follower; when none remain
 *   the leader marks the mode active, tells every follower the freeze has settled, and signals the
 *   initiator ready. A repeat enable from the verification window runs that round again
 *   (HIL-1057), and so does the close back from it (HIL-1128) - which owes nobody a ready. The
 *   initiator's {@see onDisable()} deactivates, broadcasts lift, and releases the leader's own
 *   node. The leader role is gated on holding leadership, driven by {@see onBecameLeader()} /
 *   {@see onLostLeadership()}. The leader authorizes initiator frames by agent type and index,
 *   while their link node id supplies log context. Ready finds the agent's current placement.
 * - Follower side: {@see onQuiesce()} freezes this node and, once its roster has stopped
 *   ({@see onRosterStopped()}), reports quiesced; {@see onSettled()} writes active once the leader
 *   says every node has stopped, and {@see onLift()} releases it. A frozen follower, former leader,
 *   or node restored from a freeze file follows the current leader when that leader sends a frame.
 *   The initiator's own node relays the leader's {@see onReady()} to its agent. The verifier circle
 *   photographed at the freeze is
 *   written on a follower's row from the leader's frame ({@see onCircle()}), as a pass is.
 *   A code admission follows that pass from its 101 master through the leader to every follower
 *   ({@see onAdmit()}), so each master's row gives the same browser the same verdict (HIL-1305).
 *
 * A single-node cluster has no followers, so the leader activates the moment its own roster has
 * stopped. An installation with cluster mode off has no coordinator at all and freezes through
 * {@see StandaloneProtectedMode} instead; the three interfaces here mark which half of this class
 * each caller uses - the request path ({@see ProtectedModeSwitch}) is shared with that standalone
 * sibling, the leadership hooks ({@see ProtectedModeLeadership}) and the peer frames
 * ({@see ProtectedModeCoordinator}) exist only in a cluster. A freeze that stops moving - a round
 * that never closes, an initiator that goes quiet or disappears - is not judged here: the leader's
 * master watches for that and tells a person ({@see ProtectedModeWatchdog}), and no code path in
 * this class ever lifts a freeze on a timeout.
 *
 * Entering is fail-closed on both sides, the same way {@see StandaloneProtectedMode} enters: a node
 * whose process carries no runtime state refuses the freeze loudly - the leader before it records
 * anything, a follower before it reports quiesced - instead of standing inert. The initiator waits
 * for ready before it destroys anything, so a refusal leaves it waiting safely, while a node that
 * confirmed a freeze it never entered would run the operation over live clients.
 */
final class ClusterProtectedMode implements
    ProtectedModeCoordinator,
    ProtectedModeSwitch,
    ProtectedModeLeadership,
    ProtectedModeQuiesceRoster
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

    /**
     * @var array<string, true> Node ids the leader still awaits a quiesced report from - the followers,
     *     and the leader itself until its own roster has stopped ({@see onRosterStopped()})
     */
    private array $pendingNodes = [];

    /** @var bool True once every follower has quiesced and the leader has signalled ready */
    private bool $active = false;

    /**
     * @var bool Whether the round in flight owes the initiator a ready - an entry does, the close
     *     back from the window does not (HIL-1128); the round's end tells the followers first
     *     ({@see onSettled()}) and the initiator only when owed
     */
    private bool $readyOwed = false;

    /** @var ?string Node id of the leader that ordered this node's freeze, or null when not frozen */
    private ?string $freezingLeaderId = null;

    /** @var bool True once this node has relayed the current freeze's ready to its initiator agent */
    private bool $readyRelayed = false;

    /** @var ?string Initiator agent type that requested enable from this node, cleared on verdict */
    private ?string $pendingInitiatorAgentType = null;

    /** @var ?int Initiator agent index that requested enable from this node, cleared on verdict */
    private ?int $pendingInitiatorAgentIndex = null;

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
     * Marks this node the leader; it now owns the freeze orchestration, including one it inherits.
     *
     * A promotion in the middle of a freeze rebuilds the leader-side state from the freeze row this
     * node already carries, and that is not a refinement: leader-side state kept in memory dies with
     * the leader, and a blank successor drops {@see onDisable()} from a live and healthy initiator
     * ({@see leadsFreezeFor()} on `activeFreeze === null`) - so nothing at all could unfreeze the
     * cluster. With the watchdog never lifting a freeze by itself (HIL-482), this path is not an
     * extra: it is the only way out.
     *
     * What the successor cannot inherit is who had already quiesced - that list lived in the dead
     * leader's memory - so an unfinished round starts over with every follower outstanding. It will
     * usually not close: a follower already frozen drops the repeat instead of reporting again. That
     * is the honest end of it, and it is reported rather than papered over - the watchdog names the
     * round overdue and the operator ends the freeze by the ladder. The deadline it is measured
     * against starts at this promotion, because {@see ProtectedModeWatchdog} counts every threshold
     * from the moment it first saw the freeze, never from the clocks on the row.
     */
    public function onBecameLeader(): void
    {
        $this->isLeader = true;
        $this->adoptStandingFreeze();
    }

    /**
     * Names the nodes this leader is still waiting on for the freeze it is driving.
     *
     * The watchdog's one question that the freeze row cannot answer: a round that never closed is
     * only actionable once the operator knows which node to go and look at. A node that leads
     * nothing answers empty, which reads correctly as "nobody is outstanding here".
     *
     * @return list<string> Node ids that have not reported quiesced, empty when none are
     */
    public function pendingNodeIds(): array
    {
        if (!$this->isLeader) {
            return [];
        }

        return array_keys($this->pendingNodes);
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
     * raised while no leader is known is refused back to the initiator with that reason (HIL-909):
     * this path does not queue or retry, and a node that ends up frozen with nobody driving it is
     * reported by {@see ProtectedModeWatchdog}.
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
            Hilos::$cluster?->protectedModeInitiatorRelay()?->deliverProtectedModeRefused(
                $data->initiatorAgentType,
                $data->initiatorAgentIndex === null ? null : (string)$data->initiatorAgentIndex,
                ProtectedModeRefusalCopy::NO_LEADER,
            );
            return;
        }

        // This node's own initiator is asking, so the ready that comes back answers this request
        // rather than repeating an old one. Re-arming matters when the freeze already stands: the
        // leader sends no second quiesce then, and that is the other place the guard is cleared.
        $this->readyRelayed = false;
        $this->pendingInitiatorAgentType = $data->initiatorAgentType;
        $this->pendingInitiatorAgentIndex = $data->initiatorAgentIndex;

        $this->mesh->sendEnable($leaderNodeId, $data);
    }

    /**
     * Entry point on the initiator's own node: routes this node's release request to the leader.
     *
     * Mirrors {@see requestEnable()}: handled locally through {@see onDisable()} when this node
     * leads, otherwise sent to the current leader over the peer channel and dropped when no leader
     * is known.
     *
     * @param ProtectedModeDisableSignalData $data Identity of the agent asking for the release
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestDisable(ProtectedModeDisableSignalData $data): void
    {
        if ($this->isLeader) {
            $this->onDisable($this->selfNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping disable request on '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendDisable($leaderNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex);
    }

    /**
     * Entry point on the initiator's own node: routes the request for the verification window.
     *
     * Mirrors {@see requestDisable()} in routing and in authorization; the phase check that makes
     * it fail-closed lives on the leader ({@see onVerify()}), where the freeze being driven is.
     *
     * @param ProtectedModeVerifySignalData $data Identity of the agent asking for the window
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestVerify(ProtectedModeVerifySignalData $data): void
    {
        if ($this->isLeader) {
            $this->onVerify($this->selfNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping verify request on '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendVerify($leaderNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex);
    }

    /**
     * Entry point on the initiator's own node: routes a progress mark to the leader.
     *
     * The mark belongs on the leader's row and nowhere else, because the leader is the only node
     * that runs the watchdog reading it - so unlike the pass, which every node needs in order to
     * admit a verifier that lands on it, this frame is never fanned out to the followers.
     *
     * A mark raised while no leader is known is dropped silently, where its siblings log: this
     * frame repeats for every line the operation prints, and a warning apiece would drown the
     * channel to report a condition the watchdog states better anyway - a freeze that nobody
     * leads is a freeze whose row stops being refreshed, which is precisely what gets reported.
     *
     * @param ProtectedModeProgressSignalData $data Identity of the agent reporting progress
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestProgress(ProtectedModeProgressSignalData $data): void
    {
        if ($this->isLeader) {
            $this->onProgress($this->selfNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            return;
        }

        $this->mesh->sendProgress($leaderNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex);
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
            $this->onPass($this->selfNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex, $data->passHash);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping pass request on '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendPass($leaderNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex, $data->passHash);
    }

    /**
     * Records on the 101 master first, then sends the earned admission through the leader.
     *
     * @param string $passHash Hash of the pass accepted at 101
     * @param string $sessionTokenHash Hash of the verifier session
     * @throws EnvException When leader lookup is unavailable
     */
    public function requestAdmit(string $passHash, string $sessionTokenHash): void
    {
        if ($this->isLeader) {
            $this->onAdmit($this->selfNodeId, $passHash, $sessionTokenHash);
            return;
        }

        $this->executor->admitVerifier($sessionTokenHash);
        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: the admission of a verifier stays on node '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendAdmit($leaderNodeId, $passHash, $sessionTokenHash);
    }

    /**
     * Entry point on the initiator's own node: routes the photographed circle to every master.
     *
     * The photograph is fanned the way a pass is - this node to the leader, the leader to every
     * follower master - and for the reason the pass is: a member's tab connects to whichever node
     * the balancer hands it, and each node decides admission against its own copy of the row. The
     * leader takes the photograph through the same door a frame from another node does
     * ({@see onCircle()}). A follower writes its own row first and then sends, so the node the
     * initiator sits on does not wait on a round trip to recognize a member already attached to it;
     * with no leader known that row is all there is, and the rest of the cluster lets the circle in
     * by code alone.
     *
     * A node holding no freeze of its own still sends: that is a slave, which no freeze frame reaches
     * at all - they go to masters only - so there is no row of its own to write, while the leader
     * authorizes the photograph by the agent identity it carries, exactly as it authorizes every
     * other frame of the window. With no leader known as well, the photograph is dropped.
     *
     * The phase is deliberately not checked, unlike the pass - a follower reaches `active` only on
     * its leader's settled frame ({@see onSettled()}, HIL-1128), and gating on `active` would make
     * the circle hostage to one more frame on exactly the topology that has one.
     *
     * @param ProtectedModeCircleSignalData $data Initiator identity and the circle photographed for it
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestCircle(ProtectedModeCircleSignalData $data): void
    {
        $snapshot = new VerifierCircleSnapshot($data->namedCount, $data->sessionTokenHashes);
        if ($this->isLeader) {
            $this->onCircle($this->selfNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex, $snapshot);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($this->freezingLeaderId !== null) {
            $this->runtimeView()?->actions->admitCircle($snapshot);
            if ($leaderNodeId === null) {
                Logger::warning("Protected mode: the circle from agent '{$data->initiatorAgentType}'"
                    . " stays on node '{$this->selfNodeId}' — no leader is known");
                return;
            }

            $this->mesh->sendCircle($leaderNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex, $snapshot);
            return;
        }

        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping circle from agent '{$data->initiatorAgentType}'"
                . " — node '{$this->selfNodeId}' holds no freeze and no leader is known");
            return;
        }

        $this->mesh->sendCircle($leaderNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex, $snapshot);
    }

    /**
     * Entry point on the initiator's own node: routes the request to close back out of the window.
     *
     * @param ProtectedModeRefreezeSignalData $data Identity of the agent asking to close back
     * @throws EnvException When the cluster-enabled flag value is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestRefreeze(ProtectedModeRefreezeSignalData $data): void
    {
        if ($this->isLeader) {
            $this->onRefreeze($this->selfNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex);
            return;
        }

        $leaderNodeId = $this->mesh->leaderNodeId();
        if ($leaderNodeId === null) {
            Logger::warning("Protected mode: dropping refreeze request on '{$this->selfNodeId}' — no leader is known");
            return;
        }

        $this->mesh->sendRefreeze($leaderNodeId, $data->initiatorAgentType, $data->initiatorAgentIndex);
    }

    /**
     * @param string $fromNodeId Node id of the initiator that sent the request
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     * @throws EnvException When the cluster-enabled flag value is invalid during initiator placement lookup
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onEnable(string $fromNodeId, ProtectedModeEnableSignalData $data): void
    {
        if (!$this->isLeader) {
            Logger::warning("Protected mode: dropping enable from '{$fromNodeId}' — node '{$this->selfNodeId}' is not the leader");
            $this->signalInitiatorRefused($fromNodeId, $data, ProtectedModeRefusalCopy::NO_LEADER);
            return;
        }
        // A clustered enable needs an entry node for the row's history.
        if ($data->initiatorNodeId === null) {
            Logger::warning("Protected mode: dropping enable from '{$fromNodeId}' — the request names no initiator node");
            $this->signalInitiatorRefused($fromNodeId, $data, ProtectedModeRefusalCopy::ANOTHER_OPERATION);
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
            $this->signalInitiatorRefused($fromNodeId, $data, ProtectedModeRefusalCopy::NO_RUNTIME_ROW);
            return;
        }

        $this->activeFreeze = new ProtectedModeQuiesceData(
            $data->operation,
            $data->initiatorAgentType,
            $data->initiatorAgentIndex,
            $data->initiatorNodeId,
            $data->initiatorSessionTokenHash,
        );
        $this->readyOwed = true;
        $this->enterRound($this->activeFreeze, $data->initiatorAcceptKey);
    }

    /**
     * @param string $fromNodeId Node id of the follower that quiesced
     * @throws EnvException When the cluster-enabled flag value is invalid during initiator placement lookup
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
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onDisable(string $fromNodeId, string $agentType, ?int $agentIndex): void
    {
        if (!$this->leadsFreezeFor($fromNodeId, $agentType, $agentIndex, 'disable')) {
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
     * The exception is a quiesce from the leader this node follows while the row is still verifying
     * (HIL-1057): the window has returned the roster, so a second stop is safe, and that is how a
     * repeat entry from the window - and the close back from it (HIL-1128) - reaches a follower,
     * which does not tell the two apart. On activating or active the same frame is still dropped,
     * because those phases hold a standing or unfinished stop list.
     *
     * A node that holds no runtime state refuses the quiesce and answers nothing, mirroring the
     * leader's entry guard: silence keeps the leader in activating, which is the safe half of the
     * trade, while a quiesced report would unfreeze the whole operation's premise.
     *
     * @param string $fromNodeId Node id of the leader that ordered the freeze
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the freeze protects
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onQuiesce(string $fromNodeId, ProtectedModeQuiesceData $data): void
    {
        if ($this->underFreeze()) {
            $acceptedLeader = $this->obeysLeader($fromNodeId);
            if ($acceptedLeader) {
                $this->followLeader($fromNodeId);
            }
            if (!$acceptedLeader || !$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
                Logger::warning("Protected mode: dropping quiesce from '{$fromNodeId}'"
                    . " — node '{$this->selfNodeId}' is already frozen by '" . ($this->freezingLeaderId ?? 'nobody') . "'");
                return;
            }
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
        // The descriptor carries the operator's session hash to this master, where its next tab
        // may connect (HIL-1305). The accept key remains on the node of its socket. The quiesced
        // report waits for this roster to stop and leaves from onRosterStopped().
        $this->executor->enterActivating($data, null);
    }

    /**
     * Says this node is frozen, to whoever is owed it, now that its roster has stopped.
     *
     * The leader counts itself quiesced and activates if no follower is outstanding; a follower
     * reports quiesced to the leader that froze it. A node is only ever one of the two for a given
     * freeze, so what the class already holds decides which. Every walk that enters a freeze sits
     * on activating and is reported - the first entry, a repeat from the verification window
     * (HIL-1057) and the close back from it (HIL-1128). The close owes the initiator no ready, and
     * the leader remembers that in its own state, so nothing here tells the walks apart.
     *
     * Said here and not when the stop was asked for, because that is the promise {@see onQuiesce()}
     * makes: a quiesced report for a freeze this node has not entered lets the leader hand ready to
     * the initiator while the node is still serving its clients.
     *
     * @throws EnvException When the cluster-enabled flag value is invalid during initiator placement lookup
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onRosterStopped(): void
    {
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_ACTIVATING)) {
            return;
        }

        if ($this->isLeader && $this->activeFreeze !== null) {
            unset($this->pendingNodes[$this->selfNodeId]);
            $this->activateWhenAllQuiesced();
            return;
        }

        if ($this->freezingLeaderId !== null) {
            $this->mesh->sendQuiesced($this->freezingLeaderId);
        }
    }

    /**
     * Finishes whichever lift brought the roster back, told apart by the phase already on the row.
     *
     * The same on the leader and on a follower: each node tells its own browsers.
     */
    public function onRosterResumed(): void
    {
        $phase = $this->runtimeView()?->phase;
        if ($phase === StateProtectedModeRuntime::PHASE_VERIFYING) {
            $this->executor->finishVerifying();
        } elseif ($phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            $this->executor->finishLift();
        }
    }

    /**
     * Relays the leader's ready to this node's initiator agent, exactly once per request.
     *
     * Only the leader this node follows may confirm it, and only the first confirmation runs:
     * {@see ProtectedModeExecutor::notifyInitiatorReady} lets the initiator start its destructive
     * operation, so a stray or duplicate ready must not re-fire it. What re-arms the guard is this
     * node's own initiator asking again ({@see requestEnable()}) or a fresh freeze being ordered
     * against this node ({@see onQuiesce()}) - the two moments a ready is owed.
     *
     * @param string $fromNodeId Node id of the leader that confirmed the freeze
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onReady(string $fromNodeId): void
    {
        $this->pendingInitiatorAgentType = null;
        $this->pendingInitiatorAgentIndex = null;

        if (!$this->obeysLeader($fromNodeId)) {
            Logger::warning("Protected mode: dropping ready from '{$fromNodeId}' — node '{$this->selfNodeId}' is not frozen by it");
            return;
        }
        $this->followLeader($fromNodeId);
        if ($this->readyRelayed) {
            return;
        }

        $this->readyRelayed = true;
        $this->executor->notifyInitiatorReady();
    }

    /**
     * Relays the leader's refusal to this node's initiator agent.
     *
     * @param string $fromNodeId Node id of the leader that refused the freeze
     * @param string $reason Human-readable operator-facing refusal reason
     */
    public function onRefused(string $fromNodeId, string $reason): void
    {
        $agentType = $this->pendingInitiatorAgentType ?? $this->runtimeView()?->initiatorAgentType;
        $agentIndex = $this->pendingInitiatorAgentIndex ?? $this->runtimeView()?->initiatorAgentIndex;

        $this->pendingInitiatorAgentType = null;
        $this->pendingInitiatorAgentIndex = null;

        if ($agentType === null) {
            Logger::warning("Protected mode: refusal ('{$reason}') arrived from '{$fromNodeId}' but no initiator identity is recorded");
            return;
        }

        Hilos::$cluster?->protectedModeInitiatorRelay()?->deliverProtectedModeRefused(
            $agentType,
            $agentIndex === null ? null : (string)$agentIndex,
            $reason,
        );
    }

    /**
     * Releases this follower node only on an order from the leader it follows.
     *
     * A frozen row outlives a former leader or a node restart. The current leader may take over
     * that row; a stale lift from any other peer must not thaw the node mid-operation.
     *
     * @param string $fromNodeId Node id of the leader that lifted the freeze
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onLift(string $fromNodeId): void
    {
        $this->pendingInitiatorAgentType = null;
        $this->pendingInitiatorAgentIndex = null;

        if (!$this->underFreeze()) {
            Logger::warning("Protected mode: dropping lift from '{$fromNodeId}' — node '{$this->selfNodeId}' is not frozen");
            return;
        }
        if (!$this->obeysLeader($fromNodeId)) {
            $leaderNodeId = $this->mesh->leaderNodeId() ?? 'no leader';
            Logger::warning("Protected mode: dropping lift from '{$fromNodeId}' — node '{$this->selfNodeId}'"
                . " was frozen by '" . ($this->freezingLeaderId ?? 'nobody') . "' and knows '{$leaderNodeId}' as the leader");
            return;
        }
        $this->followLeader($fromNodeId);

        $this->executor->enterInactive();
        $this->freezingLeaderId = null;
    }

    /**
     * Writes active on this follower once its leader says every node has stopped its roster.
     *
     * The leader's word is all that active says on a follower (HIL-1128): this node's own walk ends
     * in a quiesced report, and only the round as a whole decides that the freeze holds. Taken only
     * from the leader this node follows and only on activating, because the frame rides every
     * link to the node ({@see PeerServer::broadcastToMasters()}): a copy over the second link
     * arrives on active, with the circle photographed after the first copy already on the row, and
     * writing active again would clear it.
     *
     * @param string $fromNodeId Node id of the leader that closed the round
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onSettled(string $fromNodeId): void
    {
        if (!$this->obeysLeader($fromNodeId)) {
            Logger::warning("Protected mode: dropping settled from '{$fromNodeId}' — node '{$this->selfNodeId}' is not frozen by it");
            return;
        }
        $this->followLeader($fromNodeId);
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_ACTIVATING)) {
            Logger::warning("Protected mode: dropping settled from '{$fromNodeId}' — node '{$this->selfNodeId}' is not activating");
            return;
        }

        $this->executor->enterActive();
    }

    /**
     * @param string $fromNodeId Node id the frame came from
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onVerify(string $fromNodeId, string $agentType, ?int $agentIndex): void
    {
        if ($this->obeysLeader($fromNodeId)) {
            $this->followLeader($fromNodeId);
            // Follower half: the leader has already decided, so what is left to refuse is a repeat
            // - reapplying the window would re-roll the stopped-agent roster the lift resumes.
            if ($this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
                Logger::warning("Protected mode: dropping verify from '{$fromNodeId}' — node '{$this->selfNodeId}' is already verifying");
                return;
            }

            $this->executor->enterVerifying();
            return;
        }

        if (!$this->leadsFreezeFor($fromNodeId, $agentType, $agentIndex, 'verify')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_ACTIVE)) {
            Logger::warning("Protected mode: dropping verify from '{$fromNodeId}' — the freeze has not reached active");
            return;
        }

        $this->executor->enterVerifying();
        $this->mesh->broadcastVerify($this->activeFreeze->initiatorAgentType, $this->activeFreeze->initiatorAgentIndex);
    }

    /**
     * Stamps this leader's freeze row with a progress mark the initiator's node reported.
     *
     * The freeze survives a leader change with its clock, not with its history: whoever leads
     * reads the row it already carries, so a mark landing here is what keeps that row from
     * looking silent while the operation behind it is fine.
     *
     * Authorized exactly as {@see onDisable()} authorizes the release: only the agent that asked
     * for the freeze may say anything about it, regardless of its current node. A
     * mark for a freeze this node is not leading is dropped without a log, because it is the
     * ordinary tail of an operation whose last marks outlived its freeze, and this frame arrives
     * once per line of the operation's output. A mark from a node that does NOT own a freeze that
     * IS being led is logged: that one is a stranger refreshing somebody else's row, and it could
     * keep a hung operation looking alive indefinitely.
     *
     * @param string $fromNodeId Node id the frame came from
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onProgress(string $fromNodeId, string $agentType, ?int $agentIndex): void
    {
        if (!$this->isLeader || $this->activeFreeze === null) {
            return;
        }
        if (!$this->leadsFreezeFor($fromNodeId, $agentType, $agentIndex, 'progress')) {
            return;
        }

        $this->runtimeView()?->actions->markProgress();
    }

    /**
     * @param string $fromNodeId Node id the frame came from
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @param string $passHash SHA-256 of the minted pass
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onPass(string $fromNodeId, string $agentType, ?int $agentIndex, string $passHash): void
    {
        if ($this->obeysLeader($fromNodeId)) {
            $this->followLeader($fromNodeId);
            if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
                Logger::warning("Protected mode: dropping pass from '{$fromNodeId}' — node '{$this->selfNodeId}' is not verifying");
                return;
            }

            $this->issuePassAndAnnounceFirst($passHash);
            return;
        }

        if (!$this->leadsFreezeFor($fromNodeId, $agentType, $agentIndex, 'pass')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
            Logger::warning("Protected mode: dropping pass from '{$fromNodeId}' — the mode is not verifying");
            return;
        }

        $this->issuePassAndAnnounceFirst($passHash);
        $this->mesh->broadcastPass($this->activeFreeze->initiatorAgentType, $this->activeFreeze->initiatorAgentIndex, $passHash);
    }

    /**
     * Admits only a pass minted in the current verification window, then forwards the verdict.
     *
     * @param string $fromNodeId Node id that sent the admission
     * @param string $passHash Hash of the presented pass
     * @param string $sessionTokenHash Hash of the verifier session
     * @throws EnvException When leader lookup is unavailable
     */
    public function onAdmit(string $fromNodeId, string $passHash, string $sessionTokenHash): void
    {
        if ($this->obeysLeader($fromNodeId)) {
            $this->followLeader($fromNodeId);
            if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
                Logger::warning("Protected mode: dropping admission from '{$fromNodeId}' — node '{$this->selfNodeId}' is not verifying");
                return;
            }
            if (!$this->passStandsOnRow($passHash)) {
                Logger::warning("Protected mode: dropping admission from '{$fromNodeId}' — its code was not minted in this window");
                return;
            }

            $this->executor->admitVerifier($sessionTokenHash);
            return;
        }

        if (!$this->isLeader || $this->activeFreeze === null) {
            Logger::warning("Protected mode: dropping admission from '{$fromNodeId}' — no freeze is being led here");
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
            Logger::warning("Protected mode: dropping admission from '{$fromNodeId}' — the mode is not verifying");
            return;
        }
        if (!$this->passStandsOnRow($passHash)) {
            Logger::warning("Protected mode: dropping admission from '{$fromNodeId}' — its code was not minted in this window");
            return;
        }

        $this->executor->admitVerifier($sessionTokenHash);
        $this->mesh->broadcastAdmit($passHash, $sessionTokenHash);
    }

    /**
     * @param string $passHash Hash of the pass to find on this master's row
     * @return bool Whether this window minted that pass
     */
    private function passStandsOnRow(string $passHash): bool
    {
        foreach ($this->runtimeView()?->passHashes ?? [] as $minted) {
            if (hash_equals($minted, $passHash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records the photographed circle on this node's row, and on the leader fans it to every master.
     *
     * The phase is checked on neither half. A follower reaches `active` only on its leader's settled
     * frame, as {@see requestCircle()} says, and the leader has nothing a phase would add: the
     * photograph is taken at ready, the one moment it is both final and true, and
     * {@see leadsFreezeFor()} has already said that the freeze it belongs to is the one being led here.
     *
     * @param string $fromNodeId Node id the frame came from
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @param VerifierCircleSnapshot $snapshot The circle as the initiator's node photographed it
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onCircle(string $fromNodeId, string $agentType, ?int $agentIndex, VerifierCircleSnapshot $snapshot): void
    {
        if ($this->obeysLeader($fromNodeId)) {
            $this->followLeader($fromNodeId);
            $this->runtimeView()?->actions->admitCircle($snapshot);
            return;
        }

        if (!$this->leadsFreezeFor($fromNodeId, $agentType, $agentIndex, 'circle')) {
            return;
        }

        $this->runtimeView()?->actions->admitCircle($snapshot);
        $this->mesh->broadcastCircle($this->activeFreeze->initiatorAgentType, $this->activeFreeze->initiatorAgentIndex, $snapshot);
    }

    /**
     * Closes the cluster back from the verification window, on the leader.
     *
     * The frame travels one way only, from the initiator's node to the leader. The close is the same
     * quiesce round as an entry (HIL-1128): this node and every follower stop their rosters again,
     * and active is written only once all of them have reported. It owes the initiator no ready -
     * the close is answered by the row reaching active. A follower no longer applies a refreeze from
     * its own leader, because none is sent; the frame falls to {@see leadsFreezeFor()} and is dropped.
     * The accept key and the session hash come off the leader's row, so the next window lets the
     * same operator in.
     *
     * @param string $fromNodeId Node id the frame came from
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onRefreeze(string $fromNodeId, string $agentType, ?int $agentIndex): void
    {
        if (!$this->leadsFreezeFor($fromNodeId, $agentType, $agentIndex, 'refreeze')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
            Logger::warning("Protected mode: dropping refreeze from '{$fromNodeId}' — the mode is not verifying");
            return;
        }

        // Both are already vouched for by the two guards above; the check is for the type system.
        $view = $this->runtimeView();
        if ($view === null || $this->activeFreeze === null) {
            return;
        }

        $this->readyOwed = false;
        $this->activeFreeze = $this->activeFreeze->withInitiatorSessionTokenHash($view->initiatorSessionTokenHash);
        $this->enterRound($this->activeFreeze, $view->initiatorAcceptKey);
    }

    /**
     * Starts a quiesce round for the freeze already held: this node plus every follower.
     *
     * Shared by the first entry, a repeat enable from the verification window (HIL-1057) and the
     * close back from it (HIL-1128). The leader waits for itself as it waits for any follower:
     * its own roster stops over several master passes, and a follower with a shorter one reports
     * back before it has. Counted only among the followers, that report would activate a freeze
     * the leader's own node was still serving clients under (HIL-1012).
     *
     * @param ProtectedModeQuiesceData $freeze Freeze this round is entering
     * @param ?string $initiatorAcceptKey Accept key of the initiator connection when the leader
     *                                    freezes itself; null when the initiator sits on another node
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    private function enterRound(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void {
        $this->pendingNodes = array_fill_keys([...$this->mesh->followerMasterNodeIds(), $this->selfNodeId], true);
        $this->active = false;

        $this->executor->enterActivating($freeze, $initiatorAcceptKey);
        $this->mesh->broadcastQuiesce($freeze);
    }

    /**
     * Answers an enable raised against the freeze this leader is already driving.
     *
     * The leader half of {@see StandaloneProtectedMode::answerFreezeAlreadyHeld()}, and it exists
     * for the same operator: closing the verification window leaves the whole cluster frozen on
     * active so another attempt can run, and that attempt's enable would otherwise be refused as a
     * freeze in flight, leaving its initiator waiting for a ready nobody was going to send. The
     * quiesce round is not replayed for a freeze that already stands on active - the followers
     * are still frozen, and re-ordering it would re-roll the stopped-agent roster each of them
     * resumes against - so what the initiator gets is the ready the settled freeze already earns it.
     * Active means every node has stopped, after a close as after an entry: the close back runs the
     * same round and the leader writes active only at its end (HIL-1128).
     *
     * An enable that arrives while the verification window is open is an entry again (HIL-1057):
     * the quiesce round runs once more, and ready comes from {@see activateWhenAllQuiesced()} once
     * this node and every follower have reported. Any other asker is refused
     * with a stated reason ({@see ProtectedModeRefusalCopy}) instead of being left to its timeout.
     *
     * Authorized by agent type and index alone. The initiator is a singleton cluster-wide,
     * enforced by topology validation, so the identity still names one actor after it moves.
     * A repeat entry from the verification window runs another quiesce round, letting the
     * operator keep the system closed while checking the restored data.
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
        if (!$this->active) {
            Logger::warning("Protected mode: dropping enable from '{$fromNodeId}'"
                . " — a '{$freeze->operation}' freeze is already in flight");
            $this->signalInitiatorRefused($fromNodeId, $data, ProtectedModeRefusalCopy::ANOTHER_OPERATION);
            return;
        }

        if (
            $data->initiatorAgentType !== $freeze->initiatorAgentType
            || $data->initiatorAgentIndex !== $freeze->initiatorAgentIndex
        ) {
            Logger::warning("Protected mode: dropping enable from '{$fromNodeId}'"
                . " — a '{$freeze->operation}' freeze is already in flight");
            $this->signalInitiatorRefused($fromNodeId, $data, ProtectedModeRefusalCopy::FOREIGN_FREEZE);
            return;
        }

        if ($this->phaseIs(StateProtectedModeRuntime::PHASE_ACTIVE)) {
            $this->signalInitiatorReady($freeze);
            return;
        }

        if ($this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING)) {
            $this->readyOwed = true;
            $this->activeFreeze = $freeze->withInitiatorSessionTokenHash($data->initiatorSessionTokenHash);
            $this->enterRound($this->activeFreeze, $data->initiatorAcceptKey);
            return;
        }

        Logger::warning("Protected mode: dropping enable from '{$fromNodeId}'"
            . " — a '{$freeze->operation}' freeze is already in flight");
        $this->signalInitiatorRefused($fromNodeId, $data, ProtectedModeRefusalCopy::ANOTHER_OPERATION);
    }

    private function signalInitiatorRefused(
        string $fromNodeId,
        ProtectedModeEnableSignalData $data,
        string $reason,
    ): void {
        if ($fromNodeId === $this->selfNodeId) {
            Hilos::$cluster?->protectedModeInitiatorRelay()?->deliverProtectedModeRefused(
                $data->initiatorAgentType,
                $data->initiatorAgentIndex === null ? null : (string)$data->initiatorAgentIndex,
                $reason,
            );
            return;
        }

        $this->mesh->sendRefused($fromNodeId, $reason);
    }

    /**
     * Whether this follower may accept a freeze frame from its former or current leader.
     *
     * This check is pure; {@see followLeader()} records an accepted change separately.
     *
     * @param string $fromNodeId Node id the frame came from
     * @return bool Whether this follower accepts that leader
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    private function obeysLeader(string $fromNodeId): bool
    {
        if ($this->isLeader) {
            return false;
        }
        if ($this->freezingLeaderId !== null && $fromNodeId === $this->freezingLeaderId) {
            return true;
        }
        return $fromNodeId === $this->mesh->leaderNodeId() && $this->underFreeze();
    }

    /**
     * Records the leader this frozen follower now obeys after {@see obeysLeader()} accepts it.
     *
     * @param string $fromNodeId Accepted leader node id
     */
    private function followLeader(string $fromNodeId): void
    {
        if ($this->freezingLeaderId === $fromNodeId) {
            return;
        }
        Logger::info("Protected mode: node '{$this->selfNodeId}' now follows leader '{$fromNodeId}' for its freeze"
            . " (was '" . ($this->freezingLeaderId ?? 'nobody') . "')");
        $this->freezingLeaderId = $fromNodeId;
    }

    /**
     * Whether this node still carries a freeze in memory or on its runtime row.
     *
     * @return bool Whether this node is under a freeze
     */
    private function underFreeze(): bool
    {
        $phase = $this->runtimeView()?->phase;
        return $this->freezingLeaderId !== null
            || ($phase !== null && $phase !== StateProtectedModeRuntime::PHASE_INACTIVE);
    }

    /**
     * Whether this node leads the freeze the sending node initiated.
     *
     * The leader half of every verification frame shares this authorization with
     * {@see onDisable()}: only the agent that asked for the freeze may drive it.
     *
     * @param string $fromNodeId Node id the frame came from, for logging
     * @param string $agentType Agent type in the frame
     * @param ?int $agentIndex Agent index in the frame
     * @param string $frame Frame name for the refusal log line
     * @return bool Whether the agent may drive the freeze
     */
    private function leadsFreezeFor(string $fromNodeId, string $agentType, ?int $agentIndex, string $frame): bool
    {
        $label = self::agentLabel($agentType, $agentIndex);
        if (!$this->isLeader || $this->activeFreeze === null) {
            Logger::warning("Protected mode: dropping {$frame} from agent '{$label}' on '{$fromNodeId}' — no freeze is being led here");
            return false;
        }
        if (
            $agentType !== $this->activeFreeze->initiatorAgentType
            || $agentIndex !== $this->activeFreeze->initiatorAgentIndex
        ) {
            $initiatorLabel = self::agentLabel(
                $this->activeFreeze->initiatorAgentType,
                $this->activeFreeze->initiatorAgentIndex,
            );
            Logger::warning("Protected mode: dropping {$frame} from agent '{$label}' on '{$fromNodeId}'"
                . " — freeze was initiated by agent '{$initiatorLabel}'");
            return false;
        }

        return true;
    }

    /**
     * @param string $agentType Agent type
     * @param ?int $agentIndex Agent index, or null for a singleton
     * @return string Human-readable agent identity
     */
    private static function agentLabel(string $agentType, ?int $agentIndex): string
    {
        return $agentIndex === null ? $agentType : $agentType . '#' . $agentIndex;
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
     * Records a minted pass on this node's row and tells the locked-out browsers about the first one.
     *
     * Both ways a pass reaches the leader end here, and both owe the same announcement: the browsers
     * frozen on this node are showing a stub that says nothing has been minted yet, and only the
     * zero-to-one step makes that sentence false. A later mint changes nothing any of them display,
     * so it is written to the row and left unannounced.
     *
     * @param string $passHash SHA-256 of the minted pass
     */
    private function issuePassAndAnnounceFirst(string $passHash): void
    {
        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        $view->actions->issuePass($passHash);

        if (count($view->passHashes) === 1) {
            $this->executor->announcePassIssued();
        }
    }

    /**
     * Marks the freeze active once no node is still pending, tells every follower so, and signals
     * the initiator when the round owes it a ready.
     */
    private function activateWhenAllQuiesced(): void
    {
        if ($this->activeFreeze === null || $this->active || $this->pendingNodes !== []) {
            return;
        }

        $this->active = true;
        $this->executor->enterActive();
        // Before the ready, and the order carries weight: an initiator on a follower gets both frames
        // over one link, writes active first and only then relays the ready that photographs the
        // circle, so the follower's enterActive() never clears a circle already on its row.
        $this->mesh->broadcastSettled();
        if (!$this->readyOwed) {
            return;
        }

        $this->readyOwed = false;
        $this->signalInitiatorReady($this->activeFreeze);
    }

    /**
     * Finds the initiator's current placement and tells it the cluster has quiesced.
     *
     * A local agent is notified directly; a remote one receives a ready peer frame. Unknown
     * placement is logged and left to the watchdog rather than sent to the old entry node.
     *
     * @param ProtectedModeQuiesceData $freeze Freeze whose initiator is being signalled
     * @throws EnvException When the cluster-enabled flag value is invalid during placement lookup
     */
    private function signalInitiatorReady(ProtectedModeQuiesceData $freeze): void
    {
        $location = $this->mesh->locateAgent($freeze->initiatorAgentType, $freeze->initiatorAgentIndex);
        if ($location->kind === AgentLocationKind::Here) {
            $this->executor->notifyInitiatorReady();
            return;
        }
        if ($location->kind === AgentLocationKind::Node && $location->nodeId !== null) {
            $this->mesh->sendReady($location->nodeId);
            return;
        }

        $label = self::agentLabel($freeze->initiatorAgentType, $freeze->initiatorAgentIndex);
        Logger::warning("Protected mode: the ready for agent '{$label}' reached nobody — no node is known to host it");
    }

    /**
     * Takes over the freeze this node is already under, as its leader rather than as its follower.
     *
     * Read off the freeze row, which every node keeps a copy of, because that is the only record of
     * the freeze that outlived the leader that ordered it. A row that names no operation or no
     * initiator agent cannot be led - there would be nobody to authorize the disable against - so it
     * is reported and left alone; the watchdog reports the same freeze as stuck, and the operator
     * ends it.
     *
     * The follower-side marker is dropped in the same movement: this node was frozen by the leader
     * that is gone, and it leads that freeze now. Left standing, it would outlive the lift - only a
     * lift from the same leader clears it ({@see onLift()}), and a leader does not send itself one -
     * and the next freeze that ordered this node to quiesce would be refused as "already frozen",
     * leaving it serving clients through somebody else's restore.
     */
    private function adoptStandingFreeze(): void
    {
        $view = $this->runtimeView();
        if ($view === null || $view->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            return;
        }

        $operation = $view->operation;
        $initiatorAgentType = $view->initiatorAgentType;
        if ($operation === null || $initiatorAgentType === null) {
            Logger::error(
                "Protected mode: node '{$this->selfNodeId}' became leader under a freeze on phase "
                . "'{$view->phase}' that names no operation or initiator, and cannot lead it"
            );
            return;
        }

        $this->activeFreeze = new ProtectedModeQuiesceData(
            $operation,
            $initiatorAgentType,
            $view->initiatorAgentIndex,
            $view->initiatorNodeId,
            $view->initiatorSessionTokenHash,
        );
        // A row past activating means the round closed under the previous leader: the freeze is
        // established and the initiator has been told so. Re-collecting there would hand out a
        // second ready in the middle of the operation the first one started.
        $this->active = $view->phase !== StateProtectedModeRuntime::PHASE_ACTIVATING;
        // An unfinished round is taken for an entry, as it always was - the row does not say
        // whether a close started it.
        $this->readyOwed = !$this->active;
        $this->pendingNodes = $this->active
            ? []
            : array_fill_keys($this->mesh->followerMasterNodeIds(), true);
        $this->freezingLeaderId = null;

        Logger::warning(
            "Protected mode: node '{$this->selfNodeId}' became leader under a standing freeze for "
            . "'{$operation}' on phase '{$view->phase}'; it now leads it"
        );
    }

    /**
     * Clears the leader-side orchestration back to idle.
     */
    private function resetLeaderState(): void
    {
        $this->activeFreeze = null;
        $this->pendingNodes = [];
        $this->active = false;
        $this->readyOwed = false;
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
