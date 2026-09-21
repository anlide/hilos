<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

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
 * The single-node freeze: protected mode for an installation running without a cluster.
 *
 * It is the {@see ClusterProtectedMode} state machine with everything peer-shaped removed. With no
 * followers there is no quiesce round to wait for and no pendingNodes to track, and with no
 * leadership there is nothing to gate on, so the whole entry collapses into one walk: freeze the
 * node, and once its roster has stopped ({@see onRosterStopped()}) mark it active and tell the
 * initiator to go. The local half is shared verbatim with the clustered path - the same
 * {@see ProtectedModeExecutor} writes the same {@see ProtectedModeRuntime} row and stops the same
 * agents - so a project sees identical behavior whether or not it clusters, which is the whole
 * point of this class existing.
 *
 * Two guards mirror the cluster's for the same reasons. A repeat enable is never re-run while the
 * roster is still standing, because re-entering the freeze re-rolls the stopped-agent roster the
 * release resumes against and would strand agents. From the verification window, where that roster
 * has been returned, a repeat enable is an entry again under the initiator the enable names, and
 * ready comes from {@see onRosterStopped()} (HIL-1057). The initiator of a freeze that already
 * stands on active is answered ready instead of dropped, and anyone else is refused with a reason
 * ({@see answerFreezeAlreadyHeld()}, HIL-909).
 * A release is honored only for the agent recorded as the initiator: on one node the cluster's
 * node-id check compares a node against itself and authorizes nothing, so the agent identity is
 * the only thing left that distinguishes the initiator from any other agent that might resume the
 * system mid-restore.
 *
 * The verification window adds three more requests - open it, mint a pass into it, close back out
 * of it - and they authorize by that same recorded agent identity. Each also refuses from the wrong
 * phase, which the enable and disable pair never had to: those two are the ends of the ladder, while
 * a verify raised twice or a pass minted for a window nobody opened would move the phase behind the
 * operator's back.
 *
 * Entering without a mounted runtime row is refused loudly instead of silently doing nothing: the
 * initiator waits for ready before it starts destroying anything, so a refusal - delivered to it
 * with its reason since HIL-909 - ends that wait with nothing destroyed, while a silent no-op that
 * still reported ready would run a restore over a live system.
 */
final class StandaloneProtectedMode implements ProtectedModeSwitch
{
    /** @var ProtectedModeExecutor Local-node port that writes the phase and stops or resumes agents */
    private ProtectedModeExecutor $executor;

    /** @var ?ProtectedModeQuiesceData Freeze this node is holding, or null when idle */
    private ?ProtectedModeQuiesceData $activeFreeze = null;

    /**
     * @param ProtectedModeExecutor $executor Local-node port that writes the phase and stops agents
     */
    public function __construct(ProtectedModeExecutor $executor)
    {
        $this->executor = $executor;
    }

    /**
     * Freezes this node for a destructive operation; the initiator is told it may run once the
     * roster has stopped ({@see onRosterStopped()}).
     *
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestEnable(ProtectedModeEnableSignalData $data): void
    {
        if ($this->activeFreeze !== null) {
            $this->answerFreezeAlreadyHeld($this->activeFreeze, $data);
            return;
        }
        if ($this->runtimeView() === null) {
            Logger::error(
                "Protected mode: cannot enter for '{$data->operation}' requested by agent "
                . "'{$data->initiatorAgentType}' — this process holds no protected mode runtime state"
            );
            $this->deliverRefusal($data, ProtectedModeRefusalCopy::NO_RUNTIME_ROW);
            return;
        }

        $this->activeFreeze = new ProtectedModeQuiesceData(
            $data->operation,
            $data->initiatorAgentType,
            $data->initiatorAgentIndex,
            null,
        );

        $this->executor->enterActivating($this->activeFreeze, $data->initiatorAcceptKey, $data->initiatorSessionTokenHash);
    }

    /**
     * Releases this node once the initiator that froze it says its operation has finished.
     *
     * @param ProtectedModeDisableSignalData $data Identity of the agent asking for the release
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestDisable(ProtectedModeDisableSignalData $data): void
    {
        if (!$this->initiatorMayDrive($data->initiatorAgentType, $data->initiatorAgentIndex, 'disable')) {
            return;
        }

        $this->executor->enterDeactivating();
        $this->executor->enterInactive();
        $this->activeFreeze = null;
    }

    /**
     * Opens the verification window once the initiator that froze this node says its operation is over.
     *
     * @param ProtectedModeVerifySignalData $data Identity of the agent asking for the window
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestVerify(ProtectedModeVerifySignalData $data): void
    {
        if (!$this->initiatorMayDrive($data->initiatorAgentType, $data->initiatorAgentIndex, 'verify')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_ACTIVE, 'verify')) {
            return;
        }

        $this->executor->enterVerifying();
    }

    /**
     * Stamps the freeze row with the moment the initiator's operation last moved.
     *
     * Two things separate it from every other request here. It writes no phase, so there is no
     * wrong phase to refuse from: a restore marks its acceptance before the freeze exists and its
     * outcome after the freeze has lifted, and both are honest reports about work that did move.
     * And a mark arriving under no freeze at all is dropped without a word - it is a report to
     * nobody rather than an anomaly, and this frame repeats for every line the operation prints,
     * so a log line per stray mark would bury the ones that mean something.
     *
     * What is still refused loudly is the case that means something: another agent stamping the
     * freeze this one is holding. That is the same authorization the release is given, and for the
     * same reason - on a single node the recorded agent identity is all that tells the initiator
     * from anyone else, and a stranger able to refresh the mark could keep a hung operation
     * looking alive for as long as it liked.
     *
     * @param ProtectedModeProgressSignalData $data Identity of the agent reporting the progress
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestProgress(ProtectedModeProgressSignalData $data): void
    {
        if ($this->activeFreeze === null) {
            return;
        }
        if (!$this->initiatorMayDrive($data->initiatorAgentType, $data->initiatorAgentIndex, 'progress')) {
            return;
        }

        $this->runtimeView()?->actions->markProgress();
    }

    /**
     * Records one more pass for the verification window this node is holding.
     *
     * @param ProtectedModePassSignalData $data Minting agent identity and the hash of the pass
     */
    public function requestPass(ProtectedModePassSignalData $data): void
    {
        if (!$this->initiatorMayDrive($data->initiatorAgentType, $data->initiatorAgentIndex, 'pass')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING, 'pass')) {
            return;
        }

        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        $view->actions->issuePass($data->passHash);

        // Zero-to-one and no other mint: the announcement says a code is standing, which the second
        // one would say again to browsers already showing the field.
        if (count($view->passHashes) === 1) {
            $this->executor->announcePassIssued();
        }
    }

    /**
     * Records the verifier circle this node's initiator photographed under the freeze (HIL-643).
     *
     * Authorized by the recorded initiator like every other request here, and for a sharper reason
     * than most: the payload is a list of session hashes that the verification window will let in
     * unasked, so a stranger agent able to send one could name whoever it liked.
     *
     * Fail-closed on {@see StateProtectedModeRuntime::PHASE_ACTIVE} because that is the phase the
     * photograph is taken from - the initiator is told ready from {@see onRosterStopped()},
     * having quiesced - and a circle written outside a settled freeze would sit on the row waiting
     * for a window whose entry clears it anyway.
     *
     * @param ProtectedModeCircleSignalData $data Photographing agent identity and the circle it saw
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestCircle(ProtectedModeCircleSignalData $data): void
    {
        if (!$this->initiatorMayDrive($data->initiatorAgentType, $data->initiatorAgentIndex, 'circle')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_ACTIVE, 'circle')) {
            return;
        }

        $this->runtimeView()?->actions->admitCircle(
            new VerifierCircleSnapshot($data->namedCount, $data->sessionTokenHashes),
        );
    }

    /**
     * Closes this node back from the verification window, voiding every pass.
     *
     * @param ProtectedModeRefreezeSignalData $data Identity of the agent asking to close back
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestRefreeze(ProtectedModeRefreezeSignalData $data): void
    {
        if (!$this->initiatorMayDrive($data->initiatorAgentType, $data->initiatorAgentIndex, 'refreeze')) {
            return;
        }
        if (!$this->phaseIs(StateProtectedModeRuntime::PHASE_VERIFYING, 'refreeze')) {
            return;
        }

        $this->executor->reenterActive();
    }

    /**
     * Marks the freeze active and tells the initiator it may run, now that the roster has stopped.
     *
     * Only for the walk that enters a freeze - the row still says activating. That walk is the first
     * entry or a repeat from the verification window (HIL-1057); both sit on activating. The walk
     * that closes the verification window back runs on a row already written active and answers
     * nobody: that initiator was told ready when the freeze first took hold. A walk that a lift
     * overtook never gets here at all ({@see ProtectedModeAgentFreezer::resumeAgentsForProtectedMode()}).
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onRosterStopped(): void
    {
        if ($this->activeFreeze === null || $this->runtimeView()?->phase !== StateProtectedModeRuntime::PHASE_ACTIVATING) {
            return;
        }

        $this->executor->enterActive();
        $this->executor->notifyInitiatorReady();
    }

    /**
     * Finishes whichever lift brought the roster back, told apart by the phase already on the row.
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
     * Answers an enable raised against the freeze this node is already holding.
     *
     * Closing the verification window is how an operator gets to run another attempt, and it
     * deliberately leaves the node frozen on active: the next operation therefore finds nothing
     * left to enter, and a plain refusal left its initiator waiting for a ready that could never
     * come. So the initiator the row records is told ready once more - the node is quiesced, which
     * is all a ready ever asserted.
     *
     * An enable arriving from inside the verification window is an entry again under the initiator
     * the enable names (HIL-1057): the row goes back to activating on the freeze already held, and
     * ready is told from {@see onRosterStopped()} once that walk has finished.
     *
     * Any other phase, or an enable naming another initiator agent, is refused immediately with an
     * operator-facing reason so the caller does not wait out the 60-second freeze timeout.
     *
     * The operation named on the row is left alone; a request naming another one says so in the
     * log, because the freeze it would rename is the one every locked-out client is already
     * reading a stub about.
     *
     * @param ProtectedModeQuiesceData $freeze Freeze this node is holding
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the enable names
     */
    private function answerFreezeAlreadyHeld(
        ProtectedModeQuiesceData $freeze,
        ProtectedModeEnableSignalData $data,
    ): void {
        $view = $this->runtimeView();
        if ($view === null) {
            Logger::warning("Protected mode: dropping enable — a '{$freeze->operation}' freeze is already in flight");
            $this->deliverRefusal($data, ProtectedModeRefusalCopy::NO_RUNTIME_ROW);
            return;
        }

        if (
            $view->initiatorAgentType !== $data->initiatorAgentType
            || $view->initiatorAgentIndex !== $data->initiatorAgentIndex
        ) {
            Logger::warning("Protected mode: dropping enable — a '{$freeze->operation}' freeze is already in flight");
            $this->deliverRefusal($data, ProtectedModeRefusalCopy::FOREIGN_FREEZE);
            return;
        }

        if ($view->phase === StateProtectedModeRuntime::PHASE_ACTIVE) {
            if ($data->operation !== $freeze->operation) {
                Logger::warning(
                    "Protected mode: enable for '{$data->operation}' arrived under the standing "
                    . "'{$freeze->operation}' freeze — the stub keeps naming the operation it was entered for"
                );
            }
            $this->executor->notifyInitiatorReady();
            return;
        }

        if ($view->phase === StateProtectedModeRuntime::PHASE_VERIFYING) {
            if ($data->operation !== $freeze->operation) {
                Logger::warning(
                    "Protected mode: enable for '{$data->operation}' arrived under the standing "
                    . "'{$freeze->operation}' freeze — the stub keeps naming the operation it was entered for"
                );
            }
            $this->executor->enterActivating($freeze, $data->initiatorAcceptKey, $data->initiatorSessionTokenHash);
            return;
        }

        Logger::warning("Protected mode: dropping enable — a '{$freeze->operation}' freeze is already in flight");
        $this->deliverRefusal($data, ProtectedModeRefusalCopy::ANOTHER_OPERATION);
    }

    private function deliverRefusal(ProtectedModeEnableSignalData $data, string $reason): void
    {
        Hilos::$cluster?->protectedModeInitiatorRelay()?->deliverProtectedModeRefused(
            $data->initiatorAgentType,
            $data->initiatorAgentIndex === null ? null : (string)$data->initiatorAgentIndex,
            $reason,
        );
    }

    /**
     * Whether this agent is the initiator recorded on the freeze row and may drive it.
     *
     * The same authorization {@see requestDisable()} applies, lifted into one place because three
     * more requests now need it: on a single node the recorded agent identity is all that
     * distinguishes the initiator from any other agent that might open the system mid-operation.
     *
     * @param string $agentType Agent type making the request
     * @param ?int $agentIndex Agent index making the request, or null for a singleton agent
     * @param string $request Request name for the refusal log line
     * @return bool Whether the request may proceed
     */
    private function initiatorMayDrive(string $agentType, ?int $agentIndex, string $request): bool
    {
        if ($this->activeFreeze === null) {
            Logger::warning("Protected mode: dropping {$request} from agent '{$agentType}' — no freeze is active here");
            return false;
        }

        $view = $this->runtimeView();
        if (
            $view === null
            || $view->initiatorAgentType !== $agentType
            || $view->initiatorAgentIndex !== $agentIndex
        ) {
            Logger::warning("Protected mode: dropping {$request} from agent '{$agentType}' — the freeze was initiated by another agent");
            return false;
        }

        return true;
    }

    /**
     * Whether the freeze row is on the phase a request is only meaningful from.
     *
     * Fail-closed and separate from the identity check above: a verify raised twice, or a pass
     * minted for a window nobody opened, is a request against a system in a state it does not
     * describe, and running it would move the phase behind the operator's back.
     *
     * @param string $expected Phase the request requires
     * @param string $request Request name for the refusal log line
     * @return bool Whether the row is on that phase
     */
    private function phaseIs(string $expected, string $request): bool
    {
        $phase = $this->runtimeView()?->phase;
        if ($phase === $expected) {
            return true;
        }

        Logger::warning("Protected mode: dropping {$request} — the mode is '{$phase}', not '{$expected}'");

        return false;
    }

    /**
     * Resolves the protected-mode runtime singleton, or null when this process holds no runtime state.
     *
     * Read here as well as in {@see DaemonProtectedModeExecutor} because the two ask different
     * questions of the same row: the executor asks where to write, this asks whether entering the
     * mode is possible at all and who the recorded initiator is. Null never means a project turned
     * the mode off - the framework mounts this row for every project that has an RT context.
     *
     * @return ?ProtectedModeRuntime Runtime singleton view, or null when runtime state is unavailable
     */
    private function runtimeView(): ?ProtectedModeRuntime
    {
        return Hilos::$rt?->hilosProtectedModeRuntime;
    }
}
