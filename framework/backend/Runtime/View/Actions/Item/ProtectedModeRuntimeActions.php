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
     * **The browser the freeze was started from is carried; everything else a connection was
     * recognized by is dropped.** The accept key cannot be anything but dropped: it is minted on
     * the 101 and does not outlive the process that minted it. The passes and the sessions they
     * admitted are a decision rather than a consequence - a pass lasts minutes and is read out to
     * a verifier by voice, so one who was already inside asks for another instead of keeping the
     * run of a node over a cookie that outlived the daemon.
     *
     * The initiator's session hash stays for the reason the other three go: the exit from the
     * verification window stopped being terminal-only. Since HIL-676 it is a button on the backup
     * page, gated on {@see StateProtectedModeRuntime::belongsToInitiator()}, which refuses null -
     * so dropping the hash locked out no stranger, it locked out the one operator who started the
     * operation and left them a terminal as their only door. What comes back is therefore a row
     * that locks out everybody except that browser, and the operator lifts it either by the
     * button or by the ladder ({@see enterInactive()}) once they have looked at the data.
     *
     * TODO(HIL-93): the right to enter a frozen node belongs to the roles subsystem (HIL-93
     * anchors it, HIL-97 enforces it); until it exists this half of the decision is a session
     * hash, which names a browser rather than a person.
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
        $this->state->initiatorSessionTokenHash = $row->initiatorSessionTokenHash;
        $this->state->initiatorAgentType = $row->initiatorAgentType;
        $this->state->initiatorAgentIndex = $row->initiatorAgentIndex;
        $this->state->initiatorNodeId = $row->initiatorNodeId;
        $this->state->startedAt = $row->startedAt;
        $this->state->activatedAt = $row->activatedAt;
        $this->state->progressAt = $row->progressAt;
        $this->state->passHashes = [];
        $this->state->admittedSessionTokenHashes = [];
        $this->sync();
    }

    /**
     * Records the freeze this node is entering and the identity allowed through it.
     *
     * The initiator's accept key and session hash are recorded only on the node that froze itself
     * with them; elsewhere they stay null, which is what keeps the verification window shut to
     * that browser on the other nodes. A browser that reconnects to another node of a cluster is
     * therefore locked out there for the whole operation - deliberately, and it is the cluster
     * epic that widens it. Neither name buys anything while the freeze holds: under the frozen
     * phases the row refuses every connection, this one included.
     *
     * A new freeze starts with no passes and nobody admitted, and that is written rather than
     * assumed: {@see ViewProtectedModeRuntime::admits()} reads the frozen phases as empty by
     * construction, and one path can arrive here holding an abandoned window's hashes - a
     * demoted leader still on verifying, quiesced again by whoever took leadership. Every other
     * way in passes through a clear already, so this costs two assignments and closes the one
     * hole where a voided pass could admit its holder to the next operation.
     *
     * @param ProtectedModeQuiesceData $freeze Operation and initiator identity the freeze protects
     * @param ?string $initiatorAcceptKey Accept key recorded here and admitted once the verification
     *                                    window opens; null on a follower node
     * @param ?string $initiatorSessionTokenHash Hash of the initiator browser's session token, recorded
     *                                           and admitted on the same terms; null on a follower
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
        $this->state->admittedSessionTokenHashes = [];
        $this->sync();
    }

    /**
     * Marks the freeze fully established: every node has quiesced.
     *
     * Coming back from {@see enterVerifying()} this is also the operator closing the system
     * again, so the passes and the admissions they earned are voided here: a pass that outlived
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
        $this->state->admittedSessionTokenHashes = [];
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
     * Records a browser session that presented a valid pass as let in.
     *
     * One entry per admitted session, and presenting the same pass again from a second tab of the
     * same browser adds nothing: the row is keyed by the session behind the connection, so one
     * browser is one entry however many sockets it opens. Without the guard the list would grow
     * by one on every reconnect of every tab of a verifier who is already inside. What bounds it
     * beyond that is the window rather than the count - both ways out of it ({@see enterActive()},
     * {@see enterInactive()}) clear the lists, and it is open for as long as an operator is
     * looking at the system.
     *
     * Membership is tested with {@see hash_equals()} rather than {@see in_array()} for the reason
     * {@see StateProtectedModeRuntime::admits()} compares that way: both sides are derived from a
     * secret.
     *
     * @param string $sessionTokenHash Hash of the admitted browser's session token
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function admitSession(string $sessionTokenHash): void
    {
        $this->ensureCanWrite();

        foreach ($this->state->admittedSessionTokenHashes as $admittedSessionTokenHash) {
            if (hash_equals($admittedSessionTokenHash, $sessionTokenHash)) {
                return;
            }
        }

        $this->state->admittedSessionTokenHashes[] = $sessionTokenHash;
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
     * sessions they admitted go the same way and for the same reason.
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
        $this->state->admittedSessionTokenHashes = [];
        $this->sync();
    }
}
