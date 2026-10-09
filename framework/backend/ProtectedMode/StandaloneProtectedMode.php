<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Daemon\DaemonManager;
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
use Hilos\Runtime\View\Actions\Item\ProtectedModeRuntimeActions;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Utils\Logger;

/**
 * The single-node protected mode: ordinary freeze and direct verification window.
 *
 * It is the {@see ClusterProtectedMode} state machine with everything peer-shaped removed. With no
 * followers there is no quiesce round to wait for and no pendingNodes to track, and with no
 * leadership there is nothing to gate on, so an ordinary entry collapses into one walk: freeze the
 * node, and once its roster has stopped ({@see onRosterStopped()}) mark it active and tell the
 * initiator to go. The local half is shared verbatim with the clustered path - the same
 * {@see ProtectedModeExecutor} writes the same {@see ProtectedModeRuntime} row and stops the same
 * agents. Direct entry is single-node only: it writes verifying immediately, leaves agents running,
 * and completes its visitor and personal frames after the worker's circle photograph arrives.
 *
 * Two guards mirror the cluster's for the same reasons. A repeat enable is never re-run while the
 * roster is still standing, because re-entering the freeze re-rolls the stopped-agent roster the
 * release resumes against and would strand agents. From the verification window, where that roster
 * has been returned, a repeat enable is an entry again under the initiator the enable names, and
 * ready comes from {@see onRosterStopped()} (HIL-1057). The initiator of a freeze that already
 * stands on active is answered ready instead of dropped, and anyone else is refused with a reason
 * ({@see answerFreezeAlreadyHeld()}, HIL-909). That ready is honest because active is written only
 * at the end of a walk, the close back from the window included (HIL-1128).
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
 *
 * A switch built over a freeze that is already standing takes it over ({@see adoptStandingFreeze()}).
 * The freeze held in memory dies with the master, while the row comes back from disk, so a node
 * restarted under a freeze would otherwise drop every request of the initiator the row records and
 * open to nothing but a hand on the freeze file. It is this node's counterpart of a cluster leader
 * change, where the successor takes over the freeze its node is under (HIL-1510).
 */
final class StandaloneProtectedMode implements ProtectedModeSwitch
{
    /** @var ProtectedModeExecutor Local-node port that writes the phase and stops or resumes agents */
    private ProtectedModeExecutor $executor;

    /** @var ?ProtectedModeQuiesceData Freeze this node is holding, or null when idle */
    private ?ProtectedModeQuiesceData $activeFreeze = null;

    /**
     * @var bool Whether the walk in flight owes the initiator a ready - an entry does, the close
     *     back from the window does not (HIL-1128)
     */
    private bool $readyOwed = false;

    /** Whether the direct window has accepted its one verifier-circle photograph. */
    private bool $directCircleReceived = false;

    /**
     * @param ProtectedModeExecutor $executor Local-node port that writes the phase and stops agents
     */
    public function __construct(ProtectedModeExecutor $executor)
    {
        $this->executor = $executor;
    }

    /**
     * Takes over the freeze this node came up under, so the initiator the row records can drive it.
     *
     * Called once, right where the switch is built: the row is already back by then, put there by
     * the restore at the end of {@see DaemonManager::boot()}, and from that moment this node answers
     * for the freeze. Adopting lazily on the first request would spread the state over every entry
     * point and still miss the progress mark, which is dropped silently under no freeze.
     *
     * Read off the runtime row rather than the file, because the row is what the restore decided to
     * keep ({@see ProtectedModeRuntimeActions::restoreFromDisk()}): anything that rule burns at a
     * restart is burned here too, without a line of this method knowing about it. A row that names
     * no operation or no initiator agent cannot be driven - there is nobody to authorize a request
     * against - so it is reported and left alone, and the watchdog reports the freeze as stuck.
     *
     * Nobody is owed a ready, whatever the phase. A ready answers an operation waiting to start, and
     * the operation behind this freeze did not survive the restart; neither did any walk of the
     * roster, which lived in the memory of the process that is gone. A row left on activating is
     * therefore not an entry to finish: its way out is the release, which passes from any phase. This
     * is where the node parts with the cluster on purpose: a promoted leader collects the round again
     * because its followers are still alive, and a restarted node has nobody to collect it from.
     *
     * The direct window's one photograph of the circle counts as taken. The restore burned the circle
     * it admitted, so the window comes back empty, and a second photograph would re-send window frames
     * to browsers that read the state anew on their handshake anyway. A repeat enable from the same
     * initiator is answered ready, and a code is the way in again.
     */
    public function adoptStandingFreeze(): void
    {
        $view = $this->runtimeView();
        if ($view === null || $view->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            return;
        }

        $operation = $view->operation;
        $initiatorAgentType = $view->initiatorAgentType;
        if ($operation === null || $initiatorAgentType === null) {
            Logger::error(
                "Protected mode: this node came up under a freeze on phase '{$view->phase}' that names "
                . 'no operation or initiator, and cannot drive it'
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
        $this->readyOwed = false;
        $this->directCircleReceived = $view->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW;

        Logger::warning(
            "Protected mode: this node holds the '{$operation}' freeze it came up under on phase "
            . "'{$view->phase}'; agent '{$initiatorAgentType}' (index " . ($view->initiatorAgentIndex ?? 'none')
            . ') drives it'
        );
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
        $view = $this->runtimeView();
        if ($view === null) {
            Logger::error(
                "Protected mode: cannot enter for '{$data->operation}' requested by agent "
                . "'{$data->initiatorAgentType}' — this process holds no protected mode runtime state"
            );
            $this->deliverRefusal($data, ProtectedModeRefusalCopy::NO_RUNTIME_ROW);
            return;
        }
        if ($view->phase !== StateProtectedModeRuntime::PHASE_INACTIVE) {
            $this->deliverRefusal($data, ProtectedModeRefusalCopy::ANOTHER_OPERATION);
            return;
        }

        $this->activeFreeze = new ProtectedModeQuiesceData(
            $data->operation,
            $data->initiatorAgentType,
            $data->initiatorAgentIndex,
            null,
            $data->initiatorSessionTokenHash,
        );

        if ($data->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW) {
            $this->directCircleReceived = false;
            $this->executor->enterVerificationWindow($this->activeFreeze, $data->initiatorAcceptKey);
            $this->executor->notifyInitiatorReady();

            return;
        }

        $this->readyOwed = true;
        $this->executor->enterActivating($this->activeFreeze, $data->initiatorAcceptKey);
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

        $directWindow = $this->runtimeView()?->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW;
        if (!$directWindow) {
            $this->executor->enterDeactivating();
        }
        $this->executor->enterInactive();
        if ($directWindow) {
            $this->executor->finishLift();
        }
        $this->activeFreeze = null;
        $this->readyOwed = false;
        $this->directCircleReceived = false;
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
     * @param string $passHash Hash of the presented pass, checked at 101
     * @param string $sessionTokenHash Hash of the verifier session
     */
    public function requestAdmit(string $passHash, string $sessionTokenHash): void
    {
        $this->executor->admitVerifier($sessionTokenHash);
    }

    /**
     * Records the verifier circle photographed for this node's initiator (HIL-643).
     *
     * Authorized by the recorded initiator like every other request here, and for a sharper reason
     * than most: the payload is a list of session hashes that the verification window will let in
     * unasked, so a stranger agent able to send one could name whoever it liked.
     *
     * An ordinary freeze accepts the photograph only in active, after the roster stopped. Direct
     * entry accepts it in verifying and finishes the window on the first photograph, including an
     * empty one. Later photographs cannot replace the admitted circle or resend browser frames.
     *
     * @param ProtectedModeCircleSignalData $data Initiator identity and the circle photographed for it
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function requestCircle(ProtectedModeCircleSignalData $data): void
    {
        if (!$this->initiatorMayDrive($data->initiatorAgentType, $data->initiatorAgentIndex, 'circle')) {
            return;
        }
        $view = $this->runtimeView();
        if ($view === null || !in_array($view->phase, [
            StateProtectedModeRuntime::PHASE_ACTIVE,
            StateProtectedModeRuntime::PHASE_VERIFYING,
        ], true)) {
            Logger::warning("Protected mode: dropping circle — the mode is '{$view?->phase}', not active or a direct window");
            return;
        }
        if (
            $view->phase === StateProtectedModeRuntime::PHASE_VERIFYING
            && $view->entryMode !== StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW
        ) {
            return;
        }
        if ($view->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW && $this->directCircleReceived) {
            return;
        }

        $view->actions->admitCircle(
            new VerifierCircleSnapshot($data->namedCount, $data->sessionTokenHashes),
        );
        if ($view->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW) {
            $this->directCircleReceived = true;
            $this->executor->finishVerifying();
        }
    }

    /**
     * Closes this node back from the verification window, voiding every pass.
     *
     * The close is an entry like any other (HIL-1128): the row goes back to activating on the freeze
     * already held and active is written only once the walk has stopped the roster again. The accept
     * key and the session hash come off the row, so the next window lets the same operator in.
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
        if ($this->runtimeView()?->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW) {
            Logger::warning('Protected mode: dropping refreeze — a direct verification window cannot be frozen');
            return;
        }

        // Both are already vouched for by the two guards above; the check is for the type system.
        $view = $this->runtimeView();
        if ($view === null || $this->activeFreeze === null) {
            return;
        }

        $this->readyOwed = false;
        $this->activeFreeze = $this->activeFreeze->withInitiatorSessionTokenHash($view->initiatorSessionTokenHash);
        $this->executor->enterActivating($this->activeFreeze, $view->initiatorAcceptKey);
    }

    /**
     * Marks the freeze active now that the roster has stopped, and tells the initiator it may run
     * when the walk owes it that.
     *
     * Every walk that enters the freeze sits on activating - the first entry, a repeat from the
     * verification window (HIL-1057) and the close back from it (HIL-1128) - and each writes active
     * at its end. Only an entry owes the initiator a ready; the close is answered by the row reaching
     * active. A walk that a lift overtook never gets here at all
     * ({@see ProtectedModeAgentFreezer::resumeAgentsForProtectedMode()}).
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
        if (!$this->readyOwed) {
            return;
        }

        $this->readyOwed = false;
        $this->executor->notifyInitiatorReady();
    }

    /**
     * Finishes whichever lift brought the roster back, told apart by the phase already on the row.
     */
    public function onRosterResumed(): void
    {
        $view = $this->runtimeView();
        if ($view?->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW) {
            return;
        }

        $phase = $view?->phase;
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
     * is all a ready ever asserted. That holds by construction: active is written only at the end
     * of a walk, and since HIL-1128 the close back from the window walks too.
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

        if ($view->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW) {
            if (
                $data->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW
                && $data->operation === $freeze->operation
            ) {
                $this->executor->notifyInitiatorReady();
            } else {
                $this->deliverRefusal($data, ProtectedModeRefusalCopy::ANOTHER_OPERATION);
            }

            return;
        }
        if ($data->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW) {
            $this->deliverRefusal($data, ProtectedModeRefusalCopy::ANOTHER_OPERATION);
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
            $this->readyOwed = true;
            $this->activeFreeze = $freeze->withInitiatorSessionTokenHash($data->initiatorSessionTokenHash);
            $this->executor->enterActivating($this->activeFreeze, $data->initiatorAcceptKey);
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
