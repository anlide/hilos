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
     * A new freeze starts with no passes and nobody admitted, and that is written rather than
     * assumed: {@see ViewProtectedModeRuntime::admits()} reads the frozen phases as empty by
     * construction, and one path can arrive here holding an abandoned window's hashes - a
     * demoted leader still on verifying, quiesced again by whoever took leadership. Every other
     * way in passes through a clear already, so this costs two assignments and closes the one
     * hole where a voided key could admit its holder to the next operation.
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
        $this->state->passHashes = [];
        $this->state->admittedAcceptKeys = [];
        $this->sync();
    }

    /**
     * Marks the freeze fully established: every node has quiesced.
     *
     * Coming back from {@see enterVerifying()} this is also the operator closing the system
     * again, so the passes and the admissions they earned are voided here: a key that outlived
     * the verification it was minted for would let its holder in during the next operation.
     * On the ordinary activating -> active path both lists are empty already and the clear
     * costs nothing.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function enterActive(): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        $this->state->activatedAt = time();
        $this->state->passHashes = [];
        $this->state->admittedAcceptKeys = [];
        $this->sync();
    }

    /**
     * Opens the verification window: the operation is over, but the mode is not lifted yet.
     *
     * The operation and the whole initiator identity stay on the row - the initiator still
     * drives, and it is the only one that may end this phase in either direction.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function enterVerifying(): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateProtectedModeRuntime::PHASE_VERIFYING;
        $this->sync();
    }

    /**
     * Records one more pass minted for the verification in flight.
     *
     * Only the hash is stored; the clear key travels from the operator's terminal to the
     * verifier's browser and exists nowhere on this row.
     *
     * @param string $passHash SHA-256 of the minted pass
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function issuePass(string $passHash): void
    {
        $this->ensureCanWrite();

        $this->state->passHashes[] = $passHash;
        $this->sync();
    }

    /**
     * Records a connection that presented a valid pass as let in.
     *
     * One entry per admitted connection, and a verifier that reconnects makes another: the accept
     * key is minted fresh on each 101, so the same browser coming back is a different key and there
     * is nothing here to deduplicate. What bounds the list is the window rather than the count -
     * both ways out of it ({@see enterActive()}, {@see enterInactive()}) clear the lists, and it is
     * open for as long as an operator is looking at the system.
     *
     * @param string $acceptKey Accept key of the admitted connection
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function admitConnection(string $acceptKey): void
    {
        $this->ensureCanWrite();

        $this->state->admittedAcceptKeys[] = $acceptKey;
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
     * The passes and the admissions they earned go the same way and for the same reason.
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
        $this->state->passHashes = [];
        $this->state->admittedAcceptKeys = [];
        $this->sync();
    }
}
