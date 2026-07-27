<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Utils\Logger;

/**
 * Daemon-master implementation of {@see ProtectedModeExecutor}: applies the freeze transitions the
 * {@see ClusterProtectedMode} orchestration decides to this node's local runtime row.
 *
 * It writes the {@see ProtectedModeRuntime} singleton through Hilos::$rt — the daemon truth source
 * registered in HIL-267 slice 2a — so every worker on this node sees the current phase and the
 * master's welcome path and the browser page guards can lock connections out. Both the leader
 * (freezing itself) and every follower own one, and both release the same way. A node that never
 * mounted the runtime item (getStateItem returns null) writes nothing, so the executor is inert
 * there rather than failing.
 *
 * {@see notifyInitiatorReady()} relays the leader's ready to the initiator agent by addressing the
 * worker hosting it through {@see ProtectedModeReadyRelay}, reading the initiator identity back from
 * the runtime row this node wrote on entry. One effect is still a seam a later slice fills: stopping
 * and resuming this node's own agents (leaving the initiator agent running) is HIL-267 slice 7.
 */
final class DaemonProtectedModeExecutor implements ProtectedModeExecutor
{
    /**
     * @param ProtectedModeQuiesceData $freeze Operation and initiator identity the freeze protects
     * @param ?string $initiatorAcceptKey Accept key let through when the leader freezes itself; null on a follower
     */
    public function enterActivating(ProtectedModeQuiesceData $freeze, ?string $initiatorAcceptKey): void
    {
        $state = $this->runtimeState();
        if ($state === null) {
            return;
        }

        $state->phase = ProtectedModeRuntime::PHASE_ACTIVATING;
        $state->operation = $freeze->operation;
        $state->initiatorAcceptKey = $initiatorAcceptKey;
        $state->initiatorAgentType = $freeze->initiatorAgentType;
        $state->initiatorAgentIndex = $freeze->initiatorAgentIndex;
        $state->initiatorNodeId = $freeze->initiatorNodeId;
        $state->startedAt = time();
        $state->activatedAt = null;
        $state->sync();

        // Stopping this node's own agents (leaving the initiator running) lands in HIL-267 slice 7.
    }

    public function enterActive(): void
    {
        $state = $this->runtimeState();
        if ($state === null) {
            return;
        }

        $state->phase = ProtectedModeRuntime::PHASE_ACTIVE;
        $state->activatedAt = time();
        $state->sync();
    }

    public function enterDeactivating(): void
    {
        $state = $this->runtimeState();
        if ($state === null) {
            return;
        }

        $state->phase = ProtectedModeRuntime::PHASE_DEACTIVATING;
        $state->sync();
    }

    public function enterInactive(): void
    {
        $state = $this->runtimeState();
        if ($state === null) {
            return;
        }

        $state->phase = ProtectedModeRuntime::PHASE_INACTIVE;
        $state->operation = null;
        $state->initiatorAcceptKey = null;
        $state->initiatorAgentType = null;
        $state->initiatorAgentIndex = null;
        $state->initiatorNodeId = null;
        $state->startedAt = null;
        $state->activatedAt = null;
        $state->sync();

        // Resuming the agents stopped on entry lands with the mass agent-stop in HIL-267 slice 7.
    }

    public function notifyInitiatorReady(): void
    {
        $state = $this->runtimeState();
        if ($state === null) {
            return;
        }

        if ($state->initiatorAgentType === null) {
            Logger::warning('Protected mode: ready arrived but no initiator identity is recorded');
            return;
        }

        Hilos::$cluster?->protectedModeReadyRelay()?->deliverProtectedModeReady(
            $state->initiatorAgentType,
            $state->initiatorAgentIndex === null ? null : (string)$state->initiatorAgentIndex,
        );
    }

    /**
     * Resolves the protected-mode runtime singleton, or null when this node never mounted it.
     *
     * @return ?ProtectedModeRuntime Runtime singleton, or null when runtime state is unavailable
     */
    private function runtimeState(): ?ProtectedModeRuntime
    {
        $state = Hilos::$rt?->getStateItem(ProtectedModeRuntime::RT_ITEM);

        return $state instanceof ProtectedModeRuntime ? $state : null;
    }
}
