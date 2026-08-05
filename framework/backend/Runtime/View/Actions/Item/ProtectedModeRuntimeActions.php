<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime as ViewProtectedModeRuntime;

/**
 * Write operations for the protected-mode runtime singleton.
 *
 * One method per phase of the freeze, mirroring the transitions the cluster orchestration
 * decides: the executor says which phase this node entered, and the row plus the RT sync
 * that carries it are this class's business. The daemon master of each node is the
 * registered truth source; {@see RtActions::ensureCanWrite()} enforces that.
 *
 * @extends RtActions<ViewProtectedModeRuntime, StateProtectedModeRuntime>
 * @property-read StateProtectedModeRuntime $state
 */
final class ProtectedModeRuntimeActions extends RtActions
{
    /**
     * Records the freeze this node is entering and the identity allowed through it.
     *
     * The initiator's accept key is recorded only on the node that froze itself with it;
     * elsewhere it stays null, which is what locks every connection out on the other nodes.
     *
     * @param ProtectedModeQuiesceData $freeze Operation and initiator identity the freeze protects
     * @param ?string $initiatorAcceptKey Accept key let through here; null on a follower node
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function enterActivating(ProtectedModeQuiesceData $freeze, ?string $initiatorAcceptKey): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateProtectedModeRuntime::PHASE_ACTIVATING;
        $this->state->operation = $freeze->operation;
        $this->state->initiatorAcceptKey = $initiatorAcceptKey;
        $this->state->initiatorAgentType = $freeze->initiatorAgentType;
        $this->state->initiatorAgentIndex = $freeze->initiatorAgentIndex;
        $this->state->initiatorNodeId = $freeze->initiatorNodeId;
        $this->state->startedAt = time();
        $this->state->activatedAt = null;
        $this->sync();
    }

    /**
     * Marks the freeze fully established: every node has quiesced.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function enterActive(): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        $this->state->activatedAt = time();
        $this->sync();
    }

    /**
     * Marks the freeze as lifting; the lockdown stays up until it is inactive.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function enterDeactivating(): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateProtectedModeRuntime::PHASE_DEACTIVATING;
        $this->sync();
    }

    /**
     * Lifts the freeze and forgets the initiator.
     *
     * The whole identity is cleared with the phase: a stale accept key left on the row
     * would hand one connection a privilege after the operation that earned it is over.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function enterInactive(): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $this->state->operation = null;
        $this->state->initiatorAcceptKey = null;
        $this->state->initiatorAgentType = null;
        $this->state->initiatorAgentIndex = null;
        $this->state->initiatorNodeId = null;
        $this->state->startedAt = null;
        $this->state->activatedAt = null;
        $this->sync();
    }
}
