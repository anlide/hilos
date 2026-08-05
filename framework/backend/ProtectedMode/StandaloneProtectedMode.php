<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
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
 * Two guards mirror the cluster's for the same reasons. A repeat enable is dropped rather than
 * re-run, because re-entering the freeze re-rolls the stopped-agent roster the release resumes
 * against and would strand agents. A release is honored only for the agent recorded as the
 * initiator: on one node the cluster's node-id check compares a node against itself and authorizes
 * nothing, so the agent identity is the only thing left that distinguishes the initiator from any
 * other agent that might resume the system mid-restore.
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
     */
    public function requestEnable(ProtectedModeEnableSignalData $data): void
    {
        if ($this->activeFreeze !== null) {
            Logger::warning("Protected mode: dropping enable — a '{$this->activeFreeze->operation}' freeze is already in flight");
            return;
        }
        if ($this->runtimeView() === null) {
            Logger::error(
                "Protected mode: cannot enter for '{$data->operation}' requested by agent "
                . "'{$data->initiatorAgentType}' — this project mounts no protected mode runtime state"
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
     */
    public function requestDisable(ProtectedModeDisableSignalData $data): void
    {
        if ($this->activeFreeze === null) {
            Logger::warning("Protected mode: dropping disable from agent '{$data->initiatorAgentType}' — no freeze is active here");
            return;
        }

        $view = $this->runtimeView();
        if (
            $view === null
            || $view->initiatorAgentType !== $data->initiatorAgentType
            || $view->initiatorAgentIndex !== $data->initiatorAgentIndex
        ) {
            Logger::warning("Protected mode: dropping disable from agent '{$data->initiatorAgentType}' — the freeze was initiated by another agent");
            return;
        }

        $this->executor->enterDeactivating();
        $this->executor->enterInactive();
        $this->activeFreeze = null;
    }

    /**
     * Resolves the protected-mode runtime singleton, or null when this project mounted none.
     *
     * Read here as well as in {@see DaemonProtectedModeExecutor} because the two ask different
     * questions of the same row: the executor asks where to write, this asks whether entering the
     * mode is possible at all and who the recorded initiator is.
     *
     * @return ?ProtectedModeRuntime Runtime singleton view, or null when runtime state is unavailable
     */
    private function runtimeView(): ?ProtectedModeRuntime
    {
        return Hilos::$rt?->hilosProtectedModeRuntime;
    }
}
