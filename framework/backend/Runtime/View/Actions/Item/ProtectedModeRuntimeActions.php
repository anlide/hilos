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
     * Puts back the freeze this node went down under, read off the disk it was left on.
     *
     * The one write that establishes a freeze without a transition deciding it: the row is memory
     * only, so without this a restart under a freeze comes back open and serves clients over a
     * database whose restore never finished. It runs during startup, before any server binds, so
     * the first handshake after the restart is already locked out.
     *
     * **Everything a connection was recognized by is dropped rather than carried.** The passes and
     * the keys they admitted belong to browsers that died with the daemon, and so does the
     * initiator's own key: an accept key is minted on the 101 and cannot outlive the process that
     * minted it. The initiator's session hash goes the same way, and that one is a decision rather
     * than a consequence - the cookie behind it does outlive the daemon, so keeping it would hand
     * one browser the run of a node whose operation nobody is driving any more. What is left behind
     * instead is a row that locks out everybody - the honest state of a node whose operation is
     * gone - and the operator lifts it by the ladder ({@see enterInactive()}) once they have looked
     * at the data.
     *
     * @param StateProtectedModeRuntime $row Freeze row as it was left on disk
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function restoreFromDisk(StateProtectedModeRuntime $row): void
    {
        $this->ensureCanWrite();

        $this->state->phase = $row->phase;
        $this->state->operation = $row->operation;
        $this->state->initiatorAcceptKey = null;
        $this->state->initiatorSessionTokenHash = null;
        $this->state->initiatorAgentType = $row->initiatorAgentType;
        $this->state->initiatorAgentIndex = $row->initiatorAgentIndex;
        $this->state->initiatorNodeId = $row->initiatorNodeId;
        $this->state->startedAt = $row->startedAt;
        $this->state->activatedAt = $row->activatedAt;
        $this->state->progressAt = $row->progressAt;
        $this->state->passHashes = [];
        $this->state->admittedAcceptKeys = [];
        $this->sync();
    }

    /**
     * Records the freeze this node is entering and the identity allowed through it.
     *
     * The initiator's accept key and session hash are recorded only on the node that froze itself
     * with them; elsewhere they stay null, which is what locks every connection out on the other
     * nodes. A browser that reconnects to another node of a cluster is therefore locked out there
     * exactly as it is today - deliberately, and it is the cluster epic that widens it.
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
     * @param ?string $initiatorSessionTokenHash Hash of the initiator browser's session token, let through
     *                                           here for as long as the freeze holds; null on a follower
     *                                           node and whenever nothing with a browser asked
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function enterActivating(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
        ?string $initiatorSessionTokenHash,
    ): void {
        $this->ensureCanWrite();

        $this->state->phase = StateProtectedModeRuntime::PHASE_ACTIVATING;
        $this->state->operation = $freeze->operation;
        $this->state->initiatorAcceptKey = $initiatorAcceptKey;
        $this->state->initiatorSessionTokenHash = $initiatorSessionTokenHash;
        $this->state->initiatorAgentType = $freeze->initiatorAgentType;
        $this->state->initiatorAgentIndex = $freeze->initiatorAgentIndex;
        $this->state->initiatorNodeId = $freeze->initiatorNodeId;
        $this->state->startedAt = time();
        $this->state->activatedAt = null;
        $this->state->progressAt = null;
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
     * Reaching the window is itself progress, so it is stamped as one: the operation that just
     * finished may well have been silent for longer than the watchdog's threshold, and without
     * this stamp the window would be reported stuck the moment it opened, for the silence of the
     * step before it. What it is instead given is a full threshold of its own - a window nobody
     * ever ends is a real condition, and it has to be reported on its own account.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function enterVerifying(): void
    {
        $this->ensureCanWrite();

        $this->state->phase = StateProtectedModeRuntime::PHASE_VERIFYING;
        $this->state->progressAt = time();
        $this->sync();
    }

    /**
     * Stamps the row with the moment the operation behind the freeze last moved.
     *
     * The one write on this class that changes no phase: it records life, not a transition. The
     * value is taken here, on the master that owns the row, and never from the request that asked
     * for it - a mark carried on the wire would let a node with a skewed clock decide how long
     * another node's freeze may stay silent.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function markProgress(): void
    {
        $this->ensureCanWrite();

        $this->state->progressAt = time();
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
     * The whole identity is cleared with the phase: a stale accept key left on the row would hand
     * one connection a privilege after the operation that earned it is over, and a stale session
     * hash would hand it to a whole browser, for as long as its cookie lives. The passes and the
     * admissions they earned go the same way and for the same reason.
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
        $this->state->initiatorSessionTokenHash = null;
        $this->state->initiatorAgentType = null;
        $this->state->initiatorAgentIndex = null;
        $this->state->initiatorNodeId = null;
        $this->state->startedAt = null;
        $this->state->activatedAt = null;
        $this->state->progressAt = null;
        $this->state->passHashes = [];
        $this->state->admittedAcceptKeys = [];
        $this->sync();
    }
}
