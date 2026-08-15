<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

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
 * The single-node freeze: protected mode for an installation running without a cluster.
 *
 * It is the {@see ClusterProtectedMode} state machine with everything peer-shaped removed. With no
 * followers there is no quiesce round to wait for and no pendingNodes to track, and with no
 * leadership there is nothing to gate on, so the whole entry collapses into one tick: freeze the
 * node, mark it active, tell the initiator to go. The local half is shared verbatim with the
 * clustered path - the same {@see ProtectedModeExecutor} writes the same
 * {@see ProtectedModeRuntime} row and stops the same agents - so a project sees identical behavior
 * whether or not it clusters, which is the whole point of this class existing.
 *
 * Two guards mirror the cluster's for the same reasons. A repeat enable is never re-run, because
 * re-entering the freeze re-rolls the stopped-agent roster the release resumes against and would
 * strand agents; the initiator of a freeze that already stands is answered ready instead of
 * dropped ({@see answerFreezeAlreadyHeld()}). A release is honored only for the agent recorded as the
 * initiator: on one node the cluster's node-id check compares a node against itself and authorizes
 * nothing, so the agent identity is the only thing left that distinguishes the initiator from any
 * other agent that might resume the system mid-restore.
 *
 * The verification window adds three more requests - open it, mint a pass into it, close back out
 * of it - and they authorize by that same recorded agent identity. Each also refuses from the wrong
 * phase, which the enable and disable pair never had to: those two are the ends of the ladder, while
 * a verify raised twice or a pass minted for a window nobody opened would move the phase behind the
 * operator's back.
 *
 * Entering without a mounted runtime row is refused loudly instead of silently doing nothing: the
 * initiator waits for ready before it starts destroying anything, so a refusal keeps it waiting
 * safely, while a silent no-op that still reported ready would run a restore over a live system.
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
     * Freezes this node for a destructive operation and tells the initiator it may run.
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
            return;
        }

        $this->activeFreeze = new ProtectedModeQuiesceData(
            $data->operation,
            $data->initiatorAgentType,
            $data->initiatorAgentIndex,
            null,
        );

        $this->executor->enterActivating($this->activeFreeze, $data->initiatorAcceptKey);
        $this->executor->enterActive();
        $this->executor->notifyInitiatorReady();
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

        $this->runtimeView()?->actions->issuePass($data->passHash);
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
     * Answers an enable raised against the freeze this node is already holding.
     *
     * Closing the verification window is how an operator gets to run another attempt, and it
     * deliberately leaves the node frozen on active: the next operation therefore finds nothing
     * left to enter, and a plain refusal left its initiator waiting for a ready that could never
     * come. So the initiator the row records is told ready once more - the node is quiesced, which
     * is all a ready ever asserted - while any other agent, and any phase that is not a settled
     * freeze, is dropped exactly as before. Re-entering is not the alternative:
     * {@see ProtectedModeExecutor::enterActivating()} re-rolls the stopped-agent roster the
     * release resumes against, which is what the repeat was refused for in the first place.
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
        if (
            $view === null
            || $view->phase !== StateProtectedModeRuntime::PHASE_ACTIVE
            || $view->initiatorAgentType !== $data->initiatorAgentType
            || $view->initiatorAgentIndex !== $data->initiatorAgentIndex
        ) {
            Logger::warning("Protected mode: dropping enable — a '{$freeze->operation}' freeze is already in flight");
            return;
        }

        if ($data->operation !== $freeze->operation) {
            Logger::warning(
                "Protected mode: enable for '{$data->operation}' arrived under the standing "
                . "'{$freeze->operation}' freeze — the stub keeps naming the operation it was entered for"
            );
        }

        $this->executor->notifyInitiatorReady();
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
